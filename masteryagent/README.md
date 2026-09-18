# Mastery agent (mod_masteryagent)

A Moodle activity that assesses a learner through a bounded conversation
instead of a multiple-choice quiz.

Built for the MCU-NPS AI Learning Initiatives use case *PME Course Material
Mastery Evaluation Agent* (EWSDEP 8670 prerequisite course).

## What it does

1. A teacher uploads the course question-set JSON and names which lessons this
   activity assesses — one, a few, or all of them.
2. The agent puts the first lesson's question to the learner.
3. Each reply is judged against that lesson's evidence rubric. The agent tracks
   which strong-evidence elements have been demonstrated and which
   misconceptions have appeared, then probes the largest remaining gap — one
   question at a time.
4. A lesson closes when its evidence is complete or its reply budget is spent.
   The agent says so and puts the next lesson's question straight away; the
   learner never has to navigate anywhere.
5. After the last lesson the agent reports a score and written feedback for
   every lesson, adds an overall judgement across the whole run, and pushes the
   total to the gradebook.

A learner can stop part way and come back: the attempt keeps its place, and
lessons never reached simply score nothing.

## Requirements

- Moodle 4.5 or later (5.1 recommended; developed and tested against 5.1.7).
- An AI provider configured in **Site administration → General → AI → AI
  providers**, with the **Generate text** action enabled.

All model access goes through `\core_ai\manager`, so the site's API key, rate
limits, AI user policy and `ai_action_register` logging apply to every request
this plugin makes. The plugin stores no credentials of its own.

## Installation

Upload the zip at **Site administration → Plugins → Install plugins**, or
extract into `mod/masteryagent` (`public/mod/masteryagent` when your Moodle installation uses a `public` web root).

## AJAX conversation (0.4.0)

Beginning an assessment, sending a reply, ending and scoring, and starting a
new attempt now update the conversation in place. There is no AJAX setting to
toggle. JavaScript-enabled browsers automatically use Moodle's `core/ajax`
service; normal POST forms remain available if JavaScript is unavailable.

The page shows a processing status, prevents concurrent submissions, moves
focus to the reply box or final result, and preserves draft text after errors.
Stale requests from another tab or after a lost response refresh the conversation
without applying the action again. Review the displayed conversation before
resubmitting a preserved draft.

The service validates the module context, enrolment/access and capabilities,
uses the signed-in user's latest attempt, and returns only the learner-facing
HTML. A per-user/activity lock serializes updates. Conversation actions use a
DB transaction so provider or scoring failures do not save a partial turn or
grade. External provider requests themselves cannot be rolled back.

### Upgrading from 0.3.2

1. Install the new ZIP through **Site administration → Plugins → Install plugins**,
   or replace the existing `mod/masteryagent` directory under your Moodle web root.
2. Visit **Site administration → Notifications** and complete the plugin upgrade.
   Version `2026091703` registers `mod_masteryagent_update_conversation` from
   `db/services.php`. Copying the files alone does not register this service.
3. Purge Moodle caches using **Site administration → Development → Purge caches**.
4. Open an activity as an enrolled learner and begin, reply, finish, and retry
   if allowed. The conversation and scores should update without a new page load.

The built JavaScript and source map are included in `amd/build`; no build tool
is required for installation. There is no need to enable general external web
services, create a token, or change the existing AI-provider settings. In a
Moodle development checkout, rebuild edited JavaScript with `npx grunt amd`
from the plugin directory.

