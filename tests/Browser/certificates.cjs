/* Certificates on the document template engine, in a real browser. ADMIN sees the Classic, Modern
 * and Minimal designs; the course's certificate page has no HTML/CSS editor, links to the templates,
 * saves the certificate wording and previews it through the shared renderer in a sandboxed frame,
 * with a sample PDF. A learner passes the final, opens the certificate from the course and downloads
 * its PDF. ADMIN then makes Modern current: the course preview and the next learner's certificate use
 * it while the first certificate stays exactly as issued. Authored template HTML is saved unchanged
 * (the CKEditor rewriting that broke the old certificate editor is gone). The pages work without
 * JavaScript, a learner cannot open the course's certificate settings, and the pages fit every theme
 * at desktop and phone widths. Firefox repeats the certificate page and the settings preview.
 *
 * Run against the development instance with certificates-fixture.php. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-certificates-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-certificates';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];
const adminTokens = [...state.admin_tokens];
const admin = () => adminTokens.shift();
const settingsUrl = `/admin/courses/${state.course}/certificate`;
const CLASSIC = 'This is to certify that';
const MODERN = 'Completed';
let firstCertificate = '';

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
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const submit = async (page, name) => {
    step = name;
    const button = page.getByRole('button', {name, exact: true});
    await button.focus();
    return Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
};
const frame = (page) => page.frameLocator('iframe.cl-document-preview-frame');
const frameText = async (page) => (await frame(page).locator('body').textContent()).replace(/\s+/g, ' ');
async function assertPdf(page, href, label) {
    const response = await page.request.get(new URL(href, base).href);
    assert.equal(response.status(), 200, `${label} answers 200`);
    assert.equal(response.headers()['content-type'], 'application/pdf', `${label} is served as a PDF`);
    const body = await response.body();
    assert.equal(body.subarray(0, 5).toString(), '%PDF-', `${label} is a PDF`);
    assert.equal((body.toString('latin1').match(/\/Type\s*\/Page(?![a-zA-Z])/g) || []).length, 1, `${label} is one page`);
}
/* A learner passes the one-question final in the reader and opens the certificate from the course. */
async function passFinal(page) {
    await page.goto(base + state.assessment);
    await submit(page, 'Start graded assessment');
    step = 'answer';
    await page.getByLabel('Correct').check();
    await submit(page, 'Submit answer');
    await page.goto(`${base}/learn/${state.slug}`);
    step = 'View certificate';
    await Promise.all([page.waitForNavigation(), page.getByRole('link', {name: 'View certificate'}).click()]);
    assert.match(page.url(), /\/certificates\/[0-9a-f-]{36}$/);
    return page.url();
}

