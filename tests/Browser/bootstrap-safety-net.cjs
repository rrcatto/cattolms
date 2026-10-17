/* The safety net under Bootstrap 6 (UX rule 7.10) and the fixed colour scheme, in a real browser.
 *
 * Bootstrap 6 keeps every rule in a cascade layer and draws its colours with light-dark() tokens. A browser older
 * than cascade layers ignores all of it; one with layers but without light-dark() loses every --bs-* colour. Core
 * and every theme state the colours a reader needs in plain values, so neither browser loses them. A theme also
 * keeps its look when the operating system is dark (theme.json color_scheme; data-bs-theme on <html>).
 *
 * In Chromium and Firefox, in every bundled theme (theme preview), on the UI components page, the account page and
 * the catalogue, and on Core's reader (a course's public preview): the body's text and background, a link, the text,
 * background and border of a text field, a select and a text area where the page has them, the accent of a checkbox, and the keyboard focus
 * outline are read four times: as served; with the operating system dark; with Bootstrap's three files removed (a
 * browser without cascade layers); and with every --bs-* token made invalid (a browser without light-dark()). Each
 * reading must be the same as served, and the page's colour scheme stays light in a dark operating system. A
 * theme page also paints the canvas behind the scrollbar gutter Bootstrap 6 keeps (an html or body colour). A
 * mutation, a body colour drawn only from Bootstrap's tokens, must be caught.
 *
 * Uses the isolated identity from ui-stabilisation-fixture.php: create it and copy its state to the host first
 * (README). The site's active theme is never changed. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-ui-stabilisation-state.json'));
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-bootstrap-safety-net';
const browsers = (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',').filter(Boolean);
fs.mkdirSync(output, {recursive: true});
const themes = fs.readdirSync(path.join(root, 'themes')).filter((n) => fs.existsSync(path.join(root, 'themes', n, 'theme.json')))
    .map((n) => JSON.parse(fs.readFileSync(path.join(root, 'themes', n, 'theme.json'))).theme).map((t) => ({slug: t.slug, key: `${t.slug}-v${t.version}`}));
const ROUTES = ['/admin/system/ui-components', '/account/profile', '/courses'];
const results = [], failures = [];

async function check(name, run, page = null) {
    try {
        await run();
        results.push(name);
        console.log('PASS', name);
    } catch (error) {
        failures.push(`${name}: ${error.message.split('\n')[0]}`);
        console.error('FAIL', name, error.message.split('\n')[0]);
        if (page) await page.screenshot({path: path.join(output, name.replace(/[^a-z0-9]+/gi, '-') + '-failure.png'), fullPage: true}).catch(() => {});
    }
}

/* The colours a reader needs, from the first visible element of each kind; the focus outline is the first
 * element keyboard focus reaches. */
async function read(page) {
    // Keyboard focus starts from the top each time: a start point that is not in the tab order, then Tab. Transitions
    // are switched off so a colour is read at rest, not part-way through a theme's focus animation.
    await page.evaluate(() => {
        if (!document.getElementById('safety-net-still')) document.head.insertAdjacentHTML('beforeend', '<style id="safety-net-still">*,*::before,*::after{transition:none!important;animation:none!important}</style>');
        const start = document.createElement('span');
        start.id = 'safety-net-start';
        start.tabIndex = -1;
        document.body.prepend(start);
        start.focus();
    });
    await page.keyboard.press('Tab');
    await page.evaluate(() => document.getElementById('safety-net-start')?.remove());
    return page.evaluate(() => {
        const visible = (e) => !!e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden';
        const first = (selector) => [...document.querySelectorAll(selector)].find(visible) || null;
        const pick = (e, props) => e ? Object.fromEntries(props.map((p) => [p, getComputedStyle(e)[p]])) : null;
        const field = ['color', 'backgroundColor', 'borderTopColor'];
        const focused = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
        return {
            scheme: getComputedStyle(document.documentElement).colorScheme,
            body: pick(document.body, ['color', 'backgroundColor']),
            link: pick(first('main a[href]:not([class])') || first('main a[href]'), ['color']),
            input: pick(first('main input:not([type]), main input[type=text], main input[type=email], main input[type=search]'), field),
            select: pick(first('main select'), field),
            textarea: pick(first('main textarea'), field),
            checkbox: pick(first('input[type=checkbox]'), ['accentColor']),
            focus: focused ? {element: focused.tagName.toLowerCase() + (focused.className ? '.' + String(focused.className).trim().split(/\s+/).join('.') : ''), ...pick(focused, ['outlineColor', 'outlineStyle'])} : null,
        };
    });
}

/* The colours alone: without Bootstrap 6 nothing states the colour scheme, which is then the browser's own light. */
const colours = ({scheme, ...rest}) => rest;

/* Core's reader: the public preview of the first catalogue course that has one. */
async function readerRoute(page) {
    await page.goto(`${base}/courses`, {waitUntil: 'networkidle'});
    const courses = [...new Set(await page.locator('main a[href^="/courses/"]').evaluateAll((links) => links.map((a) => a.getAttribute('href'))))]
        .filter((href) => /^\/courses\/[a-zA-Z0-9_-]+$/.test(href)).slice(0, 12);
    for (const href of courses) {
        await page.goto(`${base}${href}`, {waitUntil: 'networkidle'});
        if (await page.locator(`a[href="${href}/preview"]`).count()) return `${href}/preview`;
    }
    throw new Error('no catalogue course with a public preview among the first twelve');
}

