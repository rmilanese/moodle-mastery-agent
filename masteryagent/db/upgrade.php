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
 * Database upgrade steps for mod_masteryagent.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Apply upgrade steps.
 *
 * @param int $oldversion The currently installed version.
 * @return bool
 */
function xmldb_masteryagent_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091601) {
        // Sequence mode: one activity can run an ordered set of lessons.
        $table = new xmldb_table('masteryagent');

        $field = new xmldb_field('sequencejson', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lessonjson');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lessonkeys', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '', 'sequencejson');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('masteryagent_attempt');

        $field = new xmldb_field('lessonindex', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'turnsused');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('lessonscores', XMLDB_TYPE_TEXT, null, null, null, null, null, 'lessonindex');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('masteryagent_message');

        $field = new xmldb_field('lessonkey', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '', 'turnno');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Existing activities become single-lesson sequences.
        $instances = $DB->get_recordset_select('masteryagent', 'sequencejson IS NULL AND lessonjson IS NOT NULL');
        foreach ($instances as $instance) {
            $decoded = json_decode((string) $instance->lessonjson, true);
            if (!is_array($decoded)) {
                continue;
            }
            $DB->set_field('masteryagent', 'sequencejson', json_encode([$decoded]), ['id' => $instance->id]);
            $DB->set_field('masteryagent', 'lessonkeys', (string) $instance->questionid, ['id' => $instance->id]);
        }
        $instances->close();

        upgrade_mod_savepoint(true, 2026091601, 'masteryagent');
    }

    if ($oldversion < 2026091705) {
        $table = new xmldb_table('masteryagent_attempt');
        $field = new xmldb_field('draftreply', XMLDB_TYPE_TEXT, null, null, null, null, null, 'status');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026091705, 'masteryagent');
    }

    return true;
}
