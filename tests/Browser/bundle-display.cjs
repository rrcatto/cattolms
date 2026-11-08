/* Bundles beside the courses (owner, 2026-11-06: "Bundle mockup is approved"), in Chromium and Firefox, read from the
 * site's own bundles and never changing one:
 *   - a course's page has, in its Course access card under the buy button, one note for each bundle holding it that the
 *     visitor could buy, the larger saving first: "Also in ...", "This course and the N beside it for ...", a Save badge
 *     and See the bundle with its icon, opening the bundle's page;
 *   - All courses and a category's pages have a section of bundles above the courses (owner, 2026-11-07, the approved
 *     mockup), "Course bundles" with List, Grid and View all bundles in its head: the bundles' own cards, three to a row,
 *     shorter than a course card, in the bundle colour, View bundle at the bottom left under a line, six to a page with
 *     their own pages; the line about bundles stays only where no bundle shows (a category without bundles);
 *   - every bundled theme at 1440 and 390: nothing out of its card or its grid, no sideways scrolling, no inline style,
 *     and the shared page checks (page-construction.cjs);
 *   - a company account sees no note and no row;
 *   - without JavaScript the notes, the section, its pages and See the bundle work, and a search or a later page of
 *     courses keeps the bundles; with it, a search, a page of courses, a page of bundles and List or Grid each change
 *     their own section in place and leave the other exactly as it was, the address naming both, the page never
 *     loading again.
 * What each page should show comes from BundleService itself, through the fixture, which only makes a company account.
 *
 *   podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/bundle-display-fixture.php create'
 *   CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/bundle-display.cjs
 *   podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/bundle-display-fixture.php cleanup'
 */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
// Loaded and settled (fonts in, two frames), never half a second of network quiet: pages are drawn by the server.
const {open: arrive} = require('./pace.cjs');
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
const ALL_LINE = 'Several courses for one price, for less than buying them one by one.';
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
    const response = await arrive(page, `${base}${url}`);
    // The cards' drawings load as they come into view: here every one is asked for, so the check sees them all (with
    // JavaScript; without it a browser loads them at once, and Firefox never settles decode() there), three seconds at most.
    if (await page.evaluate(() => typeof window.htmx !== 'undefined')) {
        await page.evaluate(() => Promise.race([Promise.all([...document.querySelectorAll('img[loading="lazy"]')].map((img) => { img.loading = 'eager'; return img.decode().catch(() => {}); })), new Promise((done) => setTimeout(done, 3000))]));
    }
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
/** What a catalogue page shows of the bundles' section and the line. */
const rowOnPage = () => {
    const row = document.querySelector('#catalogue-bundles');
    const grid = row ? row.querySelector('.cl-bundle-cards') : null;
    const cards = row ? [...row.querySelectorAll('.cl-bundle-card')] : [];
    const courses = [...document.querySelectorAll('#catalogue-results .cl-course-card, #catalogue-results tbody tr')].filter((c) => !c.closest('#catalogue-bundles'));
    const head = row ? row.querySelector('.cl-ui-section-head') : null;
    const link = head ? head.querySelector('a.cl-ui-action[href="/bundles"]') : null;
    const gridBox = grid ? grid.getBoundingClientRect() : null;
    const tops = cards.map((c) => Math.round(c.getBoundingClientRect().top));
    return {
        row: !!row,
        heading: head ? head.querySelector('h2')?.textContent.trim() : null,
        summary: head ? head.querySelector('p')?.textContent.trim() : null,
        link: link ? [link.textContent.replace(/\s+/g, ' ').trim(), link.getAttribute('href'), !!link.querySelector('svg')] : null,
        titles: cards.map((c) => c.querySelector('.cl-bundle-card-title')?.textContent.trim()),
        saves: cards.map((c) => c.querySelector('.cl-bundle-card-badges .cl-ui-badge')?.textContent.replace(/\s+/g, ' ').trim() || null),
        asCourses: row ? row.querySelectorAll('.cl-course-card').length : 0,
        // Each card's View bundle at its bottom left, the line just above it (the card may be as tall as a course card since
        // it has its drawing: owner, 2026-11-07, "You can increase the height of the bundle cards if that helps").
        feet: cards.map((c) => {
            const b = c.getBoundingClientRect(), foot = c.querySelector('.cl-bundle-card-foot'), a = foot?.querySelector('a.cl-ui-action'), title = c.querySelector('.cl-bundle-card-title');
            if (!a) return 'no View bundle';
            const ab = a.getBoundingClientRect(), pad = parseFloat(getComputedStyle(c).paddingBottom);
            if (a.textContent.replace(/\s+/g, ' ').trim() !== 'View bundle') return 'not View bundle';
            if (Math.abs(ab.left - title.getBoundingClientRect().left) > 1) return 'not at the left';
            if (Math.abs(b.bottom - pad - ab.bottom) > 1) return 'not at the bottom';
            if (parseFloat(getComputedStyle(foot).borderTopWidth) < 1) return 'no line above it';
            return 'ok';
        }),
        // Each card's own drawing across its top (owner, 2026-11-07): which have one, and any that did not arrive.
        drawn: cards.filter((c) => c.querySelector('.cl-bundle-card-art img')).map((c) => c.querySelector('.cl-bundle-card-title')?.textContent.trim()),
        unloaded: cards.filter((c) => { const i = c.querySelector('.cl-bundle-card-art img'); return i && !(i.complete && i.naturalWidth > 0); }).length,
        artOnTop: cards.every((c) => { const a = c.querySelector('.cl-bundle-card-art'); return !a || Math.abs(a.getBoundingClientRect().top - c.getBoundingClientRect().top) < 1; }),
        colour: cards.length ? getComputedStyle(cards[0]).backgroundColor !== getComputedStyle(cards[0].closest('.cl-ui-surface')).backgroundColor : false,
        perRow: tops.filter((t) => t === tops[0]).length,
        outOfGrid: gridBox ? cards.filter((c) => { const b = c.getBoundingClientRect(); return b.left < gridBox.left - 0.5 || b.right > gridBox.right + 0.5; }).length : 0,
        aboveCourses: !row || !courses.length || row.getBoundingClientRect().bottom <= courses[0].getBoundingClientRect().top + 1,
        line: [...document.querySelectorAll('.cl-catalogue-bundles')].map((p) => p.textContent.replace(/\s+/g, ' ').trim()),
        styled: row ? row.querySelectorAll('[style]').length : 0,
        sideways: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
    };
};

