/* A buyer's order links opened by someone else, and the error pages, in Chromium and Firefox (owner, 2026-10-23: as
 * ADMIN, a link in a buyer's email gave "403 Request failed This order belongs to another purchaser ... should it not
 * fail more gracefully? Also, I am logged in as ADMIN and therefore technically I should have authority to view the
 * order"):
 *   ADMIN opening the buyer's order lands on the same order in Administration, told whose it is, and the buyer's invoice
 *   link opens the same document there;
 *   the buyer still opens their own order;
 *   anyone else, and anyone opening an order that does not exist, finds no such order in their account (a 404, never
 *   "it belongs to another purchaser", which told a stranger the order exists, and never a 500);
 *   a missing page is a page built like every other (page head, card, what to do next) in plain words, and a signed-in
 *   reader refused a page keeps their header and identity band, with Go to your account and Sign out.
 *
 * Uses the financial documents fixture (tests/Browser/financial-documents-fixture.php create ... cleanup): its orders
 * are placed through the real checkout. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {inspect, judge} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-financial-documents-state.json';
const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const results = [], failures = [];
const order = state.orders.paid;
const invoice = state.documents.paid.invoice[0];
const clean = (text) => text.replace(/\s+/g, ' ').trim();

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${error.message.split('\n')[0]}`);
    }
}
async function as(browser, token) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    return {ctx, page};
}

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();
        let admin = 0;
        const adminToken = () => state.tokens.admin[(admin++) % state.tokens.admin.length];

        await scenario('ADMIN opening the buyer\'s order link lands on the same order in Administration', browserName, async () => {
            const {ctx, page} = await as(browser, adminToken());
            const response = await page.goto(`${base}/account/orders/${order}`);
            assert.equal(response.status(), 200);
            assert.equal(new URL(page.url()).pathname, `/admin/commerce/orders/${order}`, 'the order in Administration');
            assert.match(clean(await page.locator('[data-flash-message]').first().innerText()), new RegExp(`This is ${state.learner.name}'s order\\. It opens here in Administration, where you manage it\\.`));
            await ctx.close();
        });

        await scenario('ADMIN opening the buyer\'s invoice link gets the same invoice from Administration', browserName, async () => {
            const {ctx, page} = await as(browser, adminToken());
            const response = await page.request.get(`${base}/account/orders/${order}/documents/${invoice}`, {maxRedirects: 0});
            assert.ok([302, 303].includes(response.status()), `a redirect, not ${response.status()}`);
            assert.equal(new URL(response.headers().location, base).pathname, `/admin/commerce/orders/${order}/documents/${invoice}`);
            const pdf = await page.request.get(`${base}/account/orders/${order}/documents/${invoice}`);
            assert.equal(pdf.status(), 200);
            assert.match(pdf.headers()['content-type'], /application\/pdf/);
            await ctx.close();
        });

        await scenario('the buyer still opens their own order', browserName, async () => {
            const {ctx, page} = await as(browser, state.tokens.learner[0]);
            const response = await page.goto(`${base}/account/orders/${order}`);
            assert.equal(response.status(), 200);
            assert.equal(new URL(page.url()).pathname, `/account/orders/${order}`);
            await ctx.close();
        });

        await scenario('anyone else finds no such order in their account, and nothing says whose it is', browserName, async () => {
            const {ctx, page} = await as(browser, state.tokens.other);
            for (const address of [`/account/orders/${order}`, `/account/orders/${order}/documents/${invoice}`, '/account/orders/999999999']) {
                const response = await page.goto(base + address);
                assert.equal(response.status(), 404, address);
                const text = clean(await page.locator('main').innerText());
                assert.match(text, /We could not find that order in your account\./, address);
                assert.doesNotMatch(text, /another purchaser|belongs to/i, address);
            }
            await ctx.close();
        });

        await scenario('a signed-in reader refused a page keeps their header and band, with a way on', browserName, async () => {
            const {ctx, page} = await as(browser, state.tokens.other);
            const response = await page.goto(`${base}/admin`);
            assert.equal(response.status(), 403);
            assert.equal(clean(await page.locator('h1').innerText()), 'You can\'t open this page');
            assert.ok(await page.locator('.cl-identity-head').count(), 'the identity band');
            assert.match(clean(await page.locator('.cl-identity-head').innerText()), /Other Browser Learner/, 'naming the reader');
            assert.equal(await page.locator('main').getByRole('link', {name: 'Sign in'}).count(), 0, 'no Sign in for someone signed in');
            assert.ok(await page.getByRole('button', {name: 'Sign out'}).count(), 'Sign out, to use another account');
            assert.ok(await page.getByRole('link', {name: 'Go to your account'}).count());
            await ctx.close();
        });

        await scenario('a missing page is built like every other page, in plain words', browserName, async () => {
            for (const token of [state.tokens.other, null]) {
                const {ctx, page} = await as(browser, token);
                const response = await page.goto(`${base}/no-such-page-${Date.now()}`);
                assert.equal(response.status(), 404);
                assert.equal(clean(await page.locator('h1').innerText()), 'We could not find that page');
                assert.equal(await page.locator('h1').count(), 1, 'one main heading');
                assert.match(clean(await page.locator('main').innerText()), /The address may be mistyped, or the page has moved or been removed\./);
                assert.doesNotMatch(clean(await page.locator('main').innerText()), /No route found|Request failed/);
                assert.ok(await page.getByRole('link', {name: 'Go to the home page'}).count(), 'a way on');
                const problems = judge(await page.evaluate(inspect), '');
                assert.deepEqual(problems, [], 'the page construction checks pass');
                await ctx.close();
            }
        });
        await browser.close();
    }
    console.log(results.map((r) => `PASS ${r}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
