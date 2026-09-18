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
 * Nearby request feedback and the normal POST recovery presentation.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(conversation_view::class)]
final class request_feedback_test extends \advanced_testcase {
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

    /** Parse actual renderer output without JavaScript. */
    private function fragment(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING);
        return new \DOMXPath($dom);
    }

    /** Render a normal initial page fragment with optional posted text. */
    private function render(?attempt $current, string $feedback = '', ?string $draft = null): \DOMXPath {
        return $this->fragment(conversation_view::render($this->instance, $this->cm,
            sequence::from_instance($this->instance), $current, $draft, false, $feedback));
    }

    public function test_idle_feedback_is_hidden_with_single_error_status_and_help_regions(): void {
        $xpath = $this->fragment(conversation_view::request_feedback());
        $this->assertSame(1, $xpath->query('//*[@data-region="request-feedback" and @hidden and @data-state="idle"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="error" and @hidden and @role="alert" and @tabindex="-1"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="status" and @aria-hidden="true"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="request-help" and @hidden]')->length);
        $this->assertSame('', $xpath->document->textContent);
        $this->assertSame(0, $xpath->query('//form | //*[@aria-live]')->length);
    }

    public function test_initial_error_and_recovery_help_are_escaped_visible_and_accessibly_associated(): void {
        $error = 'Provider failed <img src=x onerror=alert(1)> & could not continue.';
        $help = 'Keep your answer <script>unsafe()</script> before trying again.';
        $xpath = $this->fragment(conversation_view::request_feedback($error, $help));
        $this->assertSame(1, $xpath->query('//*[@data-region="request-feedback" and not(@hidden) and @data-state="error"]')->length);
        $alert = $xpath->query('//*[@data-region="error" and not(@hidden)]')->item(0);
        $this->assertNotNull($alert);
        $this->assertSame($error, $alert->textContent);
        $helpnodes = $xpath->query('//*[@id="' . $alert->getAttribute('aria-describedby') . '" and not(@hidden)]');
        $this->assertSame(1, $helpnodes->length);
        $this->assertSame($help, $helpnodes->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//img | //script')->length);
    }

    public function test_failed_reply_feedback_is_beside_send_and_save_with_the_exact_unsent_draft_preserved(): void {
        $current = attempt::start($this->instance, (int) $this->student->id, sequence::from_instance($this->instance));
        $draft = "My unfinished answer.\n<script>unsafe()</script> & supporting reasoning.";
        $feedback = conversation_view::request_feedback('The evaluator could not respond.',
            get_string('requestreplyrecovery', 'mod_masteryagent'));
        $xpath = $this->render($current, $feedback, $draft);
        $this->assertSame(1, $xpath->query('//*[@data-region="request-feedback-slot"]')->length);
        $this->assertSame(1, $xpath->query('//form[@data-action="reply"]/*[@data-region="request-feedback-slot"]'
            . '/preceding-sibling::*[1][contains(concat(" ", @class, " "), " masteryagent-attempt-actions ")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="request-feedback-slot"]'
            . '/*[@data-region="request-feedback" and not(@hidden)]')->length);
        $this->assertSame($draft, $xpath->query('//textarea[@id="masteryagent-reply"]')->item(0)->textContent);
        $this->assertStringContainsString(get_string('requestreplyrecovery', 'mod_masteryagent'),
            $xpath->query('//*[@data-region="request-help"]')->item(0)->textContent);
        $this->assertSame(1, $xpath->query('//form')->length);
        $this->assertSame(1, $xpath->query('//button[@value="reply"]')->length);
        $this->assertSame(1, $xpath->query('//button[@value="pause"]')->length);
        $this->assertSame(0, $xpath->query('//script')->length);
        $this->assertSame('', $current->draft_reply());
        $this->assertSame(0, $current->turns_used());
    }

    public function test_start_and_finished_views_have_one_feedback_slot_without_extra_forms_or_ajax_feedback_copies(): void {
        $feedback = conversation_view::request_feedback('The request could not be completed.',
            get_string('requestactionrecovery', 'mod_masteryagent'));
        $initial = $this->render(null, $feedback);
        $this->assertSame(1, $initial->query('//*[@data-region="request-feedback-slot"]')->length);
        $this->assertSame(1, $initial->query('//form[@data-action="start"]/following-sibling::*'
            . '[@data-region="request-feedback-slot"]')->length);
        $this->assertSame(1, $initial->query('//form')->length);
        $current = attempt::start($this->instance, (int) $this->student->id, sequence::from_instance($this->instance));
        $record = clone $current->get_record();
        $record->status = attempt::STATUS_FINISHED;
        $record->score = 3;
        $record->summary = 'Saved assessment feedback.';
        $finished = new attempt($record, $this->instance);
        foreach ([1, 0] as $allowretry) {
            $this->instance->allowretry = $allowretry;
            $xpath = $this->render($finished, $feedback);
            $this->assertSame(1, $xpath->query('//*[@data-region="request-feedback-slot"]')->length);
            $this->assertSame(1, $xpath->query('//*[@data-region="results"]/following-sibling::*'
                . '[@data-region="request-feedback-slot"]')->length);
            $this->assertSame($allowretry, $xpath->query('//form')->length);
            $this->assertSame(0, $xpath->query('//form//*[@data-region="request-feedback-slot"]')->length);
        }
        foreach ([null, $current, $finished] as $attempt) {
            $xpath = $this->render($attempt);
            $this->assertSame(1, $xpath->query('//*[@data-region="request-feedback-slot" and not(node())]')->length);
            $this->assertSame(0, $xpath->query('//*[@data-region="request-feedback"]')->length);
        }
    }

    public function test_historical_reviews_do_not_include_request_slots_or_submission_controls(): void {
        $current = attempt::start($this->instance, (int) $this->student->id, sequence::from_instance($this->instance));
        $xpath = $this->fragment(conversation_view::render_review($current));
        $this->assertSame(0, $xpath->query('//*[@data-region="request-feedback-slot"'
            . ' or @data-region="request-feedback" or @data-region="error" or @data-region="status"]')->length);
        $this->assertSame(0, $xpath->query('//form | //button | //textarea')->length);
        $this->assertSame(1, $xpath->query('//*[@data-region="history-review-transcript"]')->length);
    }
}
