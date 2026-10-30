/* The new course structure and CatWalk (owner, 2026-10-28, decisions 1A to 7A), as a learner and an author see them, in
   Chromium and Firefox at 1440 and 390px:
   - the reader's outline numbers the module ("Module 1:"), labels each part and gives its time;
   - a presentation plays: Next reveals a point at a time, then moves on; the quick question marks the answer and opens
     its explanation; Contents jumps; Escape closes it; the counter follows; nothing scrolls sideways on a phone;
   - two presentations on one lesson page each answer only their own keys;
   - flash cards turn over, "Again" brings a card back, and finishing says every card is known;
   - practical work keeps its model answer closed until it is opened;
   - the lesson, summary, flash cards and practical work download as PDFs;
   - without JavaScript, a presentation reads as one page with every slide, and flash cards as a list;
   - a slide's narration, recorded on its page with a stand-in microphone (Chromium), is heard by the author, refused to a
     visitor, and offered by the reader. The presentation editor's own checks are presentation-editor.cjs.
   Fixture: php tests/Browser/course-parts-fixture.php create|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-parts-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: !!ok, detail}); if (!ok) console.log('FAIL', name, detail); };
const learn = (node, suffix = 'content') => `${base}/learn/${state.slug}/item/${node}/${suffix}?preview=1`;

async function session(browser, options = {}) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, ...options});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return context;
}

async function startPreview(page) {
    await page.goto(`${base}/admin/courses/${state.course_id}/preview`, {waitUntil: 'networkidle'});
    const start = page.locator('button:has-text("Start course")');
    if (await start.count()) {
        for (const box of await page.locator('form[action*="/start"] input[type=checkbox][required]').all()) await box.check();
        await Promise.all([page.waitForNavigation(), start.first().click()]);
    }
}

async function reader(browserType, name, width) {
    const browser = await browserType.launch();
    const context = await session(browser, {viewport: {width, height: 1000}, reducedMotion: 'reduce'});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const at = `${name} ${width}`;
    await startPreview(page);

    await page.goto(learn(state.presentation_node), {waitUntil: 'networkidle'});
    const outline = await page.locator('.cl-course-outline').innerText();
    check(`${at}: the outline numbers the module`, outline.includes('Module 1: Soil, compost and containers'), outline.slice(0, 200));
    check(`${at}: the outline labels each part`, ['PRESENTATION', 'LESSON', 'SUMMARY', 'FLASH CARDS', 'PRACTICAL WORK'].every((label) => outline.toUpperCase().includes(label)));
    check(`${at}: the outline gives the module's time`, /\d+ (hour|minute)/.test(outline));
    const deck = page.locator('.cw-deck').first();
    check(`${at}: CatWalk mounts the presentation`, await page.locator('.cw-deck.cw-ready').count() === 1);
    check(`${at}: the counter starts at 1 of 4`, (await page.locator('.cw-counter').first().innerText()) === '1 / 4');
    await deck.focus();
    await page.keyboard.press('ArrowRight');
    check(`${at}: Next moves to the points slide`, (await page.locator('.cw-current').getAttribute('data-cw-layout')) === 'points');
    check(`${at}: the points build one at a time`, await page.locator('.cw-current .cw-step.cw-step-hidden').count() === 3);
    await page.keyboard.press('ArrowRight');
    check(`${at}: Next reveals the first point`, await page.locator('.cw-current .cw-step.cw-step-hidden').count() === 2);
    for (let i = 0; i < 3; i++) await page.keyboard.press('ArrowRight');
    check(`${at}: after the last point, Next moves on`, (await page.locator('.cw-current').getAttribute('data-cw-layout')) === 'check');
    await page.locator('.cw-current .cw-choice').nth(0).click();
    check(`${at}: the quick question marks the right answer`, await page.locator('.cw-current .cw-choice.cw-right').count() === 1 && await page.locator('.cw-current .cw-choice.cw-wrong').count() === 1);
    check(`${at}: the explanation opens`, await page.locator('.cw-current details.cw-explain[open]').count() === 1);
    await page.locator('.cw-btn', {hasText: 'Contents'}).first().click();
    check(`${at}: Contents lists every slide`, await page.locator('.cw-contents button').count() === 4);
    await page.keyboard.press('Escape');
    check(`${at}: Escape closes Contents`, await page.locator('.cw-contents').count() === 0);
    await page.locator('.cw-btn', {hasText: 'Contents'}).first().click();
    await page.locator('.cw-contents button').nth(3).click();
    check(`${at}: Contents jumps to a slide`, (await page.locator('.cw-counter').first().innerText()) === '4 / 4');
    check(`${at}: Continue leads to the next part`, (await page.locator('.cw-current a.cw-action').getAttribute('href')).includes('/content'));
    check(`${at}: nothing scrolls sideways`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    check(`${at}: the reader offers no PDF of a presentation`, await page.locator('.cl-course-item-pdf').count() === 0);

    await page.goto(learn(state.lesson_node), {waitUntil: 'networkidle'});
    const decks = page.locator('.cw-deck.cw-ready');
    check(`${at}: the lesson plays both embedded presentations`, await decks.count() === 2);
    await decks.nth(0).focus();
    await page.keyboard.press('ArrowRight');
    const counters = await page.locator('.cw-counter').allInnerTexts();
    check(`${at}: each presentation answers only its own keys`, counters[0] === '2 / 4' && counters[1] === '1 / 4', counters.join(' | '));
    check(`${at}: the lesson offers its PDF`, await page.locator('.cl-course-item-pdf a').count() === 1);

    await page.goto(learn(state.flashcards_node), {waitUntil: 'networkidle'});
    check(`${at}: the flash cards show one card at a time`, await page.locator('.cl-flashcards-card').count() === 1 && await page.locator('.cl-flashcards-list').isHidden());
    await page.getByRole('button', {name: 'Turn over', exact: true}).click();
    check(`${at}: turning a card over shows its answer`, (await page.locator('.cl-flashcards-back').innerText()) === 'Clay.');
    await page.getByRole('button', {name: 'Again', exact: true}).click();
    for (let i = 0; i < 6; i++) {
        if (await page.getByRole('button', {name: 'Go through them again', exact: true}).isVisible()) break;
        await page.getByRole('button', {name: 'Turn over', exact: true}).click();
        await page.getByRole('button', {name: 'I knew it', exact: true}).click();
    }
    const status = await page.locator('.cl-flashcards-status').innerText();
    check(`${at}: a card marked Again comes back, and the set ends when every card is known`, status.startsWith('Every card known, after going over 1 again'), status);

    await page.goto(learn(state.practical_node), {waitUntil: 'networkidle'});
    check(`${at}: the model answer starts closed`, await page.locator('details.cl-practical-answer:not([open])').count() === 1);
    await page.locator('details.cl-practical-answer summary').click();
    check(`${at}: the model answer opens`, (await page.locator('.cl-practical-answer-body').innerText()).includes('Sand settles first'));

    for (const [part, node] of [['lesson', state.lesson_node], ['summary', state.summary_node], ['flash cards', state.flashcards_node], ['practical work', state.practical_node]]) {
        const response = await page.request.get(learn(node, 'pdf'));
        const body = await response.body();
        check(`${at}: the ${part} downloads as a PDF`, response.status() === 200 && response.headers()['content-type'] === 'application/pdf' && body.subarray(0, 4).toString() === '%PDF');
    }
    check(`${at}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

async function withoutScript(browserType, name) {
    const browser = await browserType.launch();
    const context = await session(browser, {viewport: {width: 1440, height: 1000}, javaScriptEnabled: false, reducedMotion: 'reduce'});
    const page = await context.newPage();
    await page.goto(learn(state.presentation_node), {waitUntil: 'load'});
    check(`${name} without JavaScript: the presentation reads as one page`, await page.locator('.cw-deck:not(.cw-ready) .cw-slide').count() === 4 && await page.locator('.cw-slide').nth(3).isVisible());
    await page.goto(learn(state.flashcards_node), {waitUntil: 'load'});
    check(`${name} without JavaScript: the flash cards are a list`, await page.locator('.cl-flashcards-list li').count() === state.cards && await page.locator('.cl-flashcard-back:not([open])').count() === state.cards);
    await browser.close();
}

async function narration() {
  const browser = await chromium.launch({args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream']});
  const context = await session(browser, {viewport: {width: 1440, height: 1100}, permissions: ['microphone']});
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(`${base}/admin/course-items/${state.presentation_item}`, {waitUntil: 'networkidle'});
  await page.locator('#presentation-slide-list .cl-tree-node').first().getByRole('link', {name: /^Edit/}).click();
  await page.waitForLoadState('networkidle');
  await page.locator('#narration-record').click();
  await page.waitForTimeout(1200);
  await page.locator('#narration-stop').click();
  await page.waitForFunction(() => !document.querySelector('#narration .cl-narration-take').hidden);
  await page.locator('#narration-keep').click();
  await page.waitForFunction(() => document.querySelector('#narration .cl-narration-status').textContent.startsWith('Saved'));
  const src = await page.locator('#narration .cl-narration-current audio').getAttribute('src');
  const heard = await page.request.get(base + src);
  check('the author hears the narration', heard.status() === 200 && (heard.headers()['content-type'] || '').startsWith('audio/'));
  const visitor = await browser.newContext({ignoreHTTPSErrors: true});
  check('a visitor does not', (await visitor.request.get(base + src)).status() === 404);
  await startPreview(page);
  await page.goto(learn(state.presentation_node), {waitUntil: 'networkidle'});
  check('the reader offers the narration', await page.locator('.cw-btn', {hasText: 'Narration'}).count() === 1);
  check('narration: no script errors', errors.length === 0, errors.join(' | '));
  await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) {
        for (const width of [1440, 390]) await reader(type, name, width);
        await withoutScript(type, name);
    }
    await narration();
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})();
