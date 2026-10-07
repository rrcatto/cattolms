/* The draggable category tree on /admin/courses/categories, in Chromium and Firefox, without
 * JavaScript, and across every theme at desktop and phone widths.
 *
 * The page opens on the main categories with every branch closed; branches open and close
 * independently and are remembered. Dragging by the handle reorders main categories, puts a
 * category inside another (a closed one too), moves it back out, moves a sub-subcategory between
 * subcategories and a whole subtree at once; a fourth level is never offered and a forged one is
 * refused by the server with the tree unchanged. The counts above the tree follow a move between
 * levels. The keyboard moves on a focused handle and the ⋯ menu move without dragging. Add category
 * and Add subcategory create in the page's modal, Manage edits and returns to the tree. Without
 * JavaScript the disclosure buttons, the ⋯ menu, Move into, creating and Manage all work as
 * ordinary requests.
 *
 * Run against the development instance with category-tree-fixture.php, which builds
 *   A > A1 > A1x, A > A2      B > B1      G      D > D1 > D1x
 * after the shipped taxonomy and rebuilds it before every scenario. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-category-tree-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-category-tree';
const root = path.resolve(__dirname, '..', '..');
fs.mkdirSync(output, {recursive: true});

const php = (command) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `cd /home/cattotest/code/cattolms-v0.8 && SHELL_VERBOSITY=-1 php tests/Browser/category-tree-fixture.php ${command} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
let state = JSON.parse(execFileSync('podman', ['exec', container, 'cat', statePath], {encoding: 'utf8'}));
const reset = () => { state = JSON.parse(php('reset').trim().split('\n').pop()); };
const tokens = [...state.tokens];
const token = () => tokens.shift();
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => `${t.slug}-v${t.version}`);
const url = `${base}/admin/courses/categories`;
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
async function open(browser, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: options.width || 1280, height: options.height || 2400}, javaScriptEnabled: options.js !== false, reducedMotion: options.js === false ? 'reduce' : 'no-preference'});
    await ctx.addCookies([{name: 'catto_learning_session', value: token(), url: base, secure: true}]);
    const page = await ctx.newPage();
    page.on('pageerror', (error) => failures.push(`page error: ${error.message}`));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const ids = () => state.ids;
const keyOf = () => Object.fromEntries(Object.entries(state.ids).map(([key, id]) => [String(id), key]));
const row = (page, key) => page.locator(`#category-${ids()[key]} > .cl-tree-row`);
const handle = (page, key) => row(page, key).locator('.cl-tree-handle');
const toggle = (page, key) => row(page, key).locator('.cl-tree-toggle');
const branch = (page, key) => page.locator(`#category-branch-${ids()[key]}`);

/* The test categories as text, e.g. "A[A1[A1x],A2],B[B1],G,D[D1[D1x]]", in the order the page shows them. */
async function rendered(page) {
    return page.evaluate((names) => {
        const walk = (list) => [...list.children].filter((li) => li.matches('li[data-node-id]')).map((li) => {
            const children = li.querySelector(':scope > .cl-tree-children');
            const inner = children ? walk(children) : '';
            const name = names[li.dataset.nodeId];
            return name ? name + (inner ? `[${inner}]` : '') : inner;
        }).filter(Boolean).join(',');
        return walk(document.querySelector('#category-tree > .cl-tree-list'));
    }, keyOf());
}
async function stored(page) {
    await page.goto(url);
    return rendered(page);
}
async function expand(page, ...keys) {
    for (const key of keys) {
        if (await toggle(page, key).getAttribute('aria-expanded') !== 'true') await toggle(page, key).click();
    }
}
/* A real pointer drag from the handle to a fraction of the target row's height. */
async function drag(page, fromKey, toKey, fraction, release = true) {
    step = `drag ${fromKey} to ${toKey} at ${fraction}`;
    await row(page, toKey).scrollIntoViewIfNeeded();
    await handle(page, fromKey).scrollIntoViewIfNeeded();
    const from = await handle(page, fromKey).boundingBox();
    const to = await row(page, toKey).boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 4, from.y + from.height / 2 + 4, {steps: 3});
    await page.mouse.move(to.x + to.width / 4, to.y + to.height * fraction, {steps: 12});
    await page.mouse.move(to.x + to.width / 4 + 2, to.y + to.height * fraction, {steps: 2});
    const indicator = await page.locator(`#category-${ids()[toKey]}`).getAttribute('data-drop');
    if (release) await page.mouse.up();
    return indicator;
}
async function saved(page) {
    await page.locator('.cl-tree-status[data-state="saved"]').waitFor({timeout: 10000});
}
const stat = (page, label) => page.locator('#category-statistics .cl-ui-stat-card').filter({has: page.locator('.cl-ui-stat-label', {hasText: new RegExp(`^${label}$`)})}).locator('.cl-ui-stat-value').textContent().then(Number);
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
const START = 'A[A1[A1x],A2],B[B1],G,D[D1[D1x]]';

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();
        const {ctx, page} = await open(browser);
        // Branch memory is per browser session; each scenario starts from the closed tree.
        const fresh = async () => { reset(); await page.goto(url); await page.evaluate(() => sessionStorage.clear()); await page.goto(url); };

        await scenario('the page opens on the main categories with every branch closed', browserName, async () => {
            await fresh();
            assert.equal(await rendered(page), START);
            await handle(page, 'A').waitFor({state: 'visible'});
            assert.equal(await page.locator('.cl-tree-children:visible').count(), 0, 'No child list is shown.');
            assert.equal(await page.locator('.cl-tree-toggle[aria-expanded="true"]').count(), 0);
            assert.ok(await row(page, 'G').isVisible() && await row(page, 'A').isVisible(), 'Main categories are listed.');
            assert.equal(await row(page, 'A1').isVisible(), false, 'Subcategories wait inside their closed branch.');
            assert.equal(await toggle(page, 'G').count(), 0, 'A category with nothing inside has no disclosure button.');
            assert.equal(await handle(page, 'A').evaluate((el) => getComputedStyle(el).cursor), 'grab', 'The shared tree styles apply.');
            await page.screenshot({path: `${output}/${browserName}-closed.png`, fullPage: true});
        });

        await scenario('branches open and close independently and are remembered', browserName, async () => {
            await fresh();
            await toggle(page, 'A').click();
            assert.ok(await row(page, 'A1').isVisible());
            await toggle(page, 'B').click();
            assert.ok(await row(page, 'B1').isVisible() && await row(page, 'A1').isVisible(), 'Opening B leaves A open.');
            await toggle(page, 'A').click();
            assert.equal(await row(page, 'A1').isVisible(), false);
            assert.ok(await row(page, 'B1').isVisible(), 'Closing A leaves B open.');
            assert.deepEqual([await toggle(page, 'A').getAttribute('aria-expanded'), await toggle(page, 'B').getAttribute('aria-expanded')], ['false', 'true']);
            await page.reload();
            assert.ok(await row(page, 'B1').isVisible(), 'The open branch is remembered for the session.');
            assert.equal(await row(page, 'A1').isVisible(), false);
        });

        await scenario('dragging reorders main categories', browserName, async () => {
            await fresh();
            assert.equal(await drag(page, 'G', 'A', 0.15), 'before');
            await saved(page);
            assert.equal(await rendered(page), 'G,A[A1[A1x],A2],B[B1],D[D1[D1x]]');
            assert.equal(await stored(page), 'G,A[A1[A1x],A2],B[B1],D[D1[D1x]]', 'The order is saved.');
        });

        await scenario('dragging puts a category inside another and back out; the counts follow', browserName, async () => {
            await fresh();
            const [main, sub] = [await stat(page, 'Main categories'), await stat(page, 'Subcategories')];
            assert.equal(await drag(page, 'G', 'B', 0.5), 'inside');
            await saved(page);
            assert.equal(await rendered(page), 'A[A1[A1x],A2],B[B1,G],D[D1[D1x]]');
            assert.ok(await row(page, 'G').isVisible(), 'The destination branch stays open so the moved category can be seen.');
            assert.deepEqual([await stat(page, 'Main categories'), await stat(page, 'Subcategories')], [main - 1, sub + 1], 'The counts above the tree follow the move.');
            assert.equal(await drag(page, 'G', 'D', 0.85), 'after');
            await saved(page);
            assert.equal(await stored(page), START.replace(',G,', ',').concat(',G'), 'Moved back out to the top level, after D.');
        });

        await scenario('dragging moves a sub-subcategory between subcategories and a whole subtree', browserName, async () => {
            await fresh();
            await expand(page, 'A', 'A1', 'B', 'D');
            assert.equal(await drag(page, 'A1x', 'B1', 0.5), 'inside');
            await saved(page);
            assert.equal(await rendered(page), 'A[A1,A2],B[B1[A1x]],G,D[D1[D1x]]');
            assert.equal(await drag(page, 'D1', 'A', 0.5), 'inside');
            await saved(page);
            assert.equal(await stored(page), 'A[A1,A2,D1[D1x]],B[B1[A1x]],G,D', 'D1 moved with D1x inside it.');
        });

        await scenario('a fourth level is never offered and a forged one is refused', browserName, async () => {
            await fresh();
            await expand(page, 'A', 'B', 'D');
            step = 'D1 is two levels tall';
            assert.notEqual(await drag(page, 'D1', 'B1', 0.5, false), 'inside', 'Inside a subcategory would make D1x a fourth level.');
            await page.mouse.move(5, 5);
            await page.mouse.up();
            step = 'A onto its own subcategory';
            assert.equal(await drag(page, 'A', 'A1', 0.5, false), 'invalid', 'A category cannot go inside its own subcategory.');
            await page.mouse.move(5, 5);
            await page.mouse.up();
            step = 'forged';
            const answer = await page.evaluate(async ({from, into}) => {
                const body = new FormData();
                body.append('csrf', document.querySelector('input[name="csrf"]').value);
                body.append('parent_id', String(into));
                body.append('index', '');
                const response = await fetch(`/admin/courses/categories/${from}/arrange`, {method: 'POST', body, headers: {'HX-Request': 'true'}, credentials: 'same-origin'});
                return {status: response.status, text: await response.text()};
            }, {from: ids().D1, into: ids().B1});
            assert.equal(answer.status, 422);
            assert.match(answer.text, /three levels deep/);
            assert.equal(await stored(page), START, 'The tree is unchanged.');
        });

        await scenario('dropping onto a closed category puts it inside and opens it', browserName, async () => {
            await fresh();
            assert.equal(await toggle(page, 'D').getAttribute('aria-expanded'), 'false');
            assert.equal(await drag(page, 'G', 'D', 0.5), 'inside');
            await saved(page);
            assert.equal(await toggle(page, 'D').getAttribute('aria-expanded'), 'true');
            assert.ok(await row(page, 'G').isVisible());
            assert.equal(await stored(page), 'A[A1[A1x],A2],B[B1],D[D1[D1x],G]');
        });

        await scenario('the keyboard and the ⋯ menu move without dragging', browserName, async () => {
            await fresh();
            step = 'ArrowUp';
            await handle(page, 'B').focus();
            await page.keyboard.press('ArrowUp');
            await saved(page);
            assert.equal(await rendered(page), 'B[B1],A[A1[A1x],A2],G,D[D1[D1x]]');
            step = 'End';
            await page.keyboard.press('End');
            await saved(page);
            assert.equal(await rendered(page), 'A[A1[A1x],A2],G,D[D1[D1x]],B[B1]');
            step = 'ArrowRight';
            await page.keyboard.press('ArrowRight');
            await saved(page);
            assert.equal(await rendered(page), 'A[A1[A1x],A2],G,D[D1[D1x],B[B1]]', 'Into the category above, last.');
            step = 'ArrowLeft';
            await page.keyboard.press('ArrowLeft');
            await saved(page);
            assert.equal(await rendered(page), 'A[A1[A1x],A2],G,D[D1[D1x]],B[B1]', 'Out, just after its parent.');
            assert.equal(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('cl-tree-handle')), true, 'The handle keeps the focus.');
            step = 'menu';
            await row(page, 'G').locator('summary').click();
            await row(page, 'G').getByRole('button', {name: 'Move to top'}).click();
            await saved(page);
            assert.equal((await stored(page)).split(',')[0], 'G');
            const first = await page.locator('#category-tree > .cl-tree-list > li').first().getAttribute('data-node-id');
            assert.equal(first, String(ids().G), 'Move to top puts it above the shipped categories too.');
        });

        await scenario('Add category and Add subcategory create in the modal', browserName, async () => {
            await fresh();
            step = 'Add category';
            // The modal already holds the main-category form; wait for the one the link loads.
            await Promise.all([page.waitForResponse((response) => response.url().endsWith('/admin/courses/categories/new')), page.getByRole('link', {name: 'Add category'}).click()]);
            await page.locator('#category-create').waitFor({state: 'visible'});
            await page.locator('#category-create-body input[name="name"]').fill(`Tree New Root ${state.suffix}`);
            await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('#category-create-body').getByRole('button', {name: 'Add category'}).click()]);
            assert.ok(await page.getByText(`The course category “Tree New Root ${state.suffix}” was created.`).first().isVisible());
            const last = page.locator('#category-tree > .cl-tree-list > li').last();
            assert.ok((await last.locator(':scope > .cl-tree-row .cl-tree-name').textContent()).includes('Tree New Root'), 'It is the last main category.');
            step = 'Add subcategory';
            await row(page, 'B').getByRole('link', {name: `Add a subcategory to ${state.names.B}`}).click();
            await page.locator(`#category-create-body form[action="/admin/courses/categories/${ids().B}/children"]`).waitFor();
            assert.ok((await page.locator('#category-create-body').textContent()).includes(`inside ${state.names.B}`));
            await page.locator('#category-create-body input[name="name"]').fill(`Tree New Sub ${state.suffix}`);
            await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('#category-create-body').getByRole('button', {name: 'Add subcategory'}).click()]);
            const sub = page.locator(`#category-branch-${ids().B} > li`).last();
            assert.ok(await sub.isVisible(), 'Its branch is open so the new category shows.');
            assert.equal(await sub.getAttribute('data-depth'), '2');
            step = 'sub-subcategory';
            await row(page, 'B1').getByRole('link', {name: `Add a subcategory to ${state.names.B1}`}).click();
            await page.locator(`#category-create-body form[action="/admin/courses/categories/${ids().B1}/children"]`).waitFor();
            await page.locator('#category-create-body input[name="name"]').fill(`Tree New Leaf ${state.suffix}`);
            await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.locator('#category-create-body').getByRole('button', {name: 'Add subcategory'}).click()]);
            const leaf = page.locator(`#category-branch-${ids().B1} > li`).last();
            assert.ok(await leaf.isVisible());
            assert.equal(await leaf.getAttribute('data-depth'), '3');
            assert.equal(await leaf.locator(':scope > .cl-tree-row').getByRole('link', {name: /Add a subcategory/}).count(), 0, 'A sub-subcategory offers no Add subcategory.');
        });

        await scenario('Manage edits a category and returns to it in the tree', browserName, async () => {
            await fresh();
            await expand(page, 'A');
            await Promise.all([page.waitForNavigation(), row(page, 'A1').getByRole('link', {name: `Manage ${state.names.A1}`}).click()]);
            assert.match(page.url(), new RegExp(`/admin/courses/categories/${ids().A1}$`));
            assert.equal(await page.locator('[name="parent_id"]').count(), 0, 'Where it sits is changed in the tree.');
            assert.ok(await page.getByText(`${state.names.A} › ${state.names.A1}`).first().isVisible());
            await page.locator('input[name="name"]').fill(`Tree Renamed ${state.suffix}`);
            await Promise.all([page.waitForNavigation({waitUntil: 'domcontentloaded'}), page.getByRole('button', {name: 'Save category'}).click()]);
            assert.match(page.url(), new RegExp(`/admin/courses/categories\\?reveal=${ids().A1}#category-${ids().A1}$`));
            assert.ok(await row(page, 'A1').isVisible(), 'Its branch is open.');
            assert.ok((await row(page, 'A1').textContent()).includes(`Tree Renamed ${state.suffix}`));
        });

        await ctx.close();
        await browser.close();
    }

    const chromium = await playwright.chromium.launch();
    await scenario('without JavaScript the tree opens, moves, creates and manages by ordinary requests', 'chromium-nojs', async () => {
        reset();
        const {ctx, page} = await open(chromium, {js: false});
        await page.goto(url);
        step = 'closed';
        assert.equal(await row(page, 'A1').isVisible(), false);
        step = 'open A';
        await Promise.all([page.waitForNavigation(), toggle(page, 'A').click()]);
        assert.ok(await row(page, 'A1').isVisible());
        step = 'open B';
        await Promise.all([page.waitForNavigation(), toggle(page, 'B').click()]);
        assert.ok(await row(page, 'A1').isVisible() && await row(page, 'B1').isVisible(), 'Both stay open.');
        step = 'menu move';
        await row(page, 'G').locator('summary').click();
        await Promise.all([page.waitForNavigation(), row(page, 'G').getByRole('button', {name: 'Move up'}).click()]);
        assert.equal(await rendered(page), 'A[A1[A1x],A2],G,B[B1],D[D1[D1x]]');
        step = 'Move into';
        await row(page, 'G').locator('summary').click();
        await Promise.all([page.waitForNavigation(), row(page, 'G').getByRole('link', {name: 'Move into…'}).click()]);
        await page.getByLabel(`${state.names.D}`, {exact: true}).check();
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Move here'}).click()]);
        assert.equal(await rendered(page), 'A[A1[A1x],A2],B[B1],D[D1[D1x],G]');
        assert.ok(await row(page, 'G').isVisible(), 'The tree opens at the moved category.');
        step = 'Add subcategory page';
        await Promise.all([page.waitForNavigation(), row(page, 'B').getByRole('link', {name: `Add a subcategory to ${state.names.B}`}).click()]);
        assert.match(page.url(), new RegExp(`/admin/courses/categories/${ids().B}/new$`));
        await page.locator('input[name="name"]').fill(`Tree NoJS Sub ${state.suffix}`);
        await Promise.all([page.waitForNavigation(), page.getByRole('button', {name: 'Add subcategory'}).click()]);
        assert.ok((await page.locator(`#category-branch-${ids().B}`).textContent()).includes(`Tree NoJS Sub ${state.suffix}`));
        step = 'Add category inline';
        await page.locator('#category-create-body input[name="name"]').fill(`Tree NoJS Root ${state.suffix}`);
        await Promise.all([page.waitForNavigation(), page.locator('#category-create-body').getByRole('button', {name: 'Add category'}).click()]);
        assert.ok((await page.locator('#category-tree > .cl-tree-list > li').last().textContent()).includes(`Tree NoJS Root ${state.suffix}`));
        step = 'Manage';
        await Promise.all([page.waitForNavigation(), row(page, 'A').getByRole('link', {name: `Manage ${state.names.A}`}).click()]);
        assert.ok(await page.getByRole('button', {name: 'Save category'}).isVisible());
        await ctx.close();
    });

    await scenario('the tree fits every theme at desktop and phone widths', 'chromium', async () => {
        reset();
        const {ctx, page} = await open(chromium);
        for (const theme of themes) {
            for (const width of [1440, 390]) {
                step = `${theme} ${width}`;
                await page.setViewportSize({width, height: 1400});
                const response = await page.goto(`${url}?theme_preview=${theme}&open[]=${ids().A}&open[]=${ids().A1}`);
                assert.equal(response.status(), 200);
                assert.ok(await row(page, 'A1x').isVisible(), 'Open branches render open.');
                assert.ok(await noSideScroll(page), `${theme} ${width} does not scroll sideways`);
                const handleBox = await handle(page, 'A1x').boundingBox();
                assert.ok(handleBox && handleBox.width >= 30, 'The handle stays usable.');
                await page.screenshot({path: `${output}/${theme}-${width}.png`, fullPage: false});
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
