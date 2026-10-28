/* Every page built one way (UX rule 1.7), found by following links rather than from a list (owner, 2026-10-20: a
   person's page in Administration had its cards outside the page section, which the menu-page check never visits).
   Starting from the home page, the catalogue, Administration, Account and Company, it follows every link on the site
   as an ADMIN who also runs a company, then as a visitor, at 1440 and 390px (CATTO_EVERY_PAGE_WIDTHS), and checks each
   page:
   - what section-pages.cjs checks on the menu pages (page-construction.cjs): one h1, only page rows directly in main,
     no words on the page texture, the first card's edge on the page head's text edge, no sideways scrolling, nothing
     standing out of a table and no action cut off by its cell;
   - an answer below 400.
   Pages that differ only in an id are visited twice at most. Home, course landing pages, the course reader (/learn/)
   and its public preview are built another way on purpose; downloads, exports, PDFs and signing out are not pages. results.json goes to CATTO_BROWSER_OUTPUT.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), the same ADMIN session. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-every-page';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const LIMIT = Number(process.env.CATTO_EVERY_PAGE_LIMIT || 600);
const SKIP = /^\/(learn\/|logout|auth\/|webhooks\/|api\/|mcp)|\/(download|export|delete|remove|reset|revoke|cancel)(\/|$)|\.(pdf|csv|xml|txt|json|svg|png|jpe?g|webp|zip)$|^\/sitemap|^\/robots/;
const {inspect, judge} = require('./page-construction.cjs');
// Built another way on purpose: Home and course landing pages are full-width marketing pages, and the course reader and
// its public preview are Core's reader (UX rule 1.7; the reader is never a theme's page).
// A message's, a template's and the digest's preview, and an email's web copy (/m/v/), are the email itself, as a reader
// sees it (the Mailing menu, 2026-10-28): never a site page.
const OWN_LAYOUT = /^\/($|landing\/|learn\/|courses\/[^/]+\/preview(\/|$)|mailing\/(messages|templates)\/\d+\/preview$|mailing\/digest\/preview$|m\/v\/)/;

/** One address per page: the path, with only the tab and a preview flag kept from the query. */
function normal(href) {
    const url = new URL(href, base);
    if (url.origin !== new URL(base).origin) return null;
    // The parameters that decide which page is drawn: a tab, and a draft course's preview for its editor.
    const kept = new URLSearchParams();
    for (const name of ['tab', 'preview']) if (url.searchParams.get(name)) kept.set(name, url.searchParams.get(name));
    return url.pathname + (kept.size ? `?${kept}` : '');
}
/** Pages that differ only in an id share a pattern. */
const pattern = (address) => address.replace(/\/[0-9]+(?=\/|\?|$)/g, '/{id}').replace(/\/[0-9a-f]{16,}(?=\/|\?|$)/g, '/{key}');

async function crawl(browser, who, cookie, width) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}});
    if (cookie) await context.addCookies([{name: 'catto_learning_session', value: cookie, url: base, secure: true}]);
    const page = await context.newPage();
    const queue = ['/', '/courses', '/courses/tags', '/bundles', '/help', '/faq', '/contact', '/terms', '/refunds', '/privacy',
        ...(cookie ? ['/admin', '/account', '/company'] : ['/login', '/register'])];
    const seen = new Set(queue), perPattern = new Map(), results = [], from = new Map();
    while (queue.length && results.length < LIMIT) {
        const address = queue.shift();
        const key = pattern(address);
        if ((perPattern.get(key) || 0) >= 2) continue;
        perPattern.set(key, (perPattern.get(key) || 0) + 1);
        let response;
        try { response = await page.goto(base + address, {waitUntil: 'networkidle', timeout: 30000}); } catch (error) {
            // A link that starts a download (an invoice, a certificate) is not a page.
            if (!/Download is starting/.test(error.message)) results.push({who: `${who} ${width}`, address, problems: ['did not load: ' + error.message.split('\n')[0]]});
            continue;
        }
        if (!response || !(response.headers()['content-type'] || '').includes('text/html')) continue;
        const landed = normal(page.url());
        if (landed !== address && seen.has(landed)) continue;
        if (response.status() >= 400) { results.push({who: `${who} ${width}`, address: landed, status: response.status(), problems: [`status ${response.status()}, linked from ${from.get(address) || 'the start'}`]}); continue; }
        if (OWN_LAYOUT.test(landed.split('?')[0])) continue;
        // A page the inspection cannot read (no main, say) is a failure named with its address, never the end of the crawl.
        let facts;
        try { facts = await page.evaluate(inspect); } catch (error) {
            results.push({who: `${who} ${width}`, address: landed || address, status: response.status(), problems: ['not built as a page: ' + error.message.split('\n')[0] + ', linked from ' + (from.get(address) || 'the start')]});
            continue;
        }
        const links = await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')));
        const problems = judge(facts, '').map((failure) => failure.replace(/^: /, ''));
        results.push({who: `${who} ${width}`, address: landed || address, status: response.status(), problems});
        for (const href of links) {
            if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) continue;
            const next = normal(href);
            if (!next || seen.has(next) || SKIP.test(next.split('?')[0])) continue;
            seen.add(next);
            from.set(next, landed || address);
            queue.push(next);
        }
    }
    await context.close();
    return results;
}

(async () => {
    fs.mkdirSync(output, {recursive: true});
    const browser = await chromium.launch();
    // At a desktop and a phone width: a phone is where a page scrolls sideways.
    const widths = (process.env.CATTO_EVERY_PAGE_WIDTHS || '1440,390').split(',').map(Number);
    const results = [];
    for (const width of widths) results.push(...await crawl(browser, 'admin', state.token, width), ...await crawl(browser, 'visitor', null, width));
    await browser.close();
    const failures = results.filter((r) => r.problems.length);
    for (const r of failures) console.log(`FAIL ${r.who} ${r.address}: ${r.problems.join('; ')}`);
    const summary = {checks: results.length, failures: failures.map((r) => `${r.who} ${r.address}: ${r.problems.join('; ')}`)};
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({summary, results}, null, 2));
    console.log(JSON.stringify(summary, null, 2));
    process.exit(failures.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