(async () => {
    const chromium = await playwright.chromium.launch();

    await scenario('ADMIN sees the Classic, Modern and Minimal designs, Classic current', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/documents/templates?type=certificate`);
        for (const name of ['Classic certificate', 'Modern certificate', 'Minimal certificate']) {
            step = name;
            const link = page.getByRole('link', {name, exact: true});
            assert.ok(await link.isVisible(), `${name} is listed`);
            const href = await link.getAttribute('href');
            await page.goto(base + href);
            assert.equal(await page.locator('iframe.cl-document-preview-frame').getAttribute('sandbox'), '', 'the preview is fully sandboxed');
            assert.ok((await frameText(page)).includes('Thandiwe Mokoena'), `${name} previews the sample certificate`);
            await page.screenshot({path: `${output}/design-${name.split(' ')[0].toLowerCase()}.png`, fullPage: true});
            await page.goBack();
        }
        await page.goto(`${base}/admin/documents/templates?type=certificate&status=current`);
        assert.equal(await page.locator('table tbody tr').count(), 1, 'one certificate template is current');
        assert.ok(await page.locator('table tbody tr').first().getByText('Classic certificate').isVisible(), 'and it is Classic');
        await ctx.close();
    });

    await scenario('the course certificate page saves its wording and previews it with the shared renderer', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(base + settingsUrl);
        assert.equal(await page.locator('textarea').count(), 0, 'no HTML or CSS editor on the course');
        assert.equal(await page.locator('.ck-editor, .cl-rich-editor').count(), 0, 'no CKEditor');
        assert.equal(await page.getByRole('link', {name: 'Manage certificate templates'}).getAttribute('href'), '/admin/documents/templates?type=certificate');
        assert.equal(await page.locator('iframe.cl-document-preview-frame').getAttribute('sandbox'), '');
        let text = await frameText(page);
        assert.ok(text.includes(CLASSIC) && text.includes(state.course_title) && text.includes('Dr. Browser Signatory'), `the preview is this course in Classic (${text.slice(0, 120)})`);
        await page.locator('#certificate-title').fill('Browser Award of Completion');
        await page.locator('#certificate-footer').fill('Browser footer · NQF level 4');
        await submit(page, 'Save certificate settings');
        assert.ok(await page.getByText('The certificate settings were saved.').isVisible());
        text = await frameText(page);
        assert.ok(text.includes('Browser Award of Completion') && text.includes('Browser footer · NQF level 4'), 'the preview shows the saved wording');
        await assertPdf(page, await page.getByRole('link', {name: 'Open a sample PDF'}).getAttribute('href'), 'the sample PDF');
        await page.screenshot({path: `${output}/settings-classic.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('a learner passes the final, opens the certificate from the course and downloads its PDF', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.first_token);
        firstCertificate = await passFinal(page);
        assert.equal(await page.locator('iframe.cl-document-preview-frame').getAttribute('sandbox'), '');
        const text = await frameText(page);
        for (const expected of ['Lerato Browser Mokoena', state.course_title, 'Browser Award of Completion', CLASSIC, 'Dr. Browser Signatory']) {
            assert.ok(text.includes(expected), `the certificate shows ${expected}`);
        }
        assert.equal(await page.getByText('Drawn from the template').count(), 0, 'a learner does not see the template line');
        await assertPdf(page, await page.getByRole('link', {name: 'Download PDF'}).getAttribute('href'), 'the certificate PDF');
        await page.goto(`${base}/learn/${state.slug}`);
        await assertPdf(page, await page.getByRole('link', {name: 'Download certificate PDF'}).getAttribute('href'), 'the course page PDF link');
        await page.screenshot({path: `${output}/learner-course.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('ADMIN makes Modern current: new certificates use it and the issued one stays Classic', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/documents/templates?type=certificate`);
        await Promise.all([page.waitForNavigation(), page.getByRole('link', {name: 'Modern certificate', exact: true}).click()]);
        step = 'Start draft';
        await page.locator('#template-history').getByRole('button', {name: 'Start a new draft from version 1'}).focus();
        await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
        await submit(page, 'Save and publish');
        // Earlier runs leave published versions behind: they are immutable history.
        assert.ok(await page.getByText(/Version \d+ is published/).first().isVisible(), 'the new Modern version is published');
        await page.goto(base + settingsUrl);
        let text = await frameText(page);
        assert.ok(text.includes(MODERN) && !text.includes(CLASSIC), 'the course preview now uses Modern');
        assert.ok(await page.getByText('Modern certificate').first().isVisible(), 'and names it');

        await page.goto(firstCertificate);
        text = await frameText(page);
        assert.ok(text.includes(CLASSIC) && text.includes('Lerato Browser Mokoena'), 'the issued certificate is still Classic');
        assert.ok(await page.getByRole('link', {name: 'Classic certificate, version 1'}).isVisible(), 'ADMIN sees the version it was issued with');
        await ctx.close();

        const learner = await open(chromium, state.second_token);
        await passFinal(learner.page);
        text = await frameText(learner.page);
        assert.ok(text.includes(MODERN) && !text.includes(CLASSIC) && text.includes('Pieter Browser van Wyk'), 'the next certificate is Modern');
        await learner.page.screenshot({path: `${output}/learner-modern.png`, fullPage: true});
        await learner.ctx.close();
    });

    await scenario('authored template HTML is saved exactly as written', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/documents/templates/new?type=certificate`);
        await page.locator('#template-name').fill(`Authored markup ${state.suffix}`);
        await submit(page, 'Create template');
        // The markup the old CKEditor-backed field rewrote on every save: a bare span, <em>, a table
        // without <tbody>, and footer text outside a paragraph.
        const authored = '<div class="frame"><span class="kicker">Awarded to</span>\n<h1><em>{{ learner.name }}</em></h1>\n<table class="facts"><tr><td>{{ course.title }}</td><td>{{ certificate.number }}</td></tr></table>\n<footer>{{ certificate.issue_date }}</footer></div>';
        await page.locator('#template-html').fill(authored);
        await submit(page, 'Save draft');
        assert.ok(await page.getByText('The draft was saved.').isVisible());
        const editor = page.url();
        for (let save = 0; save < 2; save++) {
            await page.goto(editor);
            assert.equal(await page.locator('#template-html').inputValue(), authored, `the source is unchanged after save ${save + 1}`);
            assert.equal(await page.locator('.ck-editor').count(), 0, 'the source is a plain text field');
            await submit(page, 'Save draft');
        }
        assert.equal(await frame(page).locator('em').count(), 1, 'the preview keeps <em>');
        assert.equal(await frame(page).locator('i').count(), 0, 'and has no <i> substituted');
        assert.equal(await frame(page).locator('span.kicker').count(), 1, 'the span is not wrapped or dropped');
        await ctx.close();
    });

    await scenario('the certificate and its settings work without JavaScript', 'chromium-nojs', async () => {
        const {ctx, page} = await open(chromium, admin(), {js: false});
        await page.goto(firstCertificate);
        assert.ok((await frameText(page)).includes('Lerato Browser Mokoena'), 'the certificate frame renders');
        await page.goto(base + settingsUrl);
        await page.locator('#certificate-title').fill('No-JS Award');
        await submit(page, 'Save certificate settings');
        assert.ok(await page.getByText('The certificate settings were saved.').isVisible());
        assert.ok((await frameText(page)).includes('No-JS Award'));
        await ctx.close();
    });

    await scenario('a learner cannot open the course certificate settings', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.first_token);
        assert.equal((await page.goto(base + settingsUrl)).status(), 403);
        assert.equal((await page.request.get(`${base}${settingsUrl}/sample.pdf`)).status(), 403);
        await ctx.close();
    });

    await scenario('the certificate pages fit every theme at desktop and phone widths', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 1200});
                for (const url of [firstCertificate.replace(base, ''), settingsUrl]) {
                    const response = await page.goto(`${base}${url}?theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${url} does not scroll sideways`);
                    assert.ok(await page.locator('iframe.cl-document-preview-frame').isVisible(), `${theme} ${width} ${url} shows the certificate`);
                    await page.screenshot({path: `${output}/${theme}-${width}-${url.includes('admin') ? 'settings' : 'certificate'}.png`});
                }
            }
        }
        await ctx.close();
    });
    await chromium.close();

    const firefox = await playwright.firefox.launch();
    await scenario('the certificate page and the settings preview render', 'firefox', async () => {
        const {ctx, page} = await open(firefox, admin());
        await page.goto(firstCertificate);
        assert.ok((await frameText(page)).includes(CLASSIC), 'the issued certificate renders');
        await assertPdf(page, await page.getByRole('link', {name: 'Download PDF'}).getAttribute('href'), 'the certificate PDF');
        await page.goto(base + settingsUrl);
        assert.ok((await frameText(page)).includes(MODERN), 'the settings preview renders the current design');
        await ctx.close();
    });
    await firefox.close();

    fs.writeFileSync(`${output}/results.json`, JSON.stringify({results, failures}, null, 2));
    console.log(JSON.stringify({checks: results.length + failures.filter((f) => !f.startsWith('page error')).length, failures}, null, 2));
    process.exitCode = failures.length === 0 ? 0 : 1;
})();
