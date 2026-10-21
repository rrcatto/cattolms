/* The public legal pages and the checkout acceptance in a real browser.
 *
 * In every bundled theme, signed out and signed in, at 1440, 820 and 390px: /terms, /refunds and
 * /privacy answer in that theme with their title and last-updated date (no version), a contents list
 * whose every entry is a card on the page, all prose on cards, no value overlapping its label and
 * no sideways scrolling; and the Core footer lists Terms, Refunds and Privacy. In every theme at
 * the three widths the learner's checkout review and the company credit checkout show the one
 * acceptance box: required, with links to the Terms and Conditions and the Refund Policy inside
 * its label, whole and inside the form.
 *
 * Then, in Chromium and Firefox: Place my order without the tick places nothing; a forged form
 * without it is refused by the server with the reason, for the learner and for the company; the
 * Terms link opens the page in a new tab and leaves the checkout as it was; and the details step
 * has no identity number field. Without JavaScript the pages read, the footer link leads to the
 * Terms and the contents list jumps to a section.
 *
 * Each theme is activated in turn (legal-pages-fixture.php `theme`) so signed-out requests are
 * drawn in it; the theme active before the run is restored even when a check fails. Do not run it
 * beside another theme-switching check. The script creates and cleans up its own fixture; no order
 * is placed, so cleanup removes everything. If the run is killed, run the fixture's `cleanup`. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-legal-pages';
const browsers = (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',').filter(Boolean);
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/legal-pages-fixture.php ${action} 2>/dev/null`], {encoding: 'utf8'});
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme);
const WIDTHS = [1440, 820, 390];
const DOCUMENTS = [['/terms', 'terms', 'Terms and Conditions'], ['/refunds', 'refunds', 'Refund Policy'], ['/privacy', 'privacy', 'Privacy Policy']];
const REFUSED = 'Accept the Terms and Conditions and the Refund Policy before placing';
const results = [], failures = [];
let state;

async function check(name, run, page = null) {
    try {
        await run();
        results.push(name);
        console.log('PASS', name);
    } catch (error) {
        failures.push(`${name}: ${error.message.split('\n')[0]}`);
        console.error('FAIL', name, error.message.split('\n')[0]);
        if (page) await page.screenshot({path: path.join(output, name.replace(/[^a-z0-9]+/gi, '-') + '-failure.png'), fullPage: true}).catch(() => {});
    }
}
async function open(browser, token = null, options = {}) {
    // Without JavaScript the themes' smooth scrolling never settles under Playwright; reduced motion turns it off.
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1440, height: 1000}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    return {ctx, page};
}
const sideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const served = (page, theme) => page.locator(`link[href*="/${theme.slug}-v${theme.version}/"]`).count();
async function fill(page, prefix, values) {
    for (const [field, value] of Object.entries(values)) await page.locator(`#${prefix}-${field.replaceAll('_', '-')}`).fill(value);
}
const person = {billing_name: 'Lerato Dlamini', address_line_1: '12 Kerk Street', locality: 'Brooklyn', city: 'Pretoria', region: 'Gauteng', postal_code: '0181', country_code: 'ZA'};
const company = {billing_name: 'Legal Browser (Pty) Ltd', address_line_1: '1 Harbour Road', locality: 'Foreshore', city: 'Cape Town', region: 'Western Cape', postal_code: '8001', country_code: 'ZA'};

/* The learner's cart at the review step; the steps are kept in this browser's session. */
async function toReview(page) {
    await page.goto(`${base}/courses/${state.slug}`);
    await Promise.all([page.waitForURL(/\/checkout/), page.locator('form[action="/cart/add"] button[value="buy_now"]').first().click()]);
    assert.equal(await page.locator('[name=identification_number]').count(), 0, 'the details step has no identity number field');
    assert.equal(await page.getByText('ID number').count(), 0, 'and does not mention one');
    await page.locator('#first-name').fill('Lerato');
    await page.locator('#last-name').fill('Dlamini');
    await page.locator('#mobile-number').fill('0821234567');
    await fill(page, 'checkout-billing', person);
    await Promise.all([page.waitForURL(/\/checkout\/payment/), page.getByRole('button', {name: 'Continue to payment'}).click()]);
    await page.locator('#payment-method').selectOption('eft');
    await Promise.all([page.waitForURL(/\/checkout\/review/), page.getByRole('button', {name: 'Review my order'}).click()]);
}
/* The company's basket at its checkout; the basket is kept in this browser's session. */
async function toCompanyCheckout(page) {
    await page.goto(`${base}/company/credits/buy?search=${encodeURIComponent(state.course_title)}`);
    const offer = page.locator(`form[action="/company/credits/buy/add"]:has(input[name=variant_id][value="${state.variant}"])`);
    await offer.locator('input[name=quantity]').fill('2');
    await Promise.all([page.waitForNavigation(), offer.locator('button[type=submit]').click()]);
    await page.goto(`${base}/company/credits/checkout`);
    assert.equal(new URL(page.url()).pathname, '/company/credits/checkout');
}

