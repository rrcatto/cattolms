/* No row id in any address (owner, 2026-11-06: "You must not use internal db id fields in any urls ... All courses must be
   referred to by their course code in urls", narrowed the same day: names on public pages, a recognisable code in
   Administration and on other signed-in pages, "if they have one ... they should use that too", and numbers only for
   records nobody names by a code, where permissions protect them; never a guessable number opening anything to the
   public).

   It follows the links, as every-page.cjs does, as four readers (stage 2, 2026-11-07):
   - a signed-out visitor, from the home page, the catalogue and the public pages, through the course pages and their
     public preview: every address the page can open fails on a row id (ALLOWED is empty and only shrinks);
   - a learner (My Orders, an order and its documents, My Courses, the reader), a Company Administrator (the Company
     workspace, its people, orders and requests) and an ADMIN who also runs a company (Administration, Mailing, the
     Company workspace and Account): every address fails on a row id unless it is a record that keeps its number, listed
     in KEEP with why.
   On every page it reads every href, action, formaction, src, hx-get, hx-post and data-*-url, the address the page landed
   on, and the address each GET form would ask for with each of its named buttons and choices. A row id is a path segment
   of digits only, a query value of digits only unless its name is a count (page, *_page, *_page_size, page_size, v,
   insert_index, reviews_stars, month), or an anchor ending in a dash and digits.

   Pages that differ only in an id, a code, a reference, a number or a slug are visited twice at most. results.json goes
   to CATTO_BROWSER_OUTPUT.
   Fixture: php tests/Browser/no-row-ids-fixture.php create|cleanup [state path] (in env_php_1): the three sessions. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-no-row-ids';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-no-row-ids-state.json'));
const LIMIT = Number(process.env.CATTO_NO_ROW_IDS_LIMIT || 700);
// Not pages: signing out, downloads, exports, files; and nothing that changes anything is followed (links only).
const SKIP = /^\/(logout|auth\/|webhooks\/|api\/|mcp)|\/(download|export|delete|remove|reset|revoke|cancel|course-file|course-package|preview\.pdf)(\/|$)|\.(pdf|xlsx|csv|xml|txt|json|svg|png|gif|jpe?g|webp|zip|mp3|m4a|ogg|webm)$|^\/sitemap|^\/robots|^\/m\//;
const COUNTS = /^(page|.+_page|.+_page_size|page_size|v|insert_index|reviews_stars|month|after|sample_category)$/;
/** Switches that are on or off (1 or 0), never a record: a draft course's preview for its editor, a list filtered, edit
    open, a download asked for. */
const FLAGS = /^(preview|filtered|admins|arrange|download|grant|edit|registered)$/;

/** What a visitor may still meet, with why; each must still be met, so the list only shrinks. It is empty: nothing a
    visitor can open carries a row id (stage 2 named the public preview's parts and Follow by their names). */
const ALLOWED = [];

/** The records that keep their numbers, behind a sign-in, each with why (owner, 2026-11-06: "Everything else ... keeps
    its numbers. Nobody identifies those by a code, and permissions protect them"). Matched against the path, query and
    anchor of an address a signed-in reader meets. */
