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
 * Module API for mod_masteryagent.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Declare which Moodle features this module supports.
 *
 * @param string $feature Feature constant.
 * @return mixed
 */
function masteryagent_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            // Backup and restore are not implemented in this release.
            return false;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        default:
            return null;
    }
}

/**
 * Read the uploaded question-set file and fold the chosen lesson into the record.
 *
 * @param stdClass $data Form data, modified in place.
 * @return void
 * @throws moodle_exception When the file cannot be used.
 */
function masteryagent_apply_source_file(stdClass $data): void {
    global $USER;

    if (empty($data->lessonfile)) {
        return;
    }

    $fs = get_file_storage();
    $usercontext = context_user::instance($USER->id);
    $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $data->lessonfile, 'id', false);
    if (empty($files)) {
        return;
    }

    $file = reset($files);
    $contents = $file->get_content();

    $questions = \mod_masteryagent\lesson::parse_question_set($contents);
    if (empty($questions)) {
        throw new moodle_exception('errorbadjson', 'mod_masteryagent');
    }

    $wanted = trim((string) ($data->lessonkeys ?? ''));
    $selected = \mod_masteryagent\sequence::select($questions, $wanted);
    if (empty($selected)) {
        throw new moodle_exception('errorbadjson', 'mod_masteryagent');
    }

    $first = reset($selected);
    $sequence = new \mod_masteryagent\sequence($selected);

    $data->sequencejson = json_encode(array_values($selected));
    $data->lessonkeys = $wanted;
    $data->lessonjson = json_encode($first);
    $data->lessonid = (string) ($first['lesson_id'] ?? '');
    $data->questionid = (string) ($first['question_id'] ?? '');
    $data->lessontitle = $sequence->is_multi()
        ? $sequence->describe()
        : (string) ($first['lesson_title'] ?? '');
    $data->sourcemeta = json_encode(\mod_masteryagent\lesson::parse_source_meta($contents));
}

/**
 * Create a new activity instance.
 *
 * @param stdClass $data Form data.
 * @param mod_masteryagent_mod_form|null $mform The form.
 * @return int New instance id.
 */
function masteryagent_add_instance(stdClass $data, $mform = null): int {
    global $DB;

    masteryagent_apply_source_file($data);

    $data->timecreated = time();
    $data->timemodified = time();
    $data->id = $DB->insert_record('masteryagent', $data);

    masteryagent_grade_item_update($data);

    return $data->id;
}

/**
 * Update an existing activity instance.
 *
 * @param stdClass $data Form data.
 * @param mod_masteryagent_mod_form|null $mform The form.
 * @return bool
 */
function masteryagent_update_instance(stdClass $data, $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    // Keep the existing lessons unless a replacement file was supplied.
    $existing = $DB->get_record('masteryagent', ['id' => $data->id], '*', MUST_EXIST);
    $data->lessonjson = $existing->lessonjson;
    $data->sequencejson = $existing->sequencejson;
    $data->lessonid = $existing->lessonid;
    $data->questionid = $existing->questionid;
    $data->lessontitle = $existing->lessontitle;
    $data->sourcemeta = $existing->sourcemeta;
    if (empty($data->lessonfile)) {
        $data->lessonkeys = $existing->lessonkeys;
    }
    masteryagent_apply_source_file($data);

    $DB->update_record('masteryagent', $data);

    masteryagent_grade_item_update($data);
    masteryagent_update_grades($data);

    return true;
}

/**
 * Delete an activity instance and everything belonging to it.
 *
 * @param int $id Instance id.
 * @return bool
 */
function masteryagent_delete_instance($id): bool {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $instance = $DB->get_record('masteryagent', ['id' => $id]);
    if (!$instance) {
        return false;
    }

    // Remove the gradebook item first, while the instance is still resolvable.
    grade_update('mod/masteryagent', $instance->course, 'mod', 'masteryagent', $id, 0, null, ['deleted' => 1]);

    \mod_masteryagent\attempt::delete_all_for_instance((int) $id);
    $DB->delete_records('masteryagent', ['id' => $id]);

    return true;
}

/**
 * The gradebook maximum: the per-lesson maximum times the number of lessons.
 *
 * @param stdClass $instance Activity instance record.
 * @return int
 */
