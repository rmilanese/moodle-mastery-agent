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
 * One learner's assessment conversation across a sequence of lessons.
 *
 * The attempt walks the sequence: each lesson closes when its evidence is
 * complete or its reply budget is spent, the agent moves straight on to the
 * next, and the whole attempt is scored when the last one closes.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt {

    /** Status: conversation still open. */
    const STATUS_INPROGRESS = 'inprogress';

    /** Status: conversation closed and scored. */
    const STATUS_FINISHED = 'finished';

    /** Longest single learner reply accepted, in characters. */
    const MAX_REPLY_CHARS = 8000;

    /** @var \stdClass The attempt record. */
    protected \stdClass $record;

    /** @var \stdClass The activity instance record. */
    protected \stdClass $instance;

    /**
     * Constructor.
     *
     * @param \stdClass $record Attempt record.
     * @param \stdClass $instance Activity instance record.
     */
    public function __construct(\stdClass $record, \stdClass $instance) {
        $this->record = $record;
        $this->instance = $instance;
    }

    /**
     * Fetch a user's most recent attempt, if any.
     *
     * @param \stdClass $instance Activity instance record.
     * @param int $userid User id.
     * @return self|null
     */
    public static function get_latest(\stdClass $instance, int $userid): ?self {
        global $DB;
        $records = $DB->get_records(
            'masteryagent_attempt',
            ['masteryagentid' => $instance->id, 'userid' => $userid],
            'id DESC',
            '*',
            0,
            1
        );
        if (empty($records)) {
            return null;
        }
        return new self(reset($records), $instance);
    }

    /**
     * Fetch every attempt for an activity.
     *
     * @param \stdClass $instance Activity instance record.
     * @return array List of attempt records.
     */
    public static function all_for_instance(\stdClass $instance): array {
        global $DB;
        return $DB->get_records('masteryagent_attempt', ['masteryagentid' => $instance->id], 'timestarted DESC');
    }

    /**
     * Start a new conversation at the first lesson in the sequence.
     *
     * @param \stdClass $instance Activity instance record.
     * @param int $userid User id.
     * @param sequence $sequence The lessons to assess.
     * @return self
     */
    public static function start(\stdClass $instance, int $userid, sequence $sequence): self {
        global $DB;

        $record = (object) [
            'masteryagentid' => $instance->id,
            'userid' => $userid,
            'status' => self::STATUS_INPROGRESS,
            'draftreply' => '',
            'turnsused' => 0,
            'lessonindex' => 0,
            'lessonscores' => json_encode([]),
            'score' => null,
            'summary' => null,
            'evidencejson' => json_encode(['covered' => [], 'misconceptions' => [], 'resolved' => []]),
            'timestarted' => time(),
            'timefinished' => 0,
        ];
        $record->id = $DB->insert_record('masteryagent_attempt', $record);

        $attempt = new self($record, $instance);
        $first = $sequence->get(0);
        if ($first !== null) {
            $attempt->add_message('agent', $first->question_text(), $sequence->key_for(0), 0);
        }
        return $attempt;
    }

    /**
     * The underlying record.
     *
     * @return \stdClass
     */
    public function get_record(): \stdClass {
        return $this->record;
    }

    /**
     * Attempt id.
     *
     * @return int
     */
    public function get_id(): int {
        return (int) $this->record->id;
    }

    /**
     * Whether the whole attempt has been closed and scored.
     *
     * @return bool
     */
    public function is_finished(): bool {
        return $this->record->status === self::STATUS_FINISHED;
    }

    /**
     * Position in the lesson sequence, zero based.
     *
     * @return int
     */
    public function lesson_index(): int {
        return (int) $this->record->lessonindex;
    }

    /**
     * Replies consumed on the current lesson.
     *
     * @return int
     */
    public function turns_used(): int {
        return (int) $this->record->turnsused;
    }

    /**
     * Replies still available on the current lesson.
     *
     * @return int
     */
    public function turns_left(): int {
        return max(0, (int) $this->instance->maxturns - $this->turns_used());
    }

    /**
     * The unsent answer saved when the learner chose to pause.
     *
     * @return string
     */
    public function draft_reply(): string {
        return (string) ($this->record->draftreply ?? '');
    }

    /**
     * Save a draft without adding a turn, calling AI or changing a grade.
     *
     * @param string $draft Unsent text, validated by the conversation service.
     */
    public function save_draft(string $draft): void {
        global $DB;
        $this->record->draftreply = $draft;
        $DB->set_field('masteryagent_attempt', 'draftreply', $draft, ['id' => $this->record->id]);
    }

    /**
     * The running evidence ledger for the current lesson.
     *
     * @return array
     */
    public function ledger(): array {
        $decoded = json_decode((string) $this->record->evidencejson, true);
        if (!is_array($decoded)) {
            return ['covered' => [], 'misconceptions' => [], 'resolved' => []];
        }
        return $decoded + ['covered' => [], 'misconceptions' => [], 'resolved' => []];
    }

    /**
     * Results recorded for each lesson closed so far.
     *
     * @return array
     */
    public function lesson_results(): array {
        $decoded = json_decode((string) $this->record->lessonscores, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Every message in the conversation, oldest first.
     *
     * @return array List of message records.
     */
    public function messages(): array {
        global $DB;
        return $DB->get_records('masteryagent_message', ['attemptid' => $this->record->id], 'id ASC');
    }

    /**
     * The conversation for one lesson, in the shape the agent expects.
     *
     * @param string $lessonkey Which lesson to extract.
     * @return array
     */
    public function transcript_for(string $lessonkey): array {
        $transcript = [];
        foreach ($this->messages() as $message) {
            if ($message->lessonkey !== $lessonkey) {
                continue;
            }
            $transcript[] = ['role' => $message->role, 'message' => $message->message];
        }
        return $transcript;
    }

    /**
     * Append a message to the conversation.
     *
     * @param string $role Either agent or student.
     * @param string $message Message body.
     * @param string $lessonkey Which lesson the message belongs to.
     * @param int|null $turnno Turn number, defaults to the current turn count.
     * @return void
     */
    public function add_message(string $role, string $message, string $lessonkey, ?int $turnno = null): void {
        global $DB;
        $DB->insert_record('masteryagent_message', (object) [
            'attemptid' => $this->record->id,
            'turnno' => $turnno ?? $this->turns_used(),
            'lessonkey' => $lessonkey,
            'role' => $role,
            'message' => $message,
            'timecreated' => time(),
        ]);
    }

    /**
     * Process one learner reply against the current lesson.
     *
     * Closes the lesson and advances when the agent is satisfied or the reply
     * budget is spent, and closes the whole attempt after the last lesson.
     *
     * @param string $text The learner's reply.
     * @param sequence $sequence The lessons being assessed.
     * @param int $contextid Module context id for the AI request.
     * @return void
     * @throws \moodle_exception When the AI provider fails.
     */
    public function submit(string $text, sequence $sequence, int $contextid): void {
        global $DB;

        if ($this->is_finished()) {
            return;
        }

        $lesson = $sequence->get($this->lesson_index());
        if ($lesson === null) {
            return;
        }

        $text = trim($text);
        if ($text === '') {
            return;
        }
        $text = \core_text::substr($text, 0, self::MAX_REPLY_CHARS);

        $lessonkey = $sequence->key_for($this->lesson_index());
        $agent = new agent($lesson, $this->instance, $contextid);

        $this->record->turnsused = $this->turns_used() + 1;
        $this->add_message('student', $text, $lessonkey, $this->record->turnsused);
        $DB->set_field('masteryagent_attempt', 'turnsused', $this->record->turnsused, ['id' => $this->record->id]);

        $result = $agent->next_turn($this->transcript_for($lessonkey), $this->ledger(), $this->turns_used());

        $this->record->evidencejson = json_encode([
            'covered' => $result['covered'],
            'misconceptions' => $result['misconceptions'],
            'resolved' => $result['resolved'],
        ]);
        $DB->set_field(
            'masteryagent_attempt',
            'evidencejson',
            $this->record->evidencejson,
            ['id' => $this->record->id]
        );

        $this->add_message('agent', $result['reply'], $lessonkey, $this->record->turnsused);

        // Only clear the draft after a successful turn; the caller rolls back failures.
        $this->save_draft('');

        if ($result['ready_to_close'] || $this->turns_left() <= 0) {
            $this->close_lesson($sequence, $contextid);
        }
    }

    /**
     * Score the current lesson, then advance or finish.
     *
     * @param sequence $sequence The lessons being assessed.
     * @param int $contextid Module context id for the AI request.
     * @return void
     * @throws \moodle_exception When the AI provider fails.
     */
    protected function close_lesson(sequence $sequence, int $contextid): void {
        global $DB;

        $index = $this->lesson_index();
        $lesson = $sequence->get($index);
        if ($lesson === null) {
            return;
        }

        $lessonkey = $sequence->key_for($index);
        $agent = new agent($lesson, $this->instance, $contextid);
        $assessment = $agent->final_assessment($this->transcript_for($lessonkey), $this->ledger());

        $results = $this->lesson_results();
        $results[] = [
            'key' => $lessonkey,
            'lesson_id' => $lesson->lesson_id(),
            'title' => $lesson->title(),
            'score' => $assessment['score'],
            'max' => (int) $this->instance->maxgrade,
            'summary' => $assessment['summary'],
            'dimensions' => $assessment['dimensions'],
            // Snapshot public labels and readings so later uploads do not rewrite this feedback.
            'dimension_names' => $lesson->dimension_names(),
            'learning_resources' => $lesson->learning_resources(),
            'strengths' => $assessment['strengths'],
            'gaps' => $assessment['gaps'],
            'next_step' => $assessment['next_step'],
            'turns' => $this->turns_used(),
        ];

        $this->record->lessonscores = json_encode($results);
        $DB->set_field('masteryagent_attempt', 'lessonscores', $this->record->lessonscores, ['id' => $this->record->id]);

        $next = $index + 1;
        if ($next < $sequence->count()) {
            $this->advance_to($next, $sequence);
            return;
        }

        $this->finalise_attempt($sequence, $contextid);
    }

    /**
     * Move to the next lesson and put its question to the learner.
     *
     * @param int $index Zero-based position to move to.
     * @param sequence $sequence The lessons being assessed.
     * @return void
     */
    protected function advance_to(int $index, sequence $sequence): void {
        global $DB;

        $lesson = $sequence->get($index);
        if ($lesson === null) {
            return;
        }

        $previouskey = $sequence->key_for($this->lesson_index());
        $this->add_message(
            'agent',
            get_string('lessoncomplete', 'mod_masteryagent', (object) [
                'done' => $this->lesson_index() + 1,
                'total' => $sequence->count(),
                'next' => trim($lesson->lesson_id() . ' ' . $lesson->title()),
            ]),
            $previouskey,
            $this->turns_used()
        );

        $this->record->lessonindex = $index;
        $this->record->turnsused = 0;
        $this->record->evidencejson = json_encode(['covered' => [], 'misconceptions' => [], 'resolved' => []]);
        $DB->update_record('masteryagent_attempt', $this->record);

        $this->add_message('agent', $lesson->question_text(), $sequence->key_for($index), 0);
    }

    /**
     * Close the whole attempt: total the lesson scores, synthesise an overall
     * judgement, and push the grade.
     *
     * @param sequence $sequence The lessons assessed.
     * @param int $contextid Module context id for the AI request.
     * @return void
     * @throws \moodle_exception When the AI provider fails.
     */
    protected function finalise_attempt(sequence $sequence, int $contextid): void {
        global $CFG, $DB;

        $results = $this->lesson_results();
        $total = 0.0;
        foreach ($results as $result) {
            $total += (float) ($result['score'] ?? 0);
        }

        $summary = '';
        if ($sequence->is_multi() && !empty($results)) {
            $lastlesson = $sequence->get($sequence->count() - 1);
            $agent = new agent($lastlesson, $this->instance, $contextid);
            $summary = $agent->course_summary($results);
        } else if (!empty($results)) {
            $summary = (string) ($results[0]['summary'] ?? '');
        }

        $this->record->status = self::STATUS_FINISHED;
        $this->record->draftreply = '';
        $this->record->score = $total;
        $this->record->summary = $summary;
        $this->record->timefinished = time();
        $DB->update_record('masteryagent_attempt', $this->record);

        require_once($CFG->dirroot . '/mod/masteryagent/lib.php');
        masteryagent_update_grades($this->instance, (int) $this->record->userid);
    }

    /**
     * Close the attempt early at the learner's request.
     *
     * The current lesson is scored on what has been said; lessons never reached
     * score zero by omission.
     *
     * @param sequence $sequence The lessons being assessed.
     * @param int $contextid Module context id for the AI request.
     * @return void
     * @throws \moodle_exception When the AI provider fails.
     */
    public function finish_now(sequence $sequence, int $contextid): void {
        if ($this->is_finished()) {
            return;
        }

        $lessonkey = $sequence->key_for($this->lesson_index());
        if (count($this->transcript_for($lessonkey)) > 1) {
            // Something was said about this lesson, so score it.
            $index = $this->lesson_index();
            $this->close_lesson($sequence, $contextid);
            if ($this->lesson_index() !== $index && !$this->is_finished()) {
                // More lessons remain, but the learner asked to stop.
                $this->finalise_attempt($sequence, $contextid);
            }
            return;
        }

        $this->finalise_attempt($sequence, $contextid);
    }

    /**
     * How many lessons in this attempt met the mastery threshold.
     *
     * @return int
     */
    public function lessons_mastered(): int {
        $count = 0;
        foreach ($this->lesson_results() as $result) {
            if ((float) ($result['score'] ?? 0) >= (float) $this->instance->threshold) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Whether every lesson assessed met the mastery threshold.
     *
     * @return bool
     */
    public function met_threshold(): bool {
        $results = $this->lesson_results();
        if (empty($results)) {
            // Attempts recorded before sequence mode carry only a total.
            return $this->record->score !== null
                && (float) $this->record->score >= (float) $this->instance->threshold;
        }
        return $this->lessons_mastered() === count($results);
    }

    /**
     * The best finished score a user has recorded for an activity.
     *
     * @param int $instanceid Activity instance id.
     * @param int $userid User id.
     * @return float|null
     */
    public static function best_score(int $instanceid, int $userid): ?float {
        global $DB;
        $score = $DB->get_field_sql(
            "SELECT MAX(score)
               FROM {masteryagent_attempt}
              WHERE masteryagentid = :instanceid
                AND userid = :userid
                AND status = :status",
            ['instanceid' => $instanceid, 'userid' => $userid, 'status' => self::STATUS_FINISHED]
        );
        return $score === null || $score === false ? null : (float) $score;
    }

    /**
     * Delete every attempt and message for an activity.
     *
     * @param int $instanceid Activity instance id.
     * @return void
     */
    public static function delete_all_for_instance(int $instanceid): void {
        global $DB;
        $attemptids = $DB->get_fieldset_select(
            'masteryagent_attempt',
            'id',
            'masteryagentid = ?',
            [$instanceid]
        );
        if (!empty($attemptids)) {
            [$insql, $params] = $DB->get_in_or_equal($attemptids);
            $DB->delete_records_select('masteryagent_message', "attemptid $insql", $params);
        }
        $DB->delete_records('masteryagent_attempt', ['masteryagentid' => $instanceid]);
    }
}