Implementation references:
[Moodle AJAX](https://moodledev.io/docs/5.1/guides/javascript/ajax),
[external service definitions](https://moodledev.io/docs/5.1/apis/subsystems/external/writing-a-service),
[external API security](https://moodledev.io/docs/5.1/apis/subsystems/external/security),
and [the Lock API](https://moodledev.io/docs/5.1/apis/core/lock).

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Question set file | — | The course JSON. May contain every lesson. |
| Lessons to assess | — | Comma separated lesson or question IDs, in the order to run them. Blank means every lesson in the file. |
| Maximum replies per lesson | 6 | Hard ceiling per lesson. The agent may close one sooner. |
| Maximum score per lesson | 4 | Gradebook maximum is this times the number of lessons. |
| Mastery threshold per lesson | 3 | Score at or above which a lesson counts as mastered. |
| Allow repeat attempts | Yes | Gradebook keeps the highest total. |
| Mark grades as AI-provisional | Yes | Flags released grades as awaiting SME validation. |

## Choosing a structure

Both shapes work; pick per course.

**One activity per lesson** (`L01` in one activity, `L02` in the next) gives a
gradebook column per lesson, lets you release lessons as the course progresses,
and matches a lesson → reading → assessment rhythm.

**One activity for several lessons** (`L01,L02,L03`, or blank for all thirteen)
runs as a single sitting with automatic progression, one gradebook column
holding the total, and a course-level judgement at the end. This is the closer
fit for an end-of-course mastery interview.

## Updating the questions

Re-upload a newer question-set file on an existing activity and it picks up the
revised questions and rubrics. Nothing else changes and existing attempts are
left alone. This is the intended path for keeping the assessment current as
lessons and educational objectives are revised.

## Pausing and final submission (0.4.2)

**Save and leave** saves the learner's place and exact unsent reply, then
returns to the course. Opening the activity again restores that draft. Pausing
does not call the AI, use a reply, close a lesson, or submit a final grade.
Drafts are saved when this button is used; typing alone does not autosave them.

**Submit final assessment** opens a confirmation section showing completed
lessons and the number of lessons without a submitted answer. Those unanswered
lessons contribute zero points. **Yes, submit and score** finalizes the attempt;
it cannot be resumed. The learner must send or clear any unsent answer before
confirming, or choose Save and leave to keep working later. Normal automatic
completion after the last lesson continues to work as before.

All three buttons share the reply form, so drafts and the selected action also
reach the server without JavaScript. Saving and leaving intentionally uses a
normal POST and course redirect. Replies and confirmed final submission keep
their AJAX behavior. Server checks reject unconfirmed final submissions and
stale requests; old tabs cannot silently overwrite a newer saved draft.

### Upgrading to 0.4.2

Install the updated ZIP, then visit **Site administration → Notifications** to
complete version **2026091705**. This adds the nullable `draftreply` column to
the attempts table and updates the AJAX service's optional confirmation
parameter. Purge Moodle caches to load the new script, strings and styles.
Existing attempts remain usable; no question-set re-upload is needed for this
change. Reopen any activity tabs left open during the upgrade.

## Student learning plan (0.4.1)

Completed lessons now show **What you understand**, **What needs work** and
**What to do next**, followed by course reading references and feedback on
assessed skills. These sections use the feedback already generated when a
lesson closes; they do not make additional AI calls or change grading.

Question records can include an optional `dimension_names` object mapping
targeted dimension IDs to student-friendly names. The bundled course question
set and template now include names copied from the source rubric.
Re-upload the updated question set to use these names in newly scored lessons
on an existing activity.

Public names and reading references are saved with each lesson result.
Completed results retain those references when questions are later replaced.
Existing results still display their stored strengths, gaps and next step;
missing names use "Assessed skill 1", and missing readings are identified.
Historical results are not backfilled from newer course content.

Reading titles, editions, page/section references and HTTP(S) URLs come from
the uploaded question set, not from the AI. These are lesson references, not
an automatic mapping from each gap to an exact passage. References without
usable URLs appear as text. Evidence guides, probes and reviewer notes remain
outside the learner results.

Install the updated ZIP, complete Moodle's plugin upgrade, and purge caches
to load the new language strings and styles. No database schema change is
required.

## What the learner never sees

The evidence guide, misconception list and insufficient-evidence conditions are
sent to the model and shown to anyone with `mod/masteryagent:viewreports`. They
are never rendered to a learner, and the agent is instructed not to restate
them or answer the question on the learner's behalf.

## Instructor report

**View attempts** lists every attempt with its total, how many lessons closed,
and its status. Opening one shows the per-lesson score table, the full
transcript, the evidence ledger the agent maintained, and the rubric behind
every lesson — which is what a subject matter expert needs in order to validate
or overturn the agent's judgement.

## Tests

`tests/` holds 95 PHPUnit tests covering the parser, lesson selection, prompt
construction, the conversation engine, gradebook, and AJAX access and recovery.
The suite includes 15 AJAX regression tests from 0.4.0, 3 learning-plan tests
from 0.4.1, and 6 pause/final-submission tests from 0.4.2.
The PHP suite has not been executed in this workspace, which has no
Moodle/PHP runtime.
No AI provider is called by the tests. See `tests/README.md` for setup, browser
tests, and the suite command.

## Known limitations

- **No backup/restore support.** `FEATURE_BACKUP_MOODLE2` is declared false, so
  these activities are skipped by course backup rather than silently corrupting
  one. This is the first thing to add for production use.
- **No Moodle Mobile app integration.** The browser uses an authenticated AJAX
  service, but the plugin does not provide a Moodle Mobile interface.
- **Synchronous model calls on the server.** AJAX keeps the browser page in
  place while the provider works; it does not stream tokens or queue background
  jobs. Failed requests are not automatically replayed. Learners may retry
  manually, with the displayed revision checked to prevent duplicate turns.
- **Rubrics are agent-generated drafts.** The supplied question set is marked
  `DRAFT_PENDING_HUMAN_VALIDATION`. Keep the AI-provisional flag on until an SME
  has validated the rubric.

## Licence

GPL v3 or later, matching Moodle.
