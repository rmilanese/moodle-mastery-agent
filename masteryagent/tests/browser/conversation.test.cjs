// Standalone browser regression tests. Run with Node.js and Playwright installed.
// Tests the shipped AMD bundle with mocked Moodle AJAX/filter modules; no AI calls.
const {test} = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require('playwright');

const actionForm = (action, state, extra = '') => `<form data-action="${action}" method="post" action="/view.php">
    <input type="hidden" name="state" value="${state}">${extra}<button type="submit">${action}</button></form>`;
const active = (state = 'revision1', number = 1) => `<div class="masteryagent-agent" data-message-id="${number}">Question ${number}</div>
    <form data-action="reply" method="post" action="/view.php">
    <input name="state" type="hidden" value="${state}"><input name="confirmed" type="hidden" value="1">
    <label for="reply">Your reply</label><textarea id="reply" name="reply" required maxlength="8000"></textarea>
    <button name="action" value="reply">Send reply</button>
    <button name="action" value="pause" formnovalidate>Save and leave</button>
    <details><summary>Submit final assessment</summary><p>Unanswered lessons contribute zero.</p>
    <button name="action" value="finish" formnovalidate>Yes, submit and score</button></details></form>`;
const finished = () => `<div data-region="results" tabindex="-1">Score: 3 of 4</div>${actionForm('start', 'finished1')}`;

let browser;
test.before(async () => {
    browser = await chromium.launch({headless: true,
        ...(process.env.CHROME_PATH ? {executablePath: process.env.CHROME_PATH} : {})});
});
test.after(async () => { if (browser) await browser.close(); });

async function pageFor(t, fragment = actionForm('start', 'new')) {
    const page = await browser.newPage();
    t.after(() => page.close());
    await page.setContent(`<div id="masteryagent-app" data-cmid="42" data-processing="Working"
        data-updated="Updated" data-error="Request failed. Your draft is still here."
        data-unsent="Send or clear your draft first" data-confirmrequired="Confirm final submission">
        <div data-region="error" role="alert" tabindex="-1" hidden></div>
        <div data-region="status" role="status"></div>
        <div data-region="draft" hidden><textarea readonly></textarea></div>
        <div data-region="content" aria-busy="false">${fragment}</div></div>`);
    await page.evaluate(() => {
        window.requests = [];
        window.filterUpdates = 0;
        window.define = (name, deps, factory) => {
            const exports = {};
            const modules = {
                require: () => {}, exports,
                'core/ajax': {call: requests => requests.map(request => new Promise((resolve, reject) => {
                    window.requests.push({request, resolve, reject});
                }))},
                'core_filters/events': {notifyFilterContentUpdated: () => window.filterUpdates++},
            };
            factory(...deps.map(dep => modules[dep]));
            window.conversation = exports;
        };
    });
    await page.addScriptTag({path: path.resolve(__dirname, '../../amd/build/conversation.min.js')});
    await page.evaluate(() => window.conversation.init('#masteryagent-app'));
    return page;
}
async function resolve(page, html, stale = false, warning = '') {
    await page.evaluate(response => window.requests.at(-1).resolve(response), {html, stale, warning});
    await page.waitForFunction(() => document.querySelector('[data-region="content"]').getAttribute('aria-busy') === 'false');
}

test('begin replaces the fragment without a page navigation', async t => {
    const page = await pageFor(t);
    let navigations = 0;
    page.on('framenavigated', () => navigations++);
    await page.click('button');
    assert.equal(await page.textContent('[data-region="status"]'), 'Working');
    assert.equal(await page.isDisabled('button'), true);
    assert.deepEqual(await page.evaluate(() => window.requests[0].request), {
        methodname: 'mod_masteryagent_update_conversation', args: {cmid: 42, action: 'start', state: 'new', reply: ''},
    });
    await resolve(page, active());
    assert.equal(await page.locator('textarea[name="reply"]').count(), 1);
    assert.equal(await page.evaluate(() => document.activeElement.name), 'reply');
    assert.equal(await page.evaluate(() => window.filterUpdates), 1);
    assert.equal(navigations, 0);
});

