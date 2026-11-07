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
   - in Course Modules only the section the reader is in starts open: none on the course introduction or a part in no
     section, the next one as the reader moves into it, and any section opened or closed by hand with its arrow, without
     a new page (owner, 2026-11-02), on a phone and without JavaScript too;
   - practice says how the last answer went too, with one button to the result; a result has nothing beside its report
     and ends with its way on: Assessment options alone after practice, with Next after a graded attempt, with Course
     overview after the last stop, and the final's page says the course's result (owner, 2026-11-05);
   - nothing scrolls sideways and no script errors.
   Fixture: php tests/Browser/reader-preview-fixture.php create|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-reader-preview-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const pub = (path) => `${base}/courses/${state.slug}/preview/${path}`;
const learn = (path) => `${base}/learn/${state.slug}/${path}?preview=1`;
// A link to a part, by its name: its own page, with or without a query, or a page beneath it (its assessment).
const to = (part) => `[href$="/${part}"], [href*="/${part}?"], [href*="/${part}/"]`;

async function admin(browser, options) {
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
const ticked = (page, node) => page.locator(to(node).split(', ').map((link) => `.cl-course-outline-item.is-done a${link} .cl-course-outline-done`).join(', ')).count();
const press = (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
/* Mark complete answers in place with JavaScript (owner, 2026-11-02). */
const markInPlace = async (page, reads) => {
    await page.locator('.cl-course-item-complete button').click();
    await page.waitForFunction((text) => document.querySelector('.cl-course-item-complete button')?.innerText.includes(text), reads);
};
const badge = async (page) => (await page.locator('.cl-question-focus').getByText(/^Question \d+ of \d+$/).first().innerText()).trim();

/* A section in the outline, by the link to its own page. */
const group = (page, node) => page.locator(to(node).split(', ').map((link) => `.cl-course-outline-group:has(> summary a${link})`).join(', '));
const isOpen = (page, node) => group(page, node).evaluate((details) => details.open);
const toggle = (page, node) => group(page, node).locator('> summary > .cl-course-outline-toggle').click();
const openCount = (page) => page.locator('.cl-course-outline details[open]').count();

/* The student preview: only the current section open, moving with the reader, and opened and closed by hand. */
async function studentSections(page, name) {
    const at = `${name} student preview`;
    await page.goto(`${base}/learn/${state.slug}?preview=1`, {waitUntil: 'load'});
    check(`${at}: the course introduction opens no section`, await openCount(page) === 0);
    await page.goto(learn(state.lesson_node), {waitUntil: 'load'});
    check(`${at}: a part opens its own section and no other`, await isOpen(page, state.module) && await openCount(page) === 1);
    check(`${at}: the parts of a closed section are out of sight`, await page.locator(to(state.second_lesson_node).split(', ').map((link) => `.cl-course-outline a${link}`).join(', ')).isHidden());
    await toggle(page, state.second_module);
    check(`${at}: the arrow opens another section, with no new page`, await isOpen(page, state.second_module) && await isOpen(page, state.module) && new URL(page.url()).pathname.endsWith(`/${state.lesson_node}`));
    await toggle(page, state.module);
    check(`${at}: the arrow closes the current one too`, !await isOpen(page, state.module) && await page.locator(to(state.lesson_node).split(', ').map((link) => `.cl-course-outline a${link}`).join(', ')).isHidden());
    await press(page, page.locator(to(state.second_lesson_node).split(', ').map((link) => `.cl-course-outline a${link}`).join(', ')));
    check(`${at}: moving into another section opens it and closes the last`, await isOpen(page, state.second_module) && !await isOpen(page, state.module) && await openCount(page) === 1);
    await press(page, group(page, state.module).locator('> summary a'));
    check(`${at}: a section's title goes to its page, and opens it`, new URL(page.url()).pathname.endsWith(`/${state.module}`) && await isOpen(page, state.module) && await openCount(page) === 1);
    await press(page, page.locator('.cl-course-item-paging-next a'));
    check(`${at}: Next moves on with its section open`, await isOpen(page, state.module) && await openCount(page) === 1);
    await page.goto(learn(`${state.final_node}/assessment/${state.final_key}`), {waitUntil: 'load'});
    check(`${at}: a part in no section opens none`, await openCount(page) === 0);
    await page.goto(learn(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    check(`${at}: an assessment's start page opens its section`, await isOpen(page, state.module) && await openCount(page) === 1);
    await press(page, page.locator('button:has-text("Start practice assessment")'));
    check(`${at}: so does its question page, the assessment marked as the current part`, await isOpen(page, state.module) && await page.locator(`.cl-course-outline-item.is-current a[href*="/${state.assessment_node}/assessment/"]`).count() === 1 && await page.locator('.cl-course-outline-introduction.is-current').count() === 0);
}

/* The public preview, the same; a visitor, with or without JavaScript, or on a phone. */
async function visitorSections(page, at) {
    await page.goto(pub('').replace(/\/$/, ''), {waitUntil: 'load'});
    check(`${at}: the course introduction opens no section`, await openCount(page) === 0);
    await page.goto(pub(state.lesson_node), {waitUntil: 'load'});
    check(`${at}: a part opens its own section`, await isOpen(page, state.module) && await openCount(page) === 1);
    const box = await group(page, state.module).locator('> summary > .cl-course-outline-toggle').boundingBox();
    check(`${at}: the arrow is big enough to press`, box.width >= 32 && box.height >= 32, JSON.stringify(box));
    const arrow = () => group(page, state.module).locator('> summary > .cl-course-outline-toggle').evaluate((e) => { const a = getComputedStyle(e, '::before'); return {w: parseFloat(a.width), h: parseFloat(a.height), right: parseFloat(a.borderRightWidth), bottom: parseFloat(a.borderBottomWidth), turn: new DOMMatrix(a.transform).b}; });
    const down = await arrow();
    check(`${at}: the arrow is drawn as an arrow, pointing down while open`, down.w >= 8 && Math.abs(down.w - down.h) < 1 && down.right > 0 && down.bottom > 0 && down.turn > 0, JSON.stringify(down));
    await toggle(page, state.module);
    check(`${at}: the arrow closes it, staying on the page`, !await isOpen(page, state.module) && new URL(page.url()).pathname.endsWith(`/${state.lesson_node}`));
    check(`${at}: and then points sideways`, (await arrow()).turn < 0);
    await toggle(page, state.module);
    check(`${at}: and opens it again`, await isOpen(page, state.module));
    check(`${at}: nothing scrolls sideways`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
}

/* The end of an assessment (owner, 2026-11-05): practice says how the last answer went too, before the result, and a
   result's way on is at the bottom of the page, after the question report, with nothing beside the report. */
const rowLabels = (page) => page.locator('.cl-course-item-paging a').evaluateAll((links) => links.map((a) => a.textContent.trim()));

async function resultPage(page, at, what) {
    check(`${at}: ${what} has nothing beside the report`, await page.locator('.cl-course-item aside').count() === 0);
    check(`${at}: ${what} ends with its way on, after the question report`, await page.locator('.cl-course-item').evaluate((article) => {
        const row = article.querySelector('#cl-course-item-paging'); const report = [...article.querySelectorAll('h2')].find((h) => h.textContent.includes('Question report'));
        return row !== null && report !== undefined && Boolean(report.compareDocumentPosition(row) & Node.DOCUMENT_POSITION_FOLLOWING) && !row.nextElementSibling;
    }));
    const apart = await page.locator('.cl-result-score').first().evaluate((score) => score.children[1].getBoundingClientRect().left - score.children[0].getBoundingClientRect().right);
    check(`${at}: ${what} keeps the score and the grade apart`, apart >= 8, String(apart));
    const words = await page.locator('.cl-course-item').innerText();
    check(`${at}: ${what} says its times in words`, !/\d\d:\d\d:\d\d/.test(words) && !/\b1 seconds\b/.test(words), words.match(/Total time.*/)?.[0] ?? '');
}

async function practice(page, at) {
    await press(page, page.locator('button:has-text("Start practice assessment")'));
    const told = [];
    for (let turn = 0; turn < 8 && await page.locator('.cl-question-focus').count() === 1; turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Wrong\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
        told.push(await page.getByText('Not correct.').count());
    }
    check(`${at}: practice says after every answer, the last too, that it was not correct`, told.length >= 1 && told.every((count) => count === 1), told.join(','));
    const notCorrect = await page.locator('.cl-ui-notice', {hasText: 'Not correct.'}).first().evaluate((note) => getComputedStyle(note).backgroundColor);
    check(`${at}: "Not correct." shows in its own colour, not plain white (owner, 2026-11-05)`, notCorrect === 'rgb(251, 223, 148)', notCorrect);
    check(`${at}: the last answer's feedback comes before the result, with no question, timer or Finish left`, !/\/result(\?|$)/.test(page.url()) && await page.getByText('Question report').count() === 0
        && await page.locator('button:has-text("Submit answer")').count() === 0 && await page.locator('.cl-countdown').count() === 0 && await page.locator('button:has-text("Finish assessment now")').count() === 0, page.url());
    const onward = await rowLabels(page);
    check(`${at}: and its one button goes on to the result`, onward.length === 1 && onward[0].includes('View my result'), onward.join(' | '));
    await press(page, page.locator('.cl-course-item-paging-next a'));
    check(`${at}: View my result opens the result`, /\/result(\?|$)/.test(page.url()) && await page.getByText('Question report').count() === 1, page.url());
    await resultPage(page, at, 'a practice result');
    const row = await rowLabels(page);
    check(`${at}: a practice result has one button, Assessment options`, row.length === 1 && row[0].includes('Assessment options') && await page.locator('.cl-course-item a:has-text("Course overview")').count() === 0, row.join(' | '));
    await press(page, page.locator('.cl-course-item-paging-next a'));
    check(`${at}: it leads to the start page, to take the graded assessment`, await page.locator('button:has-text("Start graded assessment")').count() === 1, page.url());
    await press(page, page.locator('button:has-text("Start practice assessment")'));
    for (let turn = 0; turn < 8 && await page.locator('.cl-question-focus').count() === 1; turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Wrong\s*$/}).locator('input').check();
        const last = (await badge(page)).replace(/^Question (\d+) of (\d+)$/, '$1') === (await badge(page)).replace(/^Question (\d+) of (\d+)$/, '$2');
        await press(page, last ? page.locator('button:has-text("Skip for now")') : page.locator('button:has-text("Submit answer")'));
        if (last) break;
    }
    for (let turn = 0; turn < 8 && await page.locator('.cl-question-focus').count() === 1; turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Wrong\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
    }
    check(`${at}: a skipped question comes back, and answering it still says how it went`, !/\/result(\?|$)/.test(page.url()) && await page.getByText('Not correct.').count() === 1, page.url());
}

/* In a course with a stop after the assessment (the author's preview walks the whole course): Next goes on; the final,
   the last stop, goes back to the course. */
async function gradedWayOn(page, at) {
    await page.goto(learn(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    await press(page, page.locator('button:has-text("Start graded assessment")'));
    for (let turn = 0; turn < 6 && await page.locator('.cl-question-focus').count() === 1; turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
    }
    await resultPage(page, at, 'a graded module result');
    check(`${at}: a graded module result has Assessment options and Next`, (await rowLabels(page)).join(' | ').replace(/\s+/g, ' ').match(/Assessment options.*\|.*Next/) !== null, (await rowLabels(page)).join(' | '));
    await press(page, page.locator('.cl-course-item-paging-next a'));
    check(`${at}: Next goes to the next part`, new URL(page.url()).pathname.endsWith(`/${state.second_module}`), page.url());
    await page.goto(learn(`${state.final_node}/assessment/${state.final_key}`), {waitUntil: 'load'});
    await press(page, page.locator('button:has-text("Start graded assessment"), button:has-text("Start assessment")').first());
    for (let turn = 0; turn < 6 && await page.locator('.cl-question-focus').count() === 1; turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
    }
    await resultPage(page, at, "the final assessment's result");
    const result = await page.locator('[data-course-result]').innerText();
    check(`${at}: the final's page says the course's result beside this assessment's`, await page.getByText('This assessment', {exact: true}).count() === 1 && /Your course result/.test(result) && /Half comes from your module assessments \(100%\) and half from your final assessment \(100%\)/.test(result), result);
    check(`${at}: the final, the last stop, has Assessment options and Course overview`, (await rowLabels(page)).join(' | ').replace(/\s+/g, ' ').match(/Assessment options.*\|.*Course overview/) !== null, (await rowLabels(page)).join(' | '));
}

async function visitorAssessment(page, at) {
    await page.goto(`${base}/courses/${state.slug}/preview`, {waitUntil: 'load'});
    check(`${at}: a visitor reads the course's introduction, as a learner does`, await page.getByText('How this course relates to its exam').count() === 1);
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
    await resultPage(page, at, 'a graded result');
    await page.goto(pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    await practice(page, at);
    await page.goto(pub(`${state.readiness_node}/assessment/${state.readiness_key}`), {waitUntil: 'load'});
    await press(page, page.locator('button:has-text("Start diagnostic")'));
    for (let turn = 0; turn < 4 && /\/preview\/assessment\/[0-9a-f-]+(\?|$)/.test(page.url()); turn++) {
        await page.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(page, page.locator('button:has-text("Submit answer")'));
    }
    check(`${at}: the readiness check ends Ready and ticks its part`, await page.getByText('Diagnostic result').count() === 1 && (await page.locator('.cl-result-score span').innerText()).trim() === 'Ready' && await ticked(page, state.readiness_node) === 1);
}

async function visitorTicks(page, at) {
    await page.goto(pub(state.lesson_node), {waitUntil: 'load'});
    const before = await progress(page);
    check(`${at}: the public preview shows the progress bar and Mark complete`, before !== null && await page.locator('.cl-course-item-complete button').innerText().then((t) => t.includes('Mark complete')));
    // Down at the buttons, with a closed section opened by hand: the page must not reload or move (owner, 2026-11-02).
    const closed = page.locator('.cl-course-outline-group:not([open]) > summary .cl-course-outline-toggle').first();
    if (await closed.count() && await closed.isVisible()) await closed.click();
    await page.evaluate(() => { window.__sameDocument = true; window.scrollTo(0, document.documentElement.scrollHeight); });
    const place = () => page.evaluate(() => ({same: window.__sameDocument === true, y: window.scrollY, open: [...document.querySelectorAll('.cl-course-outline-group')].filter((g) => g.open).length}));
    const held = await place();
    await markInPlace(page, 'Completed');
    await page.waitForTimeout(200);
    const stayed = await place();
    // Without JavaScript the plain form reloads the page, and comes back to the same place at the buttons.
    check(`${at}: Mark complete ${at.includes('without JavaScript') ? 'comes back to the buttons' : 'does not reload or move the page'}`, (at.includes('without JavaScript') || (stayed.same && stayed.open === held.open)) && held.y > 0 && Math.abs(stayed.y - held.y) <= 1, JSON.stringify({held, stayed}));
    const after = await progress(page);
    check(`${at}: marked complete, the part gets its big green tick and the bar counts it`, await ticked(page, state.lesson_node) === 1 && (await page.locator('.cl-course-item-complete button').innerText()).includes('Completed') && after && after.done === before.done + 1, JSON.stringify({before, after}));
    await markInPlace(page, 'Mark complete');
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
    await row(student, `${name} student preview, a part`, learn(state.lesson_node), {previous: true, next: true, complete: true});
    await row(student, `${name} student preview, a section`, learn(state.module), {previous: true, next: true, complete: false});
    await row(student, `${name} student preview, an assessment`, learn(`${state.assessment_node}/assessment/${state.assessment_key}`), {previous: true, next: true, complete: false});
    await row(student, `${name} student preview, the course page`, `${base}/learn/${state.slug}?preview=1`, {next: true});
    await studentSections(student, name);
    await gradedWayOn(student, `${name} student preview`);

    // The public preview, as a visitor who is not signed in.
    const visitor = listen(await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'})).newPage());
    await row(visitor, `${name} public preview, a part`, pub(state.lesson_node), {previous: true, next: true, complete: true});
    await row(visitor, `${name} public preview, a section`, pub(state.module), {previous: true, next: true, complete: false});
    await row(visitor, `${name} public preview, an assessment`, pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {previous: true, complete: false});
    await visitorTicks(visitor, `${name} public preview`);
    await visitorSections(visitor, `${name} public preview`);
    await visitorAssessment(visitor, `${name} public preview`);
    check(`${name}: nothing scrolls sideways`, await visitor.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));

    const phone = listen(await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}, reducedMotion: 'reduce'})).newPage());
    await row(phone, `${name} public preview at 390, a part`, pub(state.lesson_node), {complete: 'above'});
    check(`${name} at 390: nothing scrolls sideways`, await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await visitorSections(phone, `${name} at 390`);

    // Without JavaScript the toggle and the assessment are plain forms.
    const plain = await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, javaScriptEnabled: false, reducedMotion: 'reduce'})).newPage();
    await visitorTicks(plain, `${name} without JavaScript`);
    await visitorSections(plain, `${name} without JavaScript`);
    await plain.goto(pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    await press(plain, plain.locator('button:has-text("Start graded assessment")'));
    for (let turn = 0; turn < 4 && /\/preview\/assessment\/[0-9a-f-]+(\?|$)/.test(plain.url()); turn++) {
        await plain.locator('.cl-question-focus label.cl-option').filter({hasText: /^\s*Correct\s*$/}).locator('input').check();
        await press(plain, plain.locator('button:has-text("Submit answer")'));
    }
    check(`${name} without JavaScript: the assessment runs to its result`, await plain.getByText('Question report').count() === 1);
    await plain.goto(pub(`${state.assessment_node}/assessment/${state.assessment_key}`), {waitUntil: 'load'});
    await practice(plain, `${name} without JavaScript`);

    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) await run(type, name);
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})();
