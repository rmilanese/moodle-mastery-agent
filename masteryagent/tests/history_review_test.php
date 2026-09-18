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
 * Saved attempt reviews remain private, read-only and independent of replacement course content.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_review_test extends \advanced_testcase {
    /** @var \stdClass Current activity, which may differ from the reviewed assessment. */
    private \stdClass $instance;

    /** @var \stdClass Enrolled learner. */
    private \stdClass $student;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('masteryagent', ['course' => $course->id]);
        $this->student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->setUser($this->student);
    }

    /** Store an attempt without using current questions or calling an AI provider. */
    private function saved_attempt(string $status = attempt::STATUS_FINISHED, ?float $score = 2,
            array $results = [], ?string $summary = 'Saved overall feedback.'): attempt {
        global $DB;
        $review = attempt::start($this->instance, (int) $this->student->id, new sequence([]));
        $record = $review->get_record();
        $record->status = $status;
        $record->score = $score;
        $record->summary = $summary;
        $record->lessonscores = json_encode($results);
        $record->draftreply = 'PRIVATE UNSENT DRAFT';
        $record->evidencejson = json_encode(['covered' => ['PRIVATE EVIDENCE LEDGER']]);
        $record->timefinished = $status === attempt::STATUS_FINISHED ? time() : 0;
        $DB->update_record('masteryagent_attempt', $record);
        return new attempt($DB->get_record('masteryagent_attempt', ['id' => $record->id], '*', MUST_EXIST),
            $this->instance);
    }

    /** Public feedback snapshot with private fields that must never appear in the review. */
    private function saved_result(): array {
        return [
            'key' => 'original-key', 'lesson_id' => 'OLD01', 'title' => 'Original lesson title',
            'score' => 2, 'max' => 4, 'summary' => 'Saved lesson feedback.',
            'strengths' => ['Used a concrete example.'], 'gaps' => ['Explain the mechanism.'],
            'next_step' => 'Connect the example to the mechanism.',
            'dimensions' => [['id' => 'PRIVATE DIMENSION CODE', 'verdict' => 'partial', 'comment' => 'Explain why.']],
            'dimension_names' => ['PRIVATE DIMENSION CODE' => 'Mechanism'],
            'learning_resources' => [['title' => 'Original reading', 'url' => 'https://example.org/original']],
            'rubric' => 'PRIVATE RUBRIC', 'evidence' => 'PRIVATE RESULT EVIDENCE',
        ];
    }

    /** Parse the renderer's actual HTML for semantic assertions. */
    private function fragment(attempt $review): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>'
            . conversation_view::render_review($review) . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    public function test_review_shows_saved_feedback_before_transcript_without_controls_private_data_or_writes(): void {
        global $DB;
        $review = $this->saved_attempt(attempt::STATUS_FINISHED, 2, [$this->saved_result()]);
        $review->add_message('agent', 'The original question.', 'original-key');
        $review->add_message('student', 'The submitted answer.', 'original-key');
        $tables = ['masteryagent', 'masteryagent_attempt', 'masteryagent_message', 'grade_items', 'grade_grades'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = serialize($DB->get_records($table, [], 'id ASC'));
        }
        $xpath = $this->fragment($review);
        $text = $xpath->document->textContent;
        foreach (['Saved overall feedback.', 'Used a concrete example.', 'Explain the mechanism.',
                'Connect the example to the mechanism.', 'Original reading', 'The submitted answer.'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        foreach (['PRIVATE UNSENT DRAFT', 'PRIVATE EVIDENCE LEDGER', 'PRIVATE DIMENSION CODE',
                'PRIVATE RUBRIC', 'PRIVATE RESULT EVIDENCE'] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $this->assertSame(0, $xpath->query('//form | //button | //input | //textarea | //*[@data-action]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-region="reply-context" or @data-region="current-lesson"]')->length);
        $this->assertSame(1, $xpath->query('//section[@data-region="history-review-results"]'
            . '/following-sibling::section[@data-region="history-review-transcript"]')->length);
        $this->assertSame(2, $xpath->query('//section[@data-region="history-review-results"'
            . ' or @data-region="history-review-transcript"]/h3')->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class,"masteryagent-learning-plan")]/h4')->length);
        foreach ($tables as $table) {
            $this->assertSame($before[$table], serialize($DB->get_records($table, [], 'id ASC')),
                'Review changed records in ' . $table);
        }
    }

    public function test_review_uses_saved_titles_and_scores_after_current_content_and_settings_change(): void {
        $review = $this->saved_attempt(attempt::STATUS_FINISHED, 2.5, [$this->saved_result()]);
        $review->add_message('agent', 'Original question', 'original-key');
        $before = conversation_view::render_review($review);
        $changed = clone $this->instance;
        $changed->maxgrade = 999;
        $changed->threshold = 998;
        $changed->provisional = 1;
        $changed->sequencejson = '{invalid replacement content';
        $changed->lessonjson = '';
        $this->assertSame($before, conversation_view::render_review(new attempt($review->get_record(), $changed)));
        // A read-only review does not even need the current question set or grading settings to exist.
        $this->assertSame($before,
            conversation_view::render_review(new attempt($review->get_record(), (object) ['id' => $changed->id])));
        $xpath = $this->fragment($review);
        $this->assertSame('OLD01 Original lesson title', $xpath->query('//details/summary')->item(0)->textContent);
        $this->assertSame(get_string('historyreviewscore', 'mod_masteryagent', format_float(2.5, 2)),
            $xpath->query('//*[@data-region="history-review-score"]')->item(0)->textContent);
        $this->assertStringContainsString('2/4', $xpath->query('//h4')->item(0)->textContent);
        $this->assertStringNotContainsString('AI-provisional', $xpath->document->textContent);
    }

    public function test_legacy_zero_score_is_recorded_but_missing_score_is_not_reconstructed(): void {
        foreach ([0.0, null] as $score) {
            $review = $this->saved_attempt(attempt::STATUS_FINISHED, $score, [], 'Legacy overall summary.');
            $review->add_message('student', 'Legacy submitted answer.', '');
            $xpath = $this->fragment($review);
            $this->assertStringContainsString('Legacy overall summary.', $xpath->document->textContent);
            $this->assertSame('Conversation section 1', $xpath->query('//details/summary')->item(0)->textContent);
            if ($score === null) {
                $this->assertSame(0, $xpath->query('//*[@data-region="history-review-score"]')->length);
                $this->assertStringContainsString(get_string('historyreviewnoscore', 'mod_masteryagent'),
                    $xpath->document->textContent);
            } else {
                $this->assertSame(get_string('historyreviewscore', 'mod_masteryagent', format_float(0, 2)),
                    $xpath->query('//*[@data-region="history-review-score"]')->item(0)->textContent);
            }
            $this->assertSame(0, $xpath->query('//h4')->length);
            $this->assertStringContainsString(get_string('historyreviewlessonfeedbackmissing', 'mod_masteryagent'),
                $xpath->document->textContent);
        }
    }

    public function test_unfinished_review_shows_closed_lesson_feedback_without_final_score_or_draft(): void {
        $review = $this->saved_attempt(attempt::STATUS_INPROGRESS, 99, [$this->saved_result()], 'NOT FINAL SUMMARY');
        $review->add_message('agent', 'Earlier lesson question.', 'original-key');
        $review->add_message('student', 'Submitted work only.', 'original-key');
        $review->add_message('agent', 'Unfinished lesson question.', 'unsaved-title-key');
        $xpath = $this->fragment($review);
        $this->assertSame(0, $xpath->query('//*[@data-region="history-review-score"] | //form | //textarea')->length);
        $this->assertStringContainsString(get_string('historyreviewunfinished', 'mod_masteryagent'),
            $xpath->document->textContent);
        $this->assertStringContainsString('Saved lesson feedback.', $xpath->document->textContent);
        $this->assertStringContainsString('Submitted work only.', $xpath->document->textContent);
        $this->assertStringNotContainsString('PRIVATE UNSENT DRAFT', $xpath->document->textContent);
        $this->assertStringNotContainsString('NOT FINAL SUMMARY', $xpath->document->textContent);
        $this->assertSame(2, $xpath->query('//details[@data-region="lesson-history" and not(@open)]')->length);
        $this->assertSame('Conversation section 2', $xpath->query('//details/summary')->item(1)->textContent);
    }

    public function test_public_text_is_escaped_and_unsafe_reading_links_are_not_rendered(): void {
        $result = $this->saved_result();
        $result['title'] = '<img src=x onerror=unsafe()> Saved title';
        $result['summary'] = '<script>unsafe()</script>';
        $result['strengths'] = ['<svg onload=unsafe()>'];
        $result['next_step'] = '<iframe src=unsafe></iframe>';
        $result['learning_resources'] = [['title' => '<b>Reading</b>', 'url' => 'javascript:unsafe()']];
        $review = $this->saved_attempt(attempt::STATUS_FINISHED, 2, [$result], '<script>summary()</script>');
        $review->add_message('agent', '<img src=unsafe> Prompt', 'original-key');
        $review->add_message('student', '<script>reply()</script>', 'original-key');
        $xpath = $this->fragment($review);
        $this->assertSame(0, $xpath->query('//img | //script | //svg | //iframe | //*[@onerror or @onload]')->length);
        $this->assertSame(0, $xpath->query('//a[starts-with(@href,"javascript:")]')->length);
        $this->assertStringContainsString('<img src=x', $xpath->query('//summary')->item(0)->textContent);
        $this->assertStringContainsString('<script>reply()</script>', $xpath->document->textContent);
        $this->assertStringContainsString('<b>Reading</b>', $xpath->document->textContent);
    }

    public function test_native_transcript_links_target_visible_summaries_and_keep_all_messages_in_order(): void {
        $review = $this->saved_attempt(attempt::STATUS_FINISHED, 2, [$this->saved_result()]);
        foreach (['original-key', '', 'removed-key', 'original-key'] as $index => $key) {
            $review->add_message($index % 2 ? 'student' : 'agent', 'Message ' . $index, $key);
        }
        $xpath = $this->fragment($review);
        $links = $xpath->query('//nav//a');
        $this->assertSame(4, $links->length);
        foreach ($links as $link) {
            $target = substr($link->getAttribute('href'), 1);
            $this->assertSame(1, $xpath->query('//details/summary[@id="' . $target . '"]')->length);
            $this->assertFalse($link->hasAttribute('data-conversation-jump'));
        }
        $actual = [];
        foreach ($xpath->query('//*[@data-message-id]') as $message) {
            $actual[] = (int) $message->getAttribute('data-message-id');
        }
        $this->assertSame(array_values(array_map(static fn($message) => (int) $message->id, $review->messages())), $actual);
        $this->assertSame(4, $xpath->query('//details[@data-region="lesson-history" and not(@open)]')->length);
        $this->assertStringContainsString('Conversation section 2', $xpath->document->textContent);
        $this->assertStringContainsString('Conversation section 3', $xpath->document->textContent);
    }

    public function test_empty_review_explains_missing_saved_content_without_broken_shortcuts(): void {
        $review = $this->saved_attempt(attempt::STATUS_FINISHED, null, [], null);
        $xpath = $this->fragment($review);
        foreach (['historyreviewnoscore', 'historyreviewsummarymissing', 'historyreviewlessonfeedbackmissing',
                'historyreviewnomessages'] as $key) {
            $this->assertStringContainsString(get_string($key, 'mod_masteryagent'), $xpath->document->textContent);
        }
        $this->assertSame(0, $xpath->query('//nav | //a | //details | //form')->length);
    }
}
