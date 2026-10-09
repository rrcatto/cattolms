/* Course landing pages in a real browser. An ADMIN opens the landing page from the course editor,
 * creates it (the hero and eight sections), writes the hero, fills the two lists and a question,
 * hides and shows a section, adds a call to action and removes it, drags a section to a new place
 * and moves another with the keyboard, previews the page (purchase buttons inert, not indexed) and
 * publishes it. A visitor reads the published page - hero, outline without what is inside the
 * course, the approved review, both prices, a question that opens - and adds the course to the cart
 * from the hero, which returns to the page. A learner who has the course is sent to it; a course
 * with no active price offers nothing to buy. Without JavaScript the ⋯ menu moves a section and the
 * public page and its questions work. The page and the editor fit all five themes at desktop and
 * phone widths without sideways scrolling, Firefox repeats the visitor's page and a drag, and
 * unpublishing takes the page down and keeps it.
 *
 * Run against the development instance with landing-pages-fixture.php (create before, cleanup
 * after). Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-landing-pages-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-landing-pages';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const A = state.courses.a, B = state.courses.b;
const EDITOR = `/admin/courses/${A.id}/landing`;
const PUBLIC = `/landing/${A.slug}`;
const HEADLINE = `Transfer property with confidence ${state.suffix}`;
const results = [], failures = [];

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
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1440, height: 1200}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const clean = (text) => text.replace(/[\s  ]+/g, ' ').trim();
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const order = (page) => page.locator('#landing-section-list .cl-tree-name').allTextContents().then((names) => names.map(clean));
const rowOf = (page, label) => page.locator('#landing-section-list li[data-node-id]').filter({has: page.locator('.cl-tree-name', {hasText: new RegExp(`^${label}$`)})});
const go = async (page, url, status = 200) => {
    step = `open ${url}`;
    const response = await page.goto(base + url);
    assert.equal(response.status(), status, `${url} answers ${status}`);
    return response;
};
const submit = async (page, locator, label) => {
    step = label;
    await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), locator.click()]);
};
const flash = async (page, text) => {
    step = `flash ${text}`;
    assert.ok(await page.locator('[data-flash-message]').filter({hasText: text}).first().isVisible(), `the page says: ${text}`);
};
async function saved(page) {
    await page.locator('.cl-tree-status[data-state="saved"]').waitFor({timeout: 10000});
}
async function drag(page, fromLabel, toLabel, fraction) {
    step = `drag ${fromLabel} to ${toLabel}`;
    const handle = rowOf(page, fromLabel).locator('.cl-tree-handle');
    const target = rowOf(page, toLabel).locator('.cl-tree-row');
    // Both rows on screen: the whole list in view, as a person would have it before dragging.
    await page.locator('#landing-section-list').evaluate((list) => list.scrollIntoView({block: 'center', behavior: 'instant'}));
    const from = await handle.boundingBox();
    const to = await target.boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 4, from.y + from.height / 2 + 4, {steps: 3});
    await page.mouse.move(to.x + to.width / 4, to.y + to.height * fraction, {steps: 12});
    await page.mouse.move(to.x + to.width / 4 + 2, to.y + to.height * fraction, {steps: 2});
    await page.mouse.up();
}
async function editSection(page, label) {
    await go(page, EDITOR);
    await submit(page, page.getByRole('link', {name: `Edit ${label}`, exact: true}), `edit ${label}`);
}

(async () => {
    const chromium = await playwright.chromium.launch();
    const firefox = await playwright.firefox.launch();

    await scenario('ADMIN opens the landing page from the course editor and creates it with the recommended sections', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await go(page, `/admin/courses/${A.id}`);
        await submit(page, page.getByRole('link', {name: 'Landing page, none yet'}), 'course editor link');
        assert.ok(await page.getByText('This course has no landing page').isVisible());
        await submit(page, page.getByRole('button', {name: 'Create landing page'}), 'create');
        await flash(page, 'created as a draft');
        assert.equal(clean(await page.locator('.cl-landing-fixed .cl-tree-name').textContent()), 'Hero');
        assert.deepEqual(await order(page), ['Who this course is for', 'Learning outcomes', 'Course contents', 'Benefits', 'Reviews', 'Pricing', 'FAQ', 'Call to action']);
        assert.equal(clean(await page.locator('#landing-status .cl-ui-badge').first().textContent()), 'Draft');
        assert.ok(await page.getByRole('button', {name: 'Publish'}).isDisabled(), 'Publish waits for the empty lists');
        assert.ok((await page.locator('#landing-status').textContent()).includes('FAQ has no questions yet.'));
        assert.equal(await page.locator('.cl-landing-fixed .cl-tree-handle').count(), 0, 'The hero has no handle: it is always first');
        await ctx.close();
    });

    await scenario('ADMIN writes the hero, fills the lists, adds a question and hides and shows a section', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await editSection(page, 'Hero');
        assert.equal(await page.locator('#landing-headline').getAttribute('placeholder'), A.title, 'An empty headline is the course title');
        await page.fill('#landing-headline', HEADLINE);
        await submit(page, page.getByRole('button', {name: 'Save', exact: true}), 'save hero');
        await flash(page, 'Hero was saved');
        for (const [label, lines] of [['Who this course is for', 'Candidate attorneys\nConveyancing secretaries'], ['Learning outcomes', 'Draft a deed of transfer\nLodge documents at the deeds office']]) {
            await editSection(page, label);
            await page.fill('#landing-items', lines);
            await submit(page, page.getByRole('button', {name: 'Save', exact: true}), `save ${label}`);
        }
        await editSection(page, 'FAQ');
        await page.fill('#landing-item-1-first', 'How long do I have?');
        await page.fill('#landing-item-1-second', 'A year from when you start.');
        await submit(page, page.getByRole('button', {name: 'Save', exact: true}), 'save FAQ');
        await go(page, EDITOR);
        await submit(page, page.getByRole('button', {name: 'Hide Benefits'}), 'hide benefits');
        assert.ok(await rowOf(page, 'Benefits').getByText('Hidden').isVisible());
        await submit(page, page.getByRole('button', {name: 'Show Benefits'}), 'show benefits');
        assert.equal(await rowOf(page, 'Benefits').getByText('Hidden').count(), 0);
        assert.ok(await rowOf(page, 'Benefits').getByText('Needs content').isVisible(), 'A shown empty list needs content');
        await submit(page, page.getByRole('button', {name: 'Hide Benefits'}), 'hide benefits again');
        assert.ok(await page.getByRole('button', {name: 'Publish'}).isEnabled(), 'Everything shown is complete');
        await ctx.close();
    });

    await scenario('ADMIN adds a call to action, cannot add a second pricing section, and removes the extra section', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await go(page, EDITOR);
        step = 'open Add section';
        await page.getByRole('link', {name: 'Add section'}).click();
        const modal = page.locator('#landing-add');
        await modal.waitFor({state: 'visible'});
        assert.ok(await modal.getByRole('radio', {name: /^Pricing/}).isDisabled(), 'A page has one pricing section');
        await modal.getByRole('radio', {name: /^Call to action/}).check();
        await submit(page, modal.getByRole('button', {name: 'Add section'}), 'add');
        assert.match(page.url(), /\/landing\/sections\/\d+$/, 'The new section opens in its form');
        await submit(page, page.getByRole('link', {name: 'Cancel'}), 'cancel');
        assert.equal((await order(page)).filter((name) => name === 'Call to action').length, 2);
        const extra = page.locator('#landing-section-list li[data-node-id]').filter({has: page.locator('.cl-tree-name', {hasText: /^Call to action$/})}).last();
        await extra.locator('summary.cl-ui-row-trigger').click();
        await submit(page, extra.getByRole('button', {name: 'Remove'}), 'remove');
        await flash(page, 'removed');
        assert.equal((await order(page)).filter((name) => name === 'Call to action').length, 1);
        await ctx.close();
    });

    await scenario('ADMIN drags a section to a new place and moves another with the keyboard, and the order is saved', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await go(page, EDITOR);
        await drag(page, 'Pricing', 'Who this course is for', 0.15);
        await saved(page);
        assert.equal((await order(page))[0], 'Pricing');
        step = 'keyboard';
        await rowOf(page, 'Course contents').locator('.cl-tree-handle').focus();
        await page.keyboard.press('ArrowUp');
        await saved(page);
        await page.reload();
        assert.deepEqual((await order(page)).slice(0, 4), ['Pricing', 'Who this course is for', 'Course contents', 'Learning outcomes'], 'Both moves were saved');
        assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('cl-tree-handle')), false);
        await ctx.close();
    });

    await scenario('the preview is the page with inert buttons, and ADMIN publishes it', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await go(page, EDITOR);
        step = 'preview';
        const [preview] = await Promise.all([ctx.waitForEvent('page'), page.getByRole('link', {name: 'Preview'}).click()]);
        await preview.waitForLoadState('domcontentloaded');
        assert.ok(await preview.getByText('Preview of the landing page').isVisible());
        assert.equal(clean(await preview.locator('.cl-landing-hero h1').textContent()), HEADLINE);
        assert.ok(await preview.getByRole('button', {name: 'Add to cart'}).first().isDisabled(), 'Buttons do nothing in a preview');
        assert.equal(await preview.locator('meta[name="robots"]').getAttribute('content'), 'noindex, nofollow');
        assert.equal(await preview.locator('link[rel="canonical"]').count(), 0);
        await preview.close();
        await submit(page, page.getByRole('button', {name: 'Publish'}), 'publish');
        await flash(page, 'is published');
        assert.equal(clean(await page.locator('#landing-status .cl-ui-badge').first().textContent()), 'Published');
        assert.ok(await page.locator('.cl-landing-address a').isVisible(), 'The address is a link once it is public');
        await go(page, `/admin/courses/${A.id}`);
        assert.ok(await page.getByRole('link', {name: 'Landing page, published'}).isVisible(), 'The course editor shows the state');
        await ctx.close();
    });

    await scenario('a visitor reads the published page: hero, outline, review, prices and a question', 'chromium', async () => {
        const {ctx, page} = await open(chromium, null);
        await go(page, `${PUBLIC}?utm_source=browser&utm_campaign=landing`);
        assert.equal(clean(await page.locator('h1').first().textContent()), HEADLINE);
        const text = clean(await page.locator('main').textContent());
        for (const shown of ['Candidate attorneys', 'Draft a deed of transfer', 'Deeds and transfers', 'Drafting a deed of transfer', 'Registering a bond', 'Free preview', 'Clear and practical.', 'R 1 500,00', 'R 900,00', 'How long do I have?']) {
            assert.ok(text.includes(shown), `the page shows ${shown}`);
        }
        for (const hidden of ['SECRET BROWSER QUESTION', 'SECRET BROWSER LESSON', 'SECRET BROWSER BONDS', 'Why take this course']) {
            assert.ok(!text.includes(hidden), `the page does not show ${hidden}`);
        }
        const answer = page.getByText('A year from when you start.');
        assert.equal(await answer.isVisible(), false, 'An answer starts closed');
        await page.getByText('How long do I have?').click();
        assert.ok(await answer.isVisible(), 'and opens');
        assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'), `${base}${PUBLIC}`);
        assert.ok(await page.getByRole('link', {name: 'Sign in'}).first().isVisible(), 'A visitor may sign in');
        await page.screenshot({path: `${output}/public-desktop.png`, fullPage: true});
        await ctx.close();
    });

    await scenario('a visitor adds the course to the cart from the hero and comes back to the page', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.learner);
        await go(page, PUBLIC);
        const hero = page.locator('.cl-landing-hero');
        assert.ok(clean(await hero.textContent()).includes('R 1 500,00 · 1 year access · other periods available'));
        await submit(page, hero.getByRole('button', {name: 'Add to cart'}), 'add to cart');
        assert.equal(new URL(page.url()).pathname, PUBLIC, 'Add to cart returns to the landing page');
        await flash(page, 'was added to My Cart');
        await ctx.close();
    });

    await scenario('a learner who has the course is sent to it, and a course not on sale offers nothing to buy', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.holder);
        await go(page, PUBLIC);
        assert.ok(await page.getByRole('link', {name: 'Continue course'}).first().isVisible());
        assert.equal(await page.getByRole('button', {name: 'Add to cart'}).count(), 0, 'The course is not sold to its learner again');
        await go(page, `/landing/${B.slug}`);
        assert.ok(await page.getByText('This course is not on sale at the moment.').first().isVisible());
        assert.equal(await page.getByRole('button', {name: 'Add to cart'}).count(), 0);
        await ctx.close();
    });

    await scenario('without JavaScript ADMIN moves and hides a section through the ⋯ menu', 'chromium-nojs', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin, {js: false});
        await go(page, EDITOR);
        assert.equal(await page.locator('.cl-tree-handle:visible').count(), 0, 'No drag handles without JavaScript');
        const before = await order(page);
        const row = rowOf(page, before[0]);
        await row.locator('summary.cl-ui-row-trigger').click();
        await submit(page, row.getByRole('button', {name: 'Move to bottom'}), 'move to bottom');
        await flash(page, 'was moved');
        assert.equal((await order(page)).at(-1), before[0]);
        await ctx.close();
    });

    await scenario('without JavaScript the public page, its questions and the cart work', 'chromium-nojs', async () => {
        const {ctx, page} = await open(chromium, null, {js: false});
        await go(page, PUBLIC);
        await page.getByText('How long do I have?').click();
        assert.ok(await page.getByText('A year from when you start.').isVisible());
        await submit(page, page.locator('.cl-landing-hero').getByRole('button', {name: 'Add to cart'}), 'add to cart');
        assert.equal(new URL(page.url()).pathname, PUBLIC);
        await ctx.close();
    });

    await scenario('the page, the editor and a section form fit every theme at desktop and phone widths', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await go(page, EDITOR);
        const sectionForm = await page.getByRole('link', {name: 'Edit FAQ'}).getAttribute('href');
        const pages = [['public', PUBLIC, '.cl-landing-hero h1'], ['editor', EDITOR, '#landing-section-list'], ['section', sectionForm, '#landing-heading']];
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                await page.setViewportSize({width, height: 1200});
                for (const [name, url, selector] of pages) {
                    step = `${theme} ${width} ${name}`;
                    const response = await page.goto(`${base}${url}?theme_preview=${theme}`);
                    assert.equal(response.status(), 200);
                    assert.ok(await noSideScroll(page), `${theme} ${width} ${name} does not scroll sideways`);
                    assert.ok(await page.locator(selector).first().isVisible(), `${theme} ${width} ${name} shows ${selector}`);
                    if (name === 'public') {
                        const button = await page.locator('.cl-landing-hero').getByRole('button', {name: 'Add to cart'}).boundingBox();
                        assert.ok(button && button.height >= 32 && button.x >= 0 && button.x + button.width <= width, `${theme} ${width}: the hero's button is whole and easy to press`);
                        const contrast = await page.locator('.cl-landing-hero .cl-ui-surface').first().evaluate((surface) => {
                            // Any CSS colour (rgb(), color(srgb …)) as 0-255 channels, through a canvas.
                            const canvas = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
                            const rgb = (colour) => { canvas.clearRect(0, 0, 1, 1); canvas.fillStyle = colour; canvas.fillRect(0, 0, 1, 1); return [...canvas.getImageData(0, 0, 1, 1).data].slice(0, 3); };
                            const luminance = ([r, g, b]) => [r, g, b].map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
                            const note = surface.querySelector('.cl-course-purchase-price');
                            const [a, b] = [luminance(rgb(getComputedStyle(note).color)), luminance(rgb(getComputedStyle(surface).backgroundColor))];
                            return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
                        });
                        assert.ok(contrast >= 4.5, `${theme} ${width}: the buy box's price can be read (contrast ${contrast.toFixed(2)})`);
                    }
                    await page.screenshot({path: `${output}/${theme}-${width}-${name}.png`, fullPage: name === 'public'});
                }
            }
        }
        await ctx.close();
    });

    await scenario('unpublishing takes the page down and keeps everything on it', 'chromium', async () => {
        const {ctx, page} = await open(chromium, state.tokens.admin);
        await go(page, EDITOR);
        await submit(page, page.getByRole('button', {name: 'Unpublish'}), 'unpublish');
        await flash(page, 'is unpublished');
        assert.equal(clean(await page.locator('#landing-status .cl-ui-badge').first().textContent()), 'Draft');
        assert.equal(await page.getByRole('button', {name: 'Delete page'}).count(), 0, 'A page that has been public is unpublished, not deleted');
        assert.equal((await order(page)).length, 8);
        const visitor = await open(chromium, null);
        await go(visitor.page, PUBLIC, 404);
        await visitor.ctx.close();
        await submit(page, page.getByRole('button', {name: 'Publish'}), 'publish again');
        await flash(page, 'is published');
        await ctx.close();
    });
    await chromium.close();

    await scenario('the visitor’s page, its questions and the cart, and a drag in the editor', 'firefox', async () => {
        const visitor = await open(firefox, null);
        await go(visitor.page, PUBLIC);
        assert.equal(clean(await visitor.page.locator('h1').first().textContent()), HEADLINE);
        await visitor.page.getByText('How long do I have?').click();
        assert.ok(await visitor.page.getByText('A year from when you start.').isVisible());
        await submit(visitor.page, visitor.page.locator('.cl-landing-hero').getByRole('button', {name: 'Add to cart'}), 'add to cart');
        assert.equal(new URL(visitor.page.url()).pathname, PUBLIC);
        await visitor.ctx.close();
        const {ctx, page} = await open(firefox, state.tokens.admin);
        await go(page, EDITOR);
        const before = await order(page);
        await drag(page, before[2], before[0], 0.15);
        await saved(page);
        await page.reload();
        assert.equal((await order(page))[0], before[2], 'The Firefox drag was saved');
        await ctx.close();
    });
    await firefox.close();

    fs.writeFileSync(`${output}/results.json`, JSON.stringify({results, failures}, null, 2));
    console.log(JSON.stringify({checks: results.length + failures.filter((f) => !f.startsWith('page error')).length, failures}, null, 2));
    process.exitCode = failures.length === 0 ? 0 : 1;
})();
