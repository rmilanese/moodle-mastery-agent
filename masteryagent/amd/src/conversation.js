// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Update the learner conversation without navigating away from the page.
 *
 * @module mod_masteryagent/conversation
 * @copyright 2026 MCU-NPS AI Learning Initiatives
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {call as ajaxCall} from 'core/ajax';
import {notifyFilterContentUpdated} from 'core_filters/events';

/**
 * Attach a delegated handler that survives conversation fragment replacement.
 *
 * @param {string} selector The persistent application wrapper.
 */
export const init = selector => {
    const root = document.querySelector(selector);
    if (!root || root.dataset.initialized === 'true') {
        return;
    }
    root.dataset.initialized = 'true';
    const content = root.querySelector('[data-region="content"]');
    const status = root.querySelector('[data-region="status"]');
    const error = root.querySelector('[data-region="error"]');
    const recoveredDraft = root.querySelector('[data-region="draft"]');
    let busy = false;

    const setBusy = value => {
        busy = value;
        content.setAttribute('aria-busy', String(value));
        content.querySelectorAll('button, input[type="submit"]').forEach(control => {
            control.disabled = value;
        });
        content.querySelectorAll('textarea').forEach(control => {
            control.readOnly = value;
        });
        if (value) {
            status.textContent = root.dataset.processing;
        }
    };

    const showError = message => {
        // Neither a provider error nor a transport error is trusted HTML.
        error.textContent = message;
        error.hidden = false;
        error.focus({preventScroll: true});
        error.scrollIntoView({block: 'nearest'});
    };

    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            // Back navigation can restore the page as it was while leaving.
            // Let the server's revision check reconcile any saved draft changes.
            setBusy(false);
            status.textContent = '';
        }
    });

    root.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-action]');
        if (!form || !content.contains(form)) {
            return;
        }
        if (busy) {
            event.preventDefault();
            return;
        }
        const submitter = event.submitter;
        const action = submitter && submitter.name === 'action' ? submitter.value : form.dataset.action;
        if (action === 'pause') {
            // Use a normal POST so the server saves the draft and returns to the course.
            // Do not disable form controls: the submitter and reply must be included in the POST.
            busy = true;
            status.textContent = root.dataset.processing;
            return;
        }
        event.preventDefault();
        if (action !== 'finish' && !form.reportValidity()) {
            return;
        }
        const replyBox = content.querySelector('textarea[name="reply"]');
        const draft = replyBox ? replyBox.value : '';
        if (action === 'reply' && !draft.trim()) {
            replyBox.focus();
            return;
        }
        const confirmation = form.elements.namedItem('confirmed');
        const confirmed = Boolean(confirmation && confirmation.value === '1');
        if (action === 'finish' && (!confirmed || draft.trim())) {
            showError(!confirmed ? root.dataset.confirmrequired : root.dataset.unsent);
            if (draft.trim()) {
                replyBox.focus();
            }
            return;
        }
        const previousMessages = new Set(Array.from(content.querySelectorAll('[data-message-id]'),
            node => node.dataset.messageId));
        error.hidden = true;
        error.textContent = '';
        setBusy(true);

        try {
            // core/ajax supplies the Moodle session key and standard authenticated endpoint.
            const response = await ajaxCall([{
                methodname: 'mod_masteryagent_update_conversation',
                args: {
                    cmid: Number(root.dataset.cmid),
                    action,
                    state: form.elements.namedItem('state').value,
                    reply: draft,
                    ...(action === 'finish' ? {confirmed} : {}),
                },
            }])[0];
            if (!response || typeof response.html !== 'string' || typeof response.stale !== 'boolean') {
                throw new Error(root.dataset.error);
            }
            // HTML comes only from the plugin's escaped, learner-only PHP renderer.
            content.innerHTML = response.html;
            const newReplyBox = content.querySelector('textarea[name="reply"]');
            if (response.stale && draft) {
                if (newReplyBox) {
                    newReplyBox.value = draft;
                } else {
                    recoveredDraft.querySelector('textarea').value = draft;
                    recoveredDraft.hidden = false;
                }
            }
            setBusy(false);
            status.textContent = root.dataset.updated;
            if (response.stale) {
                showError(response.warning || root.dataset.error);
            } else {
                recoveredDraft.hidden = true;
                recoveredDraft.querySelector('textarea').value = '';
                const results = content.querySelector('[data-region="results"]');
                const focusTarget = results || newReplyBox;
                if (focusTarget) {
                    focusTarget.focus({preventScroll: true});
                }
                const firstNewAgent = Array.from(content.querySelectorAll('.masteryagent-agent[data-message-id]'))
                    .find(node => !previousMessages.has(node.dataset.messageId));
                const scrollTarget = results || firstNewAgent || focusTarget;
                if (scrollTarget) {
                    scrollTarget.scrollIntoView({block: 'nearest'});
                }
            }
            notifyFilterContentUpdated([content]);
        } catch (exception) {
            // Do not replay mutations automatically: a network error may follow a successful save.
            // Keep the form's old revision so a manual retry cannot submit the same turn twice.
            setBusy(false);
            status.textContent = '';
            showError(exception && exception.message ? exception.message : root.dataset.error);
        }
    });
};
