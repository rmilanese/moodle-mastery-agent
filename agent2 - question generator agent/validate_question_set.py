#!/usr/bin/env python3
"""
Validate a question-set JSON file against what mod_masteryagent actually reads.

Errors are things that will break the plugin or silently lose a lesson.
Warnings are things that will load but assess poorly.

Usage:
    python3 validate_question_set.py question_set.json [...]

Exit status is 1 if any file has errors, otherwise 0.
"""

import json
import re
import sys
from pathlib import Path

MAX_ID_LEN = 64            # the plugin stores these in char(64) columns
MIN_STRONG_EVIDENCE = 2    # fewer than this and the conversation ends at once
MAX_STRONG_EVIDENCE = 6    # more than this cannot be covered in a reply budget
MIN_QUESTION_CHARS = 60
MAX_QUESTION_CHARS = 1200
ID_PATTERN = re.compile(r"^[A-Za-z0-9._-]+$")

EVIDENCE_KEYS = [
    "strong_evidence",
    "partial_evidence",
    "misconceptions_or_red_flags",
    "insufficient_evidence_conditions",
]


class Report:
    """Collected findings for one file."""

    def __init__(self, path: Path):
        self.path = path
        self.errors: list[str] = []
        self.warnings: list[str] = []
        self.records = 0

    def error(self, where: str, message: str) -> None:
        self.errors.append(f"{where}: {message}")

    def warn(self, where: str, message: str) -> None:
        self.warnings.append(f"{where}: {message}")

    def print(self) -> None:
        print(f"\n{self.path} — {self.records} lesson record(s)")
        if not self.errors and not self.warnings:
            print("  OK — no errors, no warnings")
            return
        for item in self.errors:
            print(f"  ERROR    {item}")
        for item in self.warnings:
            print(f"  warning  {item}")
        print(f"  {len(self.errors)} error(s), {len(self.warnings)} warning(s)")


def string_list(value) -> list[str]:
    """Return the value as a list of strings, or None when it is not a list of strings."""
    if not isinstance(value, list):
        return None
    if any(not isinstance(item, str) for item in value):
        return None
    return value


def check_id(report: Report, where: str, field: str, value, seen: dict) -> None:
    """Validate one identifier field and track uniqueness."""
    if not isinstance(value, str) or not value.strip():
        report.error(where, f"{field} is missing or empty")
        return
    value = value.strip()
    if len(value) > MAX_ID_LEN:
        report.error(where, f"{field} is longer than {MAX_ID_LEN} characters")
    if not ID_PATTERN.match(value):
        report.warn(
            where,
            f"{field} '{value}' contains characters outside A-Z a-z 0-9 . _ - "
            "which makes it awkward to type into the activity settings",
        )
    if value in seen.setdefault(field, set()):
        report.error(where, f"duplicate {field} '{value}' — records will overwrite each other")
    seen[field].add(value)


def check_evidence_guide(report: Report, where: str, record: dict) -> None:
    """Validate the four evidence lists."""
    guide = record.get("evaluator_evidence_guide")
    if not isinstance(guide, dict):
        report.error(where, "evaluator_evidence_guide is missing or is not an object")
        return

    for key in EVIDENCE_KEYS:
        items = string_list(guide.get(key))
        if items is None:
            if key in guide:
                report.error(where, f"evaluator_evidence_guide.{key} must be an array of strings")
            else:
                report.error(where, f"evaluator_evidence_guide.{key} is missing")
            continue

        blank = [i for i, item in enumerate(items) if not item.strip()]
        if blank:
            report.error(where, f"{key} has {len(blank)} empty item(s)")

        lowered = [item.strip().lower() for item in items if item.strip()]
        if len(lowered) != len(set(lowered)):
            report.warn(where, f"{key} contains duplicate items")

        for item in items:
            stripped = item.strip()
            if 0 < len(stripped) < 20:
                report.warn(where, f"{key} item is very short and probably not checkable: '{stripped}'")

        if key == "strong_evidence":
            if len(items) < MIN_STRONG_EVIDENCE:
                report.error(
                    where,
                    f"strong_evidence has {len(items)} item(s); at least {MIN_STRONG_EVIDENCE} are "
                    "needed or the conversation closes on the first reply",
                )
            elif len(items) > MAX_STRONG_EVIDENCE:
                report.warn(
                    where,
                    f"strong_evidence has {len(items)} items; more than {MAX_STRONG_EVIDENCE} is hard "
                    "to cover inside a reply budget",
                )
        elif not items:
            report.warn(where, f"{key} is empty")


LEAK_RATIO = 0.7        # share of an evidence item's content words found in the question
LEAK_MIN_WORDS = 5      # ignore items too short to judge


def content_words(text: str) -> set:
    """Distinctive words of a sentence, ignoring short and structural ones."""
    words = re.findall(r"[a-z]+", text.lower())
    return {word for word in words if len(word) > 4}


def check_answer_leak(report: Report, where: str, record: dict) -> None:
    """Warn when the question text gives away its own rubric.

    Compares content words rather than exact substrings, so a lightly reworded
    restatement is caught too.
    """
    question = record.get("question_text")
    guide = record.get("evaluator_evidence_guide")
    if not isinstance(question, str) or not isinstance(guide, dict):
        return

    asked = content_words(question)
    if not asked:
        return

    for item in guide.get("strong_evidence", []) or []:
        if not isinstance(item, str):
            continue
        expected = content_words(item)
        if len(expected) < LEAK_MIN_WORDS:
            continue
        overlap = len(expected & asked) / len(expected)
        if overlap >= LEAK_RATIO:
            report.warn(
                where,
                "question_text restates a strong_evidence item "
                f"({round(overlap * 100)}% of its wording); the question would be giving away "
                "what it is meant to test",
            )
            return