function masteryagent_total_grade(stdClass $instance): int {
    $sequence = \mod_masteryagent\sequence::from_instance($instance);
    $lessons = max(1, $sequence->count());
    return (int) $instance->maxgrade * $lessons;
}

/**
 * Create or update the gradebook item for an activity.
 *
 * @param stdClass $instance Activity instance record.
 * @param mixed $grades Grade records, 'reset', or null.
 * @return int GRADE_UPDATE_OK and friends.
 */
function masteryagent_grade_item_update(stdClass $instance, $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        'itemname' => clean_param($instance->name, PARAM_NOTAGS),
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => masteryagent_total_grade($instance),
        'grademin' => 0,
    ];

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update(
        'mod/masteryagent',
        $instance->course,
        'mod',
        'masteryagent',
        $instance->id,
        0,
        $grades,
        $item
    );
}

/**
 * Push scores into the gradebook.
 *
 * Scores are written with an AI-provisional marker when the activity is
 * configured that way, so a reviewer can see that the grade came from the
 * agent and has not yet been validated by a subject matter expert.
 *
 * @param stdClass $instance Activity instance record.
 * @param int $userid Single user, or 0 for all.
 * @return void
 */
function masteryagent_update_grades(stdClass $instance, int $userid = 0): void {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['masteryagentid' => $instance->id, 'status' => \mod_masteryagent\attempt::STATUS_FINISHED];
    $where = 'masteryagentid = :masteryagentid AND status = :status';
    if ($userid) {
        $where .= ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    $records = $DB->get_records_select('masteryagent_attempt', $where, $params, 'timefinished ASC');
    if (empty($records)) {
        if ($userid) {
            masteryagent_grade_item_update($instance, (object) [
                'userid' => $userid,
                'rawgrade' => null,
            ]);
        }
        return;
    }

    $best = [];
    foreach ($records as $record) {
        $uid = (int) $record->userid;
        if ($record->score === null) {
            continue;
        }
        if (!isset($best[$uid]) || (float) $record->score > (float) $best[$uid]->score) {
            $best[$uid] = $record;
        }
    }

    $grades = [];
    foreach ($best as $uid => $record) {
        $feedback = '';
        if (!empty($instance->provisional)) {
            $feedback .= '<p><strong>' . get_string('provisionalbanner', 'mod_masteryagent') . '</strong></p>';
        }
        $feedback .= '<p>' . nl2br(s((string) $record->summary)) . '</p>';

        $results = json_decode((string) $record->lessonscores, true);
        if (is_array($results) && $results) {
            $items = '';
            foreach ($results as $result) {
                if (!is_array($result)) {
                    continue;
                }
                $items .= '<li>' . s(trim(
                    ($result['lesson_id'] ?? '') . ' ' . ($result['title'] ?? '')
                )) . ' &mdash; ' . (isset($result['score']) ? format_float((float) $result['score'], 2)
                    . '/' . (int) ($result['max'] ?? $instance->maxgrade)
                    : get_string('historynotrecorded', 'mod_masteryagent'))
                    . (($result['status'] ?? '') === 'notassessed'
                        ? ' — ' . get_string('lessonnotassessed', 'mod_masteryagent') : '') . '</li>';
            }
            $feedback .= '<p><strong>' . get_string('perlessonscores', 'mod_masteryagent') . '</strong></p>'
                . '<ul>' . $items . '</ul>';
        }

        $grades[$uid] = [
            'userid' => $uid,
            'rawgrade' => (float) $record->score,
            'feedback' => $feedback,
            'feedbackformat' => FORMAT_HTML,
            'dategraded' => (int) $record->timefinished,
        ];
    }

    if (!empty($grades)) {
        masteryagent_grade_item_update($instance, $grades);
    }
}

/**
 * Reset gradebook entries when a course is reset.
 *
 * @param int $courseid Course id.
 * @param string $type Optional module type filter.
 * @return void
 */
function masteryagent_reset_gradebook($courseid, $type = ''): void {
    global $DB;

    $instances = $DB->get_records('masteryagent', ['course' => $courseid]);
    foreach ($instances as $instance) {
        masteryagent_grade_item_update($instance, 'reset');
    }
}
