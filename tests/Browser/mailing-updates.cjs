/*
 * Updates, each person's choice and lists of ADMIN's own (owner, 2026-10-28: decisions 1A, 7A and 9A, round two 1A, 2A,
 * 4C and 5A). A learner follows a course for the first time and the Follow window asks how they would like their
 * updates; they choose "As they happen" and My Subscriptions shows it. An administrator writes an update for the course
 * on Mailing > Digest, where it is held and reaches one person, and releases it, ticked alone; its email reaches the
 * learner in Mailpit, saying why; its "View it in your browser" opens the learner's copy, and "Forward to a friend" sends
 * the friend one copy with the learner's note and "Never email me", which puts the friend's address on Addresses never
 * emailed. The administrator makes a list, switches its welcome email on and copies its join
 * link; the learner opens the link, joins, and gets the welcome email. On Subscribers the learner is among those who want
 * updates as they happen; their page shows their lists and history, and Take off this list leaves the others. Without
 * JavaScript the Follow link opens the same
 * question on a page and following works. In Chromium and Firefox at 1440 and 390px the pages answer, with one h1 and no
 * sideways scrolling.
 *
 * Run: CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/mailing-updates.cjs
 */
'use strict';

const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://localhost:8025';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-mailing-updates-state.json';

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/mailing-updates-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'}).trim().split('\n').pop();
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
        failures.push(`${name}: ${clean(error.message).slice(0, 400)}`);
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
const text = async (id) => (await (await fetch(`${mailpit}/api/v1/message/${id}`)).json()).Text || '';
const html = async (id) => (await (await fetch(`${mailpit}/api/v1/message/${id}`)).json()).HTML || '';
const friend = 'naledi.khumalo@inbox.test';

