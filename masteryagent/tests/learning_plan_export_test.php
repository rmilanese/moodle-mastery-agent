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
 * Printable learning plans reuse public saved feedback and leave attempts and grades unchanged.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(conversation_view::class)]
final class learning_plan_export_test extends \advanced_testcase {
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

    /** Build saved records independently of the current question set, without calling AI. */
    private function saved_attempt(bool $finished = true): attempt {
        global $DB;
        $review = attempt::start($this->instance, (int) $this->student->id, new sequence([]));
        $record = $review->get_record();
        $record->status = $finished ? attempt::STATUS_FINISHED : attempt::STATUS_INPROGRESS;
        $record->score = $finished ? 2.5 : null;
        $record->summary = 'Saved overall guidance. <script>unsafe()</script>';
        $record->draftreply = 'SECRET_UNSENT_DRAFT';
        $record->evidencejson = json_encode(['covered' => ['SECRET_LEDGER_CODE']]);
        $record->timefinished = $finished ? time() : 0;
        $record->lessonscores = json_encode([[
            'key' => 'old-question', 'lesson_id' => 'OLD01', 'title' => 'Original lesson',
            'score' => 2.5, 'max' => 4, 'summary' => 'Saved lesson feedback.',
            'strengths' => ['You explained the relationship.'], 'gaps' => ['Show how the mechanism works.'],
            'next_step' => 'Compare two examples and explain the difference.',
            'dimensions' => [
                ['id' => 'SECRET_DIMENSION_CODE', 'verdict' => 'partial', 'comment' => 'Explain why.'],
                ['id' => 'SECRET_LEGACY_CODE', 'verdict' => 'unknown', 'comment' => ''],
            ],
            'dimension_names' => ['SECRET_DIMENSION_CODE' => 'Explaining mechanisms'],
            'learning_resources' => [
                ['title' => 'Original reading', 'url' => 'https://example.org/reading?chapter=2&section=3',
                    'coursebook_page_or_section' => 'Chapter 2, pages 10-12'],
                ['title' => 'Reference without a safe link', 'url' => 'javascript:unsafe()'],
            ],
            'rubric' => 'SECRET_RUBRIC', 'evidence' => 'SECRET_RESULT_EVIDENCE',
        ]]);
        $DB->update_record('masteryagent_attempt', $record);
        foreach (['agent', 'student', 'clarification'] as $role) {
            $review->add_message($role, 'SECRET_TRANSCRIPT_' . $role, 'old-question');
        }
        return new attempt($DB->get_record('masteryagent_attempt', ['id' => $record->id], '*', MUST_EXIST),
            $this->instance);
    }

