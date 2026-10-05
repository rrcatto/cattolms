/* Billing profiles in a real browser, in Chromium and Firefox and without JavaScript, then in every
 * bundled theme: a learner and a company administrator save billing details that survive a reload;
 * the learner's checkout is filled from them, a correction there is saved, the order shows the
 * billing it was placed with and keeps it after the profile changes, and the invoice PDF opens; the
 * company credit checkout is filled from the company's details and its order shows them; the forms
 * work without JavaScript and fit a phone; and the account and company billing forms render in all
 * five themes without overflow.
 *
 * Run against the development instance with billing-profiles-fixture.php. Placing orders leaves
 * them behind: orders are immutable. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-billing-profiles-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-billing-profiles';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/billing-profiles-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const reset = () => JSON.parse(php('reset').trim().split('\n').pop());
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${error.message.split('\n')[0]}`);
    }
}
async function open(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: 1400}, javaScriptEnabled: options.js !== false});
    await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    return {ctx, page};
}
/* The "Billed to" block's printed lines: one per line break. */
const billedTo = async (page) => (await page.locator('address').first().innerText()).split('\n').map((line) => line.trim()).filter(Boolean);
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
async function fill(page, prefix, values) {
    for (const [field, value] of Object.entries(values)) await page.locator(`#${prefix}-${field.replaceAll('_', '-')}`).fill(value);
}
async function values(page, prefix, fields) {
    const out = {};
    for (const field of fields) out[field] = await page.locator(`#${prefix}-${field.replaceAll('_', '-')}`).inputValue();
    return out;
}
const personA = {billing_name: 'Lerato Dlamini', organisation_name: 'Dlamini & Associates', tax_registration_number: '4000000001', address_line_1: '12 Kéré Street', address_line_2: 'Suite 4', locality: 'Brooklyn', city: 'Pretoria', region: 'Gauteng', postal_code: '0181', country_code: 'za'};
const companyA = {billing_name: 'Billing Browser (Pty) Ltd', tax_registration_number: '4999999999', address_line_1: '1 Harbour Road', address_line_2: '', locality: 'Foreshore', city: 'Cape Town', region: 'Western Cape', postal_code: '8001', country_code: 'ZA'};

async function saveAccountBilling(page, data) {
    await page.goto(`${base}/account/profile`);
    await fill(page, 'account-billing', data);
    await page.locator('#billing button[type=submit]').click();
    await page.waitForURL(/\/account\/profile/);
}
async function saveCompanyBilling(page, data) {
    await page.goto(`${base}/company/billing`);
    await fill(page, 'company-billing', data);
    await page.locator('form[action="/company/billing"] button[type=submit]').click();
    await page.waitForURL(/\/company\/billing/);
}