def check_sources(report: Report, where: str, record: dict) -> None:
    """Validate the optional source list."""
    sources = record.get("source_evidence")
    if sources is None:
        report.warn(where, "no source_evidence — instructors cannot trace a judgement to the reading")
        return
    if not isinstance(sources, list):
        report.error(where, "source_evidence must be an array")
        return
    for index, source in enumerate(sources):
        if not isinstance(source, dict):
            report.error(where, f"source_evidence[{index}] is not an object")
            continue
        if not str(source.get("title", "")).strip():
            report.warn(where, f"source_evidence[{index}] has no title")
        url = str(source.get("url", "")).strip()
        if url and not url.startswith(("http://", "https://")):
            report.warn(where, f"source_evidence[{index}] url is not an http(s) link: '{url}'")


def check_record(report: Report, index: int, record, seen: dict) -> None:
    """Validate one lesson record."""
    where = f"record {index}"
    if not isinstance(record, dict):
        report.error(where, "is not an object")
        return

    label = record.get("question_id") or record.get("lesson_id")
    if isinstance(label, str) and label.strip():
        where = f"{label.strip()} (record {index})"

    question = record.get("question_text")
    if not isinstance(question, str) or not question.strip():
        report.error(where, "question_text is missing or empty — the plugin will skip this record entirely")
    else:
        length = len(question.strip())
        if length < MIN_QUESTION_CHARS:
            report.warn(where, f"question_text is only {length} characters; too thin to assess")
        elif length > MAX_QUESTION_CHARS:
            report.warn(where, f"question_text is {length} characters; consider tightening it")

    check_id(report, where, "question_id", record.get("question_id"), seen)
    check_id(report, where, "lesson_id", record.get("lesson_id"), seen)

    if not str(record.get("lesson_title", "")).strip():
        report.error(where, "lesson_title is missing or empty")

    dimensions = string_list(record.get("target_mastery_dimensions"))
    if dimensions is None:
        report.error(where, "target_mastery_dimensions is missing or is not an array of strings")
    elif not dimensions:
        report.error(where, "target_mastery_dimensions is empty — there will be no per-dimension feedback")

    names = record.get("dimension_names")
    if names is not None:
        if not isinstance(names, dict):
            report.error(where, "dimension_names must be an object mapping dimension IDs to display names")
        else:
            for dimension_id, name in names.items():
                if not isinstance(name, str) or not name.strip():
                    report.error(where, f"dimension_names.{dimension_id} must be a nonempty string")
                if dimensions is not None and dimension_id not in dimensions:
                    report.warn(where, f"dimension_names.{dimension_id} is not a targeted dimension")
            for dimension_id in dimensions or []:
                if dimension_id not in names:
                    report.warn(where, f"dimension_names has no display name for {dimension_id}")

    probes = string_list(record.get("follow_up_probes"))
    if probes is None:
        if "follow_up_probes" in record:
            report.error(where, "follow_up_probes must be an array of strings")
        else:
            report.warn(where, "no follow_up_probes — the evaluator has no ready-made probe to fall back on")
    elif len(probes) < 2:
        report.warn(where, f"only {len(probes)} follow-up probe(s); two gives the evaluator a choice")

    notes = record.get("validation_notes")
    if notes is None:
        report.warn(where, "no validation_notes — instructors see no caveats when validating")
    elif string_list(notes) is None:
        report.error(where, "validation_notes must be an array of strings")

    check_evidence_guide(report, where, record)
    check_answer_leak(report, where, record)
    check_sources(report, where, record)


def validate(path: Path) -> Report:
    """Validate one file."""
    report = Report(path)

    try:
        raw = path.read_text(encoding="utf-8")
    except OSError as exc:
        report.error("file", f"cannot be read: {exc}")
        return report

    try:
        data = json.loads(raw)
    except json.JSONDecodeError as exc:
        report.error("file", f"is not valid JSON: {exc}")
        return report

    if isinstance(data, dict):
        questions = data.get("questions")
        if questions is None:
            report.error("file", "has no 'questions' array")
            return report
        for field in ("course", "schema_version", "rubric_validation_status"):
            if not str(data.get(field, "")).strip():
                report.warn("file", f"no top-level '{field}'")
    elif isinstance(data, list):
        questions = data
        report.warn("file", "is a bare array; the object form with course and schema_version is preferred")
    else:
        report.error("file", "must be an object with a 'questions' array")
        return report

    if not isinstance(questions, list):
        report.error("file", "'questions' is not an array")
        return report
    if not questions:
        report.error("file", "'questions' is empty")
        return report

    report.records = len(questions)
    seen: dict = {}
    for index, record in enumerate(questions):
        check_record(report, index, record, seen)

    return report


def main() -> None:
    paths = [Path(arg) for arg in sys.argv[1:]]
    if not paths:
        print(__doc__.strip())
        sys.exit(2)

    failed = False
    for path in paths:
        report = validate(path)
        report.print()
        failed = failed or bool(report.errors)

    print()
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
