/* The Page Builder for course pages (owner, 2026-10-30, the accepted mockup), in Chromium and Firefox, and without JavaScript:
   - a course's Page layout tab: on the default layout the board shows it locked; Custom layout unlocks it, and beside the
     choice a notice says it is not saved, with Save layout (2026-10-31); a
     template applied, a block hidden and shown, moved with its arrows, dragged by its handle and made full width, the
     sketch and the templates' In use following; leaving with changes asks first; Save layout keeps it;
   - the course's page draws its blocks in that order, two half blocks sharing a row, a block with nothing to show left out;
   - Reset to the default layout and Save: the course follows Settings > Course pages again, whatever the site's default
     is (never assumed), where the default is changed (Reviews first) and set to Course first; the fixture puts the site's
     own default back at cleanup;
   - without JavaScript every button sends the form and the server saves what it asks;
   - the tab and Settings are built as every page is (page-construction.cjs), at 1440 and 390px; on a phone the board is
     one column.
   Fixture: php tests/Browser/course-page-fixture.php create|cleanup (in env_php_1), the same as course-page.cjs. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {inspect, judge} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-page-state.json'));
const tab = `${base}/admin/courses/${state.course_id}?tab=page-layout`;
const settings = `${base}/admin/settings?section=course-pages#settings-course-pages`;
const coursePage = `${base}/courses/${state.slug}`;
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: !!ok, detail}); if (!ok) console.log('FAIL', name, detail); };

const BLOCKS = [['about', /^About this course$/], ['how', /^How each module works$/], ['modules', /^The \d+ modules?$|^Course Content$/], ['glance', /^At a glance$/],
    ['get', /^What you get$/], ['reviews', /^Reviews$/], ['access', /^Course access$/], ['start', /^Ready to start/], ['related', /^Related courses$/]];

async function session(browser, options = {}) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 2200}, ...options});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return context;
}

const order = (page) => page.locator('#page-layout-form input[name="order"]').inputValue();
const board = (page) => page.locator('#page-layout-form .cl-page-builder-board > [data-block]').evaluateAll((tiles) => tiles.map((tile) => tile.dataset.block));
const shelf = (page) => page.locator('#page-layout-form .cl-page-builder-shelf [data-block]').evaluateAll((tiles) => tiles.map((tile) => tile.dataset.block));
// The layout the builder shows, block by block: whatever the site's default is, never assumed (owner, 2026-10-24: no
// check depends on a setting the owner chose).
const layoutOf = (page) => page.evaluate(() => {
    const form = document.querySelector('#page-layout-form');
    return form.querySelector('input[name="order"]').value.split(',').map((id) => {
        const tile = form.querySelector(`.cl-page-builder-tile[data-block="${id}"]`);
        return {id, width: tile.dataset.width, shown: tile.querySelector('input[name^="shown["]').value === '1'};
    });
});
// The fixture's course has no review and no related course, so those blocks are never drawn.
const PRESENT = ['about', 'how', 'modules', 'glance', 'get', 'access', 'start'];
const drawn = (layout) => layout.filter((block) => block.shown && PRESENT.includes(block.id)).map((block) => block.id).join(',');
const inUse = (page) => page.locator('#page-layout-form .cl-page-builder-template[data-current="true"]').evaluateAll((cards) => cards.map((card) => card.dataset.template));

// The course page's blocks in order, by their headings, and whether two of them share a row.
async function blocks(page) {
    await page.goto(coursePage, {waitUntil: 'networkidle'});
    const headings = await page.locator('main section.cl-page-section h2').allInnerTexts();
    return headings.map((text) => (BLOCKS.find(([, pattern]) => pattern.test(text.trim())) || [null])[0]).filter(Boolean);
}
const shareRow = (page, first, second) => page.evaluate(([a, b]) => {
    const section = (text) => [...document.querySelectorAll('main section.cl-page-section')].find((s) => [...s.querySelectorAll('h2')].some((h) => h.textContent.trim() === text));
    const one = section(a);
    return !!one && one === section(b);
}, [first, second]);

async function drag(page, from, to) {
    const handle = page.locator(`.cl-page-builder-board [data-block="${from}"] .cl-page-builder-handle`);
    const target = page.locator(`.cl-page-builder-board [data-block="${to}"]`);
    await page.locator('.cl-page-builder-board').evaluate((list) => list.scrollIntoView({block: 'start', behavior: 'instant'}));
    const a = await handle.boundingBox();
    const b = await target.boundingBox();
    await page.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
    await page.mouse.down();
    await page.mouse.move(a.x + a.width / 2 + 4, a.y + a.height / 2 + 4, {steps: 3});
    await page.mouse.move(b.x + 30, b.y + 20, {steps: 12});
    await page.mouse.move(b.x + 32, b.y + 20, {steps: 2});
    await page.waitForTimeout(100);
    await page.mouse.up();
}

async function withScript(browserType, name) {
    const browser = await browserType.launch();
    const context = await session(browser, {reducedMotion: 'reduce'});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(tab, {waitUntil: 'networkidle'});
    check(`${name}: the course starts on the site's default, the board locked`, await page.locator('input[name="source"][value="default"]').isChecked()
        && await page.locator('#page-layout-form [data-locked]').count() === 2 && await page.locator('.cl-page-builder-board button[value^="move:"]:not(:disabled)').count() === 0);
    const siteDefault = await layoutOf(page);
    check(`${name}: the course page follows the site's default`, (await blocks(page)).join(',') === drawn(siteDefault), (await blocks(page)).join(','));
    await page.goto(tab, {waitUntil: 'networkidle'});
    await page.locator('label:has(input[name="source"][value="own"])').click();
    check(`${name}: Custom layout unlocks the board`, await page.locator('#page-layout-form [data-locked]').count() === 0);
    // Save layout beside the choice, with a notice that the choice is not saved yet (owner, 2026-10-31, option B).
    const bar = page.locator('.cl-page-builder-part:has(#page-layout-source) .cl-page-builder-savebar');
    check(`${name}: beside the choice, a notice says it is not saved, with Save layout`, (await bar.locator('.cl-page-builder-status').innerText()).startsWith('Not saved yet')
        && await bar.locator('.cl-page-builder-status[data-unsaved]').count() === 1 && await bar.getByRole('button', {name: 'Save layout'}).isVisible());
    check(`${name}: leaving now asks first`, await page.locator('.cl-group-tab').first().getAttribute('data-confirm') !== null);

    await page.getByRole('button', {name: 'Start from Buy first'}).click();
    check(`${name}: Buy first applied in place`, await order(page) === 'access,about,glance,modules,how,get,reviews,start,related' && JSON.stringify(await inUse(page)) === '["buy"]', await order(page));
    await page.getByRole('button', {name: 'Hide Reviews'}).click();
    check(`${name}: Reviews hidden goes to the shelf, out of the sketch`, JSON.stringify(await shelf(page)) === '["reviews"]'
        && await page.locator('.cl-page-sketch [data-block="reviews"]').isHidden() && await page.locator('.cl-page-builder-shelf-empty').isHidden());
    check(`${name}: no template is in use any more`, (await inUse(page)).length === 0);
    await page.getByRole('button', {name: 'Show Reviews'}).click();
    check(`${name}: Show puts it back in its place`, (await board(page)).join(',') === 'access,about,glance,modules,how,get,reviews,start,related' && await page.locator('.cl-page-builder-shelf-empty').isVisible());
    await page.getByRole('button', {name: 'Hide Reviews'}).click();
    await page.getByRole('button', {name: 'Move The modules up'}).click();
    check(`${name}: an arrow moves a block a place`, (await board(page)).join(',') === 'access,about,modules,glance,how,get,start,related', (await board(page)).join(','));
    check(`${name}: the first block cannot go up`, await page.getByRole('button', {name: 'Move Course access up'}).isDisabled());
    await drag(page, 'related', 'about');
    check(`${name}: a block dragged by its handle`, (await board(page)).join(',') === 'access,related,about,modules,glance,how,get,start', (await board(page)).join(','));
    await page.locator('[data-block="get"] label:has(input[value="full"])').click();
    check(`${name}: What you get made full width, in the sketch too`, await page.locator('.cl-page-builder-board [data-block="get"]').getAttribute('data-width') === 'full'
        && await page.locator('.cl-page-sketch [data-block="get"]').getAttribute('data-width') === 'full');
    const arranged = await order(page);
    await page.getByRole('button', {name: 'Save layout'}).first().click();
    await page.waitForLoadState('networkidle');
    check(`${name}: Save layout keeps it`, await page.locator('input[name="source"][value="own"]').isChecked() && await order(page) === arranged
        && (await page.locator('.cl-flash-stack').last().innerText()).includes('was saved'), await order(page));
    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));

    // The course page in that order: Reviews hidden (it has none anyway), Related courses left out (none ticked).
    const shown = await blocks(page);
    check(`${name}: the course page draws its blocks in the saved order`, shown.join(',') === 'access,about,modules,glance,how,get,start', shown.join(','));
    check(`${name}: At a glance and How each module works, both half, share a row`, await shareRow(page, 'At a glance', 'How each module works'));
    check(`${name}: About this course, half with no half beside it, has a row of its own`, !(await shareRow(page, 'About this course', 'The 2 modules')));

    // Back to the site's default.
    await page.goto(tab, {waitUntil: 'networkidle'});
    await page.getByRole('button', {name: 'Reset to the default layout'}).click();
    check(`${name}: Reset shows the default again, locked`, await page.locator('input[name="source"][value="default"]').isChecked() && await page.locator('#page-layout-form [data-locked]').count() === 2
        && await order(page) === siteDefault.map((block) => block.id).join(','));
    await page.getByRole('button', {name: 'Save layout'}).first().click();
    await page.waitForLoadState('networkidle');
    check(`${name}: saved, the course follows the default`, (await blocks(page)).join(',') === drawn(siteDefault), (await blocks(page)).join(','));

    // The site's default, at Settings > Course pages.
    await page.goto(settings, {waitUntil: 'networkidle'});
    check(`${name}: Settings has Course pages`, await page.locator('#settings-course-pages #page-layout-form').count() === 1);
    await page.getByRole('button', {name: 'Start from Reviews first'}).click();
    await page.getByRole('button', {name: 'Save layout'}).first().click();
    await page.waitForLoadState('networkidle');
    check(`${name}: a new default changes every course on it`, (await blocks(page)).join(',') === 'about,how,modules,get,glance,access,start', (await blocks(page)).join(','));
    await page.goto(settings, {waitUntil: 'networkidle'});
    await page.getByRole('button', {name: 'Back to Course first'}).click();
    check(`${name}: Back to Course first`, await order(page) === 'about,how,modules,glance,get,reviews,access,start,related');
    await page.getByRole('button', {name: 'Save layout'}).first().click();
    await page.waitForLoadState('networkidle');
    check(`${name}: and saved`, JSON.stringify(await inUse(page)) === '["course"]');
    await browser.close();
}

async function withoutScript() {
    const browser = await chromium.launch();
    const context = await session(browser, {javaScriptEnabled: false, reducedMotion: 'reduce'});
    const page = await context.newPage();
    await page.goto(tab, {waitUntil: 'load'});
    const press = async (label) => { await page.getByRole('button', {name: label}).click(); await page.waitForLoadState('load'); };
    const siteDefault = (await layoutOf(page)).map((block) => block.id).join(',');
    await press('Start from Buy first');
    check('without JavaScript: a template is saved as the course\'s own layout', await page.locator('input[name="source"][value="own"]').isChecked() && await order(page) === 'access,about,glance,modules,how,get,reviews,start,related');
    await press('Move The modules up');
    check('without JavaScript: an arrow moves and saves', await order(page) === 'access,about,modules,glance,how,get,reviews,start,related', await order(page));
    await press('Hide Reviews');
    check('without JavaScript: Hide puts the block on the shelf', JSON.stringify(await shelf(page)) === '["reviews"]');
    await press('Show Reviews');
    check('without JavaScript: Show puts it back', (await shelf(page)).length === 0 && (await board(page)).includes('reviews'));
    await page.locator('[data-block="about"] label:has(input[value="full"])').click();
    await page.getByRole('button', {name: 'Save layout'}).first().click();
    await page.waitForLoadState('load');
    check('without JavaScript: a width chosen and saved', await page.locator('.cl-page-builder-board [data-block="about"]').getAttribute('data-width') === 'full');
    await press('Reset to the default layout');
    check('without JavaScript: Reset puts the default back', await page.locator('input[name="source"][value="default"]').isChecked() && await order(page) === siteDefault);
    await page.goto(settings, {waitUntil: 'load'});
    await press('Start from Buy first');
    check('without JavaScript: Settings saves a template', JSON.stringify(await inUse(page)) === '["buy"]');
    await press('Back to Course first');
    check('without JavaScript: Back to Course first', JSON.stringify(await inUse(page)) === '["course"]');
    await browser.close();
}

async function construction() {
    const browser = await chromium.launch();
    for (const width of [1440, 390]) {
        const context = await session(browser, {viewport: {width, height: 1000}, reducedMotion: 'reduce'});
        const page = await context.newPage();
        for (const address of [tab, settings]) {
            await page.goto(address, {waitUntil: 'networkidle'});
            const problems = judge(await page.evaluate(inspect), '').map((failure) => failure.replace(/^: /, ''));
            check(`built as every page is at ${width}px: ${address.replace(base, '')}`, problems.length === 0, problems.join(' | '));
        }
        if (width === 390) {
            await page.goto(tab, {waitUntil: 'networkidle'});
            const widths = await page.locator('.cl-page-builder-board > [data-block]').evaluateAll((tiles) => tiles.map((tile) => Math.round(tile.getBoundingClientRect().width)));
            check('on a phone the board is one column', new Set(widths).size === 1, widths.join(','));
            check('on a phone nothing scrolls sideways', await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        }
        await context.close();
    }
    await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) await withScript(type, name);
    await withoutScript();
    await construction();
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})();
