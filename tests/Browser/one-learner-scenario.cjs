/* One learner buys one course (owner, 2026-10-18: "a system with 1 learner, 4 courses, places an order
 * for 1 course and pays for it via stitch. transaction fee is 3%. course starts immediately. simulate
 * that transaction"; 2026-10-19: "No check may ever write records a real person could not have
 * produced").
 *
 * Everything happens through the pages, as the people would do it, on a demo site with a clean
 * database. ADMIN signs in for the first time with the sign-in link Mailpit receives (that makes the
 * ADMIN account), sets the Stitch transaction fee to 3% in Settings, imports four courses from the
 * workspace's COURSES folder, gives each a 12-month price and publishes it, and adds the learner Thandi
 * Nkosi under People. Thandi signs in with her own link, puts Example Course 1 in her
 * cart, gives her details at checkout and pays with the simulated card, which stands in for Stitch on a
 * demo site. The course starts at once. Last, ADMIN's order page is read for what was recorded, and
 * Mailpit for what was sent: Thandi's order confirmation with her receipt attached and the email saying
 * her course has started (owner, 2026-10-19), which the commerce worker sends within a minute.
 *
 * It leaves everything it makes: that is what the owner follows through. It refuses to run when a
 * course from the folder is already on the site. Screenshots, results.json and summary.json are written
 * to /tmp/catto-one-learner-scenario (CATTO_BROWSER_OUTPUT). Uses the installed Playwright. */
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
const COURSES = [
    {file: 'example-course-1.html', category: 'Finance and Accounting › Financial Accounting › Bookkeeping Practice', price: '450.00', bought: true},
    {file: 'example-course-2.html', category: 'Finance and Accounting › Financial Accounting › Financial Statements', price: '550.00'},
    {file: 'example-course-3.html', category: 'Finance and Accounting › Financial Accounting › Bookkeeping Practice', price: '500.00'},
    {file: 'example-course-4.html', category: 'Finance and Accounting › Financial Accounting › Financial Statements', price: '750.00'},
];
const LEARNER = {first: 'Thandi', last: 'Nkosi', email: 'thandi.nkosi@example.test', mobile: '082 555 0142',
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

        summary.courses = [];
        for (const course of COURSES) {
            await step(`3. ADMIN imports ${course.file}, prices it at R${course.price} for 12 months and publishes it`, async () => {
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
                await admin.locator('#new-price-label').fill('12-month access');
                await admin.locator('form[action$="/pricing"] input[name=is_default]').check();
                await submit(admin, admin.getByRole('button', {name: 'Add price option'}));
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
                summary.courses.push({id, title, slug, price: course.price, flash: await flash(admin).catch(() => '')});
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
        });

        const bought = summary.courses.find((c, i) => COURSES[i].bought);
        await step(`6. Thandi buys ${bought.title} with the simulated card, and it starts at once`, async () => {
            await learner.goto(`${base}/courses`);
            await submit(learner, learner.getByRole('link', {name: bought.title}).first());
            await shot(learner, '06a-course-page');
            await submit(learner, learner.locator('form[action="/cart/add"] button[value="add"]'));
            await learner.goto(`${base}/checkout/profile`);
            await learner.locator('#mobile-number').fill(LEARNER.mobile);
            for (const [name, value] of Object.entries(LEARNER.billing)) await learner.locator(`[name=${name}]`).fill(value);
            const country = learner.locator('[name=country_code]');
            if ((await country.evaluate((el) => el.tagName)) === 'SELECT') await country.selectOption('ZA'); else await country.fill('ZA');
            await submit(learner, learner.getByRole('button', {name: 'Continue to payment'}));
            await learner.locator('#payment-method').selectOption('dummy');
            if (await learner.locator('#method-token').count()) await learner.locator('#method-token').selectOption('demo_success');
            await submit(learner, learner.getByRole('button', {name: 'Review my order'}));
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
            assert.ok(summary.ledger.some((line) => /paid R\s?450,00 by card/.test(line)), 'the payment is in the Money Ledger');
            assert.ok(summary.ledger.some((line) => /Stitch charged R\s?13,50/.test(line)), 'and Stitch\'s 3% fee, R13,50');
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
            assert.match(course.Text || '', /\/learn\/example-course-1/, 'it links to the course');
            summary.confirmation_text = confirmation.Text;
            summary.course_started_text = course.Text;
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
