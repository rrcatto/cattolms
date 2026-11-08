/* A course edited in place in Administration > Courses (owner, 2026-11-01: "a clickable edit icon for each row which
   changes the row into editable form fields, with clickable save / cancel icons ... in both list and grid views ...
   without reloading the page"), in Chromium and Firefox, and without JavaScript:
   - in the list the pencil turns the row into its form without a page load, its price, was price and period filled in;
   - a refused save (a was price below the price) keeps the row a form with the reason and what was typed, saving
     nothing; Cancel puts the row back unchanged;
   - a save of the code, title, price, was price, access period and category redraws the row alone, its Updated time
     new, marked as just saved; the course page's own address is unchanged;
   - a course with other price options shows Manage prices in place of the price's fields;
   - in the grid the card turns into the same form and comes back saved;
   - without JavaScript the pencil opens the list with the row as its form, and Save sends it and comes back;
   - the list with a row open is built as every page is (page-construction.cjs), at 1440 and 390px.
   Every run puts the course back as it was.
   Fixture: php tests/Browser/course-quick-edit-fixture.php create|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
// Loaded and settled (fonts in, two frames), never half a second of network quiet: pages are drawn by the server.
const {open: arrive} = require('./pace.cjs');
const {execFileSync} = require('node:child_process');
const {inspect, judge} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(execFileSync('podman', ['exec', 'env_php_1', 'cat', '/tmp/catto-course-quick-edit-state.json'], {encoding: 'utf8'}));
const {one, two} = state;
const list = (view, search) => `${base}/admin/courses?courses_q=${encodeURIComponent(search)}&courses_view=${view}`;
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: !!ok, detail}); if (!ok) console.log('FAIL', name, detail); };
const text = async (locator) => (await locator.textContent()).replace(/\s+/g, ' ').trim();

async function session(browser, options = {}) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce', ...options});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return context;
}

// A row is found by its course's code, its anchor (owner, 2026-11-06); its form's own ids still carry the row id.
const rowOf = (page, course) => page.locator(`tr#course-${course.code}`);
const field = (page, course, name) => page.locator(`#course-${course.code} [name="${name}"], [form="course-quick-${course.id}"][name="${name}"], #course-quick-${course.id} [name="${name}"]`).first();

// Does something to a row or card and waits until htmx has put a new one in its place (a page's load state says
// nothing about a request made after it).
async function swapped(page, selector, act) {
    await page.locator(selector).first().evaluate((element) => { element.dataset.cattoOld = '1'; });
    await act();
    await page.waitForFunction((wanted) => { const element = document.querySelector(wanted); return !!element && element.dataset.cattoOld !== '1'; }, selector);
}
const rowSelector = (course) => `tr#course-${course.code}`;
const cardSelector = (course) => `li.cl-ui-card:has(#course-${course.code})`;
const save = (page, course, title) => swapped(page, rowSelector(course), () => page.getByRole('button', {name: `Save ${title}`}).click());
const open = (page, course, title) => swapped(page, rowSelector(course), () => rowOf(page, course).getByRole('link', {name: `Edit ${title} here`}).click());

async function withScript(browserType, name) {
    const browser = await browserType.launch();
    const context = await session(browser);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    execFileSync('podman', ['exec', '-u', 'cattotest', 'env_php_1', 'sh', '-lc', 'php tests/Browser/course-quick-edit-fixture.php age 2>/dev/null']);

    await arrive(page, list('list', one.code));
    await page.evaluate(() => { window.cattoNoReload = true; });
    const updatedBefore = await text(rowOf(page, one).locator('td').nth(6));
    await open(page, one, one.title);
    check(`${name}: the pencil turns the row into its form without a page load`, await page.evaluate(() => window.cattoNoReload === true)
        && await field(page, one, 'title').inputValue() === one.title && await field(page, one, 'price').inputValue() === '1200.00'
        && await field(page, one, 'was_price').inputValue() === '1500.00' && await field(page, one, 'access_period_value').inputValue() === '1'
        && await field(page, one, 'access_period_unit').inputValue() === 'years');

    // Refused: a was price below the price. The row stays a form, with the reason and what was typed.
    await field(page, one, 'title').fill(`${one.title} Unsaved`);
    await field(page, one, 'price').fill('2000');
    await save(page, one, one.title);
    check(`${name}: a refused save keeps the form, the reason and what was typed`, await page.locator(`#course-${one.code} .cl-course-quick-error`).count() === 1
        && await field(page, one, 'title').inputValue() === `${one.title} Unsaved` && await field(page, one, 'price').inputValue() === '2000');
    await swapped(page, rowSelector(one), () => rowOf(page, one).getByRole('link', {name: `Cancel editing ${one.title}`}).click());
    const back = await text(rowOf(page, one));
    check(`${name}: Cancel puts the row back unchanged, nothing saved`, back.includes(one.title) && !back.includes('Unsaved') && back.includes('1 200'), back);

    // Saved: position, code, title, price, was price, period and category.
    await open(page, one, one.title);
    const newCode = `${one.code}X`, newTitle = `${one.title} Revised`;
    const position = await field(page, one, 'catalogue_position').inputValue();
    await field(page, one, 'catalogue_position').fill('9700');
    await field(page, one, 'code').fill(newCode);
    await field(page, one, 'title').fill(newTitle);
    await field(page, one, 'price').fill('1350');
    await field(page, one, 'was_price').fill('1600');
    await field(page, one, 'access_period_value').fill('6');
    await field(page, one, 'access_period_unit').selectOption('months');
    await page.locator(`#course-quick-category-${one.id}`).click();
    const option = page.locator(`#course-quick-category-${one.id}-options label.cl-ui-tree-select-option`).nth(1);
    const category = (await option.textContent()).trim();
    await option.click();
    // The row's anchor is the code, which this save changes: the row comes back under its new code.
    const renamed = {...one, code: newCode};
    await page.getByRole('button', {name: `Save ${one.title}`}).click();
    await page.locator(rowSelector(renamed)).waitFor();
    const saved = await text(rowOf(page, renamed));
    check(`${name}: Save redraws the row alone with everything changed`, await page.evaluate(() => window.cattoNoReload === true)
        && saved.includes(newCode) && saved.includes(newTitle) && saved.includes('1 350') && saved.includes('1 600') && saved.includes('6 months')
        && saved.toLowerCase().includes(category.toLowerCase()) && await rowOf(page, renamed).getAttribute('data-course-saved') !== null, saved);
    check(`${name}: the position is saved`, (await text(rowOf(page, renamed).locator('[data-position]'))) === '9700');
    check(`${name}: its Updated time is new`, (await text(rowOf(page, renamed).locator('td').nth(6))) !== updatedBefore, updatedBefore);
    const href = await rowOf(page, renamed).locator('td').nth(2).locator('a').getAttribute('href');
    check(`${name}: the course's address follows its code (owner, 2026-11-06)`, href === `/admin/courses/${newCode}`);

    // Put back as it was, by the same row.
    await open(page, renamed, newTitle);
    await field(page, renamed, 'catalogue_position').fill(position);
    await field(page, renamed, 'code').fill(one.code);
    await field(page, renamed, 'title').fill(one.title);
    await field(page, renamed, 'price').fill('1200');
    await field(page, renamed, 'was_price').fill('1500');
    await field(page, renamed, 'access_period_value').fill('12');
    await field(page, renamed, 'access_period_unit').selectOption('months');
    await page.locator(`#course-quick-category-${one.id}`).click();
    await page.locator(`#course-quick-category-${one.id}-options label.cl-ui-tree-select-option`).first().click();
    await page.getByRole('button', {name: `Save ${newTitle}`}).click();
    await page.locator(rowSelector(one)).waitFor();
    check(`${name}: and saved back`, (await text(rowOf(page, one))).includes(one.code) && (await text(rowOf(page, one))).toLowerCase().includes('uncategorised')
        && (await text(rowOf(page, one).locator('[data-position]'))) === position);

    // Other price options: Manage prices instead of the price's fields.
    await arrive(page, list('list', two.code));
    await open(page, two, two.title);
    check(`${name}: a course with other price options shows Manage prices`, await page.locator(`#course-${two.code} input[name="price"]`).count() === 0
        && await rowOf(page, two).getByRole('link', {name: 'Manage prices'}).count() === 1);

    // The grid: the card turns into the same form.
    await arrive(page, list('grid', one.code));
    await page.evaluate(() => { window.cattoNoReload = true; });
    const card = () => page.locator('li.cl-ui-card').filter({has: page.locator(`#course-${one.code}`)});
    await swapped(page, cardSelector(one), () => card().getByRole('link', {name: `Edit ${one.title} here`}).click());
    await page.locator(`#course-quick-${one.id} [name="title"]`).fill(`${one.title} Card`);
    await swapped(page, cardSelector(one), () => page.getByRole('button', {name: `Save ${one.title}`}).click());
    check(`${name}: in the grid the card is edited in place`, await page.evaluate(() => window.cattoNoReload === true)
        && (await text(page.locator('li.cl-ui-card').filter({has: page.locator(`#course-${one.code}`)}))).includes(`${one.title} Card`));
    await swapped(page, cardSelector(one), () => card().getByRole('link', {name: `Edit ${one.title} Card here`}).click());
    await page.locator(`#course-quick-${one.id} [name="title"]`).fill(one.title);
    await swapped(page, cardSelector(one), () => page.getByRole('button', {name: `Save ${one.title} Card`}).click());
    check(`${name}: and back`, (await text(card())).includes(one.title) && !(await text(card())).includes('Card'));
    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

async function withoutScript() {
    const browser = await chromium.launch();
    const context = await session(browser, {javaScriptEnabled: false});
    const page = await context.newPage();
    await page.goto(list('list', one.code), {waitUntil: 'load'});
    await rowOf(page, one).getByRole('link', {name: `Edit ${one.title} here`}).click();
    await page.waitForLoadState('load');
    check('without JavaScript: the pencil opens the list with the row as its form', page.url().includes(`edit=${one.code}`) && await field(page, one, 'title').count() === 1);
    await field(page, one, 'title').fill(`${one.title} Offline`);
    await page.getByRole('button', {name: `Save ${one.title}`}).click();
    await page.waitForLoadState('load');
    check('without JavaScript: Save sends it and the list comes back', !page.url().includes('edit=') && (await text(rowOf(page, one))).includes(`${one.title} Offline`)
        && (await page.locator('.cl-flash-stack').last().innerText()).includes('The course was saved'));
    await rowOf(page, one).getByRole('link', {name: `Edit ${one.title} Offline here`}).click();
    await page.waitForLoadState('load');
    await field(page, one, 'title').fill(one.title);
    await page.getByRole('button', {name: `Save ${one.title} Offline`}).click();
    await page.waitForLoadState('load');
    check('without JavaScript: and back', (await text(rowOf(page, one))).includes(one.title) && !(await text(rowOf(page, one))).includes('Offline'));
    await browser.close();
}

async function construction() {
    const browser = await chromium.launch();
    for (const width of [1440, 390]) {
        const context = await session(browser, {viewport: {width, height: 1000}});
        const page = await context.newPage();
        await arrive(page, `${list('list', one.code)}&edit=${one.code}`);
        const problems = judge(await page.evaluate(inspect), '').map((failure) => failure.replace(/^: /, ''));
        check(`the list with a row open is built as every page is at ${width}px`, problems.length === 0, problems.join(' | '));
        check(`nothing scrolls sideways at ${width}px`, await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
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
