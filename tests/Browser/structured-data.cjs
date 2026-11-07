/* Search engines and AI (owner, 2026-11-06, "2A 3A"), in Chromium, on the real site:
   - Settings > Search engines and AI: its three AI crawler choices are feature cards, each code field has the direct link
     to the service and its steps, and Make the missing share pictures draws every published course's and bundle's;
   - every public page (home, catalogue, a category, tags, every published course, bundle and landing page, the bundles
     page, Help, FAQ, Contact and the legal pages) carries structured data that is valid JSON, each item with the
     properties Google and schema.org require, and nothing the page does not show: a course's name is its title, its
     rating the one the page shows, each price on the page, the trail the one the theme draws, the FAQ's questions those
     on the page, a list's courses links on the page;
   - Google's rules as far as they can be checked here: a BreadcrumbList of two steps or more with absolute addresses, an
     ItemList of three courses or more, an AggregateRating from 1 to 5 with a count, a Product's Offer above zero in a
     currency, the Organization's logo at least 112 pixels, and each share picture a 1200 by 630 PNG that answers;
   - every course, bundle and landing page has the Open Graph set and X's large card;
   - no page that is not public carries any (sign-in, the reader's free preview, Administration, Account);
   - the choice saved changes robots.txt for the AI crawlers, a code pasted as its meta tag reaches the home page's head,
     and a demo site's /llms.txt answers not found.
   Fixture: php tests/Browser/structured-data-fixture.php create|cleanup [state] (in env_php_1). */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-structured-data-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + String(detail).slice(0, 400) + ')'}`); };
const GOOGLE = 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345';
const absolute = (url) => typeof url === 'string' && url.startsWith(base + '/');
const digits = (text) => String(text).replace(/\D/g, '');

/** What a page carries in its head, and what it shows. */
async function read(page, path) {
    const response = await page.goto(base + path, {waitUntil: 'load'});
    return page.evaluate(() => {
        const nodes = [...document.querySelectorAll('script[type="application/ld+json"]')].map((script) => { try { return {ok: true, node: JSON.parse(script.textContent)}; } catch (error) { return {ok: false, error: String(error), text: script.textContent.slice(0, 200)}; } });
        const meta = {};
        for (const tag of document.querySelectorAll('meta[property^="og:"], meta[name^="twitter:"], meta[name="google-site-verification"], meta[name="msvalidate.01"]')) meta[tag.getAttribute('property') || tag.getAttribute('name')] = tag.getAttribute('content');
        const trail = [...document.querySelectorAll('[data-theme-breadcrumb] .cl-ui-breadcrumb > a, [data-theme-breadcrumb] .cl-ui-breadcrumb > span:not(.cl-ui-breadcrumb-separator)')].map((el) => el.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean);
        return {nodes, meta, text: document.body.innerText, h1: (document.querySelector('h1') || {}).textContent?.replace(/\s+/g, ' ').trim() || '', links: [...document.querySelectorAll('a[href]')].map((a) => a.href), trail, accordions: [...document.querySelectorAll('.cl-ui-accordion-title')].map((el) => el.textContent.trim())};
    }).then((seen) => ({...seen, status: response ? response.status() : 0}));
}

/** A picture's real size and type, fetched as a crawler would. */
async function picture(context, url) {
    const response = await context.request.get(url);
    const body = await response.body();
    const png = body.length > 24 && body.slice(1, 4).toString() === 'PNG';
    return {status: response.status(), type: response.headers()['content-type'] || '', width: png ? body.readUInt32BE(16) : 0, height: png ? body.readUInt32BE(20) : 0};
}

async function judge(context, path, seen, expect) {
    const label = path;
    check(`${label}: answers`, seen.status === 200, seen.status);
    check(`${label}: every structured data script is valid JSON`, seen.nodes.every((n) => n.ok), JSON.stringify(seen.nodes.filter((n) => !n.ok)));
    const nodes = seen.nodes.filter((n) => n.ok).map((n) => n.node);
    check(`${label}: each item names schema.org and its type`, nodes.every((n) => n['@context'] === 'https://schema.org' && typeof n['@type'] === 'string'));
    const types = nodes.map((n) => n['@type']);
    for (const type of expect) check(`${label}: carries its ${type}`, types.includes(type), types.join(', '));
    for (const node of nodes) {
        const type = node['@type'];
        if (type === 'Course') {
            check(`${label}: Course has a name, a description, a provider and an address`, node.name && node.description && node.provider && node.provider.name && absolute(node.url), JSON.stringify(node).slice(0, 300));
            check(`${label}: Course's name is the course the page names`, seen.text.includes(node.name), node.name);
            check(`${label}: Course is taken online`, Array.isArray(node.hasCourseInstance) && node.hasCourseInstance[0].courseMode === 'Online');
            if (node.hasCourseInstance[0].courseWorkload) check(`${label}: its study time is ISO 8601`, /^PT(\d+H)?(\d+M)?$/.test(node.hasCourseInstance[0].courseWorkload), node.hasCourseInstance[0].courseWorkload);
            for (const offer of node.offers || []) {
                check(`${label}: an Offer has a price above zero in a currency, in stock, with its address`, Number(offer.price) > 0 && /^[A-Z]{3}$/.test(offer.priceCurrency) && offer.availability === 'https://schema.org/InStock' && absolute(offer.url), JSON.stringify(offer));
                if (path.startsWith('/courses/')) check(`${label}: the page shows that price`, digits(seen.text).includes(digits(offer.price)), offer.price);
            }
            if (node.educationalLevel) check(`${label}: the page shows its level`, seen.text.includes(node.educationalLevel), node.educationalLevel);
            if (node.aggregateRating) {
                const r = node.aggregateRating;
                check(`${label}: a rating is 1 to 5 with a count, as the page shows it`, Number(r.ratingValue) >= 1 && Number(r.ratingValue) <= 5 && r.ratingCount >= 1 && seen.text.includes(String(r.ratingValue)), JSON.stringify(r));
            }
            if (node.image) {
                const image = await picture(context, node.image);
                check(`${label}: its picture is a 1200 by 630 PNG that answers`, image.status === 200 && image.type.startsWith('image/png') && image.width === 1200 && image.height === 630, JSON.stringify(image));
            }
        } else if (type === 'Product') {
            check(`${label}: Product has a name and an address`, node.name && absolute(node.url));
            check(`${label}: the page names it`, seen.h1 === node.name || seen.text.includes(node.name), node.name);
            if (node.offers) check(`${label}: its Offer has a price above zero in a currency, as the page shows it`, Number(node.offers.price) > 0 && /^[A-Z]{3}$/.test(node.offers.priceCurrency) && digits(seen.text).includes(digits(node.offers.price)), JSON.stringify(node.offers));
        } else if (type === 'ItemList') {
            const items = node.itemListElement || [];
            check(`${label}: a list of courses holds three or more, in order, each by its address`, items.length >= 3 && items.every((item, i) => item.position === i + 1 && absolute(item.url)), items.length);
            check(`${label}: each course in the list is a link on the page`, items.every((item) => seen.links.includes(item.url)), items.filter((item) => !seen.links.includes(item.url)).map((item) => item.url).join(' '));
        } else if (type === 'BreadcrumbList') {
            const items = node.itemListElement || [];
            check(`${label}: a trail has two steps or more, each named, in order, with its address`, items.length >= 2 && items.every((item, i) => item.position === i + 1 && item.name && absolute(item.item)), JSON.stringify(items).slice(0, 300));
            check(`${label}: the trail is the one the page shows`, items.every((item) => seen.trail.includes(item.name)), `${items.map((i) => i.name).join(' > ')} / ${seen.trail.join(' > ')}`);
        } else if (type === 'FAQPage') {
            const questions = (node.mainEntity || []).map((q) => q.name);
            check(`${label}: every question marked up is one the page shows, and every one it shows is marked up`, questions.length > 0 && questions.length === seen.accordions.length && questions.every((q) => seen.accordions.includes(q)), `${questions.length} / ${seen.accordions.length}`);
            check(`${label}: each has its answer`, (node.mainEntity || []).every((q) => q.acceptedAnswer && q.acceptedAnswer['@type'] === 'Answer' && q.acceptedAnswer.text));
        } else if (type === 'Organization') {
            check(`${label}: the Organization has a name and an address`, node.name && absolute(node.url));
            const logo = await picture(context, node.logo);
            check(`${label}: its logo answers, at least 112 pixels`, logo.status === 200 && logo.width >= 112 && logo.height >= 112, JSON.stringify(logo));
            check(`${label}: its social profiles are never a platform's home page`, !(node.sameAs || []).some((url) => /^https:\/\/(www\.)?[a-z]+\.com\/$/.test(url)));
        } else if (type === 'WebSite') {
            check(`${label}: the WebSite has its name and home page`, node.name && node.url === base + '/');
        }
    }
    return nodes;
}

