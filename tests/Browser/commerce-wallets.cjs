/* Wallets, EFT amounts, refunds, the transactions that need attention and the commerce settings in a
 * real browser (2026-10-17 launch work).
 *
 * In Chromium and Firefox, for a fresh learner whose R200.00 of wallet credit came from an EFT that paid
 * R200.00 more than a course cost (the fixture makes it only as a learner and ADMIN would): the wallet
 * pays part of a R300.00 course and the rest is chosen as EFT; checkout asks for both boxes; the order
 * page quotes the account reference and the R100.00 still to pay; ADMIN records an EFT of R150.00 (in
 * Chromium from Record EFT Payment, found by the account reference on the bank statement, in Firefox
 * on the order's page), the course starts and R50.00 goes to the wallet; My Wallet shows it; the ADMIN dashboard lists the overpayment,
 * ADMIN emails the learner (Mailpit receives it) and marks it dealt with; the learner asks for a refund
 * on the order page as money back; ADMIN approves it with an override and records the payout; and
 * Start course on an ADMIN-assigned course needs its confirmation box, in the browser and on the
 * server. A private learner is offered buying and no company request; a company learner is offered
 * only Request from my company, with the reason it cannot buy, has no cart in the header, and is sent
 * from checkout to the cart, which explains it (owner, 2026-10-18). Without JavaScript the refund request and Start course still work. ADMIN changes a fee
 * on Commerce Settings, the Refund Policy shows it, a wrong value is refused with its reason, and the
 * reset restores the default. A deposit that pays no order goes on Unmatched Money and the dashboard,
 * and leaves both when ADMIN records that it was paid back. An order's page shows its Money Ledger
 * lines, Payment Events and Commerce Trail together, each opening its own page; Credits & Orders lists
 * neither Payment Events nor the Commerce Trail (owner, 2026-10-19). The learner is emailed how to pay
 * the EFT, with the invoice attached and the demo site's fake bank details labelled (owner, 2026-10-19). In every bundled theme at 1440, 820 and 390px the new pages answer in
 * that theme with one h1 and no sideways scrolling.
 *
 * Run against the development instance with commerce-wallets-fixture.php. Orders and the ledger are
 * immutable and stay behind. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://localhost:8025';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-commerce-wallets-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-commerce-wallets';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (...args) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/commerce-wallets-fixture.php ${args[0]} ${statePath} ${args.slice(1).join(' ')} 2>/dev/null`], {encoding: 'utf8'}).trim().split('\n').pop();
const state = () => JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const widths = (process.env.CATTO_WIDTHS || '1440,820,390').split(',').map(Number);
const results = [], failures = [];
const clean = (text) => text.replace(/\s+/g, ' ').trim();
/* Amounts as the platform prints them (en_ZA), after clean() has made every space ordinary: "R 1 173,45". */
const rand = (minor) => `R ${Math.floor(minor / 100).toLocaleString('en-ZA').replace(/\s/g, ' ')},${String(minor % 100).padStart(2, '0')}`;
const order = (id) => `CL-${String(id).padStart(8, '0')}`;
/* Now, a minute ago, in South African time with its offset, as the bank confirmation form wants it. */
const today = () => new Date(Date.now() + 2 * 3600 * 1000).toISOString().slice(0, 10);

async function check(name, run) {
    try {
        await run();
        results.push(name);
    } catch (error) {
        const actual = error.actual === undefined ? '' : ` (actual: ${JSON.stringify(error.actual).slice(0, 200)})`;
        failures.push(`${name}: ${error.message.split('\n')[0]}${actual}`);
    }
}
async function open(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: 1100}, javaScriptEnabled: options.js !== false, reducedMotion: 'reduce'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const submit = async (page, locator) => Promise.all([page.waitForNavigation(), locator.click()]);
const flash = async (page) => clean(await page.locator('[data-flash-message] > div').first().innerText());
const text = async (page) => clean(await page.locator('main').innerText());
/* The commerce worker's run, once, as the env_commerce-worker_1 loop does each minute. */
const maintain = () => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', 'SHELL_VERBOSITY=-1 php bin/console commerce:maintain --no-debug 2>/dev/null'], {encoding: 'utf8'});
/* The one message Mailpit holds for an address with this subject, waiting up to the seconds given. */
async function mailTo(address, subject, seconds = 20) {
    for (let i = 0; i < seconds; i++) {
        const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${address} subject:"${subject}"`)}`)).json();
        if ((found.messages || []).length > 0) return await (await fetch(`${mailpit}/api/v1/message/${found.messages[0].ID}`)).json();
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error(`no email "${subject}" reached the learner`);
}

