/* Bundles, round two (owner, 2026-11-06), in a real browser. ADMIN gives a bundle its code beside its title and chooses
 * its status from three cards on the bundle form, new and existing; the bundle list shows code, title, status, price, on
 * sale from, on sale until and access as columns, is searched by code, and edits a row or a card in place: Save and
 * Cancel without a reload, a refused save coming back as typed with why, and the plain form without JavaScript. A bundle
 * whose sale starts later stays on the Bundles page, in the catalogue's bundles, in the "Also in" note of its courses and on
 * its own page, each saying when it goes on sale, and the cart refuses it with the date. A learner stars bundles and
 * courses in place and finds them on Favourites, its own page after My Courses, filtered by All, Courses or Bundles and
 * shown as cards or a list; My Courses has only its two lists, and Course Requests is a page of its own for a company
 * account only. In Chromium and Firefox, without JavaScript and at phone width.
 *
 * Run against the development instance; bundles-round-two-fixture.php makes and removes everything it uses. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-bundles-round-two-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-bundles-round-two';
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/bundles-round-two-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const person = (action) => JSON.parse(php(action).trim().split('\n').pop());
const state = () => JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const results = [], failures = [];
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
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1440, height: 1200}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const flash = async (page) => clean(await page.locator('[data-flash-message] > div').first().innerText());
const submit = async (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
const mainText = async (page) => clean(await page.locator('main').innerText());
/* The page was not loaded again: a mark set on the window survives only an in-place change. */
const mark = (page) => page.evaluate(() => { window.__bundlesRoundTwo = 1; });
const stillMarked = (page) => page.evaluate(() => window.__bundlesRoundTwo === 1);
/* The links of the Account menu's Courses group, in order. */
const coursesMenu = (page) => page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href'))
    .filter((href, index, all) => ['/account/orders', '/account/wallet', '/account/courses', '/account/favourites', '/account/requests', '/account/certificates'].includes(href) && all.indexOf(href) === index));

