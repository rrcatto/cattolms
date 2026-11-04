/* Site Analytics from the outside (owner, 2026-11-04, the approved mockup REPORTS/site-analytics-mockup/), filled by the real
   recording and rules (site-analytics-fixture.php create):
   - in every bundled theme at 1440 and 390px through the theme preview (nothing is activated): the Overview, the Site Log,
     Flagged Activity, one address, Lockouts and Site protection settings pass the shared page checks (page-construction.cjs);
   - the menu has the five pages, the figures open what they count, and each page's cards choose its table;
   - without JavaScript the feature cards' Show button and the filters still work;
   - a signed-out visitor and an account without the permission never see the pages;
   - the sign-in form carries the hidden field, never shown;
   - Lock out, Lift, Not a problem and the email list work through their forms, and the warnings follow the choice;
   - what the server refuses is shown and cannot be lifted, and the firewall tick asks for a ban and a release;
   - the address in use now can never be locked out from a page;
   - a lockout on the address this check comes from answers every page with the bare refusal, no cookie and no theme, and
     a webhook still gets through; when it is lifted the site answers again;
   - Site protection saves, refuses a wrong length and resets.
   Fixtures: section-pages-fixture.php create (an ADMIN to look with) and site-analytics-fixture.php create, then both cleanup
   (in env_php_1). */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const {execFileSync} = require('node:child_process');
const root = path.resolve(__dirname, '../..');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const themes = fs.readdirSync(path.join(root, 'themes')).filter(slug => fs.existsSync(path.join(root, 'themes', slug, 'theme.json')))
  .map(slug => JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme);
const only = (process.env.CATTO_THEMES || '').split(',').filter(Boolean);
const widths = (process.env.CATTO_WIDTHS || '1440,390').split(',').map(Number);
const {inspect, judge} = require('./page-construction.cjs');
const failures = [];
let checks = 0;
const check = (ok, what) => { checks++; if (!ok) failures.push(what); };
const text = async (page) => (await page.locator('main').evaluate((main) => main.textContent)).replace(/\s+/g, ' ');
// A form sent or a choice made, and the page it leads to.
const go = async (page, action) => { await Promise.all([page.waitForNavigation({waitUntil: 'load'}), action()]); };
const pages = {
  overview: '/admin/site-analytics', log: '/admin/site-analytics/log', flagged: '/admin/site-analytics/flagged',
  address: '/admin/site-analytics/address/198.51.100.23', lockouts: '/admin/site-analytics/lockouts', protection: '/admin/site-analytics/protection',
};
const php = (script, ...args) => execFileSync('podman', ['exec', '-u', 'cattotest', process.env.CATTO_PHP_CONTAINER || 'env_php_1', 'php', script, ...args], {encoding: 'utf8'});
const sql = (statement) => execFileSync('podman', ['exec', 'env_postgres_1', 'psql', '-U', 'catto_learning', '-d', 'catto_learning', '-tAc', statement], {encoding: 'utf8'}).trim();

async function signedIn(browser, options = {}) {
  const context = await browser.newContext({ignoreHTTPSErrors: true, reducedMotion: 'reduce', viewport: {width: 1440, height: 900}, ...options});
  await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
  return context;
}

