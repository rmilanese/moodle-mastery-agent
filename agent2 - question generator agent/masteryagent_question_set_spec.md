# Question set format for mod_masteryagent

**Give this document to the agent that generates the question sets.** Any file
that follows it will load into the Moodle mastery agent plugin without edits.

The plugin reads one JSON file. Each record in it is one lesson's assessment
conversation: the question put to the Marine, and the evidence rubric the
evaluator judges their answers against. Nothing else is required, and the
plugin ignores fields it does not recognise — so extra fields are safe to carry.

---

## 1. File shape

```json
{
  "course": "AY27 8670 Prerequisite",
  "schema_version": "1.0",
  "rubric_validation_status": "DRAFT_PENDING_HUMAN_VALIDATION",
  "questions": [ { ...lesson record... }, { ...lesson record... } ]
}
```

A bare array of lesson records also loads, but the wrapper is preferred: the
three top-level fields are shown to the teacher when they load the file, and
`rubric_validation_status` is what tells an instructor the rubric has not yet
been validated by a subject matter expert.

One record per lesson. The teacher chooses which records an activity uses by
listing their IDs, so **every record must be independently usable**.

---

## 2. Lesson record

### Required — the file fails or degrades badly without these

| Field | Type | Used for |
| --- | --- | --- |
| `question_text` | string | The opening question. Sent to the model and shown to the Marine verbatim. |
| `question_id` | string | Unique key. Selects the lesson, tags every message in the transcript. Max 64 chars. |
| `lesson_id` | string | Short label shown in progress ("Lesson 2 of 13"), and an alternative selector. Max 64 chars. |
| `lesson_title` | string | Display name in progress, reports and results. |
| `evaluator_evidence_guide` | object | The rubric. Four lists, below. |
| `target_mastery_dimensions` | array of strings | The dimensions the final assessment reports on, one judgement each. |

### Strongly recommended

| Field | Type | Used for |
| --- | --- | --- |
| `follow_up_probes` | array of strings | Offered to the evaluator as ready-made probing questions when evidence is thin. Two is a good number. |
| `source_evidence` | array of objects | Shown to instructors in the report so they can check a judgement against the reading. Each: `source_id`, `title`, `edition_or_date`, `coursebook_page_or_section`, `url`, `verification_status`. |
| `validation_notes` | array of strings | Shown to instructors in the report. This is where open issues and "pending SME validation" caveats belong. |

### Public learning-plan metadata

Add `dimension_names` to each question: an object mapping every targeted
dimension ID to its name in the source rubric, for example
`{"L01-MD01": "Learning, Education, and Training for Warfighting"}`.
Keep `target_mastery_dimensions` as an array of IDs. Names are public labels;
do not include evidence criteria, expected answers, probes or reviewer notes.

The completed student results display these names and public fields from
`source_evidence`: `title`, `edition_or_date`,
`coursebook_page_or_section` and `url`. Supply accurate page/section references
and a direct HTTP(S) URL when available; leave the URL empty for printed or
embedded readings. Do not invent a link or a gap-to-passage association.

Both fields are saved with the scored lesson. Older files remain supported:
missing names use numbered skill labels, and missing readings show a clear
empty state. Evaluator notes and evidence guides stay private.

### Carried but not currently displayed

`target_educational_objectives`, `lesson_number`, `estimated_response_minutes`,
`selection_rationale`. Keep producing them — they are useful to humans reading
the file, and they cost nothing.

### `evaluator_evidence_guide`

```json
"evaluator_evidence_guide": {
  "strong_evidence": ["...", "..."],
  "partial_evidence": ["...", "..."],
  "misconceptions_or_red_flags": ["...", "..."],
  "insufficient_evidence_conditions": ["...", "..."]
}
```

All four are arrays of plain strings. How the plugin uses them:

- **`strong_evidence`** is the ledger. The evaluator tracks which of these the
  Marine has demonstrated, probes the largest gap, and closes the lesson early
  once they are all covered. **This list is what drives the conversation** — get
  it right and everything else follows.
- **`partial_evidence`** describes answers that are heading the right way but
  underdeveloped. Used to distinguish a 2 from a 3.
- **`misconceptions_or_red_flags`** are penalised. A response containing one
  cannot score above 2, and the evaluator will challenge it directly.
