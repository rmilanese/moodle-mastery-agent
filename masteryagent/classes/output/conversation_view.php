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

namespace mod_masteryagent\output;

use html_writer;
use moodle_url;
use mod_masteryagent\attempt;
use mod_masteryagent\conversation;
use mod_masteryagent\sequence;

/**
 * Shared learner-only HTML for the initial page and AJAX responses.
 *
 * @package mod_masteryagent
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class conversation_view {
    /**
     * Render the current conversation without exposing the evidence ledger or rubric.
     *
     * @param \stdClass $instance Activity record.
     * @param \stdClass $cm Course module record.
     * @param sequence $sequence Selected lessons.
     * @param attempt|null $current Current user's attempt.
     * @param string|null $draftoverride Unsent text from a failed normal POST, if any.
     * @param bool $showresume Show a welcome summary when opening an unfinished attempt.
     * @return string Escaped HTML.
     */
    public static function render(\stdClass $instance, \stdClass $cm, sequence $sequence, ?attempt $current,
            ?string $draftoverride = null, bool $showresume = false): string {
        global $OUTPUT;
        $out = '';
        if ($current === null) {
            $out .= $OUTPUT->box(
                self::render_overview($instance, $sequence)
                . html_writer::div(
                    self::action_form($cm, $current, 'start', 'begin')
                ),
                'generalbox'
            );
            return $out;
        }

        // A returning learner gets one overview of their saved place before the transcript.
        if ($showresume && !$current->is_finished()) {
            $out .= self::render_resume($sequence, $current);
        } else if (!$current->is_finished() && $sequence->is_multi()) {
            $lesson = $sequence->get($current->lesson_index());
            $out .= $OUTPUT->box(
                html_writer::tag('strong', get_string('progress', 'mod_masteryagent', (object) [
                    'position' => $current->lesson_index() + 1,
                    'total' => $sequence->count(),
                ]))
                . ' ' . s($lesson === null ? '' : trim($lesson->lesson_id() . ' ' . $lesson->title())),
                'generalbox py-2'
            );
        }

        $messages = $current->messages();
        $out .= self::render_transcript($sequence, $current, $messages);

        if (!$current->is_finished()) {
            $out .= html_writer::tag(
                'p',
                get_string('turnsleft', 'mod_masteryagent', $current->turns_left()),
                ['class' => 'text-muted']
            );

            $out .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/mod/masteryagent/view.php'),
                'class' => 'masteryagent-form',
                'data-action' => 'reply',
            ]);
            // One form keeps the current draft attached to every action, including without JavaScript.
            $out .= self::form_fields($cm, $current, '');
            $out .= html_writer::empty_tag('input', [
                'type' => 'hidden', 'name' => 'confirmed', 'value' => '1',
            ]);
            if ($draftoverride === null && $current->draft_reply() !== '') {
                $out .= html_writer::tag('p', get_string('draftrestored', 'mod_masteryagent'), ['class' => 'text-muted']);
            }
            $out .= self::render_reply_context($messages);
            $out .= html_writer::tag('label', get_string('yourreply', 'mod_masteryagent'), ['for' => 'masteryagent-reply']);
            $out .= html_writer::tag('p', get_string('replyguidance', 'mod_masteryagent'), [
                'id' => 'masteryagent-reply-guidance', 'class' => 'masteryagent-reply-guidance',
            ]);
            $out .= html_writer::tag('p', get_string('replylimit', 'mod_masteryagent', attempt::MAX_REPLY_CHARS), [
                'id' => 'masteryagent-reply-limit', 'class' => 'text-muted',
            ]);
            $out .= html_writer::tag('textarea', s($draftoverride ?? $current->draft_reply()), [
                'name' => 'reply',
                'id' => 'masteryagent-reply',
                'maxlength' => attempt::MAX_REPLY_CHARS,
                'rows' => 8,
                'class' => 'form-control masteryagent-reply-editor',
                'aria-describedby' => 'masteryagent-reply-guidance masteryagent-reply-limit',
                'required' => 'required',
                'placeholder' => get_string('replyplaceholder', 'mod_masteryagent'),
            ]);
            // Static limit text remains available without JavaScript; live counters are progressive enhancement.
            $out .= html_writer::tag('p', '', [
                'id' => 'masteryagent-reply-counter', 'data-region' => 'reply-counter',
                'class' => 'masteryagent-reply-counter text-muted', 'hidden' => 'hidden',
                'data-remaining' => get_string('replyremaining', 'mod_masteryagent', '{remaining}'),
                'data-nearlimit' => get_string('replynearlimit', 'mod_masteryagent'),
                'data-limitreached' => get_string('replylimitreached', 'mod_masteryagent'),
                'data-overlimit' => get_string('replyoverlimit', 'mod_masteryagent'),
            ]);
            $out .= html_writer::div(
                html_writer::tag('button', get_string('sendreply', 'mod_masteryagent'), [
                    'type' => 'submit', 'name' => 'action', 'value' => 'reply',
                    'class' => 'btn btn-primary',
                ])
                . html_writer::tag('button', get_string('saveandleave', 'mod_masteryagent'), [
                    'type' => 'submit', 'name' => 'action', 'value' => 'pause',
                    'class' => 'btn btn-secondary', 'formnovalidate' => 'formnovalidate',
                ]),
                'masteryagent-attempt-actions mt-2'
            );
            $out .= html_writer::tag('p', get_string('pausehelp', 'mod_masteryagent'), ['class' => 'text-muted mt-2']);

            $unanswered = max(0, $sequence->count() - $current->lesson_index()
                - ($current->turns_used() > 0 ? 1 : 0));
            $out .= html_writer::tag('details',
                html_writer::tag('summary', get_string('finishassessment', 'mod_masteryagent'))
                . html_writer::tag('p', get_string('finishprogress', 'mod_masteryagent', (object) [
                    'done' => count($current->lesson_results()), 'total' => $sequence->count(),
                ]), ['class' => 'mt-3'])
                . html_writer::tag('p', get_string('finishconsequences', 'mod_masteryagent'))
                . html_writer::tag('p', get_string('finishunanswered', 'mod_masteryagent', $unanswered))
                . html_writer::tag('p', get_string('finishunsent', 'mod_masteryagent'))
                . html_writer::tag('button', get_string('confirmfinish', 'mod_masteryagent'), [
                    'type' => 'submit', 'name' => 'action', 'value' => 'finish',
                    'class' => 'btn btn-outline-danger', 'formnovalidate' => 'formnovalidate',
                ]),
                ['class' => 'masteryagent-finish-confirmation mt-3']
            );
            $out .= html_writer::end_tag('form');
        } else {
            $record = $current->get_record();
            $results = $current->lesson_results();

            $body = html_writer::tag('h3', get_string('scoreline', 'mod_masteryagent', (object) [
                'score' => format_float((float) $record->score, 0),
                'max' => masteryagent_total_grade($instance),
            ]));

            if ($sequence->is_multi()) {
                $body .= html_writer::tag('p', get_string('lessonsmastered', 'mod_masteryagent', (object) [
                    'mastered' => $current->lessons_mastered(),
                    'total' => count($results),
                ]), ['class' => 'lead']);
            } else {
                $body .= html_writer::tag('p', $current->met_threshold()
                    ? get_string('verdictmet', 'mod_masteryagent')
                    : get_string('verdictnotmet', 'mod_masteryagent', (int) $instance->threshold), ['class' => 'lead']);
            }

            if (!empty($instance->provisional)) {
                $body .= html_writer::div(get_string('provisionalbanner', 'mod_masteryagent'), 'alert alert-info');
            }

            $body .= html_writer::tag('p', nl2br(s((string) $record->summary)));
            $out .= html_writer::div($body, 'generalbox', [
                'data-region' => 'results', 'id' => 'masteryagent-results', 'tabindex' => '-1',
            ]);

            foreach ($results as $result) {
                $out .= $OUTPUT->box(self::render_lesson_result($result), 'generalbox');
            }

            if (!empty($instance->allowretry)) {
                $out .= html_writer::div(
                    self::action_form($cm, $current, 'start', 'tryagain'),
                    'mt-2'
                );
            }
        }

        return $out;
    }

    /**
     * Review only public information saved with an attempt, independently of current activity settings.
     *
     * The caller must establish ownership before passing an attempt. This renderer never resumes,
     * scores or changes it, and deliberately excludes unsent drafts and private evaluation data.
     *
     * @param attempt $review Attempt the learner is allowed to review.
     * @return string Escaped, read-only feedback and transcript without JavaScript dependencies.
     */
    public static function render_review(attempt $review): string {
        $record = $review->get_record();
        $feedback = html_writer::tag('h3', get_string('historyreviewfeedback', 'mod_masteryagent'),
            ['id' => 'masteryagent-history-feedback-title']);
        if ($review->is_finished()) {
            if ($record->score !== null) {
                $feedback .= html_writer::tag('p', get_string('historyreviewscore', 'mod_masteryagent',
                    format_float((float) $record->score, 2)), ['class' => 'lead', 'data-region' => 'history-review-score']);
                $feedback .= html_writer::tag('p', get_string('historyreviewscorenote', 'mod_masteryagent'),
                    ['class' => 'text-muted']);
            } else {
                $feedback .= html_writer::tag('p', get_string('historyreviewnoscore', 'mod_masteryagent'));
            }
            $summary = trim((string) ($record->summary ?? ''));
            $feedback .= html_writer::tag('p', $summary !== '' ? nl2br(s($summary))
                : get_string('historyreviewsummarymissing', 'mod_masteryagent'));
        } else {
            $feedback .= html_writer::tag('p', get_string('historyreviewunfinished', 'mod_masteryagent'));
        }
        $results = array_filter($review->lesson_results(), 'is_array');
        foreach ($results as $result) {
            $feedback .= html_writer::div(self::render_lesson_result($result), 'generalbox');
        }
        if (!$results) {
            $feedback .= html_writer::tag('p', get_string('historyreviewlessonfeedbackmissing', 'mod_masteryagent'));
        }
        $out = html_writer::tag('section', $feedback, [
            'data-region' => 'history-review-results', 'aria-labelledby' => 'masteryagent-history-feedback-title',
        ]);
        $messages = $review->messages();
        $transcript = html_writer::tag('h3', get_string('historyreviewtranscript', 'mod_masteryagent'),
            ['id' => 'masteryagent-history-transcript-title']);
        $transcript .= $messages ? self::render_transcript(new sequence([]), $review, $messages, true)
            : html_writer::tag('p', get_string('historyreviewnomessages', 'mod_masteryagent'));
        return $out . html_writer::tag('section', $transcript, [
            'data-region' => 'history-review-transcript', 'aria-labelledby' => 'masteryagent-history-transcript-title',
        ]);
    }

    /**
     * Help a returning learner find their saved place without revealing their draft in the summary.
     *
     * @param sequence $sequence Selected lessons.
     * @param attempt $current Unfinished attempt.
     * @return string Escaped welcome summary and a native reply link.
     */
    private static function render_resume(sequence $sequence, attempt $current): string {
        $lesson = $sequence->get($current->lesson_index());
        $body = html_writer::tag('h3', get_string('resumeheading', 'mod_masteryagent'),
            ['id' => 'masteryagent-resume-title']);
        $body .= html_writer::tag('p',
            html_writer::tag('strong', get_string('progress', 'mod_masteryagent', (object) [
                'position' => $current->lesson_index() + 1, 'total' => $sequence->count(),
            ]))
            . ' ' . s($lesson === null ? '' : trim($lesson->lesson_id() . ' ' . $lesson->title())));
        $body .= html_writer::tag('ul',
            html_writer::tag('li', get_string('finishprogress', 'mod_masteryagent', (object) [
                'done' => count($current->lesson_results()), 'total' => $sequence->count(),
            ]))
            . html_writer::tag('li', get_string('turnsleft', 'mod_masteryagent', $current->turns_left())));
        $body .= html_writer::tag('p', get_string($current->draft_reply() !== '' ? 'resumesaveddraft' : 'resumenodraft',
            'mod_masteryagent'), ['data-region' => 'resume-draft-status']);
        $body .= html_writer::tag('p', html_writer::link('#masteryagent-reply',
            get_string('resumecontinue', 'mod_masteryagent'), [
                'class' => 'btn btn-primary', 'data-conversation-jump' => 'masteryagent-reply',
                'id' => 'jump-masteryagent-reply-from-resume',
            ]));
        return html_writer::tag('section', $body, [
            'class' => 'generalbox masteryagent-resume-summary', 'data-region' => 'resume-summary',
            'aria-labelledby' => 'masteryagent-resume-title',
        ]);
    }

    /**
     * Explain the configured assessment before the learner starts it.
     *
     * @param \stdClass $instance Activity settings.
     * @param sequence $sequence Selected lessons.
     * @return string Public expectations without private rubric content.
     */
    private static function render_overview(\stdClass $instance, sequence $sequence): string {
        $items = [];
        $items[] = $sequence->is_multi()
            ? get_string('beforebeginmulti', 'mod_masteryagent', (object) [
                'lessons' => $sequence->count(), 'turns' => (int) $instance->maxturns,
            ])
            : get_string('beforebeginsingle', 'mod_masteryagent', (int) $instance->maxturns);
        $items[] = get_string($sequence->is_multi() ? 'beforebeginflowmulti' : 'beforebeginflowsingle',
            'mod_masteryagent');
        $items[] = get_string('beforebegingrading', 'mod_masteryagent', (object) [
            'max' => (int) $instance->maxgrade,
            'threshold' => (int) $instance->threshold,
            'total' => (int) $instance->maxgrade * $sequence->count(),
        ]);
        $items[] = get_string(!empty($instance->allowretry) ? 'beforebeginretry' : 'beforebeginnoretry',
            'mod_masteryagent');
        $items[] = get_string('beforebeginpause', 'mod_masteryagent');
        $items[] = get_string('beforebeginfinish', 'mod_masteryagent');
        $body = html_writer::tag('h3', get_string('beforebeginheading', 'mod_masteryagent'),
            ['id' => 'masteryagent-before-begin-title'])
            . html_writer::tag('p', get_string('beforebeginintro', 'mod_masteryagent'))
            . html_writer::tag('ul', implode('', array_map(static fn($item) => html_writer::tag('li', $item), $items)));
        if (!empty($instance->provisional)) {
            $body .= html_writer::tag('p', get_string('beforebeginprovisional', 'mod_masteryagent'),
                ['class' => 'masteryagent-overview-provisional']);
        }
        return html_writer::tag('section', $body, [
            'class' => 'masteryagent-overview', 'data-region' => 'before-begin',
            'aria-labelledby' => 'masteryagent-before-begin-title',
        ]);
    }

    /**
     * Keep the last evaluator message and original scenario beside the reply field.
     *
     * Read only the final contiguous lesson group, matching the visible current lesson.
     * Copies deliberately omit transcript message markers to avoid duplicate announcements.
     *
     * @param array $messages Saved messages, oldest first.
     * @return string Escaped context, or nothing when this group has no evaluator message.
     */
    private static function render_reply_context(array $messages): string {
        $key = null;
        $opening = null;
        $latest = null;
        foreach ($messages as $message) {
            $messagekey = (string) ($message->lessonkey ?? '');
            if ($messagekey !== $key) {
                $key = $messagekey;
                $opening = null;
                $latest = null;
            }
            if ($message->role === 'agent') {
                $opening = $opening ?? $message;
                $latest = $message;
            }
        }
        if ($latest === null) {
            return '';
        }
        $body = html_writer::tag('h3', get_string('latestagentmessage', 'mod_masteryagent'), [
            'id' => 'masteryagent-reply-context-title', 'tabindex' => '-1',
        ]) . html_writer::div(nl2br(s($latest->message)), 'masteryagent-context-text');
        if ($opening->message !== $latest->message) {
            $id = 'masteryagent-scenario-' . (int) $opening->id;
            $body .= html_writer::tag('details',
                html_writer::tag('summary', get_string('revieworiginalscenario', 'mod_masteryagent'), ['id' => $id])
                . html_writer::div(nl2br(s($opening->message)), 'masteryagent-context-text'), [
                    'class' => 'masteryagent-original-scenario', 'data-region' => 'original-scenario',
                    'data-section-key' => $id,
                ]);
        }
        $body .= html_writer::tag('p', self::jump_link('masteryagent-reply',
            get_string('writemyreply', 'mod_masteryagent'), '-from-context'));
        return html_writer::tag('section', $body, [
            'class' => 'masteryagent-reply-context', 'data-region' => 'reply-context',
            'aria-labelledby' => 'masteryagent-reply-context-title',
        ]);
    }

    /**
     * Group adjacent messages without reordering history or dropping legacy messages.
     *
     * @param sequence $sequence Selected lessons.
     * @param attempt $current Learner attempt.
     * @param array $messages Saved messages, oldest first.
     * @param bool $readonly Use saved conversation groups without active controls or JavaScript shortcuts.
     * @return string Escaped transcript and native navigation.
     */
    private static function render_transcript(sequence $sequence, attempt $current, array $messages,
            bool $readonly = false): string {
        $titles = [];
        foreach ($sequence->all() as $index => $lesson) {
            $titles[$sequence->key_for($index)] = trim($lesson->lesson_id() . ' ' . $lesson->title());
        }
        foreach ($current->lesson_results() as $result) {
            if (!is_array($result)) {
                continue;
            }
            // Prefer the saved public title when the instructor has since replaced the content.
            $titles[$result['key'] ?? ''] = trim(($result['lesson_id'] ?? '') . ' ' . ($result['title'] ?? ''));
        }
        $groups = [];
        $latestagent = null;
        foreach ($messages as $message) {
            $key = (string) ($message->lessonkey ?? '');
            $last = count($groups) - 1;
            if ($last < 0 || $groups[$last]['key'] !== $key) {
                $groups[] = ['key' => $key, 'messages' => []];
                $last++;
            }
            $groups[$last]['messages'][] = $message;
            if ($message->role === 'agent') {
                $latestagent = $message->id;
            }
        }
        $links = '';
        $sections = '';
        foreach ($groups as $index => $group) {
            $id = 'masteryagent-section-' . (int) $group['messages'][0]->id;
            $title = $titles[$group['key']] ?? '';
            if ($title === '') {
                $title = get_string('conversationsection', 'mod_masteryagent', $index + 1);
            }
            $active = !$readonly && !$current->is_finished() && $index === count($groups) - 1;
            $label = s($title) . ($active ? ' — ' . get_string('currentconversationlesson', 'mod_masteryagent') : '');
            $links .= html_writer::tag('li', $readonly ? html_writer::link('#' . $id, $label)
                : self::jump_link($id, $label));
            $groupmessages = '';
            foreach ($group['messages'] as $message) {
                $isagent = $message->role === 'agent';
                $groupmessages .= html_writer::div(
                    html_writer::div(get_string($isagent ? 'roleagent' : 'rolestudent', 'mod_masteryagent'),
                        'masteryagent-role')
                    . html_writer::div(nl2br(s($message->message)), 'masteryagent-text'),
                    'masteryagent-message ' . ($isagent ? 'masteryagent-agent' : 'masteryagent-student'), [
                        'id' => 'masteryagent-message-' . (int) $message->id,
                        'data-message-id' => $message->id, 'tabindex' => '-1',
                    ]
                );
            }
            if ($active) {
                $sections .= html_writer::tag('section',
                    html_writer::tag('h3', $label, ['id' => $id, 'tabindex' => '-1']) . $groupmessages,
                    ['data-region' => 'current-lesson', 'aria-labelledby' => $id]);
            } else {
                $sections .= html_writer::tag('details',
                    html_writer::tag('summary', $label, ['id' => $id]) . $groupmessages,
                    ['class' => 'masteryagent-history', 'data-region' => 'lesson-history', 'data-section-key' => $id]);
            }
        }
        $shortcuts = '';
        if (!$readonly && $latestagent !== null) {
            $shortcuts .= self::jump_link('masteryagent-message-' . (int) $latestagent,
                get_string('latestagentmessage', 'mod_masteryagent'));
        }
        if (!$readonly) {
            $shortcuts .= self::jump_link($current->is_finished() ? 'masteryagent-results' : 'masteryagent-reply',
                get_string($current->is_finished() ? 'jumptoresults' : 'jumptoreply', 'mod_masteryagent'));
        }
        return html_writer::tag('nav',
            ($shortcuts !== '' ? html_writer::div($shortcuts, 'masteryagent-shortcuts') : '')
            . html_writer::tag('ul', $links, ['class' => 'masteryagent-lesson-links']), [
                'aria-label' => get_string('conversationnavigation', 'mod_masteryagent'),
                'data-region' => 'conversation-navigation', 'class' => 'masteryagent-navigation',
            ]) . html_writer::div($sections, 'masteryagent-conversation')
            . (!$readonly && !$current->is_finished() && $latestagent !== null ? html_writer::tag('p',
                self::jump_link('masteryagent-message-' . (int) $latestagent,
                    get_string('latestagentmessage', 'mod_masteryagent'), '-by-reply')) : '');
    }

    /**
     * Render a fragment link that also works without JavaScript.
     *
     * @param string $target Generated element ID.
     * @param string $label Escaped link label.
     * @param string $suffix Distinguishes repeated shortcuts.
     * @return string
     */
    private static function jump_link(string $target, string $label, string $suffix = ''): string {
        return html_writer::link('#' . $target, $label, [
            'data-conversation-jump' => $target, 'id' => 'jump-' . $target . $suffix,
        ]);
    }

    /**
     * Render a start or retry POST form, also handled by the AJAX module.
     *
     * @param \stdClass $cm Course module.
     * @param attempt|null $current Latest attempt.
     * @param string $action Action name.
     * @param string $label Language string identifier.
     * @param string $class Button classes.
     * @return string
     */
    private static function action_form(\stdClass $cm, ?attempt $current, string $action,
            string $label, string $class = 'btn btn-primary'): string {
        return html_writer::tag('form', self::form_fields($cm, $current, $action)
            . html_writer::tag('button', get_string($label, 'mod_masteryagent'), [
                'type' => 'submit', 'class' => $class,
            ]), [
                'method' => 'post',
                'action' => new moodle_url('/mod/masteryagent/view.php'),
                'data-action' => $action,
            ]);
    }

    /**
     * Fields shared by AJAX and normal POST submissions.
     *
     * @param \stdClass $cm Course module.
     * @param attempt|null $current Latest attempt.
     * @param string $action Action name.
     * @return string
     */
    private static function form_fields(\stdClass $cm, ?attempt $current, string $action): string {
        $out = '';
        foreach (['id' => $cm->id, 'action' => $action, 'sesskey' => sesskey(),
                'state' => conversation::state($current)] as $name => $value) {
            if ($name === 'action' && $action === '') {
                continue;
            }
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        return $out;
    }

    /**
     * Render the public assessment for a lesson.
     *
     * @param array $result Stored per-lesson result.
     * @return string
     */
    private static function render_lesson_result(array $result): string {
        $heading = trim(($result['lesson_id'] ?? '') . ' ' . ($result['title'] ?? ''));
        $out = html_writer::tag('h4', s($heading) . ' — ' . s((string) ($result['score'] ?? '')) . '/'
            . (int) ($result['max'] ?? 0));
        $out .= html_writer::tag('p', nl2br(s((string) ($result['summary'] ?? ''))));

        $out .= html_writer::div(
            self::feedback_section('learningstrengths', $result['strengths'] ?? [], 'learningstrengthsempty')
            . self::feedback_section('learninggaps', $result['gaps'] ?? [], 'learninggapsempty'),
            'masteryagent-feedback-grid'
        );

        $nextstep = is_string($result['next_step'] ?? null) ? trim($result['next_step']) : '';
        $out .= html_writer::div(
            html_writer::tag('h5', get_string('learningnextstep', 'mod_masteryagent'))
            . html_writer::tag('p', $nextstep !== '' ? nl2br(s($nextstep))
                : get_string('learningnextstepempty', 'mod_masteryagent')),
            'masteryagent-next-step'
        );
        $out .= self::render_readings($result['learning_resources'] ?? []);

        $verdictmap = [
            'met' => 'verdictmetshort',
            'partial' => 'verdictpartial',
            'notmet' => 'verdictnotmetshort',
        ];
        $names = is_array($result['dimension_names'] ?? null) ? $result['dimension_names'] : [];
        $rows = '';
        $position = 0;
        foreach ((array) ($result['dimensions'] ?? []) as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }
            $position++;
            $id = is_string($dimension['id'] ?? null) ? $dimension['id'] : '';
            $name = is_string($names[$id] ?? null) ? trim($names[$id]) : '';
            if ($name === '') {
                // Older question sets have only internal IDs; do not invent a skill name.
                $name = get_string('learningdimensionfallback', 'mod_masteryagent', $position);
            }
            $raw = preg_replace('/[^a-z]/', '', strtolower((string) ($dimension['verdict'] ?? '')));
            $label = isset($verdictmap[$raw])
                ? get_string($verdictmap[$raw], 'mod_masteryagent')
                : get_string('learningverdictunknown', 'mod_masteryagent');
            $rows .= html_writer::tag(
                'tr',
                html_writer::tag('th', s($name), ['scope' => 'row'])
                . html_writer::tag('td', $label)
                . html_writer::tag('td', s((string) ($dimension['comment'] ?? '')))
            );
        }
        if ($rows !== '') {
            $out .= html_writer::div(html_writer::tag(
                'table',
                html_writer::tag('caption', get_string('learningbreakdown', 'mod_masteryagent'))
                . html_writer::tag(
                    'thead',
                    html_writer::tag(
                        'tr',
                        html_writer::tag('th', get_string('learningskill', 'mod_masteryagent'), ['scope' => 'col'])
                        . html_writer::tag('th', get_string('verdict', 'mod_masteryagent'), ['scope' => 'col'])
                        . html_writer::tag('th', get_string('comment', 'mod_masteryagent'), ['scope' => 'col'])
                    )
                ) . html_writer::tag('tbody', $rows),
                ['class' => 'table table-sm']
            ), 'table-responsive');
        }

        return html_writer::div($out, 'masteryagent-learning-plan');
    }

    /**
     * Render learner feedback as escaped text, with an honest empty state.
     *
     * @param string $heading Language string for the heading.
     * @param mixed $items Stored feedback.
     * @param string $empty Language string when no feedback was recorded.
     * @return string
     */
    private static function feedback_section(string $heading, $items, string $empty): string {
        $list = '';
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list .= html_writer::tag('li', s(trim($item)));
            }
        }
        return html_writer::div(
            html_writer::tag('h5', get_string($heading, 'mod_masteryagent'))
            . ($list !== '' ? html_writer::tag('ul', $list)
                : html_writer::tag('p', get_string($empty, 'mod_masteryagent'), ['class' => 'text-muted'])),
            'masteryagent-feedback-section'
        );
    }

    /**
     * Render the reading references saved with this result.
     *
     * Only HTTP(S) links are allowed. References without usable URLs remain text.
     *
     * @param mixed $readings Stored public reading references.
     * @return string
     */
    private static function render_readings($readings): string {
        $list = '';
        foreach (is_array($readings) ? $readings : [] as $reading) {
            if (!is_array($reading) || !is_string($reading['title'] ?? null) || trim($reading['title']) === '') {
                continue;
            }
            $title = s(trim($reading['title']));
            $url = is_string($reading['url'] ?? null) ? trim($reading['url']) : '';
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true)) {
                $title = html_writer::link(new moodle_url($url), $title);
            }
            $details = [];
            foreach (['edition_or_date', 'coursebook_page_or_section'] as $key) {
                if (is_string($reading[$key] ?? null) && trim($reading[$key]) !== '') {
                    $details[] = trim($reading[$key]);
                }
            }
            $list .= html_writer::tag('li', $title
                . ($details ? html_writer::div(s(implode(' · ', $details)), 'text-muted') : ''));
        }
        return html_writer::div(
            html_writer::tag('h5', get_string('learningreadings', 'mod_masteryagent'))
            . ($list !== ''
                ? html_writer::tag('p', get_string('learningreadingshelp', 'mod_masteryagent'))
                    . html_writer::tag('ul', $list)
                : html_writer::tag('p', get_string('learningreadingsempty', 'mod_masteryagent'), ['class' => 'text-muted'])),
            'masteryagent-readings'
        );
    }

}