(async () => {
  const browser = await chromium.launch({headless: true});
  // What a server's firewall would tell the site, so every theme also draws Refused by the server.
  php('tests/Browser/site-analytics-fixture.php', 'server-view');
  for (const theme of themes) {
    const key = `${theme.slug}-v${theme.version}`;
    if (only.length && !only.includes(theme.slug)) continue;
    for (const width of widths) {
      const context = await signedIn(browser, {viewport: {width, height: 900}});
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', (error) => errors.push(error.message));
      for (const [name, route] of Object.entries(pages)) {
        const separator = route.includes('?') ? '&' : '?';
        const answer = await page.goto(`${base}${route}${separator}theme_preview=${key}`, {waitUntil: 'load'});
        check(answer.status() === 200, `${key} ${width}px ${name}: answers ${answer.status()}`);
        const report = await page.evaluate(inspect);
        for (const fault of judge(report, `${key} ${width}px ${name}`)) check(false, fault);
      }
      check(errors.length === 0, `${key} ${width}px: no script errors (${errors.join(' | ')})`);
      await context.close();
    }
  }

  // The pages themselves, in the active theme.
  const context = await signedIn(browser);
  const page = await context.newPage();
  await page.goto(`${base}/admin/site-analytics`, {waitUntil: 'load'});
  const menu = await page.locator('nav').first().evaluate(() => [...document.querySelectorAll('nav a')].map((a) => a.getAttribute('href')));
  for (const route of ['/admin/site-analytics', '/admin/site-analytics/log', '/admin/site-analytics/flagged', '/admin/site-analytics/lockouts', '/admin/site-analytics/protection']) check(menu.includes(route), `the menu has ${route}`);
  let main = await text(page);
  for (const words of ['Needs your attention', '198.51.100.23', 'Visitors', 'People signed in', 'Page views', 'Flagged addresses', 'Locked out now', 'Visits each day', 'Where they are from', 'What they are doing', 'How they found you', 'On the site now']) check(main.includes(words), `the Overview shows ${words}`);
  check(await page.locator('.cl-site-chart-column').count() >= 7, 'the Overview draws a column for each day');
  const stats = await page.locator('a.cl-ui-stat-link').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
  check(stats.length >= 5 && stats.some((href) => href.includes('/flagged')) && stats.some((href) => href.includes('/lockouts')) && stats.some((href) => href.includes('/log')), 'each figure opens what it counts');
  await go(page, () => page.selectOption('#site-period', 'today'));
  check(page.url().includes('period=today'), 'choosing a period shows it at once');

  await page.goto(`${base}/admin/site-analytics/log`, {waitUntil: 'load'});
  main = await text(page);
  for (const words of ['All requests', 'People signed in', 'Other people', 'Automated', 'Not found', 'Refused', 'Flagged']) check(main.includes(words), `the Site Log has a card for ${words}`);
  check(main.includes('GET /m/u/••••') && !main.includes('0123456789abcdef'), 'a secret in an address is written as ••••');
  await go(page, () => page.locator('#site-log-kinds input[value="missing"]').check({force: true}));
  check(page.url().includes('kind=missing'), 'a card chooses its list');
  check(await page.locator('tr[data-site-request]').count() > 0 && (await text(page)).includes('404 Not found') && !(await text(page)).includes('200 Shown'), 'Not found lists only what was not found');
  await page.goto(`${base}/admin/site-analytics/log?kind=missing&sitelog_q=198.51.100.23`, {waitUntil: 'load'});
  check(await page.inputValue('#sitelog-search-input') === '198.51.100.23', 'the search box holds what is searched for');
  check(await page.locator('#site-log-kinds input[value="missing"]').isChecked(), 'the search keeps the card');
  check(await page.locator('tr[data-site-request]').count() > 0 && !(await page.locator('tr[data-site-request]').allInnerTexts()).some((row) => !row.includes('198.51.100.23')), 'the search narrows the list');
  await page.goto(`${base}/admin/site-analytics/log`, {waitUntil: 'load'});
  await page.fill('#sitelog-search-input', '203.0.113.57');
  await page.waitForFunction(() => { const rows = [...document.querySelectorAll('tr[data-site-request]')]; return rows.length > 0 && rows.every((row) => row.textContent.includes('203.0.113.57')); }, null, {timeout: 8000}).then(() => check(true, ''), () => check(false, 'typing in the search box narrows the list in place'));

  await page.goto(`${base}/admin/site-analytics/flagged`, {waitUntil: 'load'});
  main = await text(page);
  check(main.includes('Asked for 22 pages that do not exist in 10 minutes.'), 'Flagged Activity says why in plain words');
  check(main.includes('Forms refused') && main.includes('seo-offers@example.com'), 'Forms refused lists them with the address typed');

  // One address: its parts and the lock form.
  await page.goto(`${base}/admin/site-analytics/address/203.0.113.14`, {waitUntil: 'load'});
  main = await text(page);
  for (const words of ['What it did', 'History', 'People who used it', 'Its network', 'Lock out', 'This address', 'Its whole range']) check(main.includes(words), `an address's page shows ${words}`);
  // Lock out by hand, from its page.
  await page.fill('#lock-why', 'Looking around with a checker');
  await go(page, () => page.locator('form.cl-lock-form button[type=submit]').click());
  main = await text(page);
  check(main.includes('locked out') && main.includes('Lift this lockout'), 'Lock out works and offers Lift');
  check(sql("SELECT COUNT(*) FROM site_lockouts WHERE label = '203.0.113.14' AND lifted_at IS NULL AND NOT automatic") === '1', 'the lockout is by hand and in force');
  await page.goto(`${base}/admin/site-analytics/lockouts?show=you`, {waitUntil: 'load'});
  check((await text(page)).includes('203.0.113.14'), 'Lockouts lists it under By you');
  await go(page, () => page.locator(`tr:has-text("203.0.113.14") button[type=submit]`).click());
  check(sql("SELECT COUNT(*) FROM site_lockouts WHERE label = '203.0.113.14' AND lifted_at IS NULL") === '0', 'Lift ends it');
  // Add a lockout: a range, a wrong entry refused in words.
  await page.goto(`${base}/admin/site-analytics/lockouts`, {waitUntil: 'load'});
  await page.fill('#add-target', 'not an address');
  await page.fill('#add-why', 'Testing');
  await go(page, () => page.locator('form[action="/admin/site-analytics/lockouts"] button[type=submit]').click());
  check((await page.locator('body').innerText()).includes('Give an address, a range'), 'a wrong entry is refused in words');
  await page.fill('#add-target', '198.51.100.0/25');
  await page.fill('#add-why', 'Fake registrations from many of its machines');
  await go(page, () => page.locator('form[action="/admin/site-analytics/lockouts"] button[type=submit]').click());
  check((await text(page)).includes('198.51.100.0/25'), 'a range can be locked out');
  await go(page, () => page.locator(`tr:has-text("198.51.100.0/25") button[type=submit]`).click());
  // The refused list.
  await page.fill('#refuse-value', 'seo-offers.example');
  await page.fill('#refuse-why', 'Selling search engine work');
  await go(page, () => page.locator('form[action="/admin/site-analytics/refused-emails"] button[type=submit]').click());
  check((await text(page)).includes('seo-offers.example'), 'an email domain can be refused');
  await go(page, () => page.locator('tr:has-text("seo-offers.example") button[type=submit]').click());
  check(await page.locator('tr:has-text("seo-offers.example")').count() === 0, 'and removed again');

  // Not a problem ends the time-out.
  await page.goto(`${base}/admin/site-analytics/flagged`, {waitUntil: 'load'});
  await go(page, () => page.locator('tr:has-text("192.0.2.61") button[type=submit]:visible').first().click());
  check(page.url().includes('show=fine') && (await text(page)).includes('192.0.2.61'), 'Not a problem moves it to its card');
  check(sql("SELECT COUNT(*) FROM site_lockouts WHERE label = '192.0.2.61' AND lifted_at IS NULL") === '0', 'Not a problem ends the time-out');

  // Site protection: a wrong length refused, a change saved, Reset.
  await page.goto(`${base}/admin/site-analytics/protection`, {waitUntil: 'load'});
  main = await text(page);
  for (const words of ['Automatic time-outs', 'If it comes back', 'Never timed out automatically', 'Never lock out', 'Records', "The server's firewall"]) check(main.includes(words), `Site protection shows ${words}`);
  await page.fill('#site-log-keep', '120 days');
  await go(page, () => page.locator('button[form="site-protection-form"]').click());
  check((await page.locator('body').innerText()).includes('Keep the Site Log for'), 'a wrong length is refused in words');
  await page.fill('#site-log-keep', '90d');
  await go(page, () => page.locator('button[form="site-protection-form"]').click());
  check(await page.inputValue('#site-log-keep') === '90d', 'a change is saved');
  await page.goto(`${base}/privacy`, {waitUntil: 'load'});
  check((await page.locator('main').evaluate((m) => m.textContent)).includes('90 days'), 'the Privacy Policy says how long the Site Log is kept, from the setting');
  await page.goto(`${base}/admin/site-analytics/protection`, {waitUntil: 'load'});
  page.once('dialog', (dialog) => dialog.accept());
  await go(page, () => page.locator('form[action="/admin/site-analytics/protection/reset"] button[type=submit]').click());
  check(await page.inputValue('#site-log-keep') === '120d', 'Reset returns to the starting values');
  await context.close();

  // What the server refuses, and the firewall tick (decisions 1B and 2B).
  const firewall = await signedIn(browser);
  const fw = await firewall.newPage();
  await fw.goto(`${base}/admin/site-analytics`, {waitUntil: 'load'});
  main = await text(fw);
  check(main.includes('Refused by the server') && main.includes('47'), 'the Overview shows what the server refused');
  check(await fw.locator('a.cl-ui-stat-link[href$="/lockouts#server"]').count() === 1, 'and the figure opens its list');
  await fw.goto(`${base}/admin/site-analytics/lockouts`, {waitUntil: 'load'});
  main = await text(fw);
  check(main.includes('198.51.100.200') && main.includes('Probing for WordPress') && main.includes('/wp-login.php') && !main.includes('x=1'), 'Lockouts lists the bans and probes, without what follows a question mark');
  check(await fw.locator('#server button').count() === 0, 'nothing the server refuses can be lifted from here');
  check(await fw.locator('input[name=firewall]').count() === 0, 'with the setting off, no firewall tick is offered');
  await fw.goto(`${base}/admin/site-analytics/address/198.51.100.200`, {waitUntil: 'load'});
  check((await text(fw)).includes("Banned at the server's firewall"), 'an address the firewall holds says so');
  await fw.goto(`${base}/admin/site-analytics/protection`, {waitUntil: 'load'});
  await fw.locator('input[name=firewall_link]').check();
  await go(fw, () => fw.locator('button[form="site-protection-form"]').click());
  await fw.goto(`${base}/admin/site-analytics/address/203.0.113.57`, {waitUntil: 'load'});
  check(await fw.locator('input[name=firewall]').count() === 1, 'with the setting on, a lockout offers the tick');
  await fw.locator('input[name=firewall]').check();
  await fw.fill('#lock-why', 'Testing the firewall tick');
  await go(fw, () => fw.locator('form.cl-lock-form button[type=submit]').click());
  let asked = php('tests/Browser/site-analytics-fixture.php', 'server-requests');
  check(/"do":"ban","network":"203\.0\.113\.57"/.test(asked), `the tick asks the firewall for a ban (${asked.slice(0, 120)})`);
  await go(fw, () => fw.locator('form[action*="/lift"] button[type=submit]').first().click());
  asked = php('tests/Browser/site-analytics-fixture.php', 'server-requests');
  check(/"do":"lift","network":"203\.0\.113\.57"/.test(asked), 'Lift asks the firewall to release it');
  await fw.goto(`${base}/admin/site-analytics/protection`, {waitUntil: 'load'});
  fw.once('dialog', (dialog) => dialog.accept());
  await go(fw, () => fw.locator('form[action="/admin/site-analytics/protection/reset"] button[type=submit]').click());
  await firewall.close();

  // Without JavaScript.
  const plain = await signedIn(browser, {javaScriptEnabled: false});
  const plainPage = await plain.newPage();
  await plainPage.goto(`${base}/admin/site-analytics/log`, {waitUntil: 'load'});
  await plainPage.locator('#site-log-kinds input[value="bot"]').check({force: true});
  await go(plainPage, () => plainPage.locator('form.cl-library-lists button[type=submit]').click());
  check(plainPage.url().includes('kind=bot'), 'without JavaScript a card still chooses its list');
  await plainPage.goto(`${base}/admin/site-analytics/address/198.51.100.23`, {waitUntil: 'load'});
  check((await text(plainPage)).includes('Lift this lockout'), 'without JavaScript the address page still works');
  await plain.close();

  // Nobody else sees the pages.
  const visitor = await browser.newContext({ignoreHTTPSErrors: true});
  const visitorPage = await visitor.newPage();
  for (const route of Object.values(pages)) {
    await visitorPage.goto(`${base}${route}`, {waitUntil: 'load'});
    check(visitorPage.url().includes('/login'), `a visitor is sent to sign in from ${route}`);
  }
  // The sign-in form's hidden field is never shown.
  await visitorPage.goto(`${base}/login`, {waitUntil: 'load'});
  check(await visitorPage.locator('input[name=website]').evaluate((input) => { const r = input.getBoundingClientRect(); return r.right <= 0 && input.closest('[aria-hidden="true"]') !== null; }), 'the sign-in form has the hidden field, never shown');
  await visitor.close();

  // The refusal itself. The address this check comes from, as the site sees it, is locked out for a moment.
  const seen = (await (async () => { const c = await signedIn(browser); const p = await c.newPage(); await p.goto(`${base}/admin/site-analytics/protection`, {waitUntil: 'load'}); const words = await text(p); await c.close(); return words; })()).match(/You are using ([0-9a-f.:]+) now/);
  check(seen !== null, 'Site protection names the address in use');
  if (seen) {
    const own = await signedIn(browser);
    const ownPage = await own.newPage();
    await ownPage.goto(`${base}/admin/site-analytics/address/${seen[1]}`, {waitUntil: 'load'});
    check((await text(ownPage)).includes('You are using this address now, so you cannot lock it out.'), 'the address in use cannot be locked out from its page');
    await own.close();
    const id = sql(`INSERT INTO site_lockouts (kind, network, label, which, why) VALUES ('address', '${seen[1]}', '${seen[1]}', 'lockout', 'The browser check of the refusal') RETURNING id`).split('\n')[0];
    try {
      const refused = await browser.newContext({ignoreHTTPSErrors: true});
      const answer = await refused.request.get(`${base}/courses`, {maxRedirects: 0});
      const body = await answer.text();
      check(answer.status() === 403, `a locked-out visitor is answered 403 (${answer.status()})`);
      check(body.includes('This site is not available.') && !/<link|<script|cl-page|locked/i.test(body), 'the refusal is bare and gives no reason');
      check(!answer.headers()['set-cookie'], 'the refusal starts no session');
      const hook = await refused.request.post(`${base}/webhooks/didit`, {data: '{}', headers: {'content-type': 'application/json'}, failOnStatusCode: false});
      check(hook.status() !== 403, `a webhook is never refused (${hook.status()})`);
      await refused.close();
      check(Number(sql(`SELECT refused FROM site_lockouts WHERE id = ${id}`)) >= 1, 'the refusal is counted');
    } finally {
      sql(`UPDATE site_lockouts SET lifted_at = NOW() WHERE id = ${id}`);
    }
    const after = await browser.newContext({ignoreHTTPSErrors: true});
    const back = await after.request.get(`${base}/courses`);
    check(back.status() === 200, 'lifted, the site answers again');
    await after.close();
    sql(`DELETE FROM site_requests WHERE refused AND address = '${seen[1]}'`);
    sql(`DELETE FROM site_lockouts WHERE id = ${id}`);
  }

  await browser.close();
  console.log(JSON.stringify({checks, failures}, null, 2));
  process.exit(failures.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
