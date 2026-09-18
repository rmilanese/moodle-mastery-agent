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

namespace mod_masteryagent\output;

use html_writer;
use moodle_url;
use mod_masteryagent\attempt;

/**
 * Learner-owned attempt history, without assessment actions or private evidence.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history_view {
    /** Number of summaries on one history page. */
    const PER_PAGE = 10;

    /**
     * Open history separately so an unsent answer stays in the activity tab.
     *
     * @param \stdClass $cm Course module.
     * @return string
     */
    public static function activity_link(\stdClass $cm): string {
        return html_writer::div(
            html_writer::link(new moodle_url('/mod/masteryagent/history.php', ['id' => $cm->id]),
                get_string('historyopen', 'mod_masteryagent'), [
                    'class' => 'btn btn-secondary', 'target' => '_blank', 'rel' => 'noopener noreferrer',
                    'aria-describedby' => 'masteryagent-history-help',
                ])
            . html_writer::tag('p', get_string('historyopenhelp', 'mod_masteryagent'), [
                'id' => 'masteryagent-history-help', 'class' => 'text-muted mt-2 mb-0',
            ]),
            'masteryagent-history-entry mb-3'
        );
    }

    /**
     * Distinguish the learner's best completed score from the score of the attempt being viewed.
     *
     * This is a raw attempt score, not a claim about an instructor-adjusted gradebook grade.
     * The caller must establish that the learner may view this activity and their own attempts.
     *
     * @param \stdClass $instance Activity record.
     * @param \stdClass $cm Course module.
     * @param int $userid Learner whose own score is being displayed.
     * @return string
     */
    public static function retained_score(\stdClass $instance, \stdClass $cm, int $userid): string {
        global $DB;
        $best = attempt::best_score((int) $instance->id, $userid);
        $out = html_writer::tag('p', html_writer::tag('strong',
            $best === null ? get_string('highestcompletedscorenone', 'mod_masteryagent')
                : get_string('highestcompletedscore', 'mod_masteryagent', format_float($best, 2))));
        if ($best !== null) {
            $out .= html_writer::tag('p', get_string('highestcompletedscorehelp', 'mod_masteryagent'));
        }
        $courseid = (int) ($cm->course ?? $instance->course);
        $coursecontext = \context_course::instance($courseid);
        // Match the user grade report's access rules, including the course's grade visibility setting.
        if (has_capability('gradereport/user:view', $coursecontext, $userid)
                && (has_capability('moodle/grade:viewall', $coursecontext, $userid)
                    || (has_capability('moodle/grade:view', $coursecontext, $userid)
                        && $DB->get_field('course', 'showgrades', ['id' => $courseid], MUST_EXIST)))) {
            $out .= html_writer::link(new moodle_url('/grade/report/user/index.php', ['id' => $courseid]),
                get_string('viewmygradebook', 'mod_masteryagent'));
        }
        return html_writer::div($out, 'masteryagent-retained-score', ['data-region' => 'retained-score']);
    }

    /**
     * Render one page of already owner-scoped summary records.
     *
     * @param \stdClass $cm Course module.
     * @param array $records Summary records, newest first.
     * @param int $total Total attempts belonging to this learner in the activity.
     * @param int $page Zero-based page, already clamped to the available range.
     * @return string
     */
    public static function render_list(\stdClass $cm, array $records, int $total, int $page): string {
        if (empty($records)) {
            return html_writer::tag('p', get_string('historyempty', 'mod_masteryagent'));
        }

        $items = '';
        $position = 0;
        foreach ($records as $record) {
            $number = $total - $page * self::PER_PAGE - $position;
            $heading = html_writer::tag('h3', get_string('historyattemptnumber', 'mod_masteryagent', $number));
            if ($page === 0 && $position === 0) {
                $heading .= html_writer::tag('p', get_string('historylatest', 'mod_masteryagent'), ['class' => 'text-muted']);
            }
            $items .= html_writer::tag('li', $heading . self::metadata($record)
                . html_writer::link(new moodle_url('/mod/masteryagent/history.php', [
                    'id' => $cm->id, 'attempt' => $record->id, 'page' => $page,
                ]), get_string('historyreviewattempt', 'mod_masteryagent', $number), ['class' => 'btn btn-secondary']),
                ['class' => 'masteryagent-attempt-card']);
            $position++;
        }

        return html_writer::tag('p', get_string('historyintro', 'mod_masteryagent'))
            . html_writer::tag('ul', $items, ['class' => 'masteryagent-attempt-list']);
    }

    /**
     * Show saved dates, status and points; never infer old grading settings.
     *
     * @param \stdClass $record Attempt or summary record.
     * @return string
     */
    public static function metadata(\stdClass $record): string {
        $finished = $record->status === attempt::STATUS_FINISHED;
        $fields = [
            'status' => get_string($finished ? 'statusfinished' : 'statusinprogress', 'mod_masteryagent'),
            'historystarted' => self::date((int) $record->timestarted),
            'historysubmitted' => $finished ? self::date((int) $record->timefinished)
                : get_string('historynotsubmitted', 'mod_masteryagent'),
            'historyrecordedscore' => $finished && $record->score !== null
                ? get_string('historypoints', 'mod_masteryagent', format_float((float) $record->score, 2))
                : get_string($finished ? 'historynotrecorded' : 'historynotsubmitted', 'mod_masteryagent'),
        ];
        $out = '';
        foreach ($fields as $label => $value) {
            $out .= html_writer::div(html_writer::tag('dt', get_string($label, 'mod_masteryagent'))
                . html_writer::tag('dd', $value));
        }
        return html_writer::tag('dl', $out, ['class' => 'masteryagent-attempt-meta']);
    }

    /**
     * Localized date with a machine-readable timestamp, or an honest fallback.
     *
     * @param int $timestamp Saved time.
     * @return string
     */
    private static function date(int $timestamp): string {
        return $timestamp > 0
            ? html_writer::tag('time', s(userdate($timestamp)), ['datetime' => gmdate('c', $timestamp)])
            : get_string('historynotrecorded', 'mod_masteryagent');
    }
}
