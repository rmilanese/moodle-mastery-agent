# Student Experience TODO

This backlog records the repository review recommendations and proposed batch updates. It is intended for a near-term hackathon demo serving mid-grade Marine Corps officers, all with undergraduate degrees and some with master's degrees.

Prioritize trustworthy grading, clear assessment scope, and feedback that leads to a useful next action. Effort estimates are relative. All implementation items below are pending.

## Batch updates

| Batch | Recommendations | Shared work and outcome |
| --- | --- | --- |
| **1. Grading accuracy and useful feedback** | **#1** Validate AI responses; **#2** Define assessed skills; **#3** Test real officer-level answers | Update evaluator prompts and response validation together, then verify them against SME-reviewed answers. Students receive more consistent, defensible judgments. |
| **2. Assessment roadmap and learning plan** | **#5** Show scope and lesson progress; **#6** Prioritize study actions | Reuse public lesson metadata and saved results to connect assessment expectations with the next study action. This provides the most visible demo improvement. |
| **3. Answer protection and reliable completion** | **#4** Autosave drafts; **#7** Reduce final-submission dependencies; **#8** Keep assessment rules stable | Improve persistence and submission handling together. Verify interrupted sessions, competing requests, provider failures, and instructor edits. |
| **4. AI transparency and instructor review** | **#9** Request instructor review; **#10** Explain AI use and handle policy acceptance | Connect the initial explanation of AI assessment with an actionable review process. Carry review status through results, history, and exports. |

### Suggested implementation order

