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
 * Shared helpers for the mastery agent test suite.
 *
 * Tests never call a real AI provider. Instead they install a scripted
 * responder that answers the plugin's prompts in the same shapes a provider
 * would, so the conversation engine can be driven deterministically.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait helper_trait {

    /** @var array Every prompt the plugin sent during a test. */
    protected array $sentprompts = [];

    /**
     * Install a scripted AI responder.
     *
     * Recognised config keys:
     *   closeafter     int    Close each lesson after this many learner replies (default 1).
     *   scores         array  Score to award per lesson id, e.g. ['S01' => 4]. Default 3.
     *   reply          string The evaluator's conversational reply.
     *   clarification  string An ungraded plain-language question clarification.
     *   coursesummary  string The closing summary for a multi-lesson run.
     *   covered        array  Evidence codes to report as covered.
     *   misconceptions array  Misconception codes to report as still outstanding.
     *   resolved       array  Misconception codes to report as corrected.
     *   fence          bool   Wrap turn replies in a ```json fence (default false).
     *
     * @param array $config Script configuration.
     * @return void
     */
    protected function stub_ai(array $config = []): void {
        $config += [
            'closeafter' => 1,
            'scores' => [],
            'reply' => 'Understood. Say more about the second half.',
            'clarification' => 'Explain what the question asks you to decide and why.',
            'coursesummary' => 'Across the run you explained concepts but rarely showed mechanism.',
            'covered' => ['SE1'],
            'misconceptions' => [],
            'resolved' => [],
            'fence' => false,
        ];

        $this->sentprompts = [];
        $turns = 0;

        agent::set_test_responder(function (string $prompt) use ($config, &$turns) {
            $this->sentprompts[] = $prompt;

            if (str_contains($prompt, '=== QUESTION CLARIFICATION ===')) {
                return json_encode(['clarification' => $config['clarification']]);
            }

            if (str_contains($prompt, '=== LESSON RESULTS ===')) {
                return json_encode(['summary' => $config['coursesummary']]);
            }

            if (str_contains($prompt, '=== FULL CONVERSATION ===')) {
                $turns = 0;
                $lessonid = self::lesson_id_from_prompt($prompt);
                return json_encode([
                    'score' => $config['scores'][$lessonid] ?? 3,
                    'summary' => "Closing feedback for {$lessonid}.",
                    'dimensions' => [
                        ['id' => $lessonid . '-MD01', 'verdict' => 'met', 'comment' => 'Reasoning shown.'],
                    ],
                    'strengths' => ["Strength in {$lessonid}."],
                    'gaps' => ["Gap in {$lessonid}."],
                    'next_step' => "Next step for {$lessonid}.",
                ]);
            }

            $turns++;
            $payload = json_encode([
                'covered' => $config['covered'],
                'misconceptions' => $config['misconceptions'],
                'resolved' => $config['resolved'],
                'ready_to_close' => $turns >= $config['closeafter'],
                'reply' => $config['reply'],
            ]);

            return $config['fence'] ? "```json\n{$payload}\n```" : $payload;
        });
    }

    /**
     * Install a responder that returns something the plugin cannot parse.
     *
     * @return void
     */
    protected function stub_ai_garbage(): void {
        agent::set_test_responder(fn(string $prompt) => 'the model replied in prose');
    }

    /**
     * Remove any scripted responder. Call from tearDown.
     *
     * @return void
     */
    protected function reset_ai(): void {
        agent::set_test_responder(null);
    }

    /**
     * Read the lesson id out of a prompt's LESSON block.
     *
     * @param string $prompt The prompt sent to the provider.
     * @return string
     */
    protected static function lesson_id_from_prompt(string $prompt): string {
        if (preg_match('/=== LESSON ===\s*\n([A-Za-z0-9._-]+)/', $prompt, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * The bundled sample question set, decoded.
     *
     * @return array Question records keyed by question id.
     */
    protected function fixture_questions(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/masteryagent/tests/generator/lib.php');
        return lesson::parse_question_set(\mod_masteryagent_generator::fixture_json());
    }

    /**
     * The bundled sample question set as raw JSON.
     *
     * @return string
     */
    protected function fixture_json(): string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/masteryagent/tests/generator/lib.php');
        return \mod_masteryagent_generator::fixture_json();
    }

    /**
     * Put a question set into the current user's draft area, as the file
     * picker would when a teacher saves the settings form.
     *
     * @param string $contents File contents.
     * @return int The draft item id.
     */
    protected function make_draft_file(string $contents): int {
        global $USER;

        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $fs->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'questionset.json',
        ], $contents);

        return $draftitemid;
    }
}
