/* Course bundles in a real browser: an ADMIN creates a bundle, adds four courses through the course
 * lookup, reorders one, removes one, sets its price and publishes it. In Chromium and Firefox a
 * learner sees the public bundle page (its course cards, its price, the individual prices and the
 * saving), adds it to the cart with the keyboard, applies a bundle promotion at checkout, buys it, and
 * finds every course in their library, with the order and invoice showing the bundle as the item
 * bought. A learner who already has one of the courses is told so. The purchase and the ADMIN reorder
 * work without JavaScript, and the bundle pages, the cart and the ADMIN screens render in all five
 * themes at desktop and phone widths without sideways scrolling.
 *
 * Run against the development instance with bundles-fixture.php, which the script creates and cleans
 * up itself. Placing orders leaves them behind: orders are immutable. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-bundles-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-bundles';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/bundles-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const learner = (action = 'learner') => JSON.parse(php(action).trim().split('\n').pop());
const state = () => JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];
const clean = (text) => text.replace(/\s+/g, ' ').trim();
/* Amounts as the platform prints them (en_ZA), after clean(): "R 1 200,00". */
const rand = (minor) => `R ${Math.floor(minor / 100).toLocaleString('en-ZA').replace(/\s/g, ' ')},${String(minor % 100).padStart(2, '0')}`;

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
    // Without JavaScript the themes' smooth scrolling never settles under Playwright's own scrolling;
    // the themes turn it off for reduced motion.
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: 1400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const flash = async (page) => clean(await page.locator('[data-flash-message] > div').first().innerText());
const submit = async (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
const totals = async (page) => {
    const list = page.locator('.cl-ui-key-value-list').last();
    const labels = await list.locator('dt').allInnerTexts();
    const values = await list.locator('dd').allInnerTexts();
    return labels.map((label, i) => [clean(label), clean(values[i])]);
};
const courseOrder = async (page) => (await page.locator('.cl-bundle-courses li strong').allInnerTexts()).map(clean);

async function checkoutToReview(page) {
    await page.goto(`${base}/checkout/profile`);
    await submit(page, page.getByRole('button', {name: 'Continue to payment'}));
    await page.locator('#payment-method').selectOption('dummy');
    if (await page.locator('#method-token').count()) await page.locator('#method-token').selectOption('demo_success');
    await submit(page, page.getByRole('button', {name: 'Review my order'}));
    assert.match(page.url(), /\/checkout\/review/);
}

(async () => {
    php('create');
    const fixture = state();
    const title = (key) => fixture.courses[key].title;
    let bundleUrl = '', editorUrl = '';

    const chromium = await playwright.chromium.launch();
    await scenario('ADMIN creates a bundle, adds courses through the lookup, reorders, removes, prices and publishes it', 'chromium', async () => {
        const {ctx, page} = await open(chromium, fixture.admin_token);
        await page.goto(`${base}/admin/bundles/new`);
        await page.locator('#bundle-title').fill(fixture.bundle_title);
        await page.locator('#bundle-short').fill('Four office courses for one price.');
        await submit(page, page.getByRole('button', {name: 'Create bundle'}));
        assert.match(page.url(), /\/admin\/bundles\/\d+/);
        assert.match(await flash(page), /^The bundle was created as a draft/);
        editorUrl = page.url().split('#')[0];
        for (const key of ['a', 'b', 'c', 'd']) {
            // The lookup searches on keyup, as a person typing produces; fill() alone sends no key.
            await page.locator('#add_course_id-search').fill(title(key));
            await page.locator('#add_course_id-search').press('End');
            const result = page.locator('#add_course_id-results button.lookup-result', {hasText: title(key)});
            await result.waitFor();
            await result.click();
            await submit(page, page.getByRole('button', {name: 'Add course'}));
            assert.equal(await flash(page), `${title(key)} was added to the bundle.`);
        }
        assert.deepEqual(await courseOrder(page), ['a', 'b', 'c', 'd'].map(title));
        await submit(page, page.getByRole('button', {name: `Move ${title('c')} up`}));
        assert.deepEqual(await courseOrder(page), ['a', 'c', 'b', 'd'].map(title), 'C moved above B');
        await submit(page, page.getByRole('button', {name: `Remove ${title('d')}`}));
        assert.deepEqual(await courseOrder(page), ['a', 'c', 'b'].map(title), 'D removed');
        assert.ok(clean(await page.locator('main').innerText()).includes(`add up to ${rand(160000)}.`), 'the current individual prices are shown for context while choosing a price');
        await page.locator('#bundle-price').fill('1200');
        await page.locator('#bundle-period').fill('1');
        await page.locator('#bundle-unit').selectOption('years');
        await submit(page, page.getByRole('button', {name: 'Save price'}));
        assert.match(await flash(page), /^The bundle price was saved/);
        assert.ok(clean(await page.locator('main').innerText()).includes(`add up to ${rand(160000)}, so the bundle saves ${rand(40000)}`));
        await submit(page, page.getByRole('button', {name: 'Publish', exact: true}));
        assert.equal(await flash(page), 'The bundle is published.');
        bundleUrl = await page.getByRole('link', {name: 'View public page'}).getAttribute('href');
        await page.goto(`${base}/admin/bundles?bundles_q=${encodeURIComponent(fixture.suffix)}`);
        assert.match(clean(await page.locator('tbody tr').first().innerText()), /On sale/i, 'listed as on sale (themes may upper-case badges)');
        await ctx.close();
    });

    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = browserName === 'chromium' ? chromium : await playwright[browserName].launch();
        await scenario('a learner sees the bundle, adds it with the keyboard, buys it with a promotion and receives every course', browserName, async () => {
            const {ctx, page} = await open(browser, learner().token);
            await page.goto(`${base}/bundles`);
            assert.ok(await page.getByRole('link', {name: fixture.bundle_title}).first().isVisible(), 'the bundle is in the bundle catalogue');
            await page.goto(`${base}${bundleUrl}`);
            assert.equal(await page.locator('main .cl-course-card').count(), 3, 'its courses are shown with the catalogue course cards');
            const box = clean(await page.locator('aside').innerText());
            for (const shown of [rand(120000), `Individual course prices ${rand(160000)}`, `You save ${rand(40000)}`]) assert.ok(box.includes(shown), shown);
            await page.getByRole('button', {name: 'Add bundle to cart'}).focus();
            await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
            assert.equal(await flash(page), `${fixture.bundle_title} was added to My Cart.`);
            await page.goto(`${base}/cart`);
            assert.match(clean(await page.locator('.cl-bundle-line').innerText()), /Includes 3 courses/);
            await checkoutToReview(page);
            await page.locator('#promo-code').fill(fixture.code.toLowerCase());
            await Promise.all([page.waitForNavigation(), page.locator('#promo-code').press('Enter')]);
            assert.deepEqual(await totals(page), [['Subtotal', rand(120000)], [`Promo ${fixture.code} (10% off)`, `−${rand(12000)}`], ['Total', rand(108000)]]);
            await page.locator('input[name=accept_terms]').check();
            await submit(page, page.getByRole('button', {name: 'Place my order'}));
            assert.match(page.url(), /\/account\/orders\/\d+/);
            const order = clean(await page.locator('main').innerText());
            assert.match(order, /Status: Fulfilled/);
            assert.ok(order.includes(fixture.bundle_title) && order.includes(`Includes: ${['a', 'c', 'b'].map(title).join(', ')}`), 'the order shows the bundle as the item bought, with what it included');
            assert.deepEqual(await totals(page), [['Subtotal', rand(120000)], [`Promo ${fixture.code} (10% off)`, `−${rand(12000)}`], ['Total', rand(108000)]]);
            const pdf = await ctx.request.get(`${base}${await page.locator('a[href*="/documents/"]').first().getAttribute('href')}`);
            assert.equal((await pdf.body()).subarray(0, 5).toString(), '%PDF-');
            await page.goto(`${base}/account/library`);
            const library = clean(await page.locator('main').innerText());
            for (const key of ['a', 'b', 'c']) assert.ok(library.includes(title(key)), `${title(key)} is in the library`);
            assert.ok(!library.includes(title('d')), 'the removed course is not');
            await page.screenshot({path: `${output}/${browserName}-library.png`, fullPage: true});
            await ctx.close();
        });
        await scenario('the bundle page and cart fit a phone', browserName, async () => {
            const {ctx, page} = await open(browser, learner().token, {width: 390});
            await page.goto(`${base}${bundleUrl}`);
            assert.ok(await noSideScroll(page));
            await submit(page, page.getByRole('button', {name: 'Add bundle to cart'}));
            await page.goto(`${base}/cart`);
            assert.ok(await noSideScroll(page));
            await page.screenshot({path: `${output}/${browserName}-390-cart.png`, fullPage: true});
            await ctx.close();
        });
        if (browser !== chromium) await browser.close();
    }

    await scenario('a learner who already has one of the courses is told so, and the price stays the bundle price', 'chromium', async () => {
        const {ctx, page} = await open(chromium, learner('learner-owning-a').token);
        await page.goto(`${base}${bundleUrl}`);
        const box = clean(await page.locator('aside').innerText());
        assert.ok(box.includes('You already have access to 1 of the 3 courses in this bundle.'));
        assert.ok(box.includes(rand(120000)));
        await ctx.close();
    });

    await scenario('a bundle is bought, and an ADMIN reorders its courses, without JavaScript', 'chromium-nojs', async () => {
        const buyer = await open(chromium, learner().token, {js: false});
        await buyer.page.goto(`${base}${bundleUrl}`);
        await submit(buyer.page, buyer.page.getByRole('button', {name: 'Add bundle to cart'}));
        await checkoutToReview(buyer.page);
        await buyer.page.locator('input[name=accept_terms]').check();
        await submit(buyer.page, buyer.page.getByRole('button', {name: 'Place my order'}));
        assert.match(clean(await buyer.page.locator('main').innerText()), /Status: Fulfilled/);
        await buyer.ctx.close();
        const admin = await open(chromium, fixture.admin_token, {js: false});
        await admin.page.goto(editorUrl);
        await submit(admin.page, admin.page.getByRole('button', {name: `Move ${title('b')} up`}));
        assert.deepEqual(await courseOrder(admin.page), ['a', 'b', 'c'].map(title));
        await admin.ctx.close();
    });

    await scenario('the bundle pages, the cart and the ADMIN bundle screens render in every theme without overflow', 'chromium', async () => {
        const buyer = await open(chromium, learner().token);
        await buyer.page.goto(`${base}${bundleUrl}`);
        await submit(buyer.page, buyer.page.getByRole('button', {name: 'Add bundle to cart'}));
        const admin = await open(chromium, fixture.admin_token);
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                for (const [page, url, selector] of [[buyer.page, '/bundles', '.cl-course-card'], [buyer.page, bundleUrl, 'aside'], [buyer.page, '/cart', '.cl-bundle-line'], [admin.page, '/admin/bundles', 'table'], [admin.page, editorUrl.replace(base, ''), '.cl-bundle-courses']]) {
                    await page.setViewportSize({width, height: 1200});
                    const response = await page.goto(`${base}${url}${url.includes('?') ? '&' : '?'}theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    assert.ok(await page.locator(selector).first().isVisible(), `${theme} ${width} ${url} shows ${selector}`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${url} does not scroll sideways`);
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
