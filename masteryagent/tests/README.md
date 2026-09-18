# Running the mastery agent tests

155 PHPUnit tests covering the question-set parser, lesson selection, prompt
construction, the conversation engine, gradebook, AJAX endpoint and learner history. They never call a real
AI provider — a scripted responder stands in for one — so they cost nothing to
run and are deterministic.

## One-time setup on a dev Moodle

From the Moodle root (not the plugin directory):

```bash
composer install
# add to config.php, above require_once(__DIR__ . '/lib/setup.php'):
#   $CFG->phpunit_prefix = 'phpu_';
#   $CFG->phpunit_dataroot = '/path/to/phpunitdata';
php admin/tool/phpunit/cli/init.php
```

If your installation uses a `public` web root, use
`php public/admin/tool/phpunit/cli/init.php` if that path is what exists.

Re-run `init.php` after installing or upgrading any plugin — it rebuilds the
test database and the suite list.

## Running them

```bash
# just this plugin
vendor/bin/phpunit --testsuite mod_masteryagent_testsuite

# one file
vendor/bin/phpunit public/mod/masteryagent/tests/attempt_test.php

# one test
vendor/bin/phpunit --filter test_a_sequence_advances_on_its_own
```

The original suite contained 71 tests. The AJAX update added 15 tests in
`external_test.php`. The learning-plan update adds 3 tests in
`learning_plan_test.php`. Pausing and final submission add 6 more tests in
`external_test.php`. Conversation navigation adds 4 tests in
`conversation_view_test.php`, and the student overview/reply-context update adds 6 more in that file,
with 4 further tests for the welcome summary and editor markup in 0.4.5.
Version 0.4.6 adds 8 history-access tests, 5 history-list tests and 7 saved-review
tests. Version 0.4.7 adds 5 nearby request-feedback tests. Version 0.4.8 adds
14 clarification service/prompt tests and 6 clarification-view tests. Version 0.4.9
adds one shared skill-card rendering test, for 155 tests in total.
The PHP suite has not been
executed in this workspace; run it on a development Moodle site.

## What each file covers

| File | Covers |
| --- | --- |
| `lesson_test.php` | Reading a question set: wrapper and bare-array forms, missing fields, source metadata |
| `sequence_test.php` | Lesson selection by id, ordering, unknown ids, legacy single-lesson instances |
| `agent_test.php` | Prompt contents, scoring bands across scale widths, corrected-misconception handling, JSON parsing (fenced, prose-wrapped, malformed), score clamping, course summary |
| `learning_plan_test.php` | Saved public feedback, safe reading links, legacy fallbacks and semantic skill cards shared by finished and historical attempts |
| `attempt_test.php` | Turns, budget exhaustion, automatic lesson advance, totals, early finish, provider failure recovery |
| `external_test.php` | Authenticated AJAX access, ownership and hidden activities, duplicate/stale requests, progression, grades, retry settings, input limits, escaped output and atomic provider/scoring failures |
| `conversation_view_test.php` | Lesson grouping, message order, navigation targets, finished history, legacy keys, escaped saved titles, overview settings, saved reply context, welcome summaries and editor guidance |
| `history_access_test.php` | Learner/activity isolation, older-attempt retrieval, bounded pagination, metadata-only queries, unchanged attempts/messages/grades |
| `history_view_test.php` | Draft-preserving new-tab entry, paged cards, accessible dates, score empty states and excluded private text |
| `history_review_test.php` | Saved feedback and transcripts independent of current settings, read-only controls, private-data exclusion, legacy/unfinished/empty attempts, safe markup and native navigation |
| `request_feedback_test.php` | Nearby feedback slots, escaped errors and associated recovery help, POST draft retention, start/completion states and exclusion from historical reviews |
| `clarification_test.php` | Clarification prompts and cache, exclusion from grading, unchanged attempt state, stale/invalid requests and provider failure recovery |
| `clarification_view_test.php` | Clarification controls, retained drafts, original prompts, accessible help markup and distinct transcript/report labels |
| `lib_test.php` | Settings form save paths, file upload and re-upload, gradebook item and grade writing, deletion cleanup |