/* Two readings the same, or a failure naming each value that differs. */
function same(actual, expected, message) {
    const differ = [];
    const walk = (a, b, at) => {
        if (a && b && typeof a === 'object' && typeof b === 'object') { for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) walk(a[k], b[k], at ? `${at}.${k}` : k); }
        else if (a !== b) differ.push(`${at}: ${JSON.stringify(b)} -> ${JSON.stringify(a)}`);
    };
    walk(actual, expected, '');
    assert.ok(differ.length === 0, `${message} (${differ.join('; ')})`);
}

/* A browser without cascade layers ignores Bootstrap 6 altogether, its tokens included. */
const removeBootstrap = (page) => page.evaluate(() => {
    const links = [...document.querySelectorAll('link[rel=stylesheet][href*="/css/bootstrap-"]')];
    links.forEach((l) => l.remove());
    return links.length;
});

/* A browser without light-dark() loses every --bs-* token: each is made the guaranteed-invalid value, so a
 * colour drawn only from one is lost. */
const invalidateTokens = (page) => page.evaluate(() => {
    const names = new Set();
    const walk = (rules) => {
        for (const rule of rules) {
            if (rule.style) for (let i = 0; i < rule.style.length; i++) if (rule.style[i].startsWith('--bs-')) names.add(rule.style[i]);
            if (rule.cssRules) walk(rule.cssRules);
        }
    };
    for (const sheet of document.styleSheets) { try { walk(sheet.cssRules); } catch (e) { /* another origin's sheet */ } }
    const style = document.createElement('style');
    style.textContent = `:root,*,::before,::after{${[...names].map((n) => `${n}:initial!important`).join(';')}}`;
    document.head.append(style);
    return names.size;
});

(async () => {
    for (const name of browsers) {
        const browser = await playwright[name].launch({headless: true});
        try {
            const contexts = {};
            for (const scheme of ['light', 'dark']) {
                contexts[scheme] = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, colorScheme: scheme, reducedMotion: 'reduce'});
                await contexts[scheme].addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
            }
            const light = await contexts.light.newPage(), dark = await contexts.dark.newPage();
            for (const page of [light, dark]) page.on('pageerror', (error) => failures.push(`${name} JavaScript: ${error.message}`));
            let reader = null;
            await check(`${name} a course to read`, async () => { reader = await readerRoute(light); }, light);
            const pages = themes.flatMap((theme) => ROUTES.map((route) => [theme.slug, route, `${base}${route}?theme_preview=${theme.key}`]));
            if (reader) pages.push(['reader', reader, `${base}${reader}`]);
            for (const [label, route, url] of pages) {
                await check(`${name} ${label} ${route}`, async () => {
                    const response = await light.goto(url, {waitUntil: 'networkidle'});
                    assert.equal(response.status(), 200, 'the page answers');
                    assert.equal(await light.locator('html').getAttribute('data-bs-theme'), 'light', 'the page states its colour scheme');
                    if (label === 'reader') assert.equal(await light.locator('body[data-cl-course-reader]').count(), 1, 'Core\'s reader');
                    const served = await read(light);
                    assert.equal(served.scheme, 'light', 'a light colour scheme');
                    for (const kind of ['body', 'focus']) assert.ok(served[kind], `a ${kind} to read`);
                    if (['/admin/system/ui-components', '/courses'].includes(route)) assert.ok(served.link, 'a link to read');
                    if (route === '/admin/system/ui-components') for (const kind of ['input', 'select', 'checkbox']) assert.ok(served[kind], `a ${kind} to read`);
                    if (label !== 'reader') {
                        // Bootstrap 6 keeps the scrollbar gutter, which a fixed background does not reach: the canvas behind
                        // it takes html's colour, or the body's when html has none. It must be the page's, never white.
                        const canvas = await light.evaluate(() => [document.documentElement, document.body].map((e) => getComputedStyle(e).backgroundColor).find((c) => !/^rgba\(.*, 0\)$|^transparent$/.test(c)) || null);
                        assert.ok(canvas, 'the canvas behind the scrollbar gutter has a colour');
                    }
                    await dark.goto(url, {waitUntil: 'networkidle'});
                    same(await read(dark), served, 'a dark operating system changes nothing');
                    assert.equal(await removeBootstrap(light), 3, 'Bootstrap\'s three files were served');
                    same(colours(await read(light)), colours(served), 'without Bootstrap 6 (no cascade layers) the colours stay');
                    await light.goto(url, {waitUntil: 'networkidle'});
                    assert.ok(await invalidateTokens(light) > 100, 'Bootstrap\'s tokens were found');
                    same(colours(await read(light)), colours(served), 'without its tokens (no light-dark()) the colours stay');
                }, light);
            }
            // The check must catch what it guards against: a body colour drawn only from Bootstrap 6's tokens.
            await check(`${name} mutation: a colour drawn only from a token is caught`, async () => {
                await light.goto(`${base}/admin/system/ui-components?theme_preview=${themes[0].key}`, {waitUntil: 'networkidle'});
                await light.addStyleTag({content: 'body{color:var(--bs-fg-body)!important;background-color:var(--bs-bg-body)!important}'});
                const served = await read(light);
                await invalidateTokens(light);
                const after = await read(light);
                assert.notDeepEqual(after.body, served.body, 'invalid tokens change a token-only body colour');
            }, light);
            for (const context of Object.values(contexts)) await context.close();
        } finally {
            await browser.close();
        }
    }
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({results, failures}, null, 2));
    console.log(`${results.length} passed, ${failures.length} failed`);
    if (failures.length) process.exit(1);
})().catch((error) => { console.error(error); process.exit(1); });