(async () => {
    php('create');
    const fixture = state();
    const course = fixture.course;
    const chromium = await playwright.chromium.launch();
    const firefox = await playwright.firefox.launch();
    const headline = `A new case study for ${course.title}`;
    let listId = '', joinUrl = '';
    try {
        const learner = await open(chromium, fixture.learner.token);
        const admin = await open(chromium, fixture.admin.token);

        await check('email goes around the clock for the check (Sending settings, When email goes)', async () => {
            await admin.page.goto(`${base}/mailing/settings#hours`);
            await admin.page.fill('#mr-from', '00:00');
            await admin.page.fill('#mr-until', '00:00');
            await submit(admin.page, admin.page.getByRole('button', {name: 'Save the sending discipline'}));
            assert.match(await admin.page.locator('#hours').innerText(), /around the clock|00:00/);
        });

        await check('the first Follow asks how the learner would like their updates, weekly chosen', async () => {
            await learner.page.goto(`${base}/courses/${course.slug}`);
            await learner.page.locator('#follow a', {hasText: 'Follow'}).first().click();
            const window = learner.page.locator(`#follow-how-course-${course.id}`);
            await window.waitFor({state: 'visible'});
            assert.ok(await window.locator('input[name="frequency"][value="weekly"]').isChecked(), 'weekly is chosen');
            assert.ok(await window.locator('input[name="delivery"][value="combined"]').isChecked(), 'one digest is chosen');
            await window.locator('input[name="delivery"][value="instant"]').check();
            await submit(learner.page, window.getByRole('button', {name: `Follow ${course.title}`}));
            assert.match(await flash(learner.page), /You follow it now/);
            await learner.page.goto(`${base}/account/subscriptions`);
            assert.ok(await learner.page.locator('#how input[name="delivery"][value="instant"]').isChecked(), 'My Subscriptions shows the choice');
            assert.match(await learner.page.locator('#following').innerText(), new RegExp(course.title));
        });

        let updateId = '', updateMail = '';
        await check('an update written for the course is held and reaches one person', async () => {
            await admin.page.goto(`${base}/mailing/digest`);
            await admin.page.selectOption('#update-for', `course:${course.id}`);
            await admin.page.fill('#update-headline', headline);
            await admin.page.fill('#update-body', 'Worked through step by step, with the figures to try yourself.');
            await admin.page.fill('#update-link', `${base}/courses/${course.slug}`);
            await submit(admin.page, admin.page.getByRole('button', {name: 'Hold this update'}));
            assert.match(await flash(admin.page), /held with the others/);
            updateId = (admin.page.url().match(/#update-(\d+)/) || [])[1] || '';
            assert.ok(updateId, 'its row is shown');
            assert.match(await admin.page.locator(`#update-${updateId}`).innerText(), /Reaches 1 person/i);
        });

        await check('released alone and now, it reaches the learner as it happens, saying why', async () => {
            await admin.page.goto(`${base}/mailing/digest#release`);
            for (const box of await admin.page.locator('input[name="updates[]"]').all()) await box.uncheck();
            await admin.page.locator(`input[name="updates[]"][value="${updateId}"]`).check();
            await submit(admin.page, admin.page.getByRole('button', {name: 'Release the ticked updates'}));
            assert.match(await flash(admin.page), /Released 1 update/);
            const message = await mail(`to:${fixture.learner.email}`, (m) => (m.Subject || '').includes(headline));
            assert.ok(message, 'the update email arrived');
            const body = await text(message.ID);
            assert.match(body, new RegExp(`Because you follow ${course.title}`));
            assert.match(body, /Show my subscriptions/);
            await admin.page.goto(`${base}/mailing/digest#released`);
            assert.match(await admin.page.locator('#released').innerText(), new RegExp(headline));
            updateMail = message.ID;
        });

        await check('the email\'s web copy is the learner\'s copy, and Forward to a friend sends the friend one copy', async () => {
            const page = await html(updateMail);
            const web = (page.match(/href="([^"]*\/m\/v\/[0-9a-f]{32})"/) || [])[1];
            const forward = (page.match(/href="([^"]*\/m\/f\/[0-9a-f]{32})"/) || [])[1];
            assert.ok(web && forward, 'both links are in the email');
            const view = await open(chromium, '');
            await view.page.goto(web.replace(/&amp;/g, '&'));
            assert.match(await view.page.content(), new RegExp(headline));
            await view.page.goto(forward.replace(/&amp;/g, '&'));
            await view.page.fill('#fw-email', friend);
            await view.page.fill('#fw-name', 'Naledi');
            await view.page.fill('#fw-note', 'This one made me think of you.');
            await submit(view.page, view.page.getByRole('button', {name: 'Forward it'}));
            assert.match(clean(await view.page.locator('#forward').innerText()), new RegExp(`on its way to ${friend}`));
            const sent = await mail(`to:${friend}`, (m) => (m.Subject || '').includes(headline));
            assert.ok(sent, 'the friend got it');
            const body = await text(sent.ID);
            assert.match(body, /thought you would like this email/);
            assert.match(body, /This one made me think of you\./);
            assert.match(body, /You are not on any of our lists/);
            assert.doesNotMatch(body, /Unsubscribe from/, 'the learner\'s own links are not in it');
            const never = ((await html(sent.ID)).match(/href="([^"]*\/m\/n\/[0-9a-f]{32})"/) || [])[1];
            await view.page.goto(never.replace(/&amp;/g, '&'));
            await submit(view.page, view.page.getByRole('button', {name: 'Never email me'}));
            assert.match(await view.page.locator('h1').innerText(), /We will never email you/);
            await view.ctx.close();
        });

        await check('Automatic emails rewords the digests, and Reports counts what was sent', async () => {
            await admin.page.goto(`${base}/mailing/automatic#digest`);
            assert.match(await admin.page.locator('#every').innerText(), /Do you still want our news\?/);
            await admin.page.fill('#ae-digest', 'Hello [First name],\n\nHere is your news from us.');
            await submit(admin.page, admin.page.locator('#digest').getByRole('button', {name: 'Save the words'}));
            assert.match(await flash(admin.page), /next ones sent use these words/);
            assert.equal(await admin.page.locator('#ae-digest').inputValue(), 'Hello [First name],\n\nHere is your news from us.');
            await submit(admin.page, admin.page.locator('#digest').getByRole('button', {name: 'Back to the starting words'}));
            assert.match(await admin.page.locator('#ae-digest').inputValue(), /since your last digest/);
            await admin.page.goto(`${base}/mailing/reports?period=today`);
            assert.match(clean(await admin.page.locator('#figures').innerText()), /Emails sent/i);
            assert.match(clean(await admin.page.locator('#kinds').innerText()), /Updates as they happen/);
        });

        await check('catto-mail: its address is kept, Connect goes to its connect page with a PKCE challenge, and a stray answer changes nothing', async () => {
            await admin.page.goto(`${base}/mailing/cattomail`);
            assert.match(clean(await admin.page.locator('#setup').innerText()), /of 4 steps done/);
            if (!(await admin.page.locator('#address').evaluate((el) => el.open))) await admin.page.locator('#address summary').click();
            await admin.page.fill('#cm-address', 'https://mail.example.test');
            await submit(admin.page, admin.page.getByRole('button', {name: 'Keep this address'}));
            assert.match(await flash(admin.page), /address is kept/);
            // The made-up catto-mail cannot be opened: the Connect button's answer is read instead of followed.
            const csrf = await admin.page.locator('#connect input[name="csrf"]').inputValue();
            const answer = await admin.page.request.post(`${base}/mailing/cattomail/connect`, {form: {csrf}, maxRedirects: 0});
            assert.equal(answer.status(), 303);
            const url = new URL(answer.headers().location);
            assert.equal(url.origin, 'https://mail.example.test');
            assert.equal(url.pathname, '/dashboard/connect');
            assert.equal(url.searchParams.get('code_challenge_method'), 'S256');
            assert.match(url.searchParams.get('code_challenge') || '', /^[A-Za-z0-9_-]{43}$/);
            assert.match(url.searchParams.get('redirect_uri') || '', /\/mailing\/cattomail\/callback$/);
            assert.match(url.searchParams.get('webhook_url') || '', /\/webhooks\/cattomail$/);
            await admin.page.goto(`${base}/mailing/cattomail/callback?code=forged&state=not-the-one`);
            assert.match(await flash(admin.page), /does not belong to a Connect pressed here/);
        });

        await check('a list is made and its welcome email switched on', async () => {
            await admin.page.goto(`${base}/mailing/lists#make`);
            await admin.page.fill('#list-name', 'Pricing case studies');
            await admin.page.fill('#list-words', 'A new worked example each month.');
            await submit(admin.page, admin.page.getByRole('button', {name: 'Make the list'}));
            listId = (admin.page.url().match(/\/mailing\/lists\/(\d+)/) || [])[1] || '';
            assert.ok(listId, 'its page opens');
            await admin.page.locator('#welcome input[name="on"][value="1"]').check();
            await submit(admin.page, admin.page.locator('#welcome').getByRole('button', {name: 'Save'}));
            assert.match(await flash(admin.page), /welcome email is on/);
            joinUrl = clean(await admin.page.locator('#join code').innerText());
            assert.match(joinUrl, /\/join\/[0-9a-f]{32}$/);
        });

        await check('the learner joins with the join link and gets the welcome email once', async () => {
            await learner.page.goto(joinUrl);
            await submit(learner.page, learner.page.getByRole('button', {name: 'Join this list'}));
            assert.match(await flash(learner.page), /You joined Pricing case studies/);
            assert.match(await learner.page.locator('#lists').innerText(), /You are on it/i);
            const message = await mail(`to:${fixture.learner.email}`, (m) => (m.Subject || '').includes('Welcome to Pricing case studies'));
            assert.ok(message, 'the welcome email arrived');
        });

        await check('Subscribers finds the learner among those who want updates as they happen, and takes them off one list', async () => {
            await admin.page.goto(`${base}/mailing/subscribers?how=instant&subscribers_q=${encodeURIComponent(fixture.learner.email)}`);
            const row = admin.page.locator('table tbody tr', {hasText: fixture.learner.email});
            assert.equal(await row.count(), 1, 'the learner is listed');
            assert.match(await row.innerText(), /As they happen/);
            await submit(admin.page, row.locator('a').first());
            assert.match(admin.page.url(), new RegExp(`/mailing/subscribers/${fixture.learner.id}$`));
            const lists = admin.page.locator('#lists');
            assert.match(await lists.innerText(), /Pricing case studies/);
            assert.match(await lists.innerText(), new RegExp(course.title));
            await submit(admin.page, lists.locator('article', {hasText: 'Pricing case studies'}).getByRole('button', {name: 'Take off this list'}));
            assert.match(await flash(admin.page), /off that list/);
            assert.doesNotMatch(await admin.page.locator('#lists').innerText(), /Pricing case studies/);
            assert.match(await admin.page.locator('#lists').innerText(), new RegExp(course.title), 'the other lists stay');
            assert.match(await admin.page.locator('#history').innerText(), /Left Pricing case studies: taken off by an administrator/);
        });

        await check('without JavaScript the first Follow asks how on a page, and following works', async () => {
            const plain = await open(chromium, fixture.admin.token, {js: false});
            await plain.page.goto(`${base}/courses/${course.slug}`);
            await Promise.all([plain.page.waitForNavigation(), plain.page.locator('#follow a', {hasText: 'Follow'}).first().click()]);
            assert.match(plain.page.url(), new RegExp(`/follow/course/${course.id}`));
            assert.ok(await plain.page.locator('input[name="frequency"][value="weekly"]').isChecked());
            await submit(plain.page, plain.page.getByRole('button', {name: `Follow ${course.title}`}));
            assert.match(await flash(plain.page), /You follow it now/);
            await plain.ctx.close();
        });

        await check('a template starts from a sample design, and each colour is a hex code whose swatch follows as it is typed', async () => {
            await admin.page.goto(`${base}/mailing/templates#new`);
            assert.equal(await admin.page.locator('#template-samples input[name="sample"]').count(), 7, 'six samples and the default');
            await admin.page.locator('#template-samples input[name="sample"][value="noir"]').check();
            await submit(admin.page, admin.page.getByRole('button', {name: 'Make the template'}));
            assert.match(await flash(admin.page), /Gold on black sample/);
            assert.equal(await admin.page.locator('input[type="color"]').count(), 0, 'no colour picker');
            const field = admin.page.locator('#design-colour-header');
            assert.equal(await field.getAttribute('type'), 'text');
            assert.equal((await field.inputValue()).toLowerCase(), '#0f0f10');
            const swatch = admin.page.locator('#design-colour-header').locator('xpath=..').locator('rect');
            assert.equal((await swatch.getAttribute('fill')).toLowerCase(), '#0f0f10');
            await field.fill('#1f5f6b');
            assert.equal(await swatch.getAttribute('fill'), '#1f5f6b', 'the swatch follows without submitting');
            await field.fill('#1f5');
            assert.equal(await swatch.getAttribute('fill'), 'none', 'a half-typed code shows no colour');
        });

        await check('Addresses never emailed shows the matching addresses as an address is typed, and one is chosen', async () => {
            await admin.page.goto(`${base}/mailing/never-emailed`);
            await admin.page.locator('#sx-email').pressSequentially(fixture.learner.email.slice(0, 8), {delay: 40});
            const match = admin.page.locator('#sx-matches [data-fill-value]', {hasText: fixture.learner.email});
            await match.waitFor({state: 'visible', timeout: 5000});
            await match.click();
            assert.equal(await admin.page.locator('#sx-email').inputValue(), fixture.learner.email);
            assert.equal(await admin.page.locator('#sx-matches [data-fill-value]').count(), 0, 'the list closes');
        });

        await check('Subscribers shows a grid of cards, and a card\'s Actions menu stops all email to the address', async () => {
            await admin.page.goto(`${base}/mailing/subscribers?subscribers_view=grid&subscribers_q=${encodeURIComponent(fixture.learner.email)}`);
            const card = admin.page.locator('.cl-ui-card', {hasText: fixture.learner.email});
            assert.equal(await card.count(), 1, 'the learner as a card');
            await card.locator('summary.cl-ui-row-trigger').click();
            await submit(admin.page, card.getByRole('button', {name: 'Never email this address'}));
            assert.match(await flash(admin.page), /will never be emailed news/);
            assert.match(admin.page.url(), /\/mailing\/subscribers\?/, 'back on the list');
            await admin.page.goto(`${base}/mailing/never-emailed?suppressions_q=${encodeURIComponent(fixture.learner.email)}`);
            assert.match(await admin.page.locator('table').innerText(), new RegExp(fixture.learner.email.replace('.', '\\.')));
            await admin.page.goto(`${base}/mailing/subscribers?subscribers_view=list`);
        });

        await check('the pages answer at 1440 and 390px in Chromium and Firefox, with one h1 and no sideways scrolling', async () => {
            const routes = ['/mailing/digest', '/mailing/lists', `/mailing/lists/${listId}`, '/mailing/subscribers', `/mailing/subscribers/${fixture.learner.id}`, '/mailing/settings', '/mailing/automatic', '/mailing/reports', '/mailing/reports?period=all', '/mailing/cattomail', '/account/subscriptions', joinUrl.replace(base, '')];
            for (const browser of [chromium, firefox]) {
                for (const width of [1440, 390]) {
                    const view = await open(browser, fixture.admin.token, {width});
                    for (const route of routes) {
                        const response = await view.page.goto(`${base}${route}`);
                        assert.equal(response.status(), 200, route);
                        assert.equal(await view.page.locator('h1').count(), 1, `${route} has one h1`);
                        const wide = await view.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
                        assert.ok(wide <= 1, `${route} scrolls sideways by ${wide}px at ${width}`);
                    }
                    await view.ctx.close();
                }
            }
        });
    } finally {
        await chromium.close();
        await firefox.close();
        php('cleanup');
    }
    console.log(`${results.length} passed, ${failures.length} failed`);
    for (const failure of failures) console.log(`FAIL ${failure}`);
    process.exit(failures.length === 0 ? 0 : 1);
})();
