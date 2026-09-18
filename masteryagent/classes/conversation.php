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

namespace mod_masteryagent;

/**
 * Authenticated conversation actions shared by AJAX and the POST fallback.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation {
    /**
     * A revision token to reject duplicate requests and forms from an older tab.
     * This is not an authorization token; identity always comes from the session.
     *
     * @param attempt|null $current Latest attempt.
     * @return string
     */
    public static function state(?attempt $current): string {
        if ($current === null) {
            return 'new';
        }
        $record = $current->get_record();
        $messages = $current->messages();
        $last = empty($messages) ? null : end($messages);
        return hash('sha256', json_encode([
            (int) $record->id, $record->status, (int) $record->lessonindex,
            (int) $record->turnsused, (int) $record->timefinished, $last ? (int) $last->id : 0,
            $current->draft_reply(),
        ]));
    }

    /**
     * Apply one action to the signed-in user's latest attempt.
     *
     * The caller must validate context access (external API) or require_login
     * (web page), and provide Moodle's session-key protection. Capabilities and
     * ownership are enforced here as well as on the external API boundary.
     *
     * @param \stdClass $instance Activity record.
     * @param \context_module $context Validated activity context.
     * @param string $action start, reply, pause or finish.
     * @param string $state Revision displayed when the form was rendered.
     * @param string $reply Current reply box text, including an unsent draft.
     * @param bool $confirmed Whether the learner confirmed final submission.
     * @return array Latest attempt and whether this was a stale request.
     */
    public static function process(\stdClass $instance, \context_module $context, string $action,
            string $state, string $reply = '', bool $confirmed = false): array {
        global $DB, $USER;

        $cm = get_coursemodule_from_id('masteryagent', $context->instanceid, 0, false, MUST_EXIST);
        if ((int) $cm->instance !== (int) $instance->id) {
            throw new \invalid_parameter_exception('Activity and context do not match.');
        }
        require_capability('mod/masteryagent:view', $context);
        require_capability('mod/masteryagent:attempt', $context);
        if (isguestuser() || !isloggedin()) {
            throw new \moodle_exception('requireloginerror', 'error');
        }
        if (!in_array($action, ['start', 'reply', 'pause', 'finish'], true)) {
            throw new \invalid_parameter_exception('Unknown conversation action.');
        }

        $sequence = sequence::from_instance($instance);
        if ($sequence->count() === 0) {
            throw new \moodle_exception('nolessonloaded', 'mod_masteryagent');
        }

        // Serialize writes across tabs/sessions, including sites with alternate session handlers.
        $factory = \core\lock\lock_config::get_lock_factory('mod_masteryagent');
        $lock = $factory->get_lock($instance->id . ':' . $USER->id, 0);
        if (!$lock) {
            throw new \moodle_exception('conversationbusy', 'mod_masteryagent');
        }

        try {
            $current = attempt::get_latest($instance, (int) $USER->id);
            if (!hash_equals(self::state($current), $state)) {
                return ['attempt' => $current, 'stale' => true];
            }
            if ($action === 'start') {
                if ($current !== null && (!$current->is_finished() || empty($instance->allowretry))) {
                    throw new \moodle_exception('attemptnotavailable', 'mod_masteryagent');
                }
            } else if ($current === null || $current->is_finished()) {
                throw new \moodle_exception('attemptnotavailable', 'mod_masteryagent');
            }
            if (in_array($action, ['reply', 'pause', 'finish'], true)
                    && \core_text::strlen($reply) > attempt::MAX_REPLY_CHARS) {
                throw new \moodle_exception('replytoolong', 'mod_masteryagent', '', attempt::MAX_REPLY_CHARS);
            }
            if ($action === 'finish') {
                if (!$confirmed) {
                    throw new \moodle_exception('finishconfirmationrequired', 'mod_masteryagent');
                }
                if (trim($reply) !== '') {
                    throw new \moodle_exception('finishunsent', 'mod_masteryagent');
                }
            }
            if ($action === 'reply') {
                $reply = trim($reply);
                if ($reply === '') {
                    throw new \moodle_exception('replyrequired', 'mod_masteryagent');
                }
            }

            // A failed turn must not consume a reply, duplicate scores, or leave a partial grade.
            $transaction = $DB->start_delegated_transaction();
            try {
                if ($action === 'start') {
                    $current = attempt::start($instance, (int) $USER->id, $sequence);
                } else if ($action === 'reply') {
                    $current->submit($reply, $sequence, (int) $context->id);
                } else if ($action === 'pause') {
                    $current->save_draft($reply);
                } else {
                    $current->finish_now($sequence, (int) $context->id);
                }
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
            return ['attempt' => $current, 'stale' => false];
        } finally {
            $lock->release();
        }
    }
}
