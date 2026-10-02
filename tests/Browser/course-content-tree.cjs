/* Course Content tree: drag and drop, keyboard and ⋯ menu moves, collapse memory, save failure,
 * "+ Add here" and the no-JavaScript fallback, in Chromium and Firefox.
 *
 * Run against the development instance with the identity and course from course-content-fixture.php.
 * The fixture state is read through the PHP container, which also resets the tree before every
 * scenario. Uses the installed Playwright; no frontend build or application dependency is added. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-content-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-course-content-tree';
fs.mkdirSync(output, {recursive: true});

const php = (...args) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/course-content-fixture.php ${args.join(' ')} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const reset = () => JSON.parse(php('reset').trim().split('\n').pop());
const results = [], failures = [];

/* The saved tree as text, e.g. "A[a1,a2],c", read from the page the server renders. */
async function savedTree(page, state) {
    await page.goto(`${base}/admin/courses/${state.course}/content`);
    return renderedTree(page, state);
}
async function renderedTree(page, state) {
    const names = Object.fromEntries(Object.entries(state.nodes).map(([name, id]) => [String(id), name]));
    return page.evaluate((names) => {
        const walk = (list) => [...list.children].filter((el) => el.matches('li[data-node-id]')).map((li) => {
            const children = li.querySelector(':scope > .cl-course-tree-children');
            const inner = children ? walk(children) : '';
            return (names[li.dataset.nodeId] || li.dataset.nodeId) + (inner ? `[${inner}]` : '');
        }).join(',');
        return walk(document.querySelector('#course-content-tree > .cl-course-tree-list'));
    }, names);
}
const row = (page, id) => page.locator(`#course-node-${id} > .cl-course-tree-row`);
const handle = (page, id) => page.locator(`#course-node-${id} > .cl-course-tree-row .cl-course-tree-handle`);

