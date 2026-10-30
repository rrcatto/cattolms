/* Lists shown as cards or as a list (owner, 2026-10-26: "offer a grid view and show grids of cards for each person,
   course, company ... So ADMIN can choose between list view and grid view. In catalogue ... also offer a choice"), in
   every bundled theme at 1440 and 390px through the theme preview (nothing is activated). Each theme and width starts
   in a fresh browser, so no choice is remembered from the last:
   - Administration's People, Companies, Course Creators and Courses open as tables, the catalogue as cards;
   - each grid shows whole rows: 5 people or companies, 4 courses to a row at 1440px, never more than 2 at 390px; its
     own page sizes (30, 60, 90 or 24, 48, 96) and its Sort; every picture it asks for arrives;
   - the choice is remembered (the address without it shows the same view), the list comes back when chosen, and the
     catalogue's choice holds on a tag's page too;
   - every view passes the shared page checks (page-construction.cjs);
   - a card's ⋯ menu opens whole, every action in it visible, clickable and on the screen (2026-10-27: the card hid
     what it held, so only a sliver of Administration → Courses' menu showed and it seemed to do nothing);
   - with JavaScript, in the first theme, every switch changes only its own list, the page never loading again (owner,
     2026-10-30: "I just want the view of the list of slides to change without a full page reload"), and the button
     pressed keeps the focus: Administration's lists, the catalogue, the Company workspace's people, Subscribers, a
     company's page (its people and its courses) and a presentation's slides;
   - without JavaScript, in the first theme, the switch's links and the grid's Sort still work.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), as section-pages.cjs. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const root = path.resolve(__dirname, '../..');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-card-grids';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const themes = fs.readdirSync(path.join(root, 'themes')).filter(slug => fs.existsSync(path.join(root, 'themes', slug, 'theme.json')))
  .map(slug => JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme);
const only = (process.env.CATTO_THEMES || '').split(',').filter(Boolean);
const widths = (process.env.CATTO_WIDTHS || '1440,390').split(',').map(Number);
const {inspect, judge} = require('./page-construction.cjs');

// Each list: its address, its dataset, its cards to a row and its grid's page sizes.
const lists = [
  {route: '/admin/people', name: 'people', columns: 5, sizes: ['30', '60', '90']},
  {route: '/admin/companies', name: 'companies', columns: 5, sizes: ['30', '60', '90']},
  {route: '/admin/companies/creators', name: 'company_creators', columns: 5, sizes: ['30', '60', '90']},
  {route: '/admin/courses', name: 'courses', columns: 4, sizes: ['24', '48', '96']},
];

/** What a page shows of a list: cards, rows, cards to the first row, the switch's choice, sizes, Sort, pictures. */
const facts = () => {
  const cards = [...document.querySelectorAll('.cl-ui-card, .cl-course-card')];
  const tops = cards.slice(0, 12).map(c => Math.round(c.getBoundingClientRect().top));
  const grid = document.querySelector('.cl-ui-card-grid, .cl-course-cards');
  const wide = grid ? cards.filter(c => c.getBoundingClientRect().right > grid.getBoundingClientRect().right + 1).length : 0;
  const shown = [...document.querySelectorAll('.cl-view-switch a')].find(a => /shown now/.test(a.getAttribute('aria-label') || ''));
  const select = document.querySelector('.pagination-row select');
  return {
    cards: cards.length,
    rows: document.querySelectorAll('.cl-ui-table tbody tr').length,
    perRow: tops.filter(t => t === tops[0]).length,
    outOfGrid: wide,
    shown: shown ? shown.textContent.trim().toLowerCase() : '',
    sizes: select ? [...select.options].map(o => o.value) : [],
    sort: document.querySelectorAll('.cl-grid-sort select').length,
  };
};

async function pictures(page, label, failures) {
  const broken = await page.evaluate(async () => {
    const grid = document.querySelector('.cl-ui-card-grid');
    if (!grid) return [];
    const images = [...grid.querySelectorAll('img')].slice(0, 10);
    for (const img of images) { img.loading = 'eager'; }
    await Promise.all(images.map(img => img.complete ? null : new Promise(done => { img.onload = img.onerror = done; })));
    return images.filter(img => !img.naturalWidth).map(img => img.getAttribute('src'));
  });
  if (broken.length) failures.push(`${label}: pictures that did not arrive: ${broken.join(', ')}`);
}

