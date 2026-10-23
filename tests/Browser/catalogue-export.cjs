/* Choosing the courses to export at Administration -> Courses -> Import & Export Catalogue, in Chromium and Firefox and
 * without JavaScript (owner, 2026-10-23: "manually ticking the courses does not change this msg ... the clear ticks
 * button also does not work if I manually tick"): the count follows boxes ticked by hand; Clear the ticks clears them;
 * Tick every course and Tick the published courses tick the right boxes; without JavaScript each of the three reloads
 * the page with the boxes ticked; and the package downloaded holds exactly the courses ticked by hand.
 *
 * Run against the development instance with the section pages fixture (an ADMIN session):
 *   section-pages-fixture.php create, its state copied to /tmp/catto-section-pages-state.json, then cleanup. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-catalogue-export';
fs.mkdirSync(output, {recursive: true});
const state = JSON.parse(fs.readFileSync(statePath, 'utf8'));
const results = [], failures = [];
const PAGE = `${base}/admin/courses/catalogue`;
const BOX = 'input[name="courses[]"]';
const COUNT = '[data-catalogue-export-target="count"]';

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${error.message.split('\n')[0]}`);
    }
}

async function open(browser, javaScriptEnabled = true) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1100}, javaScriptEnabled, acceptDownloads: true});
    await ctx.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    await page.goto(PAGE, {waitUntil: 'networkidle'});
    return {ctx, page};
}
const ticked = (page) => page.locator(`${BOX}:checked`).count();
const count = async (page) => (await page.locator(COUNT).innerText()).trim();
const words = (n) => (n === 0 ? 'No course is ticked.' : `${n} ${n === 1 ? 'course is' : 'courses are'} ticked.`);
const published = (page) => page.locator(`${BOX}[data-status="published"]`).count();
/* The codes in a downloaded package's catalogue.json. */
const packaged = (file) => JSON.parse(execFileSync('python3', ['-I', '-c', "import json,sys,zipfile; print(json.dumps(json.loads(zipfile.ZipFile(sys.argv[1]).read('catalogue.json'))['courses']))", file], {encoding: 'utf8'}));

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();

        await scenario('ticking boxes by hand keeps the count right', browserName, async () => {
            const {ctx, page} = await open(browser);
            assert.equal(await ticked(page), 0, 'nothing is ticked at first');
            assert.equal(await count(page), words(0));
            await page.locator(BOX).nth(0).check();
            await page.locator(BOX).nth(1).check();
            assert.equal(await count(page), words(2), 'two ticked by hand');
            await page.locator(BOX).nth(0).uncheck();
            assert.equal(await count(page), words(1), 'one unticked by hand');
            await ctx.close();
        });

        await scenario('Clear the ticks clears boxes ticked by hand, in place', browserName, async () => {
            const {ctx, page} = await open(browser);
            for (const n of [0, 1, 2]) await page.locator(BOX).nth(n).check();
            const before = page.url();
            await page.getByRole('button', {name: 'Clear the ticks'}).click();
            assert.equal(await ticked(page), 0, 'every box is clear');
            assert.equal(await count(page), words(0));
            assert.equal(page.url(), before, 'the page is not reloaded');
            await ctx.close();
        });

        await scenario('Tick every course and Tick the published courses tick the right boxes, in place', browserName, async () => {
            const {ctx, page} = await open(browser);
            const all = await page.locator(BOX).count();
            const before = page.url();
            await page.getByRole('button', {name: 'Tick every course'}).click();
            assert.equal(page.url(), before, 'the page is not reloaded');
            assert.equal(await ticked(page), all);
            assert.equal(await count(page), words(all));
            await page.getByRole('button', {name: 'Tick the published courses'}).click();
            const shown = await published(page);
            assert.equal(await ticked(page), shown, 'only the published courses');
            assert.equal(await page.locator(`${BOX}[data-status="published"]:not(:checked)`).count(), 0);
            assert.equal(await count(page), words(shown));
            await ctx.close();
        });

        await scenario('the package holds exactly the courses ticked by hand', browserName, async () => {
            const {ctx, page} = await open(browser);
            const box = page.locator(BOX).first();
            await box.check();
            const code = (await box.getAttribute('aria-label')).split(' ')[1];
            const [download] = await Promise.all([page.waitForEvent('download', {timeout: 120000}), page.getByRole('button', {name: 'Download catalogue package (.zip)'}).click()]);
            const saved = path.join(output, `${browserName}-one.zip`);
            await download.saveAs(saved);
            assert.deepEqual(packaged(saved), [code]);
            fs.rmSync(saved);
            await ctx.close();
        });
        await browser.close();
    }

    await scenario('without JavaScript each tick button reloads the page with the boxes ticked', 'no JavaScript', async () => {
        const browser = await playwright.chromium.launch();
        const {page} = await open(browser, false);
        for (const n of [0, 1]) await page.locator(BOX).nth(n).check();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Clear the ticks'}).click()]);
        assert.equal(await ticked(page), 0, 'Clear the ticks clears boxes ticked by hand');
        assert.equal(await count(page), words(0));
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Tick the published courses'}).click()]);
        const shown = await published(page);
        assert.equal(await ticked(page), shown);
        assert.equal(await count(page), words(shown), 'the count is drawn with the page');
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Tick every course'}).click()]);
        assert.equal(await ticked(page), await page.locator(BOX).count());
        await browser.close();
    });

    console.log(results.map((r) => `PASS ${r}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
