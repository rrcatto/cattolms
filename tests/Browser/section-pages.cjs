/* Every Account, Company and Administration menu page in every bundled theme at 1440 and 390px,
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

// The 55 menu pages: Account, Company and Administration, as their menus list them.
const pages = [
  '/account', '/account/dashboard', '/account/profile', '/account/billing', '/account/emails', '/account/social',
  '/account/courses', '/account/orders', '/account/wallet', '/account/sessions', '/account/activity',
  '/company', '/company/dashboard', '/company/people', '/company/requests', '/company/enrolments', '/company/performance',
  '/company/courses', '/company/favourites', '/company/training', '/company/credits', '/company/wallet', '/company/billing',
  '/admin', '/admin/dashboard', '/admin/courses', '/admin/course-items', '/admin/resources', '/admin/courses/categories',
  '/admin/courses/tags', '/admin/course-reviews', '/admin/bundles', '/admin/certificates/designs', '/admin/course/requests',
  '/admin/course/enrolments', '/admin/people', '/admin/companies', '/admin/companies/creators', '/admin/course/credits',
  '/admin/commerce/orders', '/admin/commerce/refund-requests', '/admin/wallets', '/admin/promotions', '/admin/commerce/documents',
  '/admin/commerce/eft', '/admin/commerce/unmatched', '/admin/commerce/ledger', '/admin/commerce/payment-events', '/admin/commerce/trail', '/admin/activity', '/admin/reports',
  '/admin/reports/companies', '/admin/themes', '/admin/roles', '/admin/seed', '/admin/settings', '/admin/system/ui-components',
];
const inspect = () => {
  const main = document.querySelector('main.cl-main');
  const allowed = ['cl-flash-stack', 'cl-page-head', 'cl-identity-head', 'cl-page-section', 'cl-page-family', 'cl-ui-modal'];
  const strays = [...main.children].filter(c => !['SCRIPT', 'LINK', 'STYLE', 'TEMPLATE'].includes(c.tagName))
    .filter(c => !allowed.some(a => c.classList.contains(a))).map(c => c.tagName.toLowerCase() + '.' + [...c.classList].join('.'));
  // Words whose nearest painted ancestor is the body: they stand on the texture.
  const bare = [];
  const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode;
    if (!node.textContent.trim()) continue;
    const el = node.parentElement;
    if (!el || el.closest('[hidden], script, style, template, svg, dialog:not([open]), .cl-ui-modal:not(.open)')) continue;
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none' || !el.getClientRects().length) continue;
    let a = el, painted = false;
    while (a && a !== document.body) {
      const s = getComputedStyle(a);
      if ((s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent') || s.backgroundImage !== 'none') { painted = true; break; }
      a = a.parentElement;
    }
    if (!painted) bare.push(node.textContent.trim().slice(0, 40));
  }
  const h1 = main.querySelector('.cl-page-head h1');
  // The first box in document order: a card, or the accordion that holds a section on an "All sections" page.
  const card = main.querySelector([':scope > .cl-page-section', ':scope > .cl-page-family .cl-page-section']
    .flatMap(row => [`${row} .cl-ui-surface`, `${row} .cl-ui-accordion-section`]).join(', '));
  const rows = [...main.children].filter(c => c.getClientRects().length && !['SCRIPT', 'LINK', 'STYLE', 'TEMPLATE'].includes(c.tagName));
  const last = rows[rows.length - 1];
  const footer = document.querySelector('.cl-footer');
  // Anything standing out past its table's right edge (a menu's open panel aside), unless a box
  // inside the table scrolls or clips it; and every action control cut off by its own cell.
  const clipped = (el, stop) => { for (let a = el.parentElement; a && a !== stop; a = a.parentElement) if (getComputedStyle(a).overflowX !== 'visible') return true; return false; };
  const tableSpill = [], cutActions = [];
  for (const table of main.querySelectorAll('.cl-ui-table')) {
    const edge = table.getBoundingClientRect().right;
    if (!table.getClientRects().length) continue;
    for (const el of table.querySelectorAll('*')) {
      if (el.closest('.cl-ui-row-panel')) continue;
      const r = el.getBoundingClientRect();
      if (r.width && r.height && r.right > edge + 1 && !clipped(el, table)) { tableSpill.push(`${el.tagName.toLowerCase()} "${el.textContent.trim().slice(0, 20)}" by ${Math.round(r.right - edge)}px`); break; }
    }
    for (const control of table.querySelectorAll('td .cl-ui-action, td .cl-ui-row-trigger')) {
      if (control.closest('.cl-ui-row-panel') || !control.getClientRects().length) continue;
      const td = control.closest('td'), cs = getComputedStyle(td);
      const right = td.getBoundingClientRect().right - parseFloat(cs.paddingRight) - parseFloat(cs.borderRightWidth);
      const name = `"${control.textContent.trim().replace(/\s+/g, ' ').slice(0, 20)}"`;
      if (control.getBoundingClientRect().right > right + 1) { cutActions.push(`${name} by ${Math.round(control.getBoundingClientRect().right - right)}px`); break; }
      if (control.scrollWidth > control.clientWidth + 1) { cutActions.push(`${name} squeezed to ${control.clientWidth}px`); break; }
    }
  }
  return {
    tableSpill, cutActions,
    h1: main.querySelectorAll('h1').length,
    strays, bare: bare.slice(0, 6), bareCount: bare.length,
    headLeft: h1 ? Math.round(h1.getBoundingClientRect().left) : null,
    cardLeft: card ? Math.round(card.getBoundingClientRect().left) : null,
    gapAboveFooter: footer && main ? Math.round(footer.getBoundingClientRect().top - main.getBoundingClientRect().bottom) : null,
    lastRowBottom: last ? Math.round(main.getBoundingClientRect().bottom - last.getBoundingClientRect().bottom) : null,
    overflowX: document.documentElement.scrollWidth > innerWidth + 1,
    theme: [...document.querySelectorAll('link[href*="/themes/"]')].map(l => l.getAttribute('href')).join(' '),
  };
};

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
        if (r.h1 !== 1) failures.push(`${label}: ${r.h1} h1 elements`);
        if (r.strays.length) failures.push(`${label}: not a page row in main: ${r.strays.join(', ')}`);
        if (r.bareCount) failures.push(`${label}: words on the texture: ${JSON.stringify(r.bare)}`);
        if (r.headLeft !== null && r.cardLeft !== null && Math.abs(r.headLeft - r.cardLeft) > 3) failures.push(`${label}: first card at ${r.cardLeft}px, page head text at ${r.headLeft}px`);
        if (r.overflowX) failures.push(`${label}: scrolls sideways`);
        if (r.tableSpill.length) failures.push(`${label}: stands out of its table: ${r.tableSpill.join(', ')}`);
        if (r.cutActions.length) failures.push(`${label}: an action wider than its cell: ${r.cutActions.join(', ')}`);
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
