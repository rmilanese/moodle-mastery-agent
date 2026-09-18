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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

namespace mod_masteryagent;

use mod_masteryagent\external\update_conversation;
use mod_masteryagent\output\conversation_view;

require_once(__DIR__ . '/helper_trait.php');

/**
 * Learner orientation, reply context, transcript navigation and historical content safety.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class conversation_view_test extends \advanced_testcase {
    use helper_trait;

    /** @var \stdClass Activity under test. */
    private \stdClass $instance;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \stdClass Enrolled learner. */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $course->id, 'lessonkeys' => 'S01,S02',
        ]);
        $this->cm = get_coursemodule_from_instance('masteryagent', $this->instance->id);
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->setUser($this->student);
    }

    protected function tearDown(): void {
        $this->reset_ai();
        parent::tearDown();
    }

    /** Start through the real endpoint, then fetch the saved attempt. */
    private function start_sample(): attempt {
        $this->stub_ai();
        update_conversation::execute((int) $this->cm->id, 'start', 'new');
        return attempt::get_latest($this->instance, (int) $this->student->id);
    }

    /** Render the learner fragment for XPath assertions. */
    private function fragment(?attempt $current = null, bool $showresume = false, ?string $draftoverride = null): \DOMXPath {
        $html = conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $current, $draftoverride, $showresume);
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    public function test_overview_explains_actual_settings_retry_provisional_and_submission_before_starting(): void {
        $this->instance->maxturns = 9;
        $this->instance->maxgrade = 7;
        $this->instance->threshold = 5;
        $this->instance->allowretry = 1;
        $this->instance->provisional = 1;
        $xpath = $this->fragment();
        $overview = $xpath->query('//section[@data-region="before-begin"]')->item(0);
        $this->assertNotNull($overview);
        $text = $overview->textContent;
        $this->assertStringContainsString('2 lessons, with up to 9 replies per lesson', $text);
        $this->assertStringContainsString('Each lesson is worth 7 points, with a mastery threshold of 5 points', $text);
        $this->assertStringContainsString('14 points in total', $text);
        $this->assertStringContainsString('next lesson opens automatically', $text);
        $this->assertStringContainsString('score is submitted when the last lesson closes', $text);
        $this->assertStringContainsString('gradebook keeps your highest score', $text);
        $this->assertStringContainsString('AI-provisional', $text);
        $this->assertStringContainsString('Save and leave', $text);
        $this->assertStringContainsString('Unanswered lessons contribute 0 points', $text);
        $this->assertStringContainsString('course instructions on permitted materials', $text);
        $this->assertSame(1, $xpath->query('//*[@data-region="before-begin"]/following-sibling::div'
            . '/form[@data-action="start"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-message-id] | //*[@data-region="reply-context"]')->length);
        $this->assertStringNotContainsString(sequence::from_instance($this->instance)->get(0)->question_text(), $text);
    }

    public function test_single_lesson_overview_does_not_promise_retries_or_provisional_grades_when_disabled(): void {
        $records = json_decode($this->instance->sequencejson, true);
        $this->instance->sequencejson = json_encode([$records[0]]);
        $this->instance->maxturns = 3;
        $this->instance->maxgrade = 8;
        $this->instance->threshold = 6;
        $this->instance->allowretry = 0;
        $this->instance->provisional = 0;
        $xpath = $this->fragment();
        $text = $xpath->query('//*[@data-region="before-begin"]')->item(0)->textContent;
        $this->assertStringContainsString('One lesson, with up to 3 replies', $text);
        $this->assertStringContainsString('8 points in total', $text);
        $this->assertStringContainsString('Repeat attempts are not enabled', $text);
        $this->assertStringContainsString('score is submitted when the lesson closes', $text);
        $this->assertStringNotContainsString('highest score', $text);
        $this->assertStringNotContainsString('AI-provisional', $text);
        $this->assertStringNotContainsString('next lesson opens', $text);
    }

    public function test_initial_question_is_beside_reply_without_duplicate_scenario_or_transcript_markers(): void {
        $current = $this->start_sample();
        $xpath = $this->fragment($current);
        $contexts = $xpath->query('//section[@data-region="reply-context"]');
        $this->assertSame(1, $contexts->length);
        $this->assertStringContainsString(sequence::from_instance($this->instance)->get(0)->question_text(),
            $contexts->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-region="original-scenario"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-message-id]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-region="reply-context"]//*[@data-message-id'
            . ' or contains(concat(" ", @class, " "), " masteryagent-agent ")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="reply-context"]/following-sibling::label'
            . '[@for="masteryagent-reply"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="reply-context"]//a'
            . '[@href="#masteryagent-reply" and @data-conversation-jump="masteryagent-reply"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-region="before-begin"]')->length);
    }

    public function test_followup_context_retains_saved_original_scenario_when_uploaded_content_changes(): void {
        $current = $this->start_sample();
        $messages = $current->messages();
        $opening = reset($messages);
        $current->add_message('student', 'My explanation.', $opening->lessonkey);
        $current->add_message('agent', "What supports your conclusion?\nExplain the tradeoff.", $opening->lessonkey);
        $records = json_decode($this->instance->sequencejson, true);
        $records[0]['question_text'] = 'Replacement upload must not rewrite the scenario already shown.';
        $this->instance->sequencejson = json_encode($records);
        $xpath = $this->fragment($current);
        $context = $xpath->query('//*[@data-region="reply-context"]')->item(0);
        $scenario = $xpath->query('//details[@data-region="original-scenario" and not(@open)]')->item(0);
        $this->assertNotNull($scenario);
        $this->assertStringContainsString('What supports your conclusion?', $context->textContent);
        $this->assertStringContainsString($opening->message, $scenario->textContent);
        $this->assertStringNotContainsString($records[0]['question_text'], $context->textContent);
        $this->assertSame('masteryagent-scenario-' . $opening->id, $scenario->getAttribute('data-section-key'));
        $this->assertSame(count($current->messages()), $xpath->query('//*[@data-message-id]')->length);
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_legacy_context_uses_only_the_final_contiguous_group_and_escapes_both_messages(): void {
        $current = $this->start_sample();
        $current->add_message('agent', '<img src=x onerror=alert(1)> Original legacy scenario', '');
        $current->add_message('student', 'A legacy answer.', '');
        $current->add_message('agent', '<script>attack()</script> Follow-up & reasoning', '');
        $xpath = $this->fragment($current);
        $context = $xpath->query('//*[@data-region="reply-context"]')->item(0);
        $scenario = $xpath->query('//*[@data-region="original-scenario"]')->item(0);
        $this->assertStringContainsString('<script>attack()</script> Follow-up & reasoning', $context->textContent);
        $this->assertStringContainsString('<img src=x onerror=alert(1)> Original legacy scenario', $scenario->textContent);
        $this->assertStringNotContainsString(sequence::from_instance($this->instance)->get(0)->question_text(),
            $context->textContent);
        $this->assertSame(0, $xpath->query('//img | //script')->length);
        $this->assertSame(count($current->messages()), $xpath->query('//*[@data-message-id]')->length);
    }

    public function test_current_group_without_evaluator_does_not_repeat_an_old_lessons_question(): void {
        $current = $this->start_sample();
        $current->add_message('student', 'Legacy final group without an evaluator message.', '');
        $xpath = $this->fragment($current);
        $this->assertSame(0, $xpath->query('//*[@data-region="reply-context"]')->length);
        $this->assertSame(1, $xpath->query('//textarea[@id="masteryagent-reply"]')->length);
        $this->assertSame(count($current->messages()), $xpath->query('//*[@data-message-id]')->length);
    }

    public function test_resume_summary_requires_an_unfinished_attempt_and_an_explicit_opening_request(): void {
        $xpath = $this->fragment(null, true);
        $this->assertSame(0, $xpath->query('//*[@data-region="resume-summary"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="before-begin"]')->length);
        $current = $this->start_sample();
        $xpath = $this->fragment($current);
        $this->assertSame(0, $xpath->query('//*[@data-region="resume-summary"]')->length);
        $xpath = $this->fragment($current, true);
        $this->assertSame(1, $xpath->query('//*[@data-region="resume-summary"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="resume-summary"]/following-sibling::nav'
            . '[@data-region="conversation-navigation"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="resume-summary"]//a'
            . '[@href="#masteryagent-reply" and @data-conversation-jump="masteryagent-reply"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="masteryagent-reply"]')->length);
    }

    public function test_resume_summary_reports_actual_progress_and_saved_draft_without_copying_private_text(): void {
        $current = $this->start_sample();
        update_conversation::execute((int) $this->cm->id, 'reply', conversation::state($current), 'First answer.');
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $draft = 'My private unfinished explanation <script>unsafe()</script>.';
        $current->save_draft($draft);
        $records = json_decode($this->instance->sequencejson, true);
        $records[1]['lesson_title'] = '<img src=x onerror=alert(1)> Lesson title';
        $this->instance->sequencejson = json_encode($records);
        $xpath = $this->fragment($current, true);
        $summary = $xpath->query('//*[@data-region="resume-summary"]')->item(0);
        $this->assertStringContainsString('Welcome back', $summary->textContent);
        $this->assertStringContainsString('Lesson 2 of 2:', $summary->textContent);
        $this->assertStringContainsString('<img src=x onerror=alert(1)> Lesson title', $summary->textContent);
        $this->assertStringContainsString('Lessons completed: 1 of 2.', $summary->textContent);
        $this->assertStringContainsString('Replies remaining: ' . $current->turns_left(), $summary->textContent);
        $this->assertStringContainsString('Your saved draft is available in the reply box.', $summary->textContent);
        $this->assertStringNotContainsString($draft, $summary->textContent);
        $this->assertSame($draft, $xpath->query('//textarea[@id="masteryagent-reply"]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//img | //script')->length);
        $this->assertSame(1, substr_count($xpath->document->textContent, 'Lesson 2 of 2:'));
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_resume_summary_does_not_claim_an_unsaved_answer_has_been_saved(): void {
        $current = $this->start_sample();
        $unsaved = 'Text restored from a failed request has not been saved.';
        $xpath = $this->fragment($current, true, $unsaved);
        $status = $xpath->query('//*[@data-region="resume-draft-status"]')->item(0)->textContent;
        $this->assertSame(get_string('resumenodraft', 'mod_masteryagent'), $status);
        $this->assertStringContainsString('Choose Save and leave', $status);
        $this->assertStringNotContainsString('Your saved draft is available', $status);
        $this->assertSame($unsaved, $xpath->query('//textarea[@id="masteryagent-reply"]')->item(0)->textContent);
    }

    public function test_reply_guidance_and_limit_work_without_javascript_and_do_not_truncate_restored_text(): void {
        $current = $this->start_sample();
        $draft = str_repeat('A', attempt::MAX_REPLY_CHARS + 1) . '<script>unsafe()</script>';
        $xpath = $this->fragment($current, false, $draft);
        $editor = $xpath->query('//textarea[@id="masteryagent-reply"]')->item(0);
        $this->assertSame((string) attempt::MAX_REPLY_CHARS, $editor->getAttribute('maxlength'));
        $this->assertSame($draft, $editor->textContent);
        $this->assertSame(0, $xpath->query('//script')->length);
        $describedby = explode(' ', $editor->getAttribute('aria-describedby'));
        $this->assertSame(['masteryagent-reply-guidance', 'masteryagent-reply-limit'], $describedby);
        foreach ($describedby as $id) {
            $this->assertSame(1, $xpath->query('//*[@id="' . $id . '" and not(@hidden)]')->length);
        }
        $this->assertStringContainsString('explain your reasoning',
            $xpath->query('//*[@id="masteryagent-reply-guidance"]')->item(0)->textContent);
        $this->assertStringContainsString('Maximum: ' . attempt::MAX_REPLY_CHARS . ' characters',
            $xpath->query('//*[@id="masteryagent-reply-limit"]')->item(0)->textContent);
        $counter = $xpath->query('//*[@data-region="reply-counter" and @hidden]')->item(0);
        $this->assertNotNull($counter);
        $this->assertSame('', $counter->textContent);
        $this->assertFalse($counter->hasAttribute('aria-live'));
        $this->assertFalse($counter->hasAttribute('role'));
        $this->assertStringContainsString('{remaining}', $counter->getAttribute('data-remaining'));
        $this->assertNotSame('', $counter->getAttribute('data-overlimit'));
    }

    public function test_previous_lesson_collapses_and_current_lesson_and_all_messages_remain_available(): void {
        $current = $this->start_sample();
        update_conversation::execute((int) $this->cm->id, 'reply', conversation::state($current), 'First answer.');
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $xpath = $this->fragment($current);
        $this->assertSame(1, $xpath->query('//details[@data-region="lesson-history" and not(@open)]')->length);
        $this->assertSame(1, $xpath->query('//section[@data-region="current-lesson"]/h3')->length);
        $this->assertStringContainsString(sequence::from_instance($this->instance)->get(1)->question_text(),
            $xpath->query('//*[@data-region="reply-context"]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-region="original-scenario"]')->length);
        $this->assertSame(count($current->messages()), $xpath->query('//*[@data-message-id]')->length);
        foreach ($xpath->query('//a[@data-conversation-jump]') as $link) {
            $target = $link->getAttribute('data-conversation-jump');
            $this->assertSame(1, $xpath->query('//*[@id="' . $target . '"]')->length);
            $this->assertSame('#' . $target, $link->getAttribute('href'));
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_finished_attempt_collapses_transcript_and_links_to_visible_results(): void {
        $current = $this->start_sample();
        foreach (['First answer.', 'Second answer.'] as $reply) {
            update_conversation::execute((int) $this->cm->id, 'reply', conversation::state($current), $reply);
            $current = attempt::get_latest($this->instance, (int) $this->student->id);
        }
        $xpath = $this->fragment($current);
        $this->assertSame(0, $xpath->query('//*[@data-region="current-lesson"]')->length);
        $this->assertSame(2, $xpath->query('//details[@data-region="lesson-history" and not(@open)]')->length);
        $this->assertSame(1, $xpath->query('//a[@href="#masteryagent-results"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="masteryagent-results" and @tabindex="-1"]')->length);
        $this->assertSame(0, $xpath->query('//details//*[@data-region="results"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-region="reply-context"] | //*[@data-region="before-begin"]')->length);
        $xpath = $this->fragment($current, true);
        $this->assertSame(0, $xpath->query('//*[@data-region="resume-summary"]')->length);
    }

    public function test_legacy_and_repeated_keys_preserve_every_message_in_chronological_order(): void {
        $current = $this->start_sample();
        $current->add_message('student', 'Legacy message', '');
        $current->add_message('agent', 'Unknown lesson message', 'removed-key');
        $key = sequence::from_instance($this->instance)->key_for(0);
        $current->add_message('agent', 'Repeated lesson message', $key);
        $xpath = $this->fragment($current);
        $this->assertSame(3, $xpath->query('//details[@data-region="lesson-history"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="current-lesson"]')->length);
        $actual = [];
        foreach ($xpath->query('//*[@data-message-id]') as $node) {
            $actual[] = (int) $node->getAttribute('data-message-id');
        }
        $expected = array_values(array_map(static fn($message) => (int) $message->id, $current->messages()));
        $this->assertSame($expected, $actual);
        $this->assertStringContainsString('Conversation section 2', $xpath->document->textContent);
        $this->assertStringContainsString('Conversation section 3', $xpath->document->textContent);
        $this->assertStringContainsString('Repeated lesson message',
            $xpath->query('//*[@data-region="reply-context"]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-region="original-scenario"]')->length);
    }

    public function test_saved_titles_and_message_text_are_escaped_in_navigation_and_history(): void {
        $current = $this->start_sample();
        update_conversation::execute((int) $this->cm->id, 'reply', conversation::state($current), 'First answer.');
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $results = $current->lesson_results();
        $results[0]['title'] = '<img src=x onerror=alert(1)> Original lesson';
        $record = $current->get_record();
        $record->lessonscores = json_encode($results);
        $current = new attempt($record, $this->instance);
        $current->add_message('agent', '<script>privateAttack()</script>',
            sequence::from_instance($this->instance)->key_for(1));
        $xpath = $this->fragment($current);
        $this->assertSame(0, $xpath->query('//img | //script')->length);
        $this->assertStringContainsString('<img src=x', $xpath->query('//summary')->item(0)->textContent);
        $this->assertStringContainsString('Original lesson',
            $xpath->query('//nav[@data-region="conversation-navigation"]')->item(0)->textContent);
    }
}
