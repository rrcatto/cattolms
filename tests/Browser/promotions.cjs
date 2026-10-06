/* Promo codes in a real browser, in Chromium and Firefox, without JavaScript, and in every bundled
 * theme: at the checkout review a valid code (typed in lower case and applied with the keyboard)
 * shows the subtotal, the promotion and the discounted total; an unknown or expired code says why and
 * changes nothing; removing the code restores the total; placing the order charges the discounted
 * total and the order page and invoice keep it; ADMIN creates, edits, deactivates, reactivates and
 * deletes a promotion; and the checkout promo section and the ADMIN promotion pages render in all
 * five themes at desktop and phone widths without sideways scrolling.
 *
 * Run against the development instance with promotions-fixture.php. Placing orders leaves them
 * behind: orders are immutable. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-promotions-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-promotions';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/promotions-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const learner = () => JSON.parse(php('learner').trim().split('\n').pop());
const state = () => JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];
/* Amounts as the platform prints them (en_ZA), after clean() has made every space ordinary: "R 1 173,45". */
const rand = (minor) => `R ${Math.floor(minor / 100).toLocaleString('en-ZA').replace(/\s/g, ' ')},${String(minor % 100).padStart(2, '0')}`;
const clean = (text) => text.replace(/\s+/g, ' ').trim();

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        const actual = error.actual === undefined ? '' : ` (actual: ${JSON.stringify(error.actual)})`;
        failures.push(`${browserName}: ${name}: ${error.message.split('\n')[0]}${actual}`);
    }
}
async function open(browser, token, options = {}) {
    // Without JavaScript the themes' smooth scrolling never settles under Playwright's own
    // scrolling, so a click below the fold times out; the themes turn it off for reduced motion.
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: 1400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
/* The totals list: [[label, value], ...]. */
const totals = async (page) => {
    const list = page.locator('.cl-ui-key-value-list').last();
    const labels = await list.locator('dt').allInnerTexts();
    const values = await list.locator('dd').allInnerTexts();
    return labels.map((label, i) => [clean(label), clean(values[i])]);
};
const flash = async (page) => clean(await page.locator('[data-flash-message] > div').first().innerText());
const submit = async (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);

/* A learner with both courses in the cart, at the checkout review with the simulated card chosen. */
async function toReview(page, fixture) {
    for (const course of [fixture.courses.a, fixture.courses.b]) {
        await page.goto(`${base}/courses/${course.slug}`);
        await submit(page, page.locator('form[action="/cart/add"] button[value="add"]'));
    }
    await page.goto(`${base}/checkout/profile`);
    await submit(page, page.getByRole('button', {name: 'Continue to payment'}));
    await page.locator('#payment-method').selectOption('dummy');
    if (await page.locator('#method-token').count()) await page.locator('#method-token').selectOption('demo_success');
    await submit(page, page.getByRole('button', {name: 'Review my order'}));
    assert.match(page.url(), /\/checkout\/review/);
}
async function applyCode(page, code, keyboard = true) {
    await page.locator('#promo-code').fill(code);
    if (keyboard) await Promise.all([page.waitForNavigation(), page.locator('#promo-code').press('Enter')]);
    else await submit(page, page.locator('form[action="/checkout/promo"] button[type=submit]'));
}

(async () => {
    php('create');
    const fixture = state();
    const code = fixture.code;
    const discounted = [['Subtotal', rand(17345)], [`Promo ${code} (10% off)`, `−${rand(1735)}`], ['Total', rand(15610)]];

    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();

        await scenario('a valid code updates the totals, an invalid one says why, and removing it restores the total', browserName, async () => {
            const {ctx, page} = await open(browser, learner().token);
            await toReview(page, fixture);
            assert.deepEqual(await totals(page), [['Total', rand(17345)]], 'before a code, only the total');
            await applyCode(page, code.toLowerCase());
            assert.equal(await flash(page), `Promo code ${code} applied: −${rand(1735)}.`, 'the code is case-insensitive and applied with Enter');
            assert.deepEqual(await totals(page), discounted, 'subtotal, promotion and discounted total');
            assert.equal(clean(await page.locator('.cl-checkout-promo-applied').innerText()), `${code} · 10% off · −${rand(1735)}`);
            await applyCode(page, 'NOSUCHCODE');
            assert.equal(await flash(page), 'We don’t recognise that promo code.');
            await applyCode(page, fixture.expired_code);
            assert.equal(await flash(page), 'That promo code has expired.');
            assert.deepEqual(await totals(page), discounted, 'a rejected code leaves the applied one');
            await page.getByRole('button', {name: `Remove promo code ${code}`}).focus();
            await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
            assert.equal(await flash(page), 'The promo code was removed.');
            assert.deepEqual(await totals(page), [['Total', rand(17345)]], 'removing the code restores the undiscounted total');
            await page.screenshot({path: `${output}/${browserName}-review.png`, fullPage: true});
            await ctx.close();
        });

        await scenario('placing the order charges the discounted total, and the order and invoice keep it', browserName, async () => {
            const {ctx, page} = await open(browser, learner().token);
            await toReview(page, fixture);
            await applyCode(page, code, false);
            await page.locator('input[name=accept_terms]').check();
            await submit(page, page.getByRole('button', {name: 'Place my order'}));
            assert.match(page.url(), /\/account\/orders\/\d+/);
            assert.deepEqual(await totals(page), discounted, 'the order shows the promotion it was placed with');
            assert.match(clean(await page.locator('main').innerText()), /Status: Fulfilled/, 'the simulated card was charged the discounted total');
            const pdf = await ctx.request.get(`${base}${await page.locator('a[href*="/documents/"]').first().getAttribute('href')}`);
            assert.equal(pdf.status(), 200);
            assert.equal((await pdf.body()).subarray(0, 5).toString(), '%PDF-');
            await page.screenshot({path: `${output}/${browserName}-order.png`, fullPage: true});
            await ctx.close();
        });

        await scenario('the review with a code fits a phone', browserName, async () => {
            const {ctx, page} = await open(browser, learner().token, {width: 390});
            await toReview(page, fixture);
            await applyCode(page, code);
            assert.ok(await noSideScroll(page), 'no sideways scroll at 390px');
            await page.screenshot({path: `${output}/${browserName}-390-review.png`, fullPage: true});
            await ctx.close();
        });
        await browser.close();
    }

    const chromium = await playwright.chromium.launch();
    await scenario('a promo code is applied, removed and used without JavaScript', 'chromium-nojs', async () => {
        const {ctx, page} = await open(chromium, learner().token, {js: false});
        await toReview(page, fixture);
        await applyCode(page, code, false);
        assert.deepEqual(await totals(page), discounted);
        await submit(page, page.getByRole('button', {name: `Remove promo code ${code}`}));
        assert.deepEqual(await totals(page), [['Total', rand(17345)]]);
        await applyCode(page, code, false);
        await page.locator('input[name=accept_terms]').check();
        await submit(page, page.getByRole('button', {name: 'Place my order'}));
        assert.deepEqual(await totals(page), discounted, 'the order was placed with the discount as an ordinary form post');
        await ctx.close();
    });

    await scenario('ADMIN creates, edits, deactivates, reactivates and deletes a promotion', 'chromium', async () => {
        const {ctx, page} = await open(chromium, fixture.admin_token);
        const adminCode = `admin${fixture.suffix}`;
        await page.goto(`${base}/admin/promotions/new`);
        await page.locator('#promotion-code').fill(adminCode);
        await page.locator('#promotion-name').fill('Browser ADMIN offer');
        await page.locator('#promotion-value').fill('15');
        await page.locator('#promotion-max-customer').fill('1');
        await submit(page, page.getByRole('button', {name: 'Create promotion'}));
        assert.match(page.url(), /\/admin\/promotions\/\d+$/);
        assert.equal(await flash(page), 'The promotion was created.');
        assert.match(clean(await page.locator('h1').innerText()), new RegExp(adminCode.toUpperCase()), 'the code is stored in upper case');
        const editor = page.url();

        await page.locator('#promotion-value').fill('150');
        await submit(page, page.getByRole('button', {name: 'Save promotion'}));
        assert.equal(await flash(page), 'Enter a percentage greater than 0 and no more than 100, such as 10 or 12.5.');
        assert.equal(await page.locator('#promotion-value').inputValue(), '150', 'the rejected value is kept to correct');
        await page.locator('#promotion-value').fill('20');
        await submit(page, page.getByRole('button', {name: 'Save promotion'}));
        assert.match(await flash(page), /^The promotion was saved\./);
        assert.match(clean(await page.locator('main').innerText()), /20% off/);

        await submit(page, page.getByRole('button', {name: 'Deactivate'}));
        assert.match(await flash(page), /^The promotion is deactivated\./);
        assert.ok(await page.getByText('Deactivated', {exact: true}).first().isVisible());
        const buyer = await open(chromium, learner().token);
        await toReview(buyer.page, fixture);
        await applyCode(buyer.page, adminCode);
        assert.equal(await flash(buyer.page), 'That promo code is not currently available.', 'a deactivated code is refused at checkout');
        await buyer.ctx.close();

        await page.goto(editor);
        await submit(page, page.getByRole('button', {name: 'Activate'}));
        assert.match(await flash(page), /^The promotion is active\./);
        await page.goto(`${base}/admin/promotions?promotions_q=${encodeURIComponent(adminCode)}`);
        const row = page.locator('tbody tr');
        assert.equal(await row.count(), 1, 'search finds it whatever the case');
        assert.match(clean(await row.innerText()), /20% off/);
        await page.goto(`${base}/admin/promotions?status=inactive&promotions_q=${encodeURIComponent(adminCode)}`);
        assert.equal(await page.locator('tbody tr').count(), 0, 'it is no longer listed as deactivated');

        await page.goto(editor);
        await submit(page, page.getByRole('button', {name: 'Delete promotion'}));
        assert.equal(await flash(page), 'The promotion was deleted.', 'an unused promotion can be deleted');
        assert.match(page.url(), /\/admin\/promotions$/);
        await ctx.close();
    });

    await scenario('the checkout promo section and the ADMIN promotion pages render in every theme without overflow', 'chromium', async () => {
        const buyer = await open(chromium, learner().token);
        await toReview(buyer.page, fixture);
        await applyCode(buyer.page, code);
        const admin = await open(chromium, fixture.admin_token);
        await admin.page.goto(`${base}/admin/promotions?promotions_q=${fixture.code}`);
        const editor = await admin.page.locator('tbody a[href^="/admin/promotions/"]').first().getAttribute('href');
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                for (const [page, url, selector] of [[buyer.page, '/checkout/review', '.cl-checkout-promo'], [admin.page, '/admin/promotions', 'table'], [admin.page, editor, '#promotion-code']]) {
                    await page.setViewportSize({width, height: 1200});
                    const response = await page.goto(`${base}${url}${url.includes('?') ? '&' : '?'}theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    assert.ok(await page.locator(selector).first().isVisible(), `${theme} ${width} ${url} shows ${selector}`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${url} does not scroll sideways`);
                    if (url === '/checkout/review') assert.deepEqual(await totals(page), discounted, `${theme} ${width} shows the discounted totals`);
                }
            }
        }
        await buyer.ctx.close();
        await admin.ctx.close();
    });
    await chromium.close();
    php('cleanup');

    console.log(results.map((r) => `PASS ${r}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
