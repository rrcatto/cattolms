/* The home page's rotating Popular Courses in a real browser, in Chromium and Firefox and without
 * JavaScript: groups change on the interval and cycle back, rotation never asks the server for
 * anything, Pause and Resume work from the keyboard, hovering or focusing the grid holds the current
 * group, hidden groups cannot be reached by keyboard, reduced motion starts paused and changes groups
 * without a fade, a favourite star keeps its state across a full cycle, card links work, and the
 * grid fits a desktop row and a phone.
 *
 * Time is Playwright's fake clock, so a 20-second interval is jumped rather than waited for. Run
 * against the development instance with the fixture from popular-courses-fixture.php. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-popular-courses-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-popular-courses';
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/popular-courses-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const reset = () => JSON.parse(php('reset').trim().split('\n').pop());
const results = [], failures = [];

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${error.message.split('\n')[0]}`);
    }
}

const GROUP = '#popular-courses [data-popular-courses-target="group"]';
const visibleGroup = (page) => page.locator(GROUP).evaluateAll((groups) => groups.findIndex((g) => !g.hidden));
const shownGroups = (page) => page.locator(GROUP).evaluateAll((groups) => groups.filter((g) => !g.hidden).length);
const groupCount = (page) => page.locator(GROUP).count();
const tick = (page, seconds) => page.clock.fastForward(seconds * 1000);
const awayFromGrid = async (page) => { await page.mouse.move(5, 5); await page.evaluate(() => document.activeElement?.blur()); };

/* A page with the fake clock, loaded and with the controller connected (it reveals one control). */
async function open(browser, {token = null, width = 1440, reducedMotion = 'no-preference'} = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 1100}, reducedMotion});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    await page.clock.install();
    await page.goto(`${base}/`);
    await page.locator('#popular-courses-pause:visible, #popular-courses-resume:visible').first().waitFor();
    await awayFromGrid(page);
    return {ctx, page};
}

