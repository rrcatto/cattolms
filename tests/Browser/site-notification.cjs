/* The site notification (owner, 2026-11-05: "a notification message, under the Demo mode message ... set in settings",
   with a "count down in weeks, days, hours, minutes, seconds"), in Chromium and Firefox:
   - Settings has it straight after Site mode, refuses a shown notification with no message and a date already gone;
   - it shows under the demo notice, as wide as it, to a signed-out visitor, on the home page, the catalogue, sign-in and a
     legal page, in every bundled theme, on a phone too, and nothing scrolls sideways;
   - each kind has its own background and icon, in every theme and on the pages where a course is taken, and Settings shows all three;
   - the ticks are feature cards; the pages where a course is taken show it only with Also while taking a course ticked;
   - the count shows the five units from the largest that is not zero, one unit in the singular, and ticks, whatever the
     visitor's clock says, and after a page has changed in place;
   - without JavaScript it still shows, with the count and the date;
   - it goes away by itself at zero, and the server stops drawing it;
   - unticked it stops showing and keeps its words; Remove clears them.
   Fixture: php tests/Browser/site-notification-fixture.php create|soon <seconds>|cleanup (in env_php_1). */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-site-notification-state.json'));
const results = [];
const check = (name, ok, detail = '') => { results.push({name, ok: Boolean(ok)}); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const fixture = (...args) => execFileSync('podman', ['exec', '-u', 'cattotest', 'env_php_1', 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/site-notification-fixture.php ${args.join(' ')} >/dev/null 2>&1`]);
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const THEMES = fs.readdirSync(path.join(root, 'themes')).filter((slug) => fs.existsSync(path.join(root, 'themes', slug, 'theme.json'))).map((slug) => { const theme = JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme; return `${theme.slug}-v${theme.version}`; });
const box = '[data-site-notification]';

async function theForm(page) {
    // Without its #, or a page already there would only jump to it and show what it showed before.
    await page.goto(`${base}/admin/settings?section=site-notification`, {waitUntil: 'load'});
}
function inTheSitesTime(secondsAhead) {
    return new Intl.DateTimeFormat('sv-SE', {timeZone: 'Africa/Johannesburg', dateStyle: 'short', timeStyle: 'short'}).format(new Date(Date.now() + secondsAhead * 1000)).replace(' ', 'T');
}
async function save(page, {show = true, onCoursePages = false, message = '', heading = '', kind = 'promotion', ends = '', words = ''}) {
    await theForm(page);
    await page.locator('#settings-site-notification input[name=show]').setChecked(show, {force: true});
    await page.locator('#settings-site-notification input[name=on_course_pages]').setChecked(onCoursePages, {force: true});
    await page.locator(`#settings-site-notification input[name=kind][value=${kind}]`).check({force: true});
    await page.locator('#site-notification-heading').fill(heading);
    await page.locator('#site-notification-message').fill(message);
    await page.locator('#site-notification-ends').fill(ends);
    await page.locator('#site-notification-words').fill(words);
    await Promise.all([page.waitForNavigation(), page.locator('button:has-text("Save site notification")').click()]);
}
const units = (page) => page.locator(`${box} [data-unit]:not([hidden])`).evaluateAll((all) => all.map((u) => u.textContent.replace(/\s+/g, ' ').trim()));
const seconds = async (page) => Number(await page.locator(`${box} [data-unit=second] strong`).innerText());

/* If there is a choice it must be a real one (owner, 2026-11-05: "I need each different type of notification to show a real
   difference. different colour backgrounds, icons etc."): in every theme and on the pages where a course is taken, each
   kind has its own background and its own icon, and its words can be read on that background. */
const luminance = (rgb) => { const [r, g, b] = rgb.map((v) => { const c = v / 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; }); return 0.2126 * r + 0.7152 * g + 0.0722 * b; };
const contrast = (a, b) => { const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05); };
const lookOf = (page) => page.evaluate((s) => {
    const note = document.querySelector(`${s} .cl-ui-notice`);
    if (!note) return null;
    const channels = (colour) => { const c = document.createElement('canvas').getContext('2d'); c.fillStyle = colour; c.fillRect(0, 0, 1, 1); return [...c.getImageData(0, 0, 1, 1).data].slice(0, 3); };
    const svg = note.querySelector('.cl-ui-notice-icon svg');
    const heading = note.querySelector('.cl-ui-notice-heading');
    return {background: channels(getComputedStyle(note).backgroundColor), text: channels(getComputedStyle(note).color), heading: heading ? channels(getComputedStyle(heading).color) : null, icon: svg ? svg.innerHTML : '', iconBox: svg ? svg.getBoundingClientRect().width : 0};
}, box);
async function kindsDiffer(admin, name) {
    const looks = {};
    for (const kind of ['promotion', 'information', 'warning']) {
        await save(admin, {kind, heading: 'Heading', message: 'The message.', onCoursePages: true});
        for (const key of [...THEMES.map((theme) => `/courses?theme_preview=${theme}`), `/courses/${state.course}/preview`]) {
            await admin.goto(base + key, {waitUntil: 'load'});
            (looks[key] ||= {})[kind] = await lookOf(admin);
        }
    }
    const distance = (a, b) => Math.abs(a[0] - b[0]) + Math.abs(a[1] - b[1]) + Math.abs(a[2] - b[2]);
    for (const [where, kinds] of Object.entries(looks)) {
        const three = Object.values(kinds);
        const label = where.includes('theme_preview') ? where.split('=')[1] : 'a page where a course is taken';
        const nearest = Math.min(distance(three[0].background, three[1].background), distance(three[0].background, three[2].background), distance(three[1].background, three[2].background));
        check(`${name}: ${label}: the three kinds have clearly different backgrounds`, nearest >= 90, JSON.stringify(three.map((l) => l.background)));
        check(`${name}: ${label}: the three kinds have different icons`, three.every((l) => l.icon !== '' && l.iconBox >= 20) && new Set(three.map((l) => l.icon)).size === 3);
        check(`${name}: ${label}: their words can be read`, three.every((l) => contrast(l.text, l.background) >= 4.5 && contrast(l.heading, l.background) >= 4.5), JSON.stringify(three.map((l) => [l.text, l.heading, l.background])));
    }
}

async function run(browserType, name) {
    const browser = await browserType.launch();
    const errors = [];
    const listen = (page) => { page.on('pageerror', (e) => errors.push(e.message)); return page; };
    const adminContext = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'});
    await adminContext.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const admin = listen(await adminContext.newPage());
    const visitor = listen(await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'})).newPage());

    await kindsDiffer(admin, name);
    await theForm(admin);
    admin.once('dialog', (dialog) => dialog.accept());
    await Promise.all([admin.waitForNavigation(), admin.locator('button:has-text("Remove the notification")').click()]);

    await theForm(admin);
    const order = await admin.locator('.cl-admin-workspace details[id^="settings-"]').evaluateAll((all) => all.map((d) => d.id));
    check(`${name}: Settings has it straight after Site mode`, order.indexOf('settings-site-notification') === order.indexOf('settings-site') + 1, order.slice(0, 3).join(', '));
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    check(`${name}: nothing shows until one is saved`, await visitor.locator(box).count() === 0);

    await save(admin, {message: ''});
    check(`${name}: a shown notification with no message is refused`, await admin.getByText('Write the message, or untick').count() >= 1 && await admin.locator(box).count() === 0);
    await save(admin, {message: 'Too late', ends: '2020-01-01T10:00'});
    check(`${name}: a date already gone is refused`, await admin.getByText('That date and time has already passed').count() >= 1 && await admin.locator(box).count() === 0);

    await save(admin, {heading: 'Winter sale', message: 'Every course is reduced this month.'});
    for (const path of ['/', '/courses', '/sign-in', '/terms']) {
        await visitor.goto(base + path, {waitUntil: 'load'});
        const words = await visitor.locator(box).innerText().catch(() => '');
        const under = await visitor.evaluate((s) => { const demo = document.querySelector('[data-demo-site]'); const note = document.querySelector(s); return Boolean(demo && note && demo.compareDocumentPosition(note) & Node.DOCUMENT_POSITION_FOLLOWING) && Math.abs(demo.querySelector('.cl-ui-notice').getBoundingClientRect().width - note.querySelector('.cl-ui-notice').getBoundingClientRect().width) < 1; }, box);
        check(`${name}: a visitor sees it under the demo notice, as wide as it, at ${path}`, words.includes('Winter sale') && words.includes('Every course is reduced this month.') && under, words);
    }
    check(`${name}: with no date it has no count`, await visitor.locator(`${box} [data-site-countdown]`).count() === 0);

    await theForm(admin);
    check(`${name}: Settings shows how each kind looks`, await admin.locator('[data-site-notification-samples] .cl-ui-notice--solid .cl-ui-notice-icon svg').count() === 3 && await admin.locator('#site-notification-kind input + *, #site-notification-kind label').filter({hasText: 'Amber, with a warning triangle'}).count() >= 1);
    const cards = await admin.locator('#site-notification-show label').evaluateAll((all) => all.map((l) => l.textContent.replace(/\s+/g, ' ').trim()));
    check(`${name}: its ticks are feature cards with an icon, a title and a note`, await admin.locator('#site-notification-show.cl-ui-picture-choice--cards.cl-ui-picture-choice--multiple svg').count() === 2 && /^Show this notification\s*At the top/.test(cards[0]) && /^Also while taking a course\s*On the lessons/.test(cards[1]), cards.join(' | '));
    check(`${name}: nothing still says every page`, await admin.locator('#settings-site-notification').getByText('every page').count() === 0);
    const taking = `${base}/courses/${state.course}/preview`;
    await visitor.goto(taking, {waitUntil: 'load'});
    check(`${name}: not on a page where a course is taken, by default`, await visitor.locator('[data-demo-site]').count() === 1 && await visitor.locator(box).count() === 0);
    await visitor.goto(`${base}/courses/${state.course}`, {waitUntil: 'load'});
    check(`${name}: though the course's own page in the catalogue has it`, await visitor.locator(box).count() === 1);
    await save(admin, {kind: 'warning', heading: 'Winter sale', message: 'Every course is reduced this month.', onCoursePages: true});
    await visitor.goto(taking, {waitUntil: 'load'});
    check(`${name}: and with Also while taking a course ticked, there too`, await visitor.locator(box).count() === 1);
    await theForm(admin);
    check(`${name}: the tick is kept`, await admin.locator('#settings-site-notification input[name=on_course_pages]').isChecked());

    await save(admin, {kind: 'warning', message: 'The site goes down for maintenance.', ends: inTheSitesTime(16 * 86400 + 3 * 3600 + 300), words: 'Going offline in'});
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    const long = await units(visitor);
    check(`${name}: the count shows all five units`, long.length === 5 && /^2 weeks$/.test(long[0]) && /^2 days$/.test(long[1]) && /hours?$/.test(long[2]) && /minutes?$/.test(long[3]) && /seconds?$/.test(long[4]), long.join(', '));
    check(`${name}: with the words before it and the date after it`, (await visitor.locator(`${box} .cl-site-countdown-words`).innerText()) === 'Going offline in' && /Until \d+ \w+ \d{4} at \d\d:\d\d \S+/.test(await visitor.locator(`${box} .cl-site-countdown-until`).innerText()));
    check(`${name}: a warning is drawn as a warning`, await visitor.locator(`${box} .cl-ui-notice--warning`).count() === 1);
    const first = await seconds(visitor);
    await visitor.waitForTimeout(2200);
    check(`${name}: it ticks`, await seconds(visitor) !== first);

    const wrongClock = await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}})).newPage();
    await wrongClock.addInitScript(() => { const real = Date.now.bind(Date); Date.now = () => real() + 5 * 86400 * 1000; });
    await wrongClock.goto(`${base}/`, {waitUntil: 'load'});
    await wrongClock.waitForTimeout(1300);
    check(`${name}: a visitor's wrong clock does not change it`, /^2 weeks$/.test((await units(wrongClock))[0]) && /^2 days$/.test((await units(wrongClock))[1]), (await units(wrongClock)).join(', '));
    await wrongClock.context().close();

    const plain = await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, javaScriptEnabled: false, reducedMotion: 'reduce'})).newPage();
    await plain.goto(`${base}/`, {waitUntil: 'load'});
    const still = await plain.locator(box).innerText();
    check(`${name}: without JavaScript it shows the count and the date`, still.includes('The site goes down for maintenance.') && /2 weeks/.test(still) && /Until /.test(still), still);
    await plain.context().close();

    for (const key of THEMES) {
        await visitor.goto(`${base}/courses?theme_preview=${key}`, {waitUntil: 'load'});
        const fit = await visitor.evaluate((s) => { const demo = document.querySelector('[data-demo-site] .cl-ui-notice'); const note = document.querySelector(s + ' .cl-ui-notice'); return note && demo ? Math.abs(demo.getBoundingClientRect().width - note.getBoundingClientRect().width) : -1; }, box);
        check(`${name}: ${key} draws it as wide as the demo notice`, fit >= 0 && fit < 1, String(fit));
    }
    const phone = listen(await (await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 390, height: 844}, reducedMotion: 'reduce'})).newPage());
    await phone.goto(`${base}/`, {waitUntil: 'load'});
    check(`${name}: on a phone it fits and nothing scrolls sideways`, await phone.locator(box).isVisible() && await phone.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await phone.context().close();

    await admin.goto(`${base}/admin/site-analytics/log`, {waitUntil: 'load'});
    const before = await seconds(admin);
    await admin.evaluate(() => document.getElementById('catto-live').dispatchEvent(new CustomEvent('catto-live-refresh')));
    await admin.waitForTimeout(2500);
    check(`${name}: it keeps ticking after a page has changed in place`, await admin.locator(box).count() === 1 && await seconds(admin) !== before);

    await save(admin, {message: 'The site goes down for maintenance.', ends: inTheSitesTime(7200), words: ''});
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    const near = await units(visitor);
    check(`${name}: units above the largest are left out, and it says the standard words`, near.length <= 3 && /hours?$/.test(near[0]) && (await visitor.locator(`${box} .cl-site-countdown-words`).innerText()) === 'Time left:', near.join(', '));
    fixture('soon', 66);
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    const single = await units(visitor);
    check(`${name}: one unit is in the singular`, single.length === 2 && single[0] === '1 minute', single.join(', '));

    fixture('soon', 4);
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    check(`${name}: it is showing just before zero`, await visitor.locator(box).isVisible());
    await visitor.waitForTimeout(5200);
    check(`${name}: it goes away by itself at zero`, !await visitor.locator(box).isVisible());
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    check(`${name}: and the next page does not draw it`, await visitor.locator(box).count() === 0);
    await theForm(admin);
    check(`${name}: Settings says its countdown has ended`, await admin.locator('#settings-site-notification').getByText('Its countdown has ended').count() === 1);

    await save(admin, {message: 'Kept while switched off.', show: false});
    await visitor.goto(`${base}/`, {waitUntil: 'load'});
    check(`${name}: unticked it stops showing`, await visitor.locator(box).count() === 0);
    await theForm(admin);
    check(`${name}: and keeps its words`, (await admin.locator('#site-notification-message').inputValue()) === 'Kept while switched off.');
    admin.once('dialog', (dialog) => dialog.accept());
    await Promise.all([admin.waitForNavigation(), admin.locator('button:has-text("Remove the notification")').click()]);
    check(`${name}: Remove clears them`, (await admin.locator('#site-notification-message').inputValue()) === '');

    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

(async () => {
    for (const [type, name] of [[chromium, 'Chromium'], [firefox, 'Firefox']]) await run(type, name);
    const failures = results.filter((r) => !r.ok);
    console.log(`${results.length} checks, ${failures.length} failures.`);
    process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
