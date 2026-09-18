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

use mod_masteryagent\output\history_view;

/**
 * Learner history links, dated summaries and read-only metadata presentation.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(history_view::class)]
final class history_view_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /** Parse a renderer fragment for semantic assertions. */
    private function fragment(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    /** Build a historical record including text that must never enter a summary. */
    private function record(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'id' => 900,
            'status' => attempt::STATUS_FINISHED,
            'score' => 5,
            'timestarted' => 1700000000,
            'timefinished' => 1700001234,
            'draftreply' => 'Private unsent draft.',
            'summary' => 'Private final feedback <img src=x onerror=alert(1)>.',
            'evidencejson' => '{"covered":["private-evidence-code"]}',
            'lessonscores' => '[{"summary":"Private lesson feedback."}]',
        ]);
    }

    public function test_activity_link_opens_personal_history_separately_with_an_associated_draft_reminder(): void {
        $xpath = $this->fragment(history_view::activity_link((object) ['id' => 42]));
        $links = $xpath->query('//a');
        $this->assertSame(1, $links->length);
        $link = $links->item(0);
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertContains('noopener', explode(' ', $link->getAttribute('rel')));
        $this->assertContains('noreferrer', explode(' ', $link->getAttribute('rel')));
        $this->assertStringContainsString('opens in a new tab', $link->textContent);
        $this->assertNotSame(get_string('viewreport', 'mod_masteryagent'), $link->textContent);
        $url = parse_url($link->getAttribute('href'));
        $this->assertStringEndsWith('/mod/masteryagent/history.php', $url['path']);
        parse_str($url['query'], $query);
        $this->assertSame(['id' => '42'], $query);
        $help = $xpath->query('//*[@id="' . $link->getAttribute('aria-describedby') . '"]');
        $this->assertSame(1, $help->length);
        $this->assertFalse($help->item(0)->hasAttribute('hidden'));
        $this->assertStringContainsString('Keep this activity tab open', $help->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//form')->length);
    }

    public function test_older_page_has_correct_attempt_numbers_and_owner_neutral_urls_without_private_payloads(): void {
        $records = [$this->record(), $this->record(['id' => 700])];
        $xpath = $this->fragment(history_view::render_list((object) ['id' => 42], $records, 12, 1));
        $headings = $xpath->query('//li/h3');
        $this->assertSame(2, $headings->length);
        $this->assertSame('Attempt 2', $headings->item(0)->textContent);
        $this->assertSame('Attempt 1', $headings->item(1)->textContent);
        $this->assertStringNotContainsString(get_string('historylatest', 'mod_masteryagent'),
            $xpath->document->textContent);
        foreach ($xpath->query('//li/a') as $index => $link) {
            $url = parse_url($link->getAttribute('href'));
            $this->assertStringEndsWith('/mod/masteryagent/history.php', $url['path']);
            parse_str($url['query'], $query);
            $this->assertSame(['id' => '42', 'attempt' => (string) $records[$index]->id, 'page' => '1'], $query);
            $this->assertSame('Review attempt ' . (2 - $index), $link->textContent);
        }
        foreach (['Private unsent draft.', 'Private final feedback', 'private-evidence-code',
                'Private lesson feedback.'] as $private) {
            $this->assertStringNotContainsString($private, $xpath->document->textContent);
        }
        $this->assertSame(0, $xpath->query('//form | //textarea | //img | //script')->length);
    }

    public function test_latest_attempt_and_dates_use_saved_timestamps_with_machine_readable_time_elements(): void {
        $record = $this->record();
        $xpath = $this->fragment(history_view::render_list((object) ['id' => 42], [$record], 1, 0));
        $this->assertStringContainsString(get_string('historylatest', 'mod_masteryagent'),
            $xpath->document->textContent);
        $times = $xpath->query('//time');
        $this->assertSame(2, $times->length);
        foreach ([$record->timestarted, $record->timefinished] as $index => $timestamp) {
            $time = $times->item($index);
            $this->assertSame($timestamp, (new \DateTimeImmutable($time->getAttribute('datetime')))->getTimestamp());
            $this->assertSame(userdate($timestamp), $time->textContent);
        }
        $unknown = $this->fragment(history_view::metadata($this->record(['timestarted' => 0, 'timefinished' => 0])));
        $this->assertSame(0, $unknown->query('//time')->length);
        $this->assertSame(2, substr_count($unknown->document->textContent,
            get_string('historynotrecorded', 'mod_masteryagent')));
    }

    public function test_zero_missing_and_unfinished_scores_are_distinguished_without_inventing_a_maximum(): void {
        $cases = [
            [attempt::STATUS_FINISHED, 0, get_string('historypoints', 'mod_masteryagent', format_float(0, 2))],
            [attempt::STATUS_FINISHED, null, get_string('historynotrecorded', 'mod_masteryagent')],
            [attempt::STATUS_INPROGRESS, 5, get_string('historynotsubmitted', 'mod_masteryagent')],
        ];
        foreach ($cases as [$status, $score, $expected]) {
            $xpath = $this->fragment(history_view::metadata($this->record(['status' => $status, 'score' => $score])));
            $value = $xpath->query('//dt[text()="Recorded score"]/following-sibling::dd')->item(0);
            $this->assertNotNull($value);
            $this->assertSame($expected, $value->textContent);
            $this->assertStringNotContainsString('/', $value->textContent);
            if ($status === attempt::STATUS_INPROGRESS) {
                $this->assertSame(1, $xpath->query('//time')->length);
                $this->assertStringContainsString(get_string('statusinprogress', 'mod_masteryagent'),
                    $xpath->document->textContent);
            }
        }
    }

    public function test_empty_history_explains_when_attempts_appear_without_review_links_or_actions(): void {
        $xpath = $this->fragment(history_view::render_list((object) ['id' => 42], [], 0, 0));
        $this->assertStringContainsString(get_string('historyempty', 'mod_masteryagent'),
            $xpath->document->textContent);
        $this->assertSame(0, $xpath->query('//a | //form | //button | //time | //li')->length);
    }
}