(async () => {
    php('create');
    const fixture = state();
    const later = fixture.bundles.later, now = fixture.bundles.now;
    const chromium = await playwright.chromium.launch();

    await scenario('the bundle form has the code beside the title and the status as three cards, for a new bundle and an existing one', 'chromium', async () => {
        const {ctx, page} = await open(chromium, fixture.admin_token);
        await page.goto(`${base}/admin/bundles/new`);
        assert.ok(await page.locator('#bundle-code').isVisible(), 'a code field on a new bundle');
        const cards = page.locator('form[action="/admin/bundles"] input[type=radio][name=status]');
        assert.deepEqual(await cards.evaluateAll((inputs) => inputs.map((i) => i.value)), ['draft', 'published', 'retired'], 'one card for each status');
        const words = await page.locator('form[action="/admin/bundles"] fieldset:has(input[name=status])').innerText();
        for (const said of ['Draft', 'Only administrators see it.', 'Published', 'In the catalogue.', 'Retired', 'Off the catalogue, and kept for its orders.']) assert.ok(clean(words).includes(said), said);
        await page.locator('#bundle-title').fill(fixture.new_title);
        await page.locator('#bundle-code').fill(fixture.new_code.toLowerCase());
        await submit(page, page.getByRole('button', {name: 'Create bundle'}));
        assert.ok(page.url().endsWith(`/admin/bundles/${fixture.new_code}`), `a new bundle opens at its code (${page.url()})`);
        assert.equal(await page.locator('#bundle-code').inputValue(), fixture.new_code, 'the code is kept as a code is written: capitals');
        assert.equal(await page.locator('input[name=status][value=draft]').isChecked(), true, 'a new bundle is a draft');
        // Publishing an empty bundle is refused by the same checks as ever, and says why.
        await page.locator('label:has(input[name=status][value=published])').click();
        await submit(page, page.getByRole('button', {name: 'Save details'}));
        assert.equal(await flash(page), 'Add at least two courses before publishing the bundle.');
        assert.equal(await page.locator('input[name=status][value=draft]').isChecked(), true, 'still a draft');
        await ctx.close();
    });

    await scenario('the bundle list shows the owner\'s columns and finds a bundle by its code', 'chromium', async () => {
        const {ctx, page} = await open(chromium, fixture.admin_token);
        await page.goto(`${base}/admin/bundles?bundles_q=${encodeURIComponent(later.code)}`);
        // Each heading's words, without its sort mark (the list sorts by every column).
        const headers = (await page.locator('main table thead th').allInnerTexts()).map((h) => clean(h.replace(/[↕▲▼]/g, ''))).filter((h) => h !== '');
        for (const column of ['Code', 'Bundle', 'Status', 'Price', 'On sale from', 'On sale until', 'Access']) assert.ok(headers.some((h) => h.toLowerCase() === column.toLowerCase()), `a ${column} column (${headers.join(', ')})`);
        assert.equal(await page.locator('main tbody tr').count(), 1, 'the code finds the one bundle');
        assert.ok(clean(await page.locator('main tbody tr').first().innerText()).includes(later.code));
        assert.ok(await noSideScroll(page));
        await ctx.close();
    });

    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = browserName === 'chromium' ? chromium : await playwright[browserName].launch();
        await scenario('a row of the bundle list is edited in place, saved and cancelled without a reload, and a refusal says why', browserName, async () => {
            const {ctx, page} = await open(browser, fixture.admin_token);
            await page.goto(`${base}/admin/bundles?bundles_q=${encodeURIComponent(now.code)}&bundles_view=list`);
            await mark(page);
            const row = page.locator(`tr#bundle-${now.code}`);
            await row.getByRole('link', {name: `Edit ${now.title} here`}).click();
            await page.locator(`tr#bundle-${now.code} input[name=code]`).waitFor();
            assert.equal(await page.locator(`tr#bundle-${now.code} input[name=code]`).inputValue(), now.code);
            assert.equal(await page.locator(`tr#bundle-${now.code} input[name=status][value=published]`).isChecked(), true, 'the status cards in the row');
            const title = `${now.title} ${browserName === 'chromium' ? 'Plus' : 'Extra'}`;
            await page.locator(`tr#bundle-${now.code} input[name=title]`).fill(title);
            await page.locator(`tr#bundle-${now.code} input[name=price]`).fill('950');
            await page.locator(`tr#bundle-${now.code} input[name=was_price]`).fill('1400');
            await page.locator(`tr#bundle-${now.code} input[name=access_period_value]`).fill('18');
            await page.locator(`tr#bundle-${now.code} select[name=access_period_unit]`).selectOption('months');
            await page.locator(`tr#bundle-${now.code} input[name=available_until]`).fill('2027-03-31T17:00');
            await page.getByRole('button', {name: `Save ${now.title}`}).click();
            await page.locator(`tr#bundle-${now.code}[data-bundle-saved]`).waitFor();
            const saved = clean(await page.locator(`tr#bundle-${now.code}`).innerText());
            for (const shown of [title, 'R 950,00', 'R 1 400,00', '18 months', '31 Mar 2027']) assert.ok(saved.includes(shown), `${shown} in "${saved}"`);
            assert.ok(await stillMarked(page), 'saved without loading the page again');
            now.title = title;
            // A course's code is refused, in place, with what was typed kept.
            await page.locator(`tr#bundle-${now.code}`).getByRole('link', {name: `Edit ${now.title} here`}).click();
            await page.locator(`tr#bundle-${now.code} input[name=code]`).fill(fixture.courses.a.code);
            await page.getByRole('button', {name: `Save ${now.title}`}).click();
            await page.locator(`tr#bundle-${now.code} [role=alert]`).waitFor();
            assert.match(clean(await page.locator(`tr#bundle-${now.code} [role=alert]`).innerText()), new RegExp(`${fixture.courses.a.code}.*course`, 'i'));
            assert.equal(await page.locator(`tr#bundle-${now.code} input[name=code]`).inputValue(), fixture.courses.a.code, 'what was typed stays');
            await page.getByRole('link', {name: `Cancel editing ${now.title}`}).click();
            await page.locator(`tr#bundle-${now.code}:not(:has(input[name=code]))`).waitFor();
            assert.ok(clean(await page.locator(`tr#bundle-${now.code}`).innerText()).includes(now.code), 'Cancel brings the row back as it is');
            assert.ok(await stillMarked(page), 'cancelled without loading the page again');
            await page.reload();
            assert.ok(clean(await page.locator(`tr#bundle-${now.code}`).innerText()).includes('R 950,00'), 'the save was kept');
            await ctx.close();
        });
        await scenario('a card of the bundle grid is edited in place', browserName, async () => {
            const {ctx, page} = await open(browser, fixture.admin_token);
            await page.goto(`${base}/admin/bundles?bundles_q=${encodeURIComponent(later.code)}&bundles_view=grid`);
            await mark(page);
            const card = page.locator('main li.cl-ui-card', {hasText: later.code});
            await card.getByRole('link', {name: `Edit ${later.title} here`}).click();
            await page.locator('main li.cl-ui-card input[name=title]').waitFor();
            await page.locator('main li.cl-ui-card input[name=title]').fill(`${later.title} Again`);
            await page.getByRole('button', {name: `Save ${later.title}`}).click();
            await page.locator('main li.cl-ui-card:has([data-bundle-saved])').waitFor();
            assert.ok(clean(await page.locator('main li.cl-ui-card').first().innerText()).includes(`${later.title} Again`));
            assert.ok(await stillMarked(page));
            // Back as it was for the scenarios that follow, and the list view for this browser again.
            await page.locator('main li.cl-ui-card').getByRole('link', {name: `Edit ${later.title} Again here`}).click();
            await page.locator('main li.cl-ui-card input[name=title]').fill(later.title);
            await page.getByRole('button', {name: `Save ${later.title} Again`}).click();
            await page.locator('main li.cl-ui-card:has([data-bundle-saved])').waitFor();
            await page.goto(`${base}/admin/bundles?bundles_view=list`);
            await ctx.close();
        });
        await scenario('a bundle whose sale starts later stays everywhere with its date, and the cart refuses it with the date', browserName, async () => {
            const {ctx, page} = await open(browser, person('learner').token);
            await page.goto(`${base}/bundles`);
            const card = page.locator('main .cl-bundle-card', {hasText: later.title});
            assert.equal(await card.count(), 1, 'on the Bundles page, a bundle\'s own card');
            // Compared without case: a theme may write a badge in capitals.
            assert.ok(clean(await card.innerText()).toLowerCase().includes(fixture.later_short.toLowerCase()), `the card says ${fixture.later_short}`);
            await page.goto(`${base}/bundles/${later.slug}`);
            const text = await mainText(page);
            assert.ok(text.includes(fixture.later_long), `its page says ${fixture.later_long}`);
            assert.ok(!text.includes('This bundle is not on sale yet.'), 'not the old message');
            assert.equal(await page.getByRole('button', {name: 'Add bundle to cart'}).count(), 0, 'nothing to buy yet');
            await page.goto(`${base}/courses/${fixture.courses.c.slug}`);
            const note = page.locator('.cl-course-bundle', {hasText: later.title});
            assert.equal(await note.count(), 1, 'the "Also in" note of its course');
            assert.ok(clean(await note.innerText()).toLowerCase().includes(fixture.later_short.toLowerCase()), `the note says ${fixture.later_short}`);
            // In the catalogue's bundles, on whichever of their pages (six to a page, owner 2026-11-07) its saving puts it.
            let inCatalogue = false;
            for (let n = 1; n <= 10 && !inCatalogue; n++) {
                await page.goto(`${base}/courses?bundles_page=${n}`);
                inCatalogue = clean(await page.locator('#catalogue-bundles').innerText()).includes(later.title);
                if (!inCatalogue && !(await page.locator('#catalogue-bundles a[rel="next"]').count())) break;
            }
            assert.ok(inCatalogue, 'the catalogue\'s bundles');
            // Asked to put it in the cart anyway, with the page's own form token: refused, with when.
            await page.goto(`${base}/bundles/${now.slug}`);
            const csrf = await page.locator('form[action="/cart/add-bundle"] input[name=csrf]').inputValue();
            await page.evaluate(({csrf, offer}) => { const form = document.querySelector('form[action="/cart/add-bundle"]'); form.querySelector('[name=bundle_offer_id]').value = offer; form.querySelector('[name=csrf]').value = csrf; }, {csrf, offer: String(later.offer)});
            await submit(page, page.getByRole('button', {name: 'Add bundle to cart'}));
            assert.equal(await flash(page), fixture.later_refusal);
            for (const url of ['/bundles', `/bundles/${later.slug}`, `/courses/${fixture.courses.c.slug}`, '/courses']) {
                await page.goto(`${base}${url}`);
                assert.ok(!(await mainText(page)).includes('This bundle is not on sale yet.'), `${url} never says the old message`);
            }
            await ctx.close();
        });
        await scenario('a learner stars bundles and courses in place and finds them on Favourites, filtered and as cards or a list', browserName, async () => {
            const {ctx, page} = await open(browser, person('learner').token);
            await page.goto(`${base}/bundles`);
            await mark(page);
            for (const bundle of [now, later]) {
                const star = page.locator(`#favourite-bundle-${bundle.id} button`);
                await star.click();
                await page.locator(`#favourite-bundle-${bundle.id} button[aria-pressed=true]`).waitFor();
            }
            assert.ok(await stillMarked(page), 'a star changes in place');
            await page.goto(`${base}/courses/${fixture.courses.a.slug}`);
            await page.locator(`#favourite-${fixture.courses.a.id} button`).first().click();
            await page.locator(`#favourite-${fixture.courses.a.id} button[aria-pressed=true]`).first().waitFor();
            await page.goto(`${base}/bundles/${later.slug}`);
            assert.equal(await page.locator(`#favourite-bundle-${later.id} button[aria-pressed=true]`).count(), 1, 'the bundle page has its star, filled');

            const menu = await coursesMenu(page);
            assert.equal(menu[menu.indexOf('/account/courses') + 1], '/account/favourites', `Favourites straight after My Courses (${menu.join(' ')})`);
            assert.ok(!menu.includes('/account/requests'), 'no Course Requests for a private account');
            await page.goto(`${base}/account/favourites?favourites_view=grid`);
            // A bundle keeps its own card among the courses (owner, 2026-11-07, the approved mockup).
            const titles = async () => (await page.locator('#favourites-view :is(.cl-course-card-title, .cl-bundle-card-title)').allInnerTexts()).map(clean);
            assert.deepEqual((await titles()).sort(), [fixture.courses.a.title, now.title, later.title].sort(), 'its courses and bundles together, as cards');
            await mark(page);
            await page.locator('label:has(input[name=kind][value=bundles])').click();
            await page.waitForFunction(() => document.querySelectorAll('#favourites-view .cl-bundle-card').length === 2 && !document.querySelector('#favourites-view .cl-course-card'));
            assert.deepEqual((await titles()).sort(), [now.title, later.title].sort(), 'Bundles shows only the bundles');
            await page.locator('label:has(input[name=kind][value=courses])').click();
            await page.waitForFunction(() => document.querySelectorAll('#favourites-view .cl-course-card').length === 1 && !document.querySelector('#favourites-view .cl-bundle-card'));
            assert.deepEqual(await titles(), [fixture.courses.a.title], 'Courses only the course');
            assert.ok(await stillMarked(page), 'the filter changes the list in place');
            await page.goto(`${base}/account/favourites?favourites_view=list`);
            assert.equal(await page.locator('#favourites-view tbody tr').count(), 3, 'the list shows all three');
            await page.goto(`${base}/account/favourites?favourites_view=grid`);
            await mark(page);
            await page.locator(`#favourite-bundle-${now.id} button`).click();
            await page.locator(`#favourite-bundle-${now.id} button[aria-pressed=false]`).waitFor();
            assert.ok(await stillMarked(page), 'unstarred in place');
            await page.reload();
            assert.deepEqual((await titles()).sort(), [fixture.courses.a.title, later.title].sort(), 'and it is gone after a reload');
            const card = page.locator('#favourites-view .cl-bundle-card', {hasText: later.title});
            assert.equal(await card.getByRole('link', {name: later.title}).getAttribute('href'), `/bundles/${later.slug}`, 'each card opens its page');

            await page.goto(`${base}/account/courses`);
            assert.deepEqual(await page.locator('input[name=list]').evaluateAll((inputs) => inputs.map((i) => i.value)), ['current', 'completed'], 'My Courses keeps current and completed courses only');
            const response = await page.goto(`${base}/account/requests`);
            assert.equal(response.status(), 404, 'Course Requests is not a private account\'s page');
            await page.goto(`${base}/account/favourites?favourites_view=list`);
            await ctx.close();
        });
        if (browser !== chromium) await browser.close();
    }

    await scenario('a company account has Course Requests as a page of its own, after Favourites', 'chromium', async () => {
        const {ctx, page} = await open(chromium, person('company-learner').token);
        await page.goto(`${base}/account/courses`);
        const menu = await coursesMenu(page);
        assert.deepEqual(menu.slice(menu.indexOf('/account/courses'), menu.indexOf('/account/courses') + 3), ['/account/courses', '/account/favourites', '/account/requests'], menu.join(' '));
        const response = await page.goto(`${base}/account/requests`);
        assert.equal(response.status(), 200);
        assert.equal(clean(await page.locator('h1').first().innerText()), 'Course Requests');
        assert.ok(await page.locator('main').getByText('No course requests.').isVisible(), 'its own list');
        await ctx.close();
    });

    await scenario('the quick editor, Favourites and its filter work without JavaScript, and fit a phone', 'chromium-nojs', async () => {
        const admin = await open(chromium, fixture.admin_token, {js: false});
        await admin.page.goto(`${base}/admin/bundles?bundles_q=${encodeURIComponent(later.code)}&bundles_view=list`);
        await submit(admin.page, admin.page.locator(`tr#bundle-${later.code}`).getByRole('link', {name: `Edit ${later.title} here`}));
        assert.match(admin.page.url(), new RegExp(`edit=${later.code}`), 'the row opens by its code, never its number');
        await admin.page.locator(`tr#bundle-${later.code} input[name=title]`).fill(`${later.title} Plain`);
        await submit(admin.page, admin.page.getByRole('button', {name: `Save ${later.title}`}));
        assert.equal(await flash(admin.page), 'The bundle was saved.');
        assert.ok(clean(await admin.page.locator(`tr#bundle-${later.code}`).innerText()).includes(`${later.title} Plain`));
        await admin.page.setViewportSize({width: 390, height: 1000});
        await admin.page.goto(`${base}/admin/bundles?edit=${encodeURIComponent(later.code)}&bundles_q=${encodeURIComponent(later.code)}`);
        assert.ok(await noSideScroll(admin.page), 'the list with a row open fits a phone');
        await admin.ctx.close();
        const learner = await open(chromium, person('learner').token, {js: false});
        await learner.page.goto(`${base}/bundles/${now.slug}`);
        await submit(learner.page, learner.page.locator(`#favourite-bundle-${now.id} button`));
        await learner.page.goto(`${base}/account/favourites`);
        assert.ok((await mainText(learner.page)).includes(now.title), 'starred without JavaScript');
        await learner.page.locator('label:has(input[name=kind][value=courses])').click();
        await submit(learner.page, learner.page.getByRole('button', {name: 'Show'}));
        assert.match(learner.page.url(), /kind=courses/);
        assert.ok(!(await mainText(learner.page)).includes(now.title), 'Courses leaves the bundle out');
        await learner.page.setViewportSize({width: 390, height: 1000});
        await learner.page.goto(`${base}/account/favourites`);
        assert.ok(await noSideScroll(learner.page), 'Favourites fits a phone');
        await learner.ctx.close();
    });

    await chromium.close();
    php('cleanup');
    console.log(results.map((r) => `PASS ${r}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
