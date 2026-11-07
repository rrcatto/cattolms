/* Bundles beside the courses (owner, 2026-11-06: "Bundle mockup is approved"), in Chromium and Firefox, read from the
 * site's own bundles and never changing one:
 *   - a course's page has, in its Course access card under the buy button, one note for each bundle holding it that the
 *     visitor could buy, the larger saving first: "Also in ...", "This course and the N beside it for ...", a Save badge
 *     and See the bundle with its icon, opening the bundle's page;
 *   - All courses and a category's page have a "Save with a bundle" row of the Bundles page's cards above the courses,
 *     with View all bundles in its head, in place of the line about bundles, which stays where no row shows (a category
 *     without bundles, a search, a later page);
 *   - every bundled theme at 1440 and 390: nothing out of its card or its grid, no sideways scrolling, no inline style,
 *     and the shared page checks (page-construction.cjs);
 *   - a company account sees no note and no row;
 *   - without JavaScript the notes, the row and See the bundle work; with it, a search and a page turned in place take
 *     the row away and bring the line, and clearing the search brings the row back, the page never loading again.
 * What each page should show comes from BundleService itself, through the fixture, which only makes a company account.
 *
 *   podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/bundle-display-fixture.php create'
 *   CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/bundle-display.cjs
 *   podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/bundle-display-fixture.php cleanup'
 */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {inspect, judge} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-bundle-display';
const root = path.resolve(__dirname, '..', '..');
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-bundle-display-state.json';
// What the pages should show is asked of BundleService as the run starts: the owner may change a bundle between runs.
execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/bundle-display-fixture.php expect ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => ({slug: t.slug, key: `${t.slug}-v${t.version}`}))
    .filter((t) => !process.env.CATTO_THEMES || process.env.CATTO_THEMES.split(',').includes(t.slug));
const LINE = 'Several courses for one price: browse course bundles.';
const coursePath = `/courses/${state.course.slug}`;
fs.mkdirSync(output, {recursive: true});
const results = [];
const clean = (text) => text.replace(/\s+/g, ' ').trim();

async function scenario(name, run) {
    try { await run(); results.push({name, ok: true}); console.log(`PASS ${name}`); }
    catch (error) { results.push({name, ok: false, error: error.message.split('\n')[0]}); console.log(`FAIL ${name}: ${error.message.split('\n')[0]}`); }
}
async function open(browser, options = {}) {
    // Without JavaScript the themes' smooth scrolling never settles under Playwright; they turn it off for reduced motion.
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1440, height: 900}, javaScriptEnabled: options.js !== false, reducedMotion: 'reduce'});
    if (options.token) await ctx.addCookies([{name: 'catto_learning_session', value: options.token, url: base, secure: true}]);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    return {ctx, page, errors};
}
async function visit(page, url) {
    const response = await page.goto(`${base}${url}`, {waitUntil: 'networkidle'});
    assert.equal(response.status(), 200, `${url} answers`);
    const problems = judge(await page.evaluate(inspect), url);
    assert.deepEqual(problems, [], 'the shared page checks');
}