1. Complete **Batch 1**, then **Batch 2**, to combine assessment credibility with improvements students and judges can immediately see.
2. In **Batch 3**, implement the summary fallback (#7) and configuration guard (#8) before autosave (#4). Autosave requires careful coordination with submission and multiple browser tabs.
3. Complete **Batch 4** to connect AI transparency with instructor oversight.

Each batch can be one release, with smaller commits for individual changes. Use the live-provider evaluation from #3 as a release check whenever grading behavior changes. Its expected outcomes need subject-matter-expert (SME) review.

## Recommendations

### 1. Validate AI responses before they affect a grade

**Batch:** 1 | **Priority:** Highest | **Effort:** Small–medium

The response parser treats a JSON string `"false"` as a true closing decision and converts a nonnumeric score such as `"unknown"` into zero. Missing feedback and unknown evidence codes are also accepted. These paths can end a lesson prematurely or produce an unfair result.

- [ ] Require correctly typed fields, recognized evidence codes, numeric scores, and complete skill judgments.
- [ ] Preserve previously demonstrated evidence and reject inconsistent assessment output.
- [ ] Return a recoverable error for invalid output while retaining the student's answer.
- [ ] Add regression cases for malformed values, missing fields, unknown codes, and lost cumulative evidence.

**Code:** [Evaluator response parsing](masteryagent/classes/agent.php), [attempt state updates](masteryagent/classes/attempt.php).

### 2. Give the evaluator the meaning of each skill it grades

**Batch:** 1 | **Priority:** Highest | **Effort:** Small

The final-assessment prompt supplies identifiers such as `L01-MD01` without their existing skill names or evidence mappings. The interface later attaches readable names to those judgments, although the evaluator was never explicitly given their meanings.

- [ ] Include trusted skill names and map evidence to the relevant skills in the evaluator prompt.
- [ ] Require exactly one judgment for every requested skill.
- [ ] Where useful, include a short excerpt from the student's answer explaining the judgment; verify that any quoted excerpt exists in the saved student response.

**Code:** [Assessment prompt](masteryagent/classes/agent.php), [public skill metadata](masteryagent/classes/lesson.php), [feedback rendering](masteryagent/classes/output/conversation_view.php).

### 3. Test grading quality with real officer-level answers

**Batch:** 1 | **Priority:** Highest | **Effort:** Small–medium

The backend tests use scripted AI responses. They verify software behavior but do not establish whether the configured model fairly assesses professional reasoning. Existing prompts already discourage rewarding answer length, confidence, or vocabulary; live evaluation should verify that behavior.

- [ ] Build a small SME-reviewed evaluation set using [Eric's existing answers](mastery-questions-answers-from-eric.txt).
- [ ] Include complete answers, concise equivalents, partial answers, explicitly rejected misconceptions, and misconceptions corrected during discussion.
- [ ] Record expected demonstrated evidence, acceptable mastery judgments, and appropriate follow-up behavior.
- [ ] Run the cases against the configured provider before the demo and after grading changes. Check that complete reasoning earns credit and follow-ups address actual gaps.

**Code and documentation:** [Test instructions](masteryagent/tests/README.md), [scripted AI responder](masteryagent/tests/helper_trait.php), [evaluator tests](masteryagent/tests/agent_test.php).

### 4. Autosave drafts while students write

**Batch:** 3 | **Priority:** High | **Effort:** Medium

Navigation warnings protect unsaved answers, but persistence requires **Save and leave**. Browser crashes and mobile-app termination can still lose a substantial response.

- [ ] Save drafts after a brief typing pause and show saving, saved, and failure states.
- [ ] Keep draft saving separate from graded submission; reuse existing draft storage and stale-request protections.
- [ ] Prevent delayed saves from overwriting newer text or restoring an already-submitted answer.
- [ ] Verify reload restoration, deliberate deletion, multiple tabs, failed saves, and overlapping save/submission requests.

**Code:** [Conversation editor](masteryagent/amd/src/conversation.js), [draft persistence](masteryagent/classes/attempt.php), [conversation actions](masteryagent/classes/conversation.php).

### 5. Show a lesson roadmap and state the assessment's scope

**Batch:** 2 | **Priority:** High | **Effort:** Small–medium

The opening overview explains counts, reply limits, and scoring but does not list upcoming lessons or public skill expectations. At review time, the shipped question-set metadata targeted **39 of 113 objectives** and **23 of 54 mastery dimensions**. These are selected objectives, not a comprehensive assessment of the course.

- [ ] Show lesson titles, assessed public skills, and current/completed/upcoming states.
- [ ] Explain the selected scope so students can interpret mastery results accurately.
- [ ] Derive the roadmap from activity content rather than hard-coding this course's counts.
- [ ] Keep private evidence guides and evaluator probes out of the student interface.

**Code and data:** [Assessment overview](masteryagent/classes/output/conversation_view.php), [lesson metadata](masteryagent/classes/lesson.php), [shipped question set](<agent2 - question generator agent/masteryagent_question_set_AY27_8670.json>).

### 6. Lead results with a concise after-action study plan

**Batch:** 2 | **Priority:** High | **Effort:** Small–medium

Results already contain strengths, gaps, next steps, readings, and skill cards. Across 13 lessons, students must scan considerable detail to decide where to start. Six lessons in the reviewed question set provide reading references without a clickable reading URL.

- [ ] Add an overview of lesson scores, unassessed lessons, and a few priority study actions linked to detailed feedback.
- [ ] Build the overview from saved results without another AI request.
- [ ] Add instructor-configured Moodle resource links and page references for embedded readings.
- [ ] Preserve historical results; do not infer old pass/fail outcomes from the activity's current mastery threshold.

**Code:** [Feedback rendering](masteryagent/classes/output/conversation_view.php), [saved lesson results](masteryagent/classes/attempt.php), [learning-plan export](masteryagent/learningplan.php).

### 7. Make final submission less dependent on multiple AI calls

**Batch:** 3 | **Priority:** High | **Effort:** Small–medium

A final answer can trigger conversational evaluation, lesson scoring, and a course summary in sequence. A course-summary failure currently rolls back submission even when earlier evaluation calls succeeded. Provider latency was not measured during the review.

- [ ] Provide a deterministic course-summary fallback using validated lesson results.
- [ ] Ensure an optional narrative-summary failure does not force the student to resubmit a successfully evaluated final answer.
- [ ] Preserve atomic saving of the validated assessment and grade, and verify summary-failure recovery.
- [ ] Measure completion latency with the configured provider to assess the improvement.

**Code:** [Submission and finalization](masteryagent/classes/attempt.php), [transaction handling](masteryagent/classes/conversation.php), [failure-recovery tests](masteryagent/tests/external_test.php).

### 8. Keep questions and grading rules stable throughout an attempt

**Batch:** 3 | **Priority:** High | **Effort:** Small for a guard; medium for versioning

Each request reloads current activity configuration, while attempts do not snapshot their starting rubric and settings. An instructor replacement can cause an answer to an old scenario to be evaluated against new material.

- [ ] For the demo, prevent changes to assessment content, lesson sequence, and grading settings once attempts exist; direct instructors to create a new activity.
- [ ] Verify that edits cannot change the interpretation of active or completed attempts.
- [ ] Longer term, version assessment content and snapshot the rubric, sequence, grading settings, and mastery threshold per attempt.

**Code:** [Activity updates](masteryagent/lib.php), [configuration loading](masteryagent/classes/conversation.php), [attempt creation and scoring](masteryagent/classes/attempt.php).

### 9. Make instructor review available from student results

**Batch:** 4 | **Priority:** Medium–high | **Effort:** Medium

AI-provisional status is an activity setting and notice rather than an individual review workflow. The notice does not consistently accompany historical feedback and exported learning plans. Students need a constructive way to resolve a misunderstood argument.

- [ ] Add **Request instructor review**, a short explanation field, and visible pending/reviewed status.
- [ ] Provide instructor controls to acknowledge the request and record a response.
- [ ] Retain the original AI assessment alongside the instructor's response or reviewed decision.
- [ ] Carry saved review status into current results, history, and learning-plan exports.

**Code:** [Student results](masteryagent/classes/output/conversation_view.php), [history](masteryagent/classes/output/history_view.php), [instructor report](masteryagent/report.php), [database schema](masteryagent/db/install.xml).

### 10. Explain AI use at first use and integrate Moodle policy acceptance

**Batch:** 4 | **Priority:** Medium–high | **Effort:** Small–medium

The plugin calls the AI manager directly but has no policy-status or acceptance handling. Moodle assigns those checks to the calling interface; `process_action()` does not automatically supply the acceptance workflow.

- [ ] Briefly explain that AI evaluates submitted answers, who can review them, and how provisional results are handled.
- [ ] Check policy status and provide Moodle's existing acceptance flow before the first AI interaction.
- [ ] Avoid repeated prompts for students who have already accepted the policy.
- [ ] Verify both first-use and previously accepted flows, including server-side enforcement.

**Code and reference:** [AI invocation](masteryagent/classes/agent.php), [activity entry point](masteryagent/view.php), [Moodle AI user policy documentation](https://moodledev.io/docs/5.1/apis/subsystems/ai#ai-user-policy).

## Review baseline — 2026-09-18

The review covered the Moodle plugin, student interface, assessment logic, authoring-agent instructions, rubric/question data, tests, sample answers, and installation ZIP.

- **88/88 browser checks passed:** 82 conversation checks and 6 learning-plan checks. These use mocked responses and do not validate a live Moodle/provider integration.
- The shipped 13-lesson question set and question-set template passed their validator without errors or warnings.
- The installation ZIP matched the plugin files.
- The 175 PHP tests require a Moodle environment and were not run during this review.
- Live AI grading and doctrinal correctness were not validated.

Preserve existing student features while implementing this backlog: clarification without a grading penalty, save-and-resume, accessible conversation navigation, attempt history, printable learning plans, and protection against duplicate submissions.