/* The learner's course B in the cart, paid partly from the wallet and the rest by EFT, placed. */
async function buyWithWalletAndEft(page, fixture) {
    await page.goto(`${base}/courses/${fixture.courses.b.slug}`);
    assert.equal(await page.getByRole('button', {name: 'Request from my company'}).count(), 0, 'a private account has no company to ask');
    await submit(page, page.locator('form[action="/cart/add"] button[value="add"]'));
    await page.goto(`${base}/checkout/profile`);
    await submit(page, page.getByRole('button', {name: 'Continue to payment'}));
    assert.equal(await page.locator('input[name=use_wallet]').isChecked(), true, 'the wallet is offered and chosen');
    assert.match(await text(page), new RegExp(`Use my wallet credit \\(${rand(20000)} available\\)`));
    await page.locator('#payment-method').selectOption('eft');
    await submit(page, page.getByRole('button', {name: 'Review my order'}));
    const review = await text(page);
    assert.match(review, new RegExp(`Paid from your wallet ${rand(20000)}`), 'the review shows the wallet part');
    assert.match(review, new RegExp(`To pay ${rand(10000)}`), 'and what is left to pay');
    const place = page.getByRole('button', {name: 'Place my order'});
    await page.locator('input[name=accept_terms]').check();
    await place.click();
    await page.waitForTimeout(300);
    assert.match(page.url(), /\/checkout\/review/, 'the browser keeps the form until the start box is ticked too');
    assert.equal(await page.locator('input[name=accept_start]').evaluate((box) => box.validity.valueMissing), true);
    await page.locator('input[name=accept_start]').check();
    await submit(page, place);
    assert.match(page.url(), /\/account\/orders\/\d+$/);
}