/** Opens the ⋯ menu of the first card and of the fourth (the end of a desktop row) and finds any action hidden or off the screen. */
async function cardMenus(page, label, failures) {
  const menus = page.locator('.cl-ui-card .cl-ui-row-menu');
  const count = await menus.count();
  for (const i of [...new Set([0, 3])].filter(i => i < count)) {
    const menu = menus.nth(i);
    await menu.scrollIntoViewIfNeeded();
    await menu.locator('summary').click();
    const r = await menu.evaluate(m => {
      const panel = m.querySelector('.cl-ui-row-panel');
      // The page is scrolled, never the card: scrollIntoView would also scroll a card that hides what it holds and so
      // uncover the very menu it hid.
      const at = panel.getBoundingClientRect();
      window.scrollBy(0, at.top + at.height / 2 - innerHeight / 2);
      const items = [...panel.querySelectorAll('.cl-ui-action')];
      const hidden = items.filter(item => {
        const b = item.getBoundingClientRect();
        const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
        return !hit || !item.contains(hit);
      }).map(item => item.textContent.trim());
      const p = panel.getBoundingClientRect();
      return {open: m.open, items: items.length, hidden, offScreen: p.left < 0 || p.right > innerWidth};
    });
    if (!r.open || !r.items) failures.push(`${label}: card ${i + 1}'s menu did not open`);
    if (r.hidden.length) failures.push(`${label}: card ${i + 1}'s menu hides ${r.hidden.join(', ')}`);
    if (r.offScreen) failures.push(`${label}: card ${i + 1}'s menu runs off the screen`);
    await page.keyboard.press('Escape');
  }
}