(async () => {
    let state = reset();
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();

        await scenario('groups rotate in order, cycle back, and never ask the server', browserName, async () => {
            const {ctx, page} = await open(browser);
            const groups = await groupCount(page);
            assert.ok(groups >= 2, `the pool needs more than one group (found ${groups})`);
            const requests = [];
            page.on('request', (request) => requests.push(request.url()));
            assert.equal(await visibleGroup(page), 0);
            // The fake clock also runs in real time after install, so leave room for a slow page load.
            await tick(page, 15);
            assert.equal(await visibleGroup(page), 0, 'nothing changes before the interval');
            for (let expected = 1; expected <= groups; expected++) {
                await tick(page, 20);
                assert.equal(await visibleGroup(page), expected % groups, `group ${expected % groups + 1} after ${expected} intervals`);
                assert.equal(await shownGroups(page), 1, 'exactly one group is shown');
            }
            assert.deepEqual(requests, [], 'rotation made no requests');
            await page.screenshot({path: `${output}/${browserName}-desktop.png`});
            await ctx.close();
        });

        await scenario('Pause and Resume work from the keyboard', browserName, async () => {
            const {ctx, page} = await open(browser);
            await page.locator('#popular-courses-pause').focus();
            await page.keyboard.press('Enter');
            assert.ok(await page.locator('#popular-courses-resume').isVisible(), 'Resume replaces Pause');
            assert.ok(await page.locator('#popular-courses-pause').isHidden());
            assert.equal(await page.evaluate(() => document.activeElement?.id), 'popular-courses-resume', 'focus stays on the control');
            assert.equal(await page.locator('#popular-courses-groups').getAttribute('aria-live'), 'polite');
            await tick(page, 90);
            assert.equal(await visibleGroup(page), 0, 'paused: no change');
            await page.keyboard.press('Enter');
            assert.equal(await page.evaluate(() => document.activeElement?.id), 'popular-courses-pause');
            assert.equal(await page.locator('#popular-courses-groups').getAttribute('aria-live'), 'off');
            await tick(page, 20);
            assert.equal(await visibleGroup(page), 1, 'resumed: rotation continues');
            await ctx.close();
        });

        await scenario('hovering or focusing the grid holds the group, and hidden groups are out of reach', browserName, async () => {
            const {ctx, page} = await open(browser);
            // The section is below the hero; hover() scrolls it into view first.
            await page.locator('#popular-courses-groups').hover({position: {x: 60, y: 40}});
            await tick(page, 60);
            assert.equal(await visibleGroup(page), 0, 'no change under the pointer');
            await page.mouse.move(5, 5);
            await tick(page, 20);
            assert.equal(await visibleGroup(page), 1, 'rotation resumes once the pointer leaves');

            const firstLink = page.locator(`${GROUP}:not([hidden]) .cl-course-card-title a`).first();
            await firstLink.focus();
            await tick(page, 60);
            assert.equal(await visibleGroup(page), 1, 'no change while focus is inside');
            for (let i = 0; i < 25; i++) {
                await page.keyboard.press('Tab');
                const inHidden = await page.evaluate(() => !!document.activeElement?.closest('[data-popular-courses-target="group"][hidden]'));
                assert.equal(inHidden, false, 'Tab never reaches a hidden group');
            }
            const hiddenFocusable = await page.locator(`${GROUP}[hidden] a, ${GROUP}[hidden] button`).evaluateAll((els) => els.filter((el) => el.getClientRects().length > 0).length);
            assert.equal(hiddenFocusable, 0, 'hidden groups have no rendered controls');
            await awayFromGrid(page);
            await tick(page, 20);
            assert.equal(await visibleGroup(page), 2, 'rotation resumes once focus leaves');
            await ctx.close();
        });

        await scenario('reduced motion starts paused and changes groups without a fade', browserName, async () => {
            const {ctx, page} = await open(browser, {reducedMotion: 'reduce'});
            assert.ok(await page.locator('#popular-courses-resume').isVisible(), 'starts paused, offering Resume');
            await tick(page, 90);
            assert.equal(await visibleGroup(page), 0);
            await page.locator('#popular-courses-resume').click();
            await awayFromGrid(page);
            await tick(page, 20);
            assert.equal(await visibleGroup(page), 1);
            assert.equal(await page.locator(`${GROUP}.is-entering`).count(), 0, 'no fade');
            await ctx.close();

            const motion = await open(browser);
            await tick(motion.page, 20);
            assert.equal(await motion.page.locator(`${GROUP}.is-entering`).count(), 1, 'with motion allowed the new group fades in');
            await motion.ctx.close();
        });

        await scenario('a favourite star keeps its state across a full cycle', browserName, async () => {
            state = reset();
            const {ctx, page} = await open(browser, {token: state.token});
            const groups = await groupCount(page);
            const star = page.locator(`${GROUP}:not([hidden]) .cl-favourite`).first();
            const form = await star.evaluate((el) => el.closest('form').id);
            await star.click();
            await page.locator(`#${form} .cl-favourite[aria-pressed="true"]`).waitFor();
            await awayFromGrid(page);
            for (let i = 0; i < groups; i++) await tick(page, 20);
            assert.equal(await visibleGroup(page), 0, 'back at the first group');
            assert.equal(await page.locator(`#${form} .cl-favourite`).getAttribute('aria-pressed'), 'true', 'the star is still on');
            await page.reload();
            await page.locator(`#${form} .cl-favourite[aria-pressed="true"]`).waitFor();
            await ctx.close();
            state = reset();
        });

        await scenario('a card link opens its course, and the grid fits desktop and phone', browserName, async () => {
            const {ctx, page} = await open(browser);
            const rows = await page.locator(`${GROUP}:not([hidden]) .cl-course-card`).evaluateAll((cards) => new Set(cards.map((c) => Math.round(c.getBoundingClientRect().top))).size);
            assert.equal(rows, 1, 'four cards in one row on a wide screen');
            const link = page.locator(`${GROUP}:not([hidden]) .cl-course-card-title a`).first();
            const href = await link.getAttribute('href');
            const response = await Promise.all([page.waitForNavigation(), link.click()]).then(([r]) => r);
            assert.equal(new URL(page.url()).pathname, href);
            assert.equal(response.status(), 200);
            await ctx.close();
            const phone = await open(browser, {width: 390});
            assert.ok(await phone.page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), 'no sideways scroll at 390px');
            await phone.page.screenshot({path: `${output}/${browserName}-phone.png`});
            await phone.ctx.close();
        });

        await browser.close();
    }

    await scenario('without JavaScript the first group shows and nothing else', 'chromium-nojs', async () => {
        const browser = await playwright.chromium.launch();
        const ctx = await browser.newContext({ignoreHTTPSErrors: true, javaScriptEnabled: false, viewport: {width: 1440, height: 1100}});
        const page = await ctx.newPage();
        await page.goto(`${base}/`);
        assert.equal(await shownGroups(page), 1);
        assert.equal(await visibleGroup(page), 0);
        assert.equal(await page.locator(`${GROUP}:not([hidden]) .cl-course-card`).count(), 4, 'a full first group');
        assert.ok(await page.locator('#popular-courses-pause').isHidden(), 'no Pause without rotation');
        assert.ok(await page.locator('#popular-courses-resume').isHidden());
        const link = page.locator(`${GROUP}:not([hidden]) .cl-course-card-title a`).first();
        const href = await link.getAttribute('href');
        await Promise.all([page.waitForNavigation(), link.click()]);
        assert.equal(new URL(page.url()).pathname, href, 'card links are ordinary links');
        await browser.close();
    });

    console.log(results.map((r) => `PASS ${r}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
