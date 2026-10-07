/* Certificate designs, in a real browser. The Certificate designs list shows the six installed
 * designs as pictures, Classic the default, and nothing of the template engine. ADMIN makes a design
 * in the one-form editor - a look picked by picture, the wording in CKEditor with Insert field, a
 * signatory - while the preview above it, at the full width, redraws as the form changes; the course then picks that design by
 * picture with its own accreditation line and previews what issuing will draw. A learner passes the
 * final and gets the certificate page and PDF. Editing the design changes the next learner's
 * certificate and never the issued one. An uploaded Canva-sized background becomes a design's look.
 * Duplicate, the default for new courses and delete (which moves the courses using a design) work.
 * The editor and the course page work without JavaScript, typed [Learner name] fields included; a
 * learner cannot open either; the pages fit every theme at desktop and phone widths; Firefox
 * repeats the editor and the certificate page.
 *
 * Previews are PDFs drawn into a frame; the check reads each preview's text with Ghostscript (gs, on
 * the host). Headless Chromium hands a PDF frame to a download whose body it cannot give back, so in
 * Chromium every preview request is routed through the check, which keeps the body. A routed request
 * is replayed without its uploaded file, so the upload runs in Firefox, which gives the body back.
 * Pages are taken as loaded at DOMContentLoaded: a success message hides itself after 4.5 seconds,
 * which can be before a slow preview frame finishes loading.
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
const background = process.env.CATTO_CERTIFICATE_BACKGROUND || path.resolve(root, '..', '..', 'design', 'certificate-samples', '01-classic-background.png');
fs.mkdirSync(output, {recursive: true});

const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const results = [], failures = [];
const adminTokens = [...state.admin_tokens];
const admin = () => adminTokens.shift();
const settingsUrl = `/admin/courses/${state.course}/certificate`;
const INSTALLED = ['Classic', 'Modern', 'Minimal', 'Legal Seal', 'Gold Frame', 'Professional CPD'];
const DESIGN = `Browser design ${state.suffix}`;
const UPLOADED = `Browser background ${state.suffix}`;
const ACCREDITATION = 'Browser CPD: 3 points';
let designUrl = '', uploadedUrl = '', firstCertificate = '', firstText = '';

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
/* A PDF drawn as pixels, to compare what two previews look like: their bytes always differ. */
function pdfPixels(body) {
    const file = path.join(output, `pdf-${++pdfCount}.pdf`);
    fs.writeFileSync(file, body);
    return execFileSync('gs', ['-q', '-dNOPAUSE', '-dBATCH', '-sDEVICE=ppmraw', '-r24', '-sOutputFile=-', file], {stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 64 * 1024 * 1024});
}
async function open(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, acceptDownloads: true, viewport: {width: options.width || 1440, height: 1400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    // Every preview the page draws, kept for reading. A preview still drawing when the page closes
    // is of no interest.
    const previews = [];
    if (browser.browserType().name() === 'firefox') {
        page.on('response', async (response) => {
            if (!new URL(response.url()).pathname.endsWith('/preview.pdf')) return;
            try {
                previews.push({method: response.request().method(), status: response.status(), type: response.headers()['content-type'] || '', body: await response.body()});
            } catch (closed) { /* the page closed */ }
        });
    } else {
        await page.route((url) => url.pathname.endsWith('/preview.pdf'), async (route) => {
            try {
                const response = await route.fetch();
                const body = await response.body();
                previews.push({method: route.request().method(), status: response.status(), type: response.headers()['content-type'] || '', body});
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
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const submit = async (page, name) => {
    step = name;
    const button = page.getByRole('button', {name, exact: true});
    await button.focus();
    return Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.keyboard.press('Enter')]);
};
const flash = async (page, text) => assert.ok(await page.getByText(text).first().isVisible(), `the page says: ${text}`);
/* A picture choice, and its radio, by the label printed under its picture. */
const pictureItem = (page, label) => page.locator('label.cl-ui-picture-choice-item')
    .filter({has: page.locator('.cl-ui-picture-choice-label', {hasText: new RegExp(`^${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`)})});
const pictureChoice = (page, label) => pictureItem(page, label).locator('input[type=radio]');
const designRow = (page, name) => page.locator('tbody tr').filter({has: page.getByRole('link', {name, exact: true})});
/* Types at the end of a CKEditor, on a new line. */
async function typeAtEnd(page, editor, text) {
    await editor.click();
    await page.keyboard.press('Control+End');
    await page.keyboard.press('End');
    await page.keyboard.press('Enter');
    await page.keyboard.type(text);
}
async function fetchPdf(page, href, label) {
    step = label;
    const response = await page.request.get(new URL(href, base).href);
    assert.equal(response.status(), 200, `${label} answers 200`);
    assert.equal(response.headers()['content-type'], 'application/pdf', `${label} is served as a PDF`);
    const body = await response.body();
    assert.equal(body.subarray(0, 5).toString(), '%PDF-', `${label} is a PDF`);
    assert.equal((body.toString('latin1').match(/\/Type\s*\/Page(?![a-zA-Z])/g) || []).length, 1, `${label} is one page`);
    return pdfText(body);
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
    const firefox = await playwright.firefox.launch();

    await scenario('the designs list shows the six installed designs as pictures, Classic the default, and no engine internals', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/certificates/designs`);
        for (const name of INSTALLED) {
            step = name;
            const row = designRow(page, name);
            assert.equal(await row.count(), 1, `${name} is listed`);
            assert.ok(await row.locator('img.cl-certificate-design-thumb').evaluate((img) => img.complete && img.naturalWidth > 0), `${name} shows its picture`);
        }
        assert.ok(await designRow(page, 'Classic').getByText('Default for new courses').isVisible(), 'Classic is the default for new courses');
        const text = await page.locator('main').textContent();
        for (const internal of ['Published', 'Draft', 'Archived', 'Version', '{{', 'HTML', 'CSS']) {
            assert.ok(!text.includes(internal), `the list says nothing of ${internal}`);
        }
        assert.ok(await page.locator('a[href="/admin/certificates/designs"]').count() > 0, 'the navigation leads to Certificate designs');
        assert.equal(await page.locator('a[href^="/admin/documents/templates"]').count(), 0, 'and nothing leads to the old template pages');
        await page.screenshot({path: `${output}/designs-list.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('ADMIN makes a design in one form while the preview follows it', 'chromium', async () => {
        const {ctx, page, previews} = await open(chromium, admin());
        await page.goto(`${base}/admin/certificates/designs/new`);
        step = 'editors';
        await page.locator('.cl-certificate-wording .ck-editor').first().waitFor();
        assert.equal(await page.locator('.ck-editor').count(), 2, 'the wording and the small print are CKEditor');
        assert.equal(await page.locator('#design-wording').isVisible(), false, 'the source textarea is hidden');
        for (const gone of ['page_size', 'margin_top', 'orientation']) {
            assert.equal(await page.locator(`[name="${gone}"]`).count(), 0, `no ${gone} setting`);
        }
        let text = await nextPreview(previews, () => pictureChoice(page, 'Modern').check(), 'pick Modern');
        assert.ok(text.includes('Thandiwe Mokoena') && text.includes('Conveyancing 1'), `the preview draws the sample learner (${text.slice(0, 160)})`);

        const wording = page.locator('.cl-certificate-wording').first();
        text = await nextPreview(previews, async () => {
            await typeAtEnd(page, wording.locator('.ck-editor__editable'), 'Browser edition one, issued ');
            await wording.getByRole('button', {name: 'Insert field'}).click();
            await page.locator('.ck-dropdown__panel-visible button', {hasText: 'Date issued'}).click();
        }, 'Insert field');
        assert.ok(text.includes('Browser edition one, issued'), 'the typed words are previewed');
        assert.match(await page.locator('#design-wording').inputValue(), /Browser edition one, issued(?: |&nbsp;)<span class="cl-field" data-field="certificate\.issue_date">Date issued<\/span>/, 'the field is stored as a field, not template syntax');

        text = await nextPreview(previews, async () => {
            await page.locator('#signature-name').fill('Dr. Browser Signatory');
            await page.locator('#signature-position').fill('Head of Learning');
        }, 'signatory');
        assert.ok(text.includes('Dr. Browser Signatory') && text.includes('Head of Learning'), 'the signatory is previewed');

        step = 'name does not redraw';
        const drawn = previews.length;
        await page.locator('#design-name').fill(DESIGN);
        await sleep(2000);
        assert.equal(previews.length, drawn, 'the name is not printed, so typing it does not redraw the preview');
        await page.screenshot({path: `${output}/editor.png`, fullPage: true});

        await submit(page, 'Save');
        assert.match(page.url(), /\/admin\/certificates\/designs\/\d+$/, 'saving opens the saved design');
        designUrl = page.url();
        await flash(page, `The design “${DESIGN}” was saved.`);
        assert.ok(await pictureChoice(page, 'Modern').isChecked(), 'the look is kept');
        assert.ok((await page.locator('#design-wording').inputValue()).includes('data-field="certificate.issue_date"'), 'the wording is kept');
        assert.equal(await page.locator('#signature-name').inputValue(), 'Dr. Browser Signatory');
        await ctx.close();
    });

    await scenario('the course picks that design by picture with its own accreditation line, and previews what issuing will draw', 'chromium', async () => {
        const {ctx, page, previews} = await open(chromium, admin());
        await page.goto(base + settingsUrl);
        step = 'course page';
        assert.equal(await page.locator('textarea, .ck-editor').count(), 0, 'the course holds no wording');
        assert.ok(await pictureChoice(page, 'Classic').isChecked(), 'the course starts with the default design');
        let text = await nextPreview(previews, () => pictureChoice(page, DESIGN).check(), 'pick the design');
        assert.ok(text.includes('Browser edition one') && text.includes(state.course_title) && text.includes('Certificates Administrator'), `the preview is this course in the design (${text.slice(0, 160)})`);
        text = await nextPreview(previews, () => page.locator('#certificate-accreditation').fill(ACCREDITATION), 'accreditation');
        assert.ok(text.includes(ACCREDITATION), 'the accreditation line is previewed');
        await submit(page, 'Save');
        await flash(page, 'The certificate settings were saved.');
        assert.ok(await pictureChoice(page, DESIGN).isChecked(), 'the design is saved');
        assert.equal(await page.locator('#certificate-accreditation').inputValue(), ACCREDITATION);
        assert.match(await page.getByRole('link', {name: 'Edit this design'}).getAttribute('href'), /return_to=/, 'Edit this design comes back here');
        await page.screenshot({path: `${output}/course-certificate.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('a learner passes the final, sees the certificate and downloads its PDF', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.first_tokens[0]);
        firstCertificate = await passFinal(page);
        step = 'certificate page';
        assert.equal(await page.locator('object.cl-certificate-pdf').count(), 1, 'the certificate is shown as its PDF');
        const details = await page.locator('main').textContent();
        for (const expected of ['Lerato Browser Mokoena', state.course_title, ACCREDITATION]) {
            assert.ok(details.includes(expected), `the page shows ${expected}`);
        }
        assert.equal(await page.getByText('Drawn in the design').count(), 0, 'a learner does not see the design line');
        firstText = await fetchPdf(page, await page.getByRole('link', {name: 'Download PDF'}).getAttribute('href'), 'the certificate PDF');
        for (const expected of ['Lerato Browser Mokoena', state.course_title, 'Browser edition one', ACCREDITATION, 'Dr. Browser Signatory']) {
            assert.ok(firstText.includes(expected), `the certificate prints ${expected}`);
        }
        await page.goto(`${base}/learn/${state.slug}`);
        await fetchPdf(page, await page.getByRole('link', {name: 'Download certificate PDF'}).getAttribute('href'), 'the course page PDF link');
        await page.screenshot({path: `${output}/learner-certificate.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('editing the design changes the next certificate and never the issued one', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(base + settingsUrl);
        await Promise.all([page.waitForNavigation(), page.getByRole('link', {name: 'Edit this design'}).click()]);
        step = 'edit';
        await page.locator('.cl-certificate-wording .ck-editor').first().waitFor();
        // Type at the start of the line, on its text: typing straight after the inline Date issued
        // field at the end of the line can land on the field's boundary at Playwright's typing speed.
        await page.locator('.cl-certificate-wording').first().locator('.ck-editor__editable p', {hasText: 'Browser edition one'}).click({position: {x: 3, y: 6}});
        await page.keyboard.press('Home');
        await page.keyboard.type('Edition two: ');
        await sleep(600);
        await submit(page, 'Save');
        assert.equal(page.url(), base + settingsUrl, 'saving returns to the course');
        await flash(page, 'Certificates issued from now on use it');

        assert.equal(await fetchPdf(page, `${firstCertificate}/certificate.pdf`, 'the issued certificate'), firstText, 'the issued certificate prints exactly as it did');
        await page.goto(firstCertificate);
        assert.ok(await page.getByText(`Drawn in the design “${DESIGN}”`).isVisible(), 'ADMIN sees the design it was drawn in');
        await ctx.close();

        const learner = await open(chromium, state.second_token);
        await passFinal(learner.page);
        const text = await fetchPdf(learner.page, await learner.page.getByRole('link', {name: 'Download PDF'}).getAttribute('href'), 'the next certificate');
        assert.ok(text.includes('Pieter Browser van Wyk') && text.includes('Edition two: Browser edition one'), `the next certificate uses the edited design (${text.slice(0, 200)})`);
        await learner.ctx.close();
    });

    await scenario('an uploaded Canva-sized background becomes the design’s look', 'firefox', async () => {
        assert.ok(fs.existsSync(background), `the sample background exists at ${background}`);
        const {ctx, page, previews} = await open(firefox, admin());
        await nextPreview(previews, () => page.goto(`${base}/admin/certificates/designs/new`), 'the first preview, in Classic');
        const classic = pdfPixels(previews[previews.length - 1].body);
        await page.locator('#design-name').fill(UPLOADED);
        let text = await nextPreview(previews, () => page.locator('#design-background').setInputFiles(background), 'upload');
        assert.ok(text.includes('Thandiwe Mokoena'), 'the preview draws the words on the uploaded background');
        const centred = pdfPixels(previews[previews.length - 1].body);
        assert.ok(!centred.equals(classic), 'in the uploaded look, not Classic');
        text = await nextPreview(previews, async () => {
            await pictureChoice(page, 'Right side').check();
            await page.locator('#design-typeface').selectOption('sans');
        }, 'placement');
        assert.ok(text.includes('Thandiwe Mokoena'));
        assert.ok(!pdfPixels(previews[previews.length - 1].body).equals(centred), 'the preview follows the placement and typeface');
        await submit(page, 'Save');
        uploadedUrl = page.url();
        await flash(page, `The design “${UPLOADED}” was saved.`);
        step = 'reopened';
        const own = pictureChoice(page, 'Your own background');
        assert.ok(await own.isChecked(), 'the uploaded background is the look');
        // The looks sit below the preview and their pictures load lazily: scroll to it, as a reader does.
        const ownPicture = pictureItem(page, 'Your own background').locator('img');
        await ownPicture.scrollIntoViewIfNeeded();
        await ownPicture.evaluate((img) => img.complete ? null : new Promise((loaded) => { img.addEventListener('load', loaded, {once: true}); img.addEventListener('error', loaded, {once: true}); }));
        assert.ok(await ownPicture.evaluate((img) => img.complete && img.naturalWidth > 0), 'and shows as a picture');
        assert.ok(await pictureChoice(page, 'Right side').isChecked(), 'the words stay on the right');
        assert.equal(await page.locator('#design-typeface').inputValue(), 'sans');
        await page.screenshot({path: `${output}/editor-uploaded.png`, fullPage: true});
        await page.goto(`${base}/admin/certificates/designs`);
        assert.ok(await designRow(page, UPLOADED).locator('img').evaluate((img) => img.complete && img.naturalWidth > 0 && img.src.includes('/admin/certificates/pictures/')), 'the list shows the uploaded picture');
        await ctx.close();
    });

    await scenario('duplicate, the default for new courses, and delete moving the courses that use a design', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        await page.goto(`${base}/admin/certificates/designs`);
        step = 'Duplicate';
        await designRow(page, DESIGN).getByLabel(`Actions for ${DESIGN}`).click();
        await Promise.all([page.waitForNavigation(), designRow(page, DESIGN).getByRole('button', {name: 'Duplicate'}).click()]);
        await flash(page, `A copy of “${DESIGN}” was made.`);
        const copy = `Copy of ${DESIGN}`;
        assert.equal(await page.locator('#design-name').inputValue(), copy);

        await page.goto(`${base}/admin/certificates/designs`);
        await page.locator('#default-design').selectOption({label: copy});
        await submit(page, 'Save');
        await flash(page, 'New courses will start with that design.');
        assert.ok(await designRow(page, copy).getByText('Default for new courses').isVisible());

        step = 'delete the default';
        await designRow(page, copy).getByLabel(`Actions for ${copy}`).click();
        await Promise.all([page.waitForNavigation(), designRow(page, copy).getByRole('link', {name: 'Delete'}).click()]);
        assert.ok(await page.getByText('No course uses this design.').isVisible());
        await page.locator('#replacement').selectOption({label: 'Classic'});
        await submit(page, 'Delete design');
        await flash(page, `The design “${copy}” was deleted.`);
        assert.equal(await designRow(page, copy).count(), 0, 'the copy is gone');
        assert.ok(await designRow(page, 'Classic').getByText('Default for new courses').isVisible(), 'Classic is the default again');

        step = 'delete the course’s design';
        await designRow(page, DESIGN).getByLabel(`Actions for ${DESIGN}`).click();
        await Promise.all([page.waitForNavigation(), designRow(page, DESIGN).getByRole('link', {name: 'Delete'}).click()]);
        assert.ok(await page.getByRole('link', {name: state.course_title}).isVisible(), 'the confirmation names the course using it');
        await page.locator('#replacement').selectOption({label: 'Minimal'});
        await submit(page, 'Delete design');
        await flash(page, `The design “${DESIGN}” was deleted.`);
        await page.goto(base + settingsUrl);
        assert.ok(await pictureChoice(page, 'Minimal').isChecked(), 'the course moved to Minimal');
        assert.equal(await fetchPdf(page, `${firstCertificate}/certificate.pdf`, 'the issued certificate'), firstText, 'the issued certificate still prints exactly as it did');
        await ctx.close();
    });

    await scenario('the editor and the course page work without JavaScript', 'chromium-nojs', async () => {
        const {ctx, page, previews} = await open(chromium, admin(), {js: false});
        await page.goto(`${base}/admin/certificates/designs/new`);
        step = 'textarea';
        assert.equal(await page.locator('.ck-editor').count(), 0);
        assert.ok(await page.locator('#design-wording').isVisible(), 'the wording is a plain text area');
        await page.locator('#design-name').fill(`No-JS design ${state.suffix}`);
        await page.locator('#design-wording').fill('Just words');
        await submit(page, 'Save');
        await flash(page, 'The design was not saved.');
        assert.ok(await page.getByText('Learner name field').first().isVisible(), 'a design without the learner’s name is refused');
        assert.ok((await page.locator('#design-wording').inputValue()).includes('Just words'), 'and what was typed is kept');

        await page.locator('#design-wording').fill('Certificate of Attendance\n[Learner name]\nattended [Course title] without JavaScript');
        const text = await nextPreview(previews, () => page.getByRole('button', {name: 'Update preview'}).click(), 'Update preview');
        assert.ok(text.includes('Thandiwe Mokoena') && text.includes('attended Conveyancing 1 without JavaScript'), `typed [fields] are fields (${text.slice(0, 160)})`);
        await submit(page, 'Save');
        await flash(page, `The design “No-JS design ${state.suffix}” was saved.`);
        assert.ok((await page.locator('#design-wording').inputValue()).includes('data-field="learner.name"'));

        await page.goto(base + settingsUrl);
        await pictureChoice(page, `No-JS design ${state.suffix}`).check();
        await submit(page, 'Save');
        await flash(page, 'The certificate settings were saved.');
        assert.ok(await pictureChoice(page, `No-JS design ${state.suffix}`).isChecked());
        await ctx.close();
    });

    await scenario('a learner cannot open the design pages or the course certificate page', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.first_tokens[1]);
        for (const url of ['/admin/certificates/designs', '/admin/certificates/designs/new', settingsUrl]) {
            assert.equal((await page.goto(base + url)).status(), 403, url);
        }
        for (const url of [`${settingsUrl}/preview.pdf`, '/admin/certificates/looks/classic/preview.pdf']) {
            assert.equal((await page.request.get(base + url)).status(), 403, url);
        }
        await ctx.close();
    });

    await scenario('the certificate pages fit every theme at desktop and phone widths', 'chromium', async () => {
        const {ctx, page} = await open(chromium, admin());
        const pages = [['list', '/admin/certificates/designs', 'table'], ['editor', uploadedUrl.replace(base, ''), '.cl-certificate-preview-frame'],
            ['course', settingsUrl, '.cl-certificate-preview-frame'], ['certificate', firstCertificate.replace(base, ''), 'object.cl-certificate-pdf']];
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 1200});
                for (const [name, url, selector] of pages) {
                    step = `${theme} ${width} ${name}`;
                    const response = await page.goto(`${base}${url}?theme_preview=${theme}`);
                    assert.equal(response.status(), 200, `${theme} ${url}`);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${name} does not scroll sideways`);
                    assert.ok(await page.locator(selector).first().isVisible(), `${theme} ${width} ${name} shows ${selector}`);
                    if (width === 1440 && (name === 'editor' || name === 'course')) {
                        const [frame, editor, form] = await Promise.all(['.cl-certificate-preview-frame', '.cl-certificate-editor', '.cl-certificate-editor-form'].map((s) => page.locator(s).first().boundingBox()));
                        assert.ok(frame.width >= editor.width * 0.9 && frame.y < form.y, `${theme} ${name}: the preview is above the form at the full width (${Math.round(frame.width)} of ${Math.round(editor.width)} px)`);
                    }
                    await page.screenshot({path: `${output}/${theme}-${width}-${name}.png`});
                }
            }
        }
        await ctx.close();
    });
    await chromium.close();

    await scenario('the editor, Insert field, the preview and the certificate page', 'firefox', async () => {
        const {ctx, page, previews} = await open(firefox, admin());
        await page.goto(`${base}/admin/certificates/designs/new?look=gold-frame`);
        await page.locator('.cl-certificate-wording .ck-editor').first().waitFor();
        const wording = page.locator('.cl-certificate-wording').first();
        const text = await nextPreview(previews, async () => {
            await typeAtEnd(page, wording.locator('.ck-editor__editable'), 'Firefox line for ');
            await wording.getByRole('button', {name: 'Insert field'}).click();
            await page.locator('.ck-dropdown__panel-visible button', {hasText: 'Learner name'}).click();
        }, 'Insert field');
        assert.ok(text.includes('Firefox line for Thandiwe Mokoena'), `the field is filled in (${text.slice(0, 160)})`);
        await page.goto(firstCertificate);
        assert.equal(await page.locator('object.cl-certificate-pdf').count(), 1);
        assert.equal(await fetchPdf(page, await page.getByRole('link', {name: 'Download PDF'}).getAttribute('href'), 'the certificate PDF'), firstText);
        await ctx.close();
    });
    await firefox.close();

    fs.writeFileSync(`${output}/results.json`, JSON.stringify({results, failures}, null, 2));
    console.log(JSON.stringify({checks: results.length + failures.filter((f) => !f.startsWith('page error')).length, failures}, null, 2));
    process.exitCode = failures.length === 0 ? 0 : 1;
})();
