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
 * Read-only access to the signed-in learner's attempts and saved feedback.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_masteryagent\attempt;
use mod_masteryagent\output\conversation_view;
use mod_masteryagent\output\history_view;

$id = required_param('id', PARAM_INT);
$attemptid = optional_param('attempt', 0, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));

$cm = get_coursemodule_from_id('masteryagent', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('masteryagent', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/masteryagent:view', $context);

// History needs view permission, not permission to start another attempt.
// Never accept a user id from the request, even for instructors or managers.
$userid = (int) $USER->id;
$total = attempt::count_for_user($instance, $userid);
$page = min($page, max(0, (int) ceil($total / history_view::PER_PAGE) - 1));
$historyurl = new moodle_url('/mod/masteryagent/history.php', ['id' => $cm->id, 'page' => $page]);
$pageurl = new moodle_url($historyurl);
if ($attemptid !== 0) {
    $pageurl->param('attempt', $attemptid);
}

$PAGE->set_url($pageurl);
$PAGE->set_title(get_string('historytitle', 'mod_masteryagent') . ' - ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->set_activity_record($instance);
$PAGE->set_cacheable(false);
$PAGE->navbar->add(get_string('historytitle', 'mod_masteryagent'), $historyurl);

// Check ownership before printing anything from the selected attempt.
$review = null;
if ($attemptid !== 0) {
    try {
        $review = attempt::get_for_user($instance, $userid, $attemptid);
    } catch (dml_missing_record_exception $e) {
        throw new moodle_exception('historynotavailable', 'mod_masteryagent', $historyurl->out(false));
    }
    $PAGE->navbar->add(get_string('historyreviewtitle', 'mod_masteryagent'));
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));
echo html_writer::start_div('masteryagent-attempt-history');
echo $OUTPUT->heading(get_string($review === null ? 'historytitle' : 'historyreviewtitle', 'mod_masteryagent'), 2);
$links = html_writer::link(new moodle_url('/mod/masteryagent/view.php', ['id' => $cm->id]),
    get_string('historybackactivity', 'mod_masteryagent'), ['class' => 'btn btn-secondary']);
if ($review !== null) {
    $links .= html_writer::link($historyurl, get_string('historybacklist', 'mod_masteryagent'),
        ['class' => 'btn btn-secondary']);
}
echo html_writer::tag('nav', $links, [
    'class' => 'masteryagent-attempt-actions mb-3',
    'aria-label' => get_string('historynavigation', 'mod_masteryagent'),
]);
echo html_writer::tag('p', get_string('historyreturnhelp', 'mod_masteryagent'), ['class' => 'text-muted']);
if ($review !== null) {
    echo html_writer::tag('p', get_string('historyreadonly', 'mod_masteryagent'));
    echo history_view::metadata($review->get_record());
    echo html_writer::div(conversation_view::learning_plan_link($cm, $review), 'mb-3');
    echo conversation_view::render_review($review);
} else {
    $records = attempt::page_for_user($instance, $userid, $page, history_view::PER_PAGE);
    echo history_view::render_list($cm, $records, $total, $page);
    echo $OUTPUT->paging_bar($total, $page, history_view::PER_PAGE, $historyurl);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