test('a pending reply blocks duplicate submissions and finish', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'My answer');
    await page.evaluate(() => {
        const form = document.querySelector('form[data-action="reply"]');
        form.requestSubmit();
        form.dispatchEvent(new Event('submit', {bubbles: true, cancelable: true}));
        form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true,
            submitter: document.querySelector('button[value="finish"]')}));
    });
    assert.equal(await page.evaluate(() => window.requests.length), 1);
    assert.equal(await page.evaluate(() => document.querySelector('textarea[name="reply"]').readOnly), true);
    assert.deepEqual(await page.evaluate(() => window.requests[0].request.args), {
        cmid: 42, action: 'reply', state: 'revision1', reply: 'My answer',
    });
    await resolve(page, active('revision2', 3));
    assert.equal(await page.inputValue('textarea[name="reply"]'), '');
    assert.equal(await page.isDisabled('button[value="finish"]'), false);
});

test('delegated handler continues working after multiple responses', async t => {
    const page = await pageFor(t, active());
    for (let n = 1; n <= 3; n++) {
        await page.fill('textarea[name="reply"]', `Answer ${n}`);
        await page.click('form[data-action="reply"] button');
        assert.equal(await page.evaluate(() => window.requests.length), n);
        await resolve(page, active(`revision${n + 1}`, n * 2 + 1));
    }
});

test('finish shows scores and retry starts an attempt without navigation', async t => {
    const page = await pageFor(t, active());
    let navigations = 0;
    page.on('framenavigated', () => navigations++);
    await page.click('summary');
    await page.click('button[value="finish"]');
    await resolve(page, finished());
    assert.equal(await page.evaluate(() => document.activeElement.dataset.region), 'results');
    await page.click('form[data-action="start"] button');
    assert.equal(await page.evaluate(() => window.requests.at(-1).request.args.state), 'finished1');
    await resolve(page, active('newattempt', 10));
    assert.equal(navigations, 0);
});

test('provider error keeps the draft and revision and renders errors as text', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', '<p>My unsent answer</p>');
    await page.click('form[data-action="reply"] button');
    await page.evaluate(() => window.requests[0].reject({message: '<img src=x onerror=alert(1)> Provider unavailable'}));
    await page.waitForFunction(() => !document.querySelector('[data-region="error"]').hidden);
    assert.equal(await page.inputValue('textarea[name="reply"]'), '<p>My unsent answer</p>');
    assert.equal(await page.locator('[data-region="error"] img').count(), 0);
    assert.equal(await page.isDisabled('form[data-action="reply"] button'), false);
    assert.equal(await page.inputValue('form[data-action="reply"] input[name="state"]'), 'revision1');
    assert.equal(await page.evaluate(() => window.requests.length), 1);
});

test('transport failure permits a manual retry but never automatically replays a write', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Possibly already saved');
    await page.click('form[data-action="reply"] button');
    await page.evaluate(() => window.requests[0].reject('Connection lost'));
    await page.waitForFunction(() => !document.querySelector('[data-region="error"]').hidden);
    assert.equal(await page.evaluate(() => window.requests.length), 1);
    await page.click('form[data-action="reply"] button');
    assert.equal(await page.evaluate(() => window.requests[1].request.args.state), 'revision1');
    await resolve(page, active('revision2', 3), true, 'Review the saved conversation');
    assert.equal(await page.inputValue('textarea[name="reply"]'), 'Possibly already saved');
});

test('stale response displays current state and preserves the draft', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Draft in another tab');
    await page.click('form[data-action="reply"] button');
    await resolve(page, active('revision9', 9), true, 'Conversation changed');
    assert.equal(await page.inputValue('textarea[name="reply"]'), 'Draft in another tab');
    assert.equal(await page.inputValue('form[data-action="reply"] input[name="state"]'), 'revision9');
    assert.equal(await page.textContent('[data-region="error"]'), 'Conversation changed');
});

test('a stale response that is already finished keeps a copyable draft', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Keep this draft');
    await page.click('form[data-action="reply"] button');
    await resolve(page, finished(), true, 'Already finished');
    assert.equal(await page.isVisible('[data-region="draft"]'), true);
    assert.equal(await page.inputValue('[data-region="draft"] textarea'), 'Keep this draft');
});

test('blank or whitespace-only replies do not make an AJAX call', async t => {
    const page = await pageFor(t, active());
    await page.click('form[data-action="reply"] button');
    await page.fill('textarea[name="reply"]', '   \n  ');
    await page.click('form[data-action="reply"] button');
    assert.equal(await page.evaluate(() => window.requests.length), 0);
});

