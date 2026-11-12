/* A demo site (APP_SITE_MODE=demo, owner 2026-10-18) in a real browser.
 *
 * In Chromium: every page says it is a demo site, asks search engines not to list it (robots meta and
 * X-Robots-Tag) and says so without JavaScript too; checkout offers the simulated card and states that
 * the purchase is a simulation; the order placed by EFT says it is a simulation and shows fake bank
 * details labelled as fake, and its invoice says so and quotes the account reference; a sign-in email
 * starts with [DEMO] and says it comes from a demo site (Mailpit). ADMIN switches the site to live at
 * Administration > Settings > Site mode, where ADMIN also words the notice and can go back to the standard words: the notice, the robots rule and the simulated card are gone,
 * and the earlier order still says it was a simulation; Reset returns to APP_SITE_MODE. In Firefox the
 * notice and the checkout statement show as well. In the site's own theme (Saharan Sunset, the one bundled theme since
 * 2026-11-07; nothing is activated) at 1440, 820 and 390px the
 * notice shows on the home page, the catalogue, a course and the order, with one h1 and no sideways
 * scrolling.
 *
 * Run against the development instance with demo-site-fixture.php (catto.test is a demo site: env/.env
 * says APP_SITE_MODE=demo). catto.test runs in development (APP_ENV), so Symfony adds
 * X-Robots-Tag: noindex to every response by itself; the live check therefore looks for the absence of
 * the demo site's own "noindex, nofollow". Orders and their documents are immutable and stay behind. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
// Loaded and settled (fonts in, two frames), never half a second of network quiet: pages are drawn by the server.
const {open: arrive} = require('./pace.cjs');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://localhost:8025';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-demo-site-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-demo-site';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (...args) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/demo-site-fixture.php ${args[0]} ${statePath} ${args.slice(1).join(' ')} 2>/dev/null`], {encoding: 'utf8'}).trim().split('\n').pop();
const state = () => JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const {siteTheme, themeKey} = require('./site-theme.cjs');
const themes = [themeKey(siteTheme())];
const widths = (process.env.CATTO_WIDTHS || '1440,820,390').split(',').map(Number);
// The words ADMIN's Settings give, read when the run starts (owner, 2026-11-03: the notice is ADMIN's to word).
let NOTICE = '';
let HEADING = '';
let EMAIL = '';
const SIMULATION = 'Simulation on a demo site: this purchase is not real and nothing is being bought or sold.';
const results = [], failures = [];
const clean = (text) => text.replace(/\s+/g, ' ').trim();

async function check(name, run) {
    try {
        await run();
        results.push(name);
    } catch (error) {
        const actual = error.actual === undefined ? '' : ` (actual: ${JSON.stringify(error.actual).slice(0, 200)})`;
        failures.push(`${name}: ${error.message.split('\n')[0]}${actual}`);
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
const flash = async (page) => clean(await page.locator('[data-flash-message] > div').first().innerText());
const text = async (page) => clean(await page.locator('main').innerText());
const notice = async (page) => (await page.locator('[data-demo-site]').count()) === 1 ? clean(await page.locator('[data-demo-site]').innerText()) : '';
const robots = async (page) => page.locator('meta[name=robots]').count().then((n) => n ? page.locator('meta[name=robots]').getAttribute('content') : '');

/* A course to the payment step of checkout, and on to the review when a payment method is given. */
async function toReview(page, fixture, course, method) {
    await page.goto(`${base}/courses/${fixture.courses[course].slug}`);
    await submit(page, page.locator('form[action="/cart/add"] button[value="add"]'));
    await page.goto(`${base}/checkout/profile`);
    await submit(page, page.getByRole('button', {name: 'Continue to payment'}));
    const options = await page.locator('#payment-method option').evaluateAll((all) => all.map((o) => o.value));
    if (method) {
        await page.locator('#payment-method').selectOption(method);
        await submit(page, page.getByRole('button', {name: 'Review my order'}));
    }
    return options;
}