/* One legal page as drawn: its facts, measured in the page. Each numbered section is a closed accordion (owner,
   2026-10-20): the count is taken closed, then every section is opened so its text can be measured. */
const documentFacts = (page) => page.evaluate(() => {
    const vw = document.documentElement.clientWidth;
    const stamp = document.querySelector('[data-legal-document]');
    const sections = [...document.querySelectorAll('main details.cl-ui-accordion-section[id]')];
    const closed = sections.filter((section) => !section.open).length;
    sections.forEach((section) => { section.open = true; });
    const contents = sections.map((section) => section.id);
    const inside = (e) => { const b = e.getBoundingClientRect(); return b.left >= -1 && b.right <= vw + 1; };
    const footer = document.querySelector('footer.cl-footer');
    return {
        h1: document.querySelector('h1')?.textContent.trim(),
        key: stamp?.dataset.legalDocument, version: stamp?.dataset.legalVersion, stamp: stamp?.textContent.replace(/\s+/g, ' ').trim(),
        contents: contents.length,
        closed: closed === contents.length,
        missing: contents.filter((id) => !document.querySelector(`.cl-ui-surface details#${CSS.escape(id)} > summary .cl-ui-accordion-eyebrow`)?.textContent.match(/^Section \d+$/) || !document.querySelector(`details#${CSS.escape(id)} > summary .cl-ui-accordion-title`)),
        pdf: document.querySelector('main a[href$=".pdf"]')?.getAttribute('href') ?? null,
        // Nothing of the document is written straight onto the page canvas (UX rule 1.8).
        loose: [...document.querySelectorAll('main .cl-page-section :is(p, ul, ol, dl, h2)')].filter((e) => !e.closest('.cl-ui-surface')).length,
        outside: [...document.querySelectorAll('main .cl-page-section :is(.cl-ui-surface, .cl-ui-notice, dl, ul, ol, p)')].filter((e) => !inside(e)).length,
        // A label that runs into its value: the label's text reaches past where the value begins.
        overlapping: [...document.querySelectorAll('main .cl-ui-key-value-list dt')].filter((dt) => dt.nextElementSibling && dt.getBoundingClientRect().left + dt.scrollWidth > dt.nextElementSibling.getBoundingClientRect().left + 1).map((dt) => dt.textContent.trim()),
        clipped: [...document.querySelectorAll('main .cl-ui-key-value-list dd')].filter((dd) => dd.scrollWidth > dd.clientWidth + 1).map((dd) => dd.textContent.trim()),
        overflow: document.documentElement.scrollWidth - vw,
        footer: ['/terms', '/refunds', '/privacy'].map((href) => {
            const a = footer?.querySelector(`a[href="${href}"]`);
            if (!a) return null;
            const b = a.getBoundingClientRect();
            return {text: a.textContent.trim(), shown: b.width > 0 && b.height > 0 && getComputedStyle(a).visibility !== 'hidden', inside: inside(a)};
        }),
    };
});
/* The acceptance box of a checkout form as drawn. */
const acceptanceFacts = (page, action) => page.evaluate((formAction) => {
    const vw = document.documentElement.clientWidth;
    const form = document.querySelector(`form[action="${formAction}"]`);
    const box = form?.querySelector('input[type=checkbox][name=accept_terms]');
    const label = box?.closest('label');
    const rect = (e) => e.getBoundingClientRect();
    const links = [...(label?.querySelectorAll('a') ?? [])].map((a) => ({href: a.getAttribute('href'), text: a.textContent.trim(), target: a.target, rel: a.rel,
        shown: rect(a).width > 0 && rect(a).height > 0, inside: rect(a).left >= -1 && rect(a).right <= vw + 1}));
    const submit = form?.querySelector('button[type=submit]');
    return {
        form: !!form, box: !!box, required: !!box?.required, value: box?.value, checked: !!box?.checked,
        text: label?.textContent.replace(/\s+/g, ' ').trim(), links,
        boxShown: !!box && rect(box).width > 0 && rect(box).height > 0,
        labelInside: !!label && rect(label).left >= rect(form).left - 1 && rect(label).right <= rect(form).right + 1 && rect(label).right <= vw + 1,
        beforeSubmit: !!box && !!submit && rect(box).top < rect(submit).top,
        submitShown: !!submit && rect(submit).width > 0,
        overflow: document.documentElement.scrollWidth - vw,
    };
}, action);
function assertAcceptance(facts, label) {
    assert.ok(facts.form && facts.box, `${label}: the acceptance box is in the order form`);
    assert.ok(facts.required && facts.value === 'yes' && !facts.checked, `${label}: it is required and starts unticked`);
    assert.equal(facts.text, 'I have read and accept the Terms and Conditions and the Refund Policy.', `${label}: its wording names both documents`);
    assert.deepEqual(facts.links.map((l) => [l.href, l.text, l.target]), [['/terms', 'Terms and Conditions', '_blank'], ['/refunds', 'Refund Policy', '_blank']], `${label}: both are linked from the label`);
    assert.ok(facts.links.every((l) => l.shown && l.inside && l.rel.includes('noopener')), `${label}: the links are whole and on screen`);
    assert.ok(facts.boxShown && facts.labelInside, `${label}: the box and its label fit inside the form`);
    assert.ok(facts.beforeSubmit && facts.submitShown, `${label}: it comes before the button that places the order`);
    assert.ok(facts.overflow <= 1, `${label}: the page does not scroll sideways (${facts.overflow})`);
}

