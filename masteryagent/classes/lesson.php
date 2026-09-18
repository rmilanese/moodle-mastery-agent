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
 * A single lesson's assessment question and its evidence rubric.
 *
 * Wraps one question record from the course question-set JSON. All rubric
 * material is treated as evaluator-only: it is never sent to the browser of a
 * user without the viewreports capability.
 *
 * @package    mod_masteryagent
 * @copyright  2026 MCU-NPS AI Learning Initiatives
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson {

    /** @var array The decoded question record. */
    protected array $record;

    /**
     * Constructor.
     *
     * @param array $record A decoded question record.
     */
    public function __construct(array $record) {
        $this->record = $record;
    }

    /**
     * Build from the JSON stored on an activity instance.
     *
     * @param string $json The stored lesson JSON.
     * @return self
     * @throws \moodle_exception If the stored JSON cannot be decoded.
     */
    public static function from_json(string $json): self {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('errorbadjson', 'mod_masteryagent');
        }
        return new self($decoded);
    }

    /**
     * Extract the question records from a whole uploaded question-set file.
     *
     * Accepts either the full course file (an object with a "questions" array)
     * or a bare array of question records.
     *
     * @param string $json Raw file contents.
     * @return array List of question records, keyed by question id.
     */
    public static function parse_question_set(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $questions = $decoded['questions'] ?? $decoded;
        if (!is_array($questions)) {
            return [];
        }
        $result = [];
        foreach ($questions as $question) {
            if (!is_array($question) || empty($question['question_text'])) {
                continue;
            }
            $key = $question['question_id'] ?? ($question['lesson_id'] ?? (string) count($result));
            $result[$key] = $question;
        }
        return $result;
    }

    /**
     * Source-level metadata from a whole uploaded question-set file.
     *
     * @param string $json Raw file contents.
     * @return array
     */
    public static function parse_source_meta(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return [
            'course' => $decoded['course'] ?? '',
            'schema_version' => $decoded['schema_version'] ?? '',
            'rubric_validation_status' => $decoded['rubric_validation_status'] ?? '',
        ];
    }

    /**
     * Get a raw field from the record.
     *
     * @param string $key Field name.
     * @param mixed $default Value to return when absent.
     * @return mixed
     */
    public function get(string $key, $default = '') {
        return $this->record[$key] ?? $default;
    }

    /**
     * The opening question put to the learner.
     *
     * @return string
     */
    public function question_text(): string {
        return (string) $this->get('question_text');
    }

    /**
     * Lesson identifier, e.g. L01.
     *
     * @return string
     */
    public function lesson_id(): string {
        return (string) $this->get('lesson_id');
    }

    /**
     * Question identifier, e.g. L01-Q01.
     *
     * @return string
     */
    public function question_id(): string {
        return (string) $this->get('question_id');
    }

    /**
     * Human readable lesson title.
     *
     * @return string
     */
    public function title(): string {
        return (string) $this->get('lesson_title');
    }

    /**
     * Follow-up probes the agent may use when evidence is missing.
     *
     * @return array
     */
    public function probes(): array {
        $probes = $this->get('follow_up_probes', []);
        return is_array($probes) ? $probes : [];
    }

    /**
     * One list from the evaluator evidence guide.
     *
     * @param string $key One of strong_evidence, partial_evidence,
     *                    misconceptions_or_red_flags, insufficient_evidence_conditions.
     * @return array
     */
    public function evidence(string $key): array {
        $guide = $this->get('evaluator_evidence_guide', []);
        if (!is_array($guide) || !isset($guide[$key]) || !is_array($guide[$key])) {
            return [];
        }
        return $guide[$key];
    }

    /**
     * Mastery dimensions this question targets.
     *
     * @return array
     */
    public function dimensions(): array {
        $dimensions = $this->get('target_mastery_dimensions', []);
        return is_array($dimensions) ? $dimensions : [];
    }

    /**
     * Public display names for the dimensions assessed by this lesson.
     *
     * Names come from the question set, never from model-generated feedback.
     *
     * @return array Dimension id to display name.
     */
    public function dimension_names(): array {
        $names = $this->get('dimension_names', []);
        if (!is_array($names)) {
            return [];
        }
        $out = [];
        foreach ($this->dimensions() as $id) {
            if (is_string($id) && isset($names[$id]) && is_string($names[$id]) && trim($names[$id]) !== '') {
                $out[$id] = trim($names[$id]);
            }
        }
        return $out;
    }

    /**
     * Public reading references, excluding evaluator notes and evidence criteria.
     *
     * @return array Reading titles, editions, page references and optional URLs.
     */
    public function learning_resources(): array {
        $out = [];
        foreach ($this->sources() as $source) {
            if (!is_array($source) || !is_string($source['title'] ?? null) || trim($source['title']) === '') {
                continue;
            }
            $reading = [];
            foreach (['title', 'edition_or_date', 'coursebook_page_or_section', 'url'] as $key) {
                $reading[$key] = is_string($source[$key] ?? null) ? trim($source[$key]) : '';
            }
            $out[] = $reading;
        }
        return $out;
    }

    /**
     * Educational objectives this question targets.
     *
     * @return array
     */
    public function objectives(): array {
        $objectives = $this->get('target_educational_objectives', []);
        return is_array($objectives) ? $objectives : [];
    }

    /**
     * Validation notes recorded by whoever generated the question set.
     *
     * @return array
     */
    public function validation_notes(): array {
        $notes = $this->get('validation_notes', []);
        return is_array($notes) ? $notes : [];
    }

    /**
     * Source citations for the lesson.
     *
     * @return array
     */
    public function sources(): array {
        $sources = $this->get('source_evidence', []);
        return is_array($sources) ? $sources : [];
    }

    /**
     * Render a numbered list for inclusion in a prompt.
     *
     * @param string $prefix Short code prefix, e.g. SE for strong evidence.
     * @param array $items Items to number.
     * @return string
     */
    public static function numbered(string $prefix, array $items): string {
        if (empty($items)) {
            return "(none supplied)";
        }
        $lines = [];
        $index = 1;
        foreach ($items as $item) {
            $lines[] = $prefix . $index . '. ' . (string) $item;
            $index++;
        }
        return implode("\n", $lines);
    }
}
