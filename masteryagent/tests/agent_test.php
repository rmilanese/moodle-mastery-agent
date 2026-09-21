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
 * Prompt construction and response handling.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(agent::class)]
final class agent_test extends \advanced_testcase {

    use helper_trait;

    /** @var \stdClass Activity instance under test. */
    private \stdClass $instance;

    /** @var lesson The lesson being assessed. */
    private lesson $lesson;

    /** @var int Module context id. */
    private int $contextid;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->instance = $this->getDataGenerator()->create_module('masteryagent', [
            'course' => $course->id,
            'lessonkeys' => 'S01',
        ]);
        $cm = get_coursemodule_from_instance('masteryagent', $this->instance->id, $course->id, false, MUST_EXIST);
        $this->contextid = (int) \context_module::instance($cm->id)->id;
        $this->lesson = sequence::from_instance($this->instance)->get(0);
    }

    protected function tearDown(): void {
        $this->reset_ai();
        parent::tearDown();
    }

    /**
     * An agent wired to the fixture lesson.
     *
     * @param array $overrides Instance settings to vary, e.g. maxgrade, threshold.
     * @return agent
     */
    private function agent(array $overrides = []): agent {
        $instance = clone $this->instance;
        foreach ($overrides as $field => $value) {
            $instance->$field = $value;
        }
        return new agent($this->lesson, $instance, $this->contextid);
    }

    public function test_decodes_a_plain_json_object(): void {
        $this->assertSame(['a' => 1], agent::decode_json('{"a":1}'));
    }

    public function test_decodes_json_inside_a_code_fence(): void {
        $this->assertSame(['a' => 2], agent::decode_json("```json\n{\"a\":2}\n```"));
        $this->assertSame(['a' => 2], agent::decode_json("```\n{\"a\":2}\n```"));
    }

    public function test_decodes_json_surrounded_by_prose(): void {
        $this->assertSame(['a' => 3], agent::decode_json('Certainly! {"a":3} Let me know.'));
    }

    public function test_returns_null_when_there_is_no_json(): void {
        $this->assertNull(agent::decode_json('no json here'));
        $this->assertNull(agent::decode_json('{ unbalanced'));
    }

    public function test_turn_prompt_carries_the_rubric_and_budget(): void {
        $this->stub_ai();
        $this->agent()->next_turn(
            [['role' => 'agent', 'message' => 'Question'], ['role' => 'student', 'message' => 'Answer']],
            ['covered' => [], 'misconceptions' => []],
            1
        );

        $prompt = $this->sentprompts[0];
        $this->assertStringContainsString('=== LESSON ===', $prompt);
        $this->assertStringContainsString('S01: Reading the Ground', $prompt);
        $this->assertStringContainsString('SE1. Explains that a map represents the ground', $prompt);
        $this->assertStringContainsString('MC1. Treats the map as authoritative', $prompt);
        $this->assertStringContainsString('P1. What would you go and look at yourself', $prompt);
        $this->assertStringContainsString('used 1 of 6', $prompt);
        $this->assertStringContainsString('MARINE: Answer', $prompt);
    }

    public function test_last_turn_tells_the_agent_to_stop_asking(): void {
        $this->stub_ai();
        $this->agent()->next_turn([['role' => 'student', 'message' => 'Answer']], [], 6);

        $this->assertStringContainsString('This was the final reply', $this->sentprompts[0]);
    }

    public function test_turn_result_is_normalised(): void {
        $this->stub_ai([
            'covered' => ['SE1', 'SE1', 'SE2'],
            'misconceptions' => ['MC1'],
            'closeafter' => 1,
            'reply' => '  Tell me more.  ',
        ]);

        $result = $this->agent()->next_turn([['role' => 'student', 'message' => 'Answer']], [], 1);

        $this->assertSame(['SE1', 'SE2'], $result['covered'], 'duplicates are collapsed');
        $this->assertSame(['MC1'], $result['misconceptions']);
        $this->assertTrue($result['ready_to_close']);
        $this->assertSame('Tell me more.', $result['reply'], 'reply is trimmed');
    }

    public function test_fenced_turn_replies_are_accepted(): void {
        $this->stub_ai(['fence' => true]);

        $result = $this->agent()->next_turn([['role' => 'student', 'message' => 'Answer']], [], 1);

        $this->assertNotEmpty($result['reply']);
    }

    public function test_unreadable_turn_response_raises(): void {
        $this->stub_ai_garbage();

        $this->expectException(\moodle_exception::class);
        $this->agent()->next_turn([['role' => 'student', 'message' => 'Answer']], [], 1);
    }

    public function test_final_prompt_carries_bands_and_dimensions(): void {
        $this->stub_ai();
        $this->agent()->final_assessment([['role' => 'student', 'message' => 'Answer']], []);

        $prompt = end($this->sentprompts);
        $this->assertStringContainsString('=== FULL CONVERSATION ===', $prompt);
        $this->assertStringContainsString('Scoring bands', $prompt);
        $this->assertStringContainsString('S01-MD01, S01-MD02', $prompt);
        $this->assertStringContainsString('mastery threshold of 3', $prompt);
    }

    public function test_the_band_table_covers_every_point_on_the_scale(): void {
        // Regression: the bands were hardcoded for a 0-4 scale, so on a wider
        // scale nothing described the points between "acceptable" and the
        // maximum and every score collapsed onto 0, 1, 2, 3 or the maximum.
        $this->stub_ai();
        $this->agent(['maxgrade' => 10, 'threshold' => 7])
            ->final_assessment([['role' => 'student', 'message' => 'Answer']], []);

        $prompt = end($this->sentprompts);
        $this->assertStringContainsString('Scoring bands, whole numbers only, 0 to 10', $prompt);

        // Collect the band labels and check they account for 0..10 with no gap.
        preg_match_all('/^(\\d+)(?: to (\\d+))? - /m', $prompt, $matches, PREG_SET_ORDER);
        $covered = [];
        foreach ($matches as $match) {
            $low = (int) $match[1];
            $high = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : $low;
            for ($point = $low; $point <= $high; $point++) {
                $covered[$point] = true;
            }
        }
        ksort($covered);
        $this->assertSame(range(0, 10), array_keys($covered), 'every score 0-10 falls in a band');
    }

    public function test_a_corrected_misconception_is_not_scored_as_outstanding(): void {
        // Regression: the misconception list was cumulative and the scoring
        // prompt capped any conversation that had ever contained one, so a
        // Marine who corrected themselves when challenged stayed capped.
        $this->stub_ai();
        $this->agent()->final_assessment(
            [['role' => 'student', 'message' => 'Answer']],
            ['covered' => ['SE1'], 'misconceptions' => [], 'resolved' => ['MC1']]
        );

        $prompt = end($this->sentprompts);
        $this->assertStringContainsString('Misconceptions still outstanding: (none)', $prompt);
        $this->assertStringContainsString('Misconceptions raised and then corrected: MC1', $prompt);
        $this->assertStringContainsString('Only a misconception still outstanding at the end', $prompt);
    }

    public function test_a_misconception_the_marine_corrected_leaves_the_outstanding_list(): void {
        $this->stub_ai(['misconceptions' => ['MC1'], 'resolved' => ['MC2']]);

        $result = $this->agent()->next_turn([['role' => 'student', 'message' => 'Answer']], [], 1);

        $this->assertSame(['MC1'], $result['misconceptions']);
        $this->assertSame(['MC2'], $result['resolved']);
    }

    public function test_a_code_in_both_lists_counts_as_corrected(): void {
        // The model carrying its cumulative list forward without retiring the
        // code it just marked corrected must not re-penalise the Marine.
        $this->stub_ai(['misconceptions' => ['MC1', 'MC2'], 'resolved' => ['MC1']]);

        $result = $this->agent()->next_turn([['role' => 'student', 'message' => 'Answer']], [], 1);

        $this->assertSame(['MC2'], $result['misconceptions'], 'MC1 is not outstanding any more');
        $this->assertSame(['MC1'], $result['resolved']);
    }

    public function test_final_assessment_is_returned_whole(): void {
        $this->stub_ai(['scores' => ['S01' => 4]]);

        $assessment = $this->agent()->final_assessment([['role' => 'student', 'message' => 'Answer']], []);

        $this->assertSame(4.0, $assessment['score']);
        $this->assertSame('Closing feedback for S01.', $assessment['summary']);
        $this->assertCount(1, $assessment['dimensions']);
        $this->assertSame(['Strength in S01.'], $assessment['strengths']);
        $this->assertSame(['Gap in S01.'], $assessment['gaps']);
        $this->assertStringContainsString('Next step', $assessment['next_step']);
    }

    public function test_a_score_outside_the_scale_is_clamped(): void {
        agent::set_test_responder(fn(string $p) => json_encode(['score' => 97, 'summary' => 'Too generous.']));
        $this->assertSame(4.0, $this->agent()->final_assessment([], [])['score']);

        agent::set_test_responder(fn(string $p) => json_encode(['score' => -5, 'summary' => 'Too harsh.']));
        $this->assertSame(0.0, $this->agent()->final_assessment([], [])['score']);
    }

    public function test_numeric_scores_are_normalised_to_the_stored_precision_before_returning_feedback(): void {
        foreach ([[2.6, 2.6], [2.999, 3.0], [2.994, 2.99], [3.999, 4.0]] as [$provided, $expected]) {
            agent::set_test_responder(static fn(string $prompt) => json_encode(['score' => $provided, 'summary' => 'Feedback.']));
            $this->assertSame($expected, $this->agent()->final_assessment([], [])['score']);
        }
    }

    public function test_course_summary_omits_unassessed_lessons_and_keeps_two_decimal_scores(): void {
        $this->stub_ai();
        $skipped = ['status' => 'notassessed', 'lesson_id' => 'SKIPPED_LESSON', 'title' => 'Not assessed',
            'score' => 0, 'max' => 4];
        $this->agent()->course_summary([
            ['lesson_id' => 'S01', 'title' => 'Assessed lesson', 'score' => 2.99, 'max' => 4], $skipped,
        ]);
        $this->assertCount(1, $this->sentprompts);
        $this->assertStringContainsString('scored 2.99 of 4', $this->sentprompts[0]);
        $this->assertStringContainsString('Total: 2.99 of 4', $this->sentprompts[0]);
        $this->assertStringNotContainsString('SKIPPED_LESSON', $this->sentprompts[0]);
        $this->assertSame('', $this->agent()->course_summary([$skipped]));
        $this->assertCount(1, $this->sentprompts, 'An entirely unassessed attempt must not call the AI summary.');
    }

    public function test_course_summary_reports_across_lessons(): void {
        $this->stub_ai(['coursesummary' => 'You explain well but stop short of mechanism.']);

        $summary = $this->agent()->course_summary([
            ['lesson_id' => 'S01', 'title' => 'Reading the Ground', 'score' => 4, 'max' => 4,
                'strengths' => ['Terrain'], 'gaps' => ['Sources']],
            ['lesson_id' => 'S02', 'title' => 'Deciding', 'score' => 2, 'max' => 4,
                'strengths' => [], 'gaps' => ['Intent']],
        ]);

        $this->assertSame('You explain well but stop short of mechanism.', $summary);
        $prompt = end($this->sentprompts);
        $this->assertStringContainsString('S01 Reading the Ground - scored 4 of 4', $prompt);
        $this->assertStringContainsString('Total: 6 of 8', $prompt);
    }

    public function test_course_summary_falls_back_to_plain_text(): void {
        agent::set_test_responder(fn(string $p) => '  A plain prose summary.  ');

        $summary = $this->agent()->course_summary([
            ['lesson_id' => 'S01', 'title' => 'Reading the Ground', 'score' => 3, 'max' => 4],
        ]);

        $this->assertSame('A plain prose summary.', $summary);
    }

    public function test_empty_provider_output_raises(): void {
        agent::set_test_responder(fn(string $p) => '   ');

        $this->expectException(\moodle_exception::class);
        $this->agent()->next_turn([], [], 1);
    }
}
