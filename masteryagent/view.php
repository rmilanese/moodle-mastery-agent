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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The learner-facing assessment conversation.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/masteryagent/lib.php');

use mod_masteryagent\attempt;
use mod_masteryagent\sequence;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('masteryagent', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('masteryagent', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/masteryagent:view', $context);

$PAGE->set_url('/mod/masteryagent/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($instance);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$canattempt = has_capability('mod/masteryagent:attempt', $context);
$canreport = has_capability('mod/masteryagent:viewreports', $context);
$error = null;

$sequence = sequence::from_instance($instance);

if ($sequence->count() === 0) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($instance->name));
    echo $OUTPUT->notification(get_string('nolessonloaded', 'mod_masteryagent'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$current = attempt::get_latest($instance, (int) $USER->id);

// AJAX intercepts these forms when JavaScript is available. Keep a safe POST fallback.
$draft = '';
$draftoverride = null;
if ($action !== '' && data_submitted()) {
    require_sesskey();
    $draft = optional_param('reply', '', PARAM_RAW);
    $draftoverride = $draft;
    try {
        $result = \mod_masteryagent\conversation::process(
            $instance, $context, $action, required_param('state', PARAM_ALPHANUM), $draft,
            optional_param('confirmed', false, PARAM_BOOL)
        );
        $current = $result['attempt'];
        if ($result['stale']) {
            $error = get_string('conversationchanged', 'mod_masteryagent');
        } else if ($action === 'pause') {
            redirect(new moodle_url('/course/view.php', ['id' => $course->id]),
                get_string('pausesaved', 'mod_masteryagent'));
        } else {
            redirect(new moodle_url('/mod/masteryagent/view.php', ['id' => $cm->id]));
        }
    } catch (moodle_exception $e) {
        $current = attempt::get_latest($instance, (int) $USER->id);
        $error = $e->getMessage();
    }
}

if ($canattempt) {
    $PAGE->requires->js_call_amd('mod_masteryagent/conversation', 'init', ['#masteryagent-app']);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if (!empty($instance->intro)) {
    echo $OUTPUT->box(format_module_intro('masteryagent', $instance, $cm->id), 'generalbox', 'intro');
}

if ($error !== null && !$canattempt) {
    echo $OUTPUT->notification($error, 'error');
}

if ($canreport) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/mod/masteryagent/report.php', ['id' => $cm->id]),
            get_string('viewreport', 'mod_masteryagent'),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
}

if (!$canattempt) {
    $list = '';
    foreach ($sequence->all() as $lesson) {
        $list .= html_writer::tag(
            'li',
            html_writer::tag('strong', s(trim($lesson->lesson_id() . ' ' . $lesson->title())))
            . html_writer::tag('div', s($lesson->question_text()))
        );
    }
    echo $OUTPUT->box(
        html_writer::tag('h4', get_string('lessonsinthisactivity', 'mod_masteryagent', $sequence->count()))
        . html_writer::tag('ul', $list),
        'generalbox'
    );
    echo $OUTPUT->footer();
    exit;
}

// Status and error regions stay mounted while only the conversation fragment changes.
echo html_writer::start_div('masteryagent-app', [
    'id' => 'masteryagent-app',
    'data-cmid' => $cm->id,
    'data-processing' => get_string('processing', 'mod_masteryagent'),
    'data-updated' => get_string('conversationupdated', 'mod_masteryagent'),
    'data-error' => get_string('ajaxerror', 'mod_masteryagent'),
    'data-unsent' => get_string('finishunsent', 'mod_masteryagent'),
    'data-confirmrequired' => get_string('finishconfirmationrequired', 'mod_masteryagent'),
    'data-resultsready' => get_string('assessmentresultsready', 'mod_masteryagent'),
]);
echo html_writer::div($error === null ? '' : s($error), 'alert alert-danger', [
    'data-region' => 'error', 'role' => 'alert', 'tabindex' => '-1',
] + ($error === null ? ['hidden' => 'hidden'] : []));
echo html_writer::div('', 'masteryagent-status text-muted', [
    'data-region' => 'status', 'aria-hidden' => 'true',
]);
echo html_writer::div('', 'masteryagent-sr-only', [
    'data-region' => 'announcements', 'role' => 'status', 'aria-live' => 'polite', 'aria-atomic' => 'true',
]);
$showdraft = $draft !== '' && ($current === null || $current->is_finished());
echo html_writer::div(
    html_writer::tag('label', get_string('recovereddraft', 'mod_masteryagent'), ['for' => 'masteryagent-draft'])
    . html_writer::tag('textarea', $showdraft ? s($draft) : '', [
        'id' => 'masteryagent-draft', 'readonly' => 'readonly', 'rows' => 5, 'class' => 'form-control',
    ]),
    'mb-3', ['data-region' => 'draft'] + ($showdraft ? [] : ['hidden' => 'hidden'])
);
$html = \mod_masteryagent\output\conversation_view::render($instance, $cm, $sequence, $current, $draftoverride);
echo html_writer::div($html, '', ['data-region' => 'content', 'aria-busy' => 'false']);
echo html_writer::end_div();
echo $OUTPUT->footer();
