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
use mod_masteryagent\output\history_view;

/**
 * Learner-facing score clarity, explicit skipped lessons and native feedback navigation.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(conversation_view::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(history_view::class)]
final class student_experience_view_test extends \advanced_testcase {
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

    /** Make an owner-scoped saved attempt without calling an AI provider. */
    private function saved_attempt(array $results, ?float $score = 2.6, bool $finished = true): attempt {
        global $DB;
        $current = attempt::start($this->instance, (int) $this->student->id, new sequence([]));
        $record = $current->get_record();
        $record->status = $finished ? attempt::STATUS_FINISHED : attempt::STATUS_INPROGRESS;
        $record->score = $score;
        $record->summary = 'Saved overall feedback.';
        $record->timefinished = $finished ? time() : 0;
        $record->lessonscores = json_encode($results);
        $DB->update_record('masteryagent_attempt', $record);
        return new attempt($record, $this->instance);
    }

    /** Saved public assessed feedback; internal identifiers must never become learner labels. */
    private function assessed_result(): array {
        return [
            'key' => 'PRIVATE_QUESTION_KEY', 'lesson_id' => 'S01', 'title' => 'Saved lesson title',
            'status' => 'assessed', 'score' => 2.6, 'max' => 4, 'summary' => 'Saved lesson feedback.',
            'strengths' => ['A clear explanation.'], 'gaps' => ['Explain the tradeoff.'],
            'next_step' => 'Compare two examples.', 'dimensions' => [],
        ];
    }

    /** Parse rendered learner markup. */
    private function fragment(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    /** Render the activity fragment using the supplied attempt and current configuration. */
    private function render(attempt $current, ?string $draftoverride = null): string {
        return conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $current, $draftoverride);
    }

    public function test_decimal_score_matches_history_and_does_not_look_like_a_pass_at_three_points(): void {
        $lessons = json_decode($this->instance->sequencejson, true);
        $this->instance->sequencejson = json_encode([$lessons[0]]);
        $current = $this->saved_attempt([$this->assessed_result()]);
        $html = $this->render($current);
        $xpath = $this->fragment($html);
        $this->assertSame(get_string('attemptscoreline', 'mod_masteryagent', (object) [
            'score' => format_float(2.6, 2), 'max' => 4,
        ]), $xpath->query('//*[@data-region="results"]/h3')->item(0)->textContent);
        $this->assertStringContainsString(format_float(2.6, 2) . '/4', $xpath->query('//h4')->item(0)->textContent);
        $this->assertStringContainsString(get_string('verdictnotmet', 'mod_masteryagent', 3), $html);
        $review = $this->fragment(conversation_view::render_review($current));
        $this->assertSame(get_string('historyreviewscore', 'mod_masteryagent', format_float(2.6, 2)),
            $review->query('//*[@data-region="history-review-score"]')->item(0)->textContent);
    }

    public function test_last_reply_notice_is_beside_send_and_describes_lesson_or_final_submission(): void {
        $current = $this->saved_attempt([], null, false);
        $record = $current->get_record();
        $record->turnsused = (int) $this->instance->maxturns - 1;
        foreach ([0 => 'lastreplylesson', 1 => 'lastreplyassessment'] as $index => $key) {
            $record->lessonindex = $index;
            $xpath = $this->fragment($this->render(new attempt($record, $this->instance)));
            $notice = $xpath->query('//*[@id="masteryagent-last-reply"]')->item(0);
            $this->assertNotNull($notice);
            $this->assertSame(get_string($key, 'mod_masteryagent'), $notice->textContent);
            $send = $xpath->query('//*[@id="masteryagent-last-reply"]/following-sibling::*[1]'
                . '/button[@name="action" and @value="reply"]')->item(0);
            $this->assertNotNull($send);
            $this->assertSame('masteryagent-last-reply', $send->getAttribute('aria-describedby'));
            $this->assertFalse($notice->hasAttribute('hidden'));
        }
        $record->turnsused--;
        $xpath = $this->fragment($this->render(new attempt($record, $this->instance)));
        $this->assertSame(0, $xpath->query('//*[@id="masteryagent-last-reply"]')->length);
        $this->assertSame(0, $xpath->query('//button[@value="reply" and @aria-describedby]')->length);
    }

    public function test_draft_baseline_keeps_saved_text_separate_from_recovered_or_cleared_text(): void {
        $current = $this->saved_attempt([], null, false);
        $saved = "Saved \"answer\" & <text>\nSecond line.";
        $current->save_draft($saved);
        foreach ([null, '', "Edited answer.\nUnsent line."] as $override) {
            $xpath = $this->fragment($this->render($current, $override));
            $editor = $xpath->query('//textarea[@name="reply"]')->item(0);
            $this->assertSame($saved, $editor->getAttribute('data-saved-draft'));
            $this->assertSame($override ?? $saved, $editor->textContent);
            $this->assertSame(0, $xpath->query('//text')->length);
        }
    }

    public function test_unanswered_lessons_show_saved_readings_without_invented_assessment_feedback(): void {
        $skipped = [
            'key' => 'PRIVATE_SKIPPED_KEY', 'lesson_id' => 'S02', 'title' => 'Unanswered saved lesson',
            'status' => 'notassessed', 'score' => 0, 'max' => 4,
            'learning_resources' => [['title' => 'Saved reading', 'url' => 'https://example.org/reading']],
            // Even inconsistent old data must not masquerade as an evaluation of an unanswered lesson.
            'summary' => 'FALSE_SUMMARY', 'strengths' => ['FALSE_STRENGTH'],
            'gaps' => ['FALSE_GAP'], 'next_step' => 'FALSE_NEXT_STEP',
            'dimensions' => [['id' => 'PRIVATE_DIMENSION', 'verdict' => 'met', 'comment' => 'FALSE_JUDGEMENT']],
        ];
        $current = $this->saved_attempt([$this->assessed_result(), $skipped]);
        foreach ([$this->render($current), conversation_view::render_review($current),
                conversation_view::render_learning_plan($current, 'Activity')] as $html) {
            $xpath = $this->fragment($html);
            $coverage = $xpath->query('//*[@data-region="assessment-coverage"]')->item(0);
            $this->assertSame(get_string('assessmentcoverage', 'mod_masteryagent', (object) [
                'assessed' => 1, 'notassessed' => 1, 'total' => 2,
            ]), $coverage->textContent);
            $block = $xpath->query('//*[@data-assessment-status="notassessed"]')->item(0);
            $this->assertNotNull($block);
            $this->assertStringContainsString(get_string('lessonnotassessed', 'mod_masteryagent'), $block->textContent);
            $this->assertStringContainsString(format_float(0, 2) . '/4', $block->textContent);
            $this->assertStringContainsString('Saved reading', $block->textContent);
            $this->assertStringContainsString(get_string('learningunassessedreadingshelp', 'mod_masteryagent'),
                $block->textContent);
            $this->assertSame(1, $xpath->query('//*[@data-assessment-status="notassessed"]'
                . '//a[@href="https://example.org/reading"]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-assessment-status="notassessed"]'
                . '//*[contains(@class,"masteryagent-feedback-grid") or contains(@class,"masteryagent-next-step")'
                . ' or contains(@class,"masteryagent-skill-feedback")]')->length);
            $this->assertStringContainsString('A clear explanation.', $html);
            $this->assertStringNotContainsString('FALSE_', $html);
            $this->assertStringNotContainsString('PRIVATE_', $html);
        }
        // The total remains the saved eight points if an instructor changes later activity settings.
        $this->instance->maxgrade = 99;
        $this->assertStringContainsString(get_string('attemptscoreline', 'mod_masteryagent', (object) [
            'score' => format_float(2.6, 2), 'max' => 8,
        ]), $this->render($current));
    }

    public function test_legacy_results_remain_assessed_without_inventing_missing_lesson_records(): void {
        $result = $this->assessed_result();
        unset($result['status']);
        $current = $this->saved_attempt([$result]);
        $html = conversation_view::render_review($current);
        $xpath = $this->fragment($html);
        $this->assertSame(get_string('assessmentcoveragerecorded', 'mod_masteryagent', (object) [
            'assessed' => 1, 'notassessed' => 0, 'total' => 1,
        ]), $xpath->query('//*[@data-region="assessment-coverage"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//h4')->length);
        $this->assertSame(0, $xpath->query('//*[@data-assessment-status="notassessed"]')->length);
        $this->assertStringContainsString('A clear explanation.', $html);
        $changed = clone $this->instance;
        $changed->sequencejson = '{invalid replacement';
        $changed->maxgrade = 999;
        $this->assertSame($html, conversation_view::render_review(new attempt($current->get_record(), $changed)));
    }

    public function test_feedback_index_has_unique_native_targets_and_escaped_saved_labels_in_every_view(): void {
        $result = $this->assessed_result();
        $result['title'] = '<img src=x onerror=unsafe()> Same title';
        $unnamed = $result;
        $unnamed['title'] = '';
        $unnamed['lesson_id'] = '';
        $current = $this->saved_attempt([$result, $result, $unnamed]);
        $expected = [];
        foreach ([$this->render($current), conversation_view::render_review($current),
                conversation_view::render_learning_plan($current, 'Activity')] as $html) {
            $xpath = $this->fragment($html);
            $links = $xpath->query('//nav[@class="masteryagent-feedback-navigation"]//a');
            $this->assertSame(3, $links->length);
            $targets = [];
            foreach ($links as $link) {
                $target = substr($link->getAttribute('href'), 1);
                $targets[] = $target;
                $this->assertFalse($link->hasAttribute('data-conversation-jump'));
                $this->assertSame(1, $xpath->query('//h4[@id="' . $target . '" and @tabindex="-1"]')->length);
            }
            $this->assertCount(3, array_unique($targets));
            if ($expected) {
                $this->assertSame($expected, $targets);
            }
            $expected = $targets;
            $this->assertSame(get_string('feedbacklessonfallback', 'mod_masteryagent', 3), $links->item(2)->textContent);
            $this->assertSame(0, $xpath->query('//img | //script')->length);
            $this->assertStringNotContainsString('PRIVATE_', $html);
        }
        $plainhtml = conversation_view::render_learning_plan($current, 'Activity', false);
        $this->assertSame(0, $this->fragment($plainhtml)->query('//nav')->length);
        $this->assertSame(3, $this->fragment($plainhtml)->query('//h4')->length);
        $this->assertStringNotContainsString('#masteryagent-feedback-', html_to_text($plainhtml, 0));
    }

    public function test_missing_legacy_points_are_not_displayed_as_a_zero_score(): void {
        $result = $this->assessed_result();
        unset($result['status'], $result['score'], $result['max']);
        $current = $this->saved_attempt([$result], null);
        foreach ([$this->render($current), conversation_view::render_review($current),
                conversation_view::render_learning_plan($current, 'Activity')] as $html) {
            $heading = $this->fragment($html)->query('//h4')->item(0)->textContent;
            $this->assertStringContainsString(get_string('historynotrecorded', 'mod_masteryagent'), $heading);
            $this->assertStringNotContainsString(format_float(0, 2), $heading);
        }
        $xpath = $this->fragment($this->render($current));
        $score = $xpath->query('//*[@data-region="results"]/h3')->item(0)->textContent;
        $this->assertStringContainsString(get_string('historynotrecorded', 'mod_masteryagent'), $score);
        $this->assertStringNotContainsString(format_float(0, 2), $score);
    }

    public function test_lower_retry_shows_its_score_separately_from_highest_completed_score_and_export_stays_saved(): void {
        global $DB;
        $this->saved_attempt([$this->assessed_result()], 4);
        $current = $this->saved_attempt([$this->assessed_result()], 2.6);
        $html = $this->render($current);
        $xpath = $this->fragment($html);
        $this->assertStringContainsString(format_float(2.6, 2),
            $xpath->query('//*[@data-region="results"]/h3')->item(0)->textContent);
        $retained = $xpath->query('//*[@data-region="retained-score"]')->item(0);
        $this->assertStringContainsString(get_string('highestcompletedscore', 'mod_masteryagent', format_float(4, 2)),
            $retained->textContent);
        $this->assertStringContainsString(get_string('highestcompletedscorehelp', 'mod_masteryagent'),
            $retained->textContent);
        $gradeurl = new \moodle_url('/grade/report/user/index.php', ['id' => $this->instance->course]);
        $this->assertSame($gradeurl->out(false),
            $xpath->query('//*[@data-region="retained-score"]/a')->item(0)->getAttribute('href'));

        $export = conversation_view::render_learning_plan($current, 'Activity');
        $this->saved_attempt([$this->assessed_result()], 8);
        $this->assertSame($export, conversation_view::render_learning_plan($current, 'Activity'));
        $this->assertSame(0, $this->fragment($export)->query('//*[@data-region="retained-score"]')->length);

        $context = \context_course::instance((int) $this->instance->course);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $DB->set_field('course', 'showgrades', 0, ['id' => $this->instance->course]);
        $retained = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertSame(0, $this->fragment($retained)->query('//a')->length);
        $DB->set_field('course', 'showgrades', 1, ['id' => $this->instance->course]);
        $retained = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertSame(1, $this->fragment($retained)->query('//a')->length);

        assign_capability('moodle/grade:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        $this->setUser($this->student);
        $retained = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertSame(0, $this->fragment($retained)->query('//a')->length);

        // The report permits viewall holders even when individual grades are hidden.
        assign_capability('moodle/grade:viewall', CAP_ALLOW, $studentrole->id, $context->id, true);
        $DB->set_field('course', 'showgrades', 0, ['id' => $this->instance->course]);
        $this->setUser($this->student);
        $retained = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertSame(1, $this->fragment($retained)->query('//a')->length);

        // Its report-specific capability is required in either branch.
        assign_capability('gradereport/user:view', CAP_PROHIBIT, $studentrole->id, $context->id, true);
        $this->setUser($this->student);
        $retained = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertSame(0, $this->fragment($retained)->query('//a')->length);
    }

    public function test_no_completed_score_is_distinct_from_a_completed_zero_score(): void {
        $this->saved_attempt([], 9, false);
        $html = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertStringContainsString(get_string('highestcompletedscorenone', 'mod_masteryagent'), $html);
        $this->assertStringNotContainsString(get_string('highestcompletedscorehelp', 'mod_masteryagent'), $html);
        $this->saved_attempt([], 0);
        $html = history_view::retained_score($this->instance, $this->cm, (int) $this->student->id);
        $this->assertStringContainsString(get_string('highestcompletedscore', 'mod_masteryagent', format_float(0, 2)), $html);
        $this->assertStringNotContainsString(get_string('highestcompletedscorenone', 'mod_masteryagent'), $html);
    }
}
