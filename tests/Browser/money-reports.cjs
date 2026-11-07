/* Money Reports (owner, 2026-11-06, the approved mockup), as ADMIN sees it, in the active theme at 1440 and 390px:
   - the page is built as every page is (page-construction.cjs);
   - choosing a month changes the page in place, without loading it again, and the address follows; Back returns to the
     month before (UX rule 8.6a);
   - without JavaScript Show is there and does the same;
   - every figure opens its list for the month's dates, and the Money Ledger's Money in for the month is Money in;
   - Download PDF gives a PDF and Download Excel an Excel file, each named money-report-YYYY-MM;
   - the two ticks save as they change, and are put back as they were.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), the same ADMIN session; its state
   file is CATTO_BROWSER_STATE. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-money-reports';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const {inspect, judge} = require('./page-construction.cjs');
const ADDRESS = '/admin/commerce/reports';

(async () => {
  fs.mkdirSync(output, {recursive: true});
  const browser = await chromium.launch({headless: true});
  const failures = [];
  let checks = 0;
  const check = (ok, words) => { checks++; if (!ok) failures.push(words); };
  const context = async (width, javaScriptEnabled = true) => {
    const made = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, javaScriptEnabled, reducedMotion: 'reduce', acceptDownloads: true});
    await made.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    return made;
  };

  // The page as every page is built, at both widths.
  for (const width of [1440, 390]) {
    const ctx = await context(width);
    const page = await ctx.newPage();
    const response = await page.goto(base + ADDRESS, {waitUntil: 'networkidle'});
    check(response.status() === 200, `${width}: status ${response.status()}`);
    const found = await page.evaluate(inspect);
    for (const fault of judge(found, `${width} ${ADDRESS}`)) failures.push(fault);
    checks++;
    await page.screenshot({path: path.join(output, `money-reports-${width}.jpg`), type: 'jpeg', quality: 60, fullPage: true});
    await ctx.close();
  }

  const ctx = await context(1440);
  const page = await ctx.newPage();
  await page.goto(base + ADDRESS, {waitUntil: 'networkidle'});
  const months = await page.$$eval('#money-report-month-choice option', options => options.map(o => ({value: o.value, selected: o.selected})));
  check(months.length >= 2, 'the Month offers this month and last month at least');
  const shown = months.find(m => m.selected).value;
  const other = months.find(m => !m.selected).value;

  // In place: the page is not loaded again, the address follows, and the figures and downloads are the new month's.
  await page.evaluate(() => { window.moneyReportsMarker = 'still here'; });
  await page.selectOption('#money-report-month-choice', other);
  await page.waitForURL(url => url.search.includes(`month=${other}`), {timeout: 10000}).catch(() => {});
  await page.waitForLoadState('networkidle');
  check(new URL(page.url()).searchParams.get('month') === other, `choosing ${other} puts it in the address`);
  check(await page.evaluate(() => window.moneyReportsMarker) === 'still here', 'choosing a month changes the page in place, without loading it again');
  check(await page.getAttribute('a[href$=".pdf"]', 'href') === `${ADDRESS}/${other}.pdf`, 'Download PDF follows the month');
  const firstHref = await page.getAttribute('#money-report-month .cl-ui-stat-link', 'href');
  check(firstHref.includes(`from=${other}-01`), `the figures open ${other}'s lists: ${firstHref}`);
  await page.goBack();
  // htmx puts the page back after the address changes, so the check waits for the month to return.
  await page.waitForFunction(month => document.querySelector('#money-report-month-choice')?.value === month, shown, {timeout: 8000}).catch(() => {});
  check(!new URL(page.url()).searchParams.has('month') && await page.$eval('#money-report-month-choice', select => select.value) === shown, 'Back returns to the month before');

  // Every figure opens its list for the month, and Money in is the Money Ledger's Money in for it.
  await page.goto(`${base}${ADDRESS}?month=${shown}`, {waitUntil: 'networkidle'});
  const figures = await page.$$eval('#money-report-month .cl-ui-stat-link', links => links.map(a => ({href: a.getAttribute('href'), value: a.querySelector('.cl-ui-stat-value').textContent.trim(), label: a.querySelector('.cl-ui-stat-label').textContent.trim()})));
  check(figures.length === 6, `six figures (${figures.length})`);
  for (const figure of figures) check(/[?&](people_)?from=\d{4}-\d{2}-01&(people_)?to=\d{4}-\d{2}-\d{2}/.test(figure.href), `${figure.label} opens its list with the month's dates: ${figure.href}`);
  const moneyIn = figures.find(f => f.label === 'Money in');
  await page.goto(base + moneyIn.href, {waitUntil: 'networkidle'});
  const ledgerIn = await page.$$eval('.cl-ui-stat-card', cards => (cards.find(c => c.querySelector('.cl-ui-stat-label')?.textContent.trim() === 'Money in')?.querySelector('.cl-ui-stat-value')?.textContent || '').trim());
  check(ledgerIn === moneyIn.value, `Money in (${moneyIn.value}) is the Money Ledger's Money in for the month (${ledgerIn})`);

  // The downloads.
  await page.goto(`${base}${ADDRESS}?month=${shown}`, {waitUntil: 'networkidle'});
  for (const [link, name, start] of [['Download PDF', `money-report-${shown}.pdf`, '%PDF-'], ['Download Excel', `money-report-${shown}.xlsx`, 'PK']]) {
    const [download] = await Promise.all([page.waitForEvent('download'), page.click(`a:has-text("${link}")`)]);
    const file = path.join(output, download.suggestedFilename());
    await download.saveAs(file);
    check(download.suggestedFilename() === name, `${link} is ${name} (${download.suggestedFilename()})`);
    check(fs.readFileSync(file).subarray(0, start.length).toString() === start, `${link} gives a real ${name.split('.').pop()} file`);
  }

  // The ticks save as they change: untick and tick back, the page loaded afresh each time saying so.
  const ticked = async () => page.$$eval('[data-money-report-ticks] input[type="checkbox"]', boxes => Object.fromEntries(boxes.map(b => [b.name, b.checked])));
  const before = await ticked();
  check(before.email_report !== undefined && before.telegram_summary !== undefined, 'both ticks are there');
  const saved = page.waitForResponse(r => r.url().endsWith('/admin/commerce/reports/settings') && r.request().method() === 'POST');
  await page.click('label:has(input[name="telegram_summary"])');
  check((await saved).status() === 204, 'a tick saves as it changes');
  await page.reload({waitUntil: 'networkidle'});
  check((await ticked()).telegram_summary === !before.telegram_summary, 'the change is kept');
  const back = page.waitForResponse(r => r.url().endsWith('/admin/commerce/reports/settings'));
  await page.click('label:has(input[name="telegram_summary"])');
  await back;
  await page.reload({waitUntil: 'networkidle'});
  check(JSON.stringify(await ticked()) === JSON.stringify(before), 'the ticks are back as they were');
  await ctx.close();

  // Without JavaScript: Show is there and opens the chosen month.
  const plain = await context(1440, false);
  const noScript = await plain.newPage();
  await noScript.goto(base + ADDRESS, {waitUntil: 'networkidle'});
  check(await noScript.isVisible('form.cl-money-period button:has-text("Show")'), 'without JavaScript Show is there');
  await noScript.selectOption('#money-report-month-choice', other);
  await Promise.all([noScript.waitForNavigation({waitUntil: 'networkidle'}), noScript.click('form.cl-money-period button:has-text("Show")')]);
  check(new URL(noScript.url()).searchParams.get('month') === other, 'Show opens the chosen month without JavaScript');
  check(await noScript.$eval('#money-report-month-choice', select => select.value) === other, 'and the Month says so');
  const found = await noScript.evaluate(inspect);
  for (const fault of judge(found, `no JavaScript ${ADDRESS}`)) failures.push(fault);
  await plain.close();

  await browser.close();
  console.log(`${checks} checks, ${failures.length} failures.`);
  failures.forEach(f => console.log('FAIL', f));
  process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
