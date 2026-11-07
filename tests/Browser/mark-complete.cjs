/* A learner marks each part of a course complete (owner, 2026-11-01: "a mark complete button which could be a toggle ...
   centred between the navigation buttons (Previous and Next)"; "tick it off with a big green tick in the sidebar"), in
   Chromium and Firefox at 1440 and 390px and without JavaScript, on the course-parts fixture's course in an
   administrator's preview:
   - the button sits centred between Previous and Next (on a phone, above them), reads "Mark complete" and is not green;
   - pressed, the part gets its big green tick in Course Modules, the button reads "Completed" in green and the progress
     bar counts one part more ("1 of N parts done");
   - with JavaScript the page does not reload or move (owner, 2026-11-02: "I don't want to have to scroll to the bottom
     of the page again"): scrolled to the buttons it stays there, the sidebar's scroll and a section opened by hand stay
     as they were, the keyboard stays on the button, and every changed row is what a fresh page draws;
   - pressed again, the tick goes and the count falls back;
   - the same works without JavaScript, the button being a plain form that comes back to the buttons;
   - with JavaScript, an expired form reloads the page with a fresh form and a signed-out reader goes to sign in, never a
     page inside a page;
   - nothing scrolls sideways and no script errors.
   Fixture: php tests/Browser/course-parts-fixture.php create|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-parts-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const learn = (part) => `${base}/learn/${state.slug}/${part}?preview=1`;

async function session(browser, options = {}) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, ...options});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return context;
}

async function startPreview(page) {
    await page.goto(`${base}/admin/courses/${state.course_code}/preview`, {waitUntil: 'networkidle'});
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
const ticked = (page, node) => page.locator(`.cl-course-outline-item.is-done a[href$="/${node}"] .cl-course-outline-done, .cl-course-outline-item.is-done a[href*="/${node}?"] .cl-course-outline-done`).count();
/* Presses the button: with JavaScript it answers in place, without it the page comes back. */
const pressComplete = async (page, script) => {
    const button = page.locator('.cl-course-item-complete button');
    const was = await button.innerText();
    if (script) {
        await button.click();
        await page.waitForFunction((text) => document.querySelector('.cl-course-item-complete button')?.innerText !== text, was);
    } else {
        await Promise.all([page.waitForNavigation(), button.click()]);
    }
};
const rowsHtml = (page, node) => page.evaluate((id) => {
    const rows = [];
    for (let row = document.getElementById('cl-outline-row-' + id); row;) {
        rows.push(row.outerHTML.replace(/\s+/g, ' ').replace(/> </g, '><'));
        const group = row.tagName === 'SUMMARY' ? row.parentElement.parentElement.closest('details') : row.parentElement.closest('details');
        row = group ? group.querySelector(':scope > summary') : null;
    }
    return rows;
}, node);

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
    if (await page.locator('.cl-course-item-complete.is-done').count()) { await pressComplete(page, script); }
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

    const place = async () => page.evaluate(() => ({
        same: window.__sameDocument === true, url: location.href, y: window.scrollY,
        rail: document.querySelector('.cl-course-outline')?.closest('aside, nav, div')?.scrollTop ?? 0,
        open: [...document.querySelectorAll('.cl-course-outline-group')].filter((g) => g.open).length,
        focus: document.activeElement?.id || '',
    }));
    if (script) {
        // Down at the buttons, and with a closed section opened by hand: neither may change.
        const closed = page.locator('.cl-course-outline-group:not([open]) > summary .cl-course-outline-toggle').first();
        if (await closed.count() && await closed.isVisible()) await closed.click();
        await page.evaluate(() => { window.__sameDocument = true; });
        await button.scrollIntoViewIfNeeded();
        await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
        await button.focus();
        const at_start = await place();
        check(`${at}: the buttons are down the page, so a jump would show`, at_start.y > 100, JSON.stringify(at_start));
        await pressComplete(page, true);
        await page.waitForTimeout(250);
        const at_end = await place();
        check(`${at}: pressed, the page does not reload`, at_end.same && at_end.url === at_start.url, JSON.stringify(at_end));
        check(`${at}: and does not move`, Math.abs(at_end.y - at_start.y) <= 1 && at_end.rail === at_start.rail, JSON.stringify({at_start, at_end}));
        check(`${at}: the section opened by hand stays open`, at_end.open === at_start.open, JSON.stringify({at_start, at_end}));
        check(`${at}: the keyboard stays on the button`, at_end.focus === 'cl-course-item-complete-button', at_end.focus);
        const inPlace = await rowsHtml(page, node);
        const fresh = await page.context().newPage();
        await fresh.goto(learn(node), {waitUntil: 'load'});
        const drawn = await rowsHtml(fresh, node);
        check(`${at}: the rows it changed are what a fresh page draws`, inPlace.length >= 2 && inPlace.join() === drawn.join(), JSON.stringify({inPlace, drawn}).slice(0, 900));
        await fresh.close();
    } else {
        await page.evaluate(() => window.scrollTo(0, 0));
        await pressComplete(page, false);
        check(`${at}: the plain form comes back to the buttons`, page.url().endsWith('#cl-course-item-paging') && await page.locator('.cl-course-item-paging').evaluate((nav) => { const r = nav.getBoundingClientRect(); return r.top >= 0 && r.top < window.innerHeight; }), page.url());
    }
    const after = await progress(page);
    check(`${at}: ticked, the button reads Completed in green`, (await page.locator('.cl-course-item-complete button').innerText()).includes('Completed')
        && ['rgb(27, 138, 75)', 'rgb(21, 110, 59)'].includes(await page.locator('.cl-course-item-complete button').evaluate((b) => getComputedStyle(b).backgroundColor))); // a darker green under the pointer, which is still on it
    check(`${at}: Course Modules shows its big green tick`, await ticked(page, node) === 1
        && await page.locator('.cl-course-outline-done').first().evaluate((t) => getComputedStyle(t).backgroundColor === 'rgb(27, 138, 75)' && t.getBoundingClientRect().width >= 24));
    check(`${at}: the progress counts one part more`, before && after && after.done === before.done + 1 && after.total === before.total, JSON.stringify({before, after}));

    await pressComplete(page, script);
    const undone = await progress(page);
    check(`${at}: pressed again, the tick goes`, await ticked(page, node) === 0 && (await page.locator('.cl-course-item-complete button').innerText()).includes('Mark complete'));
    check(`${at}: and the count falls back`, undone && undone.done === before.done, JSON.stringify({before, undone}));
    if (script) {
        // An expired form reloads the part with a fresh form, nothing ticked and nothing swapped in.
        await page.locator('.cl-course-item-complete input[name=csrf]').evaluate((field) => { field.value = 'expired'; });
        await Promise.all([page.waitForNavigation(), page.locator('.cl-course-item-complete button').click()]);
        check(`${at}: an expired form reloads the part with a fresh form, unticked`, await ticked(page, node) === 0 && await page.locator('.cl-course-item-complete').count() === 1 && await page.locator('html html, body body').count() === 0 && await page.locator('.cl-course-item-complete input[name=csrf]').inputValue() !== 'expired', page.url());
        const away = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, reducedMotion: 'reduce'});
        await away.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const leaving = await away.newPage();
        await leaving.goto(learn(node), {waitUntil: 'load'});
        await away.clearCookies();
        await Promise.all([leaving.waitForNavigation(), leaving.locator('.cl-course-item-complete button').click()]);
        check(`${at}: signed out meanwhile, the whole page goes to sign in`, await leaving.locator('.cl-course-item-paging').count() === 0 && /\/login|sign/i.test(leaving.url() + await leaving.title()), leaving.url());
        await away.close();
    }
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