## After you change something

Run the suite. If a test fails, read the failure message before the code — the
assertions carry messages explaining what behaviour is being protected, e.g.
"a weaker retake does not lower the grade".

If you change behaviour deliberately, update the test in the same commit. A test
that no longer matches the intended behaviour should be changed, not deleted.

## Adding a test

Activities come from the generator, so a new test starts in two lines:

```php
$course = $this->getDataGenerator()->create_course();
$instance = $this->getDataGenerator()->create_module('masteryagent', [
    'course' => $course->id,
    'lessonkeys' => 'S01,S02',   // from tests/fixtures/sample_question_set.json
]);
```

Then script the AI with `$this->stub_ai([...])` from `helper_trait.php`:

```php
$this->stub_ai([
    'closeafter' => 2,                  // close each lesson after 2 replies
    'scores' => ['S01' => 4, 'S02' => 2],
    'reply' => 'What would you check yourself?',
]);
```

Always call `$this->reset_ai()` in `tearDown()` so the stub does not leak into
the next test.

The fixture is synthetic content written for these tests — three invented
lessons (S01–S03). It is not course material, and tests should assert against it
rather than against any real question set.

## Browser regression checks

`browser/runner.html` runs 73 DOM and layout regression scenarios against the shipped
AMD bundle and stylesheet, with mocked `core/ajax` and filter-event modules. Serve the plugin
directory locally, then open `tests/browser/runner.html`. The runner contains
only synthetic test data and never contacts an AI provider or Moodle server.
All 73 scenarios passed in headless Microsoft Edge during 0.4.9 packaging.
They cover reply, pause, confirmation, draft recovery, browser history, conversation
navigation, preserved reading focus, new-message announcements and narrow text wrapping.
The 11 scenarios added in 0.4.4 cover focus visibility after long replies, announcement
preferences and unavailable storage, external focus, original-scenario expansion,
and avoiding duplicate prompt announcements. The 11 scenarios added in 0.4.5
cover draft/editor sizing, narrow widths, character counts and sparse limit
warnings, error/stale recovery, manual resizing, browser history and the welcome
shortcut. Layout fixtures use the production form classes.
The 12 scenarios added in 0.4.7 cover inline feedback, action-specific waiting,
slow timers, exact-draft manual retries, stale recovery, safe recovery text,
browser history, fallback layouts and confirmed saves with filter errors.
The 10 scenarios added in 0.4.8 cover clarification with empty/over-limit drafts,
exact draft and selection preservation, request isolation, brief/full announcements,
reading focus, manual retry, stale recovery and the next assessed reply.
The 3 scenarios added in 0.4.9 cover skill cards in narrow containers, enlarged
text and resizing from wide to narrow layouts, using the production stylesheet.

A standalone Playwright suite for the original 17 scenarios is also included. With Node.js,
Playwright, and its Chromium browser available, run:

```bash
node --test tests/browser/conversation.test.cjs
```

Set `CHROME_PATH` if using an existing Chromium browser executable. The standalone
Playwright suite was not run for 0.4.9; the in-browser runner was used instead.
The UI checks do not replace the Moodle/PHP suite.

## Integration checks on a Moodle test site

- Complete the upgrade and purge caches, then verify that begin, reply, lesson
  progression, finish, final scores and allowed retries work without navigation.
- Use two tabs and submit an old form. It must show a stale-state message without
  consuming a turn or creating another attempt.
- Simulate an AI-provider failure. The draft must remain in the reply box and
  the server must retain the previous turn count and scores.
- Check a learner, an instructor without attempt permission, and a user without
  course access. Verify gradebook results after a completed attempt.
- Disable JavaScript and confirm that the POST fallback still works and rejects
  invalid session keys. AJAX session-key protection is provided by Moodle's
  authenticated `core/ajax` endpoint.

## Learning-plan regression checks (0.4.1)