(async () => {
    php('create');
    const fixture = state();
    const chromium = await playwright.chromium.launch({headless: true});
    const firefox = await playwright.firefox.launch({headless: true});
    let order = '';
    try {
        {
            const admin = await open(chromium, fixture.admin_token);
            await admin.page.goto(`${base}/admin/settings?section=site#settings-site`);
            NOTICE = clean(await admin.page.locator('#demo-notice').inputValue());
            HEADING = clean(await admin.page.locator('#demo-heading').inputValue());
            EMAIL = clean(await admin.page.locator('#demo-email-notice').inputValue());
            await admin.ctx.close();
            assert.ok(NOTICE !== '', 'Settings gives the notice');
        }

        await check('chromium: every page says it is a demo site and asks search engines not to list it', async () => {
            const {ctx, page} = await open(chromium, null);
            for (const route of ['/', '/courses', `/courses/${fixture.courses.a.slug}`, '/login', '/terms']) {
                const response = await page.goto(`${base}${route}`);
                assert.equal(await notice(page), `${HEADING} ${NOTICE}`, `${route} says so`);
                assert.equal(await robots(page), 'noindex, nofollow', `${route} robots meta`);
                assert.equal(response.headers()['x-robots-tag'], 'noindex, nofollow', `${route} X-Robots-Tag`);
            }
            await ctx.close();
        });

        // Each payment method shows only what belongs to it (owner, 2026-11-12: "A person selecting Stitch to pay should only see
        // what is relevant to that payment method"), by the stylesheet alone, so the same with and without JavaScript.
        for (const [name, browser, js] of [['chromium', chromium, true], ['firefox', firefox, true], ['chromium without JavaScript', chromium, false]]) {
            await check(`${name}: checkout shows only the chosen payment method's details`, async () => {
                const {ctx, page} = await open(browser, fixture.learner.token, {js});
                const options = await toReview(page, fixture, 'a', null);
                assert.ok(options.length >= 2, 'more than one method to choose between');
                for (const method of options) {
                    await page.locator('#payment-method').selectOption(method);
                    const shown = await page.locator('[data-method-detail]').evaluateAll((all) => all.filter((detail) => getComputedStyle(detail).display !== 'none').map((detail) => detail.dataset.methodDetail));
                    assert.deepEqual(shown, [method], `with ${method} chosen, only its details show`);
                }
                await page.locator('#payment-method').selectOption('eft');
                assert.equal(await page.locator('#method-token').isVisible(), false, 'the simulated card outcome is hidden for EFT');
                await page.goto(`${base}/cart`);
                await submit(page, page.locator('form[action="/cart/remove"] button').first());
                await ctx.close();
            });
        }

        await check('chromium without JavaScript: the notice is plain HTML', async () => {
            const {ctx, page} = await open(chromium, fixture.learner.token, {js: false});
            await page.goto(`${base}/courses`);
            assert.equal(await notice(page), `${HEADING} ${NOTICE}`);
            await ctx.close();
        });

        await check('chromium: checkout offers the simulated card, says it is a simulation, and the order and its invoice say so with fake bank details', async () => {
            const {ctx, page} = await open(chromium, fixture.learner.token);
            const options = await toReview(page, fixture, 'b', 'eft');
            // Card is offered too once the owner ticks it (Stitch, 2026-11-11): the simulated card comes after EFT either way.
            assert.deepEqual(options.filter((method) => method !== 'card'), ['eft', 'dummy'], 'the simulated card is offered on a demo site, after EFT (Settings > Payment methods order)');
            assert.ok((await text(page)).includes(SIMULATION), 'the review says the purchase is a simulation');
            // Keep me informed (mailing, step 1; owner, 2026-10-26): News and the course bought, both unticked, kept when ticked. One
            // box covers the order's courses, "Send me updates about these courses" (owner, 2026-11-11), sent with the order's form.
            const keep = page.locator('.cl-keep-informed');
            assert.equal(await keep.locator('input[name=news]').isChecked(), false, 'News and Updates is not ticked for the buyer');
            const followBox = keep.locator('input[name=follow_courses]');
            assert.equal(await followBox.isChecked(), false, 'nor is the course bought');
            assert.equal(clean(await keep.locator('label:has(input[name=follow_courses]) .cl-ui-picture-choice-label').innerText()), 'Send me updates about this course', 'it says what it asks permission for');
            assert.deepEqual([await followBox.getAttribute('form'), await keep.locator('input[name=news]').getAttribute('form')], ['checkout-place', 'checkout-place'], 'both are sent with the order');
            assert.equal(await page.locator(`#checkout-place input[name="follow_course[]"][value="${fixture.courses.b.id}"]`).count(), 1, 'the course it covers goes with the order');
            await keep.locator('input[name=news]').check();
            await followBox.check();
            await page.locator('input[name=accept_terms]').check();
            await page.locator('input[name=accept_start]').check();
            await submit(page, page.getByRole('button', {name: 'Place my order'}));
            const shown = await text(page);
            assert.ok(shown.includes(SIMULATION), 'the order page says it is a simulation');
            assert.ok(shown.includes('These bank details are fake: this is a demo site. Do not pay.'), 'the bank details are labelled as fake');
            assert.ok(shown.includes('FAKE BANK (demo site, do not pay)') && shown.includes('0000000000 (fake)'), 'and are fake');
            assert.ok(shown.includes(`Use your account reference as the payment reference: ${fixture.learner.reference}.`), 'the EFT reference is the account reference');
            assert.deepEqual(JSON.parse(php('follows', fixture.learner.id)), [['course', fixture.courses.b.id, 'checkout'], ['news', null, 'checkout']], 'what they ticked is kept, given at checkout');
            const placed = JSON.parse(php('order', fixture.learner.id));
            // The order is named by its number in its address (owner, 2026-11-06: no row id in an address).
            order = `CL-${String(placed.id).padStart(8, '0')}`;
            assert.deepEqual([placed.simulation, placed.invoice_simulation, placed.invoice_fake_bank, placed.invoice_reference], [true, true, true, true], 'the order and its invoice are a simulation, with fake bank details and the account reference');
            await page.screenshot({path: path.join(output, 'chromium-order.png'), fullPage: true});
            await ctx.close();
        });

        await check('chromium: an email from a demo site starts with [DEMO] and says where it comes from', async () => {
            const {ctx, page} = await open(chromium, null);
            await page.goto(`${base}/login`);
            await page.locator('#email').fill(fixture.learner.email);
            await submit(page, page.getByRole('button', {name: 'Email my secure link'}));
            let message = null;
            for (let attempt = 0; attempt < 20 && !message; attempt++) {
                const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:"${fixture.learner.email}"`)}`)).json();
                if (found.messages?.length) message = await (await fetch(`${mailpit}/api/v1/message/${found.messages[0].ID}`)).json();
                else await page.waitForTimeout(500);
            }
            assert.ok(message, 'Mailpit received the sign-in email');
            assert.match(message.Subject, /^\[DEMO\] /, 'its subject starts with [DEMO]');
            assert.ok(clean(message.Text).startsWith(EMAIL), 'its first line says so');
            await ctx.close();
        });

        await check('firefox: the notice and the checkout statement', async () => {
            const {ctx, page} = await open(firefox, fixture.learner.token);
            await page.goto(`${base}/courses/${fixture.courses.a.slug}`);
            assert.equal(await notice(page), `${HEADING} ${NOTICE}`);
            await page.goto(`${base}/courses/${fixture.courses.a.slug}`);
            await submit(page, page.locator('form[action="/cart/add"] button[value="add"]'));
            await page.goto(`${base}/checkout/profile`);
            await submit(page, page.getByRole('button', {name: 'Continue to payment'}));
            await submit(page, page.getByRole('button', {name: 'Review my order'}));
            assert.ok((await text(page)).includes(SIMULATION));
            await page.goto(`${base}/cart`);
            await submit(page, page.locator('form[action="/cart/remove"] button').first());
            await ctx.close();
        });

        await check('chromium: ADMIN words the heading, notice and checkout and email messages, and they go back to the standard words', async () => {
            const admin = await open(chromium, fixture.admin_token);
            const settings = `${base}/admin/settings?section=site#settings-site`;
            const fields = {'#demo-heading': 'Rehearsal mode', '#demo-notice': 'Opening soon, and <this> stays plain text.', '#demo-email-notice': 'A rehearsal email from the site.', '#demo-simulation': 'A rehearsal order, with nothing bought.'};
            await admin.page.goto(settings);
            const before = {};
            for (const field of Object.keys(fields)) before[field] = await admin.page.locator(field).inputValue();
            const own = await admin.page.getByRole('button', {name: 'Back to the standard words'}).count() > 0;
            // The site's own words come back whatever happens (a step failing here once left the test words on catto.test): the
            // standard words, then the owner's own if there were any.
            try {
                for (const [field, words] of Object.entries(fields)) await admin.page.locator(field).fill(words);
                await submit(admin.page, admin.page.getByRole('button', {name: 'Save demo messages'}));
                assert.equal(await flash(admin.page), 'The demo messages are saved.');
                const visitor = await open(chromium, null);
                await visitor.page.goto(`${base}/courses`);
                assert.equal(await notice(visitor.page), `${fields['#demo-heading']} ${fields['#demo-notice']}`, 'a visitor reads them, as plain text');
                await visitor.page.goto(`${base}/login`);
                await visitor.page.locator('#email').fill(fixture.learner.email);
                await submit(visitor.page, visitor.page.getByRole('button', {name: 'Email my secure link'}));
                let message = null;
                for (let attempt = 0; attempt < 20 && !message; attempt++) {
                    const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:"${fixture.learner.email}"`)}`)).json();
                    const newest = found.messages?.length ? await (await fetch(`${mailpit}/api/v1/message/${found.messages[0].ID}`)).json() : null;
                    if (newest && clean(newest.Text).startsWith(fields['#demo-email-notice'])) message = newest;
                    else await visitor.page.waitForTimeout(500);
                }
                assert.ok(message, 'the email opens with the email message');
                await visitor.ctx.close();
                const learner = await open(chromium, fixture.learner.token);
                await learner.page.goto(`${base}/account/orders/${order}`);
                assert.ok((await text(learner.page)).includes(fields['#demo-simulation']), 'the order says the checkout message');
                await learner.ctx.close();
                await admin.page.goto(settings);
                for (const [field, words] of Object.entries(fields)) assert.equal(await admin.page.locator(field).inputValue(), words, `${field} shows what was saved`);
                await submit(admin.page, admin.page.getByRole('button', {name: 'Back to the standard words'}));
                assert.equal(await flash(admin.page), 'The demo messages are back to the standard words.');
                assert.equal(await admin.page.getByRole('button', {name: 'Back to the standard words'}).count(), 0, 'nothing is left to go back from');
            } finally {
                await admin.page.goto(settings);
                if (await admin.page.getByRole('button', {name: 'Back to the standard words'}).count() > 0) await submit(admin.page, admin.page.getByRole('button', {name: 'Back to the standard words'}));
                if (own) {
                    for (const field of Object.keys(fields)) await admin.page.locator(field).fill(before[field]);
                    await submit(admin.page, admin.page.getByRole('button', {name: 'Save demo messages'}));
                }
            }
            assert.equal(await notice(admin.page), `${HEADING} ${NOTICE}`, 'this site says what it said before');
            await admin.ctx.close();
        });

        await check('chromium: ADMIN makes the site live and the demo signs go, then Reset returns to APP_SITE_MODE', async () => {
            const admin = await open(chromium, fixture.admin_token);
            await admin.page.goto(`${base}/admin/settings?section=site#settings-site`);
            assert.ok((await text(admin.page)).includes('APP_SITE_MODE is not set in .env, so it is a demo site.') || (await text(admin.page)).includes('APP_SITE_MODE in .env sets it.'), 'the page says where the mode comes from');
            // catto.test goes back to following .env whatever happens: a step failing here must never leave it live.
            try {
                await admin.page.locator('#site-mode').selectOption('live');
                await submit(admin.page, admin.page.getByRole('button', {name: 'Save site mode'}));
                assert.equal(await flash(admin.page), 'This site is now live.');
                const visitor = await open(chromium, null);
                const response = await visitor.page.goto(`${base}/courses`);
                assert.equal(await notice(visitor.page), '', 'no demo notice on a live site');
                assert.notEqual(await robots(visitor.page), 'noindex, nofollow', 'no demo robots rule');
                assert.notEqual(response.headers()['x-robots-tag'], 'noindex, nofollow', 'no demo robots header');
                await visitor.ctx.close();
                const learner = await open(chromium, fixture.learner.token);
                assert.deepEqual(await toReview(learner.page, fixture, 'a', null), ['eft'], 'a live site offers no simulated card');
                await learner.page.goto(`${base}/account/orders/${order}`);
                assert.ok((await text(learner.page)).includes(SIMULATION), 'an order placed on the demo site stays a simulation');
                await learner.page.goto(`${base}/cart`);
                await submit(learner.page, learner.page.locator('form[action="/cart/remove"] button').first());
                await learner.ctx.close();
            } finally {
                await admin.page.goto(`${base}/admin/settings?section=site#settings-site`);
                await submit(admin.page, admin.page.getByRole('button', {name: 'Reset site mode to .env'}));
            }
            assert.equal(await flash(admin.page), 'The site mode now follows APP_SITE_MODE in .env.');
            assert.equal(await notice(admin.page), `${HEADING} ${NOTICE}`, 'catto.test is a demo site again');
            await admin.ctx.close();
        });

        /* The notice at every width, in the site's own theme: learners cannot preview a theme, and no check switches it. */
        const pages = ['/', '/courses', `/courses/${fixture.courses.a.slug}`, `/account/orders/${order}`];
        for (const key of themes) {
            for (const width of widths) {
                const {ctx, page} = await open(chromium, fixture.learner.token, {width});
                for (const route of pages) {
                    await check(`${key} ${width} ${route}`, async () => {
                        const response = await arrive(page, `${base}${route}`);
                        assert.equal(response.status(), 200);
                        assert.equal(await notice(page), `${HEADING} ${NOTICE}`, 'the notice');
                        const facts = await page.evaluate((k) => ({
                            served: [...document.querySelectorAll('link[rel=stylesheet]')].some((l) => l.href.includes(`/${k}/`)),
                            h1: document.querySelectorAll('h1').length,
                            sideways: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                        }), key);
                        assert.equal(facts.served, true, 'drawn in that theme');
                        assert.equal(facts.h1, 1, 'one h1');
                        assert.ok(facts.sideways <= 1, `no sideways scrolling (${facts.sideways}px)`);
                        if (width === 390) await page.screenshot({path: path.join(output, `${key}-${route.replace(/[^a-z0-9]+/g, '-')}-390.png`), fullPage: true});
                    });
                }
                await ctx.close();
            }
        }
    } finally {
        // Nothing to put back: since 2026-11-07 no theme is activated.
    }

    await chromium.close();
    await firefox.close();
    php('cleanup');
    console.log(results.length + failures.length + ' checks, ' + failures.length + ' failures.');
    for (const failure of failures) console.log('FAIL ' + failure);
    process.exit(failures.length ? 1 : 0);
})().catch((error) => {
    console.error(error);
    try { php('cleanup'); } catch (ignored) {}
    process.exit(1);
});