- **`insufficient_evidence_conditions`** define a 0 — nonresponsive, fragmentary,
  or requiring a source the Marine was never given.

---

## 3. Writing rules

These are what separate a rubric that grades well from one that grades noise.

**One claim per item.** "Explains X, and also compares Y and Z" cannot be
partially satisfied, so the evaluator cannot tell what is missing. Split it.

**Write observable reasoning, not topics.** "Understands integrated deterrence"
is not checkable. "Explains integrated deterrence as tailored alignment across
domains, theatres and allies, not military punishment alone" is.

**Aim for 3 to 5 strong-evidence items.** Two makes the conversation end almost
immediately; more than six cannot be covered inside a six-reply budget.

**Never restate the rubric inside `question_text`.** The question is shown to the
Marine; the evidence lists are not. If the question contains the answer, the
assessment measures reading comprehension of the prompt.

**Make the question answerable from the assigned reading alone.** If a fair
judgement would need a source the course does not provide, say so in
`insufficient_evidence_conditions` and keep it out of `strong_evidence`.

**Write probes as single questions.** They are handed to the Marine verbatim when
they stall, so "What different contribution should education make that training
alone may not?" works; a three-part prompt does not.

**Keep `question_text` to one scenario plus one instruction.** Roughly 40 to 120
words. The current set is a good model: a short situation, then what to explain.

**IDs must be unique across the file.** Duplicate `question_id` values silently
overwrite each other. `L01`, `L02` … and `L01-Q01`, `L02-Q01` … is the
convention already in use; keep it.

**Dimension IDs should match whatever the rubric calls them** (`L01-MD01`) so an
instructor can trace a judgement back to the source rubric.

---

## 4. Minimal valid record

```json
{
  "lesson_id": "L01",
  "question_id": "L01-Q01",
  "lesson_title": "MCDP 7 – Learning",
  "question_text": "A unit treats learning as attendance at formal classes plus repetition of already familiar drills. Explain why that approach is incomplete for warfighting, and how individual Marines, instructors and leaders should combine education, training, experience and feedback to build adaptive competence.",
  "target_mastery_dimensions": ["L01-MD01", "L01-MD03"],
  "follow_up_probes": [
    "What different contribution should education make that training alone may not?",
    "What responsibility belongs specifically to leaders rather than to learners or instructors?"
  ],
  "evaluator_evidence_guide": {
    "strong_evidence": [
      "Explains learning as continuous development of knowledge, skills and attitudes for judgement under uncertainty.",
      "Distinguishes education as intellectual development from training as learning by doing, while showing both contribute to competence.",
      "Differentiates learner ownership, instructor facilitation, and leader responsibility to set conditions and develop subordinates.",
      "Connects feedback and experience to changed performance rather than course completion."
    ],
    "partial_evidence": [
      "Values both education and training but gives little explanation of how they interact.",
      "Names some role responsibilities but assigns most responsibility to one actor."
    ],
    "misconceptions_or_red_flags": [
      "Equates learning with memorisation, attendance or repetition alone.",
      "Treats education and training as interchangeable, or either as sufficient by itself.",
      "Assigns responsibility solely to instructors and omits leader example or learner ownership."
    ],
    "insufficient_evidence_conditions": [
      "The response is nonresponsive, fragmentary or too ambiguous to distinguish rubric levels.",
      "The learner gives labels or lists without enough explanation to demonstrate the targeted reasoning."
    ]
  },
  "validation_notes": [
    "Draft judgements pending human SME validation.",
    "Use only in an authorised evaluator context; do not expose the evidence guide as a student answer key."
  ]
}
```

---

## 5. Check before shipping

Run the validator that ships beside this document:

```bash
python3 validate_question_set.py my_question_set.json
```

It exits non-zero on anything that would break the plugin, and prints warnings
for things that will load but assess poorly — a missing probe list, a one-item
evidence list, a question that leaks its own answer. The generating agent can
run it on its own output and fix what it reports before handing the file over.

---

## 6. Updating an existing activity

Re-uploading a newer file onto an existing Moodle activity replaces the
questions and rubrics in place. Keep `question_id` values stable across
revisions so an activity keeps assessing the same lesson when the file is
refreshed. Changing an ID is equivalent to pointing the activity at a different
lesson.