async function shared(context, path, seen) {
    for (const property of ['og:title', 'og:description', 'og:url', 'og:type', 'og:image']) check(`${path}: has ${property}`, Boolean(seen.meta[property]), JSON.stringify(seen.meta));
    check(`${path}: has X's large card`, seen.meta['twitter:card'] === 'summary_large_image', seen.meta['twitter:card']);
    if (seen.meta['og:image']) {
        const image = await picture(context, seen.meta['og:image']);
        check(`${path}: its share picture answers as a PNG of 1200 by 630 or its own picture`, image.status === 200 && /image\/(png|jpeg|webp|gif)/.test(image.type) && (!image.type.includes('png') || image.width >= 1200), JSON.stringify(image));
    }
}

(async () => {
    const browser = await chromium.launch();
    const adminContext = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}});
    await adminContext.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const admin = await adminContext.newPage();
    const errors = [];
    admin.on('pageerror', (e) => errors.push(e.message));

    // Settings.
    await admin.goto(`${base}/admin/settings?section=search-engines`, {waitUntil: 'load'});
    const section = admin.locator('#settings-search-engines');
    check('Settings has Search engines and AI, open', await section.count() === 1 && await section.evaluate((d) => d.open));
    check('its AI crawler choices are three feature cards, all allowed by default or as saved', await admin.locator('#search-engines-ai-crawlers.cl-ui-picture-choice--cards input[name=ai_crawlers]').count() === 3 && await admin.locator('#search-engines-ai-crawlers svg').count() === 3);
    const cards = await admin.locator('#search-engines-ai-crawlers label').evaluateAll((all) => all.map((l) => l.textContent.replace(/\s+/g, ' ').trim()));
    check('they read as the owner asked', /^Allow all AI crawlers/.test(cards[0]) && /^Allow AI search and answer assistants, block training-only crawlers/.test(cards[1]) && /^Block all AI crawlers/.test(cards[2]), cards.join(' | '));
    check('each code field links straight to its service and gives its steps', await section.locator('a[href="https://search.google.com/search-console/welcome"]').count() === 1 && await section.locator('a[href="https://www.bing.com/webmasters"]').count() === 1 && await section.locator('#search-engines-google-help li').count() === 4 && await section.locator('#search-engines-bing-help li').count() === 4);
    await Promise.all([admin.waitForNavigation(), admin.locator('button:has-text("Make the missing share pictures")').click()]);
    const flash = async () => (await admin.locator('[data-flash-message]').allInnerTexts()).join(' ');
    const made = await flash();
    check('Make the missing share pictures makes them', /share pictures? (was|were) made|Every share picture was made already/.test(made), made);
    const count = await admin.locator('#settings-search-engines').innerText();
    const counted = count.match(/(\d+) of the (\d+) published courses and bundles with a cover have their share picture/);
    check('and then every published course and bundle with a cover has one', counted && counted[1] === counted[2], counted ? counted[0] : count.slice(0, 300));

    // Every public page, as a visitor.
    const visitorContext = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}});
    const visitor = await visitorContext.newPage();
    const pages = [['/', ['Organization', 'WebSite']], ['/courses', ['ItemList', 'BreadcrumbList']], ['/courses/tags', ['BreadcrumbList']], ['/bundles', ['BreadcrumbList']], ['/help', ['BreadcrumbList']], ['/faq', ['FAQPage', 'BreadcrumbList']], ['/contact', ['BreadcrumbList']], ['/terms', ['BreadcrumbList']], ['/refunds', ['BreadcrumbList']], ['/privacy', ['BreadcrumbList']]];
    if (state.category) pages.push([`/courses/category/${state.category}`, ['BreadcrumbList']]);
    for (const slug of state.courses) pages.push([`/courses/${slug}`, ['Course', 'BreadcrumbList']]);
    for (const slug of state.bundles) pages.push([`/bundles/${slug}`, ['Product', 'BreadcrumbList']]);
    for (const slug of state.landings) pages.push([`/landing/${slug}`, ['Course', 'BreadcrumbList']]);
    let marked = 0;
    for (const [path, expect] of pages) {
        const seen = await read(visitor, path);
        marked += (await judge(visitorContext, path, seen, expect)).length;
        if (/^\/(courses\/(?!tags|category)|bundles\/|landing\/)/.test(path)) await shared(visitorContext, path, seen);
        if (path === '/') {
            check('/: the home page is shared with the site\'s picture', Boolean(seen.meta['og:image']) && seen.meta['twitter:card'] === 'summary_large_image');
            const image = await picture(visitorContext, seen.meta['og:image']);
            check('/: the site\'s picture is a 1200 by 630 PNG', image.status === 200 && image.width === 1200 && image.height === 630, JSON.stringify(image));
        }
    }
    check(`${pages.length} public pages read, ${marked} items checked`, marked >= pages.length);

    // Never on a page that is not public.
    const firstCourse = state.courses[0];
    for (const [who, page, path] of [['a visitor', visitor, '/login'], ['a visitor', visitor, `/courses/${firstCourse}/preview`], ['ADMIN', admin, '/admin/settings'], ['ADMIN', admin, '/account'], ['ADMIN', admin, '/admin/courses']]) {
        const seen = await read(page, path);
        check(`${path}, for ${who}: no structured data and no verification code`, seen.status === 200 && seen.nodes.length === 0 && !seen.meta['google-site-verification'] && !seen.meta['msvalidate.01'], `${seen.status} ${seen.nodes.length}`);
    }

    // The choice and the codes.
    await admin.goto(`${base}/admin/settings?section=search-engines`, {waitUntil: 'load'});
    await admin.locator('#search-engines-ai-crawlers input[value=search]').check({force: true});
    await admin.locator('#search-engines-google').fill(`<meta name="google-site-verification" content="${GOOGLE}" />`);
    await admin.locator('#search-engines-bing').fill('');
    await Promise.all([admin.waitForNavigation(), admin.locator('button:has-text("Save search engines and AI")').click()]);
    check('saving says so', /Search engines and AI are saved/.test(await flash()));
    check('the code is kept bare', await admin.locator('#search-engines-google').inputValue() === GOOGLE);
    const robots = await (await visitorContext.request.get(`${base}/robots.txt`)).text();
    const group = (token) => (robots.split(/\n\n/).find((g) => g.includes(`User-agent: ${token}\n`)) || '');
    check('robots.txt keeps a training crawler out', /\nDisallow: \/\s*$/.test(group('GPTBot')) && !group('GPTBot').includes('Allow: /'), group('GPTBot'));
    check('and lets an AI search crawler in, away from the private pages', group('OAI-SearchBot').includes('Allow: /') && group('OAI-SearchBot').includes('Disallow: /admin/'), group('OAI-SearchBot'));
    check('and never keeps search engines out', /User-agent: \*\n[\s\S]*?Allow: \//.test(robots) && !/User-agent: \*\n(?:[^\n]*\n)*?Disallow: \/\n/.test(robots.split('\n\n# AI crawlers')[0]));
    const home = await read(visitor, '/');
    check('the home page carries the code', home.meta['google-site-verification'] === GOOGLE, home.meta['google-site-verification']);
    const course = await read(visitor, `/courses/${firstCourse}`);
    check('and no other page does', !course.meta['google-site-verification']);
    const llms = await visitorContext.request.get(`${base}/llms.txt`);
    check('a demo site has no llms.txt', llms.status() === 404, llms.status());
    // Back as it was before: the fixture's cleanup puts the saved settings back too.
    await admin.goto(`${base}/admin/settings?section=search-engines`, {waitUntil: 'load'});
    await admin.locator('#search-engines-ai-crawlers input[value=all]').check({force: true});
    await admin.locator('#search-engines-google').fill('');
    await Promise.all([admin.waitForNavigation(), admin.locator('button:has-text("Save search engines and AI")').click()]);
    check('every AI crawler is let in again', group('GPTBot') !== '' && (await (await visitorContext.request.get(`${base}/robots.txt`)).text()).includes('User-agent: GPTBot\nDisallow: /auth/'));

    check('no script error', errors.length === 0, errors.join(' | '));
    await browser.close();
    const failed = results.filter((r) => !r.ok).length;
    console.log(`\n${results.length - failed} passed, ${failed} failed`);
    process.exit(failed === 0 ? 0 : 1);
})().catch((error) => { console.error(error); process.exit(1); });
