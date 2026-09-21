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

require_once(__DIR__ . '/helper_trait.php');

/**
 * The conversation itself: turns, lesson transitions, scoring and recovery.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt::class)]
final class attempt_test extends \advanced_testcase {

    use helper_trait;

    /** @var \stdClass The course under test. */
    private \stdClass $course;

    /** @var \stdClass The learner. */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $this->course->id, 'student');
    }

    protected function tearDown(): void {
        $this->reset_ai();
        parent::tearDown();
    }

    /**
     * Build an activity and return everything a test needs to drive it.
     *
     * @param string $lessonkeys Which fixture lessons to load.
     * @param array $settings Instance setting overrides.
     * @return array [instance, sequence, contextid]
     */
    private function make_activity(string $lessonkeys, array $settings = []): array {
        $instance = $this->getDataGenerator()->create_module('masteryagent', $settings + [
            'course' => $this->course->id,
            'lessonkeys' => $lessonkeys,
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);

        return [$instance, sequence::from_instance($instance), (int) \context_module::instance($cm->id)->id];
    }

    public function test_an_attempt_opens_with_the_lesson_question(): void {
        [$instance, $sequence] = $this->make_activity('S01');

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);

        $this->assertFalse($attempt->is_finished());
        $this->assertSame(0, $attempt->lesson_index());
        $this->assertSame(6, $attempt->turns_left());

        $messages = $attempt->messages();
        $this->assertCount(1, $messages);
        $first = reset($messages);
        $this->assertSame('agent', $first->role);
        $this->assertSame('S01-Q01', $first->lessonkey);
        $this->assertStringContainsString('digital map display', $first->message);
    }

    public function test_a_reply_is_recorded_and_answered(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 99, 'reply' => 'What would you check yourself?']);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('Maps go out of date.', $sequence, $contextid);

        $this->assertSame(1, $attempt->turns_used());
        $this->assertSame(5, $attempt->turns_left());
        $this->assertFalse($attempt->is_finished());
        $this->assertSame(['SE1'], $attempt->ledger()['covered']);

        $messages = array_values($attempt->messages());
        $this->assertCount(3, $messages);
        $this->assertSame('student', $messages[1]->role);
        $this->assertSame('Maps go out of date.', $messages[1]->message);
        $this->assertSame('What would you check yourself?', $messages[2]->message);
    }

    public function test_an_empty_reply_is_ignored(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 99]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('    ', $sequence, $contextid);

        $this->assertSame(0, $attempt->turns_used());
        $this->assertCount(1, $attempt->messages());
    }

    public function test_an_overlong_reply_is_capped(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 99]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit(str_repeat('a', attempt::MAX_REPLY_CHARS + 500), $sequence, $contextid);

        $messages = array_values($attempt->messages());
        $this->assertSame(attempt::MAX_REPLY_CHARS, \core_text::strlen($messages[1]->message));
    }

    public function test_a_single_lesson_closes_when_the_evidence_is_in(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 2, 'scores' => ['S01' => 4]]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('First answer.', $sequence, $contextid);
        $this->assertFalse($attempt->is_finished());

        $attempt->submit('Second answer.', $sequence, $contextid);
        $this->assertTrue($attempt->is_finished());

        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(4.0, (float) $record->score);
        $this->assertSame('Closing feedback for S01.', $record->summary);

        $results = (new attempt($record, $instance))->lesson_results();
        $this->assertCount(1, $results);
        $this->assertSame('assessed', $results[0]['status']);
        $this->assertSame('S01', $results[0]['lesson_id']);
        $this->assertSame(1, (new attempt($record, $instance))->lessons_mastered());
        $this->assertTrue((new attempt($record, $instance))->met_threshold());
    }

    public function test_the_reply_budget_closes_a_lesson(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01', ['maxturns' => 3]);
        $this->stub_ai(['closeafter' => 99, 'scores' => ['S01' => 1]]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        foreach (['one', 'two', 'three'] as $reply) {
            $attempt->submit($reply, $sequence, $contextid);
        }

        $this->assertTrue($attempt->is_finished());
        $this->assertSame(3, $attempt->turns_used());
        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(1.0, (float) $record->score);
        $this->assertFalse((new attempt($record, $instance))->met_threshold());
    }

    public function test_a_sequence_advances_on_its_own(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02,S03');
        $this->stub_ai(['closeafter' => 1]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('Answer to lesson one.', $sequence, $contextid);

        $this->assertSame(1, $attempt->lesson_index());
        $this->assertFalse($attempt->is_finished());
        $this->assertSame(0, $attempt->turns_used(), 'the budget resets for the new lesson');
        $this->assertCount(1, $attempt->lesson_results());
        $this->assertSame(
            ['covered' => [], 'misconceptions' => [], 'resolved' => []],
            $attempt->ledger(),
            'the new lesson starts with a clean ledger'
        );

        $messages = array_values($attempt->messages());
        $transition = $messages[count($messages) - 2];
        $this->assertStringContainsString('closes lesson 1 of 3', $transition->message);
        $this->assertStringContainsString('S02 Deciding Under Time Pressure', $transition->message);

        $question = end($messages);
        $this->assertSame('S02-Q01', $question->lessonkey);
        $this->assertStringContainsString('before it can gather everything', $question->message);
    }

    public function test_a_finished_sequence_totals_and_synthesises(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02,S03');
        $this->stub_ai([
            'closeafter' => 1,
            'scores' => ['S01' => 4, 'S02' => 2, 'S03' => 3],
            'coursesummary' => 'You explain concepts but stop short of mechanism.',
        ]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        foreach (['one', 'two', 'three'] as $reply) {
            $attempt->submit($reply, $sequence, $contextid);
        }

        $this->assertTrue($attempt->is_finished());

        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(9.0, (float) $record->score, 'the total is the sum of the lessons');
        $this->assertSame('You explain concepts but stop short of mechanism.', $record->summary);

        $reloaded = new attempt($record, $instance);
        $results = $reloaded->lesson_results();
        $this->assertCount(3, $results);
        $this->assertSame(['S01', 'S02', 'S03'], array_column($results, 'lesson_id'));
        $this->assertSame('Closing feedback for S02.', $results[1]['summary']);
        $this->assertSame(2, $reloaded->lessons_mastered());
        $this->assertFalse($reloaded->met_threshold(), 'not every lesson cleared the threshold');
    }

    public function test_only_one_course_summary_is_requested(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02');
        $this->stub_ai(['closeafter' => 1]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('one', $sequence, $contextid);
        $attempt->submit('two', $sequence, $contextid);

        $summaries = array_filter($this->sentprompts, fn($p) => str_contains($p, '=== LESSON RESULTS ==='));
        $this->assertCount(1, $summaries);
    }

    public function test_a_single_lesson_run_does_not_ask_for_a_course_summary(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 1]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('one', $sequence, $contextid);

        $summaries = array_filter($this->sentprompts, fn($p) => str_contains($p, '=== LESSON RESULTS ==='));
        $this->assertCount(0, $summaries);
    }

    public function test_stopping_early_scores_only_what_was_reached(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02,S03');
        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 4]]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('Answer to lesson one.', $sequence, $contextid);
        $attempt->finish_now($sequence, $contextid);

        $this->assertTrue($attempt->is_finished());
        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(4.0, (float) $record->score, 'lessons never reached contribute nothing');
        $reloaded = new attempt($record, $instance);
        $results = $reloaded->lesson_results();
        $this->assertCount(3, $results);
        $this->assertSame(['assessed', 'notassessed', 'notassessed'], array_column($results, 'status'));
        $this->assertSame(['S01', 'S02', 'S03'], array_column($results, 'lesson_id'));
        foreach ([1, 2] as $index) {
            $this->assertSame($sequence->key_for($index), $results[$index]['key']);
            $this->assertSame($sequence->get($index)->title(), $results[$index]['title']);
            $this->assertSame(0, $results[$index]['score']);
            $this->assertSame((int) $instance->maxgrade, $results[$index]['max']);
            $this->assertSame($sequence->get($index)->learning_resources(), $results[$index]['learning_resources']);
            foreach (['summary', 'dimensions', 'strengths', 'gaps', 'next_step'] as $feedback) {
                $this->assertArrayNotHasKey($feedback, $results[$index]);
            }
        }
        $summaryprompts = array_values(array_filter($this->sentprompts,
            static fn($prompt) => str_contains($prompt, '=== LESSON RESULTS ===')));
        $this->assertCount(1, $summaryprompts);
        $this->assertStringContainsString('S01', $summaryprompts[0]);
        $this->assertStringNotContainsString('S02', $summaryprompts[0]);
        $this->assertStringNotContainsString('S03', $summaryprompts[0]);
        $instance->sequencejson = '[]';
        $instance->lessonjson = '';
        $instance->threshold = 0;
        $historical = new attempt($record, $instance);
        $this->assertSame($results, $historical->lesson_results());
        $this->assertSame(1, $historical->lessons_mastered(), 'Skipped lessons never count as mastered at threshold zero.');
        $this->assertFalse($historical->met_threshold());
    }

    public function test_finishing_before_answering_anything_scores_zero(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02,S03', ['threshold' => 0]);
        $this->stub_ai();

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->add_message('agent', 'Another evaluator message without a learner answer.', $sequence->key_for(0));
        $attempt->finish_now($sequence, $contextid);

        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()]);
        $this->assertTrue($attempt->is_finished());
        $this->assertSame(0.0, (float) $record->score);
        $reloaded = new attempt($record, $instance);
        $this->assertSame(['notassessed', 'notassessed', 'notassessed'],
            array_column($reloaded->lesson_results(), 'status'));
        $this->assertSame(0, $reloaded->lessons_mastered());
        $this->assertFalse($reloaded->met_threshold());
        $this->assertSame('', $record->summary);
        $this->assertCount(0, $this->sentprompts);
    }

    public function test_early_submission_scores_the_answered_open_lesson_without_opening_another_question(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02,S03');
        $this->stub_ai(['closeafter' => 99, 'scores' => ['S01' => 3]]);
        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('My answer so far.', $sequence, $contextid);
        $messages = serialize($attempt->messages());
        $attempt->finish_now($sequence, $contextid);
        $this->assertTrue($attempt->is_finished());
        $this->assertSame($messages, serialize($attempt->messages()), 'Final submission added a transition or next question.');
        $this->assertSame(0, $attempt->lesson_index());
        $this->assertSame(1, $attempt->turns_used());
        $this->assertSame(['assessed', 'notassessed', 'notassessed'], array_column($attempt->lesson_results(), 'status'));
        $this->assertCount(1, array_filter($this->sentprompts,
            static fn($prompt) => str_contains($prompt, '=== FULL CONVERSATION ===')));
        $this->assertCount(1, array_filter($this->sentprompts,
            static fn($prompt) => str_contains($prompt, '=== LESSON RESULTS ===')));
        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()], '*', MUST_EXIST);
        $calls = count($this->sentprompts);
        $attempt->finish_now($sequence, $contextid);
        $this->assertEquals($record, $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()], '*', MUST_EXIST));
        $this->assertCount($calls, $this->sentprompts, 'Repeated finalization generated another assessment.');

        // A repeated question key does not make answers from its earlier occurrence count in the newly opened lesson.
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S01');
        $this->assertSame(2, $sequence->count());
        $this->stub_ai(['closeafter' => 1]);
        $repeated = attempt::start($instance, (int) $this->student->id, $sequence);
        $repeated->submit('Answered the first occurrence only.', $sequence, $contextid);
        $this->assertSame(0, $repeated->turns_used());
        $messages = serialize($repeated->messages());
        $repeated->finish_now($sequence, $contextid);
        $this->assertSame(['assessed', 'notassessed'], array_column($repeated->lesson_results(), 'status'));
        $this->assertSame($messages, serialize($repeated->messages()));
        $this->assertCount(1, array_filter($this->sentprompts,
            static fn($prompt) => str_contains($prompt, '=== FULL CONVERSATION ===')));
    }

    public function test_decimal_scores_agree_in_saved_feedback_total_and_mastery_decision(): void {
        global $DB;
        foreach ([[2.6, 2.6, false], [2.999, 3.0, true], [2.994, 2.99, false]] as [$provided, $expected, $mastered]) {
            [$instance, $sequence, $contextid] = $this->make_activity('S01', ['threshold' => 3]);
            $this->stub_ai(['scores' => ['S01' => $provided]]);
            $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
            $attempt->submit('A completed answer.', $sequence, $contextid);
            $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()], '*', MUST_EXIST);
            $reloaded = new attempt($record, $instance);
            $this->assertSame($expected, (float) $reloaded->lesson_results()[0]['score']);
            $this->assertSame($expected, (float) $record->score);
            $this->assertSame($mastered, $reloaded->met_threshold());
            $this->assertSame($mastered ? 1 : 0, $reloaded->lessons_mastered());
        }
    }

    public function test_legacy_results_without_status_remain_assessed_without_rewriting_them(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01,S02');
        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $record = $attempt->get_record();
        $record->status = attempt::STATUS_FINISHED;
        $record->score = 3.5;
        $record->lessonscores = json_encode([['key' => $sequence->key_for(0), 'score' => 3.5, 'max' => 4]]);
        $original = $record->lessonscores;
        $legacy = new attempt($record, $instance);
        $this->assertSame(1, $legacy->lessons_mastered());
        $this->assertTrue($legacy->met_threshold());
        $this->assertSame($original, $legacy->get_record()->lessonscores);
        $this->assertArrayNotHasKey('status', $legacy->lesson_results()[0]);

        // A newly finalized older active attempt gets a complete inventory without changing existing feedback.
        $activerecord = clone $record;
        $activerecord->status = attempt::STATUS_INPROGRESS;
        $activerecord->lessonindex = 1;
        $activerecord->turnsused = 0;
        $active = new attempt($activerecord, $instance);
        $active->add_message('agent', $sequence->get(1)->question_text(), $sequence->key_for(1));
        $this->stub_ai();
        $active->finish_now($sequence, $contextid);
        $this->assertSame(['assessed', 'notassessed'], array_column($active->lesson_results(), 'status'));
        $first = $active->lesson_results()[0];
        unset($first['status']);
        $this->assertSame(json_decode($original, true)[0], $first);
    }

    public function test_a_provider_failure_keeps_the_attempt_open(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai_garbage();

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);

        try {
            $attempt->submit('An answer that must survive.', $sequence, $contextid);
            $this->fail('expected the unreadable response to raise');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $record = $DB->get_record('masteryagent_attempt', ['id' => $attempt->get_id()]);
        $this->assertSame(attempt::STATUS_INPROGRESS, $record->status);
        $this->assertSame(1, $DB->count_records('masteryagent_message', [
            'attemptid' => $attempt->get_id(),
            'role' => 'student',
        ]), 'the learner reply is kept so it can be resent');
    }

    public function test_a_finished_attempt_ignores_further_replies(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 1]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('one', $sequence, $contextid);
        $before = count($attempt->messages());

        $attempt->submit('two', $sequence, $contextid);

        $this->assertCount($before, $attempt->messages());
    }

    public function test_the_latest_attempt_and_best_score_are_found(): void {
        [$instance, $sequence, $contextid] = $this->make_activity('S01');

        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 4]]);
        $first = attempt::start($instance, (int) $this->student->id, $sequence);
        $first->submit('strong', $sequence, $contextid);

        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 1]]);
        $second = attempt::start($instance, (int) $this->student->id, $sequence);
        $second->submit('weak', $sequence, $contextid);

        $latest = attempt::get_latest($instance, (int) $this->student->id);
        $this->assertSame($second->get_id(), $latest->get_id());
        $this->assertSame(4.0, attempt::best_score((int) $instance->id, (int) $this->student->id));
        $this->assertCount(2, attempt::all_for_instance($instance));
    }

    public function test_no_attempt_yet_returns_nothing(): void {
        [$instance] = $this->make_activity('S01');

        $this->assertNull(attempt::get_latest($instance, (int) $this->student->id));
        $this->assertNull(attempt::best_score((int) $instance->id, (int) $this->student->id));
    }

    public function test_deleting_an_activity_removes_its_conversations(): void {
        global $DB;
        [$instance, $sequence, $contextid] = $this->make_activity('S01');
        $this->stub_ai(['closeafter' => 1]);

        $attempt = attempt::start($instance, (int) $this->student->id, $sequence);
        $attempt->submit('one', $sequence, $contextid);

        attempt::delete_all_for_instance((int) $instance->id);

        $this->assertSame(0, $DB->count_records('masteryagent_attempt', ['masteryagentid' => $instance->id]));
        $this->assertSame(0, $DB->count_records('masteryagent_message', ['attemptid' => $attempt->get_id()]));
    }
}