`learning_plan_test.php` adds three tests for completed feedback, saved public
names/readings after content replacement, historical results with missing fields,
and escaping/unsafe reading URLs. They exercise the shared renderer and AJAX
completion path without contacting an AI provider. These new tests have not been
run in this Windows workspace, which has no Moodle/PHP runtime.

After upgrading a test site, complete a lesson and check the three feedback
sections, readable skill names, reading links and page references. Check an old
completed attempt too. At a narrow viewport the feedback cards should stack.

## Pause and final-submission checks (0.4.2)

The six additional PHP tests cover pause/resume with no AI calls, turns or grade;
stale and overlong draft rejection; explicit final confirmation; blocking unsent
text; saved drafts surviving AI failures; and confirmation counts/POST rendering.
These tests have not run here because the workspace has no Moodle/PHP runtime.

On a Moodle test site, check both fresh installation and upgrade from 0.4.1.
Pause with a partially written answer, return from the course, and verify the
exact draft and remaining replies. Repeat with JavaScript disabled. Confirm an
empty reply can be paused, and an unsent reply blocks final submission. Check
the final grade after deliberately ending a partially completed sequence. Use
two tabs to verify an old pause cannot overwrite a newer draft. A failed normal
POST must retain the edited draft once, including an explicitly cleared box.


## Conversation navigation checks (0.4.3)

The four PHP tests exercise the real renderer and saved messages. They check
collapsed history and the current lesson, matching and unique navigation targets,
visible final results, message ordering with missing/repeated lesson keys, and
escaped historical titles. They have not run in this workspace because Moodle
and PHP are unavailable.

Before the demo, validate in your Moodle theme:

- Use Tab and Shift+Tab to reach lesson links and disclosure summaries, then
  Enter/Space to open and close history. Follow the latest-message and reply
  links; focus must reach visible content with no keyboard trap.
- While an AI reply is pending, open an earlier lesson and focus its message.
  After the response arrives, verify that it stays open and your position remains.
- With NVDA/Firefox or VoiceOver/Safari, verify that the initial transcript is
  not announced as a live update, new evaluator messages are announced once,
  and errors and final results are discoverable. Check a lesson transition too.
- Test at 200% and 400% zoom and a narrow viewport with long replies and titles.
  Controls and text should wrap without a separate transcript scroll area.
- Disable JavaScript, reopen the activity, expand earlier lessons and follow
  the lesson/reply links. Submit a reply and pause using the native forms.

