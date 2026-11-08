/* Every page built one way (UX rule 1.7), found by following links rather than from a list (owner, 2026-10-20: a
   person's page in Administration had its cards outside the page section, which the menu-page check never visits).
   Starting from the home page, the catalogue, Administration, Account and Company, it follows every link on the site
   as an ADMIN who also runs a company and as a visitor, at 1440 and 390px (CATTO_EVERY_PAGE_WIDTHS), the four crawls at
   once and each opening several pages at once (CATTO_PAGES), and checks each page:
   - what section-pages.cjs checks on the menu pages (page-construction.cjs): one h1, only page rows directly in main,
     no words on the page texture, the first card's edge on the page head's text edge, no sideways scrolling, nothing
     standing out of a table and no action cut off by its cell;
   - an answer below 400, within a minute and a half (a ceiling for a stuck page, with the gate's checks side by side).
   Pages that differ only in an id or a course code are visited twice at most. Home, course landing pages, the course reader (/learn/)
   and its public preview are built another way on purpose; downloads, exports, PDFs and signing out are not pages. results.json goes to CATTO_BROWSER_OUTPUT.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), the same ADMIN session. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-every-page';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const LIMIT = Number(process.env.CATTO_EVERY_PAGE_LIMIT || 600);
const SKIP = /^\/(learn\/|logout|auth\/|webhooks\/|api\/|mcp)|\/(download|export|delete|remove|reset|revoke|cancel)(\/|$)|\.(pdf|xlsx|csv|xml|txt|json|svg|png|jpe?g|webp|zip)$|^\/sitemap|^\/robots/;
const {inspect, judge} = require('./page-construction.cjs');
const {PAGES, open, within} = require('./pace.cjs');
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
/** Pages that differ only in an id, or a record's code, number, reference or slug, share a pattern (owner, 2026-11-06:
    no row id in an address; a course is its code in Administration's addresses, /admin/courses/GDN-101/content, a
    person their account reference, /admin/people/CL2026011510423Z, an order its number, /admin/commerce/orders/
    CL-00000689, a promotion its code, SUMMER_25). A code is capitals, digits, hyphens and underscores with a letter.
    Administration names a Course Item, a category and a tag by a slug, and the public preview a course's part by its
    part name: those are one page each too. */
const pattern = (address) => address.replace(/\/[0-9]+(?=\/|\?|$)/g, '/{id}')
    .replace(/\/(?=[A-Z0-9_-]*[A-Z])[A-Z0-9]+(?:[-_][A-Z0-9]+)*(?=\/|\?|$)/g, '/{code}')
    .replace(/^\/admin\/(course-items|courses\/categories|courses\/tags)\/(?!new(?=\/|\?|$)|inline(?=\/|\?|$)|field(?=\/|\?|$))[a-z0-9-]+/, '/admin/$1/{slug}')
    .replace(/^\/courses\/(?!category\/|tag\/)([a-z0-9-]+)\/preview\/(?!assessment\/)[a-z0-9-]+/, '/courses/$1/preview/{part}')
    .replace(/\/[0-9a-f]{16,}(?=\/|\?|$)/g, '/{key}');

async function crawl(browser, who, cookie, width) {
    // Several pages at once (CATTO_PAGES), each in a browser context of its own, taking addresses from one queue.
    const contexts = [];
    const pageFor = async (worker) => {
        if (!contexts[worker]) {
            const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}});
            if (cookie) await context.addCookies([{name: 'catto_learning_session', value: cookie, url: base, secure: true}]);
            contexts[worker] = {context, page: await context.newPage()};
        }
        return contexts[worker].page;
    };
    const queue = ['/', '/courses', '/courses/tags', '/bundles', '/presentation', '/help', '/faq', '/contact', '/terms', '/refunds', '/privacy',
        ...(cookie ? ['/admin', '/account', '/company'] : ['/login', '/register'])];
    const seen = new Set(queue), perPattern = new Map(), results = [], from = new Map();
    let busy = 0;
    const visit = async (address, page) => {
        const key = pattern(address);
        if ((perPattern.get(key) || 0) >= 2) return;
        perPattern.set(key, (perPattern.get(key) || 0) + 1);
        let response;
        try { response = await open(page, base + address, {timeout: 90000}); } catch (error) {
            // A link that starts a download (an invoice, a certificate) is not a page.
            if (!/Download is starting/.test(error.message)) results.push({who: `${who} ${width}`, address, problems: ['did not load: ' + error.message.split('\n')[0]]});
            return;
        }
        if (!response || !(response.headers()['content-type'] || '').includes('text/html')) return;
        const landed = normal(page.url());
        if (landed !== address && seen.has(landed)) return;
        if (response.status() >= 400) { results.push({who: `${who} ${width}`, address: landed, status: response.status(), problems: [`status ${response.status()}, linked from ${from.get(address) || 'the start'}`]}); return; }
        if (OWN_LAYOUT.test(landed.split('?')[0])) return;
        // A page the inspection cannot read (no main, say) is a failure named with its address, never the end of the crawl.
        let facts;
        try { facts = await page.evaluate(inspect); } catch (error) {
            results.push({who: `${who} ${width}`, address: landed || address, status: response.status(), problems: ['not built as a page: ' + error.message.split('\n')[0] + ', linked from ' + (from.get(address) || 'the start')]});
            return;
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
    };
    // A worker takes the next address; when the queue is empty it waits while another worker may still find more.
    // A stuck page is closed and its worker carries on with a new one.
    const fresh = async (number, stuck) => {
        await stuck.close().catch(() => {});
        const page = await contexts[number].context.newPage();
        contexts[number].page = page;
        return page;
    };
    const worker = async (number) => {
        let page = await pageFor(number);
        for (;;) {
            if (results.length >= LIMIT) return;
            const address = queue.shift();
            if (address === undefined) {
                if (busy === 0) return;
                await new Promise((done) => setTimeout(done, 50));
                continue;
            }
            busy++;
            try { await within(120000, 'took more than two minutes', () => visit(address, page)); }
            catch (error) { results.push({who: `${who} ${width}`, address, problems: ['did not load: ' + error.message.split('\n')[0]]}); page = await fresh(number, page); }
            finally { busy--; }
        }
    };
    await Promise.all(Array.from({length: PAGES}, (_, number) => worker(number)));
    for (const {context} of contexts.filter(Boolean)) await context.close();
    return results;
}

(async () => {
    fs.mkdirSync(output, {recursive: true});
    const browser = await chromium.launch();
    // At a desktop and a phone width: a phone is where a page scrolls sideways. The four crawls run at once.
    const widths = (process.env.CATTO_EVERY_PAGE_WIDTHS || '1440,390').split(',').map(Number);
    const crawls = widths.flatMap((width) => [crawl(browser, 'admin', state.token, width), crawl(browser, 'visitor', null, width)]);
    const results = (await Promise.all(crawls)).flat();
    await browser.close();
    const failures = results.filter((r) => r.problems.length);
    for (const r of failures) console.log(`FAIL ${r.who} ${r.address}: ${r.problems.join('; ')}`);
    const summary = {checks: results.length, failures: failures.map((r) => `${r.who} ${r.address}: ${r.problems.join('; ')}`)};
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({summary, results}, null, 2));
    console.log(JSON.stringify(summary, null, 2));
    process.exit(failures.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
