/* Footer social media and Share this course or bundle (owner, 2026-11-09: "I need an Admin setting to setup links to social
   media profiles which are published in the footer of the site. Call the setting: Footer social media", and "one generic
   share icon (three node graph). The share icon on a mobile device should use the native built-in sharesheet feature. On a
   Linux or Win PC browser, I'd like a modal window to popup with the 6 options + Telegram. Place Telegram after WhatsApp."):
   - a visitor on a course page and a bundle page, in Chromium and Firefox, at 1440 and 390px, finds one Share link with the
     three-node mark; pressing it opens a window with Facebook, WhatsApp, Telegram, LinkedIn, X, Email and Copy link, in
     that order, each platform sharing the page's canonical address in a new tab, inside the window, nothing scrolling
     sideways and nothing refused by the content policy; Copy link copies the address and says so; Escape closes it;
   - on a phone (a touch screen whose browser has its own Share) the link opens the device's share sheet with the page's
     title and canonical address, and no window;
   - without JavaScript the window is a part of the page, the link goes to it, and the six links are there without Copy link;
   - ADMIN saves three profiles at Settings > Footer social media (Telegram among them: owner, "yes, add Telegram to the
     footer social media too") and the footer then shows exactly those three marks and the
     home page names them as the organisation's own; an address on another site is refused in plain words and nothing is
     changed; the owner's own setting is read first and put back exactly at the end;
   - every shared address is tagged with where it was shared (owner: "I'd like to see how many visitors arrive through
     shares - tag them"), and a visitor arriving through one is in the Site Log as a share and on the Overview's Visitors
     through shares, with the page and the platform.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), its ADMIN session. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const {watchPolicy} = require('./page-construction.cjs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const sql = (query) => execFileSync('podman', ['exec', 'env_postgres_1', 'psql', '-U', 'catto_learning', '-d', 'catto_learning', '-tAq', '-c', query]).toString().trim();
const coursePath = '/courses/' + sql("SELECT slug FROM courses WHERE status = 'published' ORDER BY catalogue_position NULLS LAST, id LIMIT 1");
const bundlePath = '/bundles/' + sql("SELECT slug FROM bundles WHERE status = 'published' ORDER BY id LIMIT 1");
const PLATFORMS = ['facebook', 'whatsapp', 'telegram', 'x', 'linkedin', 'instagram', 'youtube'];

const OPTIONS = ['Facebook', 'WhatsApp', 'Telegram', 'LinkedIn', 'X', 'Email', 'Copy link'];
// The address shared, tagged with where it was shared (partials/share-page.html.twig).
const tagged = (canonical, source) => `${canonical}${canonical.includes('?') ? '&' : '?'}utm_medium=share&utm_campaign=share&utm_source=${source}`;

async function sharing(page, label, javascript, browserName, width) {
    const canonical = await page.locator('link[rel=canonical]').getAttribute('href');
    const what = label.includes('bundle') ? 'bundle' : 'course';
    const box = page.locator('[data-share]');
    check(`${label}: one Share box`, await box.count() === 1);
    check(`${label}: shares the canonical address`, await box.getAttribute('data-share-url') === canonical, canonical);
    const opener = box.locator(`a[href="#share-${what}"]`);
    check(`${label}: one Share this ${what} link with the three-node mark`, await opener.count() === 1 && (await opener.innerText()).trim().toLowerCase() === `share this ${what}` && await opener.locator('use[href$="#action-share"]').count() === 1);
    const window = page.locator(`#share-${what}`);
    const encoded = (source) => encodeURIComponent(tagged(canonical, source));
    const options = async () => window.locator('.cl-share-page-option').evaluateAll((all) => all.filter((o) => o.getClientRects().length).map((o) => ({name: o.textContent.trim(), href: o.getAttribute('href') || '', target: o.getAttribute('target') || '', rel: o.getAttribute('rel') || ''})));
    if (!javascript) {
        check(`${label}: without JavaScript the window is part of the page`, await window.isVisible());
        check(`${label}: without JavaScript, the six links and no Copy link`, (await options()).map((o) => o.name).join('|') === OPTIONS.slice(0, 6).join('|'), JSON.stringify((await options()).map((o) => o.name)));
        await opener.click();
        check(`${label}: without JavaScript the link goes to it`, new URL(page.url()).hash === `#share-${what}`);
        return;
    }
    check(`${label}: the window is closed at first`, !(await window.isVisible()));
    await opener.click();
    await window.waitFor({state: 'visible', timeout: 5000}).catch(() => {});
    check(`${label}: pressing Share opens the window`, await window.isVisible() && (await window.locator('h3').innerText()).trim() === `Share this ${what}`);
    const shown = await options();
    check(`${label}: Facebook, WhatsApp, Telegram, LinkedIn, X, Email and Copy link, in that order`, shown.map((o) => o.name).join('|') === OPTIONS.join('|'), JSON.stringify(shown.map((o) => o.name)));
    const href = (name) => shown.find((o) => o.name === name)?.href || '';
    check(`${label}: Facebook shares this page, tagged facebook`, href('Facebook') === `https://www.facebook.com/sharer/sharer.php?u=${encoded('facebook')}`, href('Facebook'));
    check(`${label}: Telegram shares this page, tagged telegram`, href('Telegram').startsWith(`https://t.me/share/url?url=${encoded('telegram')}&text=`), href('Telegram'));
    check(`${label}: LinkedIn shares this page, tagged linkedin`, href('LinkedIn') === `https://www.linkedin.com/sharing/share-offsite/?url=${encoded('linkedin')}`, href('LinkedIn'));
    check(`${label}: WhatsApp and X carry the address, tagged`, href('WhatsApp').startsWith('https://wa.me/?text=') && href('WhatsApp').includes(encoded('whatsapp')) && href('X').startsWith('https://x.com/intent/post?') && href('X').includes(encoded('x')));
    check(`${label}: Email carries the address, tagged email`, href('Email').startsWith('mailto:?subject=') && href('Email').includes(encoded('email')));
    check(`${label}: each platform opens in a new tab`, shown.filter((o) => o.href.startsWith('https://')).every((o) => o.target === '_blank' && o.rel.includes('noopener')));
    const fits = await page.evaluate((id) => {
        const card = document.querySelector(`#${id} .cl-ui-modal-card`).getBoundingClientRect();
        return {sideways: document.documentElement.scrollWidth > innerWidth + 1, inside: [...document.querySelectorAll(`#${id} .cl-share-page-option`)].filter((o) => o.getClientRects().length).every((o) => { const r = o.getBoundingClientRect(); return r.left >= card.left - 1 && r.right <= card.right + 1; })};
    }, `share-${what}`);
    check(`${label}: every option inside the window, nothing scrolls sideways`, fits.inside && !fits.sideways, JSON.stringify(fits));
    if (width === 1440) {
        await window.locator('[data-share-copy]').click();
        await window.locator('[data-share-done]').waitFor({state: 'visible', timeout: 5000}).catch(() => {});
        check(`${label}: Copy link says the link is copied`, await window.locator('[data-share-done]').isVisible());
        if (browserName === 'chromium') {
            const copied = await page.evaluate(() => navigator.clipboard.readText());
            check(`${label}: the canonical address, tagged copied_link, is what was copied`, copied === tagged(canonical, 'copied_link'), copied);
        }
    }
    await page.keyboard.press('Escape');
    check(`${label}: Escape closes the window`, !(await window.isVisible()));
}

async function phone(browser, path, what) {
    // A phone: a touch screen whose browser has its own Share (recorded here rather than shown).
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true});
    await context.addInitScript(() => { window.cattoShared = []; navigator.share = (data) => { window.cattoShared.push(data); return Promise.resolve(); }; });
    await watchPolicy(context);
    const page = await context.newPage();
    await page.goto(base + path, {waitUntil: 'load'});
    const canonical = await page.locator('link[rel=canonical]').getAttribute('href');
    const title = await page.locator('[data-share]').getAttribute('data-share-title');
    await page.locator(`[data-share] a[href="#share-${what}"]`).tap();
    await page.waitForTimeout(300);
    const shared = await page.evaluate(() => window.cattoShared);
    check(`phone ${what} page: Share opens the device's share sheet with the title and canonical address, tagged share_sheet`, shared.length === 1 && shared[0].url === tagged(canonical, 'share_sheet') && shared[0].title === title, JSON.stringify(shared));
    check(`phone ${what} page: and no window`, !(await page.locator(`#share-${what}`).isVisible()));
    check(`phone ${what} page: nothing refused by the content policy`, (await page.evaluate(() => window.cattoPolicyRefusals || [])).length === 0);
    await context.close();
}

/* A visitor arriving through a link shared on WhatsApp is a share in the Site Log, and ADMIN sees it on the Overview. */
async function arriving(browser, admin) {
    // A person's phone, as the Site Log sees one: the check's own browser says it is headless, which counts as automated.
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36'});
    const page = await context.newPage();
    await page.goto(base + coursePath, {waitUntil: 'load'});
    const canonical = await page.locator('link[rel=canonical]').getAttribute('href');
    await page.goto(tagged(canonical, 'whatsapp'), {waitUntil: 'load'});
    check('a shared link opens the page, its canonical address untagged', await page.locator('link[rel=canonical]').getAttribute('href') === canonical);
    await context.close();
    let logged = '';
    for (let i = 0; i < 20 && logged === ''; i++) {
        logged = sql(`SELECT source || ' ' || came_from FROM cattophp_site_requests WHERE path = '${coursePath.replace(/'/g, "''")}' AND at > NOW() - INTERVAL '1 minute' AND source = 'share' ORDER BY id DESC LIMIT 1`);
        if (logged === '') await new Promise((done) => setTimeout(done, 250));
    }
    check('the Site Log has the visit as a share from WhatsApp', logged === 'share whatsapp', logged);
    await admin.goto(`${base}/admin/site-analytics`, {waitUntil: 'load'});
    const card = admin.locator('#site-shares');
    check('the Overview has Visitors through shares', await card.count() === 1);
    const row = await card.locator('tbody tr').evaluateAll((rows, name) => rows.map((r) => r.innerText).find((text) => text.includes('WhatsApp')) || '', null);
    check('it names the page arrived at and WhatsApp', row !== '', row);
    const found = await admin.locator('table').filter({hasText: 'Came from'}).innerText();
    check('How they found you names Shared on WhatsApp', found.includes('Shared on WhatsApp'), found.slice(0, 200));
}

