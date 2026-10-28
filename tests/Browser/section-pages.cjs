/* Every Account, Company, Administration and Mailing menu page in every bundled theme at 1440 and 390px,
   through the theme preview (nothing is activated). Checks the one construction every page shares:
   - exactly one h1;
   - only page rows directly in main (flash stack, page head, identity band, page sections, a theme's
     page-family wrapper, form windows);
   - no words standing on the page texture;
   - the first card's edge on the page head's text edge (on the "All sections" pages the first card is
     the first section's accordion, which holds that section's own cards);
   - the same gap above the footer on every page of a theme;
   - no sideways scrolling;
   - nothing standing out past a table's edge, and no action cut off by its cell (an action column is
     never narrower than its control, at either width).
   Screenshots go beside results.json.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1). */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const root = path.resolve(__dirname, '../..');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-section-pages';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const themes = fs.readdirSync(path.join(root, 'themes')).filter(slug => fs.existsSync(path.join(root, 'themes', slug, 'theme.json')))
  .map(slug => JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme);
const only = (process.env.CATTO_THEMES || '').split(',').filter(Boolean);
const widths = (process.env.CATTO_WIDTHS || '1440,390').split(',').map(Number);

// The 70 menu pages: Account, Company, Administration and Mailing (its own menu since 2026-10-28), as their menus list
// them, and the fixture company's page.
const pages = [
  '/account', '/account/dashboard', '/account/profile', '/account/billing', '/account/emails', '/account/social',
  '/account/courses', '/account/certificates', '/account/orders', '/account/wallet', '/account/sessions', '/account/activity',
  '/company', '/company/dashboard', '/company/profile', '/company/people', '/company/requests', '/company/enrolments', '/company/performance',
  '/company/courses', '/company/favourites', '/company/training', '/company/credits', '/company/orders', '/company/wallet', '/company/billing',
  '/admin', '/admin/dashboard', '/admin/courses', '/admin/course-items', '/admin/resources', '/admin/courses/categories',
  '/admin/courses/tags', '/admin/course-reviews', '/admin/bundles', '/admin/certificates/designs', '/admin/course/requests',
  '/admin/course/enrolments', '/admin/people', '/admin/people/signup-attempts', '/admin/companies', '/admin/companies/creators', '/admin/course/credits',
  '/admin/commerce/orders', '/admin/commerce/refund-requests', '/admin/wallets', '/admin/promotions', '/admin/commerce/documents',
  '/admin/commerce/eft', '/admin/commerce/unmatched', '/admin/commerce/ledger', '/admin/commerce/payment-events', '/admin/commerce/trail', '/admin/activity', '/admin/reports',
  '/admin/reports/companies', '/admin/themes', '/admin/roles', '/admin/seed', '/admin/settings', '/admin/system/ui-components',
  '/mailing', '/mailing/digest', '/mailing/messages', '/mailing/lists', '/mailing/subscribers', '/mailing/never-emailed', '/mailing/templates', '/mailing/automatic', '/mailing/settings', '/mailing/reports',
];
const {inspect, judge} = require('./page-construction.cjs');
// The company's own page in Administration (owner, 2026-10-26), opened from its name: the fixture's company.
if (state.company) pages.push(`/admin/companies/${state.company}`);

(async () => {
  fs.mkdirSync(output, {recursive: true});
  const browser = await chromium.launch({headless: true});
  const results = [], failures = [];
  for (const theme of themes.filter(t => !only.length || only.includes(t.slug))) {
    const key = `${theme.slug}-v${theme.version}`;
    for (const width of widths) {
      const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, reducedMotion: 'reduce'});
      await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
      const page = await context.newPage();
      const gaps = new Map();
      for (const route of pages) {
        const label = `${theme.slug} ${width} ${route}`;
        const response = await page.goto(`${base}${route}?theme_preview=${key}`, {waitUntil: 'networkidle'});
        const r = await page.evaluate(inspect);
        results.push({theme: theme.slug, width, route, status: response.status(), ...r});
        if (response.status() !== 200) { failures.push(`${label}: status ${response.status()}`); continue; }
        if (!r.theme.includes(`/${key}/`)) failures.push(`${label}: the previewed theme was not served`);
        failures.push(...judge(r, label));
        if (r.gapAboveFooter !== null) gaps.set(route, r.gapAboveFooter);
        const file = `${theme.slug}__${route.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '')}__${width}.jpg`;
        await page.screenshot({path: path.join(output, file), type: 'jpeg', quality: 60, fullPage: true});
      }
      const values = [...gaps.values()];
      const common = values.sort((a, b) => values.filter(v => v === a).length - values.filter(v => v === b).length).pop();
      for (const [route, gap] of gaps) if (Math.abs(gap - common) > 2) failures.push(`${theme.slug} ${width} ${route}: ${gap}px above the footer, ${common}px on the other pages`);
      await context.close();
      console.log(`${theme.slug} ${width}: done`);
    }
  }
  await browser.close();
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({failures, results}, null, 1));
  console.log(`${results.length} page views, ${failures.length} failures.`);
  failures.slice(0, 80).forEach(f => console.log('FAIL', f));
  process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
