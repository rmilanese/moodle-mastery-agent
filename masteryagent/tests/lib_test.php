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

require_once(__DIR__ . '/helper_trait.php');

/**
 * The module API: saving settings, loading question sets, and the gradebook.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class lib_test extends \advanced_testcase {

    use helper_trait;

    /** @var \stdClass The course under test. */
    private \stdClass $course;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();

        require_once($CFG->dirroot . '/mod/masteryagent/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $this->course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
    }

    protected function tearDown(): void {
        $this->reset_ai();
        parent::tearDown();
    }

    /**
     * Create an activity the way the settings form does: by uploading a file.
     *
     * @param string $lessonkeys Lesson selection.
     * @param string|null $contents File contents, defaults to the fixture.
     * @return \stdClass The stored instance record.
     */
    private function create_from_upload(string $lessonkeys, ?string $contents = null): \stdClass {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'masteryagent'], '*', MUST_EXIST);
        $info = add_moduleinfo((object) [
            'course' => $this->course->id,
            'module' => $module->id,
            'modulename' => 'masteryagent',
            'section' => 1,
            'visible' => 1,
            'name' => 'Uploaded activity',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'lessonfile' => $this->make_draft_file($contents ?? $this->fixture_json()),
            'lessonkeys' => $lessonkeys,
            'maxturns' => 6,
            'maxgrade' => 4,
            'threshold' => 3,
            'allowretry' => 1,
            'provisional' => 1,
            'cmidnumber' => '',
        ], $this->course);

        return $DB->get_record('masteryagent', ['id' => $info->instance], '*', MUST_EXIST);
    }

    public function test_supported_features(): void {
        $this->assertTrue(masteryagent_supports(FEATURE_MOD_INTRO));
        $this->assertTrue(masteryagent_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertFalse(
            masteryagent_supports(FEATURE_BACKUP_MOODLE2),
            'backup is declared unsupported rather than silently producing broken backups'
        );
        $this->assertNull(masteryagent_supports('something_invented'));
    }

    public function test_an_uploaded_file_becomes_the_lesson_sequence(): void {
        $instance = $this->create_from_upload('S02');

        $this->assertSame('S02', $instance->lessonid);
        $this->assertSame('S02-Q01', $instance->questionid);
        $this->assertSame('Deciding Under Time Pressure', $instance->lessontitle);
        $this->assertSame('S02', $instance->lessonkeys);

        $sequence = sequence::from_instance($instance);
        $this->assertSame(1, $sequence->count());
        $this->assertCount(3, $sequence->get(0)->evidence('strong_evidence'));

        $meta = json_decode($instance->sourcemeta, true);
        $this->assertSame('DRAFT_PENDING_HUMAN_VALIDATION', $meta['rubric_validation_status']);
    }

    public function test_a_multi_lesson_selection_is_summarised(): void {
        $instance = $this->create_from_upload('S01, S02, S03');

        $this->assertSame('S01 - S03 (3)', $instance->lessontitle);
        $this->assertSame(3, sequence::from_instance($instance)->count());
    }

    public function test_a_blank_selection_loads_the_whole_file(): void {
        $instance = $this->create_from_upload('');

        $this->assertSame(3, sequence::from_instance($instance)->count());
    }

    public function test_an_unusable_file_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        masteryagent_apply_source_file((object) [
            'lessonfile' => $this->make_draft_file('{"nothing":"useful"}'),
            'lessonkeys' => '',
        ]);
    }

    public function test_an_unknown_lesson_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        masteryagent_apply_source_file((object) [
            'lessonfile' => $this->make_draft_file($this->fixture_json()),
            'lessonkeys' => 'S99',
        ]);
    }

    public function test_saving_settings_without_a_new_file_keeps_the_lessons(): void {
        global $DB;
        $instance = $this->create_from_upload('S02');
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);

        $update = clone $instance;
        $update->instance = $instance->id;
        $update->coursemodule = $cm->id;
        $update->modulename = 'masteryagent';
        $update->cmidnumber = '';
        $update->visible = 1;
        $update->visibleoncoursepage = 1;
        $update->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $update->lessonfile = 0;
        $update->lessonkeys = '';
        $update->maxturns = 4;
        update_moduleinfo($cm, $update, $this->course);

        $after = $DB->get_record('masteryagent', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('S02', $after->lessonid, 'the lesson survives an unrelated settings change');
        $this->assertSame('S02', $after->lessonkeys);
        $this->assertSame(4, (int) $after->maxturns);
    }

    public function test_re_uploading_swaps_the_lessons(): void {
        global $DB;
        $instance = $this->create_from_upload('S01');
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);

        $update = clone $instance;
        $update->instance = $instance->id;
        $update->coursemodule = $cm->id;
        $update->modulename = 'masteryagent';
        $update->cmidnumber = '';
        $update->visible = 1;
        $update->visibleoncoursepage = 1;
        $update->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
        $update->lessonfile = $this->make_draft_file($this->fixture_json());
        $update->lessonkeys = 'S03';
        update_moduleinfo($cm, $update, $this->course);

        $after = $DB->get_record('masteryagent', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('S03', $after->lessonid);
        $this->assertStringContainsString(
            'same failures keep recurring',
            sequence::from_instance($after)->get(0)->question_text()
        );
    }

    public function test_the_gradebook_maximum_scales_with_the_lessons(): void {
        global $DB;

        $single = $this->create_from_upload('S01');
        $this->assertSame(4, masteryagent_total_grade($single));
        $item = $DB->get_record('grade_items', ['itemmodule' => 'masteryagent', 'iteminstance' => $single->id]);
        $this->assertSame(4.0, (float) $item->grademax);

        $all = $this->create_from_upload('');
        $this->assertSame(12, masteryagent_total_grade($all));
        $item = $DB->get_record('grade_items', ['itemmodule' => 'masteryagent', 'iteminstance' => $all->id]);
        $this->assertSame(12.0, (float) $item->grademax);
    }

    public function test_a_finished_run_reaches_the_gradebook(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $this->course->id,
            'lessonkeys' => 'S01,S02',
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);
        $contextid = (int) \context_module::instance($cm->id)->id;
        $sequence = sequence::from_instance($instance);

        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 4, 'S02' => 3]]);
        $attempt = attempt::start($instance, (int) $student->id, $sequence);
        $attempt->submit('one', $sequence, $contextid);
        $attempt->submit('two', $sequence, $contextid);

        $grades = grade_get_grades($this->course->id, 'mod', 'masteryagent', $instance->id, $student->id);
        $grade = reset($grades->items)->grades[$student->id];

        $this->assertSame(7.0, (float) $grade->grade);
        $this->assertStringContainsString('AI-provisional', $grade->feedback);
        $this->assertStringContainsString('Per-lesson scores', $grade->feedback);
        $this->assertStringContainsString('S01 Reading the Ground', $grade->feedback);
    }

    public function test_the_provisional_marker_can_be_turned_off(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $this->course->id,
            'lessonkeys' => 'S01',
            'provisional' => 0,
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);
        $sequence = sequence::from_instance($instance);

        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 3]]);
        $attempt = attempt::start($instance, (int) $student->id, $sequence);
        $attempt->submit('one', $sequence, (int) \context_module::instance($cm->id)->id);

        $grades = grade_get_grades($this->course->id, 'mod', 'masteryagent', $instance->id, $student->id);
        $grade = reset($grades->items)->grades[$student->id];

        $this->assertSame(3.0, (float) $grade->grade);
        $this->assertStringNotContainsString('AI-provisional', $grade->feedback);
    }

    public function test_the_gradebook_keeps_the_best_attempt(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $this->course->id,
            'lessonkeys' => 'S01',
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);
        $contextid = (int) \context_module::instance($cm->id)->id;
        $sequence = sequence::from_instance($instance);

        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 4]]);
        attempt::start($instance, (int) $student->id, $sequence)->submit('strong', $sequence, $contextid);

        $this->stub_ai(['closeafter' => 1, 'scores' => ['S01' => 1]]);
        attempt::start($instance, (int) $student->id, $sequence)->submit('weak', $sequence, $contextid);

        $grades = grade_get_grades($this->course->id, 'mod', 'masteryagent', $instance->id, $student->id);
        $grade = reset($grades->items)->grades[$student->id];

        $this->assertSame(4.0, (float) $grade->grade, 'a weaker retake does not lower the grade');
    }

    public function test_deleting_the_activity_cleans_up(): void {
        global $DB;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');

        $instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $this->course->id,
            'lessonkeys' => 'S01',
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);
        $sequence = sequence::from_instance($instance);

        $this->stub_ai(['closeafter' => 1]);
        attempt::start($instance, (int) $student->id, $sequence)
            ->submit('one', $sequence, (int) \context_module::instance($cm->id)->id);

        $this->assertTrue(masteryagent_delete_instance($instance->id));

        $this->assertFalse($DB->record_exists('masteryagent', ['id' => $instance->id]));
        $this->assertSame(0, $DB->count_records('masteryagent_attempt', ['masteryagentid' => $instance->id]));
        $this->assertFalse($DB->record_exists('grade_items', [
            'itemmodule' => 'masteryagent',
            'iteminstance' => $instance->id,
        ]));
    }

    public function test_early_submission_records_decimal_grade_and_skipped_feedback_and_keeps_it_after_a_weaker_retry(): void {
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $this->course->id, 'lessonkeys' => 'S01,S02',
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $instance->id, $this->course->id, false, MUST_EXIST);
        $contextid = (int) \context_module::instance($cm->id)->id;
        $sequence = sequence::from_instance($instance);
        foreach ([2.6, 1.1] as $score) {
            $this->stub_ai(['closeafter' => 99, 'scores' => ['S01' => $score]]);
            $current = attempt::start($instance, (int) $student->id, $sequence);
            $current->submit('A submitted answer before finishing early.', $sequence, $contextid);
            $current->finish_now($sequence, $contextid);
            $grades = grade_get_grades($this->course->id, 'mod', 'masteryagent', $instance->id, $student->id);
            $grade = reset($grades->items)->grades[$student->id];
            $this->assertSame(2.6, (float) $grade->grade);
            $this->assertStringContainsString('2.60/4', $grade->feedback);
            $this->assertStringContainsString('0.00/4', $grade->feedback);
            $this->assertStringContainsString('S02 Deciding Under Time Pressure', $grade->feedback);
            $this->assertStringContainsString(get_string('lessonnotassessed', 'mod_masteryagent'), $grade->feedback);
        }
    }

    public function test_deleting_something_that_is_not_there(): void {
        $this->assertFalse(masteryagent_delete_instance(-1));
    }
}
