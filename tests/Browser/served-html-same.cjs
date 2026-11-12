/* The same HTML before and after a move (CattoPHP Phase 3: cattolms's interface moves into CattoPHP with no visible change).
   `save` fetches every page the every-page crawl visited (its results.json, CATTO_EVERY_PAGE_RESULTS), as ADMIN and as a
   visitor, as it is served (after HtmlLayout, which changes whitespace only where nothing drawn changes, so a template's
   own whitespace that no browser draws is not a difference; CATTO_SAME_HTML_AS_BUILT=1 compares the page as Twig drew it,
   X-Served-Html: as-built, which only a development site answers), and keeps each with what differs on every request
   taken out: nonces, form tokens, the layout's marks. `compare` fetches the same pages again and
   lists every one whose HTML changed, with its first differing lines. A move of templates or of the PHP behind them must
   leave every page the same; a page that differs is explained (a live figure, the check's own visit in the Site Log) or
   the move is wrong. Pages are kept in CATTO_SAME_HTML_STORE (/tmp/catto-served-html-same).
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), its ADMIN session, the same one for
   both runs. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const store = process.env.CATTO_SAME_HTML_STORE || '/tmp/catto-served-html-same';
const mode = process.argv[2];
const AT_ONCE = Number(process.env.CATTO_PAGES || 6);
const asBuilt = process.env.CATTO_SAME_HTML_AS_BUILT ? {'X-Served-Html': 'as-built'} : {};
// What is not a page, as every-page.cjs has it.
const SKIP = /^\/(learn\/|logout|auth\/|webhooks\/|api\/|mcp)|\/(download|export|delete|remove|reset|revoke|cancel)(\/|$)|\.(pdf|xlsx|csv|xml|txt|json|svg|png|jpe?g|webp|zip)$|^\/sitemap|^\/robots/;

/** What differs on every request, taken out: a script's nonce, a form's token, the layout's marks, the countdown. */
const normal = (html) => html
    .replace(/ nonce="[^"]*"/g, ' nonce=""')
    // The versions the page names, which a move between CattoPHP's folder and its release changes.
    .replace(/<meta name="generator" content="[^"]*">/, '<meta name="generator" content="">')
    .replace(/<!--(\/?)as-written:[0-9a-f]+-->/g, '<!--$1as-written-->')
    .replace(/(["'=:])[A-Za-z0-9_\-+/]{32,}={0,2}(["'&])/g, '$1(token)$2')
    // The Register form's opening time, and the site notification's countdown, drawn from the time of the request.
    .replace(/ name="signup_opened" value="[^"]*"/g, ' name="signup_opened" value=""')
    .replace(/ data-site-now="\d+"/g, ' data-site-now=""')
    .replace(/<strong>\d+<\/strong> <span data-one="([a-z]+)" data-many="([a-z]+)">[a-z]+<\/span>/g, '<strong>(n)</strong> <span data-one="$1" data-many="$2">(unit)</span>');

/** Lines to compare: the page split at each tag, so a difference names the tag it is in. */
const lines = (html) => html.replace(/></g, '>\n<').split('\n');

function pages() {
    if (mode === 'compare') return JSON.parse(fs.readFileSync(path.join(store, 'index.json')));
    const crawl = JSON.parse(fs.readFileSync(process.env.CATTO_EVERY_PAGE_RESULTS || '/tmp/catto-every-page/results.json'));
    const seen = new Map();
    for (const result of crawl.results) {
        const who = result.who.split(' ')[0];
        if (result.status >= 400 || SKIP.test(result.address)) continue;
        seen.set(`${who} ${result.address}`, {who, address: result.address});
    }
    return [...seen.values()].map((page, number) => ({...page, file: `${String(number).padStart(4, '0')}.html`}));
}

(async () => {
    if (!['save', 'compare'].includes(mode)) { console.error('Usage: node served-html-same.cjs save|compare'); process.exit(2); }
    const list = pages();
    fs.mkdirSync(store, {recursive: true});
    const fetchers = {
        admin: await playwright.request.newContext({ignoreHTTPSErrors: true, extraHTTPHeaders: {...asBuilt, Cookie: `catto_learning_session=${state.token}`}}),
        visitor: await playwright.request.newContext({ignoreHTTPSErrors: true, extraHTTPHeaders: asBuilt}),
    };
    const changed = [];
    let next = 0;
    const worker = async () => {
        while (next < list.length) {
            const page = list[next++];
            const answer = await fetchers[page.who].get(base + page.address, {maxRedirects: 0, timeout: 90000});
            const html = `status ${answer.status()}\n` + normal(await answer.text());
            const file = path.join(store, page.file);
            if (mode === 'save') { fs.writeFileSync(file, html); continue; }
            const before = fs.readFileSync(file, 'utf8');
            if (before === html) continue;
            const a = lines(before), b = lines(html), shown = [];
            for (let i = 0; i < Math.max(a.length, b.length) && shown.length < 3; i++) {
                if (a[i] !== b[i]) shown.push(`    line ${i + 1}\n      was: ${(a[i] ?? '(end)').slice(0, 240)}\n      now: ${(b[i] ?? '(end)').slice(0, 240)}`);
            }
            fs.writeFileSync(file.replace(/\.html$/, '.now.html'), html);
            changed.push(`${page.who} ${page.address} (${page.file})\n${shown.join('\n')}`);
        }
    };
    await Promise.all(Array.from({length: AT_ONCE}, worker));
    await Promise.all(Object.values(fetchers).map((fetcher) => fetcher.dispose()));
    if (mode === 'save') {
        fs.writeFileSync(path.join(store, 'index.json'), JSON.stringify(list, null, 1));
        console.log(`${list.length} pages saved in ${store}.`);
        return;
    }
    for (const page of changed) console.log(`CHANGED ${page}`);
    console.log(`${list.length - changed.length} of ${list.length} pages the same.`);
    process.exit(changed.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
