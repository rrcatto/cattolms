/* The served page's layout draws nothing differently (owner, 2026-11-09: "the html must be easily human readable which means
   it needs newlines and hierarchical indentation"; "yes, start Phase 3 with the body HTML formatter"). For each page, in
   Chromium and Firefox, at 1440 and 390px: the page as Twig drew it (asked for with X-Served-Html: as-built, which only a
   development site answers) and the same HTML laid out by the site's own HtmlLayout (served-html-format.php) are served at
   the page's own address, and every element's box and a full-page screenshot must be the same. Public pages, the course
   reader's public preview, and pages signed in as ADMIN, the component gallery among them.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), its ADMIN session. */
const playwright = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const browsers = (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',');
const sql = (query) => execFileSync('podman', ['exec', 'env_postgres_1', 'psql', '-U', 'catto_learning', '-d', 'catto_learning', '-tAq', '-c', query]).toString().trim();
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };

const course = sql("SELECT slug || ' ' || code FROM courses WHERE status = 'published' ORDER BY catalogue_position NULLS LAST, id LIMIT 1").split(' ');
const bundle = sql("SELECT slug FROM bundles WHERE status = 'published' ORDER BY id LIMIT 1");
const tag = sql('SELECT t.slug FROM tags t WHERE EXISTS (SELECT 1 FROM course_tags ct WHERE ct.tag_id = t.id) ORDER BY t.id LIMIT 1');
const visitor = ['/', '/courses', '/courses/tags', `/courses/tag/${tag}`, '/bundles', `/bundles/${bundle}`, `/courses/${course[0]}`, `/courses/${course[0]}/preview`,
    '/presentation', '/help', '/faq', '/contact', '/terms', '/privacy', '/refunds', '/login', '/register', '/no-such-page'];
const admin = ['/admin', '/admin/system/ui-components', '/admin/courses', `/admin/courses/${course[1]}`, `/admin/courses/${course[1]}/content`, '/admin/courses/categories',
    '/admin/courses/tags', '/admin/courses/page-layouts', '/admin/people', '/admin/companies', '/admin/settings', '/admin/site-analytics', '/admin/site-analytics/log',
    '/admin/certificates/designs', '/admin/bundles', '/account', '/account/courses', '/account/security', '/company'];

function laidOut(html) {
    return execFileSync('podman', ['exec', '-i', '-u', 'cattotest', 'env_php_1', 'sh', '-lc', 'cd /home/cattotest/code/current && SHELL_VERBOSITY=-1 php tests/Browser/served-html-format.php'], {input: html, maxBuffer: 64 * 1024 * 1024}).toString();
}

async function look(browser, url, html, width, cookie) {
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, reducedMotion: 'reduce'});
    if (cookie) await context.addCookies([{name: 'catto_learning_session', value: cookie, url: base, secure: true}]);
    const page = await context.newPage();
    await page.clock.install({time: new Date('2026-11-09T08:00:00Z')});
    await page.route(url, (route) => route.request().isNavigationRequest() ? route.fulfill({status: 200, contentType: 'text/html; charset=utf-8', body: html}) : route.continue());
    await page.goto(url, {waitUntil: 'load'});
    // Every font the page names, loaded (an off-screen field does not ask for its own), and nothing moving: animated drawings
    // and transitions keep going whatever the clock says, so two loads of the same page differ without this.
    await page.evaluate(async () => {
        await Promise.all([...document.fonts].map((font) => font.load().catch(() => null)));
        await document.fonts.ready;
        // Every picture decoded: under the release gate's load a picture still decoding drew in one look and not the other. A
        // picture that loads only once it is scrolled to (loading="lazy") and has not loaded is left out: it never decodes.
        await Promise.all([...document.images].filter((image) => image.complete || image.loading !== 'lazy').map((image) => image.decode().catch(() => null)));
        const still = document.createElement('style');
        still.textContent = '*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important}';
        document.head.appendChild(still);
        document.querySelectorAll('svg').forEach((svg) => { if (svg.pauseAnimations) { svg.pauseAnimations(); svg.setCurrentTime(0); } });
        // The site notification's countdown ticks every second, and its width moves the notice's: frozen, with the same
        // figures in both looks (the script stops once its mark is gone).
        document.querySelectorAll('[data-site-countdown]').forEach((timer) => {
            timer.removeAttribute('data-site-countdown');
            timer.querySelectorAll('.cl-site-countdown-unit strong').forEach((count) => { count.textContent = '00'; });
            timer.querySelectorAll('.cl-site-countdown-unit [data-many]').forEach((word) => { word.textContent = word.dataset.many; });
        });
    });
    await page.waitForTimeout(300);
    const boxes = await page.evaluate(() => [...document.body.querySelectorAll('*')].map((element) => {
        const box = element.getBoundingClientRect();
        // By tag name and box: the content editor gives its elements a new random id on every load.
        return `${element.tagName} ${[box.x, box.y, box.width, box.height].map((v) => Math.round(v * 2) / 2).join(',')}`;
    }));
    // Out of the screenshot, after the boxes are measured: the countdown and the live time, and the drawn pictures of people and
    // companies, which are animated drawings shown as images that keep moving inside their own box.
    await page.addStyleTag({content: '.cl-site-countdown,[data-live-when],img[src*="/logo"],img[src*="/profile/image"],img[src*="/avatar"]{visibility:hidden!important}'});
    const shot = await page.screenshot({fullPage: true});
    await context.close();
    return {boxes, shot};
}

