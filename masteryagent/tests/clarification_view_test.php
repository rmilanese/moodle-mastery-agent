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

use mod_masteryagent\output\conversation_view;

/**
 * Clarification controls preserve the original question and distinguish unassessed help in every view.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(conversation_view::class)]
final class clarification_view_test extends \advanced_testcase {
    /** @var \stdClass Activity under test. */
    private \stdClass $instance;

    /** @var \stdClass Course module. */
    private \stdClass $cm;

    /** @var \stdClass Enrolled learner. */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $course->id, 'lessonkeys' => 'S01,S02',
        ]);
        $this->cm = get_coursemodule_from_instance('masteryagent', $this->instance->id);
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->setUser($this->student);
    }

    /** Parse actual renderer output, with no JavaScript assumptions. */
    private function fragment(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    /** Render an active or finished attempt with an optional ordinary POST draft override. */
    private function render(attempt $current, ?string $draft = null): \DOMXPath {
        return $this->fragment(conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $current, $draft));
    }

    /** Start with the configured saved question, without any AI call. */
    private function start(): attempt {
        return attempt::start($this->instance, (int) $this->student->id, sequence::from_instance($this->instance));
    }

    public function test_clarification_uses_the_reply_form_but_bypasses_answer_validation_without_javascript(): void {
        $current = $this->start();
        $current->save_draft('Previously saved answer.');
        foreach (['', "  Unsent answer\nwith exact spacing  ", str_repeat('A', attempt::MAX_REPLY_CHARS + 1)] as $draft) {
            $xpath = $this->render($current, $draft);
            $this->assertSame(1, $xpath->query('//form')->length);
            $button = $xpath->query('//form[@data-action="reply"]//button[@name="action" and @value="clarify"]')->item(0);
            $this->assertNotNull($button);
            $this->assertSame('submit', $button->getAttribute('type'));
            $this->assertTrue($button->hasAttribute('formnovalidate'));
            $this->assertSame(get_string('clarifyquestion', 'mod_masteryagent'), $button->textContent);
            $help = $xpath->query('//*[@id="' . $button->getAttribute('aria-describedby') . '"]')->item(0);
            $this->assertNotNull($help);
            $this->assertSame(get_string('clarifyquestionhelp', 'mod_masteryagent'), $help->textContent);
            $this->assertSame(1, $xpath->query('//button[@value="clarify"]/parent::*'
                . '/preceding-sibling::*[@data-region="reply-context"]')->length);
            $this->assertSame(1, $xpath->query('//button[@value="clarify"]/parent::*'
                . '/following-sibling::label[@for="masteryagent-reply"]')->length);
            $editor = $xpath->query('//textarea[@name="reply"]')->item(0);
            $this->assertSame($draft, $editor->textContent);
            $this->assertTrue($editor->hasAttribute('required'));
            $this->assertSame('Previously saved answer.', $current->draft_reply());
            $this->assertSame(0, $current->turns_used());
        }
    }

    public function test_cached_help_is_escaped_beside_the_unchanged_prompt_without_duplicate_message_markers(): void {
        $current = $this->start();
        $original = array_values($current->messages())[0];
        $help = '<img src=x onerror=unsafe()> Restate the question <script>unsafe()</script>.';
        $current->add_message('clarification', $help, $original->lessonkey);
        $xpath = $this->render($current, 'Unsent draft.');
        $copy = $xpath->query('//*[@data-region="question-clarification"]')->item(0);
        $this->assertNotNull($copy);
        $this->assertSame($help, $xpath->query('//*[@data-region="question-clarification"]'
            . '/*[@class="masteryagent-clarification-text"]')->item(0)->textContent);
        $this->assertSame($original->message, $xpath->query('//*[@data-region="reply-context"]'
            . '/*[@class="masteryagent-context-text"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//*[@data-region="question-clarification"]/h3[@tabindex="-1"'
            . ' and @id="' . $copy->getAttribute('aria-labelledby') . '"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-region="question-clarification"]//*[@data-message-id]')->length);
        $this->assertSame(0, $xpath->query('//button[@value="clarify"] | //img | //script')->length);
        $this->assertSame(2, $xpath->query('//*[@data-message-id]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ",@class," ")," masteryagent-agent ")]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ",@class," ")," masteryagent-clarification ")]')->length);
        foreach ($xpath->query('//a[@data-conversation-jump and starts-with(@href,"#masteryagent-message-")]') as $link) {
            $this->assertSame('#masteryagent-message-' . $original->id, $link->getAttribute('href'));
        }
        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_a_new_evaluator_question_restores_the_button_and_keeps_previous_help_only_in_the_transcript(): void {
        $current = $this->start();
        $key = sequence::from_instance($this->instance)->key_for(0);
        $current->add_message('clarification', 'Help for the first question.', $key);
        $current->add_message('agent', 'Explain the second part.', $key);
        $xpath = $this->render($current);
        $this->assertSame(1, $xpath->query('//button[@value="clarify"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-region="question-clarification"]')->length);
        $this->assertSame('Explain the second part.', $xpath->query('//*[@data-region="reply-context"]'
            . '/*[@class="masteryagent-context-text"]')->item(0)->textContent);
        $this->assertStringContainsString('Help for the first question.', $xpath->query('//*[@data-region="current-lesson"]')
            ->item(0)->textContent);
        $this->assertStringNotContainsString('Help for the first question.', $xpath->query('//*[@data-region="reply-context"]')
            ->item(0)->textContent);
    }

    public function test_finished_and_historical_views_label_clarification_without_new_help_controls(): void {
        $current = $this->start();
        $current->add_message('clarification', 'Saved unassessed help.', sequence::from_instance($this->instance)->key_for(0));
        $record = clone $current->get_record();
        $record->status = attempt::STATUS_FINISHED;
        $record->score = 0;
        $record->summary = 'Saved overall feedback.';
        $finished = new attempt($record, $this->instance);
        foreach ([$this->render($finished), $this->fragment(conversation_view::render_review($finished)),
                $this->fragment(conversation_view::render_review($current))] as $xpath) {
            $this->assertSame(0, $xpath->query('//button[@value="clarify"] | //*[@data-region="question-clarification"]')->length);
            $help = $xpath->query('//*[contains(concat(" ",@class," ")," masteryagent-clarification ")]')->item(0);
            $this->assertNotNull($help);
            $this->assertStringContainsString(get_string('roleclarification', 'mod_masteryagent'), $help->textContent);
            $this->assertStringContainsString('Saved unassessed help.', $help->textContent);
        }
    }

    public function test_shared_report_and_conversation_message_renderer_distinguishes_help_from_assessed_messages(): void {
        foreach (['agent', 'student', 'clarification'] as $role) {
            $xpath = $this->fragment(conversation_view::render_message((object) [
                'id' => 71, 'role' => $role, 'message' => '<script>text only</script>',
            ]));
            $message = $xpath->query('//*[@data-message-id="71"]')->item(0);
            $this->assertNotNull($message);
            $this->assertSame('masteryagent-message masteryagent-' . $role, $message->getAttribute('class'));
            $this->assertSame(get_string('role' . $role, 'mod_masteryagent'),
                $xpath->query('//*[@class="masteryagent-role"]')->item(0)->textContent);
            $this->assertSame(0, $xpath->query('//script')->length);
            $this->assertStringContainsString('<script>text only</script>', $message->textContent);
        }
    }

    public function test_no_clarification_control_appears_without_an_evaluator_question(): void {
        $current = attempt::start($this->instance, (int) $this->student->id, new sequence([]));
        foreach (['student', 'clarification'] as $role) {
            $current->add_message($role, 'This is not an evaluator question.', 'orphaned-key');
            $xpath = $this->render($current);
            $this->assertSame(0, $xpath->query('//button[@value="clarify"] | //*[@data-region="question-clarification"]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-region="reply-context"]')->length);
        }
    }
}
