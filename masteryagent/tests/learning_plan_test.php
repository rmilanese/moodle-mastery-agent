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
 * Learning feedback, historical results and the learner-facing data boundary.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class learning_plan_test extends \advanced_testcase {
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

    /**
     * Finish both lessons through the real AJAX entry point and shared renderer.
     *
     * @return array AJAX response.
     */
    private function finish_sample(): array {
        $this->stub_ai();
        update_conversation::execute((int) $this->cm->id, 'start', 'new');
        $response = [];
        foreach (['First answer.', 'Second answer.'] as $reply) {
            $current = attempt::get_latest($this->instance, (int) $this->student->id);
            $response = update_conversation::execute((int) $this->cm->id, 'reply',
                conversation::state($current), $reply);
        }
        return $response;
    }

    public function test_completed_feedback_keeps_public_names_and_readings_after_content_changes(): void {
        global $DB;
        $records = json_decode($this->instance->sequencejson, true);
        $records[0]['dimension_names'] = [
            'S01-MD01' => 'Reading terrain',
            'S01-MD02' => 'Comparing sources',
            'OTHER' => 'UNASSESSED_NAME',
        ];
        $records[0]['source_evidence'][0]['url'] = 'https://example.org/reading#page=2';
        $records[0]['source_evidence'][0]['verification_status'] = 'PRIVATE_SOURCE_NOTE';
        $records[0]['validation_notes'] = ['PRIVATE_REVIEW_NOTE'];
        $this->instance->sequencejson = json_encode($records);
        $DB->update_record('masteryagent', $this->instance);

        $response = $this->finish_sample();
        foreach (['What you understand', 'What needs work', 'What to do next',
                'Strength in S01.', 'Gap in S01.', 'Next step for S01.', 'Reading terrain',
                'Sample Lesson 1 Reading', 'pp. 1-6', 'https://example.org/reading#page=2'] as $text) {
            $this->assertStringContainsString($text, $response['html']);
        }
        foreach (['PRIVATE_SOURCE_NOTE', 'PRIVATE_REVIEW_NOTE', 'UNASSESSED_NAME', 'S01-MD01'] as $private) {
            $this->assertStringNotContainsString($private, $response['html']);
        }
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $saved = $current->lesson_results()[0];
        $this->assertArrayNotHasKey('OTHER', $saved['dimension_names']);
        $this->assertSame(['title', 'edition_or_date', 'coursebook_page_or_section', 'url'],
            array_keys($saved['learning_resources'][0]));

        // An instructor replacing content must not relabel a completed result.
        $records[0]['dimension_names']['S01-MD01'] = 'Replacement skill';
        $records[0]['source_evidence'][0]['title'] = 'Replacement reading';
        $this->instance->sequencejson = json_encode($records);
        $DB->update_record('masteryagent', $this->instance);
        $html = conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $current);
        $this->assertStringContainsString('Reading terrain', $html);
        $this->assertStringContainsString('Sample Lesson 1 Reading', $html);
        $this->assertStringNotContainsString('Replacement skill', $html);
        $this->assertStringNotContainsString('Replacement reading', $html);
    }

    public function test_older_results_have_honest_fallbacks_without_internal_codes(): void {
        global $DB;
        $this->finish_sample();
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $record = $current->get_record();
        $results = $current->lesson_results();
        foreach ($results as &$result) {
            unset($result['dimension_names'], $result['learning_resources'],
                $result['strengths'], $result['gaps'], $result['next_step']);
        }
        unset($result);
        $record->lessonscores = json_encode($results);
        $DB->update_record('masteryagent_attempt', $record);
        $html = conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), new attempt($record, $this->instance));
        $this->assertStringContainsString('Score: 6 of 8', $html);
        $this->assertStringContainsString('Closing feedback for S01.', $html);
        $this->assertStringContainsString('Assessed skill 1', $html);
        $this->assertStringContainsString('No specific strengths were recorded', $html);
        $this->assertStringContainsString('This does not mean every learning objective was assessed', $html);
        $this->assertStringContainsString('No reading references were saved', $html);
        $this->assertStringNotContainsString('S01-MD01', $html);
    }

    public function test_learning_feedback_escapes_text_and_rejects_unsafe_reading_links(): void {
        $this->finish_sample();
        $current = attempt::get_latest($this->instance, (int) $this->student->id);
        $record = $current->get_record();
        $results = $current->lesson_results();
        $results[0]['strengths'] = ['<img src=x onerror=alert(1)>', ['invalid']];
        $results[0]['gaps'] = ['<script>attack()</script>'];
        $results[0]['next_step'] = '<b>Review this</b>';
        $results[0]['dimension_names'] = ['S01-MD01' => '<em>Terrain</em>'];
        $results[0]['learning_resources'] = [
            ['title' => 'Unsafe reading', 'url' => 'javascript:alert(1)'],
            ['title' => 'Data reading', 'url' => 'data:text/html,attack'],
            ['title' => 'Relative reading', 'url' => '//example.org'],
            ['title' => '<strong>Safe reading</strong>', 'url' => 'https://example.org/course',
                'coursebook_page_or_section' => '<img src=x>', 'validation_notes' => 'PRIVATE_NOTE'],
            ['title' => 'Printed reading', 'coursebook_page_or_section' => 'Chapter 2'],
            ['title' => ['invalid'], 'url' => ['invalid']],
        ];
        $record->lessonscores = json_encode($results);
        $html = conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), new attempt($record, $this->instance));
        foreach (['<img', '<script>', '<em>', '<b>', 'javascript:', 'data:text/html', 'PRIVATE_NOTE'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('&lt;em&gt;Terrain', $html);
        $this->assertStringContainsString('href="https://example.org/course"', $html);
        $this->assertStringNotContainsString('href="//example.org"', $html);
        $this->assertStringContainsString('Printed reading', $html);
        $this->assertStringContainsString('Chapter 2', $html);
    }
}