These checks follow the
[Moodle accessibility checklist](https://moodledev.io/general/development/process/peer-review/accessibility-checklist).
The automated DOM checks do not certify assistive-technology behavior.


## Student orientation and reply context checks (0.4.4)

On a Moodle test site, check the overview with one and several lessons, retries
enabled and disabled, and provisional assessments enabled and disabled. The
displayed reply budget, scoring and retry policy must match the activity.

Begin an attempt, send an answer, then expand **Review original scenario** beside
the reply field. It should show the opening question from that lesson's transcript,
remain open across another reply in the same lesson, and reset for a new lesson.
The context should display learner-visible message text only. Repeat with
JavaScript disabled to check the native disclosure and forms.

With keyboard navigation, submit a reply whose feedback is long enough to move
the form. Focus must stay visible in the reply field when the update completes.
While another reply is pending, move to earlier history, the announcement
preference, or a course navigation link; success must respect that focus choice.

With NVDA/Firefox or VoiceOver/Safari, test both announcement modes. Brief mode
should announce availability, while full mode should read only new evaluator
messages, without reading the repeated prompt beside the editor a second time.
Changing the setting must not replay previous messages. Confirm errors and
assessment completion are still announced. Reload the tab to check that the
setting is restored where session storage is available.

Test the overview, preference selector, reply context, and scenario disclosure at
a narrow viewport and 200-400% zoom in the installed Moodle theme. Browser DOM
tests cannot establish actual screen-reader behavior or full Moodle compatibility.


## Returning students and editor checks (0.4.5)

On a Moodle test site, pause with an unsent answer and reopen the activity.
Check that the welcome summary reflects the current lesson, completed count,
remaining replies and saved-draft status. **Continue where I left off** should
focus the restored answer without submitting it. Check an unfinished attempt
without a saved draft and a completed attempt too. The welcome summary must not
reappear after each AJAX reply.

Type and paste a long multiline answer, delete part of it, and resize the browser.
The textarea should fit the content, keep its text and caret, and show the
remaining character count. Submit a reply and verify that the fresh editor resets.
Simulate a failed request and a stale tab response; preserved drafts should keep
the correct counter and height. Use browser Back after pausing to check recovery.

With a screen reader, approach and reach the character limit. Warnings should be
sparse, with no count read on every keystroke and no draft warning on initial load.
Check that the field's guidance and limit are available through its description.
Repeat at high zoom and in a narrow viewport using the installed Moodle theme.
With JavaScript disabled, the static guidance, limit, jump link and native forms
must still work. These checks do not replace the automated Moodle/PHP suite.

## Previous attempts and feedback checks (0.4.6)

The new history PHP tests cover scoped retrieval, paging and summary fields,
saved feedback and transcript rendering, legacy and incomplete attempts,
escaped content, draft/private-data exclusion and unchanged database records.
They have not run in this workspace because Moodle and PHP are unavailable.
The browser scenarios protect the conversation flow; they
do not exercise the server-rendered history route or its Moodle access checks.

Before the demo, verify on a Moodle test site:

- Sign in as a learner with no attempts. **My attempts and feedback** opens an
  empty history in a new tab, with a return link.
- Complete two attempts, then start another and type an unsent answer. Open
  history and review both completed attempts. Switch back to the original
  activity tab: the answer and current conversation must remain untouched.
  Repeat with JavaScript disabled.
- Check attempt dates in the user's timezone, newest-first ordering, a zero
  score versus an unsubmitted attempt, and paging after more than ten attempts.
  An excessive or negative page number should resolve to an available page.
- Read saved strengths, gaps, next steps and readings, and expand lesson
  transcripts using only the keyboard. With NVDA/Firefox or VoiceOver/Safari,
  check headings, labelled links, dates and native disclosure controls. Test a
  narrow viewport and 200-400% zoom in the installed Moodle theme.
- Replace or remove the current lesson configuration and change grading
  settings. Historical titles, feedback, recorded points and saved per-lesson
  maxima must remain unchanged. No overall maximum or mastery verdict should
  be inferred from the new activity settings.
- Try another learner's attempt ID, your own attempt ID from a different
  activity, and a missing ID. Each must show the same unavailable message.
  Confirm a user without module view permission or access to a hidden activity
  cannot read the history route. History must use the signed-in identity even
  for instructors/managers, and remain available when retries are disabled.
- Review an unfinished attempt and an old record with missing feedback.
  Submitted messages and existing lesson feedback should remain readable;
  unsent drafts, private rubric/evidence data and assessment action forms must
  be absent. Confirm no grade or attempt data changes when reviewing history.

## Waiting and recovery checks (0.4.7)

The browser runner covers the persistent feedback panel beside the action
buttons, action-specific waiting messages, the one-time slow notice, timer
cancellation, manual retry and stale recovery, focus preservation, missing-slot
fallbacks, and browser Back restoration. Tests control the 15-second timer
without waiting for a real provider. The PHP tests exercise real server-rendered
markup and POST-style errors; they require the Moodle/PHP test environment and
have not run in this workspace.

On a Moodle test site:

- Begin, reply, submit the final assessment, and save a draft. Check the waiting
  text for each action. On a slow reply, wait at least 15 seconds: one longer-wait
  message should appear beside the buttons, with no new request or focus jump.
  The answer should remain selectable/copyable while submission is disabled.
- Simulate a provider or network failure. Check that the answer remains exact,
  the error has useful recovery instructions, and **Send reply** allows a manual
  retry. Test expired-session recovery too, keeping a copy before signing in.
- Lose a response after the server commits it, then retry. The server should
  return the latest conversation without consuming an extra reply. The guidance
  must ask students to review those messages before sending again. Repeat after
  another tab finishes the attempt: the recovered answer must remain copyable.
- Move keyboard focus to earlier messages or a course link while waiting. The
  slow notice must not move it; success must preserve the existing reading-focus
  behavior. With a screen reader, verify one waiting notice at each transition
  and access to both the error and its recovery instructions.
- Navigate away and return with Back during a request or after saving a draft.
  Controls must become usable; a late response or canceled timer must not replace
  the restored conversation or show a stale waiting notice.
- Disable JavaScript and trigger a failed reply. The error/help should appear
  near the action buttons and the unsent answer should remain in the field.
  Check the new panel at narrow widths and high zoom in the installed theme.

## Question clarification checks (0.4.8)

The clarification PHP tests exercise the action service, bounded model response,
saved-help reuse, assessment transcript filtering and learner markup with a
scripted provider. They require Moodle/PHP and have not run in this workspace.
Browser tests use mocked responses; they do not establish live model quality.

On a Moodle test site:

- Begin an attempt and choose **Clarify this question** with an empty reply.
  Confirm the original prompt remains, clarification appears beside it, and
  the reply budget, lesson position and grade stay unchanged.
- Repeat while writing a multiline answer, after clearing a previously saved
  draft, and with an over-limit restored draft. Help must remain available and
  must preserve the editor's exact text. It must not submit the draft as an answer.
- Reload to revisit the saved clarification, then submit a normal answer.
  Check that the next evaluator question offers its own clarification. Replaying
  the same clarification request must not call AI again or add another message.
- Request clarification before submitting any answer, then submit the final
  assessment. The clarification must not count as learner evidence or earn points.
- Use two tabs and request help from an older form. The latest conversation
  should appear with stale-state guidance and the draft retained. Repeat after
  finishing the attempt in the other tab; unsent text must remain copyable.
- Simulate provider failure and malformed output. No graded reply, draft,
  assessment state or partial clarification should be saved. Retry manually.
- Disable JavaScript, type a draft and request clarification. The POST result
  must keep the exact draft, show the help, and avoid redirecting away from it.
- Check brief/full screen-reader modes, keyboard focus and narrow layouts.
  Help should be announced once and external/reading focus should be respected.
  Review completed attempts and the instructor report: clarification must have
  its own role label and must not be presented as a student's answer.
- With the configured live provider, spot-check both initial scenarios and
  short follow-up questions. Rewording should preserve meaning, avoid solutions
  and additional hints, and acknowledge missing context instead of inventing it.

## Small-screen feedback checks (0.4.9)

The learning-plan regression checks preserve the saved public skill names,
judgement labels, comments, escaping and source order in finished and historical
feedback. The added PHP check requires Moodle/PHP and has not run in this workspace.
Browser checks exercise narrow containers, long unbroken text, enlarged text and
wide-to-narrow layout changes using the production styles with synthetic cards.
Enlarging text in the browser runner does not replace testing actual browser zoom
or assistive technology in the installed Moodle theme.

On a Moodle test site, complete an assessment and open an earlier attempt too:

- At a 320 CSS-pixel viewport, check that each skill card, long skill name and
  comment fits without horizontal scrolling or clipped text. Repeat inside a
  narrow activity column on a wider page.
- Check 200% and 400% browser zoom and enlarged browser text. Each judgement
  and comment should stay beside its label in the reading order, and cards should
  stack as space shrinks.
- With NVDA/Firefox or VoiceOver/Safari, navigate from the lesson heading to
  the skills group, skill headings and their judgement/comment labels. Each item
  should appear once. Missing public labels should remain neutral fallbacks.
- Disable JavaScript and review both current results and saved history. All
  feedback should remain readable. On a wide page, cards may sit side by side;
  narrowing the page must preserve the same content and order.
