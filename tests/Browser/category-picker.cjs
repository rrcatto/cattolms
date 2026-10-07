/* The shared category picker (form.tree-select) wherever a category is chosen: a course's category
 * on the create and edit forms and in the import review, and the catalogue at /courses. In Chromium
 * and Firefox, without JavaScript, and across every theme at desktop and phone widths.
 *
 * The picker opens on Uncategorised and the main categories with every branch closed; branches open
 * and close independently; any level can be chosen and the closed control shows its full path;
 * reopening opens only the way to the chosen category; Uncategorised can be chosen. + New creates a
 * category in place at the place chosen in its own picker (Top level, a main category or a
 * subcategory, never a sub-subcategory), shows the path it will have, can change its place before
 * saving, chooses the new category afterwards, and Cancel changes nothing; a forged fourth level is
 * refused. The keyboard opens, expands and chooses. A course saves the category chosen at any level
 * and Uncategorised; an import is filed in the category chosen, including one created during the
 * import. The catalogue's picker behaves the same way and navigates to the category chosen. Without
 * JavaScript the picker lists the whole tree and still chooses.
 *
 * Run against the development instance with category-tree-fixture.php, which builds
 *   A > A1 > A1x, A > A2      B > B1      G      D > D1 > D1x
 * after the shipped taxonomy and rebuilds it (deleting the courses the run made) before each
 * scenario. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-category-tree-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-category-picker';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (command) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `cd /home/cattotest/code/cattolms-v0.8 && SHELL_VERBOSITY=-1 php tests/Browser/category-tree-fixture.php ${command} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
let state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const reset = () => { state = JSON.parse(php('reset').trim().split('\n').pop()); };
const tokens = [...state.tokens];
const token = () => tokens.shift();
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
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
        console.log(`FAIL ${browserName}: ${name}: ${step ? '[' + step + '] ' : ''}${error.message.split('\n').slice(0, 6).join(' | ')}`);
    }
}
async function open(browser, options = {}) {
    // Reduced motion where the page is scrolled by the check: the themes scroll smoothly, and a smooth
    // scroll still running moves the anchored popover under the pointer.
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: options.height || 1400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false || options.still ? 'reduce' : 'no-preference'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token(), url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const ids = () => state.ids;
const names = () => state.names;
const pathOf = (...keys) => keys.map((key) => names()[key]).join(' › ');
/* One picker on the page, by the id of its closed control. */
const picker = (page, id) => ({
    toggle: page.locator(`#${id}`),
    panel: page.locator(`#${id}-options`),
    expander: (key) => page.locator(`#${id}-options [aria-controls="${id}-branch-${ids()[key]}"]`),
    option: (key) => { const value = key === '0' || key === '' ? key : ids()[key]; return page.locator(`#${id}-options button.cl-ui-tree-select-option[value="${value}"], #${id}-options label.cl-ui-tree-select-option:has(input[value="${value}"])`); },
    visible: () => page.locator(`#${id}-options .cl-ui-tree-select-option:visible`).count(),
    shown: async () => (await page.locator(`#${id} .cl-ui-tree-select-value`).textContent()).trim(),
});
/* Scrolls the open list itself, as a reader does, so a lower option is in view without moving the page. */
async function scrollList(locator) {
    await locator.evaluate((element) => { const panel = element.closest('[popover]'); panel.scrollTop = Math.max(0, element.getBoundingClientRect().top - panel.getBoundingClientRect().top + panel.scrollTop - panel.clientHeight / 3); });
}
async function opened(p) {
    if (!(await p.panel.isVisible())) await p.toggle.click();
    await p.panel.waitFor({state: 'visible'});
}
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const shipped = () => state.shipped_roots;
const ROOTS = 4; // A, B, G and D

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();
        const {ctx, page} = await open(browser);
        const fresh = async (url = `${base}/admin/courses/new`) => { reset(); await page.goto(url); };
        const course = () => picker(page, 'category');

        await scenario('the picker opens on Uncategorised and the main categories, every branch closed', browserName, async () => {
            await fresh();
            const p = course();
            assert.equal(await p.shown(), 'Uncategorised');
            await opened(p);
            assert.equal(await p.visible(), 1 + shipped() + ROOTS, 'Uncategorised and the main categories only.');
            assert.equal(await page.locator('#category-options .cl-ui-tree-select-expand[aria-expanded="true"]').count(), 0);
            assert.ok(await p.expander('A').isVisible(), 'A category with subcategories has a disclosure button.');
            assert.equal(await p.expander('G').count(), 0, 'One without has none.');
            assert.equal(await p.option('A1').isVisible(), false);
            await page.screenshot({path: `${output}/${browserName}-opened.png`});
        });

        await scenario('branches open independently and any level is chosen and shown by its path', browserName, async () => {
            await fresh();
            const p = course();
            await opened(p);
            await p.expander('A').click();
            assert.ok(await p.option('A1').isVisible());
            assert.ok(await p.panel.isVisible(), 'Opening a branch does not choose or close.');
            await p.expander('B').click();
            assert.ok(await p.option('B1').isVisible() && await p.option('A1').isVisible(), 'Two branches open at once.');
            await p.expander('A').click();
            assert.equal(await p.option('A1').isVisible(), false);
            assert.ok(await p.option('B1').isVisible(), 'Closing one leaves the other open.');
            await p.expander('A').click();
            await p.expander('A1').click();
            assert.equal(await p.expander('A1x').count(), 0, 'A sub-subcategory has no disclosure button.');
            await p.option('A1x').click();
            assert.equal(await p.panel.isVisible(), false, 'Choosing closes the picker.');
            assert.equal(await p.shown(), pathOf('A', 'A1', 'A1x'));
        });

        await scenario('reopening opens only the way to the chosen category; Uncategorised can be chosen', browserName, async () => {
            await fresh();
            const p = course();
            await opened(p);
            await p.expander('D').click();
            await p.expander('D1').click();
            await p.option('D1x').click();
            await p.toggle.click();
            await p.panel.waitFor({state: 'visible'});
            assert.deepEqual([await p.expander('D').getAttribute('aria-expanded'), await p.expander('D1').getAttribute('aria-expanded'), await p.expander('A').getAttribute('aria-expanded'), await p.expander('B').getAttribute('aria-expanded')], ['true', 'true', 'false', 'false']);
            assert.ok(await p.option('D1x').isVisible());
            await p.option('0').click();
            assert.equal(await p.shown(), 'Uncategorised');
            await p.toggle.click();
            assert.equal(await page.locator('#category-options .cl-ui-tree-select-expand[aria-expanded="true"]').count(), 0, 'With nothing chosen, every branch starts closed.');
            await page.keyboard.press('Escape');
        });

        await scenario('+ New creates a category at the chosen place and chooses it; Cancel changes nothing', browserName, async () => {
            await fresh();
            const p = course();
            const place = picker(page, 'category-new-place');
            step = 'previous choice';
            await opened(p);
            await p.option('G').click();
            step = 'cancel';
            await page.getByRole('button', {name: 'Create a new category'}).click();
            await page.locator('#category-new-name').fill(`Tree Cancelled ${state.suffix}`);
            await page.locator('#category-new').getByRole('button', {name: 'Cancel'}).click();
            assert.equal(await page.locator('#category-new').isVisible(), false);
            assert.equal(await p.shown(), names().G, 'Cancel keeps the previous choice.');
            for (const [label, where, expected] of [['top', null, 1], ['sub', 'B', 2], ['subsub', ['B', 'B1'], 3]]) {
                step = `create ${label}`;
                await page.getByRole('button', {name: 'Create a new category'}).click();
                const name = `Tree New ${label} ${state.suffix}`;
                await page.locator('#category-new-name').fill(name);
                assert.equal(await place.shown(), 'Top level', 'The place starts at Top level.');
                if (where !== null) {
                    await opened(place);
                    assert.equal(await place.expander('A1').count() + await place.option('A1x').count(), 0, 'Places under the main categories are closed at first.');
                    await place.option('A').click();
                    assert.equal(await page.locator('[data-category-create-path]').textContent(), `${names().A} › ${name}`, 'The path it will have is shown.');
                    await opened(place);
                    const steps = Array.isArray(where) ? where : [where];
                    for (const key of steps.slice(0, -1)) await place.expander(key).click();
                    await place.option(steps[steps.length - 1]).click();
                    assert.equal(await place.shown(), pathOf(...steps), 'The place can be changed before saving.');
                    await opened(place);
                    if (Array.isArray(where)) {
                        await place.expander('A').click();
                        assert.equal(await place.option('A1x').count(), 0, 'A sub-subcategory is never offered as a place.');
                        assert.equal(await place.expander('A1').count(), 0);
                    }
                    await page.keyboard.press('Escape');
                }
                const pathText = where === null ? name : `${pathOf(...(Array.isArray(where) ? where : [where]))} › ${name}`;
                assert.equal(await page.locator('[data-category-create-path]').textContent(), pathText);
                await page.locator('#category-new').getByRole('button', {name: 'Create category'}).click();
                await page.locator('#category-new').waitFor({state: 'hidden'});
                assert.equal(await course().shown(), pathText, 'The new category is chosen and shown by its path.');
                const depth = await page.locator('#category-options input:checked').evaluate((radio) => radio.closest('label').dataset.depth);
                assert.equal(depth, String(expected));
            }
            step = 'refused';
            await page.getByRole('button', {name: 'Create a new category'}).click();
            await page.locator('#category-new-name').fill(names().A);
            await page.locator('#category-new').getByRole('button', {name: 'Create category'}).click();
            await page.locator('[data-category-error]:visible').waitFor();
            assert.match(await page.locator('[data-category-error]').textContent(), /already/);
            assert.ok(await page.locator('#category-new').isVisible(), 'A refusal keeps the panel open with what was typed.');
            assert.equal(await page.locator('#category-new-name').inputValue(), names().A);
        });

        await scenario('a fourth level is refused by the server', browserName, async () => {
            await fresh();
            const answer = await page.evaluate(async (parent) => {
                const body = new FormData();
                body.append('csrf', document.querySelector('[data-category-create-panel]').dataset.csrf);
                body.append('name', `Forged ${Date.now()}`);
                body.append('parent_id', String(parent));
                const response = await fetch('/admin/courses/categories/inline', {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
                return {status: response.status, data: await response.json()};
            }, ids().A1x);
            assert.equal(answer.status, 422);
            assert.match(answer.data.error, /cannot hold subcategories/);
        });

        await scenario('the keyboard opens the picker, opens branches and chooses', browserName, async () => {
            await fresh();
            await page.locator('#title').focus();
            const p = course();
            await p.toggle.focus();
            await page.keyboard.press('Enter');
            await p.panel.waitFor({state: 'visible'});
            await p.expander('B').focus();
            await page.keyboard.press('Enter');
            assert.equal(await p.expander('B').getAttribute('aria-expanded'), 'true');
            assert.ok(await p.panel.isVisible(), 'Opening a branch with the keyboard does not choose.');
            await page.locator(`#category-options input[value="${ids().B}"]`).focus();
            await page.keyboard.press('ArrowDown');
            await page.keyboard.press('Enter');
            assert.equal(await p.panel.isVisible(), false);
            assert.equal(await p.shown(), pathOf('B', 'B1'), 'The arrow key moved to the next choice, Enter chose it.');
        });

        if (browserName === 'chromium') {
            await scenario('a course saves the category chosen at any level, and Uncategorised', browserName, async () => {
                await fresh();
                await page.locator('#title').fill(`Picker course ${state.suffix}`);
                const p = course();
                await opened(p);
                await p.expander('A').click();
                await p.option('A1').click();
                await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Create course'}).click()]);
                assert.match(page.url(), /\/admin\/courses\/\d+/);
                assert.equal(await course().shown(), pathOf('A', 'A1'), 'Saved and shown by its path on the course.');
                await opened(course());
                await course().expander('A1').click();
                await course().option('A1x').click();
                await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Save course details'}).click()]);
                assert.equal(await course().shown(), pathOf('A', 'A1', 'A1x'));
                await opened(course());
                await course().option('0').click();
                await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Save course details'}).click()]);
                assert.equal(await course().shown(), 'Uncategorised', 'Cleared to Uncategorised.');
            });

            await scenario('an import is filed in the category chosen, one created during the import included', browserName, async () => {
                reset();
                const file = path.join(os.tmpdir(), `picker-import-${state.suffix}.json`);
                const stamp = `${state.suffix}-${Date.now()}`;
                fs.writeFileSync(file, JSON.stringify({course: {slug: `picker-import-${stamp}`, title: `Picker import ${stamp}`}, modules: [{title: 'Only module', module_key: 'm1', assessment_required: false, content_html: '<p>Hello</p>'}]}));
                await page.goto(`${base}/admin/courses/import`);
                await page.locator('#course-file').setInputFiles(file);
                await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Analyse course'}).click()]);
                fs.unlinkSync(file);
                const p = picker(page, 'import-category');
                assert.equal(await p.shown(), 'Uncategorised');
                await opened(p);
                assert.equal(await p.visible(), 1 + shipped() + ROOTS, 'The import uses the same picker, closed at first.');
                await page.keyboard.press('Escape');
                step = '+ New in the import';
                await page.getByRole('button', {name: 'Create a new category'}).click();
                await page.locator('#import-category-new-name').fill(`Tree Imported ${state.suffix}`);
                const place = picker(page, 'import-category-new-place');
                await opened(place);
                await place.option('D').click();
                await page.locator('#import-category-new').getByRole('button', {name: 'Create category'}).click();
                await page.locator('#import-category-new').waitFor({state: 'hidden'});
                assert.equal(await picker(page, 'import-category').shown(), `${names().D} › Tree Imported ${state.suffix}`);
                await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Import into PostgreSQL'}).click()]);
                assert.match(page.url(), /\/admin\/courses\/\d+/, `The import was committed (${(await page.locator('[data-flash-message]').allTextContents()).join(' ').trim()})`);
                assert.equal(await course().shown(), `${names().D} › Tree Imported ${state.suffix}`, 'The imported course is in the new category.');
            });

            await scenario('the catalogue uses the same picker and goes to the category chosen', browserName, async () => {
                await fresh(`${base}/courses`);
                const p = picker(page, 'catalogue-category-picker');
                await opened(p);
                assert.equal(await p.visible(), 1 + shipped() + ROOTS, 'All categories and the main categories, closed.');
                await p.expander('A').click();
                await p.expander('A1').click();
                await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), p.option('A1x').click()]);
                assert.match(page.url(), /\/courses\/category\//);
                assert.equal(await picker(page, 'catalogue-category-picker').shown(), pathOf('A', 'A1', 'A1x'));
                await opened(picker(page, 'catalogue-category-picker'));
                assert.equal(await picker(page, 'catalogue-category-picker').expander('A1').getAttribute('aria-expanded'), 'true', 'Reopening opens the way to the category being browsed.');
                assert.equal(await picker(page, 'catalogue-category-picker').expander('B').getAttribute('aria-expanded'), 'false');
            });
        }

        await ctx.close();
        await browser.close();
    }

    const chromium = await playwright.chromium.launch();
    await scenario('without JavaScript the picker lists the whole tree and still chooses', 'chromium-nojs', async () => {
        reset();
        const {ctx, page} = await open(chromium, {js: false});
        await page.goto(`${base}/admin/courses/new`);
        const p = picker(page, 'category');
        assert.equal(await page.getByRole('button', {name: 'Create a new category'}).isVisible(), false, '+ New needs JavaScript and is not offered.');
        await p.toggle.click();
        await p.panel.waitFor({state: 'visible'});
        assert.ok(await p.option('A1x').isVisible(), 'Every level is listed.');
        await p.option('A1x').click();
        await page.keyboard.press('Escape');
        await page.locator('#title').fill(`Picker nojs ${state.suffix}`);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Create course'}).click()]);
        assert.equal(await picker(page, 'category').shown(), pathOf('A', 'A1', 'A1x'), 'The course was saved in it.');
        await ctx.close();
    });

    await scenario('the picker fits every theme at desktop and phone widths', 'chromium', async () => {
        reset();
        const {ctx, page} = await open(chromium, {still: true});
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                step = `${theme} ${width}`;
                await page.setViewportSize({width, height: 1000});
                assert.equal((await page.goto(`${base}/admin/courses/new?theme_preview=${theme}`)).status(), 200);
                const p = picker(page, 'category');
                await p.toggle.evaluate((toggle) => window.scrollTo({top: toggle.getBoundingClientRect().top + window.scrollY - 120, behavior: 'instant'}));
                await opened(p);
                await scrollList(p.expander('A'));
                await p.expander('A').click();
                await scrollList(p.expander('A1'));
                await p.expander('A1').click();
                assert.ok(await p.option('A1x').isVisible());
                const box = await p.panel.boundingBox();
                assert.ok(box && box.x >= 0 && box.x + box.width <= width + 1, `${theme} ${width}: the open picker stays on screen`);
                assert.ok(await noSideScroll(page), `${theme} ${width} does not scroll sideways`);
                await page.screenshot({path: `${output}/${theme}-${width}.png`});
                await page.keyboard.press('Escape');
            }
        }
        await ctx.close();
    });
    await chromium.close();
    php('reset');

    fs.writeFileSync(`${output}/results.json`, JSON.stringify({results, failures}, null, 2));
    console.log(JSON.stringify({checks: results.length + failures.filter((f) => !f.startsWith('page error')).length, failures}, null, 2));
    process.exitCode = failures.length === 0 ? 0 : 1;
})();
