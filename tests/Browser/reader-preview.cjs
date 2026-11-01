/* The reader's paging row on every page, Mark complete and assessments in the public preview (owner, 2026-11-01:
   "The Previous button is very wide and the Next button comes directly after Previous instead of being right aligned",
   "the section nav puts the Next button centred", the public preview "is supposed to SHOWCASE to potential students the
   exact same experience" with Mark complete and the learner's assessment: "a start page where you can take a practice
   assessment or a graded one" and "the questions one at a time with a skip button"), in Chromium and Firefox:
   - in the student preview and the public preview, on a part, a section page and an assessment's start page, Previous
     sits at the row's left edge at its own width, Next at its right edge, and Mark complete (on a part only) centred
     between them; on a phone Mark complete sits above them;
   - in the public preview a visitor marks a part complete and unmarks it: the big green tick and the progress bar follow;
   - a visitor's assessment starts from the learner's start page (practice and graded), asks one question at a time with
     the timer, Skip for now and Finish now, brings a skipped question back with its own number, ends on the result page
     with its question report, and ticks its part; practice gives feedback after an answer; a readiness check ends Ready
     or Review and ticks its part;
   - Mark complete and the assessment work without JavaScript;
   - nothing scrolls sideways and no script errors.
   Fixture: php tests/Browser/reader-preview-fixture.php create|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-reader-preview-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const pub = (path) => `${base}/courses/${state.slug}/preview/${path}`;
const learn = (path) => `${base}/learn/${state.slug}/item/${path}?preview=1`;

async function admin(browser, options) {
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

/* Where Previous, Mark complete and Next sit in the row. */
const paging = (page) => page.evaluate(() => {
    const nav = document.querySelector('.cl-course-item-paging');
    if (!nav) return null;
    const r = nav.getBoundingClientRect();
    const box = (selector) => { const e = nav.querySelector(selector); if (!e) return null; const b = e.getBoundingClientRect(); return {left: b.left, right: b.right, width: b.width, top: b.top, bottom: b.bottom}; };
    return {nav: {left: r.left, right: r.right, width: r.width}, previous: box('.cl-course-item-paging-previous a'), next: box('.cl-course-item-paging-next a'), complete: box('.cl-course-item-complete button')};
});

async function row(page, at, url, expect) {
    await page.goto(url, {waitUntil: 'load'});
    const p = await paging(page);
    if (!p) { check(`${at}: the paging row is there`, false); return; }
    const detail = JSON.stringify(p);
    if (expect.previous) check(`${at}: Previous sits at the left at its own width`, p.previous && Math.abs(p.previous.left - p.nav.left) <= 2 && p.previous.width < p.nav.width * 0.3, detail);
    if (expect.next) check(`${at}: Next sits at the right`, p.next && Math.abs(p.nav.right - p.next.right) <= 2 && p.next.width < p.nav.width * 0.3, detail);
    if (expect.complete === true) check(`${at}: Mark complete sits centred between them`, p.complete && Math.abs((p.complete.left + p.complete.right) / 2 - (p.nav.left + p.nav.right) / 2) <= 2 && (!p.previous || p.previous.right <= p.complete.left) && (!p.next || p.complete.right <= p.next.left), detail);
    if (expect.complete === 'above') check(`${at}: on a phone Mark complete sits above Previous and Next`, p.complete && (!p.previous || p.complete.bottom <= p.previous.top) && (!p.next || p.complete.bottom <= p.next.top), detail);
    if (expect.complete === false) check(`${at}: no Mark complete`, p.complete === null, detail);
}