(async () => {
    check('a published course, bundle and tag to look at', course.length === 2 && bundle !== '' && tag !== '', `${course} ${bundle} ${tag}`);
    for (const name of browsers) {
        const browser = await playwright[name].launch();
        for (const [who, paths, cookie] of [['visitor', visitor, null], ['admin', admin, state.token]]) {
            const fetcher = await playwright.request.newContext({ignoreHTTPSErrors: true, extraHTTPHeaders: {'X-Served-Html': 'as-built', ...(cookie ? {Cookie: `catto_learning_session=${cookie}`} : {})}});
            for (const path of paths) {
                if (process.env.CATTO_SERVED_HTML_PATHS && !process.env.CATTO_SERVED_HTML_PATHS.split(',').includes(path)) continue;
                const url = base + path;
                const answer = await fetcher.get(url, {maxRedirects: 0});
                if (![200, 404].includes(answer.status()) || !(answer.headers()['content-type'] || '').includes('text/html')) {
                    check(`${name} ${who} ${path}: answers with a page`, false, `status ${answer.status()}`);
                    continue;
                }
                const asBuilt = await answer.text();
                // CATTO_SERVED_HTML_CONTROL=1 serves the page as built twice, to see what differs between two loads of the same HTML.
                const laid = process.env.CATTO_SERVED_HTML_CONTROL ? asBuilt : laidOut(asBuilt);
                if (!process.env.CATTO_SERVED_HTML_CONTROL) check(`${name} ${who} ${path}: laid out`, laid !== asBuilt && /^<!doctype html>\n<html[^>]*>\n\t<head>\n/i.test(laid));
                for (const width of [1440, 390]) {
                    const compare = async () => {
                        const a = await look(browser, url, asBuilt, width, cookie);
                        const b = await look(browser, url, laid, width, cookie);
                        const moved = [];
                        if (a.boxes.length !== b.boxes.length) moved.push(`${a.boxes.length} elements, then ${b.boxes.length}`);
                        for (let i = 0; i < Math.min(a.boxes.length, b.boxes.length) && moved.length < 3; i++) if (a.boxes[i] !== b.boxes[i]) moved.push(`${a.boxes[i]} -> ${b.boxes[i]}`);
                        return {a, b, moved};
                    };
                    // Looked at up to three times before a difference counts: a page can draw differently between two loads of the
                    // same HTML, most of all while the release gate runs its other checks beside this one.
                    let {a, b, moved} = await compare();
                    for (let again = 0; again < 2 && (moved.length || !a.shot.equals(b.shot)); again++) ({a, b, moved} = await compare());
                    check(`${name} ${who} ${width} ${path}: every element where it was`, moved.length === 0, moved.join('; '));
                    check(`${name} ${who} ${width} ${path}: the same pixels`, a.shot.equals(b.shot));
                    if (!a.shot.equals(b.shot) && process.env.CATTO_BROWSER_OUTPUT) {
                        fs.mkdirSync(process.env.CATTO_BROWSER_OUTPUT, {recursive: true});
                        const stem = `${process.env.CATTO_BROWSER_OUTPUT}/${name}-${who}-${width}-${path.replace(/[^a-z0-9]+/gi, '_')}`;
                        fs.writeFileSync(`${stem}-as-built.png`, a.shot);
                        fs.writeFileSync(`${stem}-laid-out.png`, b.shot);
                    }
                }
            }
            await fetcher.dispose();
        }
        await browser.close();
    }
    const failed = results.filter((r) => !r.ok);
    console.log(`${results.length - failed.length} of ${results.length} checks passed.`);
    process.exit(failed.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
