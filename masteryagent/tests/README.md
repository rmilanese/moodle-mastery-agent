# Running the mastery agent tests

95 PHPUnit tests covering the question-set parser, lesson selection, prompt
construction, the conversation engine, gradebook, and AJAX endpoint. They never call a real
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
`external_test.php`, for 95 tests in total. The PHP suite has not been
executed in this workspace; run it on a development Moodle site.

## What each file covers

| File | Covers |
| --- | --- |
| `lesson_test.php` | Reading a question set: wrapper and bare-array forms, missing fields, source metadata |
| `sequence_test.php` | Lesson selection by id, ordering, unknown ids, legacy single-lesson instances |
| `agent_test.php` | Prompt contents, scoring bands across scale widths, corrected-misconception handling, JSON parsing (fenced, prose-wrapped, malformed), score clamping, course summary |
| `attempt_test.php` | Turns, budget exhaustion, automatic lesson advance, totals, early finish, provider failure recovery |
| `external_test.php` | Authenticated AJAX access, ownership and hidden activities, duplicate/stale requests, progression, grades, retry settings, input limits, escaped output and atomic provider/scoring failures |
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

`browser/runner.html` runs 17 DOM-level regression scenarios against the shipped
AMD bundle, with mocked `core/ajax` and filter-event modules. Serve the plugin
directory locally, then open `tests/browser/runner.html`. The runner contains
only synthetic test data and never contacts an AI provider or Moodle server.
All 17 scenarios passed in headless Microsoft Edge during 0.4.2 packaging.
They cover reply, pause, confirmation, draft recovery and browser history behavior.

An equivalent standalone Playwright suite is also included. With Node.js,
Playwright, and its Chromium browser available, run:

```bash
node --test tests/browser/conversation.test.cjs
```

Set `CHROME_PATH` if using an existing Chromium browser executable. The standalone
Playwright suite was not run for 0.4.2; the in-browser runner was used instead.
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