(async () => {
    let created = false;
    const launched = {};
    try {
        state = JSON.parse(php('create').trim().split('\n').pop());
        created = true;
        for (const name of browsers) launched[name] = await playwright[name].launch({headless: true});
        const chromium = launched.chromium || Object.values(launched)[0];

        /* 1. Every theme: the three pages and the footer, signed out and signed in; both checkout boxes. */
        const anonymous = await open(chromium);
        const learner = await open(chromium, state.learner_token);
        const buyer = await open(chromium, state.company_admin_token);
        await toReview(learner.page);
        await toCompanyCheckout(buyer.page);
        for (const theme of themes) {
            php(`theme ${theme.slug}-v${theme.version}`);
            for (const [who, session] of [['signed-out', anonymous], ['signed-in', learner]]) {
                for (const width of WIDTHS) {
                    await check(`${theme.slug} ${who} ${width}: the three pages and the footer`, async () => {
                        await session.page.setViewportSize({width, height: 1000});
                        for (const [url, key, title] of DOCUMENTS) {
                            const response = await session.page.goto(base + url);
                            assert.equal(response.status(), 200, url);
                            assert.equal(new URL(session.page.url()).pathname, url, `${url} is public: no redirect to sign in`);
                            await session.page.evaluate(() => document.fonts.ready);
                            assert.ok(await served(session.page, theme), `${url} is drawn in ${theme.slug}`);
                            const facts = await documentFacts(session.page);
                            assert.equal(facts.h1, title, url);
                            assert.equal(facts.key, key, `${url} names its document`);
                            assert.equal(facts.version, undefined, `${url} carries no version (owner, 2026-10-17)`);
                            assert.match(facts.stamp, /^Last updated \d{1,2} [A-Z][a-z]+ \d{4}\.$/, `${url} shows its date`);
                            assert.ok(facts.contents >= 8 && facts.missing.length === 0, `${url}: every numbered section is an accordion with its number and title (missing: ${facts.missing.join(', ')})`);
                            assert.ok(facts.closed, `${url}: every section starts closed`);
                            assert.equal(facts.pdf, `${url}.pdf`, `${url} links to its PDF`);
                            assert.equal(facts.loose, 0, `${url}: all prose is on a card`);
                            assert.equal(facts.outside, 0, `${url}: every card and its text is on screen`);
                            assert.deepEqual(facts.overlapping, [], `${url}: no label runs into its value`);
                            assert.deepEqual(facts.clipped, [], `${url}: no value is clipped`);
                            assert.ok(facts.overflow <= 1, `${url} does not scroll sideways (${facts.overflow})`);
                            assert.deepEqual(facts.footer.map((l) => l && l.text), ['Terms', 'Refunds', 'Privacy'], `${url}: the footer lists the three documents`);
                            assert.ok(facts.footer.every((l) => l.shown && l.inside), `${url}: the footer links are shown and on screen`);
                            const palette = session.page.locator('.cl-palette-hide');
                            if (await palette.isVisible()) await palette.click();
                            await session.page.screenshot({path: path.join(output, `${theme.slug}-${who}-${width}-${key}.png`), fullPage: true});
                        }
                        const name = await session.page.getByText(state.learner_name).count();
                        assert.equal(name > 0, who === 'signed-in', `${who}: the page is drawn for that visitor`);
                    }, session.page);
                }
            }
            for (const [who, session, url, action] of [['learner checkout', learner, '/checkout/review', '/checkout/place'], ['company checkout', buyer, '/company/credits/checkout', '/company/credits/checkout']]) {
                for (const width of WIDTHS) {
                    await check(`${theme.slug} ${who} ${width}: the acceptance box`, async () => {
                        await session.page.setViewportSize({width, height: 1000});
                        const response = await session.page.goto(base + url);
                        assert.equal(response.status(), 200);
                        assert.equal(new URL(session.page.url()).pathname, url);
                        await session.page.evaluate(() => document.fonts.ready);
                        assert.ok(await served(session.page, theme), `drawn in ${theme.slug}`);
                        assertAcceptance(await acceptanceFacts(session.page, action), `${theme.slug} ${who} ${width}`);
                        await session.page.locator('input[name=accept_terms]').scrollIntoViewIfNeeded();
                        await session.page.screenshot({path: path.join(output, `${theme.slug}-${who.replace(' ', '-')}-${width}.png`)});
                    }, session.page);
                }
            }
        }
        php('restore');
        for (const session of [anonymous, learner, buyer]) await session.ctx.close();

        /* 2. Each browser: nothing is placed without the tick, and the server says why. */
        for (const [name, browser] of Object.entries(launched)) {
            await check(`${name}: an order is not placed without accepting, and the Terms open beside the checkout`, async () => {
                const {ctx, page} = await open(browser, state.learner_token, {width: 1280});
                await toReview(page);
                const submit = page.getByRole('button', {name: 'Place my order'});
                await submit.click();
                await page.waitForTimeout(400);
                assert.equal(new URL(page.url()).pathname, '/checkout/review', 'the browser keeps the form until the box is ticked');
                assert.equal(await page.locator('input[name=accept_terms]').evaluate((box) => box.validity.valueMissing), true);
                assert.equal(Number(php('orders').trim()), 0, 'no order was placed');

                const [terms] = await Promise.all([ctx.waitForEvent('page'), page.locator('label:has(input[name=accept_terms]) a[href="/terms"]').click()]);
                await terms.waitForLoadState('domcontentloaded');
                assert.equal(new URL(terms.url()).pathname, '/terms', 'the link opens the Terms in a new tab');
                assert.equal(await terms.locator('h1').innerText(), 'Terms and Conditions');
                await terms.close();
                assert.equal(new URL(page.url()).pathname, '/checkout/review', 'and the checkout stays where it was');

                // A form that lost its required attribute still cannot place the order (the start box is ticked).
                await page.locator('input[name=accept_start]').check();
                await page.locator('input[name=accept_terms]').evaluate((box) => box.removeAttribute('required'));
                await Promise.all([page.waitForNavigation(), submit.click()]);
                assert.equal(new URL(page.url()).pathname, '/checkout/review');
                assert.ok(await page.getByText(REFUSED + ' your order.').first().isVisible(), 'the server refuses and says why');
                assert.equal(Number(php('orders').trim()), 0, 'still no order');
                await ctx.close();
            });
            await check(`${name}: a company order is not placed without accepting`, async () => {
                const {ctx, page} = await open(browser, state.company_admin_token, {width: 1280});
                await toCompanyCheckout(page);
                await fill(page, 'company-checkout-billing', company);
                await page.locator('#payment-method').selectOption('dummy');
                const submit = page.getByRole('button', {name: 'Place company order'});
                await submit.click();
                await page.waitForTimeout(400);
                assert.equal(await page.locator('input[name=accept_terms]').evaluate((box) => box.validity.valueMissing), true, 'the browser keeps the form until the box is ticked');
                await page.locator('input[name=accept_terms]').evaluate((box) => box.removeAttribute('required'));
                await Promise.all([page.waitForNavigation(), submit.click()]);
                assert.equal(new URL(page.url()).pathname, '/company/credits/checkout');
                assert.ok(await page.getByText(REFUSED + ' the company order.').first().isVisible(), 'the server refuses and says why');
                assert.equal(Number(php('orders').trim()), 0, 'no company order was placed');
                await ctx.close();
            });
        }

        /* 3. Without JavaScript: the pages read and their links work as ordinary links. */
        await check('chromium without JavaScript: the pages, the footer link and a section opened', async () => {
            const {ctx, page} = await open(chromium, null, {js: false, width: 390});
            for (const [url, , title] of DOCUMENTS) {
                assert.equal((await page.goto(base + url)).status(), 200, url);
                assert.equal(await page.locator('h1').innerText(), title);
                assert.ok(await sideScroll(page) <= 1, `${url} fits a phone`);
            }
            await page.goto(`${base}/help`);
            await Promise.all([page.waitForURL(/\/terms$/), page.locator('footer.cl-footer a[href="/terms"]').click()]);
            // Each section is a closed accordion (owner, 2026-10-20) that opens without JavaScript too.
            assert.equal(await page.locator('details.cl-ui-accordion-section[open]').count(), 0, 'every section starts closed');
            await page.locator('details#refunds > summary').click();
            assert.equal(await page.locator('details#refunds').evaluate((section) => section.open), true, 'a section opens when it is chosen');
            await Promise.all([page.waitForURL(/\/refunds$/), page.locator('#refunds a[href="/refunds"]').click()]);
            assert.equal(await page.locator('h1').innerText(), 'Refund Policy');
            await ctx.close();
        });
    } catch (error) {
        failures.push(`run: ${error.message.split('\n')[0]}`);
        console.error(error);
    } finally {
        // cleanup puts back the theme that was active before the run, then removes the fixture.
        if (created) { try { php('cleanup'); } catch (error) { failures.push(`cleanup: ${error.message.split('\n')[0]}`); } }
        for (const browser of Object.values(launched)) await browser.close();
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({results, failures}, null, 2));
    }
    console.log(JSON.stringify({checks: results.length + failures.length, passed: results.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