    /** Parse the actual rendered markup for semantic and privacy assertions. */
    private function fragment(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    public function test_completed_learning_plan_link_is_attempt_scoped_and_precedes_detailed_feedback(): void {
        $review = $this->saved_attempt();
        $xpath = $this->fragment(conversation_view::learning_plan_link($this->cm, $review));
        $link = $xpath->query('//a')->item(0);
        $this->assertNotNull($link);
        $url = new \moodle_url('/mod/masteryagent/learningplan.php', [
            'id' => $this->cm->id, 'attempt' => $review->get_id(),
        ]);
        $this->assertSame($url->out(false), $link->getAttribute('href'));
        $this->assertSame('btn btn-secondary', $link->getAttribute('class'));
        $this->assertSame(get_string('learningplanopen', 'mod_masteryagent'), $link->textContent);
        // Finished results can still have recovered unsent text in the original tab.
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertSame('noopener noreferrer', $link->getAttribute('rel'));
        $this->assertFalse($link->hasAttribute('data-action'));
        $xpath = $this->fragment(conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $review));
        $this->assertSame(1, $xpath->query('//*[@data-region="results"]/following-sibling::*[1]'
            . '[contains(concat(" ", @class, " "), " masteryagent-learning-plan-entry ")]/a')->length);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ", @class, " "), " masteryagent-learning-plan-entry ")]'
            . '/following-sibling::*[1]/*[@class="masteryagent-learning-plan"]')->length);
    }

    public function test_export_and_plain_text_contain_saved_public_guidance_and_safe_urls_without_writes(): void {
        global $DB;
        $review = $this->saved_attempt();
        $tables = ['masteryagent', 'masteryagent_attempt', 'masteryagent_message', 'grade_items', 'grade_grades'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = serialize($DB->get_records($table, [], 'id ASC'));
        }
        $activityname = 'Course activity <img src=x onerror=unsafe()> & course materials';
        $html = conversation_view::render_learning_plan($review, $activityname);
        $xpath = $this->fragment($html);
        $this->assertSame(1, $xpath->query('//article[@class="masteryagent-export-document"]/h2')->length);
        $this->assertSame($activityname, $xpath->query('//article/p')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//article/dl[@class="masteryagent-attempt-meta"]')->length);
        $this->assertSame(0, $xpath->query('//script | //img | //form | //button | //textarea | //details'
            . ' | //*[@data-message-id] | //*[@data-region="history-review-transcript"]')->length);
        $this->assertStringContainsString(conversation_view::render_feedback($review), $html);
        $text = html_to_text($html, 0);
        foreach (['Saved overall guidance.', 'Original lesson', 'Saved lesson feedback.',
                'You explained the relationship.', 'Show how the mechanism works.',
                'Compare two examples and explain the difference.', 'Explaining mechanisms',
                'Assessed skill 2', 'Original reading', 'Chapter 2, pages 10-12',
                'https://example.org/reading?chapter=2&section=3', 'Reference without a safe link'] as $public) {
            // Moodle's converter may capitalize headings in the plain-text version.
            $this->assertStringContainsString(\core_text::strtolower($public), \core_text::strtolower($text));
        }
        $this->assertStringContainsString('Not recorded', $text);
        foreach ([$html, $text] as $output) {
            $this->assertStringNotContainsString('SECRET_', $output);
            $this->assertStringNotContainsString('javascript:', $output);
        }
        foreach ($tables as $table) {
            $this->assertSame($before[$table], serialize($DB->get_records($table, [], 'id ASC')),
                'Learning plan export changed records in ' . $table);
        }
    }

    public function test_export_does_not_reinterpret_saved_results_using_changed_activity_settings(): void {
        $review = $this->saved_attempt();
        $html = conversation_view::render_learning_plan($review, 'Activity label');
        $changed = clone $this->instance;
        $changed->sequencejson = '{invalid replacement content';
        $changed->lessonjson = '';
        $changed->maxgrade = 999;
        $changed->threshold = 998;
        $changed->provisional = 1;
        $changedreview = new attempt($review->get_record(), $changed);
        $this->assertSame($html, conversation_view::render_learning_plan($changedreview, 'Activity label'));
        $this->assertSame($html, conversation_view::render_learning_plan(
            new attempt($review->get_record(), (object) ['id' => $changed->id]), 'Activity label'));
        $this->assertStringContainsString('2.5/4', $html);
        $this->assertStringNotContainsString('AI-provisional', $html);
    }

    public function test_legacy_finished_plan_has_honest_fallbacks_and_unfinished_attempts_cannot_be_exported(): void {
        $review = $this->saved_attempt();
        $record = clone $review->get_record();
        $record->score = null;
        $record->summary = null;
        $record->lessonscores = '[]';
        $legacy = new attempt($record, $this->instance);
        $html = conversation_view::render_learning_plan($legacy, 'Legacy activity');
        foreach (['historyreviewnoscore', 'historyreviewsummarymissing', 'historyreviewlessonfeedbackmissing'] as $key) {
            $this->assertStringContainsString(get_string($key, 'mod_masteryagent'), $html);
        }
        $this->assertStringNotContainsString('SECRET_TRANSCRIPT_', $html);
        $unfinished = $this->saved_attempt(false);
        $this->assertSame('', conversation_view::learning_plan_link($this->cm, $unfinished));
        $activehtml = conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $unfinished);
        $this->assertStringNotContainsString('/mod/masteryagent/learningplan.php', $activehtml);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('learningplannotavailable', 'mod_masteryagent'));
        conversation_view::render_learning_plan($unfinished, 'Activity label');
    }
}
