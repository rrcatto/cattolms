/* One learner buys one course, everything made through the pages as people would do it (owner, 2026-10-19:
 * "No check may ever write records a real person could not have produced").
 *
 * It runs on a demo site with a clean database and a catalogue file with its courses (CATTO_CATALOGUE; the
 * file published with the code holds only categories and tags) and the courses' HTML files (CATTO_COURSES_DIR).
 * ADMIN signs in for the first time with the sign-in link Mailpit receives (that makes the ADMIN account) and
 * gives its date of birth, sets the card provider's transaction fee to 3% in Settings and loads the catalogue
 * file (`catalogue:import`): the categories as a tree and the tags, each with its icon. Then, for every course
 * the file lists, ADMIN imports it into its category, gives it its 12-month price and was price, ticks its tags,
 * uploads its cover picture and enters its position in the catalogue on its Details tab, and publishes it. ADMIN adds the learner Thandi Nkosi under
 * People. Thandi signs in with her own link and gives her date of birth (a date under 18 is refused first). Nothing
 * about her identity is asked before she buys. Thandi puts one course (CATTO_BOUGHT, or the catalogue's first)
 * in her cart, gives her details at checkout and pays with the simulated card, which stands in for the card
 * provider on a demo site. The course starts at once. Last, ADMIN's order page is read for what was recorded,
 * and Mailpit for what was sent: Thandi's order confirmation with her receipt attached and the email saying her
 * course has started, which the commerce worker sends within a minute.
 *
 * It leaves everything it makes. It refuses to run when a course from the catalogue is already on the site.
 * Screenshots, results.json and summary.json are written to /tmp/catto-one-learner-scenario
 * (CATTO_BROWSER_OUTPUT). Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://127.0.0.1:8025';
const root = path.resolve(__dirname, '..', '..');
const workspace = path.resolve(root, '..', '..');
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-one-learner-scenario';
const coursesDir = process.env.CATTO_COURSES_DIR || path.join(workspace, 'COURSES');
fs.mkdirSync(output, {recursive: true});

/* The administrator's address, from the instance's .env; never printed. */
const adminEmail = process.env.CATTO_ADMIN_EMAIL || (() => {
    const line = fs.readFileSync(path.join(workspace, 'env', '.env'), 'utf8').split('\n').find((l) => l.startsWith('APP_ADMIN_EMAIL='));
    return (line || '').slice('APP_ADMIN_EMAIL='.length).replace(/["'\s]/g, '');
})();
/* The catalogue file: the categories, tags, icons and covers, and for each course its category, tags,
 * 12-month price and was price. Written by `catalogue:export`, read back here. */
// The site's own catalogue file, with its courses (the file published with the code holds only the categories and
// tags): CATTO_CATALOGUE, or the private copy beside the code tree.
const catalogueFile = process.env.CATTO_CATALOGUE || path.join(workspace, 'CATALOGUE', 'catalogue.json');
const CATALOGUE = JSON.parse(fs.readFileSync(catalogueFile, 'utf8'));
// The course the learner buys: CATTO_BOUGHT names its file, or the catalogue's first course.
const BOUGHT = process.env.CATTO_BOUGHT || (CATALOGUE.courses[0] || {}).file;
if (!BOUGHT) throw new Error(`The catalogue file ${catalogueFile} lists no courses: give CATTO_CATALOGUE a catalogue with its courses.`);
const COURSES = CATALOGUE.courses.map((course) => ({...course, was: course.was_price, bought: course.file === BOUGHT}));
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const {execFileSync} = require('node:child_process');
/* A console command in the PHP container, with what it printed to standard output (Symfony's debug log goes to standard error). */
const console_ = (command, input = undefined) => execFileSync('podman', ['exec', '-i', '-u', 'cattotest', container, 'sh', '-lc', `php bin/console ${command} 2>/dev/null`], {input, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024}).trim();
/* An amount as the pages print it, as a pattern: R 1 200,00 with any spaces. */
const amount = (major) => {
    const [rands, cents] = Number(major).toFixed(2).split('.');
    return 'R\\s?' + rands.replace(/\B(?=(\d{3})+(?!\d))/g, ' ').split(' ').join('\\s?') + ',' + cents;
};
const LEARNER = {first: 'Thandi', last: 'Nkosi', email: 'thandi.nkosi@example.test', mobile: '082 555 0142', born: '1994-08-21',
    billing: {billing_name: 'Thandi Nkosi', address_line_1: '14 Jacaranda Avenue', locality: 'Rondebosch', city: 'Cape Town', region: 'Western Cape', postal_code: '7700'}};

const results = [], failures = [], summary = {};
const clean = (text) => text.replace(/\s+/g, ' ').trim();
const submit = async (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
const flash = async (page) => clean((await page.locator('[data-flash-message]').allInnerTexts()).join(' '));
const shot = (page, name) => page.screenshot({path: path.join(output, name + '.png'), fullPage: true});

async function step(name, run) {
    try {
        await run();
        results.push(name);
        console.log('DONE ' + name);
    } catch (error) {
        failures.push(`${name}: ${error.message.split('\n')[0]}`);
        console.log('FAIL ' + name + ': ' + error.message.split('\n')[0]);
        throw error;
    }
}

/* The newest message Mailpit received for an address since a moment. */
async function mailSince(address, since, pattern = null, seconds = 30) {
    for (let i = 0; i < seconds; i++) {
        const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent('to:' + address)}&limit=20`)).json();
        for (const message of found.messages || []) {
            if (new Date(message.Created).getTime() < since) continue;
            if (pattern && !pattern.test(message.Subject)) continue;
            return await (await fetch(`${mailpit}/api/v1/message/${message.ID}`)).json();
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error(`no email reached ${address === adminEmail ? 'the administrator' : address}`);
}
/* Chooses a category in the picker as a person does: open it, open each branch on the way, pick the option. */
async function chooseCategory(page, id, categoryPath) {
    const parts = categoryPath.split(' › ');
    const panel = page.locator(`#${id}-options`);
    if (!(await panel.isVisible())) await page.locator(`#${id}`).click();
    await panel.waitFor({state: 'visible'});
    for (let depth = 1; depth < parts.length; depth++) {
        const expander = panel.locator(`button.cl-ui-tree-select-expand[aria-label="Show what is inside ${parts.slice(0, depth).join(' › ')}"]`);
        if ((await expander.getAttribute('aria-expanded')) !== 'true') await expander.click();
    }
    const option = panel.locator(`label.cl-ui-tree-select-option:has(input[data-tree-select-path="${categoryPath}"])`);
    await option.evaluate((element) => element.scrollIntoView({block: 'center'}));
    await option.click();
    assert.equal(clean(await page.locator(`#${id} .cl-ui-tree-select-value`).innerText()), categoryPath, 'the picker shows the chosen category');
}
/* The date of birth, at the first sign-in of an account someone else made (adults only, owner, 2026-10-20). */
async function giveDateOfBirthIfAsked(page, born) {
    if (!(await page.locator('input[name=date_of_birth]').count())) return false;
    await page.locator('input[name=date_of_birth]').fill(born);
    await submit(page, page.getByRole('button', {name: 'Continue'}));
    return true;
}
async function signIn(page, address) {
    const since = Date.now() - 1000;
    await page.goto(`${base}/login`);
    await page.locator('#email').fill(address);
    await submit(page, page.locator('form[action="/login"] button[type=submit]'));
    const message = await mailSince(address, since, /sign/i);
    const link = ((message.Text || '') + (message.HTML || '')).match(/https:\/\/[^\s"<>]+\/auth\/consume\?token=[^\s"<>&]+/);
    assert.ok(link, 'the email holds a sign-in link');
    await page.goto(link[0].replace(/^https:\/\/[^/]+/, base));
}

(async () => {
    assert.ok(adminEmail, 'APP_ADMIN_EMAIL is set');
    const started = Date.now();
    const browser = await playwright.chromium.launch({headless: true});
    const adminCtx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1100}, reducedMotion: 'reduce'});
    const learnerCtx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1100}, reducedMotion: 'reduce'});
    const admin = await adminCtx.newPage();
    const learner = await learnerCtx.newPage();
    for (const page of [admin, learner]) {
        page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
        page.on('dialog', (dialog) => dialog.accept());
    }
    try {
        await step('1. ADMIN signs in with the link the sign-in email holds', async () => {
            await signIn(admin, adminEmail);
            await admin.goto(`${base}/admin/dashboard`);
            // ADMIN gives its date of birth at its account's first sign-in (made by the rebuild's sign-in, or here).
            if (await giveDateOfBirthIfAsked(admin, '1978-06-15')) summary.admin_date_of_birth_given_here = true;
            await admin.goto(`${base}/admin/dashboard`);
            assert.equal(await admin.locator('h1').count(), 1);
            await shot(admin, '01-admin-dashboard');
        });

        await step('2. ADMIN sets the Stitch transaction fee to 3% in Settings', async () => {
            await admin.goto(`${base}/admin/settings?section=commerce-fees#commerce-settings-fees`);
            await admin.locator('#setting-stitch_fee-percent').fill('3');
            await admin.locator('#setting-stitch_fee-fixed').fill('0');
            await submit(admin, admin.getByRole('button', {name: 'Save fees'}));
            assert.match(await flash(admin), /Commerce settings saved/);
        });

        await step('3. ADMIN loads the catalogue file: the categories as a tree and the tags, each with its icon', async () => {
            summary.catalogue_import = console_('catalogue:import -', fs.readFileSync(catalogueFile));
            const count = (nodes) => nodes.reduce((total, node) => total + 1 + count(node.children || []), 0);
            assert.match(summary.catalogue_import, new RegExp(`Categories: ${count(CATALOGUE.categories)} created, 0 already there\\. Tags: ${CATALOGUE.tags.length} created, 0 already there\\.`));
            await admin.goto(`${base}/admin/courses/categories`);
            assert.equal(await admin.locator('li.cl-tree-node').count(), count(CATALOGUE.categories), 'Course categories lists exactly the file\'s categories');
            assert.equal(await admin.locator('li.cl-tree-node .cl-category-icon svg').count(), count(CATALOGUE.categories), 'each with its icon');
            await shot(admin, '03-categories');
            await admin.goto(`${base}/admin/courses/tags`);
            assert.equal(await admin.locator('tbody tr').count(), CATALOGUE.tags.length, 'Course Tags lists the file\'s tags');
            await shot(admin, '03-tags');
        });

        summary.courses = [];
        for (const course of COURSES) {
            await step(`3. ADMIN imports ${course.file} into ${course.category}, prices it at R${course.price} (was R${course.was}) for 12 months, gives it its tags (${course.tags.join(', ')}) and cover, and publishes it`, async () => {
                await admin.goto(`${base}/admin/courses/import`);
                await admin.locator('#course-file').setInputFiles(path.join(coursesDir, course.file));
                await submit(admin, admin.locator('form[action="/admin/courses/import"] button[type=submit]'));
                // The review preselects the course already on the site when the file's keys collide with it.
                assert.equal(await admin.locator('select[name=replace_course_id]').inputValue(), '0', 'the course is not on the site yet: run this on a clean database');
                await chooseCategory(admin, 'import-category', course.category);
                await submit(admin, admin.locator('form[action="/admin/courses/import/commit"] button[type=submit]'));
                const id = Number(new URL(admin.url()).pathname.split('/').pop());
                assert.ok(id > 0, 'the import opens the new course');
                const title = clean(await admin.locator('h1').innerText());
                await admin.goto(`${base}/admin/courses/${id}?tab=pricing`);
                await admin.locator('#new-price-period').fill('12');
                await admin.locator('#new-price-unit').selectOption('months');
                await admin.locator('#new-price-amount').fill(course.price);
                await admin.locator('#new-price-was').fill(course.was);
                await admin.locator('#new-price-label').fill('12-month access');
                await admin.locator('form[action$="/pricing"] input[name=is_default]').check();
                await submit(admin, admin.getByRole('button', {name: 'Add price option'}));
                // Its tags, its cover picture and its position in the catalogue, on its Details tab (owner, 2026-10-20).
                await admin.goto(`${base}/admin/courses/${id}`);
                if (course.position !== null && course.position !== undefined) await admin.locator('#catalogue-position').fill(String(course.position));
                const tags = admin.locator('fieldset.cl-course-tags-choice');
                for (const tag of course.tags) await tags.getByRole('checkbox', {name: tag, exact: true}).check();
                const cover = path.join(output, 'covers', course.file.replace(/\.html$/, '.svg'));
                fs.mkdirSync(path.dirname(cover), {recursive: true});
                fs.writeFileSync(cover, course.cover);
                await admin.locator('#course-cover').setInputFiles(cover);
                await submit(admin, admin.getByRole('button', {name: 'Save course details'}));
                assert.match(await flash(admin), /The course details were updated/);
                await admin.goto(`${base}/admin/courses/${id}?tab=publication`);
                await admin.locator('#course-status').selectOption('published');
                await submit(admin, admin.getByRole('button', {name: 'Change status'}));
                if (!/published/i.test(await flash(admin))) {
                    // Publishing names what still needs attention; ADMIN publishes anyway, as the form allows.
                    await admin.goto(`${base}/admin/courses/${id}?tab=publication`);
                    await admin.locator('#course-status').selectOption('published');
                    await admin.locator('input[name=override_publication_warnings]').check();
                    await submit(admin, admin.getByRole('button', {name: 'Change status'}));
                }
                const slug = (clean(await admin.locator('main').innerText()).match(/ \/([a-z0-9-]+) /) || [])[1];
                assert.equal(slug, course.slug, 'the course has the slug the catalogue file names');
                summary.courses.push({id, title, slug, price: course.price, was: course.was, tags: course.tags, flash: await flash(admin).catch(() => '')});
            });
        }

        // Related courses (owner, 2026-10-20: "the relationship must be bijective"), ticked on each course's Details tab.
        // A relation is both courses', so a course whose partner was saved first already shows the partner ticked.
        if (COURSES.some((course) => (course.related || []).length)) {
            await step('3r. ADMIN relates the courses the catalogue file relates, and each relation shows on both courses', async () => {
                const bySlug = Object.fromEntries(summary.courses.map((course) => [course.slug, course]));
                const done = new Set();
                for (const course of COURSES.filter((c) => (c.related || []).length)) {
                    await admin.goto(`${base}/admin/courses/${bySlug[course.slug].id}`);
                    const box = admin.locator('fieldset.cl-course-related-choice');
                    let changed = false;
                    for (const other of course.related) {
                        const pair = [course.slug, other].sort().join('|');
                        const checkbox = box.getByRole('checkbox', {name: bySlug[other].title, exact: true});
                        if (done.has(pair)) {
                            assert.ok(await checkbox.isChecked(), `${course.slug} already shows ${other}, related from the other course`);
                        } else {
                            assert.equal(await checkbox.isChecked(), false, `${other} is not yet related to ${course.slug}`);
                            await checkbox.check();
                            changed = true;
                            done.add(pair);
                        }
                    }
                    if (changed) {
                        await submit(admin, admin.getByRole('button', {name: 'Save course details'}));
                        assert.match(await flash(admin), /The course details were updated/);
                    }
                }
                summary.related_pairs = done.size;
            });
        }

        await step('4. ADMIN adds the learner Thandi Nkosi under People, with the account email', async () => {
            await admin.goto(`${base}/admin/people`);
            await admin.getByRole('button', {name: 'Add person'}).first().click();
            const form = admin.locator('#quickAddPersonModal');
            await form.locator('input[name=first_name]').fill(LEARNER.first);
            await form.locator('input[name=last_name]').fill(LEARNER.last);
            await form.locator('input[name=email]').fill(LEARNER.email);
            await form.locator('select[name=role]').selectOption('STUDENT');
            await form.locator('input[name=send_notice]').check();
            await submit(admin, form.getByRole('button', {name: 'Create person'}));
            assert.match(await flash(admin), /The person was created/);
        });

        await step('5. Thandi signs in with the link her sign-in email holds', async () => {
            await signIn(learner, LEARNER.email);
            assert.ok(!new URL(learner.url()).pathname.startsWith('/login'), 'she is signed in');
            assert.match(new URL(learner.url()).pathname, /^\/account\/confirm-age/, 'her account was made by ADMIN, so she is asked her date of birth first');
            await shot(learner, '05a-confirm-age');
            // The site is for adults only: a date that makes her 17 is refused, as it would be for anyone. The
            // date field itself will not take it; with that check set aside, the site refuses it too.
            const seventeen = new Date(); seventeen.setFullYear(seventeen.getFullYear() - 17);
            const born = learner.locator('input[name=date_of_birth]');
            await born.fill(seventeen.toISOString().slice(0, 10));
            assert.ok(await born.evaluate((field) => field.validity.rangeOverflow), 'the date field will not take a date under 18');
            await learner.locator('form[action="/account/confirm-age"]').evaluate((form) => { form.noValidate = true; });
            await submit(learner, learner.getByRole('button', {name: 'Continue'}));
            assert.match(await flash(learner), /18 or older/, 'someone under 18 is refused by the site too');
            assert.match(new URL(learner.url()).pathname, /^\/account\/confirm-age/, 'and goes no further');
            assert.ok(await giveDateOfBirthIfAsked(learner, LEARNER.born), 'she gives her date of birth');
            assert.ok(!new URL(learner.url()).pathname.startsWith('/account/confirm-age'), 'and continues');
        });

        const bought = summary.courses.find((c, i) => COURSES[i].bought);
        await step('5b. The catalogue shows every course marked down: the was price struck through and a Special badge', async () => {
            await learner.goto(`${base}/courses`);
            assert.equal(await learner.locator('article.cl-course-card').count(), COURSES.length, 'every course is on the first page');
            assert.equal(await learner.locator('article.cl-course-card s.cl-ui-key-value-was').count(), COURSES.length, 'each card strikes its was price through');
            assert.equal(await learner.locator('article.cl-course-card .cl-course-card-special').count(), COURSES.length, 'each card says it is a special');
            assert.equal(await learner.locator('article.cl-course-card .cl-course-card-art svg').count(), COURSES.length, 'each card has its cover picture');
            assert.equal(await learner.locator('article.cl-course-card .cl-tag-chip').count(), COURSES.reduce((total, course) => total + Math.min(4, course.tags.length), 0), 'each card shows its tags');
            assert.equal(await learner.locator('article.cl-course-card .cl-tag-chip .cl-tag-icon svg').count(), COURSES.reduce((total, course) => total + Math.min(4, course.tags.length), 0), 'each with its icon');
            assert.equal(await learner.locator('#catalogue-categories .cl-ui-picture-choice-icon svg').count(), CATALOGUE.categories.length, 'the main categories are feature cards with their icons');
            // Numbered courses first, lowest first (owner, 2026-10-20: Basic before Advanced).
            const shownTitles = (await learner.locator('article.cl-course-card .cl-course-card-title').allInnerTexts()).map(clean);
            const numbered = COURSES.filter((course) => course.position !== null && course.position !== undefined).sort((a, b) => a.position - b.position).map((course) => summary.courses.find((c) => c.slug === course.slug).title);
            assert.deepEqual(shownTitles.slice(0, numbered.length), numbered, 'the catalogue follows each course\'s position');
            await shot(learner, '05b-catalogue-marked-down');
        });

        await step(`6. Thandi buys ${bought.title} with the simulated card, and it starts at once`, async () => {
            await learner.goto(`${base}/courses`);
            await submit(learner, learner.getByRole('link', {name: bought.title}).first());
            const boughtCourse = COURSES.find((course) => course.bought);
            assert.match(clean(await learner.locator('.cl-course-purchase .cl-price').first().innerText()), new RegExp(`Was ${amount(boughtCourse.was)} now ${amount(boughtCourse.price)} Save`), 'the course page strikes the was price through');
            // Its cover picture, large, with the title over it; access starts immediately; the content lists
            // no assessment; no Reviews section while there is none; grading is in the FAQ (owner, 2026-10-20).
            assert.equal(clean(await learner.locator('.cl-picture-head h1').innerText()), bought.title, 'the title sits over the cover picture');
            const relatedTitles = (COURSES.find((course) => course.bought).related || []).map((slug) => summary.courses.find((c) => c.slug === slug).title).sort();
            assert.deepEqual((await learner.locator('section[aria-labelledby="related-courses-heading"] .cl-course-card-title').allInnerTexts()).map(clean).sort(), relatedTitles, 'its related courses are shown as cards');
            assert.equal(await learner.locator('.cl-picture-head-art svg').count(), 1);
            assert.match(clean(await learner.locator('.cl-course-head').innerText()), /Access starts Immediately/);
            assert.ok(!(await learner.locator('main ol').first().innerText()).match(/assessment/i), 'the content lists the modules, no assessment');
            assert.equal(await learner.locator('#reviews').count(), 0, 'no Reviews section without a review');
            assert.equal(await learner.locator('a[href="/faq#grading"]').count(), 1, 'grading is in the FAQ');
            await shot(learner, '06a-course-page');
            await submit(learner, learner.locator('form[action="/cart/add"] button[value="add"]'));
            // Nothing about her identity is asked before she buys (owner, 2026-10-20: "We accept all payments").
            await learner.goto(`${base}/checkout`);
            assert.doesNotMatch(clean(await learner.locator('main').innerText()), /Verify your identity/, 'checkout asks nothing about her identity');
            await learner.goto(`${base}/checkout/profile`);
            await learner.locator('#mobile-number').fill(LEARNER.mobile);
            for (const [name, value] of Object.entries(LEARNER.billing)) await learner.locator(`[name=${name}]`).fill(value);
            const country = learner.locator('[name=country_code]');
            if ((await country.evaluate((el) => el.tagName)) === 'SELECT') await country.selectOption('ZA'); else await country.fill('ZA');
            await submit(learner, learner.getByRole('button', {name: 'Continue to payment'}));
            await learner.locator('#payment-method').selectOption('dummy');
            if (await learner.locator('#method-token').count()) await learner.locator('#method-token').selectOption('demo_success');
            await submit(learner, learner.getByRole('button', {name: 'Review my order'}));
            assert.equal(await learner.locator('main s.cl-price-was').count(), 1, 'the review strikes it through too');
            await shot(learner, '06b-checkout-review');
            await learner.locator('input[name=accept_terms]').check();
            await learner.locator('input[name=accept_start]').check();
            await submit(learner, learner.getByRole('button', {name: 'Place my order'}));
            assert.match(learner.url(), /\/account\/orders\/\d+$/, 'the order page opens');
            summary.order_id = Number(new URL(learner.url()).pathname.split('/').pop());
            await shot(learner, '06c-learner-order');
            await learner.goto(`${base}/account/courses`);
            assert.match(clean(await learner.locator('main').innerText()), new RegExp(bought.title.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), 'My Courses lists it');
            await shot(learner, '06d-my-courses');
        });

        await step('7. ADMIN reads what was recorded on the order page', async () => {
            await admin.goto(`${base}/admin/commerce/orders/${summary.order_id}`);
            await shot(admin, '07-admin-order');
            summary.order_head = clean(await admin.locator('.cl-page-head').innerText());
            summary.what_happened = clean(await admin.locator('main section.cl-page-section', {has: admin.locator('h2', {hasText: 'What happened'})}).first().innerText());
            summary.ledger = await admin.locator('tr[data-order-record="ledger"]').evaluateAll((rows) => rows.map((r) => r.innerText.replace(/\s+/g, ' ').trim()));
            summary.payment_events = await admin.locator('tr[data-order-record="payment"]').evaluateAll((rows) => rows.map((r) => r.innerText.replace(/\s+/g, ' ').trim()));
            summary.trail = await admin.locator('tr[data-order-record="trail"]').evaluateAll((rows) => rows.map((r) => r.innerText.replace(/\s+/g, ' ').trim()));
            summary.documents = await admin.locator('[data-document-kind]').evaluateAll((rows) => rows.map((r) => r.dataset.documentKind + ' ' + r.textContent.replace(/\s+/g, ' ').trim()));
            const paid = COURSES.find((course) => course.bought).price;
            assert.ok(summary.ledger.some((line) => new RegExp(`paid ${amount(paid)} by card`).test(line)), 'the payment is in the Money Ledger');
            assert.ok(summary.ledger.some((line) => new RegExp(`Stitch charged ${amount((Number(paid) * 0.03).toFixed(2))}`).test(line)), 'and Stitch\'s 3% fee');
            assert.ok(summary.trail.some((line) => /started/.test(line)), 'the course started');
            assert.deepEqual(summary.documents.map((d) => d.split(' ')[0]), ['invoice', 'receipt'], 'an invoice and a receipt were issued');
        });

        await step('8. Thandi is sent her order confirmation, with the receipt attached, and that her course has started', async () => {
            // The commerce worker sends them; it runs once a minute.
            const confirmation = await mailSince(LEARNER.email, started, /Order CL-\d{8} confirmed/, 150);
            const receipt = (confirmation.Attachments || []).map((a) => a.FileName);
            assert.ok(receipt.some((name) => /^REC-\d+\.pdf$/.test(name)), 'the receipt is attached: ' + receipt.join(', '));
            assert.match(confirmation.Text || '', /Account reference CL-\d{8}-\d{4,}-[0-9A-Z]/, 'it quotes the account reference');
            const course = await mailSince(LEARNER.email, started, /Your course has started/, 150);
            assert.ok((course.Text || '').includes(`/learn/${COURSES.find((c) => c.bought).slug}`), 'it links to the course');
            summary.confirmation_text = confirmation.Text;
            summary.course_started_text = course.Text;
        });

        await step('8b. The catalogue exported from the site matches the file it was loaded from', async () => {
            const exported = JSON.parse(console_('catalogue:export -'));
            const shape = (nodes) => nodes.map((node) => ({name: node.name, slug: node.slug, children: shape(node.children || [])}));
            assert.deepEqual(shape(exported.categories), shape(CATALOGUE.categories), 'the same categories in the same tree');
            assert.deepEqual(exported.tags.map((tag) => tag.name).sort(), CATALOGUE.tags.map((tag) => tag.name).sort(), 'the same tags');
            for (const course of COURSES) {
                const found = exported.courses.find((c) => c.slug === course.slug);
                assert.ok(found, course.slug + ' is on the site');
                assert.deepEqual([found.file, found.category, found.position ?? null, [...(found.related || [])].sort(), [...found.tags].sort(), found.price, found.was_price], [course.file, course.category, course.position ?? null, [...(course.related || [])].sort(), [...course.tags].sort(), course.price, course.was_price], course.slug + ' keeps its file, category, position, related courses, tags and prices');
            }
        });

        await step('9. Mailpit shows every email the site sent', async () => {
            const found = await (await fetch(`${mailpit}/api/v1/messages?limit=50`)).json();
            summary.emails = (found.messages || []).filter((m) => new Date(m.Created).getTime() >= started)
                .map((m) => ({to: m.To.map((t) => t.Address === adminEmail ? 'ADMIN' : t.Address).join(', '), subject: m.Subject, attachments: m.Attachments, at: m.Created}))
                .reverse();
            assert.ok(summary.emails.some((m) => m.to === LEARNER.email), 'Thandi received email');
        });
    } catch (error) {
        // The step that failed is recorded; nothing is undone, so what happened can be read.
    } finally {
        await browser.close();
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({results, failures}, null, 2));
        fs.writeFileSync(path.join(output, 'summary.json'), JSON.stringify(summary, null, 2));
    }
    console.log(JSON.stringify({steps: results.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
