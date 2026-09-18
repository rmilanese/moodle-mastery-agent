<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_masteryagent;

use core_external\external_api;
use mod_masteryagent\external\update_conversation;

require_once(__DIR__ . '/helper_trait.php');

/**
 * AJAX access control, duplicate protection, rendering and atomic failure recovery.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_masteryagent\external\update_conversation
 * @covers \mod_masteryagent\conversation
 * @covers \mod_masteryagent\output\conversation_view
 */
final class external_test extends \advanced_testcase {
    use helper_trait;

    /** @var \stdClass Activity record. */
    private \stdClass $instance;
    /** @var \stdClass Course record. */
    private \stdClass $course;
    /** @var \stdClass Enrolled student. */
    private \stdClass $student;
    /** @var \stdClass Course module record. */
    private \stdClass $cm;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Provider-failure tests need a real rollback without rolling back test fixtures.
        $this->preventResetByRollback();
        $this->course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $this->course->id, 'lessonkeys' => 'S01,S02',
        ]);
        $this->cm = get_coursemodule_from_instance('masteryagent', $this->instance->id);
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
        $this->setUser($this->student);
    }

    protected function tearDown(): void {
        $this->reset_ai();
        parent::tearDown();
    }

    /** @return attempt|null Current student's attempt. */
    private function current(): ?attempt {
        return attempt::get_latest($this->instance, (int) $this->student->id);
    }

    /**
     * Call the endpoint and validate its declared return structure.
     *
     * @param string $action Action.
     * @param string $reply Reply text.
     * @param string|null $state Override the displayed state.
     * @return array
     */
    private function act(string $action, string $reply = '', ?string $state = null): array {
        $result = update_conversation::execute((int) $this->cm->id, $action,
            $state ?? conversation::state($this->current()), $reply, $action === 'finish');
        return external_api::clean_returnvalue(update_conversation::execute_returns(), $result);
    }

    public function test_begin_and_duplicate_begin_create_only_one_attempt(): void {
        global $DB;
        $result = $this->act('start');
        $this->assertFalse($result['stale']);
        $this->assertStringContainsString('digital map display', $result['html']);
        $this->assertStringContainsString('data-action="reply"', $result['html']);
        $duplicate = $this->act('start', '', 'new');
        $this->assertTrue($duplicate['stale']);
        $this->assertEquals(1, $DB->count_records('masteryagent_attempt'));
    }

    public function test_reply_advances_lessons_and_duplicate_reply_is_ignored(): void {
        $this->stub_ai(['closeafter' => 1]);
        $this->act('start');
        $state = conversation::state($this->current());
        $response = $this->act('reply', 'Maps must be checked.', $state);
        $current = $this->current();
        $this->assertSame(1, $current->lesson_index());
        $this->assertSame(0, $current->turns_used());
        $this->assertStringContainsString('Lesson 2 of 2', $response['html']);
        $count = count($current->messages());
        $calls = count($this->sentprompts);
        $duplicate = $this->act('reply', 'Maps must be checked.', $state);
        $this->assertTrue($duplicate['stale']);
        $this->assertCount($count, $this->current()->messages());
        $this->assertCount($calls, $this->sentprompts);
    }

    public function test_finish_displays_results_and_retry_uses_a_new_attempt(): void {
        $this->stub_ai(['closeafter' => 99]);
        $this->act('start');
        $this->act('reply', 'Maps need verification.');
        $oldid = $this->current()->get_id();
        $oldstate = conversation::state($this->current());
        $response = $this->act('finish');
        $this->assertTrue($this->current()->is_finished());
        $this->assertStringContainsString('data-region="results"', $response['html']);
        $this->assertStringNotContainsString('data-action="reply"', $response['html']);
        $this->act('start');
        $this->assertNotSame($oldid, $this->current()->get_id());
        $this->assertTrue($this->act('reply', 'Late reply from old tab.', $oldstate)['stale']);
        $this->assertSame(0, $this->current()->turns_used());
    }

    public function test_final_reply_updates_the_gradebook(): void {
        global $DB;
        $this->stub_ai(['scores' => ['S01' => 4, 'S02' => 3]]);
        $this->act('start');
        $this->act('reply', 'First lesson answer.');
        $response = $this->act('reply', 'Second lesson answer.');
        $this->assertTrue($this->current()->is_finished());
        $this->assertStringContainsString(get_string('attemptscoreline', 'mod_masteryagent', (object) [
            'score' => format_float(7, 2), 'max' => 8,
        ]), $response['html']);
        $item = $DB->get_record('grade_items', [
            'itemtype' => 'mod', 'itemmodule' => 'masteryagent', 'iteminstance' => $this->instance->id,
        ], '*', MUST_EXIST);
        $grade = $DB->get_record('grade_grades', [
            'itemid' => $item->id, 'userid' => $this->student->id,
        ], '*', MUST_EXIST);
        $this->assertEquals(7, $grade->rawgrade);
    }

    public function test_retry_disabled_is_enforced_by_the_server(): void {
        global $DB;
        $DB->set_field('masteryagent', 'allowretry', 0, ['id' => $this->instance->id]);
        $this->act('start');
        $response = $this->act('finish');
        $this->assertStringNotContainsString('data-action="start"', $response['html']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('attemptnotavailable', 'mod_masteryagent'));
        $this->act('start');
    }

    public function test_user_cannot_submit_to_another_users_attempt(): void {
        $this->act('start');
        $foreignstate = conversation::state($this->current());
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->course->id, 'student');
        $this->setUser($other);
        $response = update_conversation::execute((int) $this->cm->id, 'reply', $foreignstate, 'Malicious reply');
        $this->assertTrue($response['stale']);
        $this->assertNull(attempt::get_latest($this->instance, (int) $other->id));
        $this->assertSame(0, $this->current()->turns_used());
        $this->assertStringNotContainsString('digital map display', $response['html']);
    }

    public function test_teacher_without_attempt_capability_cannot_use_endpoint(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        $this->act('start');
    }

    public function test_unenrolled_user_cannot_use_endpoint(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        $this->act('start');
    }

    public function test_hidden_activity_is_not_accessible_to_student(): void {
        set_coursemodule_visible($this->cm->id, 0);
        $this->expectException(\moodle_exception::class);
        $this->act('start');
    }

    public function test_unknown_action_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->act('delete');
    }

    public function test_empty_reply_does_not_consume_a_turn(): void {
        $this->act('start');
        try {
            $this->act('reply', '   ');
            $this->fail('Whitespace-only replies must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('replyrequired', $e->errorcode);
        }
        $this->assertSame(0, $this->current()->turns_used());
    }

    public function test_overlong_reply_is_rejected_without_truncation(): void {
        $this->act('start');
        try {
            $this->act('reply', str_repeat('a', attempt::MAX_REPLY_CHARS + 1));
            $this->fail('Overlong replies must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('replytoolong', $e->errorcode);
        }
        $this->assertSame(0, $this->current()->turns_used());
    }

    public function test_provider_failure_rolls_back_the_turn_and_can_be_retried(): void {
        $this->act('start');
        $state = conversation::state($this->current());
        $this->stub_ai_garbage();
        try {
            $this->act('reply', 'My first answer.');
            $this->fail('Malformed provider response must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbadresponse', $e->errorcode);
        }
        $this->assertSame($state, conversation::state($this->current()));
        $this->assertSame(0, $this->current()->turns_used());
        $this->assertCount(1, $this->current()->messages());
        $this->stub_ai(['closeafter' => 99]);
        $this->act('reply', 'My first answer.', $state);
        $this->assertSame(1, $this->current()->turns_used());
        $this->assertCount(3, $this->current()->messages());
    }

    public function test_final_assessment_failure_does_not_save_partial_scores(): void {
        $this->act('start');
        agent::set_test_responder(function (string $prompt): string {
            if (str_contains($prompt, '=== FULL CONVERSATION ===')) {
                return 'not JSON';
            }
            return json_encode(['reply' => 'Done.', 'covered' => [], 'misconceptions' => [],
                'resolved' => [], 'ready_to_close' => true]);
        });
        try {
            $this->act('reply', 'My answer.');
            $this->fail('Final assessment must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbadresponse', $e->errorcode);
        }
        $this->assertSame([], $this->current()->lesson_results());
        $this->assertSame(0, $this->current()->turns_used());
        $this->assertCount(1, $this->current()->messages());
    }

    public function test_early_finish_summary_failure_rolls_back_assessed_and_skipped_results_and_allows_retry(): void {
        global $DB;
        $this->stub_ai(['closeafter' => 99]);
        $this->act('start');
        $this->act('reply', 'An answer already submitted.');
        $this->act('pause', 'A saved draft that must survive a failed final submission.');
        $before = $this->current();
        $state = conversation::state($before);
        $record = clone $before->get_record();
        $messages = serialize($before->messages());
        $grades = serialize($DB->get_records('grade_grades', [], 'id ASC'));
        $assessmentcalls = 0;
        agent::set_test_responder(static function(string $prompt) use (&$assessmentcalls): string {
            if (str_contains($prompt, '=== FULL CONVERSATION ===')) {
                $assessmentcalls++;
                return json_encode(['score' => 3, 'summary' => 'Saved only if all finalization succeeds.']);
            }
            throw new \moodle_exception('errorprovider', 'mod_masteryagent');
        });
        try {
            $this->act('finish', '', $state);
            $this->fail('The unavailable course summary must fail final submission.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorprovider', $e->errorcode);
        }
        $after = $this->current();
        $this->assertSame(1, $assessmentcalls);
        $this->assertEquals($record, $after->get_record());
        $this->assertSame($state, conversation::state($after));
        $this->assertSame($messages, serialize($after->messages()));
        $this->assertSame($grades, serialize($DB->get_records('grade_grades', [], 'id ASC')));
        $this->assertSame([], $after->lesson_results());
        $this->assertFalse($after->is_finished());

        $this->stub_ai(['closeafter' => 99, 'scores' => ['S01' => 3]]);
        $response = $this->act('finish', '', $state);
        $this->assertFalse($response['stale']);
        $this->assertTrue($this->current()->is_finished());
        $this->assertSame(['assessed', 'notassessed'], array_column($this->current()->lesson_results(), 'status'));
        $this->assertSame($messages, serialize($this->current()->messages()));
        $this->assertSame('', $this->current()->draft_reply());
        $this->assertSame(3.0, (float) $this->current()->get_record()->score);
    }

    public function test_html_is_escaped_and_private_evidence_is_not_returned(): void {
        $this->stub_ai(['closeafter' => 99, 'covered' => ['PRIVATE_LEDGER_SENTINEL'],
            'reply' => '<script>window.attack=true</script>']);
        $this->act('start');
        $response = $this->act('reply', '<img src=x onerror=alert(1)>');
        $this->assertStringNotContainsString('<script>', $response['html']);
        $this->assertStringNotContainsString('<img src=x', $response['html']);
        $this->assertStringContainsString('&lt;script&gt;', $response['html']);
        $this->assertStringNotContainsString('PRIVATE_LEDGER_SENTINEL', $response['html']);
        $this->assertSame(['html', 'stale', 'warning'], array_keys($response));
    }

    public function test_pause_restores_draft_without_turns_ai_calls_or_a_grade(): void {
        global $DB;
        $this->stub_ai();
        $this->act('start');
        $this->act('reply', 'Completed first lesson.');
        $before = $this->current();
        $state = conversation::state($before);
        $calls = count($this->sentprompts);
        $draft = "  An unfinished answer\n<img src=x onerror=alert(1)>";
        $response = $this->act('pause', $draft);
        $after = $this->current();
        $this->assertFalse($response['stale']);
        $this->assertFalse($after->is_finished());
        $this->assertSame($draft, $after->draft_reply());
        $this->assertSame($before->lesson_index(), $after->lesson_index());
        $this->assertSame($before->turns_used(), $after->turns_used());
        $this->assertSame($before->lesson_results(), $after->lesson_results());
        $this->assertSame($before->ledger(), $after->ledger());
        $this->assertCount(count($before->messages()), $after->messages());
        $this->assertCount($calls, $this->sentprompts);
        $this->assertNull($after->get_record()->score);
        $this->assertNotSame($state, conversation::state($after));
        $this->assertStringContainsString('An unfinished answer', $response['html']);
        $this->assertStringContainsString('&lt;img', $response['html']);
        $this->assertStringNotContainsString('<img src=x', $response['html']);
        $item = $DB->get_record('grade_items', [
            'itemmodule' => 'masteryagent', 'iteminstance' => $this->instance->id,
        ], '*', MUST_EXIST);
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $this->student->id]);
        $this->assertTrue(!$grade || $grade->rawgrade === null);
    }

    public function test_pause_rejects_stale_and_overlong_drafts_and_allows_an_empty_draft(): void {
        $this->act('start');
        $oldstate = conversation::state($this->current());
        $this->act('pause', 'Newest draft');
        $this->assertTrue($this->act('pause', 'Old tab draft', $oldstate)['stale']);
        $this->assertSame('Newest draft', $this->current()->draft_reply());
        try {
            $this->act('pause', str_repeat('a', attempt::MAX_REPLY_CHARS + 1));
            $this->fail('Overlong draft must not be silently truncated.');
        } catch (\moodle_exception $e) {
            $this->assertSame('replytoolong', $e->errorcode);
        }
        $this->assertSame('Newest draft', $this->current()->draft_reply());
        $this->act('pause', '');
        $this->assertSame('', $this->current()->draft_reply());
        $this->assertSame(0, $this->current()->turns_used());
    }

    public function test_finish_requires_explicit_confirmation(): void {
        $this->act('start');
        $state = conversation::state($this->current());
        try {
            update_conversation::execute((int) $this->cm->id, 'finish', $state);
            $this->fail('Unconfirmed finish must not score the attempt.');
        } catch (\moodle_exception $e) {
            $this->assertSame('finishconfirmationrequired', $e->errorcode);
        }
        $this->assertSame($state, conversation::state($this->current()));
        $this->assertNull($this->current()->get_record()->score);
    }

    public function test_finish_blocks_unsent_text_and_clears_draft_only_after_explicit_submission(): void {
        $this->stub_ai(['closeafter' => 99]);
        $this->act('start');
        $this->act('reply', 'An answer already sent.');
        $this->act('pause', 'An answer still being written.');
        $state = conversation::state($this->current());
        $calls = count($this->sentprompts);
        try {
            $this->act('finish', 'An answer still being written.');
            $this->fail('An unsent draft must block final submission.');
        } catch (\moodle_exception $e) {
            $this->assertSame('finishunsent', $e->errorcode);
        }
        $this->assertSame($state, conversation::state($this->current()));
        $this->assertCount($calls, $this->sentprompts);
        // Clearing the box and confirming is an explicit decision to score only sent answers.
        $this->act('finish', '');
        $this->assertTrue($this->current()->is_finished());
        $this->assertSame('', $this->current()->draft_reply());
        $this->assertSame(['assessed', 'notassessed'], array_column($this->current()->lesson_results(), 'status'));
        $this->assertCount(1, array_filter($this->current()->messages(), fn($m) => $m->role === 'student'));
    }

    public function test_failed_reply_preserves_saved_draft_and_successful_reply_clears_it(): void {
        $this->act('start');
        $this->act('pause', 'Saved draft');
        $state = conversation::state($this->current());
        $this->stub_ai_garbage();
        try {
            $this->act('reply', 'Edited reply');
            $this->fail('Malformed provider output must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorbadresponse', $e->errorcode);
        }
        $this->assertSame($state, conversation::state($this->current()));
        $this->assertSame('Saved draft', $this->current()->draft_reply());
        $this->stub_ai(['closeafter' => 99]);
        $this->act('reply', 'Edited reply');
        $this->assertSame('', $this->current()->draft_reply());
        $this->assertSame(1, $this->current()->turns_used());
    }

    public function test_confirmation_counts_unanswered_lessons_and_post_errors_restore_exact_text(): void {
        $response = $this->act('start');
        $this->assertStringContainsString('Lessons without a submitted answer: 2', $response['html']);
        $this->assertStringContainsString('value="pause"', $response['html']);
        $this->assertStringContainsString('<details', $response['html']);
        $this->assertStringContainsString('Yes, submit and score', $response['html']);
        $this->stub_ai(['closeafter' => 99]);
        $response = $this->act('reply', 'A submitted answer.');
        $this->assertStringContainsString('Lessons without a submitted answer: 1', $response['html']);
        $this->act('pause', 'Old saved draft');
        $html = \mod_masteryagent\output\conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $this->current(), 'Edited unsent draft');
        $this->assertStringContainsString('Edited unsent draft</textarea>', $html);
        $this->assertStringNotContainsString('Old saved draft', $html);
        $html = \mod_masteryagent\output\conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $this->current(), '');
        $this->assertStringNotContainsString('Old saved draft', $html);
    }
}
