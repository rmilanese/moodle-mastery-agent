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
 * Instructor report: attempts, transcripts and the rubric behind them.
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
$attemptid = optional_param('attempt', 0, PARAM_INT);

$cm = get_coursemodule_from_id('masteryagent', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$instance = $DB->get_record('masteryagent', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/masteryagent:viewreports', $context);

$PAGE->set_url('/mod/masteryagent/report.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($instance->name));

if ($attemptid) {
    $record = $DB->get_record(
        'masteryagent_attempt',
        ['id' => $attemptid, 'masteryagentid' => $instance->id],
        '*',
        MUST_EXIST
    );
    $user = core_user::get_user($record->userid, '*', MUST_EXIST);
    $single = new attempt($record, $instance);

    echo $OUTPUT->heading(fullname($user), 3);
    echo html_writer::tag('p', get_string('scoreline', 'mod_masteryagent', (object) [
        'score' => $record->score === null ? '-' : format_float((float) $record->score, 0),
        'max' => masteryagent_total_grade($instance),
    ]));

    $results = $single->lesson_results();
    if (count($results) > 1) {
        $rows = '';
        foreach ($results as $result) {
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('td', s(trim(($result['lesson_id'] ?? '') . ' ' . ($result['title'] ?? ''))))
                . html_writer::tag('td', s((string) ($result['score'] ?? '')) . ' / '
                    . (int) ($result['max'] ?? $instance->maxgrade))
                . html_writer::tag('td', (int) ($result['turns'] ?? 0))
                . html_writer::tag('td', s((string) ($result['summary'] ?? '')))
            );
        }
        echo html_writer::tag(
            'table',
            html_writer::tag(
                'thead',
                html_writer::tag(
                    'tr',
                    html_writer::tag('th', get_string('lesson', 'mod_masteryagent'))
                    . html_writer::tag('th', get_string('score', 'mod_masteryagent'))
                    . html_writer::tag('th', get_string('turnsused', 'mod_masteryagent'))
                    . html_writer::tag('th', get_string('finalfeedback', 'mod_masteryagent'))
                )
            ) . html_writer::tag('tbody', $rows),
            ['class' => 'table table-sm generaltable']
        );
    }

    foreach ($single->messages() as $message) {
        echo \mod_masteryagent\output\conversation_view::render_message($message);
    }

    if (!empty($record->summary)) {
        echo $OUTPUT->box(
            html_writer::tag('h5', get_string('finalfeedback', 'mod_masteryagent'))
            . html_writer::tag('p', nl2br(s((string) $record->summary))),
            'generalbox'
        );
    }

    $ledger = $single->ledger();
    echo $OUTPUT->box(
        html_writer::tag('h5', get_string('evidenceledger', 'mod_masteryagent'))
        . html_writer::tag('pre', s(json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))),
        'generalbox'
    );

    // The rubric, for the person validating the agent's judgement.
    $sequence = sequence::from_instance($instance);
    if ($sequence->count() > 0) {
        $rubric = html_writer::tag('h5', get_string('rubricheading', 'mod_masteryagent'));
        foreach ($sequence->all() as $lesson) {
            $rubric .= html_writer::tag('h6', s(trim($lesson->lesson_id() . ' ' . $lesson->title())));
            $rubric .= html_writer::tag(
                'p',
                html_writer::tag('strong', get_string('lessonquestion', 'mod_masteryagent'))
                . ' ' . s($lesson->question_text())
            );
            foreach (
                [
                    'strong_evidence' => 'strongevidence',
                    'partial_evidence' => 'partialevidence',
                    'misconceptions_or_red_flags' => 'misconceptions',
                    'insufficient_evidence_conditions' => 'insufficientevidence',
                ] as $key => $stringkey
            ) {
                $items = $lesson->evidence($key);
                if (empty($items)) {
                    continue;
                }
                $list = '';
                foreach ($items as $item) {
                    $list .= html_writer::tag('li', s((string) $item));
                }
                $rubric .= html_writer::tag(
                    'p',
                    html_writer::tag('strong', get_string($stringkey, 'mod_masteryagent'))
                );
                $rubric .= html_writer::tag('ul', $list);
            }

            $notes = $lesson->validation_notes();
            if (!empty($notes)) {
                $list = '';
                foreach ($notes as $note) {
                    $list .= html_writer::tag('li', s((string) $note));
                }
                $rubric .= html_writer::tag(
                    'p',
                    html_writer::tag('strong', get_string('validationnotes', 'mod_masteryagent'))
                );
                $rubric .= html_writer::tag('ul', $list);
            }

            $sources = $lesson->sources();
            if (!empty($sources)) {
                $list = '';
                foreach ($sources as $source) {
                    if (!is_array($source)) {
                        continue;
                    }
                    $label = trim(
                        (string) ($source['source_id'] ?? '') . ' ' . (string) ($source['title'] ?? '')
                    );
                    $detail = trim(
                        (string) ($source['edition_or_date'] ?? '') . ' '
                        . (string) ($source['coursebook_page_or_section'] ?? '')
                    );
                    $entry = html_writer::tag('strong', s($label)) . ' ' . s($detail);
                    if (!empty($source['url'])) {
                        $entry .= ' ' . html_writer::link(
                            new moodle_url((string) $source['url']),
                            get_string('sourcelink', 'mod_masteryagent'),
                            ['target' => '_blank', 'rel' => 'noreferrer noopener']
                        );
                    }
                    if (!empty($source['verification_status'])) {
                        $entry .= ' [' . s((string) $source['verification_status']) . ']';
                    }
                    $list .= html_writer::tag('li', $entry);
                }
                $rubric .= html_writer::tag(
                    'p',
                    html_writer::tag('strong', get_string('sourceevidence', 'mod_masteryagent'))
                );
                $rubric .= html_writer::tag('ul', $list);
            }
        }
        echo $OUTPUT->box($rubric, 'generalbox');
    }

    echo html_writer::link(
        new moodle_url('/mod/masteryagent/report.php', ['id' => $cm->id]),
        get_string('backtoreport', 'mod_masteryagent'),
        ['class' => 'btn btn-secondary']
    );
    echo $OUTPUT->footer();
    exit;
}

$attempts = attempt::all_for_instance($instance);

if (empty($attempts)) {
    echo $OUTPUT->notification(get_string('noattempts', 'mod_masteryagent'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$listsequence = sequence::from_instance($instance);
$totalgrade = masteryagent_total_grade($instance);

$table = new html_table();
$table->head = [
    get_string('user'),
    get_string('status', 'mod_masteryagent'),
    get_string('lessonsdone', 'mod_masteryagent'),
    get_string('score', 'mod_masteryagent'),
    get_string('finished', 'mod_masteryagent'),
    '',
];
$table->attributes['class'] = 'table table-sm generaltable';

foreach ($attempts as $record) {
    $user = core_user::get_user($record->userid);
    $done = json_decode((string) $record->lessonscores, true);
    $table->data[] = [
        $user ? fullname($user) : (string) $record->userid,
        $record->status === attempt::STATUS_FINISHED
            ? get_string('statusfinished', 'mod_masteryagent')
            : get_string('statusinprogress', 'mod_masteryagent'),
        (is_array($done) ? count($done) : 0) . ' / ' . $listsequence->count(),
        $record->score === null ? '-' : format_float((float) $record->score, 0) . ' / ' . $totalgrade,
        $record->timefinished ? userdate($record->timefinished) : '-',
        html_writer::link(
            new moodle_url('/mod/masteryagent/report.php', ['id' => $cm->id, 'attempt' => $record->id]),
            get_string('viewtranscript', 'mod_masteryagent')
        ),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