(async () => {
    php('create');
    const fixture = state();
    const chromium = await playwright.chromium.launch({headless: true});
    const firefox = await playwright.firefox.launch({headless: true});
    const admin = {chromium: await open(chromium, fixture.admin_token), firefox: await open(firefox, fixture.admin_token)};
    let sample = null;

    for (const [name, browser] of [['chromium', chromium], ['firefox', firefox]]) {
        const learner = JSON.parse(php('learner'));
        const {ctx, page} = await open(browser, learner.token);
        const adminPage = admin[name].page;
        let placed = null;
        await check(`${name}: the wallet pays part of an order and checkout asks for both boxes`, async () => {
            await buyWithWalletAndEft(page, fixture);
            placed = JSON.parse(php('order', learner.id));
            assert.equal(placed.state, 'awaiting_payment');
            assert.equal(placed.due, 10000);
            const shown = await text(page);
            assert.match(shown, new RegExp(`Use your account reference as the payment reference: ${learner.reference}\\. Amount to pay: ${rand(10000)}\\.`), 'the EFT instructions quote the account reference and the rest');
            assert.equal(php('wallet', learner.id), '0', 'the wallet part was taken at placement');
        });
        await check(`${name}: the learner is emailed how to pay, with the invoice and the bank account`, async () => {
            maintain();
            const mail = await mailTo(learner.email, `Order ${order(placed.id)} received: how to pay by EFT`);
            const body = clean(mail.Text || '');
            assert.match(body, new RegExp(`Account reference ${learner.reference} Order ${order(placed.id)}, placed`), 'it names the account and the order');
            assert.match(body, new RegExp(`Total: ${rand(30000)} Paid so far: ${rand(20000)} Amount to pay: ${rand(10000)}`), 'what is paid and what is left to pay');
            assert.match(body, new RegExp(`Payment reference: ${learner.reference}`), 'the account reference to pay with');
            assert.match(body, /These bank details are fake: this is a demo site\. Do not pay\./, 'a demo site\'s fake bank details say so');
            assert.match(body, /Please pay by \d{1,2} \w+ \d{4} at \d{2}:\d{2}\. An order that is not paid within .+ is cancelled automatically, and what you paid towards it goes to your wallet\./);
            assert.match(body, /Your course starts as soon as your payment is confirmed\./);
            const files = (mail.Attachments || []).map((a) => a.FileName);
            assert.ok(files.length === 1 && /^INV-\d+\.pdf$/.test(files[0]), 'the invoice is attached: ' + files.join(', '));
        });
        await check(`${name}: an EFT above what is due starts the course and credits the wallet`, async () => {
            await adminPage.goto(`${base}/admin/commerce/orders/${placed.id}`);
            // The order page names the account first and says what to do next (owner, 2026-10-18): under the order number,
            // the person linked to their profile, their account reference and their email, each on its own line (owner, 2026-10-21).
            assert.match(await adminPage.locator('.cl-page-head').innerText(), new RegExp(`Thandi Nkosi\\s+${learner.reference}`), 'the name, then the account reference on its own line');
            assert.equal(await adminPage.locator('.cl-page-head a.cl-ui-person-account').getAttribute('href'), `/admin/people/${learner.id}`, 'the name opens their account');
            assert.match(await text(adminPage), /Waiting for the EFT payment/);
            if (name === 'chromium') {
                // Record EFT Payment starts from the bank statement: no hunting for the order (owner, 2026-10-18).
                await adminPage.goto(`${base}/admin/commerce/eft`);
                await adminPage.locator('#eft-reference').fill(`EFT ${learner.reference} THANDI`);
                await adminPage.locator('#eft-amount').fill('150.00');
                await adminPage.locator('#eft-received-on').fill(today());
                await adminPage.locator('#eft-bank-reference').fill(`FNB-${name}-${Date.now()}`);
                await adminPage.locator('#eft-reason').fill('Seen on the bank statement.');
                await submit(adminPage, adminPage.getByRole('button', {name: 'Find the account'}));
                assert.match(await adminPage.locator('[data-eft-account]').innerText(), new RegExp(learner.reference), 'the account is found from the statement');
                await submit(adminPage, adminPage.locator(`tr[data-eft-order="${placed.id}"] button`));
                assert.equal(new URL(adminPage.url()).pathname, `/admin/commerce/orders/${placed.id}`, 'and the payment is recorded against its order');
            } else {
                await adminPage.locator('#bank-amount').fill('150.00');
                await adminPage.locator('#bank-received-on').fill(today());
                await adminPage.locator('input[name=bank_reference]').fill(`FNB-${name}-${Date.now()}`);
                await adminPage.locator('textarea[name=reason]').first().fill('Seen on the bank statement.');
                await submit(adminPage, adminPage.getByRole('button', {name: 'Record the EFT payment'}));
            }
            assert.equal(JSON.parse(php('order', learner.id)).state, 'fulfilled');
            assert.equal(php('wallet', learner.id), '5000');
            await page.goto(`${base}/account/wallet`);
            const wallet = await text(page);
            assert.match(wallet, new RegExp(rand(5000)), 'My Wallet shows the balance');
            assert.match(wallet, /Overpayment credited to wallet/, 'and the overpayment on the statement');
            await page.goto(`${base}/account/dashboard`);
            assert.match(await text(page), /Started on payment, /, 'the dashboard says the course started on payment');
        });
        await check(`${name}: ADMIN sees the overpayment, emails the learner and marks it dealt with`, async () => {
            await adminPage.goto(`${base}/admin/dashboard`);
            const title = `Order ${order(placed.id)} was overpaid`;
            await submit(adminPage, adminPage.getByRole('link', {name: title, exact: true}));
            const item = await text(adminPage);
            assert.match(item, new RegExp(`${rand(5000)} more than was due went to the payer.s wallet`));
            assert.match(await adminPage.locator('textarea[name=body]').inputValue(), /went to your wallet/, 'the email starts written');
            const subject = `Your overpayment ${name} ${Date.now()}`;
            await adminPage.locator('input[name=subject]').fill(subject);
            await submit(adminPage, adminPage.getByRole('button', {name: 'Send email'}));
            assert.match(await flash(adminPage), /sent/i);
            assert.match(await text(adminPage), new RegExp(subject), 'the email is listed on the item');
            const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(`subject:"${subject}"`)}`)).json();
            assert.equal(found.messages_count ?? found.total ?? found.messages.length, 1, 'Mailpit received the email');
            await submit(adminPage, adminPage.getByRole('button', {name: 'Mark as dealt with'}));
            await adminPage.goto(`${base}/admin/dashboard`);
            assert.equal(await adminPage.getByRole('link', {name: title, exact: true}).count(), 0, 'the item has left the list');
        });
        await check(`${name}: the learner asks for money back, ADMIN overrides the rule and records the payout`, async () => {
            await page.goto(`${base}/account/orders/${placed.id}`);
            await page.locator('summary', {hasText: 'Ask for a refund'}).click();
            assert.match(await text(page), /A started course is not refunded\. If the course is faulty, is not as described or cannot be provided, tell us below/);
            await page.locator('textarea[name=reason]').fill('Module two will not open.');
            await page.locator('select[name=destination]').selectOption('money_back');
            await submit(page, page.getByRole('button', {name: 'Send my refund request'}));
            assert.match(await flash(page), /Your refund request was sent/);
            assert.match(await text(page), /It is waiting for a decision\./);
            const request = php('request', learner.id);
            await adminPage.goto(`${base}/admin/commerce/refund-requests`);
            const form = adminPage.locator(`form[action="/admin/commerce/refund-requests/${request}/approve"]`);
            await form.locator('select[name=basis]').selectOption('service_failure');
            await form.locator('textarea[name=reason]').fill('Module two did not open.');
            await form.locator('textarea[name=override_reason]').fill('Faulty course confirmed by ADMIN.');
            await submit(adminPage, form.getByRole('button', {name: 'Override and approve'}));
            assert.equal(JSON.parse(php('order', learner.id)).state, 'refunded');
            assert.equal(php('wallet', learner.id), '25000', 'the wallet part went back to the wallet');
            await adminPage.goto(`${base}/admin/commerce/orders/${placed.id}`);
            const payout = adminPage.locator('form[action$="/payout"]');
            assert.match(clean(await payout.innerText()), /Pay /, 'the order says what to pay out');
            await payout.locator('input[name=paid_on]').fill(today());
            await payout.locator('input[name=reference]').fill(`EFT-REFUND-${name}`);
            await submit(adminPage, payout.getByRole('button', {name: 'Record the refund payment'}));
            assert.equal(await adminPage.locator('form[action$="/payout"]').count(), 0, 'nothing is left to pay');
            assert.match(await text(adminPage), new RegExp(`EFT-REFUND-${name}`));
        });
        await check(`${name}: Start course needs its confirmation, in the browser and on the server`, async () => {
            await page.goto(`${base}/learn/${fixture.courses.a.slug}`);
            const start = page.getByRole('button', {name: 'Start course'});
            await start.click();
            await page.waitForTimeout(300);
            assert.equal(await page.locator('input[name=confirm_start]').evaluate((box) => box.validity.valueMissing), true, 'the browser asks for the tick');
            await page.locator('input[name=confirm_start]').evaluate((box) => box.removeAttribute('required'));
            await submit(page, start);
            assert.match(await flash(page), /Tick the box to confirm that starting begins your access period/, 'the server refuses without it');
            await page.locator('input[name=confirm_start]').check();
            await submit(page, page.getByRole('button', {name: 'Start course'}));
            assert.match(await flash(page), /The course has started/);
        });
        await check(`${name}: a private learner can buy and a company learner can only ask its company`, async () => {
            const staff = JSON.parse(php('company-learner'));
            const company = await open(browser, staff.token);
            await company.page.goto(`${base}/courses/${fixture.courses.b.slug}`);
            const box = company.page.locator('.cl-course-purchase').first();
            assert.equal(await box.getByRole('button', {name: 'Add to cart'}).count(), 0, 'no Add to cart');
            assert.equal(await box.getByRole('button', {name: 'Buy now'}).count(), 0, 'no Buy now');
            assert.match(clean(await box.innerText()), /Company accounts cannot buy courses for themselves\. Your company gives you your courses\./);
            assert.equal(await company.page.locator('.cl-cart-chrome').count(), 0, 'no cart in the header');
            await company.page.goto(`${base}/checkout`);
            assert.equal(new URL(company.page.url()).pathname, '/cart', 'checkout sends it to the cart');
            assert.match(await flash(company.page), /^Company accounts cannot buy courses for themselves\./);
            assert.match(await text(company.page), /To buy a course for yourself, register a separate private account with your own email address\./);
            await company.page.goto(`${base}/courses/${fixture.courses.b.slug}`);
            await submit(company.page, company.page.getByRole('button', {name: 'Request from my company'}));
            assert.equal(await flash(company.page), 'Your course request was sent to your company administrator.');
            await company.ctx.close();
        });
        if (name === 'chromium') sample = {learner, order: placed.id};
        await ctx.close();
    }

    await check('chromium without JavaScript: the refund request and Start course work as plain forms', async () => {
        const learner = JSON.parse(php('learner'));
        const js = await open(chromium, learner.token);
        await js.page.goto(`${base}/courses/${fixture.courses.b.slug}`);
        await submit(js.page, js.page.locator('form[action="/cart/add"] button[value="add"]'));
        await js.page.goto(`${base}/checkout/profile`);
        await submit(js.page, js.page.getByRole('button', {name: 'Continue to payment'}));
        await js.page.locator('input[name=use_wallet]').uncheck();
        await js.page.locator('#payment-method').selectOption('dummy');
        if (await js.page.locator('#method-token').count()) await js.page.locator('#method-token').selectOption('demo_success');
        await submit(js.page, js.page.getByRole('button', {name: 'Review my order'}));
        await js.page.locator('input[name=accept_terms]').check();
        await js.page.locator('input[name=accept_start]').check();
        await submit(js.page, js.page.getByRole('button', {name: 'Place my order'}));
        const placed = JSON.parse(php('order', learner.id));
        assert.equal(placed.state, 'fulfilled', 'paid by the simulated card, the course started');
        await js.ctx.close();
        const {ctx, page} = await open(chromium, learner.token, {js: false});
        await page.goto(`${base}/account/orders/${placed.id}`);
        await page.locator('summary', {hasText: 'Ask for a refund'}).click();
        await page.locator('textarea[name=reason]').fill('The videos do not play.');
        await submit(page, page.getByRole('button', {name: 'Send my refund request'}));
        assert.match(await text(page), /It is waiting for a decision\./);
        await submit(page, page.getByRole('button', {name: 'Withdraw my refund request'}));
        assert.equal(php('request', learner.id), '0', 'the learner withdrew it');
        await page.goto(`${base}/learn/${fixture.courses.a.slug}`);
        await page.locator('input[name=confirm_start]').check();
        await submit(page, page.getByRole('button', {name: 'Start course'}));
        assert.match(await flash(page), /The course has started/);
        await ctx.close();
    });

    await check('chromium: the commerce settings in Settings change a fee, refuse a wrong value with its reason, and reset', async () => {
        const settings = await open(chromium, fixture.admin_token);
        const page = settings.page;
        // One settings area (owner, 2026-10-18): the commerce settings are sections of Administration > Settings.
        await page.goto(`${base}/admin/settings?section=commerce-fees#commerce-settings-fees`);
        await page.locator('#setting-stitch_fee-percent').fill('2.9');
        await page.locator('#setting-stitch_fee-fixed').fill('1.50');
        await submit(page, page.getByRole('button', {name: 'Save fees'}));
        assert.match(await flash(page), /Commerce settings saved/);
        const refunds = await open(chromium, fixture.admin_token);
        await refunds.page.goto(`${base}/refunds`);
        // The Refund Policy names no payment provider (owner, 2026-10-19): it deducts whatever fee the provider charged.
        // Its sections are closed accordions (owner, 2026-10-20): they are opened to be read.
        await refunds.page.evaluate(() => document.querySelectorAll('details.cl-ui-accordion-section').forEach((section) => { section.open = true; }));
        const policy = await text(refunds.page);
        assert.match(policy, /any fee the payment provider charged us for your payment/, 'the Refund Policy states the provider\'s fee in general');
        assert.doesNotMatch(policy, /Stitch/, 'the Refund Policy names no payment provider');
        await refunds.ctx.close();
        await page.goto(`${base}/admin/settings?section=commerce-wallet#commerce-settings-wallet`);
        // The address opens the Wallet credit section.
        await page.locator('#setting-wallet_reminder').fill('5y');
        await submit(page, page.getByRole('button', {name: 'Save wallet credit'}));
        assert.match(await flash(page), /Expiry reminder: make it shorter than the credit expiry\./);
        await page.goto(`${base}/admin/settings?section=commerce-fees#commerce-settings-fees`);
        await submit(page, page.getByRole('button', {name: 'Reset stitch transaction fee to the .env default'}));
        assert.equal(await page.locator('#setting-stitch_fee-percent').inputValue(), '0', 'the .env default is back');
        await settings.ctx.close();
    });

    await check('chromium: a dashboard money figure opens the ledger, an entry, the trail and the payment events', async () => {
        const admin = await open(chromium, fixture.admin_token);
        const page = admin.page;
        await page.goto(`${base}/admin/dashboard?money_period=ytd`);
        const received = page.locator('a.cl-ui-stat-link', {hasText: 'Money received'});
        assert.equal(await received.count(), 1, 'the Money received figure is a link');
        await Promise.all([page.waitForNavigation(), received.click()]);
        assert.equal(new URL(page.url()).pathname, '/admin/commerce/ledger');
        assert.equal(new URL(page.url()).searchParams.get('kind'), 'payment_received', 'filtered to what the figure adds up');
        assert.equal(await page.locator('#ledger-kind').inputValue(), 'payment_received');
        await page.goto(`${base}/admin/commerce/ledger?q=${encodeURIComponent(sample.learner.reference)}`);
        const kinds = await page.locator('tr[data-ledger-id]').evaluateAll(rows => rows.map(r => r.dataset.ledgerKind));
        assert.ok(kinds.includes('payment_received') && kinds.includes('overpayment_to_wallet'), 'the learner\'s payment and overpayment, found by account reference: ' + kinds.join(', '));
        assert.match(await text(page), /paid R\s?[\d\s]+,\d{2} more than was due/, 'each line says what happened in a sentence');
        await Promise.all([page.waitForNavigation(), page.locator('tr[data-ledger-id] a', {hasText: 'Open'}).first().click()]);
        assert.match(await page.locator('h1').innerText(), /^Ledger line \d+$/);
        assert.ok((await text(page)).includes(sample.learner.reference), 'the entry names the account');
        // The order's page shows its ledger lines, payment events and trail together, each opening its
        // own page, whose way back is the order (owner, 2026-10-19).
        await page.goto(`${base}/admin/commerce/orders/${sample.order}`);
        for (const kind of ['ledger', 'payment', 'trail']) assert.ok(await page.locator(`tr[data-order-record="${kind}"]`).count() > 0, `the order page lists its ${kind} records`);
        await Promise.all([page.waitForNavigation(), page.locator('tr[data-order-record="payment"] a').first().click()]);
        assert.match(await page.locator('h1').innerText(), /^Payment step \d+$/);
        assert.match(await page.locator('[data-payment-sentence]').innerText(), /payment of R\s?[\d\s]+,\d{2} for order CL-\d{8} /, 'the step says what became of the payment');
        await Promise.all([page.waitForNavigation(), page.locator('a.cl-back-link').click()]);
        assert.equal(new URL(page.url()).pathname, `/admin/commerce/orders/${sample.order}`, 'the way back is the order');
        await Promise.all([page.waitForNavigation(), page.locator('tr[data-order-record="trail"] a').first().click()]);
        assert.ok(await page.locator('[data-trail-sentence]').count() === 1, 'a trail entry opens its own page');
        const menu = await page.locator('#cl-primary-navigation').evaluate((nav) => [...nav.querySelectorAll('a')].map((a) => a.textContent.trim()));
        assert.ok(menu.includes('Record EFT Payment') && menu.includes('Unmatched Money'), 'Credits & Orders lists Record EFT Payment and Unmatched Money');
        assert.ok(!menu.includes('Payment Events') && !menu.includes('Commerce Trail'), 'and not Payment Events or the Commerce Trail');
        await admin.ctx.close();
    });

    await check('chromium: money that pays no order waits on Unmatched Money until it is paid back', async () => {
        const admin = await open(chromium, fixture.admin_token);
        const page = admin.page;
        const payer = `NO SUCH PAYER ${Date.now()}`;
        await page.goto(`${base}/admin/commerce/eft`);
        await page.locator('#eft-reference').fill(payer);
        await page.locator('#eft-amount').fill('99.00');
        await page.locator('#eft-received-on').fill(today());
        await page.locator('#eft-bank-reference').fill(`FNB-UNMATCHED-${Date.now()}`);
        await page.locator('#eft-reason').fill('Seen on the bank statement.');
        await submit(page, page.getByRole('button', {name: 'Find the account'}));
        assert.match(await text(page), /There is no account reference or order number in/, 'the page says nothing matches');
        await submit(page, page.getByRole('button', {name: 'Put it on the unmatched money list'}));
        assert.match(clean(await page.locator("[data-unmatched-sentence]").innerText()), new RegExp(`${rand(9900)} arrived on .+ with the reference "${payer}"`));
        const item = new URL(page.url()).pathname;
        await page.goto(`${base}/admin/dashboard`);
        assert.ok(await page.locator(`a[href="${item}"]`).count() > 0, 'the dashboard lists it');
        await page.goto(`${base}/admin/commerce/unmatched`);
        assert.match(await text(page), new RegExp(payer));
        await page.goto(`${base}${item}`);
        await page.locator('#unmatched-returned-on').fill(today());
        await page.locator('#unmatched-return-reference').fill(`FNB-BACK-${Date.now()}`);
        await submit(page, page.getByRole('button', {name: 'Record that it was paid back'}));
        assert.match(await page.locator('[data-unmatched-outcome]').innerText(), /^Paid back to the sender on /);
        await page.goto(`${base}/admin/commerce/unmatched`);
        assert.doesNotMatch(await text(page), new RegExp(payer), 'it has left the list');
        await page.goto(`${base}/admin/dashboard`);
        assert.equal(await page.locator(`a[href="${item}"]`).count(), 0, 'and the dashboard');
        await admin.ctx.close();
    });

    /* Every new page in every theme at every width. Learners cannot preview a theme, so each theme is
     * activated in turn and the active theme is restored afterwards. */
    const learnerPages = ['/account/wallet', '/account/dashboard', `/account/orders/${sample?.order}`, '/refunds', '/terms', '/privacy'];
    const adminPages = ['/admin/dashboard', '/admin/settings', '/admin/commerce/refund-requests', '/admin/wallets', `/admin/wallets/person/${sample?.learner.id}`, `/admin/commerce/orders/${sample?.order}`,
        '/admin/commerce/orders', `/admin/commerce/ledger?order=${sample?.order}`, '/admin/commerce/trail', '/admin/commerce/payment-events', '/admin/commerce/eft', '/admin/commerce/unmatched'];
    try {
        for (const key of themes) {
            php('theme', key);
            for (const width of widths) {
                for (const [token, pages] of [[sample?.learner.token, learnerPages], [fixture.admin_token, adminPages]]) {
                    const {ctx, page} = await open(chromium, token, {width});
                    for (const route of pages) {
                        await check(`${key} ${width} ${route}`, async () => {
                            const response = await page.goto(`${base}${route}`, {waitUntil: 'networkidle'});
                            assert.equal(response.status(), 200);
                            const facts = await page.evaluate((k) => ({
                                served: [...document.querySelectorAll('link[rel=stylesheet]')].some((l) => l.href.includes(`/${k}/`)),
                                h1: document.querySelectorAll('h1').length,
                                sideways: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                            }), key);
                            assert.equal(facts.served, true, 'drawn in that theme');
                            assert.equal(facts.h1, 1, 'one h1');
                            assert.ok(facts.sideways <= 1, `no sideways scrolling (${facts.sideways}px)`);
                            if (width === 390) await page.screenshot({path: path.join(output, `${key}-${route.replace(/[^a-z0-9]+/g, '-')}-390.png`), fullPage: true});
                        });
                    }
                    await ctx.close();
                }
            }
        }
    } finally {
        php('restore');
    }

    for (const session of Object.values(admin)) await session.ctx.close();
    await chromium.close();
    await firefox.close();
    php('cleanup');
    console.log(results.length + failures.length + ' checks, ' + failures.length + ' failures.');
    for (const failure of failures) console.log('FAIL ' + failure);
    process.exit(failures.length ? 1 : 0);
})().catch((error) => {
    console.error(error);
    try { php('cleanup'); } catch (ignored) {}
    process.exit(1);
});
