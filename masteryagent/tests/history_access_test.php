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

namespace mod_masteryagent;

/**
 * Learner-owned history retrieval, bounded paging and read-only metadata.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(attempt::class)]
final class history_access_test extends \advanced_testcase {
    /** @var \stdClass The activity whose history is requested. */
    private \stdClass $instance;

    /** @var \stdClass Another activity in the same course. */
    private \stdClass $otherinstance;

    /** @var \stdClass The learner requesting their history. */
    private \stdClass $student;

    /** @var \stdClass Another learner in the course. */
    private \stdClass $otherstudent;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $course->id, 'lessonkeys' => 'S01,S02',
        ]);
        $this->otherinstance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $course->id, 'lessonkeys' => 'S01,S02',
        ]);
        $this->student = $this->getDataGenerator()->create_user();
        $this->otherstudent = $this->getDataGenerator()->create_user();
        foreach ([$this->student, $this->otherstudent] as $student) {
            $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        }
        $this->setUser($this->student);
    }

    /**
     * Seed history directly without running AI or grading a live attempt.
     *
     * @param \stdClass $instance Activity record.
     * @param int $userid Learner ID.
     * @param array $overrides Optional record fields.
     * @return int Attempt ID.
     */
    private function make_attempt(\stdClass $instance, int $userid, array $overrides = []): int {
        global $DB;
        return (int) $DB->insert_record('masteryagent_attempt', (object) ($overrides + [
            'masteryagentid' => $instance->id,
            'userid' => $userid,
            'status' => attempt::STATUS_INPROGRESS,
            'draftreply' => 'Private draft text.',
            'turnsused' => 2,
            'lessonindex' => 0,
            'lessonscores' => json_encode([['summary' => 'Private lesson feedback.']]),
            'score' => null,
            'summary' => 'Private final feedback.',
            'evidencejson' => json_encode(['covered' => ['private-evidence-code']]),
            'timestarted' => 1700000000,
            'timefinished' => 0,
        ]));
    }

    public function test_an_owned_older_attempt_can_be_retrieved_without_selecting_the_latest(): void {
        $olderid = $this->make_attempt($this->instance, (int) $this->student->id, [
            'status' => attempt::STATUS_FINISHED, 'score' => 5, 'timefinished' => 1700000100,
        ]);
        $newerid = $this->make_attempt($this->instance, (int) $this->student->id);
        $older = attempt::get_for_user($this->instance, (int) $this->student->id, $olderid);
        $this->assertSame($olderid, $older->get_id());
        $this->assertTrue($older->is_finished());
        $this->assertSame('Private final feedback.', $older->get_record()->summary);
        $this->assertSame($newerid, attempt::get_latest($this->instance, (int) $this->student->id)->get_id());
    }

    public function test_other_users_other_activities_and_missing_ids_have_the_same_failure(): void {
        $otheruserid = $this->make_attempt($this->instance, (int) $this->otherstudent->id);
        $otheractivityid = $this->make_attempt($this->otherinstance, (int) $this->student->id);
        $failures = [];
        foreach ([$otheruserid, $otheractivityid, $otheractivityid + 1000] as $attemptid) {
            try {
                attempt::get_for_user($this->instance, (int) $this->student->id, $attemptid);
                $this->fail('An attempt outside the requested learner and activity scope must not be returned.');
            } catch (\dml_missing_record_exception $exception) {
                $failures[] = [$exception->errorcode, $exception->getMessage()];
            }
        }
        $this->assertCount(3, $failures);
        $this->assertSame($failures[0], $failures[1]);
        $this->assertSame($failures[0], $failures[2]);
    }

    public function test_count_and_pages_are_scoped_to_both_activity_and_learner(): void {
        $firstid = $this->make_attempt($this->instance, (int) $this->student->id);
        $this->make_attempt($this->instance, (int) $this->otherstudent->id);
        $this->make_attempt($this->otherinstance, (int) $this->student->id);
        $secondid = $this->make_attempt($this->instance, (int) $this->student->id, [
            'status' => attempt::STATUS_FINISHED, 'score' => 4, 'timefinished' => 1700000100,
        ]);
        $this->assertSame(2, attempt::count_for_user($this->instance, (int) $this->student->id));
        $records = attempt::page_for_user($this->instance, (int) $this->student->id);
        $this->assertSame([$secondid, $firstid], array_keys($records));
    }

    public function test_empty_history_returns_zero_count_and_an_empty_page(): void {
        $this->make_attempt($this->instance, (int) $this->otherstudent->id);
        $this->make_attempt($this->otherinstance, (int) $this->student->id);
        $this->assertSame(0, attempt::count_for_user($this->instance, (int) $this->student->id));
        $this->assertSame([], attempt::page_for_user($this->instance, (int) $this->student->id));
    }

    public function test_pages_use_stable_newest_id_order_even_when_start_times_match(): void {
        $ids = [];
        for ($index = 0; $index < 23; $index++) {
            $ids[] = $this->make_attempt($this->instance, (int) $this->student->id);
        }
        $expected = array_reverse($ids);
        $this->assertSame(array_slice($expected, 0, 10),
            array_keys(attempt::page_for_user($this->instance, (int) $this->student->id)));
        $this->assertSame(array_slice($expected, 10, 10),
            array_keys(attempt::page_for_user($this->instance, (int) $this->student->id, 1)));
        $this->assertSame(array_slice($expected, 20, 10),
            array_keys(attempt::page_for_user($this->instance, (int) $this->student->id, 2)));
        $this->assertSame([], attempt::page_for_user($this->instance, (int) $this->student->id, 3));
    }

    public function test_page_sizes_are_bounded_and_invalid_offsets_do_not_remove_the_limit(): void {
        $ids = [];
        for ($index = 0; $index < 105; $index++) {
            $ids[] = $this->make_attempt($this->instance, (int) $this->student->id);
        }
        $this->assertCount(100, attempt::page_for_user($this->instance, (int) $this->student->id, 0, PHP_INT_MAX));
        $this->assertSame([end($ids)],
            array_keys(attempt::page_for_user($this->instance, (int) $this->student->id, -1, 0)));
        $this->assertSame([end($ids)],
            array_keys(attempt::page_for_user($this->instance, (int) $this->student->id, 0, -1)));
        $this->assertSame([], attempt::page_for_user($this->instance, (int) $this->student->id, PHP_INT_MAX, 100));
    }

    public function test_list_metadata_preserves_pending_and_zero_scores_without_loading_private_payloads(): void {
        $pendingid = $this->make_attempt($this->instance, (int) $this->student->id);
        $finishedid = $this->make_attempt($this->instance, (int) $this->student->id, [
            'status' => attempt::STATUS_FINISHED, 'score' => 0, 'timefinished' => 1700000100,
        ]);
        $records = attempt::page_for_user($this->instance, (int) $this->student->id);
        $this->assertNull($records[$pendingid]->score);
        $this->assertSame(attempt::STATUS_INPROGRESS, $records[$pendingid]->status);
        $this->assertSame(0.0, (float) $records[$finishedid]->score);
        $this->assertSame(attempt::STATUS_FINISHED, $records[$finishedid]->status);
        $expectedfields = ['id', 'lessonindex', 'score', 'status', 'timefinished', 'timestarted', 'turnsused'];
        foreach ($records as $record) {
            $fields = array_keys(get_object_vars($record));
            sort($fields);
            $this->assertSame($expectedfields, $fields);
        }
    }

    public function test_history_reads_leave_saved_attempts_messages_and_grades_unchanged(): void {
        global $DB;
        $attemptid = $this->make_attempt($this->instance, (int) $this->student->id);
        $DB->insert_record('masteryagent_message', (object) [
            'attemptid' => $attemptid, 'turnno' => 1, 'lessonkey' => 'S01-Q01',
            'role' => 'student', 'message' => 'Saved answer.', 'timecreated' => 1700000001,
        ]);
        $before = [];
        foreach (['masteryagent_attempt', 'masteryagent_message', 'grade_grades'] as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }
        attempt::get_for_user($this->instance, (int) $this->student->id, $attemptid);
        attempt::count_for_user($this->instance, (int) $this->student->id);
        attempt::page_for_user($this->instance, (int) $this->student->id);
        foreach ($before as $table => $records) {
            $this->assertEquals($records, $DB->get_records($table, null, 'id'));
        }
    }
}
