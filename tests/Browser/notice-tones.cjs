/* A notice shows what kind of message it is (owner, 2026-11-05: "Yes, fix the ordinary notices"), in Chromium and Firefox, in
   light and dark, in every bundled theme and on the pages where a course is taken. In each of the three places a notice
   sits (among the messages at the top of a page, on the page, inside a card), the success, information, warning and danger
   notices have clearly different backgrounds and edges, and every word in them can be read on that background. The real
   "Correct." and "Not correct." are measured by reader-preview.cjs.
   Fixture: php tests/Browser/site-notification-fixture.php create|cleanup (in env_php_1), for its ADMIN and a public course. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-site-notification-state.json'));
const root = path.resolve(__dirname, '../..');
const THEMES = fs.readdirSync(path.join(root, 'themes')).filter((slug) => fs.existsSync(path.join(root, 'themes', slug, 'theme.json'))).map((slug) => { const theme = JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme; return `${theme.slug}-v${theme.version}`; });
const TONES = ['success', 'info', 'warning', 'danger'];
const results = [];
const check = (name, ok, detail = '') => { results.push(Boolean(ok)); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const luminance = (rgb) => { const [r, g, b] = rgb.map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
const distance = (a, b) => Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]);

/* Real notices' markup, set in the places real notices sit, so the site's own stylesheets decide how they look. */
const measure = (page, tones) => page.evaluate((list) => {
    const channels = (colour) => { const c = document.createElement('canvas').getContext('2d'); c.fillStyle = colour; c.fillRect(0, 0, 1, 1); return [...c.getImageData(0, 0, 1, 1).data].slice(0, 3); };
    const made = (tone, flash) => {
        const note = document.createElement('div');
        note.className = `cl-ui-notice cl-ui-notice--${tone}`;
        if (flash) { note.setAttribute('data-flash-message', ''); note.setAttribute('data-flash-type', tone); }
        note.innerHTML = '<div><strong class="cl-ui-notice-heading">Heading</strong><p>Words of the message, <strong>strong words</strong>, <a href="#a">a link</a>.</p><small>Small words.</small></div>';
        return note;
    };
    const main = document.querySelector('#cl-main') || document.querySelector('main');
    const stack = document.querySelector('.cl-flash-stack') || main;
    const card = document.createElement('section');
    card.className = 'cl-ui-surface';
    main.prepend(card);
    const onPage = document.createElement('div');
    main.prepend(onPage);
    const places = {'among the messages at the top': [stack, true], 'on the page': [onPage, false], 'inside a card': [card, false]};
    const out = {};
    for (const [place, [parent, flash]] of Object.entries(places)) {
        out[place] = {};
        for (const tone of list) {
            const note = made(tone, flash);
            parent.append(note);
            const style = getComputedStyle(note);
            out[place][tone] = {
                background: channels(style.backgroundColor), edge: channels(style.borderLeftColor), edgeWidth: parseFloat(style.borderLeftWidth),
                words: [...note.querySelectorAll('strong,p,a,small')].map((el) => ({tag: el.tagName, colour: channels(getComputedStyle(el).color)})),
            };
            note.remove();
        }
    }
    onPage.remove(); card.remove();
    return out;
}, tones);

async function run(browserType, name) {
    const browser = await browserType.launch();
    for (const scheme of ['light', 'dark']) {
        const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce', colorScheme: scheme});
        await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const page = await context.newPage();
        for (const where of [...THEMES.map((theme) => `/courses?theme_preview=${theme}`), `/courses/${state.course}/preview`]) {
            await page.goto(base + where, {waitUntil: 'load'});
            const label = `${name}, ${scheme}, ${where.includes('theme_preview') ? where.split('=')[1] : 'a page where a course is taken'}`;
            const looks = await measure(page, TONES);
            for (const [place, tones] of Object.entries(looks)) {
                const list = Object.entries(tones);
                let nearest = Infinity; let nearestEdge = Infinity; let pair = '';
                for (let i = 0; i < list.length; i++) for (let j = i + 1; j < list.length; j++) {
                    const d = distance(list[i][1].background, list[j][1].background);
                    if (d < nearest) { nearest = d; pair = `${list[i][0]} and ${list[j][0]}`; }
                    nearestEdge = Math.min(nearestEdge, distance(list[i][1].edge, list[j][1].edge));
                }
                check(`${label}: ${place}: the four have clearly different backgrounds`, nearest >= 60, `${pair} are ${nearest} apart`);
                check(`${label}: ${place}: the four have clearly different edges`, nearestEdge >= 60 && list.every(([, t]) => t.edgeWidth >= 3), `nearest ${nearestEdge}, widths ${list.map(([, t]) => t.edgeWidth)}`);
                const faint = list.flatMap(([tone, t]) => t.words.filter((w) => contrast(w.colour, t.background) < 4.5).map((w) => `${tone} ${w.tag} ${contrast(w.colour, t.background).toFixed(1)}`));
                check(`${label}: ${place}: every word can be read`, faint.length === 0, faint.join(', '));
            }
        }
        await context.close();
    }
    await browser.close();
}

(async () => {
    await run(chromium, 'Chromium');
    await run(firefox, 'Firefox');
    const failed = results.filter((ok) => !ok).length;
    console.log(`${results.length} checks, ${failed} failures`);
    process.exit(failed ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