/** Each bundle card's star against its card (owner, 2026-11-07: "the star needs to be visible when i'm not hovering over
 * it"): its colour and its contrast with the card, as WCAG counts it. */
const starsOnPage = () => {
    const rgb = (value) => {
        const srgb = value.match(/color\(srgb ([\d.]+) ([\d.]+) ([\d.]+)/);
        if (srgb) return srgb.slice(1, 4).map((n) => Number(n) * 255);
        return (value.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
    };
    const luminance = (c) => { const [r, g, b] = c.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
    return [...document.querySelectorAll('#catalogue-bundles .cl-bundle-card')].map((card) => {
        const star = card.querySelector('.cl-favourite');
        if (!star) return null;
        const a = luminance(rgb(getComputedStyle(star).color)), b = luminance(rgb(getComputedStyle(card).backgroundColor));
        return {colour: getComputedStyle(star).color, contrast: Math.round(((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)) * 10) / 10};
    });
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
    titles = titles.slice(0, 6);
    assert.ok(found.row, `${label}: the bundles show`);
    assert.equal(found.heading, 'Course bundles', `${label}: its heading`);
    assert.equal(found.summary, summary, `${label}: its line`);
    assert.deepEqual(found.link, ['View all bundles', '/bundles', true], `${label}: a link to all bundles, with its icon`);
    assert.deepEqual(found.titles, titles, `${label}: the first six bundles, the larger saving first`);
    assert.equal(found.asCourses, 0, `${label}: no bundle drawn as a course`);
    assert.ok(found.colour, `${label}: the cards in the bundle colour`);
    assert.deepEqual(found.drawn, titles.filter((t) => state.drawn.includes(t)), `${label}: each bundle with a drawing has it across the top of its card`);
    assert.equal(found.unloaded, 0, `${label}: every drawing arrives`);
    assert.ok(found.artOnTop, `${label}: the drawing at the very top of its card`);
    assert.deepEqual(found.feet, titles.map(() => 'ok'), `${label}: View bundle at each card's bottom left, under a line`);
    assert.ok(found.saves.some(Boolean), `${label}: the cards say what they save`);
    assert.ok(width >= 1440 ? found.perRow === Math.min(3, titles.length) : found.perRow === 1, `${label}: ${found.perRow} cards to a row`);
    assert.equal(found.outOfGrid, 0, `${label}: no card out of the grid`);
    assert.ok(found.aboveCourses, `${label}: above the courses`);
    assert.deepEqual(found.line, [], `${label}: the bundles replace the line`);
    assert.equal(found.styled, 0, `${label}: no inline style`);
    assert.equal(found.sideways, false, `${label}: no sideways scrolling`);
}
function expectNoRow(found, label, line = true) {
    assert.equal(found.row, false, `${label}: no bundles`);
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
                    expectRow(await page.evaluate(rowOnPage), state.all, ALL_LINE, 'All courses', width);
                    // The star shows on the dark card at rest and changes colour under the pointer (moved away first: Firefox
                    // keeps it where the last page left it, which can be over a star).
                    await page.mouse.move(0, 0);
                    const stars = await page.evaluate(starsOnPage);
                    assert.ok(stars.length > 0 && stars.every((st) => st && st.contrast >= 3), `every bundle card's star shows without hovering (${stars.map((st) => st && st.contrast).join(', ')})`);
                    // Scrolled to first and then pointed at in steps: Firefox does not always take :hover from a pointer that
                    // arrives in one jump across a scroll.
                    const firstStar = page.locator('#catalogue-bundles .cl-bundle-card .cl-favourite').first();
                    await firstStar.scrollIntoViewIfNeeded();
                    const starBox = await firstStar.boundingBox();
                    await page.mouse.move(starBox.x + starBox.width / 2, starBox.y + starBox.height / 2, {steps: 4});
                    await page.waitForFunction(() => document.querySelector('#catalogue-bundles .cl-bundle-card .cl-favourite')?.matches(':hover'), null, {timeout: 5000});
                    // Firefox reports the colour a tick late, through the theme's near-zero transition: up to a second to settle.
                    await page.waitForFunction((rest) => getComputedStyle(document.querySelector('#catalogue-bundles .cl-bundle-card .cl-favourite')).color !== rest, stars[0].colour, {timeout: 1000}).catch(() => {});
                    const hovered = (await page.evaluate(starsOnPage))[0];
                    assert.notEqual(hovered.colour, stars[0].colour, `hovering the star changes its colour (${stars[0].colour} at rest, ${hovered.colour} under the pointer)`);
                    assert.ok(hovered.contrast >= 3, `and it still shows (${hovered.contrast})`);
                    await page.mouse.move(0, 0);
                    await page.locator('#catalogue-bundles').screenshot({path: path.join(output, `${browserName}-${theme.slug}-${width}-all-courses.png`)});
                    if (state.with) {
                        await visit(page, `${state.with.href}?theme_preview=${theme.key}`);
                        expectRow(await page.evaluate(rowOnPage), state.with.row, `Bundles with a course in ${state.with.name}, for less than buying the courses one by one.`, state.with.name, width);
                        await page.screenshot({path: path.join(output, `${browserName}-${theme.slug}-${width}-category.png`), fullPage: true});
                    }
                    if (state.without) {
                        await visit(page, `${state.without.href}?theme_preview=${theme.key}`);
                        expectNoRow(await page.evaluate(rowOnPage), state.without.name);
                    }
                    await visit(page, `/bundles?theme_preview=${theme.key}`);
                    const saves = await page.locator('.cl-bundle-card .cl-bundle-card-badges').count();
                    assert.ok(saves >= Math.min(24, state.all.length), `the Bundles page's own cards say what they save (${saves})`);
                    assert.equal(await page.locator('.cl-course-card').count(), 0, 'no bundle drawn as a course on the Bundles page');
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

        await scenario(`${browserName}: without JavaScript the notes, the bundles, their pages and See the bundle work, and a search or a later page keeps the bundles`, async () => {
            const {ctx, page} = await open(browser, {js: false});
            await visit(page, coursePath);
            expectNotes(await page.evaluate(notesOnPage), 'course page');
            await Promise.all([page.waitForNavigation(), page.locator('.cl-course-bundle').first().getByRole('link', {name: 'See the bundle'}).click()]);
            assert.equal(new URL(page.url()).pathname, state.course.notes[0].href, 'See the bundle opens the bundle');
            assert.ok(clean(await page.locator('main h1').innerText()).includes(state.course.notes[0].title), 'the bundle\'s own page');
            // Its page opens as its card does: its drawing, then the bundle colour with its name (owner, 2026-11-07).
            const head = await page.evaluate(() => { const h = document.querySelector('.cl-bundle-head'); return h ? {art: !!h.querySelector('.cl-bundle-head-art svg'), title: !!h.querySelector('.cl-bundle-head-band h1'), artFirst: h.firstElementChild?.classList.contains('cl-bundle-head-art')} : null; });
            assert.ok(head && head.title, 'the bundle\'s name in its page\'s band');
            if (state.drawn.includes(state.course.notes[0].title)) assert.ok(head.art && head.artFirst, 'its drawing at the head of its page');
            await visit(page, '/courses');
            expectRow(await page.evaluate(rowOnPage), state.all, ALL_LINE, 'All courses', 1440);
            // A later page, where the site has one (BundleDisplayIntegrationTest makes one for itself).
            const later = await page.locator('#catalogue-results > .cl-ui-surface:last-child a[rel="next"]').count() > 0;
            await visit(page, '/courses?catalogue_q=course');
            expectRow(await page.evaluate(rowOnPage), state.all, ALL_LINE, 'a search', 1440);
            if (later) {
                await visit(page, '/courses?page=2');
                expectRow(await page.evaluate(rowOnPage), state.all, ALL_LINE, 'page 2 of courses', 1440);
            } else console.log('NOTE: All courses has one page here, so no later page was seen without JavaScript.');
            if (state.all.length > 6) {
                await Promise.all([page.waitForNavigation(), page.locator('#catalogue-bundles a[rel="next"]').click()]);
                assert.deepEqual((await page.evaluate(rowOnPage)).titles, state.all.slice(6, 12), 'the bundles\' second page');
                assert.match(page.url(), /bundles_page=2/, 'its address');
            }
            await ctx.close();
        });

        await scenario(`${browserName}: a search, a page of courses, a page of bundles and List or Grid each change their own section in place`, async () => {
            const {ctx, page, errors} = await open(browser);
            await visit(page, '/courses?catalogue_bundles_view=grid');
            await page.evaluate(() => { window.__samePage = true; });
            const mark = (selector) => page.evaluate((sel) => { window.__kept = document.querySelector(sel); }, selector);
            const kept = (selector) => page.evaluate((sel) => window.__kept === document.querySelector(sel), selector);
            const titles = () => page.evaluate(() => [...document.querySelectorAll('.cl-bundle-card-title, .cl-bundle-list-title')].map((t) => t.textContent.trim()));
            await mark('.cl-bundle-card');
            await page.locator('#catalogue-search-input').fill('course');
            await page.waitForFunction(() => location.search.includes('catalogue_q=course'), null, {timeout: 10000});
            await page.waitForFunction(() => document.querySelector('#catalogue-results > .cl-ui-surface:last-child h2')?.textContent.trim() === 'Search results', null, {timeout: 10000});
            assert.ok(await kept('.cl-bundle-card'), 'a search leaves the bundles exactly as they were');
            await page.locator('#catalogue-search-input').fill('');
            // An emptied search asks for catalogue_q= and the courses' head says Courses again.
            await page.waitForFunction(() => document.querySelector('#catalogue-results > .cl-ui-surface:last-child h2')?.textContent.trim() === 'Courses', null, {timeout: 10000});
            const next = page.locator('#catalogue-results > .cl-ui-surface:last-child a[rel="next"]').first();
            if (!(await next.count())) console.log('NOTE: All courses has one page here, so no page of courses was turned in place.');
            else {
                await mark('.cl-bundle-card');
                await next.click();
                await page.waitForFunction(() => /[?&]page=2/.test(location.search), null, {timeout: 10000});
                assert.ok(await kept('.cl-bundle-card'), 'a page of courses leaves the bundles exactly as they were');
            }
            if (state.all.length > 6) {
                const before = page.url();
                await mark('#catalogue-results > .cl-ui-surface:last-child .cl-course-card, #catalogue-results > .cl-ui-surface:last-child tbody tr');
                await page.locator('#catalogue-bundles a[rel="next"]').click();
                await page.waitForFunction(() => location.search.includes('bundles_page=2'), null, {timeout: 10000});
                await page.waitForFunction((want) => document.querySelector('.cl-bundle-card-title')?.textContent.trim() === want, state.all[6], {timeout: 10000});
                assert.ok(await kept('#catalogue-results > .cl-ui-surface:last-child .cl-course-card, #catalogue-results > .cl-ui-surface:last-child tbody tr'), 'a page of bundles leaves the courses exactly as they were');
                if (/[?&]page=2/.test(before)) assert.match(page.url(), /[?&]page=2/, 'the address keeps the courses\' page');
            }
            const shown = await titles();
            await mark('#catalogue-results > .cl-ui-surface:last-child .cl-course-card');
            await page.locator('#catalogue-bundles').getByRole('link', {name: 'Show as a list'}).click();
            await page.locator('.cl-bundle-list').waitFor({timeout: 10000});
            assert.deepEqual(await titles(), shown, 'the same bundles as a list');
            assert.deepEqual(await page.evaluate(() => [...document.querySelectorAll('.cl-bundle-list thead th')].map((t) => t.textContent.trim()).slice(0, 5)), ['Bundle', 'No of courses', 'Description', 'Access', 'Price'], 'the list\'s columns');
            assert.ok(await kept('#catalogue-results > .cl-ui-surface:last-child .cl-course-card'), 'List leaves the courses exactly as they were');
            await page.locator('#catalogue-bundles').getByRole('link', {name: 'Show as a grid of cards'}).click();
            await page.locator('#catalogue-bundles .cl-bundle-cards').waitFor({timeout: 10000});
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