async function footerMarks(page) {
    return page.locator('.cl-footer-social a').evaluateAll((all) => all.map((a) => a.getAttribute('href')));
}

(async () => {
    check('a published course and bundle to share', coursePath !== '/courses/' && bundlePath !== '/bundles/', `${coursePath} ${bundlePath}`);
    for (const kind of [chromium, firefox]) {
        const browser = await kind.launch();
        const name = kind.name();
        for (const width of [1440, 390]) {
            for (const javascript of [true, false]) {
                const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, javaScriptEnabled: javascript, ...(name === 'chromium' && javascript ? {permissions: ['clipboard-read', 'clipboard-write']} : {})});
                await watchPolicy(context);
                const page = await context.newPage();
                for (const [path, what] of [[coursePath, 'course'], [bundlePath, 'bundle']]) {
                    const label = `${name} ${width}${javascript ? '' : ' no JavaScript'} ${what} page`;
                    await page.goto(base + path, {waitUntil: 'load'});
                    await sharing(page, label, javascript, name, width);
                    if (javascript) check(`${label}: nothing refused by the content policy`, (await page.evaluate(() => window.cattoPolicyRefusals || [])).length === 0, JSON.stringify(await page.evaluate(() => window.cattoPolicyRefusals || [])));
                }
                await context.close();
            }
        }
        // Firefox has no phone to imitate in Playwright; Chromium's stands for the phone's browser.
        if (name === 'chromium') for (const [path, what] of [[coursePath, 'course'], [bundlePath, 'bundle']]) await phone(browser, path, what);
        await browser.close();
    }

    // Settings > Footer social media, as ADMIN: once, in Chromium, the owner's own setting read first and put back.
    const browser = await chromium.launch();
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const admin = await context.newPage();
    const settings = async () => admin.goto(`${base}/admin/settings?section=footer-social`, {waitUntil: 'load'});
    const fill = async (values) => {
        await settings();
        for (const key of PLATFORMS) await admin.locator(`#footer-social-${key}`).fill(values[key] || '');
        await Promise.all([admin.waitForNavigation(), admin.locator('button:has-text("Save footer social media")').click()]);
    };
    await arriving(browser, admin);
    await settings();
    check('Settings has Footer social media, open at its address', await admin.locator('details#settings-footer-social[open]').count() === 1);
    const original = {};
    for (const key of PLATFORMS) original[key] = await admin.locator(`#footer-social-${key}`).inputValue();
    await admin.goto(`${base}/courses`, {waitUntil: 'load'});
    const footerBefore = await footerMarks(admin);
    try {
        const own = {facebook: 'https://www.facebook.com/footer-check-example', telegram: 'https://t.me/footer_check_example', youtube: 'https://www.youtube.com/@footer-check-example'};
        await fill(own);
        check('saving says so', (await admin.locator('[data-flash-message]').allInnerTexts()).join(' ').includes('The footer social media links are saved.'));
        await admin.goto(`${base}/courses`, {waitUntil: 'load'});
        check('the footer shows exactly the three profiles, in its order, Telegram after Facebook', JSON.stringify(await footerMarks(admin)) === JSON.stringify([own.facebook, own.telegram, own.youtube]), JSON.stringify(await footerMarks(admin)));
        check("the footer's Telegram mark is drawn", await admin.locator('.cl-footer-social a[href="' + own.telegram + '"] use[href$="#social-telegram"]').count() === 1);
        await admin.goto(`${base}/`, {waitUntil: 'load'});
        const named = await admin.locator('script[type="application/ld+json"]').allTextContents();
        check("the home page names them as the organisation's own", named.some((json) => json.replace(/\\\//g, '/').includes(own.youtube)));
        await fill({...own, linkedin: 'https://www.example.org/not-linkedin'});
        const refusal = (await admin.locator('[data-flash-message]').allInnerTexts()).join(' ');
        check('an address on another site is refused in plain words', refusal.includes('LinkedIn'), refusal);
        await admin.goto(`${base}/courses`, {waitUntil: 'load'});
        check('and nothing is changed', JSON.stringify(await footerMarks(admin)) === JSON.stringify([own.facebook, own.telegram, own.youtube]));
    } finally {
        await fill(original);
        await admin.goto(`${base}/courses`, {waitUntil: 'load'});
        check("the owner's own setting is back as it was", JSON.stringify(await footerMarks(admin)) === JSON.stringify(footerBefore), JSON.stringify(await footerMarks(admin)));
        await browser.close();
    }

    const failed = results.filter((r) => !r.ok);
    console.log(`${results.length - failed.length} of ${results.length} checks passed.`);
    process.exit(failed.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
