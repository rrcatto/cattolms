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

        const modal = () => page.locator('#course-content-insert');
        const modalBody = () => page.locator('#course-content-insert-body');
        // Opens "+ Add here" at a slot and chooses one of its actions; the form loads in the modal.
        async function insertAt(slot, action) {
            await slot.locator('summary').click();
            await slot.getByRole('link', {name: action}).click();
            await modal().waitFor({state: 'visible'});
            await modalBody().locator('.cl-course-insert-panel, form').first().waitFor();
        }
        const afterRow = (id) => page.locator(`#course-node-${id} > .cl-course-tree-add`);
        const firstInside = (id) => page.locator(`#course-branch-${id} > li.cl-course-tree-add`);
        const courseStart = () => page.locator('#course-content-tree > .cl-course-tree-list > li.cl-course-tree-add');

        await scenario('the top Add item area is gone; + Add here is the way in', browserName, async () => {
            state = reset();
            await page.goto(url());
            assert.equal(await page.locator('#add-item, #add-new, #add-existing, #add-section').count(), 0);
            assert.ok(await page.locator('.cl-course-tree-add').count() >= 9, 'A slot at the start of each list and after each row.');
            assert.equal(await modal().isHidden(), true, 'The insert modal starts closed.');
        });

        await scenario('Add section in the modal lands between rows, in place, keeping scroll and collapsed sections', browserName, async () => {
            state = reset();
            await page.goto(url());
            await page.evaluate(() => { window.__sameDocument = true; });
            await row(page, n().B).locator('.cl-course-tree-toggle').click();
            await page.locator('#course-content-tree').evaluate((tree) => tree.scrollIntoView({block: 'start', behavior: 'instant'}));
            const scrollBefore = await page.evaluate(() => window.scrollY);
            await insertAt(afterRow(n().a1), 'Add section');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'insert-section-title', 'The first field takes the focus.');
            assert.match(await modalBody().innerText(), /inside “Section A”, as row 2/);
            await page.screenshot({path: `${output}/${browserName}-modal-section.png`});
            await page.locator('#insert-section-title').fill('Inserted section');
            await modalBody().getByRole('button', {name: 'Save new section'}).click();
            await modal().waitFor({state: 'hidden'});
            await saved(page);
            assert.match(await renderedTree(page, state), /^A\[a1,\d+,a2,a3\],B\[b1\[b1x\]\],c$/);
            assert.equal(await page.evaluate(() => window.__sameDocument === true), true, 'No page load.');
            assert.equal(await row(page, n().B).locator('.cl-course-tree-toggle').getAttribute('aria-expanded'), 'false', 'Collapsed sections stay collapsed.');
            assert.ok(Math.abs(await page.evaluate(() => window.scrollY) - scrollBefore) < 120, 'The editor does not jump to the top.');
            assert.equal(await page.evaluate(() => document.activeElement.closest('li[data-node-id]')?.querySelector('.cl-course-tree-name')?.textContent), 'Inserted section', 'The new row takes the focus.');
            assert.match(await savedTree(page, state), /^A\[a1,\d+,a2,a3\],B\[b1\[b1x\]\],c$/, 'It persisted.');
            await row(page, n().B).locator('.cl-course-tree-toggle').click();
        });

        await scenario('Add existing item in the modal lands at the start of the course', browserName, async () => {
            state = reset();
            await page.goto(url());
            await insertAt(courseStart(), 'Add existing item');
            await modalBody().locator('#insert-existing-search').fill('Item b1x');
            await modalBody().getByRole('button', {name: 'Search items'}).click();
            // The full list already contains the item; wait for the filtered list to replace it.
            await page.waitForFunction(() => document.querySelectorAll('#insert-existing-item option').length === 2);
            await modalBody().locator('#insert-existing-item').selectOption({label: (await modalBody().locator('#insert-existing-item option', {hasText: 'Item b1x'}).first().textContent()).trim()});
            await modalBody().getByRole('button', {name: 'Add to course'}).click();
            await modal().waitFor({state: 'hidden'});
            await saved(page);
            const first = page.locator('#course-content-tree > .cl-course-tree-list > li[data-node-id]').first();
            assert.equal(await first.locator(':scope > .cl-course-tree-row .cl-course-tree-name').textContent(), 'Item b1x', 'The shared item is placed first.');
            assert.match(await savedTree(page, state), /^\d+,A\[a1,a2,a3\],B\[b1\[b1x\]\],c$/);
        });

        await scenario('Create new item in the modal: a refused key keeps the entry, then it lands inside the section', browserName, async () => {
            state = reset();
            await page.goto(url());
            await insertAt(firstInside(n().B), 'Create new item');
            await modalBody().getByLabel('HTML lesson').check();
            await modalBody().getByRole('button', {name: 'Continue'}).click();
            await page.locator('#item-key').waitFor();
            await page.waitForFunction(() => document.querySelectorAll('#course-content-insert-body .ck-editor').length === 2);
            await page.evaluate(() => { for (const [element, editor] of window.CattoLearningEditors.instances) if (element.id === 'item-source') editor.setData('<p>Kept lesson body</p>'); });
            const takenKey = await page.evaluate((id) => document.querySelector(`#course-node-${id} .cl-course-tree-meta code`).textContent, n().a1);
            await page.locator('#item-key').fill(takenKey);
            await page.locator('#item-title').fill('Created in the modal');
            await modalBody().getByRole('button', {name: 'Create and add to course'}).click();
            await modalBody().getByText('Another Course Item already uses that key.').waitFor();
            await page.waitForFunction(() => document.querySelectorAll('#course-content-insert-body .ck-editor').length === 2);
            assert.equal(await page.locator('#item-title').inputValue(), 'Created in the modal', 'The title is kept.');
            assert.match(await page.evaluate(() => { for (const [element, editor] of window.CattoLearningEditors.instances) if (element.id === 'item-source') return editor.getData(); return ''; }), /Kept lesson body/, 'The rich text is kept.');
            assert.equal(await page.locator('#course-content-insert-body textarea.cl-rich-editor').evaluateAll((all) => all.filter((e) => getComputedStyle(e).display !== 'none').length), 0, 'Each rich-text field shows its editor only.');
            await page.screenshot({path: `${output}/${browserName}-modal-error.png`});
            await page.locator('#item-key').fill(`created-${Date.now()}`);
            await modalBody().getByRole('button', {name: 'Create and add to course'}).click();
            await modal().waitFor({state: 'hidden'});
            await saved(page);
            const created = page.locator(`#course-branch-${n().B} > li[data-node-id]`).first();
            assert.equal(await created.locator(':scope > .cl-course-tree-row .cl-course-tree-name').textContent(), 'Created in the modal');
            assert.match(await savedTree(page, state), /^A\[a1,a2,a3\],B\[\d+,b1\[b1x\]\],c$/, 'Created, attached and placed in one step.');
        });

        await scenario('Escape, Cancel and the close button change nothing', browserName, async () => {
            state = reset();
            await page.goto(url());
            await insertAt(afterRow(n().a2), 'Add section');
            await page.locator('#insert-section-title').fill('Never saved');
            await page.keyboard.press('Escape');
            await modal().waitFor({state: 'hidden'});
            await insertAt(afterRow(n().c), 'Add existing item');
            await modalBody().getByRole('button', {name: 'Cancel'}).click();
            await modal().waitFor({state: 'hidden'});
            await insertAt(afterRow(n().a1), 'Create new item');
            assert.equal(await afterRow(n().a1).locator('details').getAttribute('open'), null, 'Choosing an action closes the + Add here menu.');
            await modal().locator('.cl-ui-close').click();
            await modal().waitFor({state: 'hidden'});
            assert.equal(await savedTree(page, state), 'A[a1,a2,a3],B[b1[b1x]],c');
        });

        await scenario('an inserted row can be dragged straight away', browserName, async () => {
            state = reset();
            await page.goto(url());
            await insertAt(afterRow(n().a3), 'Add section');
            await page.locator('#insert-section-title').fill('Then dragged');
            await modalBody().getByRole('button', {name: 'Save new section'}).click();
            await modal().waitFor({state: 'hidden'});
            await saved(page);
            const id = await page.evaluate(() => document.activeElement.closest('li[data-node-id]').dataset.nodeId);
            assert.equal(await drag(page, id, n().a1, 0.15), 'before');
            await saved(page);
            assert.match(await savedTree(page, state), /^A\[\d+,a1,a2,a3\],B\[b1\[b1x\]\],c$/);
        });

        await scenario('+ Add here keeps working after every tree refresh, in one page load', browserName, async () => {
            state = reset();
            await page.goto(url());
            await page.evaluate(() => { window.__sameDocument = true; });
            // Waits for the new row itself: the previous "Saved" status can still be showing.
            const addSection = async (slot, title) => {
                const rows = await page.locator('#course-content-tree li[data-node-id]').count();
                await insertAt(slot, 'Add section');
                await page.locator('#insert-section-title').waitFor();
                await page.locator('#insert-section-title').fill(title);
                await modalBody().getByRole('button', {name: 'Save new section'}).click();
                await modal().waitFor({state: 'hidden'});
                await page.waitForFunction((count) => document.querySelectorAll('#course-content-tree li[data-node-id]').length === count, rows + 1);
            };
            await addSection(afterRow(n().a1), 'First added');
            await addSection(afterRow(n().a3), 'Second added');
            const twice = await renderedTree(page, state);
            assert.match(twice, /^A\[a1,\d+,a2,a3,\d+\],B\[b1\[b1x\]\],c$/, `Two sections in a row, no reload: ${twice}`);
            assert.equal(await drag(page, n().c, n().A, 0.15), 'before');
            await saved(page);
            await addSection(afterRow(n().c), 'After a drag');
            await row(page, n().a2).locator('summary.cl-ui-row-trigger').click();
            await row(page, n().a2).getByRole('button', {name: 'Move to top'}).click();
            await saved(page);
            await insertAt(courseStart(), 'Add existing item');
            await modalBody().locator('#insert-existing-item').waitFor();
            await modalBody().getByRole('button', {name: 'Cancel'}).click();
            await modal().waitFor({state: 'hidden'});
            await page.route('**/content/arrange', (route) => route.abort());
            await drag(page, n().a3, n().a2, 0.15);
            await page.locator('.cl-course-tree-error:not([hidden])').waitFor();
            await page.unroute('**/content/arrange');
            await insertAt(afterRow(n().b1), 'Create new item');
            await modalBody().locator('input[name="type"]').first().waitFor();
            await modalBody().getByRole('button', {name: 'Cancel'}).click();
            await modal().waitFor({state: 'hidden'});
            assert.equal(await page.evaluate(() => window.__sameDocument === true), true, 'All in one page load.');
            assert.match(await savedTree(page, state), /^c,\d+,A\[a2,a1,\d+,a3,\d+\],B\[b1\[b1x\]\]$/);
        });

        await scenario('an empty course still offers + Add here', browserName, async () => {
            state = JSON.parse(php('empty').trim().split('\n').pop());
            await page.goto(url());
            assert.match(await page.locator('#course-content-tree').innerText(), /No Course Content yet/);
            await insertAt(courseStart(), 'Add section');
            await page.locator('#insert-section-title').fill('First section');
            await modalBody().getByRole('button', {name: 'Save new section'}).click();
            await modal().waitFor({state: 'hidden'});
            await saved(page);
            assert.equal(await page.locator('#course-content-tree > .cl-course-tree-list > li[data-node-id]').count(), 1);
            assert.match(await savedTree(page, state), /^\d+$/);
        });

        assert.deepEqual(pageErrors, [], `No script errors in ${browserName}.`);
        await browser.close();
    }

    await scenario('without JavaScript the ⋯ menu, Move into page and + Add here work', 'chromium-nojs', async () => {
        state = reset();
        const browser = await playwright.chromium.launch();
        const context = await browser.newContext({ignoreHTTPSErrors: true, javaScriptEnabled: false});
        await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const page = await context.newPage();
        await page.goto(`${base}/admin/courses/${state.course}/content`);
        assert.equal(await handle(page, state.nodes.a1).isHidden(), true, 'No drag handle without JavaScript.');
        assert.equal(await page.locator('#course-content-insert').isHidden(), true, 'The empty insert modal is not shown without JavaScript.');
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
        const slot = page.locator(`#course-node-${state.nodes.a1} > .cl-course-tree-add`);
        await slot.locator('summary').click();
        await slot.getByRole('link', {name: 'Add section'}).click();
        await page.waitForURL(/content\/insert\/section/);
        assert.equal(await page.locator('#course-content-insert').count(), 0, 'The form page has no modal.');
        await page.locator('#insert-section-title').fill('Added without script');
        await page.locator('#insert-section-title').press('Enter');
        await page.waitForURL(/\/content#course-node-\d+$/);
        assert.match(await renderedTree(page, state), /^A\[a3,a1,\d+,a2\],B\[b1\[b1x\],c\]$/, 'Without JavaScript + Add here opens the form on its own page.');
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