test('initialization twice does not register duplicate listeners', async t => {
    const page = await pageFor(t);
    await page.evaluate(() => window.conversation.init('#masteryagent-app'));
    await page.click('button');
    assert.equal(await page.evaluate(() => window.requests.length), 1);
});

test('malformed AJAX payload leaves the existing form usable', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Do not lose this');
    await page.click('form[data-action="reply"] button');
    await page.evaluate(() => window.requests[0].resolve({unexpected: true}));
    await page.waitForFunction(() => !document.querySelector('[data-region="error"]').hidden);
    assert.equal(await page.inputValue('textarea[name="reply"]'), 'Do not lose this');
    assert.equal(await page.isDisabled('form[data-action="reply"] button'), false);
});

test('opening and closing final confirmation never submits or loses the draft', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Still writing');
    assert.equal(await page.isVisible('button[value="finish"]'), false);
    await page.click('summary');
    assert.equal(await page.isVisible('button[value="finish"]'), true);
    await page.click('summary');
    assert.equal(await page.evaluate(() => window.requests.length), 0);
    assert.equal(await page.inputValue('textarea[name="reply"]'), 'Still writing');
});

test('unsent text blocks final submission but a cleared reply allows explicit confirmation', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Still writing');
    await page.click('summary');
    await page.click('button[value="finish"]');
    assert.equal(await page.evaluate(() => window.requests.length), 0);
    assert.equal(await page.textContent('[data-region="error"]'), 'Send or clear your draft first');
    assert.equal(await page.inputValue('textarea[name="reply"]'), 'Still writing');
    await page.fill('textarea[name="reply"]', '');
    await page.click('button[value="finish"]');
    assert.equal(await page.evaluate(() => window.requests[0].request.args.confirmed), true);
    assert.equal(await page.evaluate(() => window.requests[0].request.args.action), 'finish');
    await resolve(page, finished());
});

test('save and leave posts the exact draft, pause action and revision without AJAX', async t => {
    for (const draft of ['', '  Unsent answer\nsecond line']) {
        const page = await pageFor(t, active());
        await page.evaluate(() => {
            document.querySelector('#masteryagent-app').addEventListener('submit', event => {
                window.pausePost = {blocked: event.defaultPrevented,
                    fields: Object.fromEntries(new FormData(event.target, event.submitter))};
                event.preventDefault();
            }, {once: true});
        });
        await page.fill('textarea[name="reply"]', draft);
        await page.click('button[value="pause"]');
        assert.deepEqual(await page.evaluate(() => window.pausePost), {
            blocked: false, fields: {state: 'revision1', confirmed: '1', reply: draft, action: 'pause'},
        });
        assert.equal(await page.evaluate(() => window.requests.length), 0);
    }
});

test('a pending reply blocks navigation through save and leave', async t => {
    const page = await pageFor(t, active());
    await page.fill('textarea[name="reply"]', 'Already sending');
    await page.click('button[value="reply"]');
    const blocked = await page.evaluate(() => {
        const event = new SubmitEvent('submit', {bubbles: true, cancelable: true,
            submitter: document.querySelector('button[value="pause"]')});
        document.querySelector('form').dispatchEvent(event);
        return event.defaultPrevented;
    });
    assert.equal(blocked, true);
    assert.equal(await page.evaluate(() => window.requests.length), 1);
    await resolve(page, active('revision2', 3));
});

test('an old form without a confirmation field cannot finalize an attempt', async t => {
    const page = await pageFor(t, active());
    await page.evaluate(() => document.querySelector('[name="confirmed"]').remove());
    await page.click('summary');
    await page.click('button[value="finish"]');
    assert.equal(await page.evaluate(() => window.requests.length), 0);
    assert.equal(await page.textContent('[data-region="error"]'), 'Confirm final submission');
});

test('browser history restores usable controls after save and leave', async t => {
    const page = await pageFor(t, active());
    await page.evaluate(() => {
        document.querySelector('#masteryagent-app').addEventListener('submit', event => event.preventDefault(), {once: true});
    });
    await page.fill('textarea[name="reply"]', 'Saved for later');
    await page.click('button[value="pause"]');
    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})));
    assert.equal(await page.textContent('[data-region="status"]'), '');
    await page.click('button[value="reply"]');
    assert.equal(await page.evaluate(() => window.requests.length), 1);
    await resolve(page, active('revision2', 3), true, 'Review latest conversation');
    assert.equal(await page.inputValue('textarea[name="reply"]'), 'Saved for later');
});
