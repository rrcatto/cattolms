/* A refused form in a window comes back as it was typed and chosen, its window open (2026-11-07: a refused New course
   came back empty; every create form now keeps what was sent, Http\TypedForm). Administration > People's Add person, with
   an address that already has an account, in Chromium and Firefox and without JavaScript:
   - with JavaScript the page comes back with the refusal and the window open over it, every field as it was typed, the
     role as it was chosen and the notification still ticked, and the window still closes;
   - without JavaScript the window is a section of the page, holding the same;
   - the page coming back after that is empty again: what was typed shows once;
   - nothing is made.
   The New course form and its category are checked the same way in category-picker.cjs, and every create form on the
   server in RefusedFormsKeepWhatWasTypedIntegrationTest.
   Fixture: php tests/Browser/refused-forms-fixture.php create|cleanup (in env_php_1). */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const {execFileSync} = require('node:child_process');
const {open: arrive, settle} = require('./pace.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(execFileSync('podman', ['exec', 'env_php_1', 'cat', '/tmp/catto-refused-forms-state.json'], {encoding: 'utf8'}));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: !!ok, detail}); console.log(ok ? 'PASS' : 'FAIL', name, ok ? '' : detail); };
const windowOf = (page) => page.locator('#quickAddPersonModal');
const field = (page, name) => windowOf(page).locator(`[name="${name}"]`);
const notice = (page) => windowOf(page).locator('input[name="send_notice"]');

async function refuse(page, js) {
    await arrive(page, `${base}/admin/people`);
    if (js) {
        await page.locator('[data-open-modal="quickAddPersonModal"]').first().click();
        await windowOf(page).waitFor({state: 'visible'});
    }
    await field(page, 'first_name').fill(state.first);
    await field(page, 'last_name').fill(state.last);
    await field(page, 'email').fill(state.taken_email);
    await field(page, 'role').selectOption('COURSE_EDITOR');
    await windowOf(page).locator('label.cl-ui-picture-choice-item:has(input[name="send_notice"])').click();
    await Promise.all([page.waitForNavigation(), windowOf(page).getByRole('button', {name: 'Create person'}).click()]);
    await settle(page);
}

async function run(browserName, js) {
    const name = js ? browserName : `${browserName} without JavaScript`;
    const browser = await playwright[browserName].launch();
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, javaScriptEnabled: js, reducedMotion: 'reduce'});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    try {
        await refuse(page, js);
        const said = (await page.locator('[data-flash-message]').allTextContents()).join(' ');
        check(`${name}: the refusal is said`, new URL(page.url()).pathname === '/admin/people' && said.includes('That email address already has an account.'), `${page.url()} ${said}`);
        if (js) check(`${name}: the window is open over the page`, await windowOf(page).isVisible() && await windowOf(page).evaluate((element) => element.classList.contains('open')));
        else check(`${name}: the window is a section of the page`, await windowOf(page).isVisible());
        check(`${name}: every field is as it was typed`, await field(page, 'first_name').inputValue() === state.first && await field(page, 'last_name').inputValue() === state.last
            && await field(page, 'email').inputValue() === state.taken_email, `${await field(page, 'first_name').inputValue()} ${await field(page, 'last_name').inputValue()} ${await field(page, 'email').inputValue()}`);
        check(`${name}: the role is as it was chosen and the notification still ticked`, await field(page, 'role').inputValue() === 'COURSE_EDITOR' && await notice(page).isChecked());
        if (js) {
            await windowOf(page).locator('.cl-ui-modal-head [data-close-modal]').click();
            check(`${name}: the window still closes`, !(await windowOf(page).isVisible()));
        }
        await arrive(page, `${base}/admin/people`);
        check(`${name}: what was typed shows once`, await field(page, 'first_name').inputValue() === '' && (!js || !(await windowOf(page).isVisible())));
        check(`${name}: no script error`, errors.length === 0, errors.join(' | '));
    } catch (error) {
        check(`${name}: the check ran`, false, error.message.split('\n')[0]);
    } finally {
        await context.close();
        await browser.close();
    }
}

(async () => {
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) await run(browserName, true);
    await run('chromium', false);
    const made = execFileSync('podman', ['exec', 'env_postgres_1', 'psql', '-U', 'catto_learning', '-d', 'catto_learning', '-At', '-c',
        `SELECT count(*) FROM users WHERE first_name = '${state.first.replace(/'/g, "''")}' AND last_name = '${state.last.replace(/'/g, "''")}'`], {encoding: 'utf8'}).trim();
    check('nothing was made', made === '0', `${made} people named ${state.first} ${state.last}`);
    const failed = results.filter((result) => !result.ok);
    console.log(`${results.length - failed.length} of ${results.length} passed`);
    process.exit(failed.length === 0 ? 0 : 1);
})();