const progress = async (page) => {
    const label = page.locator('.cl-course-reader-progress .ps-label b');
    if (!await label.count()) return null;
    const match = /(\d+) of (\d+) parts done/.exec(await label.innerText());
    return match ? {done: Number(match[1]), total: Number(match[2])} : null;
};
const ticked = (page, node) => page.locator(`.cl-course-outline-item.is-done a[href*="/preview/${node}/"] .cl-course-outline-done`).count();
const press = (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
const badge = async (page) => (await page.locator('.cl-question-focus').getByText(/^Question \d+ of \d+$/).first().innerText()).trim();

async function visitorAssessment(page, at) {
    await page.goto(pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    check(`${at}: the start page offers practice and graded`, await page.locator('button:has-text("Start practice assessment")').count() === 1 && await page.locator('button:has-text("Start graded assessment")').count() === 1);
    const before = await progress(page);
    await press(page, page.locator('button:has-text("Start graded assessment")'));
    check(`${at}: one question at a time with Skip, the timer and Finish now`, await page.locator('.cl-question-focus').count() === 1 && await page.locator('.cl-question-focus input[type=radio]').count() >= 2
        && await page.locator('button:has-text("Skip for now")').count() === 1 && await page.locator('.cl-countdown').count() === 1 && await page.locator('button:has-text("Finish assessment now")').count() === 1);
    const first = await badge(page);
    await press(page, page.locator('button:has-text("Skip for now")'));
    const second = await badge(page);
    check(`${at}: Skip moves on to the next question`, first === 'Question 1 of 3' && second === 'Question 2 of 3', `${first}, ${second}`);
    const seen = [];
    for (let turn = 0; turn < 6 && /\/preview\/assessment\/[0-9a-f-]+(\?|$)/.test(page.url()); turn++) {
        seen.push(await badge(page));
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
    }
    check(`${at}: the skipped question comes back last with its own number`, seen.join(',') === 'Question 2 of 3,Question 3 of 3,Question 1 of 3', seen.join(','));
    check(`${at}: it ends on the result page with its question report`, /\/result(\?|$)/.test(page.url()) && await page.getByText('Question report').count() === 1 && (await page.locator('.cl-result-score strong').innerText()).trim() === '100%');
    const after = await progress(page);
    check(`${at}: the finished assessment ticks its part`, await ticked(page, state.assessment_node) === 1 && before && after && after.done === before.done + 1, JSON.stringify({before, after}));
    await page.goto(pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    await press(page, page.locator('button:has-text("Start practice assessment")'));
    await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Wrong\s*$/}).locator('input').check();
    await press(page, page.locator('button:has-text("Submit answer")'));
    check(`${at}: practice says at once that an answer was not correct`, await page.getByText('Not correct.').count() === 1);
    await page.goto(pub(`${state.readiness_node}/assessment/${state.readiness_key}`), {waitUntil: 'load'});
    await press(page, page.locator('button:has-text("Start diagnostic")'));
    for (let turn = 0; turn < 4 && /\/preview\/assessment\/[0-9a-f-]+(\?|$)/.test(page.url()); turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
    }
    check(`${at}: the readiness check ends Ready and ticks its part`, await page.getByText('Diagnostic result').count() === 1 && (await page.locator('.cl-result-score span').innerText()).trim() === 'Ready' && await ticked(page, state.readiness_node) === 1);
}

async function visitorTicks(page, at) {
    await page.goto(pub(`${state.lesson_node}/content`), {waitUntil: 'load'});
    const before = await progress(page);
    check(`${at}: the public preview shows the progress bar and Mark complete`, before !== null && await page.locator('.cl-course-item-complete button').innerText().then((t) => t.includes('Mark complete')));
    await press(page, page.locator('.cl-course-item-complete button'));
    const after = await progress(page);
    check(`${at}: marked complete, the part gets its big green tick and the bar counts it`, await ticked(page, state.lesson_node) === 1 && (await page.locator('.cl-course-item-complete button').innerText()).includes('Completed') && after && after.done === before.done + 1, JSON.stringify({before, after}));
    await press(page, page.locator('.cl-course-item-complete button'));
    const undone = await progress(page);
    check(`${at}: pressed again, the tick goes and the count falls back`, await ticked(page, state.lesson_node) === 0 && undone && undone.done === before.done, JSON.stringify({before, undone}));
}

async function run(browserType, name) {
    const browser = await browserType.launch();
    const errors = [];
    const listen = (page) => { page.on('pageerror', (e) => errors.push(e.message)); return page; };

    // The student preview: the administrator's preview of the learner's pages.
    const student = listen(await (await admin(browser, {viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'})).newPage());
    await startPreview(student);
    await row(student, `${name} student preview, a part`, learn(`${state.lesson_node}/content`), {previous: true, next: true, complete: true});
    await row(student, `${name} student preview, a section`, learn(`${state.module}/content`), {previous: true, next: true, complete: false});
    await row(student, `${name} student preview, an assessment`, learn(`${state.assessment_node}/assessment/${state.assessment_key}`), {previous: true, next: true, complete: false});
    await row(student, `${name} student preview, the course page`, `${base}/learn/${state.slug}?preview=1`, {next: true});

    // The public preview, as a visitor who is not signed in.
    const visitor = listen(await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'})).newPage());
    await row(visitor, `${name} public preview, a part`, pub(`${state.lesson_node}/content`), {previous: true, next: true, complete: true});
    await row(visitor, `${name} public preview, a section`, pub(`${state.module}/content`), {previous: true, next: true, complete: false});
    await row(visitor, `${name} public preview, an assessment`, pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {previous: true, complete: false});
    await visitorTicks(visitor, `${name} public preview`);
    await visitorAssessment(visitor, `${name} public preview`);
    check(`${name}: nothing scrolls sideways`, await visitor.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));

    const phone = listen(await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}, reducedMotion: 'reduce'})).newPage());
    await row(phone, `${name} public preview at 390, a part`, pub(`${state.lesson_node}/content`), {complete: 'above'});
    check(`${name} at 390: nothing scrolls sideways`, await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));

    // Without JavaScript the toggle and the assessment are plain forms.
    const plain = await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, javaScriptEnabled: false, reducedMotion: 'reduce'})).newPage();
    await visitorTicks(plain, `${name} without JavaScript`);
    await plain.goto(pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    await press(plain, plain.locator('button:has-text("Start graded assessment")'));
    for (let turn = 0; turn < 4 && /\/preview\/assessment\/[0-9a-f-]+(\?|$)/.test(plain.url()); turn++) {
        await plain.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(plain, plain.locator('button:has-text("Submit answer")'));
    }
    check(`${name} without JavaScript: the assessment runs to its result`, await plain.getByText('Question report').count() === 1);

    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) await run(type, name);
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})();