/** What the course page's notes show, where they sit and whether anything stands out of the card. */
const notesOnPage = () => {
    const card = document.querySelector('#course-access')?.closest('.cl-ui-surface');
    const notes = [...document.querySelectorAll('.cl-course-bundle')];
    const cardBox = card ? card.getBoundingClientRect() : null;
    const buy = card ? card.querySelector('.cl-course-purchase') : null;
    return {
        inCard: notes.filter((n) => card && card.contains(n)).length,
        afterBuy: notes.every((n) => buy && n.getBoundingClientRect().top >= buy.getBoundingClientRect().bottom - 1),
        notes: notes.map((n) => {
            const link = n.querySelector('a.cl-ui-action');
            const badge = n.querySelector('.cl-ui-badge');
            const box = n.getBoundingClientRect();
            const linkBox = link ? link.getBoundingClientRect() : null;
            return {
                title: n.querySelector('.cl-course-bundle-title')?.textContent.trim(),
                offer: n.querySelectorAll('p')[1]?.textContent.replace(/\s+/g, ' ').trim(),
                save: badge ? badge.textContent.replace(/\s+/g, ' ').trim() : null,
                badgeShown: badge ? badge.getBoundingClientRect().width > 0 && getComputedStyle(badge).visibility !== 'hidden' : false,
                link: link ? link.textContent.replace(/\s+/g, ' ').trim() : null,
                href: link ? link.getAttribute('href') : null,
                icon: link ? !!link.querySelector('svg') : false,
                out: !!cardBox && (box.left < cardBox.left - 0.5 || box.right > cardBox.right + 0.5 || (linkBox && (linkBox.left < cardBox.left - 0.5 || linkBox.right > cardBox.right + 0.5))),
                styled: n.querySelectorAll('[style]').length + (n.hasAttribute('style') ? 1 : 0),
            };
        }),
        sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
};
/** What a catalogue page shows of the bundle row and the line. */
const rowOnPage = () => {
    const row = document.querySelector('#catalogue-bundles');
    const grid = row ? row.querySelector('.cl-course-cards') : null;
    const cards = row ? [...row.querySelectorAll('.cl-course-card')] : [];
    const courses = [...document.querySelectorAll('#catalogue-results .cl-course-card, #catalogue-results tbody tr')].filter((c) => !c.closest('#catalogue-bundles'));
    const head = row ? row.querySelector('.cl-ui-section-head') : null;
    const link = head ? head.querySelector('a.cl-ui-action') : null;
    const gridBox = grid ? grid.getBoundingClientRect() : null;
    const tops = cards.map((c) => Math.round(c.getBoundingClientRect().top));
    return {
        row: !!row,
        heading: head ? head.querySelector('h3')?.textContent.trim() : null,
        summary: head ? head.querySelector('p')?.textContent.trim() : null,
        link: link ? [link.textContent.replace(/\s+/g, ' ').trim(), link.getAttribute('href'), !!link.querySelector('svg')] : null,
        titles: cards.map((c) => c.querySelector('.cl-course-card-title')?.textContent.trim()),
        saves: cards.map((c) => c.querySelector('.cl-course-card-saving')?.textContent.replace(/\s+/g, ' ').trim() || null),
        perRow: tops.filter((t) => t === tops[0]).length,
        outOfGrid: gridBox ? cards.filter((c) => { const b = c.getBoundingClientRect(); return b.left < gridBox.left - 0.5 || b.right > gridBox.right + 0.5; }).length : 0,
        aboveCourses: !row || !courses.length || row.getBoundingClientRect().bottom <= courses[0].getBoundingClientRect().top + 1,
        line: [...document.querySelectorAll('.cl-catalogue-bundles')].map((p) => p.textContent.replace(/\s+/g, ' ').trim()),
        styled: row ? row.querySelectorAll('[style]').length : 0,
        sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
};

function expectNotes(found, label) {
    assert.equal(found.inCard, state.course.notes.length, `${label}: one note for each bundle, in the Course access card`);
    assert.ok(found.afterBuy, `${label}: under the buy button`);
    assert.deepEqual(found.notes.map((n) => n.title), state.course.notes.map((n) => `Also in ${n.title}`), `${label}: the larger saving first`);
    found.notes.forEach((n, i) => {
        const want = state.course.notes[i];
        assert.match(n.offer, new RegExp(`^This course and the ${want.others} beside it for .+, with .+ of access to each\\.$`), `${label}: the offer's words`);
        assert.equal(n.save, want.save === null ? null : clean(want.save), `${label}: the saving`);
        if (want.save) assert.ok(n.badgeShown, `${label}: the Save badge shows`);
        assert.deepEqual([n.link, n.href, n.icon], ['See the bundle', want.href, true], `${label}: See the bundle, with its icon`);
        assert.equal(n.out, false, `${label}: the note stays inside its card`);
        assert.equal(n.styled, 0, `${label}: no inline style`);
    });
    assert.equal(found.sideways, false, `${label}: no sideways scrolling`);
}
function expectRow(found, titles, summary, label, width) {
    assert.ok(found.row, `${label}: the row shows`);
    assert.equal(found.heading, 'Save with a bundle', `${label}: its heading`);
    assert.equal(found.summary, summary, `${label}: its line`);
    assert.deepEqual(found.link, ['View all bundles', '/bundles', true], `${label}: a link to all bundles, with its icon`);
    assert.deepEqual(found.titles, titles, `${label}: the bundles, the larger saving first`);
    assert.ok(found.saves.some(Boolean), `${label}: the cards say what they save`);
    assert.ok(width >= 1440 ? found.perRow === Math.min(4, titles.length) : found.perRow === 1, `${label}: ${found.perRow} cards to a row`);
    assert.equal(found.outOfGrid, 0, `${label}: no card out of the grid`);
    assert.ok(found.aboveCourses, `${label}: above the courses`);
    assert.deepEqual(found.line, [], `${label}: the row replaces the line`);
    assert.equal(found.styled, 0, `${label}: no inline style`);
    assert.equal(found.sideways, false, `${label}: no sideways scrolling`);
}
function expectNoRow(found, label, line = true) {
    assert.equal(found.row, false, `${label}: no row`);
    assert.deepEqual(found.line, line ? [LINE] : [], `${label}: ${line ? 'the line stays' : 'no line'}`);
}

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();
        for (const width of (process.env.CATTO_WIDTHS || '1440,390').split(',').map(Number)) {
            for (const theme of themes) {
                await scenario(`${browserName} ${width} ${theme.slug}: the notes on a course page and the row on All courses and a category`, async () => {
                    // An administrator, who may preview a theme without activating it (a private account, so a buyer).
                    const {ctx, page, errors} = await open(browser, {width, token: state.admin_token});
                    await visit(page, `${coursePath}?theme_preview=${theme.key}`);
                    assert.ok(await page.evaluate((key) => [...document.querySelectorAll('link[rel=stylesheet]')].some((l) => l.getAttribute('href').startsWith(`/themes/${key}/`)), theme.key), `drawn in ${theme.key}`);
                    expectNotes(await page.evaluate(notesOnPage), 'course page');
                    await page.locator('#course-access').locator('xpath=ancestor::*[contains(concat(" ", @class, " "), " cl-ui-surface ")][1]').screenshot({path: path.join(output, `${browserName}-${theme.slug}-${width}-course-access.png`)});
                    await visit(page, `/courses?theme_preview=${theme.key}`);
                    assert.ok(await page.evaluate((key) => [...document.querySelectorAll('link[rel=stylesheet]')].some((l) => l.getAttribute('href').startsWith(`/themes/${key}/`)), theme.key), `drawn in ${theme.key}`);
                    expectRow(await page.evaluate(rowOnPage), state.all, 'Several courses for one price.', 'All courses', width);
                    await page.locator('#catalogue-bundles').screenshot({path: path.join(output, `${browserName}-${theme.slug}-${width}-all-courses.png`)});
                    if (state.with) {
                        await visit(page, `${state.with.href}?theme_preview=${theme.key}`);
                        expectRow(await page.evaluate(rowOnPage), state.with.row, `Bundles with a course in ${state.with.name}.`, state.with.name, width);
                        await page.screenshot({path: path.join(output, `${browserName}-${theme.slug}-${width}-category.png`), fullPage: true});
                    }
                    if (state.without) {
                        await visit(page, `${state.without.href}?theme_preview=${theme.key}`);
                        expectNoRow(await page.evaluate(rowOnPage), state.without.name);
                    }
                    await visit(page, `/bundles?theme_preview=${theme.key}`);
                    const saves = await page.locator('.cl-course-card-saving').count();
                    assert.ok(saves >= state.all.length, `the Bundles page's cards say what they save (${saves})`);
                    assert.deepEqual(errors, []);
                    await ctx.close();
                });
            }
        }

        await scenario(`${browserName}: a company account sees no note and no row`, async () => {
            const {ctx, page} = await open(browser, {token: state.company_token});
            await visit(page, coursePath);
            assert.equal(await page.locator('.cl-course-bundle').count(), 0, 'no note');
            assert.equal(await page.getByRole('button', {name: 'Add to cart'}).count(), 0, 'and no buy button');
            await visit(page, '/courses');
            expectNoRow(await page.evaluate(rowOnPage), 'All courses');
            if (state.with) {
                await visit(page, state.with.href);
                expectNoRow(await page.evaluate(rowOnPage), state.with.name);
            }
            await ctx.close();
        });

        await scenario(`${browserName}: without JavaScript the notes, the row and See the bundle work, and a search or a later page has the line`, async () => {
            const {ctx, page} = await open(browser, {js: false});
            await visit(page, coursePath);
            expectNotes(await page.evaluate(notesOnPage), 'course page');
            await Promise.all([page.waitForNavigation(), page.locator('.cl-course-bundle').first().getByRole('link', {name: 'See the bundle'}).click()]);
            assert.equal(new URL(page.url()).pathname, state.course.notes[0].href, 'See the bundle opens the bundle');
            assert.ok(clean(await page.locator('main h1').innerText()).includes(state.course.notes[0].title), 'the bundle\'s own page');
            await visit(page, '/courses');
            expectRow(await page.evaluate(rowOnPage), state.all, 'Several courses for one price.', 'All courses', 1440);
            // A later page, where the site has one (BundleDisplayIntegrationTest makes one for itself).
            const later = await page.locator('#catalogue-results a[rel="next"]').count() > 0;
            await visit(page, '/courses?catalogue_q=course');
            expectNoRow(await page.evaluate(rowOnPage), 'a search');
            if (later) {
                await visit(page, '/courses?page=2');
                expectNoRow(await page.evaluate(rowOnPage), 'page 2');
            } else console.log('NOTE: All courses has one page here, so no later page was seen without JavaScript.');
            await ctx.close();
        });

        await scenario(`${browserName}: a search and a page turned in place take the row away and bring the line, and clearing brings the row back`, async () => {
            const {ctx, page, errors} = await open(browser);
            await visit(page, '/courses');
            await page.evaluate(() => { window.__samePage = true; });
            await page.locator('#catalogue-search-input').fill('course');
            await page.locator('#catalogue-bundles').waitFor({state: 'detached', timeout: 10000});
            expectNoRow(await page.evaluate(rowOnPage), 'a search in place');
            await page.locator('#catalogue-search-input').fill('');
            await page.locator('#catalogue-bundles').waitFor({state: 'attached', timeout: 10000});
            expectRow(await page.evaluate(rowOnPage), state.all, 'Several courses for one price.', 'the search cleared', 1440);
            const next = page.locator('#catalogue-results a[rel="next"]').first();
            if (!(await next.count())) console.log('NOTE: All courses has one page here, so no page was turned in place.');
            else {
                await next.click();
                await page.locator('#catalogue-bundles').waitFor({state: 'detached', timeout: 10000});
                expectNoRow(await page.evaluate(rowOnPage), 'page 2 in place');
                assert.match(page.url(), /page=2/, 'the address follows');
                await page.locator('#catalogue-results a[rel="prev"]').first().click();
                await page.locator('#catalogue-bundles').waitFor({state: 'attached', timeout: 10000});
            }
            assert.ok(await page.evaluate(() => window.__samePage === true), 'the page never loaded again');
            assert.deepEqual(errors, []);
            await ctx.close();
        });
        await browser.close();
    }
    const failures = results.filter((r) => !r.ok);
    console.log(JSON.stringify({checks: results.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
