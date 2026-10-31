/* A course's catalogue page (owner, 2026-10-30, the accepted mockup, its head kept as it was): the cover with the title,
 * one buy box (under Course access), the page in rows (About beside How each module works, the modules across the
 * page, At a glance beside What you get, the reviews, Course access), the modules with how a module is made, and "Watch course presentation" opening the
 * trailer in a window as wide as the page's box, closed by its Close button, by Esc and by the slides' Get this course
 * (which then shows Course access); without JavaScript the button opens the trailer in the free preview. Chromium and
 * Firefox, at 1440 and 390.
 *
 *   podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/course-page-fixture.php create'
 *   CATTO_PLAYWRIGHT_MODULE=$HOME/tools/playwright/node_modules/playwright node tests/Browser/course-page.cjs
 *   podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/course-page-fixture.php cleanup'
 */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(execFileSync('podman', ['exec', 'env_php_1', 'cat', '/tmp/catto-course-page-state.json'], {encoding: 'utf8'}));
const page_url = `${base}/courses/${state.slug}`;
// The rows the page should draw, from the site's default layout as the fixture found it (never assumed: the owner chooses
// it; Course first when none is saved) and the blocks this course has something for: no review, no related course.
const COURSE_FIRST = [['about', 'half'], ['how', 'half'], ['modules', 'full'], ['glance', 'half'], ['get', 'half'], ['reviews', 'full'], ['access', 'full'], ['start', 'full'], ['related', 'full']].map(([id, width]) => ({id, width, shown: true}));
const PRESENT = ['about', 'how', 'modules', 'glance', 'get', 'access', 'start'];
const rows = [];
let open = null;
for (const block of (state.default_layout ? JSON.parse(state.default_layout) : COURSE_FIRST).filter((b) => b.shown && PRESENT.includes(b.id))) {
    if (block.width === 'half' && open !== null) { rows[open].push(block.id); open = null; continue; }
    rows.push([block.id]);
    open = block.width === 'half' ? rows.length - 1 : null;
}
const rowOf = (id) => rows.findIndex((row) => row.includes(id));
const results = [];

async function scenario(name, run) {
    try { await run(); results.push({name, ok: true}); console.log(`PASS ${name}`); }
    catch (error) { results.push({name, ok: false, error: error.message}); console.log(`FAIL ${name}: ${error.message}`); }
}

(async () => {
    for (const browserName of ['chromium', 'firefox']) {
        const browser = await playwright[browserName].launch();
        for (const width of [1440, 390]) {
            await scenario(`${browserName} ${width}: the page, its modules, one buy box and the presentation window`, async () => {
                const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, reducedMotion: 'reduce'});
                const page = await ctx.newPage();
                const errors = []; page.on('pageerror', (e) => errors.push(e.message));
                assert.equal((await page.goto(page_url, {waitUntil: 'networkidle'})).status(), 200);
                assert.equal(await page.locator('.cl-course-purchase').count(), 1, 'one buy box');
                assert.equal(await page.locator('.cl-page-section:has(#course-access) .cl-course-purchase').count(), 1, 'under Course access');
                // The page in the layout's rows (owner, 2026-10-30): two half blocks side by side on a wide screen, every block
                // in the layout's order, one above the other on a phone.
                const at = Object.fromEntries(await Promise.all([['about', 'h2:text-is("About this course")'], ['how', 'h2:text-is("How each module works")'], ['modules', '#course-modules']].map(async ([id, s]) => [id, await page.locator(s).boundingBox()])));
                for (const [a, b] of [['about', 'how'], ['about', 'modules'], ['how', 'modules']]) {
                    const [first, second] = rowOf(a) < rowOf(b) || (rowOf(a) === rowOf(b) && rows[rowOf(a)].indexOf(a) < rows[rowOf(a)].indexOf(b)) ? [a, b] : [b, a];
                    if (width === 1440 && rowOf(a) === rowOf(b)) assert.ok(Math.abs(at[a].y - at[b].y) < 2 && at[second].x > at[first].x, `${a} and ${b} share a row`);
                    else assert.ok(at[second].y > at[first].y + 1, `${second} comes after ${first}`);
                }
                assert.equal(await page.locator('.cl-picture-head .cl-course-detail-headline h1').count(), 1, 'the title over the cover');
                assert.deepEqual(await page.locator('.cl-course-modules .cl-course-module-title').allTextContents(), state.modules, 'the modules, in order');
                assert.match(await page.locator('.cl-course-module-paragraph').textContent(), /^Every module opens with a presentation/, 'how a module is made');
                assert.equal(await page.locator('.cl-course-modules a[href$="/preview"]').count(), 1, 'the first module is the free preview');
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, 'no sideways scrolling');

                const watch = page.getByRole('link', {name: 'Watch course presentation'});
                await watch.click();
                const modal = page.locator('#course-presentation');
                await modal.locator('.cw-deck.cw-ready').waitFor();
                assert.ok(await modal.evaluate((m) => m.classList.contains('open')), 'the window opens');
                if (width === 1440) {
                    const [card, row] = await Promise.all([modal.locator('.cl-ui-modal-card').boundingBox(), page.locator('.cl-page-section').first().boundingBox()]);
                    assert.ok(card.width >= row.width - 60, `the window is as wide as the page's box (${Math.round(card.width)} of ${Math.round(row.width)})`);
                    const deck = await modal.locator('.cw-deck').boundingBox();
                    assert.ok(deck.y + deck.height <= 900, 'the slides fit the screen');
                }
                await page.keyboard.press('Escape');
                assert.equal(await modal.evaluate((m) => m.classList.contains('open')), false, 'Esc closes it');
                await watch.click();
                await modal.locator('.cl-ui-modal-foot').getByRole('button', {name: 'Close'}).click();
                assert.equal(await modal.evaluate((m) => m.classList.contains('open')), false, 'Close at its foot closes it');
                await watch.click();
                await modal.locator('.cl-ui-modal-head').getByRole('button', {name: 'Close'}).click();
                assert.equal(await modal.evaluate((m) => m.classList.contains('open')), false, 'and so does the × in its head');
                await watch.click();
                await modal.locator('.cw-deck').focus();
                await page.keyboard.press('End');
                await modal.locator('a.cw-action[href="#course-access"]').first().click();
                assert.equal(await modal.evaluate((m) => m.classList.contains('open')), false, 'Get this course closes the window');
                assert.ok(await page.locator('#course-access').isVisible(), 'and shows Course access');
                assert.deepEqual(errors, []);
                await ctx.close();
            });
        }
        await scenario(`${browserName}: without JavaScript the button opens the trailer in the free preview`, async () => {
            const ctx = await browser.newContext({ignoreHTTPSErrors: true, javaScriptEnabled: false, reducedMotion: 'reduce'});
            const page = await ctx.newPage();
            await page.goto(page_url);
            const href = await page.getByRole('link', {name: 'Watch course presentation'}).getAttribute('href');
            assert.equal(href, `/courses/${state.slug}/preview/${state.trailer_node}/content`);
            assert.equal((await page.goto(base + href)).status(), 200);
            assert.ok(await page.locator('.cw-deck').count() > 0, 'the trailer plays there');
            await ctx.close();
        });
        await browser.close();
    }
    const failures = results.filter((r) => !r.ok);
    console.log(JSON.stringify({checks: results.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
