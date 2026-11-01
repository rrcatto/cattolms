/* A learner marks each part of a course complete (owner, 2026-11-01: "a mark complete button which could be a toggle ...
   centred between the navigation buttons (Previous and Next)"; "tick it off with a big green tick in the sidebar"), in
   Chromium and Firefox at 1440 and 390px and without JavaScript, on the course-parts fixture's course in an
   administrator's preview:
   - the button sits centred between Previous and Next (on a phone, above them), reads "Mark complete" and is not green;
   - pressed, the page comes back with the part's big green tick in Course Modules, the button reading "Completed" in
     green, and the progress bar counting one part more ("1 of N parts done");
   - pressed again, the tick goes and the count falls back;
   - the same works without JavaScript, the button being a plain form;
   - nothing scrolls sideways and no script errors.
   Fixture: php tests/Browser/course-parts-fixture.php create|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-parts-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const learn = (node) => `${base}/learn/${state.slug}/item/${node}/content?preview=1`;

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

const progress = async (page) => {
    const text = await page.locator('.cl-course-reader-progress .ps-label b').innerText();
    const match = /(\d+) of (\d+) parts done/.exec(text);
    return match ? {done: Number(match[1]), total: Number(match[2])} : null;
};
const ticked = (page, node) => page.locator(`.cl-course-outline-item.is-done a[href*="/item/${node}/"] .cl-course-outline-done`).count();

async function run(browserType, name, width, script) {
    const browser = await browserType.launch();
    const context = await session(browser, {viewport: {width, height: 900}, reducedMotion: 'reduce', javaScriptEnabled: script});
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const at = `${name} ${width}${script ? '' : ' without JavaScript'}`;
    if (script) { await startPreview(page); } else {
        const scripted = await (await session(browser)).newPage();
        await startPreview(scripted);
        await scripted.close();
    }
    const node = state.lesson_node;
    await page.goto(learn(node), {waitUntil: 'load'});
    // A part already ticked by an earlier run in the same preview starts unticked here.
    if (await page.locator('.cl-course-item-complete.is-done').count()) { await Promise.all([page.waitForNavigation(), page.locator('.cl-course-item-complete button').click()]); }
    const before = await progress(page);
    const button = page.locator('.cl-course-item-complete button');
    check(`${at}: the button reads Mark complete`, (await button.innerText()).includes('Mark complete') && await page.locator('.cl-course-item-complete.is-done').count() === 0);
    const nav = await page.locator('.cl-course-item-paging').boundingBox();
    const box = await button.boundingBox();
    const previous = await page.locator('.cl-course-item-paging-previous a').boundingBox();
    const next = await page.locator('.cl-course-item-paging-next a').boundingBox();
    if (width > 520) {
        check(`${at}: it sits centred between Previous and Next`, Math.abs((box.x + box.width / 2) - (nav.x + nav.width / 2)) <= 2 && (!previous || previous.x + previous.width <= box.x) && (!next || box.x + box.width <= next.x),
            JSON.stringify({nav, box, previous, next}));
    } else {
        check(`${at}: on a phone it sits centred above them`, Math.abs((box.x + box.width / 2) - (nav.x + nav.width / 2)) <= 2 && (!previous || box.y + box.height <= previous.y) && (!next || box.y + box.height <= next.y));
    }
    check(`${at}: the part has no tick yet`, await ticked(page, node) === 0);

    await Promise.all([page.waitForNavigation(), button.click()]);
    const after = await progress(page);
    check(`${at}: ticked, the button reads Completed in green`, (await page.locator('.cl-course-item-complete button').innerText()).includes('Completed')
        && await page.locator('.cl-course-item-complete button').evaluate((b) => getComputedStyle(b).backgroundColor) === 'rgb(27, 138, 75)');
    check(`${at}: Course Modules shows its big green tick`, await ticked(page, node) === 1
        && await page.locator('.cl-course-outline-done').first().evaluate((t) => getComputedStyle(t).backgroundColor === 'rgb(27, 138, 75)' && t.getBoundingClientRect().width >= 24));
    check(`${at}: the progress counts one part more`, before && after && after.done === before.done + 1 && after.total === before.total, JSON.stringify({before, after}));

    await Promise.all([page.waitForNavigation(), page.locator('.cl-course-item-complete button').click()]);
    const undone = await progress(page);
    check(`${at}: pressed again, the tick goes`, await ticked(page, node) === 0 && (await page.locator('.cl-course-item-complete button').innerText()).includes('Mark complete'));
    check(`${at}: and the count falls back`, undone && undone.done === before.done, JSON.stringify({before, undone}));
    check(`${at}: nothing scrolls sideways`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    check(`${at}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) {
        for (const width of [1440, 390]) await run(type, name, width, true);
        await run(type, name, 1440, false);
    }
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})();
