/* Course page layouts (owner, 2026-10-30, the Page Builder; layouts of one's own, 2026-11-01, the accepted mockup), in
   Chromium and Firefox, and without JavaScript:
   - Administration > Courses > Course Page Layouts: every layout as a card, the default marked; New layout made on its
     own page (a block moved up, Save layout), where the sketch is drawn for the check's course with the blocks it leaves
     out and why; Copy, and Delete of the unused copy; every course listed with its layout and what its page leaves out;
     Apply to the ticked courses; the feature cards filter the courses;
   - a course's Page layout tab: on the default layout it shows that layout drawn for this course, no builder; choosing
     shows what it means at once and the notice says it is not saved; One of your layouts, chosen and saved, is what the
     course page draws; Custom layout in its dashed frame: Start again from, a block hidden, moved, dragged and made
     full width, saved, and drawn so on the course page (two halves sharing a row); Save as a layout; back to Default
     layout, the custom layout kept; Make default changes every course on the default layout, and the default the owner
     chose is made the default again (never assumed or changed for good: the fixture puts it back too);
   - without JavaScript every button sends the form and the server saves what it asks;
   - the three pages are built as every page is (page-construction.cjs), at 1440 and 390px; on a phone the board is
     one column.
   Fixture: php tests/Browser/course-page-fixture.php create|cleanup (in env_php_1), the same as course-page.cjs. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {inspect, judge} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-page-state.json'));
const tab = `${base}/admin/courses/${state.course_code}?tab=page-layout`;
const layoutsPage = `${base}/admin/courses/page-layouts`;
const coursePage = `${base}/courses/${state.slug}`;
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: !!ok, detail}); if (!ok) console.log('FAIL', name, detail); };

const BLOCKS = [['about', /^About this course$/], ['how', /^How each module works$/], ['modules', /^The \d+ modules?$|^Course Content$/], ['glance', /^At a glance$/],
    ['get', /^What you get$/], ['reviews', /^Reviews$/], ['access', /^Course access$/], ['start', /^Ready to start/], ['related', /^Related courses$/]];
// The fixture's course has no review and no related course, so those blocks are never drawn.
const PRESENT = ['about', 'how', 'modules', 'glance', 'get', 'access', 'start'];
const drawn = (blocks) => blocks.filter((block) => block.shown && PRESENT.includes(block.id)).map((block) => block.id).join(',');

async function session(browser, options = {}) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 2200}, ...options});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return context;
}

const order = (page) => page.locator('#page-layout-form .cl-page-builder input[name="order"]').inputValue();
const board = (page) => page.locator('#page-layout-form .cl-page-builder-board > [data-block]').evaluateAll((tiles) => tiles.map((tile) => tile.dataset.block));
const shelf = (page) => page.locator('#page-layout-form .cl-page-builder-shelf [data-block]').evaluateAll((tiles) => tiles.map((tile) => tile.dataset.block));
// The course tab's layouts as its script has them, and the default's id: whichever layout the owner made the default.
const tabLayouts = (page) => page.locator('#page-layout-form').evaluate((form) => ({
    layouts: JSON.parse(form.dataset.pageLayoutChoiceLayoutsValue), defaultId: form.dataset.pageLayoutChoiceDefaultIdValue}));
const sketchOf = (page, scope) => page.locator(`${scope} .cl-page-sketch [data-block]`).evaluateAll((cells) => cells.filter((cell) => !cell.hidden).map((cell) => cell.dataset.block).join(','));

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

const saved = async (page) => { await page.waitForLoadState('networkidle'); return (await page.locator('.cl-flash-stack').last().innerText().catch(() => '')).trim(); };

async function withScript(browserType, name) {
    const browser = await browserType.launch();
    const context = await session(browser, {reducedMotion: 'reduce'});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('dialog', (dialog) => dialog.accept());
    const buy = `Buy first ${state.mark} ${name}`;

    // Course Page Layouts: the layouts and every course.
    await page.goto(layoutsPage, {waitUntil: 'networkidle'});
    check(`${name}: Course Page Layouts lists the default layout, marked`, await page.locator('#your-layouts .cl-ui-card').filter({hasText: state.default_layout_name}).filter({hasText: 'Default layout'}).count() >= 1);
    const row = page.locator('#courses-layouts tr').filter({has: page.locator(`a[href="/admin/courses/${state.course_code}?tab=page-layout"]`)});
    const rowText = (await row.textContent()).replace(/\s+/g, ' ');
    check(`${name}: the check's course is listed on the default layout, with what its page leaves out and why`, rowText.includes('Default layout')
        && rowText.includes('Reviews (no approved review)') && rowText.includes('Related courses (no related course)'), rowText);

    // A new layout, made on its own page: Course access moved to the top.
    await page.getByRole('link', {name: 'New layout'}).click();
    await page.waitForLoadState('networkidle');
    await page.locator('#page-layout-name').fill(buy);
    await page.locator('#page-layout-purpose').fill('Course access first, for a course people know they want.');
    await page.locator('#page-layout-course').selectOption(String(state.course_code));
    check(`${name}: the sketch is drawn for the course chosen, with what it leaves out`, (await page.locator('.cl-page-builder-preview .cl-page-sketch-left-out').innerText()).includes('Reviews (no approved review)'));
    for (let step = 0; step < 9; step++) {
        const up = page.getByRole('button', {name: 'Move Course access up'});
        if (await up.isDisabled()) break;
        await up.click();
    }
    check(`${name}: an arrow moves a block a place, the notice says it is not saved`, (await board(page))[0] === 'access'
        && (await page.locator('.cl-page-builder-savebar .cl-page-builder-status').innerText()).startsWith('Not'), (await board(page)).join(','));
    await page.getByRole('button', {name: 'Save layout'}).first().click();
    check(`${name}: Save layout makes it`, (await saved(page)).includes('The layout was made') && (await page.locator('h1').innerText()).includes(buy));
    const buyId = page.url().match(/page-layouts\/(\d+)/)[1];

    // Copy, and Delete of the copy no course uses.
    await page.goto(layoutsPage, {waitUntil: 'networkidle'});
    await page.getByRole('button', {name: `Copy ${buy}`}).click();
    await page.waitForLoadState('networkidle');
    check(`${name}: Copy makes "Copy of" and opens it`, (await page.locator('h1').innerText()).includes(`Copy of ${buy}`));
    await page.goto(layoutsPage, {waitUntil: 'networkidle'});
    await page.getByRole('button', {name: `Delete Copy of ${buy}`}).click();
    check(`${name}: Delete removes an unused layout`, (await saved(page)).includes('was deleted') && await page.getByRole('button', {name: `Delete Copy of ${buy}`}).count() === 0);

    // The course tab: on the default layout, that layout drawn for this course, no builder.
    await page.goto(tab, {waitUntil: 'networkidle'});
    const {layouts, defaultId} = await tabLayouts(page);
    check(`${name}: the tab shows the default layout drawn for this course, no builder`, await page.locator('input[name="source"][value="default"]').isChecked()
        && await page.locator('[data-page-layout-choice-target="shared"]').isVisible() && await page.locator('[data-page-layout-choice-target="custom"]').isHidden()
        && await sketchOf(page, '[data-page-layout-choice-target="shared"]') === drawn(layouts[defaultId].blocks));
    check(`${name}: the course page follows the default layout`, (await blocks(page)).join(',') === drawn(layouts[defaultId].blocks), (await blocks(page)).join(','));

    // One of your layouts, chosen: drawn at once, saved with Save.
    await page.goto(tab, {waitUntil: 'networkidle'});
    await page.locator('label:has(input[name="source"][value="layout"])').click();
    await page.locator('#page-layout-layout').selectOption(buyId);
    const bar = page.locator('.cl-page-builder-part:has(#page-layout-source) .cl-page-builder-savebar');
    check(`${name}: choosing shows the layout at once and says it is not saved`, (await page.locator('[data-page-layout-choice-target="name"]').innerText()) === buy
        && (await sketchOf(page, '[data-page-layout-choice-target="shared"]')).startsWith('access') && (await bar.innerText()).includes('Not saved yet'));
    check(`${name}: leaving now asks first`, await page.locator("nav[aria-label='Course tools'] a").first().getAttribute('data-confirm') !== null);
    await bar.getByRole('button', {name: 'Save'}).click();
    check(`${name}: saved, the course uses the layout`, (await saved(page)).includes(`uses the layout ${buy}`) && await page.locator('input[name="source"][value="layout"]').isChecked());
    check(`${name}: the course page draws it`, (await blocks(page))[0] === 'access');

    // Custom layout, for this course only: Start again from, hide, move, drag, width, saved.
    await page.goto(tab, {waitUntil: 'networkidle'});
    await page.locator('label:has(input[name="source"][value="custom"])').click();
    check(`${name}: Custom layout shows its builder in the dashed frame`, await page.locator('.cl-page-layout-custom').isVisible() && await page.locator('[data-page-layout-choice-target="shared"]').isHidden());
    await page.locator('#page-layout-start-from').selectOption(buyId);
    await page.getByRole('button', {name: 'Copy it in'}).click();
    check(`${name}: Start again from copies a layout in`, (await board(page))[0] === 'access', (await board(page)).join(','));
    await page.getByRole('button', {name: 'Hide Reviews'}).click();
    check(`${name}: Reviews hidden goes to the shelf, out of the sketch`, JSON.stringify(await shelf(page)) === '["reviews"]'
        && await page.locator('.cl-page-layout-custom .cl-page-sketch [data-block="reviews"]').isHidden());
    await page.getByRole('button', {name: 'Show Reviews'}).click();
    check(`${name}: Show puts it back`, (await board(page)).includes('reviews') && (await shelf(page)).length === 0);
    await page.getByRole('button', {name: 'Hide Reviews'}).click();
    await page.getByRole('button', {name: 'Move The modules up'}).click();
    const afterMove = (await board(page)).join(',');
    await drag(page, 'related', 'about');
    const dragged = (await board(page)).join(',');
    check(`${name}: a block dragged by its handle`, dragged !== afterMove && dragged.indexOf('related') === dragged.indexOf('about') - 'related,'.length, dragged);
    await page.locator('.cl-page-layout-custom [data-block="get"] label:has(input[value="full"])').click();
    check(`${name}: What you get made full width, in the sketch too`, await page.locator('.cl-page-builder-board [data-block="get"]').getAttribute('data-width') === 'full'
        && await page.locator('.cl-page-layout-custom .cl-page-sketch [data-block="get"]').getAttribute('data-width') === 'full');
    await page.locator('.cl-page-layout-custom [data-block="glance"] label:has(input[value="half"])').click();
    await page.locator('.cl-page-layout-custom [data-block="how"] label:has(input[value="half"])').click();
    const arranged = await order(page);
    await bar.getByRole('button', {name: 'Save'}).click();
    check(`${name}: Save keeps the custom layout`, (await saved(page)).includes('custom layout') && await page.locator('input[name="source"][value="custom"]').isChecked() && await order(page) === arranged, await order(page));
    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));
    const shown = await blocks(page);
    const ids = arranged.split(',').filter((id) => id !== 'reviews' && PRESENT.includes(id));
    check(`${name}: the course page draws its blocks in the saved order`, shown.join(',') === ids.join(','), `${shown.join(',')} / ${ids.join(',')}`);
    const glance = ids.indexOf('glance'), how = ids.indexOf('how');
    if (Math.abs(glance - how) === 1) check(`${name}: two half blocks side by side share a row`, await shareRow(page, 'At a glance', 'How each module works'));

    // Save as one of your layouts: the course then uses it, its custom layout kept.
    await page.goto(tab, {waitUntil: 'networkidle'});
    await page.locator('#page-layout-new-name').fill(`Saved ${state.mark} ${name}`);
    await page.getByRole('button', {name: 'Save as a layout'}).click();
    check(`${name}: Save as a layout makes one, which the course uses`, (await saved(page)).includes('is one of your layouts now') && await page.locator('input[name="source"][value="layout"]').isChecked());

    // Back to Default layout; the custom layout is kept.
    await page.locator('label:has(input[name="source"][value="default"])').click();
    await bar.getByRole('button', {name: 'Save'}).click();
    check(`${name}: back on the default layout, the custom layout kept`, (await saved(page)).includes('uses the default layout') && (await page.locator('#page-layout-form').innerText()).includes('custom layout kept from before'));

    // Make default changes every course on the default layout; the owner's default is made the default again.
    await page.goto(layoutsPage, {waitUntil: 'networkidle'});
    await page.getByRole('button', {name: `Make ${buy} the default layout`}).click();
    check(`${name}: Make default`, (await saved(page)).includes('is the default layout now'));
    check(`${name}: every course on the default layout shows it`, (await blocks(page))[0] === 'access');
    await page.goto(layoutsPage, {waitUntil: 'networkidle'});
    await page.getByRole('button', {name: `Make ${state.default_layout_name} the default layout`}).click();
    check(`${name}: the owner's default is the default again`, (await saved(page)).includes(`"${state.default_layout_name}" is the default layout now`));

    // Apply to the ticked courses, and the feature cards.
    await page.goto(layoutsPage, {waitUntil: 'networkidle'});
    await page.getByRole('checkbox', {name: new RegExp(`^Tick .*`)}).first().waitFor();
    await row.locator('input[type="checkbox"]').check();
    await page.locator('#page-layout-apply').selectOption(buyId);
    await page.getByRole('button', {name: 'Apply'}).click();
    check(`${name}: Apply gives the ticked course the layout`, (await saved(page)).includes(`1 course uses ${buy} now`));
    await page.locator('label:has(input[name="show"][value="layout"])').click();
    await page.waitForLoadState('networkidle');
    check(`${name}: the feature card shows only courses on another layout`, page.url().includes('show=layout') && await row.count() === 1
        && await page.locator('#courses-layouts tbody tr').filter({hasText: 'Default layout:'}).count() === 0);
    check(`${name}: a layout a course uses cannot be deleted`, await page.getByRole('button', {name: `Delete ${buy}`}).isDisabled());
    // Back on the default layout for the next run.
    await row.locator('input[type="checkbox"]').check();
    await page.locator('#page-layout-apply').selectOption('0');
    await page.getByRole('button', {name: 'Apply'}).click();
    check(`${name}: and back on the default layout`, (await saved(page)).includes('uses the default layout now'));
    check(`${name}: still no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

async function withoutScript() {
    const browser = await chromium.launch();
    const context = await session(browser, {javaScriptEnabled: false, reducedMotion: 'reduce'});
    const page = await context.newPage();
    const press = async (label) => { await page.getByRole('button', {name: label}).first().click(); await page.waitForLoadState('load'); };
    await page.goto(tab, {waitUntil: 'load'});
    check('without JavaScript: the list of layouts is always there', await page.locator('#page-layout-layout').isVisible());
    await page.locator('input[name="source"][value="custom"]').check();
    await press('Save');
    check('without JavaScript: Custom layout saved shows its builder', await page.locator('input[name="source"][value="custom"]').isChecked() && await page.locator('.cl-page-layout-custom').isVisible());
    await press('Move The modules up');
    check('without JavaScript: an arrow moves and saves', (await board(page)).indexOf('modules') >= 0 && (await page.locator('.cl-flash-stack').last().innerText()).includes('custom layout'));
    // Related courses: shown in every layout the runs with JavaScript left behind.
    await press('Hide Related courses');
    check('without JavaScript: Hide puts the block on the shelf', (await shelf(page)).includes('related'));
    await press('Show Related courses');
    check('without JavaScript: Show puts it back', !(await shelf(page)).includes('related') && (await board(page)).includes('related'));
    await page.locator('.cl-page-layout-custom [data-block="about"] label:has(input[value="full"])').click();
    await press('Save');
    check('without JavaScript: a width chosen and saved', await page.locator('.cl-page-builder-board [data-block="about"]').getAttribute('data-width') === 'full');
    const own = await page.locator('#page-layout-start-from option').evaluateAll((options) => options.map((o) => [o.value, o.textContent]));
    const target = own.find(([, text]) => text.includes(state.mark)) || own[0];
    await page.locator('#page-layout-start-from').selectOption(target[0]);
    await press('Copy it in');
    check('without JavaScript: Start again from copies a layout in and saves', (await page.locator('.cl-flash-stack').last().innerText()).includes('starts again from'));
    await page.locator('input[name="source"][value="default"]').check();
    await press('Save');
    check('without JavaScript: back on the default layout', await page.locator('input[name="source"][value="default"]').isChecked() && await page.locator('.cl-page-layout-custom').isHidden());

    // A layout's page and the feature cards.
    await page.goto(`${layoutsPage}/${target[0]}`, {waitUntil: 'load'});
    await press('Hide At a glance');
    check('without JavaScript: a layout\'s block hidden and saved', (await shelf(page)).includes('glance'));
    await press('Show At a glance');
    await page.goto(layoutsPage, {waitUntil: 'load'});
    await page.locator('input[name="show"][value="custom"]').check();
    await press('Show');
    check('without JavaScript: the feature cards filter with Show', page.url().includes('show=custom'));
    await browser.close();
}

async function construction() {
    const browser = await chromium.launch();
    for (const width of [1440, 390]) {
        const context = await session(browser, {viewport: {width, height: 1000}, reducedMotion: 'reduce'});
        const page = await context.newPage();
        for (const address of [layoutsPage, `${layoutsPage}/${state.default_layout_id}`, tab]) {
            await page.goto(address, {waitUntil: 'networkidle'});
            const problems = judge(await page.evaluate(inspect), '').map((failure) => failure.replace(/^: /, ''));
            check(`built as every page is at ${width}px: ${address.replace(base, '')}`, problems.length === 0, problems.join(' | '));
            check(`nothing scrolls sideways at ${width}px: ${address.replace(base, '')}`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
        }
        if (width === 390) {
            await page.goto(`${layoutsPage}/${state.default_layout_id}`, {waitUntil: 'networkidle'});
            const widths = await page.locator('.cl-page-builder-board > [data-block]').evaluateAll((tiles) => tiles.map((tile) => Math.round(tile.getBoundingClientRect().width)));
            check('on a phone the board is one column', new Set(widths).size === 1, widths.join(','));
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