const KEEP = [
    {pattern: /^\/admin\/activity\/\d+(\?|#|$)/, why: 'An activity log entry: nobody names one by a code, and PLATFORM.ACTIVITY.VIEW protects it.'},
    {pattern: /^\/admin\/people\/[A-Z0-9]+\/(sign-ins|identity)\/\d+\//, why: 'A sign-in or an identity check, under its person\'s reference: nobody names one by a code.'},
    {pattern: /^\/admin\/people\/signup-attempts\/\d+(\?|#|$)/, why: 'A sign-up attempt: nobody names one by a code, and PLATFORM.PERSON.VIEW protects it.'},
    {pattern: /^\/admin\/roles\/\d+(\/|\?|#|$)/, why: 'A role: nobody names one by a code here, and PLATFORM.ROLE permissions protect it.'},
    {pattern: /^\/admin\/certificates\/designs\/\d+(\/|\?|#|$)|^\/admin\/courses\/[A-Z0-9-]+\/certificate\?(.*&)?design=\d+/, why: 'A certificate design: nobody names one by a code, and CERTIFICATE.DESIGN permissions protect it.'},
    {pattern: /^\/admin\/courses\/page-layouts\/\d+(\/|\?|#|$)|[?&]layout=\d+/, why: 'A course page layout: nobody names one by a code.'},
    {pattern: /^\/admin\/courses\/[A-Z0-9-]+\/landing\/sections\/\d+|#landing-section-\d+$/, why: 'A landing page section, under its course\'s code.'},
    {pattern: /^\/admin\/courses\/[A-Z0-9-]+\/pricing\/\d+|[?&]edit_price=\d+|#price-\d+$/, why: 'A course\'s price option, under its course\'s code.'},
    {pattern: /^\/admin\/resources\/\d+(\/|\?|#|$)|[?&]resource_id=\d+/, why: 'A Resource Library file in Administration: learners reach it only through the reader.'},
    {pattern: /^\/admin\/course-reviews\/\d+\/|#review-\d+$/, why: 'A course review: nobody names one by a code, and COURSE.REVIEW.MODERATE protects it.'},
    {pattern: /^\/admin\/course\/credits\/\d+\/|#credit-\d+$/, why: 'A course credit: nobody names one by a code.'},
    {pattern: /\/enrolments\/\d+\/(remove|restore)$|^\/admin\/test-grants\/\d+\//, why: 'An enrolment or a test grant: nobody names one by a code.'},
    {pattern: /\/requests\/\d+\/decision$|#request-\d+$/, why: 'A course request: nobody names one by a code.'},
    {pattern: /\/refund-requests\/\d+\/|^\/admin\/commerce\/refunds\/\d+\/|^\/admin\/commerce\/attention\/(refund_request|refund_to_pay)\/\d+/, why: 'A refund request or a refund: nobody names one by a code.'},
    {pattern: /^\/admin\/commerce\/unmatched\/\d+(\/|\?|#|$)/, why: 'An unmatched deposit: nobody names one by a code.'},
    {pattern: /^\/admin\/commerce\/(ledger|trail|payment-events)\/\d+(\?|#|$)/, why: 'A money ledger line, a trail event or a payment event: nobody names one by a code.'},
    {pattern: /^\/admin\/site-analytics\/(lockouts|refused-emails)\/\d+\//, why: 'A lockout or a refused email address: nobody names one by a code.'},
    {pattern: /^\/mailing\/(messages|lists|templates)\/\d+(\/|\?|#|$)|^\/mailing\/digest\/updates\/\d+\/|^\/mailing\/digest\?(.*&)?edit=\d+|[?&]list=\d+|^\/account\/subscriptions\/lists\/\d+$/, why: 'An email campaign, a mailing list, an email template or a mailing update: nobody names one by a code, and Mailing permissions protect it.'},
    {pattern: /#course-node-\d+$|[?&](node_ids(%5B|\[)\d*(%5D|\])|parent_node_id|insert_parent)=\d+/, why: 'Course Content\'s own rows, posted or on the page, left as they are by the brief.'},
];

/** The row ids in one address, as words. */
function rowIds(address) {
    const url = new URL(address, base);
    if (url.origin !== new URL(base).origin) return [];
    const found = [];
    for (const segment of url.pathname.split('/').filter(Boolean)) if (/^\d+$/.test(segment)) found.push(`path segment ${segment}`);
    for (const [name, value] of url.searchParams) {
        const flag = FLAGS.test(name) && /^[01]$/.test(value);
        if ((/^\d+$/.test(value) && !COUNTS.test(name) && !flag) || (name === 'follow' && /:\d+$/.test(value))) found.push(`query ${name}=${value}`);
    }
    // An anchor of lower-case words and a number (#request-7); #course-GDN-101 is a code.
    if (/^#[a-z][a-z-]*-\d+$/.test(url.hash)) found.push(`anchor ${url.hash}`);
    return found;
}

/** Every address the page can open: links, forms and their GET submissions, sources, htmx and data-*-url. */
const gather = () => {
    const seen = [];
    const add = (address, where) => { if (address && !/^(#|mailto:|tel:|javascript:|data:|blob:)/.test(address)) seen.push({address, where}); };
    for (const el of document.querySelectorAll('[href],[action],[formaction],[src],[hx-get],[hx-post],[hx-put],[hx-delete]')) {
        for (const attribute of ['href', 'action', 'formaction', 'src', 'hx-get', 'hx-post', 'hx-put', 'hx-delete']) {
            if (el.hasAttribute(attribute)) add(el.getAttribute(attribute), `${el.tagName.toLowerCase()} ${attribute}`);
        }
    }
    for (const el of document.querySelectorAll('*')) {
        for (const attribute of el.getAttributeNames()) if (/^data-.*url(-value)?$/.test(attribute)) add(el.getAttribute(attribute), `${el.tagName.toLowerCase()} ${attribute}`);
    }
    // What each GET form would ask for, with each named button and each choice, the others as they stand.
    for (const form of document.querySelectorAll('form')) {
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') continue;
        const action = form.getAttribute('action') || location.pathname;
        const fields = [...form.elements].filter((el) => el.name && !el.disabled);
        const fixed = fields.filter((el) => !(el instanceof HTMLButtonElement) && !(el instanceof HTMLInputElement && ['radio', 'checkbox', 'submit'].includes(el.type)));
        const choices = fields.filter((el) => el instanceof HTMLButtonElement || (el instanceof HTMLInputElement && ['radio', 'checkbox', 'submit'].includes(el.type)));
        // As a browser submits it: the action's own query replaced by the fields, its anchor kept after them.
        const query = (extra) => {
            const params = new URLSearchParams();
            for (const el of fixed) if (el.value !== '' && el.type !== 'search') params.append(el.name, el.value);
            if (extra) params.append(extra.name, extra.value);
            const target = new URL(action, location.href);
            target.search = params.toString();
            return target.pathname + target.search + target.hash;
        };
        // The fixed fields alone are only worth reading when one of them holds something.
        if (fixed.some((el) => el.value !== '' && el.type !== 'search' && el.type !== 'hidden')) add(query(null), 'form fields');
        for (const choice of choices) add(query(choice), `form choice ${choice.name}`);
    }
    return seen;
};

/** Pages that differ only in an id, a code, a reference, a number or a slug share a pattern. */
const pattern = (address) => address
    .replace(/\/[0-9]+(?=\/|\?|$)/g, '/{id}')
    .replace(/\/(?=[A-Z0-9_-]*[A-Z])[A-Z0-9]+(?:[-_][A-Z0-9]+)*(?=\/|\?|$)/g, '/{code}')
    .replace(/^\/admin\/(course-items|courses\/categories|courses\/tags)\/(?!new(?=\/|\?|$)|inline|field)[a-z0-9-]+/, '/admin/$1/{slug}')
    .replace(/^\/(learn)\/[a-z0-9-]+\/(?!review|start|assessment\/session)[a-z0-9-]+/, '/$1/{slug}/{part}')
    .replace(/^\/courses\/(?!category\/|tag\/)[a-z0-9-]+\/preview\/(?!assessment\/)[a-z0-9-]+/, '/courses/{slug}/preview/{part}')
    .replace(/^\/(courses|landing|learn|bundles)\/(?!category\/|tag\/|tags$|tags\/)[a-z0-9-]+/, '/$1/{slug}')
    .replace(/^\/courses\/(category|tag)\/[a-z0-9-]+/, '/courses/$1/{slug}')
    .replace(/\/[0-9a-f]{16,}(?=\/|\?|$)/g, '/{key}');

/** One address per page: the path, with only the tab and a preview flag kept from the query. */
function normal(href) {
    const url = new URL(href, base);
    if (url.origin !== new URL(base).origin) return null;
    const kept = new URLSearchParams();
    for (const name of ['tab', 'preview']) if (url.searchParams.get(name)) kept.set(name, url.searchParams.get(name));
    return url.pathname + (kept.size ? `?${kept}` : '');
}

async function crawl(browser, who, cookie, starts, signedIn, met) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}});
    if (cookie) await context.addCookies([{name: 'catto_learning_session', value: cookie, url: base, secure: true}]);
    const page = await context.newPage();
    const queue = [...starts], seen = new Set(queue), perPattern = new Map(), failures = [], from = new Map(), visited = [];
    let pages = 0;
    const judge = (address, where, landed) => {
        const ids = rowIds(address);
        if (!ids.length) return;
        const url = new URL(address, base);
        const path = url.pathname + url.search + url.hash;
        const allowed = (signedIn ? KEEP : ALLOWED).find((entry) => entry.pattern.test(path));
        if (allowed) { met.add(allowed.why); return; }
        failures.push(`${who}: ${address} (${ids.join(', ')}) in ${where} on ${landed}`);
    };
    while (queue.length && pages < LIMIT) {
        const address = queue.shift();
        const key = pattern(address);
        if ((perPattern.get(key) || 0) >= 2) continue;
        perPattern.set(key, (perPattern.get(key) || 0) + 1);
        let response;
        try { response = await page.goto(base + address, {waitUntil: 'domcontentloaded', timeout: 30000}); } catch (error) { continue; }
        if (!response || !(response.headers()['content-type'] || '').includes('text/html')) continue;
        pages++;
        visited.push(address);
        // Any htmx swap the page makes on its own (a live refresh) has settled before the page is read.
        await page.waitForLoadState('networkidle', {timeout: 5000}).catch(() => {});
        const landed = new URL(page.url()).pathname + new URL(page.url()).search + new URL(page.url()).hash;
        judge(page.url(), 'the address bar', landed);
        for (const {address: found, where} of await page.evaluate(gather)) {
            judge(found, where, landed);
            const next = normal(found);
            if (!next || seen.has(next) || SKIP.test(next.split('?')[0]) || !/^(a href)$/.test(where)) continue;
            if (signedIn && !/^\/(admin|company|account|mailing|courses|bundles|learn)/.test(next)) continue;
            seen.add(next);
            from.set(next, landed);
            queue.push(next);
        }
    }
    await context.close();
    return {pages, failures, visited};
}

(async () => {
    fs.mkdirSync(output, {recursive: true});
    const browser = await chromium.launch();
    const met = new Set();
    const visitor = await crawl(browser, 'visitor', null, ['/', `/courses/${state.course.slug}`, `/courses/${state.course.slug}/preview`, '/courses', '/courses/tags', '/bundles', '/presentation', '/help', '/faq', '/contact', '/terms', '/refunds', '/privacy', '/login', '/register'], false, met);
    const learner = await crawl(browser, 'learner', state.learner.token, ['/account', '/account/courses', `/learn/${state.course.slug}`, '/account/orders', '/account/certificates', '/account/favourites', '/account/subscriptions', '/courses'], true, met);
    const companyAdmin = await crawl(browser, 'company administrator', state.company_admin.token, ['/company', '/company/people', '/company/orders', '/company/credits', '/company/enrolments', '/company/requests', '/company/favourites', '/company/profile', '/account', '/account/orders'], true, met);
    const admin = await crawl(browser, 'admin', state.admin.token, ['/admin/courses', '/admin', '/admin/people', '/admin/companies', '/admin/commerce/orders', '/admin/commerce/ledger', '/admin/commerce/unmatched', '/admin/wallets', '/admin/bundles', '/admin/promotions', '/admin/courses/categories', '/admin/courses/tags', '/admin/certificates', '/admin/messages', '/admin/course-items', '/admin/reports/popularity', '/admin/course-reviews', '/admin/courses/page-layouts', '/admin/courses/catalogue', '/admin/activity', '/admin/analytics/events', '/admin/course/credits', '/admin/course/enrolments', '/admin/course/requests', '/admin/site-analytics', '/mailing', '/mailing/subscribers', '/company', '/company/favourites', '/company/credits', '/account'], true, met);
    await browser.close();
    const unmet = ALLOWED.filter((entry) => !met.has(entry.why)).map((entry) => `allowed but never met, take it off ALLOWED: ${entry.why}`);
    const runs = {visitor, learner, company_administrator: companyAdmin, admin};
    const failures = [...Object.values(runs).flatMap((run) => run.failures), ...unmet];
    for (const failure of failures) console.log(`FAIL ${failure}`);
    const summary = {pages: Object.fromEntries(Object.entries(runs).map(([who, run]) => [who, run.pages])), failures: Object.fromEntries(Object.entries(runs).map(([who, run]) => [who, run.failures.length])), kept_met: [...met], unmet: unmet.length};
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({summary, failures, visited: Object.fromEntries(Object.entries(runs).map(([who, run]) => [who, run.visited]))}, null, 2));
    console.log(JSON.stringify(summary, null, 2));
    process.exit(failures.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
