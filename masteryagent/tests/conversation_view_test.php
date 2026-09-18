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
 * Transcript grouping, navigation and historical content safety.
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
    private function fragment(attempt $current): \DOMXPath {
        $html = conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $current);
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    public function test_previous_lesson_collapses_and_current_lesson_and_all_messages_remain_available(): void {
        $current = $this->start_sample();
        update_conversation::execute((int) $this->cm->id, 'reply', conversation::state($current), 'First answer.');
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $xpath = $this->fragment($current);
        $this->assertSame(1, $xpath->query('//details[@data-region="lesson-history" and not(@open)]')->length);
        $this->assertSame(1, $xpath->query('//section[@data-region="current-lesson"]/h3')->length);
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