/* A real pointer drag from the handle to a fraction of the target row's height. */
async function drag(page, fromId, toId, fraction, release = true) {
    // The tree sits below the Add item panels; bring all of it into the viewport first.
    await page.locator('#course-content-tree').evaluate((tree) => tree.scrollIntoView({block: 'start', behavior: 'instant'}));
    const from = await handle(page, fromId).boundingBox();
    const to = await row(page, toId).boundingBox();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2 + 4, from.y + from.height / 2 + 4, {steps: 3});
    await page.mouse.move(to.x + to.width / 3, to.y + to.height * fraction, {steps: 12});
    await page.mouse.move(to.x + to.width / 3 + 2, to.y + to.height * fraction, {steps: 2});
    const indicator = await page.locator(`#course-node-${toId}`).getAttribute('data-drop');
    if (release) await page.mouse.up();
    return indicator;
}
async function saved(page) {
    await page.locator('.cl-course-tree-status[data-state="saved"]').waitFor({timeout: 10000});
}

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${error.message}`);
    }
}

(async () => {
    let state = JSON.parse(execFileSync('podman', ['exec', '-u', 'cattotest', container, 'cat', statePath], {encoding: 'utf8'}));
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();
        const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1280, height: 2400}});
        await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const page = await context.newPage();
        const pageErrors = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));
        const url = () => `${base}/admin/courses/${state.course}/content`;
        const n = () => state.nodes;

        await scenario('renders styled, with handles revealed', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await renderedTree(page, state), 'A[a1,a2,a3],B[b1[b1x]],c');
            await handle(page, n().a1).waitFor({state: 'visible'});
            assert.equal(await handle(page, n().a1).evaluate((el) => getComputedStyle(el).cursor), 'grab', 'The tree stylesheet is applied.');
            assert.equal(await page.locator('.cl-course-tree-children').first().evaluate((el) => getComputedStyle(el).listStyleType), 'none');
            await page.screenshot({path: `${output}/${browserName}-tree.png`, fullPage: true});
        });

        await scenario('drag before a row reorders within a level', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await drag(page, n().a3, n().a1, 0.15), 'before');
            await saved(page);
            assert.equal(await renderedTree(page, state), 'A[a3,a1,a2],B[b1[b1x]],c');
            assert.equal(await savedTree(page, state), 'A[a3,a1,a2],B[b1[b1x]],c', 'The move persisted.');
        });

        await scenario('drag after the last row moves to the bottom', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await drag(page, n().a1, n().a3, 0.85), 'after');
            await saved(page);
            assert.equal(await savedTree(page, state), 'A[a2,a3,a1],B[b1[b1x]],c');
        });

        await scenario('drag inside a section and between sections', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await drag(page, n().c, n().B, 0.5), 'inside');
            await saved(page);
            assert.equal(await renderedTree(page, state), 'A[a1,a2,a3],B[b1[b1x],c]');
            assert.equal(await drag(page, n().a2, n().b1, 0.15), 'before');
            await saved(page);
            assert.equal(await savedTree(page, state), 'A[a1,a3],B[a2,b1[b1x],c]');
        });

        await scenario('drag a section moves its whole subtree; drag out to the top level', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await drag(page, n().B, n().A, 0.15), 'before');
            await saved(page);
            assert.equal(await renderedTree(page, state), 'B[b1[b1x]],A[a1,a2,a3],c');
            assert.equal(await drag(page, n().a2, n().c, 0.85), 'after');
            await saved(page);
            assert.equal(await savedTree(page, state), 'B[b1[b1x]],A[a1,a3],c,a2');
        });

        await scenario('a nested section moves into another section', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await drag(page, n().A, n().B, 0.5), 'inside');
            await saved(page);
            assert.equal(await savedTree(page, state), 'B[b1[b1x],A[a1,a2,a3]],c');
        });

        await scenario('too deep and into-itself drops are refused', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await drag(page, n().B, n().a1, 0.5), 'invalid', 'B is three levels tall; it cannot go under a1.');
            await page.screenshot({path: `${output}/${browserName}-refused.png`});
            assert.equal(await drag(page, n().B, n().b1x, 0.5, false), 'invalid', 'A row cannot go inside itself.');
            await page.mouse.up();
            await page.waitForTimeout(300);
            assert.equal(await savedTree(page, state), 'A[a1,a2,a3],B[b1[b1x]],c', 'Nothing changed.');
        });

        await scenario('drop indicators are distinct', browserName, async () => {
            state = reset();
            await page.goto(url());
            for (const [fraction, zone] of [[0.15, 'before'], [0.5, 'inside'], [0.85, 'after']]) {
                assert.equal(await drag(page, n().c, n().A, fraction, false), zone);
                await page.screenshot({path: `${output}/${browserName}-drop-${zone}.png`, clip: await page.locator(`#course-node-${n().A}`).boundingBox()});
                await page.keyboard.press('Escape');
                await page.mouse.up();
                await page.goto(url());
            }
            assert.equal(await savedTree(page, state), 'A[a1,a2,a3],B[b1[b1x]],c');
        });

        await scenario('keyboard moves on the handle keep focus', browserName, async () => {
            state = reset();
            await page.goto(url());
            await handle(page, n().a2).focus();
            await page.keyboard.press('ArrowUp');
            await saved(page);
            assert.equal(await page.evaluate(() => document.activeElement.closest('li[data-node-id]').id), `course-node-${n().a2}`);
            await page.keyboard.press('ArrowLeft');
            await saved(page);
            await page.keyboard.press('ArrowRight');
            await saved(page);
            // Up: A[a2,a1,a3]. Left: out, beside A. Right: into the row above (A), last.
            assert.equal(await savedTree(page, state), 'A[a1,a3,a2],B[b1[b1x]],c');
        });

        await scenario('the ⋯ menu moves without a page load', browserName, async () => {
            state = reset();
            await page.goto(url());
            await page.evaluate(() => { window.__sameDocument = true; });
            await row(page, n().a1).locator('summary.cl-ui-row-trigger').click();
            await row(page, n().a1).getByRole('button', {name: 'Move to bottom'}).click();
            await saved(page);
            assert.equal(await page.evaluate(() => window.__sameDocument === true), true);
            assert.equal(await savedTree(page, state), 'A[a2,a3,a1],B[b1[b1x]],c');
        });

        await scenario('collapsed sections stay collapsed for the session', browserName, async () => {
            state = reset();
            await page.goto(url());
            const toggle = row(page, n().A).locator('.cl-course-tree-toggle');
            await toggle.click();
            assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
            assert.equal(await page.locator(`#course-branch-${n().A}`).isHidden(), true);
            await page.reload();
            assert.equal(await row(page, n().A).locator('.cl-course-tree-toggle').getAttribute('aria-expanded'), 'false');
            assert.equal(await page.locator(`#course-branch-${n().B}`).isVisible(), true, 'Branches are independent.');
            await page.getByRole('button', {name: 'Expand all'}).click();
            assert.equal(await page.locator(`#course-branch-${n().A}`).isVisible(), true);
        });

        await scenario('a failed save shows an error and restores the tree', browserName, async () => {
            state = reset();
            await page.goto(url());
            await page.route('**/content/arrange', (route) => route.abort());
            await drag(page, n().a3, n().a1, 0.15);
            await page.locator('.cl-course-tree-error:not([hidden])').waitFor();
            assert.match(await page.locator('.cl-course-tree-error').textContent(), /could not be saved/);
            assert.equal(await renderedTree(page, state), 'A[a1,a2,a3],B[b1[b1x]],c', 'The previous order is back.');
            await page.unroute('**/content/arrange');
            await page.route('**/content/arrange', (route) => route.fulfill({status: 500, contentType: 'text/html', body: '<p>Server error</p>'}));
            await drag(page, n().a3, n().a1, 0.15);
            await page.locator('.cl-course-tree-error:not([hidden])').waitFor();
            assert.equal(await renderedTree(page, state), 'A[a1,a2,a3],B[b1[b1x]],c');
            await page.unroute('**/content/arrange');
            assert.equal(await drag(page, n().a3, n().a1, 0.15), 'before', 'The restored tree still drags.');
            await saved(page);
            assert.equal(await savedTree(page, state), 'A[a3,a1,a2],B[b1[b1x]],c');
        });

        await scenario('+ Add here inserts a section at that exact place', browserName, async () => {
            state = reset();
            await page.goto(url());
            const slot = page.locator(`#course-node-${n().a1} > .cl-course-tree-add`);
            await slot.locator('summary').click();
            await slot.getByRole('link', {name: 'Add section'}).click();
            await page.waitForURL(/insert_index=1/);
            await page.locator('#new-section-title').fill('Inserted');
            await page.locator('#add-section').getByRole('button', {name: 'Save new section'}).click();
            await page.waitForURL(/\/content$/);
            const tree = await renderedTree(page, state);
            assert.match(tree, /^A\[a1,\d+,a2,a3\]/, tree);
        });

        assert.deepEqual(pageErrors, [], `No script errors in ${browserName}.`);
        await browser.close();
    }

    await scenario('without JavaScript the ⋯ menu and Move into page work', 'chromium-nojs', async () => {
        state = reset();
        const browser = await playwright.chromium.launch();
        const context = await browser.newContext({ignoreHTTPSErrors: true, javaScriptEnabled: false});
        await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const page = await context.newPage();
        await page.goto(`${base}/admin/courses/${state.course}/content`);
        assert.equal(await handle(page, state.nodes.a1).isHidden(), true, 'No drag handle without JavaScript.');
        await row(page, state.nodes.a3).locator('summary.cl-ui-row-trigger').click();
        await row(page, state.nodes.a3).getByRole('button', {name: 'Move to top'}).click();
        await page.waitForURL(/\/content$/);
        assert.equal(await renderedTree(page, state), 'A[a3,a1,a2],B[b1[b1x]],c');
        await row(page, state.nodes.c).locator('summary.cl-ui-row-trigger').click();
        await row(page, state.nodes.c).getByRole('link', {name: 'Move into section…'}).click();
        await page.getByLabel('Section · Section B').check();
        await page.getByRole('button', {name: 'Move here'}).click();
        await page.waitForURL(/\/content$/);
        assert.equal(await renderedTree(page, state), 'A[a3,a1,a2],B[b1[b1x],c]');
        await browser.close();
    });

    reset();
    console.log(results.map((line) => `ok  ${line}`).join('\n'));
    if (failures.length) {
        console.error(failures.map((line) => `FAIL ${line}`).join('\n'));
        process.exit(1);
    }
    console.log(`All ${results.length} Course Content tree checks passed. Screenshots: ${output}`);
})().catch((error) => { console.error(error); process.exit(1); });