(async () => {
    let state = reset();
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();

        await scenario('a learner’s billing details save and survive a reload', browserName, async () => {
            state = reset();
            const {ctx, page} = await open(browser, state.learner_token);
            await page.goto(`${base}/account/profile`);
            assert.ok(await page.getByText('No billing details saved yet').isVisible(), 'a new profile says so');
            assert.equal(await page.locator('#account-billing-billing-name').inputValue(), 'Lerato Dlamini', 'it starts from the person’s name');
            await saveAccountBilling(page, personA);
            assert.ok(await page.getByText('Your billing details were saved.').isVisible());
            await page.reload();
            const saved = await values(page, 'account-billing', Object.keys(personA));
            assert.deepEqual(saved, {...personA, country_code: 'ZA'}, 'saved values survive a reload, the country upper-cased');
            assert.equal(await page.getByText('No billing details saved yet').count(), 0);
            await ctx.close();
        });

        await scenario('a company’s billing details save and survive a reload', browserName, async () => {
            state = reset();
            const {ctx, page} = await open(browser, state.company_admin_token);
            await page.goto(`${base}/company/billing`);
            assert.equal(await page.locator('#company-billing-billing-name').inputValue(), state.company_name, 'it starts from the company name');
            assert.equal(await page.locator('#company-billing-organisation-name').count(), 0, 'a company has no organisation field');
            await saveCompanyBilling(page, companyA);
            assert.ok(await page.getByText('The company billing details were saved.').isVisible());
            await page.reload();
            assert.deepEqual(await values(page, 'company-billing', Object.keys(companyA)), companyA);
            assert.ok(await page.locator('nav a[href="/company/billing"]').count() > 0, 'Billing details is in the company navigation');
            await ctx.close();
        });

        await scenario('the billing forms fit a phone', browserName, async () => {
            for (const [token, url] of [[state.learner_token, '/account/profile'], [state.company_admin_token, '/company/billing']]) {
                const {ctx, page} = await open(browser, token, {width: 390});
                await page.goto(`${base}${url}`);
                assert.ok(await noSideScroll(page), `no sideways scroll at 390px on ${url}`);
                await page.screenshot({path: `${output}/${browserName}-390${url.replaceAll('/', '_')}.png`, fullPage: true});
                await ctx.close();
            }
        });
        await browser.close();
    }

    const chromium = await playwright.chromium.launch();
    await scenario('checkout is filled from the profile, a correction is saved, and the order keeps the billing it was placed with', 'chromium', async () => {
        state = reset();
        const {ctx, page} = await open(chromium, state.learner_token);
        await saveAccountBilling(page, personA);
        await page.goto(`${base}/courses/${state.slug}`);
        await page.locator('form[action="/cart/add"] button[value="buy_now"]').click();
        await page.waitForURL(/\/checkout/);
        assert.deepEqual(await values(page, 'checkout-billing', ['billing_name', 'address_line_1', 'city', 'country_code']), {billing_name: 'Lerato Dlamini', address_line_1: '12 Kéré Street', city: 'Pretoria', country_code: 'ZA'}, 'checkout is filled from the saved profile');
        await page.locator('#mobile-number').fill('0821234567');
        await page.locator('#rsa-id').fill('9001015009087');
        await page.locator('#checkout-billing-address-line-1').fill('14 Corrected Avenue');
        await page.getByRole('button', {name: 'Continue to payment'}).click();
        await page.waitForURL(/\/checkout\/payment/);
        await page.locator('#payment-method').selectOption('dummy');
        await page.locator('#method-token').selectOption('demo_success');
        await page.getByRole('button', {name: 'Review my order'}).click();
        await page.waitForURL(/\/checkout\/review/);
        assert.ok((await billedTo(page)).includes('14 Corrected Avenue'), 'the review shows the corrected billing');
        await page.locator('input[name=accept_terms]').check();
        await page.getByRole('button', {name: 'Place my order'}).click();
        await page.waitForURL(/\/account\/orders\/\d+/);
        const orderUrl = page.url();
        assert.deepEqual(await billedTo(page), ['Lerato Dlamini', 'Dlamini & Associates', '14 Corrected Avenue', 'Suite 4', 'Brooklyn', 'Pretoria 0181', 'Gauteng', 'South Africa', 'Tax/VAT number: 4000000001'], 'the order shows the billing it was placed with');
        await page.goto(`${base}/account/profile`);
        assert.equal(await page.locator('#account-billing-address-line-1').inputValue(), '14 Corrected Avenue', 'the checkout correction was saved to the profile');
        await saveAccountBilling(page, {...personA, billing_name: 'Lerato Mokoena', address_line_1: '99 Changed Road'});
        await page.goto(orderUrl);
        const after = await billedTo(page);
        assert.ok(after.includes('14 Corrected Avenue') && after.includes('Lerato Dlamini'), 'after the profile changes the order still shows the billing it was placed with');
        assert.ok(!after.includes('99 Changed Road') && !after.includes('Lerato Mokoena'), 'and not the new profile');
        const invoiceHref = await page.locator('a[href*="/documents/"]').first().getAttribute('href');
        const pdf = await ctx.request.get(`${base}${invoiceHref}`);
        assert.equal(pdf.status(), 200);
        assert.match(pdf.headers()['content-type'], /application\/pdf/);
        assert.equal((await pdf.body()).subarray(0, 5).toString(), '%PDF-');
        await page.screenshot({path: `${output}/chromium-order.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('company credit checkout is filled from the company’s details and its order is billed to the company', 'chromium', async () => {
        state = reset();
        const {ctx, page} = await open(chromium, state.company_admin_token);
        await saveCompanyBilling(page, companyA);
        await page.goto(`${base}/company/credits/buy?search=${encodeURIComponent(state.course_title)}`);
        const offer = page.locator(`form[action="/company/credits/buy/add"]:has(input[name=variant_id][value="${state.variant}"])`);
        await offer.locator('input[name=quantity]').fill('2');
        await offer.locator('button[type=submit]').click();
        await page.waitForURL(/\/company\/credits\/buy/);
        await page.goto(`${base}/company/credits/checkout`);
        assert.deepEqual(await values(page, 'company-checkout-billing', ['billing_name', 'address_line_1', 'city']), {billing_name: companyA.billing_name, address_line_1: '1 Harbour Road', city: 'Cape Town'}, 'checkout is filled from the company profile');
        assert.equal(await page.locator('textarea[name=billing_address]').count(), 0, 'no one-off address box');
        await page.locator('#company-checkout-billing-address-line-1').fill('2 Corrected Quay');
        await page.locator('#payment-method').selectOption('dummy');
        await page.locator('#method-token').selectOption('demo_success');
        await page.locator('input[name=accept_terms]').check();
        await page.getByRole('button', {name: 'Place company order'}).click();
        await page.waitForURL(/\/account\/orders\/\d+/);
        assert.deepEqual(await billedTo(page), [companyA.billing_name, '2 Corrected Quay', 'Foreshore', 'Cape Town 8001', 'Western Cape', 'South Africa', 'Tax/VAT number: 4999999999'], 'the company order is billed to the company as placed');
        await page.goto(`${base}/company/billing`);
        assert.equal(await page.locator('#company-billing-address-line-1').inputValue(), '2 Corrected Quay', 'the correction updated the company’s billing details');
        await ctx.close();
    });

    await scenario('the billing forms work without JavaScript', 'chromium-nojs', async () => {
        state = reset();
        for (const [token, prefix, url, data, selector] of [
            [state.learner_token, 'account-billing', '/account/profile', personA, '#billing button[type=submit]'],
            [state.company_admin_token, 'company-billing', '/company/billing', companyA, 'form[action="/company/billing"] button[type=submit]'],
        ]) {
            const {ctx, page} = await open(chromium, token, {js: false});
            await page.goto(`${base}${url}`);
            await fill(page, prefix, data);
            await Promise.all([page.waitForNavigation(), page.locator(selector).click()]);
            await page.goto(`${base}${url}`);
            assert.equal(await page.locator(`#${prefix}-address-line-1`).inputValue(), data.address_line_1, `${url} saves as an ordinary form`);
            await ctx.close();
        }
    });

    await scenario('the account and company billing forms render in every theme without overflow', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.admin_token);
        await page.goto(`${base}/company`);
        const csrf = await page.locator('input[name=csrf]').first().inputValue();
        const selected = await ctx.request.post(`${base}/company/context`, {form: {csrf, company_id: String(state.company)}, maxRedirects: 0});
        assert.ok([200, 302, 303].includes(selected.status()), 'ADMIN selects the fixture company');
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 1200});
                for (const url of ['/account/profile', '/company/billing']) {
                    const response = await page.goto(`${base}${url}?theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    const form = page.locator(url === '/account/profile' ? '#billing form' : 'form[action="/company/billing"]');
                    assert.equal(await form.count(), 1, `${theme} ${width} ${url} has the billing form`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${url} does not scroll sideways`);
                    const overflow = await form.evaluate((el) => [...el.querySelectorAll('input')].some((input) => input.getBoundingClientRect().right > el.getBoundingClientRect().right + 1));
                    assert.equal(overflow, false, `${theme} ${width} ${url} keeps its fields inside the form`);
                }
            }
        }
        await ctx.close();
    });
    await chromium.close();

    console.log(results.map((r) => `PASS ${r}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
