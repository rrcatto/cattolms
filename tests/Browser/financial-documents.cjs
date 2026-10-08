/* Invoices, receipts and credit notes on the document template engine, in a real browser.
 * ADMIN opens Commerce → Financial Documents: the preview above the form is the chosen document
 * (invoice, receipt or credit note) for a made-up order (a learner's with a promotion and a bundle,
 * a company's credit purchase, an order a promotion made free), and it follows the form. The learner
 * downloads the invoice, receipt and credit note of their own order (promotion, bundle, refund), and
 * the invoice of an order a promotion made free, which has no receipt. ADMIN saves a new design:
 * the documents already issued download exactly as before and name their design version on the
 * order page, while an order placed afterwards is drawn in the new design. ADMIN order pages serve
 * the EFT receipt (bank reference and date) and the company's invoice. The form previews and saves
 * without JavaScript and by keyboard; a learner cannot open the design page or another person's
 * documents; the pages fit every theme at desktop and phone widths; Firefox repeats the preview and
 * a download.
 *
 * Previews are PDFs drawn into a frame, read with Ghostscript (gs, on the host). Headless Chromium
 * hands a PDF frame to a download whose body it cannot give back, so in Chromium every preview
 * request is routed through the check, which keeps the body; Firefox gives the body back.
 *
 * Run against the development instance with financial-documents-fixture.php. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-financial-documents-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-financial-documents';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];
const adminTokens = [...state.tokens.admin];
const admin = () => adminTokens.shift();
const PAGE = '/admin/commerce/documents';
const NOTE = `Browser invoice note ${state.suffix}`;
const doc = (order, kind) => state.documents[order][kind][0];
let issued = {};

let step = '';
async function scenario(name, browserName, run) {
    step = '';
    try {
        await run();
        results.push(`${browserName}: ${name}`);
        console.log(`PASS ${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${step ? '[' + step + '] ' : ''}${error.message.split('\n')[0]}`);
        console.log(`FAIL ${browserName}: ${name}: ${step ? '[' + step + '] ' : ''}${error.message.split('\n')[0]}`);
    }
}
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let pdfCount = 0;
/* The text a PDF prints, read with Ghostscript, whitespace collapsed. */
function pdfText(body) {
    const file = path.join(output, `pdf-${++pdfCount}.pdf`);
    fs.writeFileSync(file, body);
    return execFileSync('gs', ['-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=txtwrite', '-sOutputFile=-', file], {encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore']}).replace(/\s+/g, ' ');
}
async function open(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, acceptDownloads: true, viewport: {width: options.width || 1440, height: 1400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    const previews = [];
    if (browser.browserType().name() === 'firefox') {
        page.on('response', async (response) => {
            if (!new URL(response.url()).pathname.endsWith('/preview.pdf')) return;
            try {
                previews.push({status: response.status(), type: response.headers()['content-type'] || '', body: await response.body()});
            } catch (closed) { /* the page closed */ }
        });
    } else {
        await page.route((url) => url.pathname.endsWith('/preview.pdf'), async (route) => {
            try {
                const response = await route.fetch();
                const body = await response.body();
                previews.push({status: response.status(), type: response.headers()['content-type'] || '', body});
                await route.fulfill({response, body});
            } catch (closed) { /* the page closed */ }
        });
    }
    return {ctx, page, previews};
}
/* Does something, waits for the preview it causes and returns the preview's text. */
async function nextPreview(previews, action, label) {
    step = label;
    const before = previews.length;
    await action();
    const deadline = Date.now() + 20000;
    while (previews.length === before) {
        if (Date.now() > deadline) throw new Error(`${label}: no preview was drawn`);
        await sleep(100);
    }
    await sleep(1500); // the last of a burst of changes
    const last = previews[previews.length - 1];
    assert.equal(last.status, 200, `${label}: the preview answers 200`);
    assert.match(last.type, /^application\/pdf/, `${label}: the preview is a PDF`);
    return pdfText(last.body);
}
async function fetchPdf(page, href, label, status = 200) {
    step = label;
    const response = await page.request.get(new URL(href, base).href);
    assert.equal(response.status(), status, `${label} answers ${status}`);
    if (status !== 200) return '';
    assert.equal(response.headers()['content-type'], 'application/pdf', `${label} is served as a PDF`);
    assert.match(response.headers()['cache-control'] || '', /private/, `${label} is private`);
    assert.match(response.headers()['cache-control'] || '', /no-store/, `${label} is not stored by caches`);
    assert.equal(response.headers()['x-content-type-options'], 'nosniff', `${label} is nosniff`);
    const body = await response.body();
    assert.equal(body.subarray(0, 5).toString(), '%PDF-', `${label} is a PDF`);
    return pdfText(body);
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const submit = async (page, name) => {
    step = name;
    const button = page.getByRole('button', {name, exact: true});
    await button.focus();
    return Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.keyboard.press('Enter')]);
};
const flash = async (page, text) => assert.ok(await page.getByText(text).first().isVisible(), `the page says: ${text}`);
const contains = (text, words, label) => { for (const word of words) assert.ok(text.includes(word), `${label} shows ${word} (${text.slice(0, 200)})`); };
const lacks = (text, words, label) => { for (const word of words) assert.ok(!text.includes(word), `${label} does not show ${word}`); };
const accountDocument = (order, id) => `/account/orders/${state.orders[order]}/documents/${id}`;
const adminDocument = (order, id) => `/admin/commerce/orders/${state.orders[order]}/documents/${id}`;
const learnerTexts = {};

(async () => {
    const chromium = await playwright.chromium.launch();
    const firefox = await playwright.firefox.launch();

    await scenario('ADMIN previews each document for each sample order, and the preview follows the form', 'chromium', async () => {
        const {ctx, page, previews} = await open(chromium, admin());
        await page.goto(base + PAGE);
        step = 'page';
        assert.ok(await page.getByRole('heading', {name: 'Financial documents', level: 1}).isVisible());
        assert.ok(await page.locator(`a[href="${PAGE}"]`).count() > 0, 'the navigation leads to Financial Documents');
        const main = await page.locator('main').innerText();
        lacks(main, ['Published', 'Draft', 'Version', '{{', 'HTML', 'CSS', 'Margin', 'Letter'], 'the design page');
        let text = await nextPreview(previews, () => page.selectOption('#preview-document', 'receipt'), 'receipt');
        contains(text, ['Receipt', 'REC-00000398', 'Amount received', 'EFT / bank transfer', 'FNB-20261003-7741', 'Lerato Dlamini'], 'the learner receipt');
        text = await nextPreview(previews, () => page.selectOption('#preview-document', 'credit_note'), 'credit note');
        contains(text, ['Credit note', 'Credits invoice INV-00000412', 'Amount credited', 'Goodwill', 'Bundle: Workplace Safety Essentials'], 'the learner credit note');
        text = await nextPreview(previews, () => page.selectOption('#preview-sample', 'company'), 'company');
        contains(text, ['Mokoena Logistics', 'Purchased for Mokoena Logistics by Sipho Mokoena', '3 of 10 credits refunded'], 'the company credit note');
        text = await nextPreview(previews, async () => { await page.selectOption('#preview-document', 'invoice'); await page.selectOption('#preview-sample', 'free'); }, 'free');
        contains(text, ['WELCOME100', '100% off', 'Total R 0,00'], 'the free order invoice');
        lacks(text, ['Payment due', 'Paying by EFT'], 'the free order invoice');
        text = await nextPreview(previews, () => page.locator('#note-invoice').fill(NOTE), 'note');
        contains(text, [NOTE], 'the invoice preview');
        text = await nextPreview(previews, async () => { await page.selectOption('#document-typeface', 'plex-serif'); await page.locator('#document-colour').fill('#7a1f2b'); }, 'look');
        contains(text, ['Invoice', NOTE], 'the restyled invoice');
        await page.screenshot({path: `${output}/design-page.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('the learner downloads the invoice, receipt and credit note of their order, and only an invoice for a free order', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.learner[0]);
        await page.goto(`${base}/account/orders/${state.orders.paid}`);
        step = 'order page';
        for (const kind of ['invoice', 'receipt', 'credit note']) {
            assert.ok(await page.getByRole('link', {name: new RegExp(`^View ${kind} `)}).first().isVisible(), `the order page offers its ${kind}`);
        }
        const [course, bundle] = [state.courses.a.title, state.bundle.title];
        learnerTexts.invoice = await fetchPdf(page, accountDocument('paid', doc('paid', 'invoice')) + '?download=1', 'invoice');
        contains(learnerTexts.invoice, ['Invoice', course, `Bundle: ${bundle}`, `Includes: ${state.courses.b.title}`, state.courses.c.title, `Promotion ${state.codes[0]} (10% off)`, 'Ayanda Browser Zulu', 'Browser Attorneys Inc', 'Tax/VAT number: 4999999999', 'An invoice is not proof of payment.'], 'the invoice');
        learnerTexts.receipt = await fetchPdf(page, accountDocument('paid', doc('paid', 'receipt')), 'receipt');
        contains(learnerTexts.receipt, ['Receipt', 'Amount received', 'Card (simulated)', `Promotion ${state.codes[0]}`], 'the receipt');
        learnerTexts.credit = await fetchPdf(page, accountDocument('paid', doc('paid', 'credit_note')), 'credit note');
        contains(learnerTexts.credit, ['Credit note', 'Amount credited', 'R 1 080,00', 'Goodwill', `Discount from promotion ${state.codes[0]}`, 'learner asked for this course'], 'the credit note');
        assert.deepEqual(Object.keys(state.documents.zero), ['invoice'], 'a free order has no receipt');
        const free = await fetchPdf(page, accountDocument('zero', doc('zero', 'invoice')), 'free invoice');
        contains(free, ['Total R 0,00', `Promotion ${state.codes[1]} (100% off)`], 'the free invoice');
        lacks(free, ['Paying by EFT', 'Payment due'], 'the free invoice');
        await ctx.close();
    });

    await scenario('a new design draws documents issued afterwards and never the ones already issued', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(base + PAGE);
        await page.locator('#note-invoice').fill(NOTE);
        await submit(page, 'Save');
        await flash(page, 'The design was saved.');
        assert.equal(await page.locator('#note-invoice').inputValue(), NOTE, 'the saved note is shown');
        const learner = await open(chromium, state.tokens.learner[1]);
        for (const [kind, label] of [['invoice', 'invoice'], ['receipt', 'receipt'], ['credit_note', 'credit']]) {
            assert.equal(await fetchPdf(learner.page, accountDocument('paid', doc('paid', kind)), `${kind} again`), learnerTexts[label], `the ${kind} downloads exactly as before`);
        }
        await page.goto(`${base}/admin/commerce/orders/${state.orders.paid}`);
        step = 'ADMIN order page';
        const named = (await page.locator('main').innerText()).match(/drawn in the invoice design, version (\d+)\b/);
        assert.ok(named, 'the order page names the version that drew the invoice');
        issued = JSON.parse(execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `php tests/Browser/financial-documents-fixture.php order ${statePath} 2>/dev/null | tail -1`], {encoding: 'utf8'}).trim());
        const later = await fetchPdf(learner.page, `/account/orders/${issued.order}/documents/${issued.documents.invoice[0]}`, 'the later invoice');
        contains(later, [NOTE], 'an invoice issued after the save');
        await page.goto(`${base}/admin/commerce/orders/${issued.order}`);
        assert.ok((await page.locator('main').innerText()).includes(`drawn in the invoice design, version ${Number(named[1]) + 1}`), 'and names the new version');
        await learner.ctx.close();
        await ctx.close();
    });

    await scenario('ADMIN order pages serve the EFT receipt and the company invoice', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/commerce/orders/${state.orders.eft}`);
        step = 'EFT order';
        assert.match(await page.locator('main').innerText(), /Receipt REC-\d{8}/);
        const receipt = await fetchPdf(page, adminDocument('eft', doc('eft', 'receipt')) + '?download=1', 'EFT receipt');
        contains(receipt, ['EFT / bank transfer', state.bank_reference, 'Amount received', 'R 800,00'], 'the EFT receipt');
        const invoice = await fetchPdf(page, adminDocument('company', doc('company', 'invoice')), 'company invoice');
        contains(invoice, ['Browser Logistics (Pty) Ltd', `Purchased for Browser Logistics ${state.suffix} by Company Browser Admin`, '3 company credits at R 500,00 each'], 'the company invoice');
        lacks(invoice, ['Promotion', 'Subtotal'], 'the company invoice');
        await ctx.close();
    });

    await scenario('a learner cannot open the design page or another person’s documents', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.other);
        for (const url of [PAGE, `${PAGE}/invoice/preview.pdf`]) {
            assert.equal((await page.request.get(base + url)).status(), 403, url);
        }
        assert.notEqual((await page.request.get(base + accountDocument('paid', doc('paid', 'invoice')))).status(), 200, 'another person’s invoice is refused');
        assert.equal((await page.request.get(base + adminDocument('paid', doc('paid', 'invoice')))).status(), 403, 'the ADMIN document route is refused');
        await ctx.close();
    });

    await scenario('without JavaScript the form previews into its frame and saves', 'chromium-nojs', async () => {
        const {ctx, page, previews} = await open(chromium, admin(), {js: false});
        await page.goto(base + PAGE);
        await page.selectOption('#preview-document', 'credit_note');
        await page.locator('#note-credit_note').fill(`No-JS credit note ${state.suffix}`);
        const text = await nextPreview(previews, () => page.getByRole('button', {name: 'Update preview'}).click(), 'Update preview');
        contains(text, ['Credit note', `No-JS credit note ${state.suffix}`], 'the no-JS preview');
        await submit(page, 'Save');
        await flash(page, 'The design was saved.');
        assert.equal(await page.locator('#note-credit_note').inputValue(), `No-JS credit note ${state.suffix}`);
        assert.equal(await page.locator('#preview-document').inputValue(), 'credit_note', 'the chosen document stays chosen');
        await ctx.close();
    });

    await scenario('the design form is used by keyboard alone', 'chromium', async () => {
        const {ctx, page, previews} = await open(chromium, admin());
        await page.goto(base + PAGE);
        step = 'keyboard';
        await page.locator('#preview-document').focus();
        const text = await nextPreview(previews, async () => { await page.keyboard.press('ArrowDown'); }, 'ArrowDown');
        contains(text, ['Receipt'], 'the keyboard-chosen document');
        await page.locator('#note-receipt').focus();
        await page.keyboard.press('Control+A');
        await page.keyboard.type(`Keyboard receipt ${state.suffix}`);
        for (let i = 0; i < 40 && !(await page.evaluate(() => document.activeElement?.textContent?.trim() === 'Save')); i++) await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement?.textContent?.trim()), 'Save', 'Tab reaches Save');
        await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.keyboard.press('Enter')]);
        await flash(page, 'The design was saved.');
        assert.equal(await page.locator('#note-receipt').inputValue(), `Keyboard receipt ${state.suffix}`);
        await ctx.close();
    });

    await scenario('the design and order pages fit every theme at desktop and phone widths', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        const pages = [['design', PAGE, '.cl-document-preview-frame'], ['order', `/admin/commerce/orders/${state.orders.paid}`, 'main h1']];
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 1200});
                for (const [name, url, selector] of pages) {
                    step = `${theme} ${width} ${name}`;
                    const response = await page.goto(`${base}${url}?theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${name} does not scroll sideways`);
                    assert.ok(await page.locator(selector).first().isVisible(), `${theme} ${width} ${name} shows ${selector}`);
                    if (name === 'design') {
                        const [frame, form] = await Promise.all(['.cl-document-preview-frame', '.cl-document-design-form'].map((s) => page.locator(s).first().boundingBox()));
                        assert.ok(frame.y < form.y, `${theme} ${width}: the preview is above the form`);
                        assert.ok(Math.abs(frame.height / frame.width - 297 / 210) < 0.05, `${theme} ${width}: the preview is an A4 portrait page`);
                    }
                    await page.screenshot({path: `${output}/${theme}-${width}-${name}.png`});
                }
            }
        }
        await ctx.close();
    });
    await chromium.close();

    await scenario('the preview follows the form and the learner downloads a receipt', 'firefox', async () => {
        const {ctx, page, previews} = await open(firefox, admin());
        await page.goto(base + PAGE);
        const text = await nextPreview(previews, () => page.selectOption('#preview-sample', 'company'), 'company');
        contains(text, ['Mokoena Logistics', '10 company credits'], 'the Firefox preview');
        await ctx.close();
        const learner = await open(firefox, state.tokens.learner[0]);
        assert.equal(await fetchPdf(learner.page, accountDocument('paid', doc('paid', 'receipt')), 'Firefox receipt'), learnerTexts.receipt);
        await learner.ctx.close();
    });
    await firefox.close();

    fs.writeFileSync(`${output}/results.json`, JSON.stringify({results, failures}, null, 2));
    console.log(JSON.stringify({checks: results.length + failures.filter((f) => !f.startsWith('page error')).length, failures}, null, 2));
    process.exitCode = failures.length === 0 ? 0 : 1;
})();
