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

namespace mod_masteryagent\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_masteryagent\conversation;
use mod_masteryagent\output\conversation_view;
use mod_masteryagent\sequence;

/**
 * Update and render the current learner's conversation using Moodle's AJAX API.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_conversation extends external_api {
    /**
     * Describe the request. User and attempt ids are deliberately not accepted.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'action' => new external_value(PARAM_ALPHA, 'start, reply, clarify, pause or finish'),
            'state' => new external_value(PARAM_ALPHANUM, 'Revision from the displayed form'),
            'reply' => new external_value(PARAM_RAW, 'Learner reply; ignored for clarify', VALUE_DEFAULT, ''),
            'confirmed' => new external_value(PARAM_BOOL, 'Final submission confirmed', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Process an authenticated AJAX action.
     *
     * @param int $cmid Course module id.
     * @param string $action Action to perform.
     * @param string $state Revision the learner saw.
     * @param string $reply Learner reply.
     * @param bool $confirmed Whether the learner confirmed final submission.
     * @return array Learner-only HTML and status.
     */
    public static function execute(int $cmid, string $action, string $state, string $reply = '',
            bool $confirmed = false): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/masteryagent/lib.php');
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'action' => $action, 'state' => $state, 'reply' => $reply,
            'confirmed' => $confirmed,
        ]);
        $cm = get_coursemodule_from_id('masteryagent', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/masteryagent:view', $context);
        require_capability('mod/masteryagent:attempt', $context);

        $instance = $DB->get_record('masteryagent', ['id' => $cm->instance], '*', MUST_EXIST);
        $result = conversation::process($instance, $context, $params['action'], $params['state'],
            $params['reply'], $params['confirmed']);
        return [
            'html' => conversation_view::render($instance, $cm, sequence::from_instance($instance), $result['attempt']),
            'stale' => $result['stale'],
            'warning' => $result['stale'] ? get_string('conversationchanged', 'mod_masteryagent') : '',
        ];
    }

    /**
     * Describe the response, excluding private rubric and evidence data.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Escaped learner conversation HTML'),
            'stale' => new external_value(PARAM_BOOL, 'The request was stale and did not write data'),
            'warning' => new external_value(PARAM_TEXT, 'A message for stale submissions'),
        ]);
    }
}