(async () => {
  fs.mkdirSync(output, {recursive: true});
  const browser = await chromium.launch({headless: true});
  const failures = [];
  let views = 0;
  const visit = async (page, url, label) => {
    const response = await page.goto(url, {waitUntil: 'networkidle'});
    views++;
    if (response.status() !== 200) { failures.push(`${label}: status ${response.status()}`); return null; }
    const r = await page.evaluate(inspect);
    failures.push(...judge(r, label));
    return page.evaluate(facts);
  };

  // A tag that labels courses, for the catalogue's choice on a tag's page.
  const probe = await browser.newContext({ignoreHTTPSErrors: true});
  const probePage = await probe.newPage();
  await probePage.goto(`${base}/courses/tags`, {waitUntil: 'networkidle'});
  const tag = await probePage.locator('a[href^="/courses/tag/"]').first().getAttribute('href');
  await probe.close();

  for (const theme of themes.filter(t => !only.length || only.includes(t.slug))) {
    const key = `${theme.slug}-v${theme.version}`;
    for (const width of widths) {
      const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, reducedMotion: 'reduce'});
      await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
      const page = await context.newPage();
      page.on('response', response => { if (response.status() >= 400 && /\/(profile-image|logo)\?v=/.test(response.url())) failures.push(`${key} ${width}: ${response.status()} for ${response.url()}`); });
      const most = width >= 1440 ? null : 2;

      for (const list of lists) {
        const label = `${theme.slug} ${width} ${list.route}`;
        let f = await visit(page, `${base}${list.route}?theme_preview=${key}`, `${label} (first visit)`);
        if (f && (f.cards || !f.rows || f.shown !== 'list')) failures.push(`${label}: opens as ${f.cards} cards and ${f.rows} rows, ${f.shown || 'no'} view shown, not as its table`);
        f = await visit(page, `${base}${list.route}?${list.name}_view=grid&theme_preview=${key}`, `${label} grid`);
        if (f) {
          if (!f.cards || f.rows) failures.push(`${label} grid: ${f.cards} cards and ${f.rows} table rows`);
          if (f.shown !== 'grid') failures.push(`${label} grid: the switch shows ${f.shown || 'nothing'} as chosen`);
          const expected = most === null ? Math.min(list.columns, f.cards) : null;
          if (expected !== null && f.perRow !== expected) failures.push(`${label} grid: ${f.perRow} cards to a row, not ${expected}`);
          if (most !== null && f.perRow > most) failures.push(`${label} grid: ${f.perRow} cards to a row on a phone`);
          if (f.outOfGrid) failures.push(`${label} grid: ${f.outOfGrid} cards stand out of the grid`);
          if (f.sizes.length && f.sizes.join(',') !== list.sizes.join(',')) failures.push(`${label} grid: page sizes ${f.sizes.join(', ')}, not ${list.sizes.join(', ')}`);
          if (!f.sort) failures.push(`${label} grid: no Sort`);
          await pictures(page, `${label} grid`, failures);
          await cardMenus(page, `${label} grid`, failures);
          await page.screenshot({path: path.join(output, `${theme.slug}__${list.name}__grid__${width}.jpg`), type: 'jpeg', quality: 60, fullPage: true});
        }
        f = await visit(page, `${base}${list.route}?theme_preview=${key}`, `${label} remembered`);
        if (f && (!f.cards || f.shown !== 'grid')) failures.push(`${label}: the grid was not remembered (${f.cards} cards, ${f.shown || 'no'} view shown)`);
        f = await visit(page, `${base}${list.route}?${list.name}_view=list&theme_preview=${key}`, `${label} list`);
        if (f && (f.cards || !f.rows || f.shown !== 'list')) failures.push(`${label} list: ${f.cards} cards and ${f.rows} rows, ${f.shown || 'no'} view shown`);
      }

      const label = `${theme.slug} ${width} /courses`;
      let f = await visit(page, `${base}/courses?theme_preview=${key}`, `${label} (first visit)`);
      if (f && (!f.cards || f.rows || f.shown !== 'grid')) failures.push(`${label}: opens as ${f.cards} cards and ${f.rows} rows, not as cards`);
      if (f && most === null && f.perRow !== Math.min(4, f.cards)) failures.push(`${label}: ${f.perRow} cards to a row, not 4`);
      if (f && most !== null && f.perRow > most) failures.push(`${label}: ${f.perRow} cards to a row on a phone`);
      f = await visit(page, `${base}/courses?catalogue_view=list&theme_preview=${key}`, `${label} list`);
      if (f && (f.cards || !f.rows || f.shown !== 'list')) failures.push(`${label} list: ${f.cards} cards and ${f.rows} rows`);
      await page.screenshot({path: path.join(output, `${theme.slug}__catalogue__list__${width}.jpg`), type: 'jpeg', quality: 60, fullPage: true});
      if (tag) {
        f = await visit(page, `${base}${tag}?theme_preview=${key}`, `${theme.slug} ${width} ${tag}`);
        if (f && (f.cards || !f.rows)) failures.push(`${theme.slug} ${width} ${tag}: the catalogue's list was not kept (${f.cards} cards, ${f.rows} rows)`);
      }
      f = await visit(page, `${base}/courses?catalogue_view=grid&theme_preview=${key}`, `${label} grid`);
      if (f && (!f.cards || f.rows)) failures.push(`${label} grid: ${f.cards} cards and ${f.rows} rows`);
      await context.close();
      console.log(`${theme.slug} ${width}: done`);
    }
  }

  // In place: every switch changes only its own list, and no page loads again.
  const firstTheme = themes.filter(t => !only.length || only.includes(t.slug))[0];
  if (firstTheme) {
    const key = `${firstTheme.slug}-v${firstTheme.version}`;
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const shown = (area) => page.evaluate((area) => {
      const link = [...document.querySelectorAll(`${area} .cl-view-switch a`)].find(a => /shown now/.test(a.getAttribute('aria-label') || ''));
      return link ? link.textContent.trim().toLowerCase() : '';
    }, area);
    const inPlace = async (route, area, optional = false) => {
      const label = `in place ${route}`;
      await page.goto(`${base}${route}${route.includes('?') ? '&' : '?'}theme_preview=${key}`, {waitUntil: 'networkidle'});
      views++;
      const from = await shown(area);
      if (!from) { if (!optional) failures.push(`${label}: no view switch in ${area}`); return; }
      for (const to of from === 'list' ? ['grid', 'list'] : ['list', 'grid']) {
        await page.evaluate(() => { window.__samePage = true; });
        const link = page.locator(`${area} .cl-view-switch a`, {hasText: to === 'grid' ? 'Grid' : 'List'});
        const id = await link.getAttribute('id');
        await link.click();
        try {
          await page.waitForFunction(([area, to]) => [...document.querySelectorAll(`${area} .cl-view-switch a`)].some(a => /shown now/.test(a.getAttribute('aria-label') || '') && a.textContent.trim().toLowerCase() === to), [area, to], {timeout: 10000});
        } catch { failures.push(`${label}: ${to} was not shown`); return; }
        if (!(await page.evaluate(() => window.__samePage === true))) failures.push(`${label}: showing the ${to} loaded the page again`);
        if (await page.evaluate(() => document.activeElement && document.activeElement.id) !== id) failures.push(`${label}: the focus left the ${to} button`);
      }
    };
    for (const list of lists) await inPlace(list.route, `#${list.name}-view`);
    await inPlace('/courses', '#catalogue-region');
    await inPlace('/company/people', '#people-view');
    await inPlace('/mailing/subscribers', '#subscribers-view');
    const listing = await context.newPage();
    await listing.goto(`${base}/admin/companies?companies_view=list`, {waitUntil: 'networkidle'});
    const company = await listing.locator('#companies-view a[href^="/admin/companies/"]').evaluateAll(links => links.map(a => a.getAttribute('href')).find(href => /^\/admin\/companies\/\d+$/.test(href)));
    await listing.goto(`${base}/admin/course-items?type=slide_deck`, {waitUntil: 'networkidle'});
    const presentation = await listing.locator('a[href^="/admin/course-items/"]').evaluateAll(links => links.map(a => a.getAttribute('href')).find(href => /^\/admin\/course-items\/\d+$/.test(href)));
    await listing.close();
    if (company) { await inPlace(company, '#company_people-view'); await inPlace(company, '#company_courses-view', true); } else failures.push('in place: no company to open');
    if (presentation) await inPlace(presentation, '#slides-view'); else failures.push('in place: no presentation to open');
    if (errors.length) failures.push(`in place: script errors: ${errors.join(' | ')}`);
    await context.close();
  }

  // Without JavaScript: the switch is links and the Sort a form, so both work.
  const first = themes.filter(t => !only.length || only.includes(t.slug))[0];
  if (first) {
    const key = `${first.slug}-v${first.version}`;
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, javaScriptEnabled: false, reducedMotion: 'reduce'});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const page = await context.newPage();
    await page.goto(`${base}/admin/people?theme_preview=${key}`, {waitUntil: 'load'});
    await page.locator('.cl-view-switch a', {hasText: 'Grid'}).click();
    await page.waitForLoadState('load');
    if (!(await page.locator('.cl-ui-card').count())) failures.push('without JavaScript: Grid did not show the cards');
    await page.selectOption('#people-grid-sort', {index: 0});
    await page.selectOption('.cl-grid-sort select[name="people_dir"]', 'desc');
    await page.locator('.cl-grid-sort button[type="submit"]').click();
    await page.waitForLoadState('load');
    const url = new URL(page.url());
    if (url.searchParams.get('people_dir') !== 'desc' || !(await page.locator('.cl-ui-card').count())) failures.push(`without JavaScript: Sort did not sort the grid (${page.url()})`);
    await page.locator('.cl-view-switch a', {hasText: 'List'}).click();
    await page.waitForLoadState('load');
    if (!(await page.locator('.cl-ui-table tbody tr').count())) failures.push('without JavaScript: List did not bring the table back');
    views += 4;
    await context.close();
  }

  await browser.close();
  fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({failures, views}, null, 1));
  console.log(`${views} page views, ${failures.length} failures.`);
  failures.slice(0, 80).forEach(failure => console.log('FAIL', failure));
  process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
