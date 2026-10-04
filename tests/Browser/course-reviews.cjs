/* Course ratings and reviews in a real browser, in Chromium and Firefox and without JavaScript:
 * the five-star control by keyboard with a visible selection and focus ring, submitting a review,
 * its pending status, ADMIN approval, and the course page showing only approved reviews and the
 * rating from them, at desktop and phone widths.
 *
 * Run against the development instance with the fixture from course-review-fixture.php, which is
 * read and reset through the PHP container. Uses the installed Playwright. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const container = process.env.CATTO_PHP_CONTAINER || 'env_php_1';
const statePath = process.env.CATTO_BROWSER_STATE || '/tmp/catto-course-review-state.json';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-course-reviews';
fs.mkdirSync(output, {recursive: true});

const php = (action) => execFileSync('podman', ['exec', '-u', 'cattotest', container, 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/course-review-fixture.php ${action} ${statePath} 2>/dev/null`], {encoding: 'utf8'});
const reset = () => JSON.parse(php('reset').trim().split('\n').pop());
const results = [], failures = [];

async function scenario(name, browserName, run) {
    try {
        await run();
        results.push(`${browserName}: ${name}`);
    } catch (error) {
        failures.push(`${browserName}: ${name}: ${error.message.split('\n')[0]}`);
    }
}
async function context(browser, token, options = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1280, height: 1600}, ...options});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    return ctx;
}
// Read after the stars' short opacity transition has finished.
const starOpacity = async (page, n) => (await page.waitForTimeout(300), page.locator(`.cl-rating-option:nth-child(${n}) .cl-rating-star`).evaluate((el) => Number(getComputedStyle(el).opacity)));
const noSideScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);

(async () => {
    let state = reset();
    for (const browserName of (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',')) {
        const browser = await playwright[browserName].launch();
        const pageErrors = [];

        await scenario('the public course page shows the approved review and its rating', browserName, async () => {
            state = reset();
            const ctx = await context(browser, null);
            const page = await ctx.newPage();
            page.on('pageerror', (error) => pageErrors.push(error.message));
            await page.goto(`${base}/courses/${state.slug}`);
            await page.getByText('Already approved before the run.').waitFor();
            assert.ok(await page.getByText('Sam S.').isVisible(), 'published under first name and initial');
            assert.equal(await page.getByText('Sam Smith').count(), 0, 'the full name is not published');
            assert.ok((await page.locator('.cl-rating-summary').first().textContent()).includes('Rated 4.0 out of 5 from 1 review'));
            assert.ok(await page.locator('.cl-rating-figures').first().isVisible(), 'the figures are visible');
            assert.equal(await page.getByRole('link', {name: 'Rate this course'}).count(), 0, 'a visitor is not offered the form');
            await page.screenshot({path: `${output}/${browserName}-course-page.png`, fullPage: true});
            await ctx.close();
        });

        await scenario('the star control works by keyboard, shows the choice and submits for moderation', browserName, async () => {
            state = reset();
            const ctx = await context(browser, state.learner_token);
            const page = await ctx.newPage();
            page.on('pageerror', (error) => pageErrors.push(error.message));
            await page.goto(`${base}/courses/${state.slug}`);
            await page.getByRole('link', {name: 'Rate this course'}).click();
            await page.waitForURL(`**/learn/${state.slug}/review`);
            const group = page.getByRole('group', {name: /Your rating/});
            assert.equal(await group.getByRole('radio').count(), 5, 'five radios in one labelled group');
            await page.getByRole('radio', {name: '1 star', exact: true}).focus();
            await page.keyboard.press('Space');
            for (let i = 0; i < 3; i++) await page.keyboard.press('ArrowRight');
            assert.ok(await page.getByRole('radio', {name: '4 stars'}).isChecked(), 'arrow keys move to 4 stars');
            const outline = await page.locator('.cl-rating-option:nth-child(4)').evaluate((el) => getComputedStyle(el).outlineStyle);
            assert.notEqual(outline, 'none', 'the focused star shows a focus ring');
            assert.equal(await starOpacity(page, 4), 1, 'stars up to the choice are filled');
            assert.ok(await starOpacity(page, 5) < 1, 'stars after the choice are not');
            await page.mouse.move(0, 0);
            await page.getByLabel('Your review').fill('Typed in the browser.');
            await page.screenshot({path: `${output}/${browserName}-review-form.png`});
            await page.getByRole('button', {name: 'Submit review'}).click();
            await page.getByText('Pending moderation').waitFor();
            assert.ok(await page.getByRole('radio', {name: '4 stars'}).isChecked(), 'the saved rating is shown again');
            assert.equal(await page.getByLabel('Your review').inputValue(), 'Typed in the browser.');
            await page.goto(`${base}/courses/${state.slug}`);
            assert.equal(await page.getByText('Typed in the browser.').count(), 0, 'a pending review is not public');
            assert.ok((await page.locator('.cl-course-review-action').textContent()).includes('pending moderation'));
            await ctx.close();
        });

        await scenario('ADMIN approves it and the course page publishes it', browserName, async () => {
            const ctx = await context(browser, state.admin_token);
            const page = await ctx.newPage();
            page.on('pageerror', (error) => pageErrors.push(error.message));
            await page.goto(`${base}/admin/course-reviews`);
            const entry = page.locator('.cl-review', {hasText: 'Typed in the browser.'});
            await entry.waitFor();
            await entry.getByRole('button', {name: 'Approve'}).click();
            // The flash message is transient; the emptied queue is the lasting evidence.
            await page.getByText('No reviews are waiting for moderation.').waitFor();
            await page.goto(`${base}/courses/${state.slug}`);
            await page.getByText('Typed in the browser.').waitFor();
            assert.ok((await page.locator('.cl-rating-summary').first().textContent()).includes('Rated 4.0 out of 5 from 2 reviews'));
            assert.ok(await page.getByText('Jane D.').isVisible());
            await ctx.close();
        });

        await scenario('pages fit a phone without sideways scrolling', browserName, async () => {
            const ctx = await context(browser, state.learner_token, {viewport: {width: 390, height: 844}});
            const page = await ctx.newPage();
            for (const path of [`/courses/${state.slug}`, `/learn/${state.slug}/review`]) {
                await page.goto(`${base}${path}`);
                assert.ok(await noSideScroll(page), `${path} has no horizontal scroll`);
            }
            await page.screenshot({path: `${output}/${browserName}-review-phone.png`, fullPage: true});
            await ctx.close();
        });

        if (pageErrors.length) failures.push(`${browserName}: page errors: ${pageErrors.join('; ')}`);
        await browser.close();
    }

    await scenario('without JavaScript the rating form submits', 'chromium-nojs', async () => {
        state = reset();
        const browser = await playwright.chromium.launch();
        const ctx = await context(browser, state.learner_token, {javaScriptEnabled: false});
        const page = await ctx.newPage();
        await page.goto(`${base}/learn/${state.slug}/review`);
        await page.getByRole('radio', {name: '2 stars'}).check();
        await page.getByRole('button', {name: 'Submit review'}).click();
        await page.getByText('Pending moderation').waitFor();
        assert.ok(await page.getByRole('radio', {name: '2 stars'}).isChecked());
        await browser.close();
    });

    reset();
    console.log(results.map((line) => `PASS ${line}`).join('\n'));
    console.log(JSON.stringify({checks: results.length + failures.length, failures}, null, 2));
    process.exit(failures.length ? 1 : 0);
})();
