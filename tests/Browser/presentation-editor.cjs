/* The presentation editor (owner, 2026-10-30, the approved mockup), in Chromium and Firefox, and without JavaScript:
   - the presentation's page: the preview plays at the top, a slide's picture shows it there, the slides are a list or a
     grid, dragged (up and down in the list, left and right in the grid) or moved by the keyboard and the ⋯ menu, each
     move saved at once; Up next stays last; a slide is copied and deleted;
   - Add a slide: the layout window over the page, every layout drawn, then the new slide's page, kept only by Save slide;
   - a slide's page: the row of slides; Preview shows what the form holds without saving it; Change layout in a window
     keeps what was typed and the fields both layouts share; a refused Save keeps what was typed; Close asks first while
     changes are not saved;
   - the narration (Chromium, a stand-in microphone): a recording discarded, kept at once, still there after a reload and
     removed; the script saved in place;
   - the details page: Save details goes back with the description in the player's top bar; Close asks first;
   - without JavaScript: the ⋯ menu moves, Add a slide and Change layout are pages, Preview draws the page again;
   - every new page is built as every page is (page-construction.cjs), at 1440 and 390px.
   Fixture: php tests/Browser/course-parts-fixture.php create|cleanup (in env_php_1), the same as course-parts.cjs. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {inspect, judge} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-parts-state.json'));
const page1 = `${base}/admin/course-items/${state.presentation_item}`;
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: !!ok, detail}); if (!ok) console.log('FAIL', name, detail); };

async function session(browser, options = {}) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, ...options});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return context;
}

const titles = (page) => page.locator('#presentation-slide-list .cl-tree-node .cl-tree-name').allInnerTexts();
const ids = (page) => page.locator('#presentation-slide-list .cl-tree-node').evaluateAll((nodes) => nodes.map((node) => node.dataset.nodeId));
const saved = (page) => page.locator('.cl-tree-status[data-state="saved"]').waitFor({timeout: 10000});

async function drag(page, fromId, toId, x, y) {
    // The whole list in view, its handles shown by the script, before measuring.
    await page.locator(`#slide-${fromId} .cl-tree-handle`).waitFor({state: 'visible'});
    await page.locator('#presentation-slides').evaluate((card) => card.scrollIntoView({block: 'start', behavior: 'instant'}));
    await page.waitForTimeout(150);
    const from = await page.locator(`#slide-${fromId} .cl-tree-handle`).boundingBox();
    const to = await page.locator(`#slide-${toId} > .cl-tree-row`).boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 4, from.y + from.height / 2 + 4, {steps: 3});
    await page.mouse.move(to.x + to.width * x, to.y + to.height * y, {steps: 12});
    await page.mouse.move(to.x + to.width * x + 2, to.y + to.height * y, {steps: 2});
    await page.waitForTimeout(100);
    const indicator = await page.locator(`#slide-${toId}`).getAttribute('data-drop');
    await page.mouse.up();
    return indicator;
}

async function presentationPage(browserType, name) {
    const browser = await browserType.launch();
    const context = await session(browser);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto(`${page1}?slides_view=list`, {waitUntil: 'networkidle'});
    const at = `${name} presentation page`;
    check(`${at}: named as the editor`, (await page.locator('h1').innerText()).trim() === 'Edit Course Item: Presentation');
    check(`${at}: the preview plays at the top`, await page.locator('#presentation-preview .cw-deck.cw-ready').count() === 1);
    check(`${at}: four slides, each drawn`, (await titles(page)).length === 4 && await page.locator('#presentation-slide-list .cl-slide-thumb .cw-slide').count() === 4);
    await page.locator('#presentation-slide-list .cl-slide-picture').nth(2).click();
    check(`${at}: a slide's picture shows it in the preview`, (await page.locator('#presentation-preview .cw-counter').innerText()).trim() === '3 / 4');

    const order = await ids(page);
    check(`${at}: dragging a slide below another moves it`, await drag(page, order[1], order[2], 0.5, 0.85) === 'after');
    await saved(page);
    check(`${at}: the drag is saved`, (await titles(page)).join('|') === 'Welcome|A quick question|By the end|Up next', (await titles(page)).join('|'));
    await page.locator(`#slide-${order[1]} .cl-tree-handle`).focus();
    await page.keyboard.press('ArrowUp');
    await saved(page);
    check(`${at}: the arrow keys move a slide`, (await titles(page)).join('|') === 'Welcome|By the end|A quick question|Up next');
    await page.evaluate(() => { window.__same = true; });
    await page.locator(`#slide-${order[3]} .cl-ui-row-trigger`).click();
    await page.locator(`#slide-${order[3]}`).getByRole('button', {name: 'Move earlier'}).click();
    await page.locator('.cl-tree-error:not([hidden])').waitFor({timeout: 10000});
    check(`${at}: Up next stays last, and says why`, (await page.locator('.cl-tree-error').innerText()).includes('comes last') && (await titles(page))[3] === 'Up next');
    check(`${at}: moves load no page`, await page.evaluate(() => window.__same === true));

    await page.goto(`${page1}?slides_view=grid`, {waitUntil: 'networkidle'});
    check(`${at}: the grid shows the slides as cards`, await page.locator('.cl-slide-tree--grid .cl-tree-node').count() === 4);
    const grid = await ids(page);
    check(`${at}: in the grid the right half of a card means after it`, await drag(page, grid[0], grid[1], 0.85, 0.3) === 'after');
    await saved(page);
    check(`${at}: the grid's drag is saved`, (await titles(page))[0] === 'By the end' && (await titles(page))[1] === 'Welcome');
    await page.locator(`#slide-${grid[0]} .cl-tree-handle`).focus();
    await page.keyboard.press('ArrowLeft');
    await saved(page);
    check(`${at}: in the grid the left arrow moves a slide earlier`, (await titles(page))[0] === 'Welcome');
    await page.goto(`${page1}?slides_view=list`, {waitUntil: 'networkidle'});
    check(`${at}: the list is remembered`, await page.locator('.cl-slide-tree--list').count() === 1);

    const first = (await ids(page))[0];
    await page.locator(`#slide-${first} .cl-ui-row-trigger`).click();
    await page.locator(`#slide-${first}`).getByRole('button', {name: 'Make a copy'}).click();
    await page.waitForLoadState('networkidle');
    check(`${at}: a slide is copied straight after it`, (await titles(page)).join('|') === 'Welcome|Welcome (copy)|By the end|A quick question|Up next', (await titles(page)).join('|'));
    const copy = (await ids(page))[1];
    await page.locator(`#slide-${copy} .cl-ui-row-trigger`).click();
    await page.locator(`#slide-${copy}`).getByRole('button', {name: 'Delete'}).click();
    await page.waitForLoadState('networkidle');
    check(`${at}: a slide is deleted`, (await titles(page)).length === 4);
    check(`${at}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

async function slidePages(browserType, name) {
    const browser = await browserType.launch();
    const context = await session(browser);
    const page = await context.newPage();
    const errors = [];
    const dialogs = [];
    let answer = 'dismiss';
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', (dialog) => { dialogs.push(dialog.message()); answer === 'accept' ? dialog.accept() : dialog.dismiss(); });
    await page.goto(page1, {waitUntil: 'networkidle'});
    const at = `${name} slide page`;

    await page.getByRole('link', {name: 'Add a slide'}).click();
    await page.locator('#slide-add-body .cl-slide-layout-choice').waitFor();
    check(`${at}: Add a slide draws every layout a module opening can have`, await page.locator('#slide-add-body .cl-ui-picture-choice-item--drawing').count() === 11);
    check(`${at}: the new slide goes before Up next`, await page.locator('#slide-after').inputValue() === '3');
    await page.locator('#slide-add-body input[value="statement"]').check();
    await page.getByRole('button', {name: 'Add the slide'}).click();
    await page.waitForURL(/\/slides\/new\?/);
    check(`${at}: a new slide's page`, (await page.locator('h1').innerText()).trim() === 'New slide' && await page.locator('#slide-text').count() === 1);
    await page.locator('#slide-title').fill('The big idea');
    await page.locator('#slide-text').fill('Feed the soil, and the soil feeds the plant.');
    await page.getByRole('button', {name: 'Save slide'}).click();
    await page.waitForURL(/\/slides\/[a-z0-9]{6,32}$/);
    check(`${at}: Save slide keeps the new slide, fourth of five`, (await page.locator('.cl-page-head').innerText()).toLowerCase().includes('slide 4 of 5'));
    check(`${at}: the row of slides shows all five, this one marked`, await page.locator('.cl-slide-strip-item').count() === 5 && (await page.locator('.cl-slide-strip-here').innerText()).includes('4. The big idea'));
    check(`${at}: the row's four buttons are icons`, await page.locator('.cl-slide-strip-scroll:not([hidden])').count() === 4);

    await page.locator('.cl-slide-strip-link', {hasText: 'By the end'}).click();
    await page.waitForLoadState('networkidle');
    check(`${at}: a slide in the row opens it`, (await page.locator('h1').innerText()).trim() === 'By the end');
    await page.locator('#slide-heading').fill('By the end of this module you can');
    check(`${at}: a change says it is not saved`, (await page.locator('.cl-slide-bar-status').innerText()).startsWith('Changes not saved yet'));
    await page.getByRole('link', {name: 'Close'}).click();
    check(`${at}: Close asks first`, dialogs.length === 1 && dialogs[0].includes('not saved') && /\/slides\//.test(page.url()));
    await page.getByRole('button', {name: 'Preview'}).click();
    await page.locator('#slide-preview-body .cw-deck.cw-ready').waitFor();
    check(`${at}: Preview shows what the form holds`, (await page.locator('#slide-preview-body h2').innerText()).includes('By the end of this module you can'));
    await page.locator('#slide-preview [data-close-modal]').first().click();

    await page.getByRole('link', {name: 'Change layout'}).click();
    await page.locator('#slide-layout-body .cl-slide-layout-choice').waitFor();
    await page.locator('#slide-layout-body input[value="steps"]').check();
    await page.getByRole('button', {name: 'Use this layout'}).click();
    await page.waitForLoadState('networkidle');
    check(`${at}: a new layout keeps what was typed`, await page.locator('#slide-heading').inputValue() === 'By the end of this module you can');
    check(`${at}: the points become steps`, (await page.locator('#slide-steps').inputValue()).startsWith('Test your soil with a jar'));
    check(`${at}: and it is not saved yet`, (await page.locator('.cl-slide-bar-status').innerText()).startsWith('Changes not saved yet') && (await page.locator('.cl-ui-notice').last().innerText()).includes('Steps layout'));
    await page.locator('#slide-heading').fill('');
    await page.locator('#slide-heading').evaluate((field) => field.removeAttribute('required'));
    await page.getByRole('button', {name: 'Save slide'}).click();
    await page.waitForLoadState('networkidle');
    check(`${at}: a refused Save says why and keeps what was typed`, (await page.locator('.cl-ui-notice--danger').innerText()).includes('needs a heading') && (await page.locator('#slide-steps').inputValue()).startsWith('Test your soil'));
    await page.locator('#slide-heading').fill('Making it work');
    await page.getByRole('button', {name: 'Save slide'}).click();
    await page.waitForLoadState('networkidle');
    check(`${at}: Save slide stays on the page`, /\/slides\/[a-z0-9]{6,32}$/.test(page.url()) && (await page.locator('.cl-slide-bar-status').innerText()).startsWith('Saved'));
    answer = 'accept';
    await page.getByRole('link', {name: 'Close'}).click();
    await page.waitForURL(/\/admin\/course-items\/\d+(#.*)?$/);
    check(`${at}: Close goes back to the presentation, the slide in its new layout`, (await page.locator('#presentation-slide-list .cl-tree-meta').allInnerTexts()).some((meta) => meta.startsWith('Steps')));

    // The narration script, saved in place.
    await page.locator('#presentation-slide-list .cl-tree-node').first().getByRole('link', {name: /^Edit/}).click();
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => { window.__same = true; });
    await page.locator('#narration-script').fill('We start underground, where the roots are.');
    await page.getByRole('button', {name: 'Save the script'}).click();
    await page.locator('#narration-script-status .cl-narration-script-result').waitFor();
    check(`${at}: the script is saved in place`, (await page.locator('#narration-script-status').innerText()).includes('saved') && await page.evaluate(() => window.__same === true));
    await page.reload({waitUntil: 'networkidle'});
    check(`${at}: and kept`, await page.locator('#narration-script').inputValue() === 'We start underground, where the roots are.');

    // The details page.
    await page.goto(`${page1}/details`, {waitUntil: 'networkidle'});
    await page.locator('#details-description').fill('Module 1: Soil and compost');
    dialogs.length = 0;
    answer = 'dismiss';
    await page.getByRole('link', {name: 'Close'}).click();
    check(`${at}: the details' Close asks first`, dialogs.length === 1 && /\/details$/.test(page.url()));
    await page.getByRole('button', {name: 'Save details'}).click();
    await page.waitForURL(/\/admin\/course-items\/\d+$/);
    check(`${at}: Save details goes back, the description in the player's top bar`, await page.locator('#presentation-preview .cw-deck').getAttribute('data-cw-title') === 'Module 1: Soil and compost');

    await page.setViewportSize({width: 390, height: 900});
    for (const address of [page1, `${page1}/details`]) {
        await page.goto(address, {waitUntil: 'networkidle'});
        check(`${at}: nothing scrolls sideways at 390px (${address.replace(base, '')})`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    }
    check(`${at}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

async function narration() {
    const browser = await chromium.launch({args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream']});
    const context = await session(browser, {permissions: ['microphone']});
    const page = await context.newPage();
    page.on('dialog', (dialog) => dialog.accept());
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(page1, {waitUntil: 'networkidle'});
    await page.locator('#presentation-slide-list .cl-tree-node').first().getByRole('link', {name: /^Edit/}).click();
    await page.waitForLoadState('networkidle');
    const box = page.locator('#narration .cl-narration');
    const kept = () => box.locator('.cl-narration-current audio').count();
    const record = async () => {
        await page.locator('#narration-record').click();
        await page.waitForTimeout(1200);
        await page.locator('#narration-stop').click();
        await page.waitForFunction(() => !document.querySelector('#narration .cl-narration-take').hidden);
    };
    await record();
    await page.locator('#narration-discard').click();
    check('narration: Discard keeps nothing', await kept() === 0);
    await record();
    await page.locator('#narration-keep').click();
    await page.waitForFunction(() => document.querySelector('#narration .cl-narration-status').textContent.startsWith('Saved'));
    const src = await box.locator('.cl-narration-current audio').getAttribute('src');
    check('narration: Keep saves it at once', /^\/narrations\/[0-9a-f-]{36}$/.test(src || ''));
    await page.reload({waitUntil: 'networkidle'});
    check('narration: still there after a reload', await kept() === 1);
    await page.goto(page1, {waitUntil: 'networkidle'});
    check('narration: the list says so', (await page.locator('#presentation-slide-list .cl-tree-meta').first().innerText()).includes('Recording saved'));
    await page.goBack({waitUntil: 'networkidle'});
    await box.locator('.cl-narration-remove button').click();
    await page.waitForFunction(() => document.querySelector('#narration .cl-narration-status').textContent.startsWith('Removed'));
    check('narration: Remove deletes it at once', await kept() === 0 && (await page.request.get(base + src)).status() === 404);
    check('narration: no script errors', errors.length === 0, errors.join(' | '));
    await browser.close();
}

async function construction() {
    const browser = await chromium.launch();
    for (const width of [1440, 390]) {
        const context = await session(browser, {viewport: {width, height: 1000}, reducedMotion: 'reduce'});
        const page = await context.newPage();
        await page.goto(page1, {waitUntil: 'networkidle'});
        const slide = (await ids(page))[1];
        for (const address of [page1, `${page1}/details`, `${page1}/slides/${slide}`, `${page1}/slides/${slide}/layout`, `${page1}/slides/add`, `${page1}/slides/new?layout=points&after=3`]) {
            await page.goto(address, {waitUntil: 'networkidle'});
            const problems = judge(await page.evaluate(inspect), '').map((failure) => failure.replace(/^: /, ''));
            check(`built as every page is at ${width}px: ${address.replace(base, '')}`, problems.length === 0, problems.join(' | '));
        }
        await context.close();
    }
    await browser.close();
}

async function withoutScript() {
    const browser = await chromium.launch();
    const context = await session(browser, {javaScriptEnabled: false, reducedMotion: 'reduce'});
    const page = await context.newPage();
    await page.goto(`${page1}?slides_view=list`, {waitUntil: 'load'});
    const before = await titles(page);
    const second = (await ids(page))[1];
    await page.locator(`#slide-${second} .cl-ui-row-trigger`).click();
    await page.locator(`#slide-${second}`).getByRole('button', {name: 'Move later'}).click();
    await page.waitForLoadState('load');
    const after = await titles(page);
    check('without JavaScript: the ⋯ menu moves a slide', after[1] === before[2] && after[2] === before[1], after.join('|'));
    await page.getByRole('link', {name: 'Add a slide'}).click();
    await page.waitForURL(/\/slides\/add$/);
    check('without JavaScript: Add a slide is a page of its own', (await page.locator('h1').innerText()).trim() === 'Add a slide' && await page.locator('.cl-ui-picture-choice-item--drawing').count() === 11);
    await page.locator('input[value="number"]').check();
    await page.getByRole('button', {name: 'Add the slide'}).click();
    await page.waitForURL(/\/slides\/new\?/);
    check('without JavaScript: then the new slide\'s page', await page.locator('#slide-figure').count() === 1);
    await page.goto(page1, {waitUntil: 'load'});
    await page.locator('#presentation-slide-list .cl-tree-node').first().getByRole('link', {name: /^Edit/}).click();
    await page.waitForLoadState('load');
    await page.locator('#slide-heading').fill('Changed without a script');
    await page.getByRole('button', {name: 'Preview'}).click();
    await page.waitForLoadState('load');
    check('without JavaScript: Preview draws the page again with the slide above the form', await page.locator('#slide-preview-page .cw-deck').count() === 1 && await page.locator('#slide-heading').inputValue() === 'Changed without a script');
    await page.getByRole('link', {name: 'Change layout'}).click();
    await page.waitForURL(/\/layout$/);
    await page.locator('input[value="statement"]').check();
    await page.getByRole('button', {name: 'Use this layout'}).click();
    await page.waitForLoadState('load');
    check('without JavaScript: Change layout is a page, and the slide is drawn in the new layout, unsaved', await page.locator('#slide-text').count() === 1 && (await page.locator('.cl-ui-notice').last().innerText()).includes('Statement layout'));
    await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) await presentationPage(type, name);
    await slidePages(chromium, 'Chromium');
    await narration();
    await construction();
    await withoutScript();
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})();
