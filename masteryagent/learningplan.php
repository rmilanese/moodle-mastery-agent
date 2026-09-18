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

/**
 * Printable, copyable learning feedback for an owned, completed attempt.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_masteryagent\attempt;
use mod_masteryagent\output\conversation_view;

$id = required_param('id', PARAM_INT);
$attemptid = required_param('attempt', PARAM_INT);
$cm = get_coursemodule_from_id('masteryagent', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('masteryagent', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/masteryagent:view', $context);
$returnurl = new moodle_url('/mod/masteryagent/history.php', ['id' => $cm->id, 'attempt' => $attemptid]);

// The caller cannot select a different learner, even with instructor capabilities.
try {
    $review = attempt::get_for_user($instance, (int) $USER->id, $attemptid);
} catch (dml_missing_record_exception $e) {
    throw new moodle_exception('historynotavailable', 'mod_masteryagent');
}
if (!$review->is_finished()) {
    throw new moodle_exception('learningplannotavailable', 'mod_masteryagent', $returnurl->out(false));
}

$PAGE->set_url(new moodle_url('/mod/masteryagent/learningplan.php', ['id' => $cm->id, 'attempt' => $attemptid]));
$PAGE->set_context($context);
$PAGE->set_activity_record($instance);
$PAGE->set_title(get_string('learningplantitle', 'mod_masteryagent') . ' - ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('embedded');
$PAGE->set_cacheable(false);
$PAGE->add_body_class('masteryagent-print-page');
$PAGE->requires->js_call_amd('mod_masteryagent/learning_plan', 'init', ['#masteryagent-export']);

$document = conversation_view::render_learning_plan($review, html_to_text(format_string($instance->name), 0, false));
// Derive both formats from the same allowlisted, escaped public feedback.
$text = html_to_text($document, 0);

echo $OUTPUT->header();
echo html_writer::start_div('masteryagent-export', [
    'id' => 'masteryagent-export',
    'data-copied' => get_string('learningplancopied', 'mod_masteryagent'),
    'data-copyfailed' => get_string('learningplancopyfailed', 'mod_masteryagent'),
    'data-printfailed' => get_string('learningplanprintfailed', 'mod_masteryagent'),
]);
echo html_writer::start_div('masteryagent-export-controls');
echo html_writer::div(
    html_writer::link($returnurl, get_string('learningplanback', 'mod_masteryagent'), ['class' => 'btn btn-secondary'])
    . html_writer::tag('button', get_string('learningplanprint', 'mod_masteryagent'), [
        'type' => 'button', 'class' => 'btn btn-primary', 'data-action' => 'print-plan', 'hidden' => 'hidden',
    ])
    . html_writer::tag('button', get_string('learningplancopy', 'mod_masteryagent'), [
        'type' => 'button', 'class' => 'btn btn-secondary', 'data-action' => 'copy-plan', 'hidden' => 'hidden',
    ]), 'masteryagent-attempt-actions');
echo html_writer::tag('p', get_string('learningplanhelp', 'mod_masteryagent'));
echo html_writer::tag('p', '', [
    'data-region' => 'copy-status', 'role' => 'status', 'aria-live' => 'polite', 'aria-atomic' => 'true',
]);
echo html_writer::tag('details',
    html_writer::tag('summary', get_string('learningplanmanual', 'mod_masteryagent'))
    . html_writer::tag('label', get_string('learningplantext', 'mod_masteryagent'), ['for' => 'masteryagent-plan-text'])
    . html_writer::tag('p', get_string('learningplanmanualhelp', 'mod_masteryagent'), ['id' => 'masteryagent-plan-text-help'])
    . html_writer::tag('textarea', s($text), [
        'id' => 'masteryagent-plan-text', 'data-region' => 'plan-text', 'readonly' => 'readonly',
        'rows' => 12, 'class' => 'form-control', 'aria-describedby' => 'masteryagent-plan-text-help',
    ]), ['data-region' => 'copy-fallback']);
echo html_writer::end_div();
echo $document;
echo html_writer::end_div();
echo $OUTPUT->footer();
