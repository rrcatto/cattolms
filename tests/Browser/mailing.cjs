/*
 * Mailing, step 2 (owner, 2026-10-26): a learner follows News and Updates on My Subscriptions; an administrator writes
 * an email in blocks (text, a button, a course, HTML), sees it in the preview, sends a test to their own address and
 * sends it; the learner's copy reaches Mailpit with the one-click unsubscribe headers (RFC 8058) and List-Id, its links
 * go through the click address and its opening pixel is recorded; the email app's one-click button stops News at once,
 * and the unsubscribe page then says so; the message's figures show the opening, the click and the unsubscribe. Settings > Mailing
 * saves its sending discipline as shown. Without JavaScript the campaign page and its forms work too. In Chromium and
 * Firefox at 1440 and 390px the mailing pages answer, with one h1 and no sideways scrolling.
 *
 * Run: CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/mailing.cjs
 */
'use strict';

const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://localhost:8025';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-mailing-state.json';

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/mailing-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'}).trim().split('\n').pop();
const runJob = () => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', 'SHELL_VERBOSITY=-1 php bin/console schedule:run --job mailing >/dev/null 2>&1 || true'], {encoding: 'utf8'});
const state = () => JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const results = [], failures = [];
const clean = (text) => text.replace(/\s+/g, ' ').trim();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function check(name, run) {
    try {
        await run();
        results.push(name);
    } catch (error) {
        failures.push(`${name}: ${error.message.split('\n')[0]}`);
    }
}
async function open(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: 1100}, javaScriptEnabled: options.js !== false, reducedMotion: 'reduce'});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const submit = async (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
const flash = async (page) => clean(await page.locator('[data-flash-message] > div').first().innerText().catch(() => ''));
async function mail(query, keep) {
    for (let i = 0; i < 30; i++) {
        const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(query)}&limit=10`)).json();
        const message = (found.messages || []).find(keep);
        if (message) return message;
        runJob();
        await sleep(2000);
    }
    return null;
}

(async () => {
    php('create');
    const fixture = state();
    const chromium = await playwright.chromium.launch();
    const firefox = await playwright.firefox.launch();
    let campaign = '';
    try {
        const learner = await open(chromium, fixture.learner.token);
        const admin = await open(chromium, fixture.admin.token);

        // Email waits for the sending hours (owner, 2026-10-28, round two 6A): the check sends around the clock, and the
        // fixture puts the hours back afterwards.
        await admin.page.goto(`${base}/mailing/settings#hours`);
        await admin.page.fill('#mr-from', '00:00');
        await admin.page.fill('#mr-until', '00:00');
        await submit(admin.page, admin.page.getByRole('button', {name: 'Save the sending discipline'}));

        await check('the learner asks for News and Updates on My Subscriptions', async () => {
            await learner.page.goto(`${base}/account/subscriptions`);
            await learner.page.locator('#news input[name=news]').check();
            await submit(learner.page, learner.page.locator('#news button[type=submit]'));
            assert.match(await flash(learner.page), /You will get News and Updates by email/);
        });

        await check('an administrator writes an email in blocks and sees it in the preview', async () => {
            const page = admin.page;
            await page.goto(`${base}/mailing/messages`);
            await page.fill('#campaign-name', fixture.campaign);
            await submit(page, page.getByRole('button', {name: 'Write the email'}));
            campaign = page.url().match(/messages\/(\d+)/)[1];
            await page.fill('#mc-subject', 'News for [First name]');
            await submit(page, page.getByRole('button', {name: 'Save the details'}));
            const text = page.locator('details[id^=block-]').nth(1);
            await text.locator('summary').click();
            // The text block is written in the editor (owner, 2026-10-28, decision 6A): bold, italics, links and lists.
            const editable = text.locator('.ck-editor__editable');
            await editable.waitFor();
            await editable.click();
            await page.keyboard.press('Control+A');
            await page.keyboard.type(`Hello [First name],`);
            await page.keyboard.press('Enter');
            await page.keyboard.type(`Our courses are at ${base}/courses `);
            await submit(page, text.getByRole('button', {name: 'Save this block'}));
            for (const [type, fill] of [['button', async (b) => { await b.locator('input[name=label]').fill('See the courses'); await b.locator('input[name=href]').fill(`${base}/courses`); }], ['course', async (b) => b.locator('select[name=course_id]').selectOption({index: 1})], ['html', async (b) => b.locator('textarea[name=html]').fill(`<p style="color:#333">Our <a href="${base}/help">help</a>.</p>`)]]) {
                await page.locator(`.cl-mailing-add input[name=type][value=${type}]`).check({force: true});
                await submit(page, page.getByRole('button', {name: 'Add the block'}));
                const opened = page.locator('details[open][id^=block-]');
                await fill(opened);
                await submit(page, opened.getByRole('button', {name: 'Save this block'}));
                assert.match(await flash(page), /The block was saved/, type);
            }
            const preview = await (await page.request.get(`${base}/mailing/messages/${campaign}/preview`)).text();
            for (const part of [`Hello ${fixture.admin.first}`, 'See the courses', 'View the course', 'help</a>', 'Unsubscribe from News and Updates']) assert.ok(preview.includes(part), `the preview shows ${part}`);
            assert.equal(await page.locator('iframe.cl-mailing-preview').count(), 1, 'the preview sits on the page');
        });

        await check('a proof goes to the address typed, the administrator\'s own to start, and the email is sent', async () => {
            const page = admin.page;
            assert.equal(await page.inputValue('#mc-proof-to'), fixture.admin.email, 'the proof is addressed to the writer to start');
            await submit(page, page.getByRole('button', {name: 'Send the proof'}));
            assert.match(await flash(page), /A proof was sent to/);
            assert.ok(await mail(`to:${fixture.admin.email}`, (m) => m.Subject.includes('[Proof]')), 'the proof reaches Mailpit');
            await submit(page, page.getByRole('button', {name: 'Send', exact: true}));
            assert.match(await flash(page), /It is on its way/);
        });

        let copy = null;
        await check('the learner\'s copy carries the one-click unsubscribe headers and tracked links', async () => {
            const message = await mail(`to:${fixture.learner.email}`, (m) => m.Subject.includes(`News for ${fixture.learner.first}`));
            assert.ok(message, 'the copy reaches Mailpit');
            const full = await (await fetch(`${mailpit}/api/v1/message/${message.ID}`)).json();
            const headers = await (await fetch(`${mailpit}/api/v1/message/${message.ID}/headers`)).json();
            copy = {html: full.HTML, stop: (headers['List-Unsubscribe'] || [''])[0].replace(/[<>]/g, '')};
            assert.match(copy.stop, /\/m\/u\/[0-9a-f]{32}$/);
            assert.equal((headers['List-Unsubscribe-Post'] || [''])[0], 'List-Unsubscribe=One-Click');
            assert.match((headers['List-Id'] || [''])[0], /^"News and Updates" <list-\d+\./);
            assert.match(copy.html, /\/m\/c\/[0-9a-f]{32}\/\d+/, 'links go through the click address');
            assert.match(copy.html, /\/m\/o\/[0-9a-f]{32}\.gif/, 'the opening pixel is there');
            assert.match(full.Text, /Unsubscribe from News and Updates: http/, 'the plain-text copy has the unsubscribe link');
        });

        await check('opening and clicking are recorded, and the email app\'s one-click button stops News at once', async () => {
            const request = (await open(chromium, null)).ctx.request;
            assert.equal((await request.get(copy.html.match(/https?:\/\/[^"]+\/m\/o\/[0-9a-f]{32}\.gif/)[0])).status(), 200);
            const click = await request.get(copy.html.match(/https?:\/\/[^"]+\/m\/c\/[0-9a-f]{32}\/\d+/)[0], {maxRedirects: 0});
            assert.equal(click.status(), 302);
            assert.equal((await request.post(copy.stop, {form: {'List-Unsubscribe': 'One-Click'}})).status(), 200);
            const recorded = JSON.parse(php('state'));
            assert.deepEqual([recorded.opens, recorded.clicks, recorded.stopped], [1, 1, true]);
            await learner.page.goto(`${base}/account/subscriptions`);
            assert.equal(await learner.page.locator('#news input[name=news]').isChecked(), false, 'News is no longer ticked');
            const stopPage = (await open(firefox, null)).page;
            await stopPage.goto(copy.stop);
            assert.match(clean(await stopPage.locator('main').innerText()), /You will get no more emails about News and Updates/, 'the unsubscribe page says it is stopped');
        });

        await check('the message\'s figures show the opening, the click and the unsubscribe', async () => {
            await admin.page.goto(`${base}/mailing/messages/${campaign}`);
            const figures = clean(await admin.page.locator('#summary').innerText());
            for (const part of ['1 OPENED', '1 CLICKED', '1 UNSUBSCRIBED']) assert.ok(figures.toUpperCase().includes(part), part + ' in ' + figures);
            assert.ok((await admin.page.locator('#copies tbody tr').count()) >= 1, 'the copies are listed');
            assert.ok((await admin.page.locator('#links tbody tr').count()) >= 1, 'the links are listed');
        });

        await check('Settings, Mailing saves its sending discipline as shown', async () => {
            const page = admin.page;
            await page.goto(`${base}/mailing/settings`);
            await submit(page, page.getByRole('button', {name: 'Save the sending discipline'}));
            assert.match(await flash(page), /The sending discipline was saved/);
            await submit(page, page.getByRole('button', {name: 'Reset the sending discipline'}));
            assert.match(await flash(page), /back to its starting values/);
        });

        await check('without JavaScript the campaign page and its forms work', async () => {
            const {ctx, page} = await open(firefox, fixture.admin.token, {js: false});
            await page.goto(`${base}/mailing/messages`);
            await page.fill('#campaign-name', 'Course announcements');
            await submit(page, page.getByRole('button', {name: 'Write the email'}));
            await page.locator('.cl-mailing-add input[name=type][value=divider]').check({force: true});
            await submit(page, page.getByRole('button', {name: 'Add the block'}));
            assert.match(await flash(page), /The block was added/);
            await ctx.close();
        });

        for (const [name, browser] of [['chromium', chromium], ['firefox', firefox]]) {
            for (const width of [1440, 390]) {
                await check(`${name} ${width}: the mailing pages answer with one h1 and no sideways scrolling`, async () => {
                    const {ctx, page} = await open(browser, fixture.admin.token, {width});
                    for (const route of ['/mailing/messages', `/mailing/messages/${campaign}`, '/mailing/lists', '/mailing/never-emailed', '/account/subscriptions']) {
                        const response = await page.goto(base + route);
                        assert.equal(response.status(), 200, route);
                        const facts = await page.evaluate(() => ({h1: document.querySelectorAll('h1').length, sideways: document.documentElement.scrollWidth - document.documentElement.clientWidth}));
                        assert.equal(facts.h1, 1, route + ': one h1');
                        assert.ok(facts.sideways <= 1, `${route}: no sideways scrolling (${facts.sideways}px)`);
                    }
                    await ctx.close();
                });
            }
        }
    } finally {
        await chromium.close();
        await firefox.close();
        php('cleanup');
    }
    console.log(results.length + failures.length + ' checks, ' + failures.length + ' failures.');
    for (const failure of failures) console.log('FAIL ' + failure);
    process.exit(failures.length ? 1 : 0);
})().catch((error) => {
    console.error(error);
    try { php('cleanup'); } catch (ignored) {}
    process.exit(1);
});
