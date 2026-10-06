/* Document templates in a real browser, in Chromium and Firefox, without JavaScript and in every
 * bundled theme. ADMIN lists and filters the templates, creates a certificate template, inserts a
 * placeholder with the keyboard, previews unsaved edits, is told exactly which placeholder is unknown
 * (with the edits kept), saves the draft and sees the saved draft in the preview, publishes it, starts
 * another draft from the published version, reads the version history and the version page, opens a
 * sample PDF, and is refused a script. A learner cannot open the area. The preview is a sandboxed
 * frame. The editor fits a phone in all five themes without sideways scrolling.
 *
 * Run against the development instance with document-templates-fixture.php. Uses the installed
 * Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-document-templates-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-document-templates';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];
/* A fresh sign-in for each browser context. */
const adminTokens = [...state.admin_tokens];
const admin = () => adminTokens.shift() || state.admin_token;

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
async function open(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: 1400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
/* Buttons are pressed from the keyboard: it is how the editor must work, and the floating palette
 * switcher can sit over the far end of an action row at the bottom edge of the window. */
const submit = async (page, name) => {
    step = name;
    const button = page.getByRole('button', {name, exact: true});
    await button.focus();
    return Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
};
const frameText = async (page) => (await page.frameLocator('iframe.cl-document-preview-frame').locator('body').innerText()).replace(/\s+/g, ' ');

async function createCertificate(page, name) {
    await page.goto(`${base}/admin/documents/templates/new?type=certificate`);
    await page.locator('#template-name').fill(name);
    await submit(page, 'Create template');
    assert.match(page.url(), /\/admin\/documents\/templates\/\d+$/);
}

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();

        await scenario('the template list shows the installed templates and filters by type and status', browserName, async () => {
            const {ctx, page} = await open(browser, admin());
            await page.goto(`${base}/admin/documents/templates`);
            for (const name of ['Standard certificate', 'Standard invoice', 'Standard receipt', 'Standard credit note']) {
                assert.ok(await page.getByRole('link', {name}).isVisible(), `${name} is listed`);
            }
            await page.getByRole('link', {name: 'Certificate', exact: true}).click();
            await page.waitForURL(/type=certificate/);
            assert.equal(await page.getByRole('link', {name: 'Standard invoice'}).count(), 0, 'the type filter applies');
            await page.getByRole('link', {name: 'Current', exact: true}).click();
            await page.waitForURL(/status=current/);
            const rows = page.locator('table tbody tr');
            assert.equal(await rows.count(), 1, 'exactly one certificate template is current');
            assert.ok(await rows.first().locator('.cl-ui-badge', {hasText: 'Current'}).isVisible(), 'and it is marked Current');
            const menu = await page.locator('a[data-nav-item="admin-document-templates"]').count();
            assert.ok(menu > 0, 'Document Templates is in the Administration menu');
            await ctx.close();
        });

        await scenario('a draft is edited with an inserted placeholder, previewed, corrected, saved, published and redrafted', browserName, async () => {
            const {ctx, page} = await open(browser, admin());
            const name = `Browser certificate ${browserName} ${state.suffix}`;
            await createCertificate(page, name);
            assert.ok(await page.getByText('Draft version 1').first().isVisible());
            assert.equal(await page.locator('iframe.cl-document-preview-frame').getAttribute('sandbox'), '', 'the preview frame is fully sandboxed');
            const crumbs = (await page.locator('nav.cl-ui-breadcrumb').first().locator('a, span[aria-current]').allInnerTexts()).map((t) => t.trim());
            assert.deepEqual(crumbs, ['Home', 'Administration', name], 'the breadcrumb ends at the template, with no crumb repeated');

            // Insert a placeholder with the keyboard: the cursor is at the end of the editor.
            const html = page.locator('#template-html');
            await html.fill('<h1>Browser marker for {{ learner.name }}</h1>\n<p>');
            await html.press('End');
            const insert = page.getByRole('button', {name: 'Insert {{ course.title }}'});
            assert.ok(await insert.isVisible(), 'Insert buttons are shown with JavaScript');
            await insert.focus();
            await page.keyboard.press('Enter');
            assert.ok((await html.inputValue()).endsWith('<p>{{ course.title }}'), 'the placeholder was inserted at the cursor');
            assert.ok(await html.evaluate((el) => el === document.activeElement), 'focus returns to the editor');
            await html.press('End');
            await page.keyboard.type('</p>');

            await submit(page, 'Preview');
            assert.ok(await page.getByText('This preview shows your unsaved changes').isVisible());
            let text = await frameText(page);
            assert.ok(text.includes('Browser marker for Thandiwe Mokoena'), `preview renders sample data (${text.slice(0, 80)})`);
            assert.ok(text.includes('Fire Safety for Workplace Supervisors'), 'the inserted placeholder renders');

            await html.fill('<h1>{{ learner.nmae }}</h1>');
            await submit(page, 'Save draft');
            const errors = (await page.locator('#template-errors').innerText()).replace(/\s+/g, ' ');
            assert.ok(errors.includes('Unknown placeholder {{ learner.nmae }} on line 1 (did you mean {{ learner.name }}?)'), `the unknown placeholder is named (${errors})`);
            assert.equal(await page.locator('#template-html').inputValue(), '<h1>{{ learner.nmae }}</h1>', 'the edits are kept');

            await page.locator('#template-html').fill('<script>window.injected = 1</script><p>{{ learner.name }}</p>');
            await submit(page, 'Save draft');
            assert.ok((await page.locator('#template-errors').innerText()).includes('<script> elements are not allowed'), 'a script is refused');

            await page.locator('#template-html').fill('<h1>Saved marker {{ learner.name }}</h1><p>{{ course.title }}</p>');
            await page.locator('#template-note').fill('Browser edit');
            await submit(page, 'Save draft');
            assert.ok(await page.getByText('The draft was saved.').isVisible());
            assert.ok(await page.getByRole('heading', {name: 'Preview of the saved draft'}).isVisible());
            text = await frameText(page);
            assert.ok(text.includes('Saved marker Thandiwe Mokoena'), 'the preview after saving shows the saved draft');
            assert.equal(await page.frameLocator('iframe.cl-document-preview-frame').locator('body').evaluate(() => typeof window.injected), 'undefined');

            await submit(page, 'Save and publish');
            assert.ok(await page.getByText('Version 1 is published').isVisible());
            assert.ok(await page.locator('.cl-ui-badge', {hasText: 'Current'}).first().isVisible(), 'the template is now current');
            const history = page.locator('#template-history');
            assert.ok(await history.getByText('Published · in use').isVisible());
            assert.ok(await history.getByText('Browser edit').isVisible(), 'the change note is in the history');

            const pdfHref = await page.getByRole('link', {name: 'Open a sample PDF'}).getAttribute('href');
            const pdf = await page.request.get(base + pdfHref);
            assert.equal(pdf.headers()['content-type'], 'application/pdf');
            assert.equal((await pdf.body()).subarray(0, 5).toString(), '%PDF-', 'the sample PDF is a PDF');

            step = 'Start draft';
            await history.getByRole('button', {name: 'Start a new draft from version 1'}).focus();
            await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
            assert.ok(await page.getByText('A new draft was started from that version.').isVisible());
            assert.ok(await page.getByText('Draft version 2').first().isVisible());
            assert.equal(await page.locator('#template-history tbody tr').count(), 2, 'the history has both versions');
            await Promise.all([page.waitForNavigation(), page.locator('#template-history').getByRole('link', {name: 'Version 1'}).click()]);
            assert.ok(await page.getByRole('heading', {name: 'Version 1', exact: true}).first().isVisible());
            assert.ok((await frameText(page)).includes('Saved marker Thandiwe Mokoena'), 'the published version still renders as it was');
            await page.screenshot({path: `${output}/${browserName}-version.png`, fullPage: true});
            await ctx.close();
        });

        await scenario('the editor fits a phone', browserName, async () => {
            const {ctx, page} = await open(browser, admin(), {width: 390});
            await page.goto(`${base}/admin/documents/templates`);
            const href = await page.getByRole('link', {name: 'Standard certificate'}).getAttribute('href');
            await page.goto(base + href);
            assert.ok(await noSideScroll(page), 'no sideways scroll at 390px');
            assert.ok(await page.locator('iframe.cl-document-preview-frame').isVisible(), 'the landscape preview scrolls inside its own box');
            await page.screenshot({path: `${output}/${browserName}-390.png`, fullPage: true});
            await ctx.close();
        });
        await browser.close();
    }

    const chromium = await playwright.chromium.launch();
    await scenario('the editor works without JavaScript', 'chromium-nojs', async () => {
        const {ctx, page} = await open(chromium, admin(), {js: false});
        await createCertificate(page, `No-JS certificate ${state.suffix}`);
        assert.equal(await page.getByRole('button', {name: 'Insert {{ course.title }}'}).count(), 0, 'Insert buttons stay hidden; the syntax is listed to copy');
        assert.ok(await page.getByText('{{ course.title }}', {exact: true}).first().isVisible());
        await page.locator('#template-html').fill('<p>No-JS marker {{ learner.name }}</p>');
        await submit(page, 'Preview');
        assert.ok(await page.getByText('This preview shows your unsaved changes').isVisible());
        await submit(page, 'Save draft');
        const shown = (await page.locator('[data-flash-message]').allInnerTexts()).join(' | ') + ' // ' + (await page.locator('#template-errors').innerText());
        assert.ok(shown.includes('The draft was saved.'), `saved (${shown.replace(/\s+/g, ' ')} @ ${page.url()})`);
        assert.equal(await page.locator('#template-html').inputValue(), '<p>No-JS marker {{ learner.name }}</p>');
        await ctx.close();
    });

    await scenario('a learner cannot open document templates', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.learner_token);
        const response = await page.goto(`${base}/admin/documents/templates`);
        assert.equal(response.status(), 403);
        await ctx.close();
    });

    await scenario('the template pages render in every theme without overflow', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/documents/templates`);
        const editor = await page.getByRole('link', {name: 'Standard invoice'}).getAttribute('href');
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 1200});
                for (const url of ['/admin/documents/templates', editor]) {
                    const response = await page.goto(`${base}${url}?theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${url} does not scroll sideways`);
                }
                assert.ok(await page.locator('iframe.cl-document-preview-frame').isVisible(), `${theme} ${width} shows the preview`);
                await page.screenshot({path: `${output}/${theme}-${width}-editor.png`});
            }
        }
        await ctx.close();
    });
    await chromium.close();

    fs.writeFileSync(`${output}/results.json`, JSON.stringify({results, failures}, null, 2));
    console.log(JSON.stringify({checks: results.length + failures.filter((f) => !f.startsWith('page error')).length, failures}, null, 2));
    process.exitCode = failures.length === 0 ? 0 : 1;
})();
