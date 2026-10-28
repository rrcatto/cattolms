/* A dashboard's money amounts stay inside their cards whatever their length (owner, 2026-10-21: the amounts ran out of
   their cards; UX rule 2.6f), in every bundled theme at 1440 and 390px, with reduced motion and without, through the
   theme preview (nothing is activated). The amounts the dashboard shows depend on the data of the day, so each small
   amount is given longer ones in turn (a million, a negative million, a hundred million, a trillion) and must fit its
   card again within a moment: fitFigures in platform-overrides.js refits an amount whenever it or its card changes
   size. Gilded Noir's transition on every property once made the refit read an amount's old size and leave it past its
   card (2026-10-26); this check found that on purpose, where the menu pages found it only by chance.
   The text is changed in the browser only: nothing is written.
   Fixture: php tests/Browser/section-pages-fixture.php create|cleanup (in env_php_1), as section-pages.cjs. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const root = path.resolve(__dirname, '../..');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const themes = fs.readdirSync(path.join(root, 'themes')).filter(slug => fs.existsSync(path.join(root, 'themes', slug, 'theme.json')))
  .map(slug => JSON.parse(fs.readFileSync(path.join(root, 'themes', slug, 'theme.json'))).theme);
const only = (process.env.CATTO_THEMES || '').split(',').filter(Boolean);
const widths = (process.env.CATTO_WIDTHS || '1440,390').split(',').map(Number);
const amounts = ['R 1 112 653,62', '-R 1 112 653,62', 'R 123 112 653,62', '-R 1 234 567 112 653,62', 'R 0,00'];

(async () => {
  const browser = await chromium.launch({headless: true});
  const failures = [];
  let checks = 0;
  for (const theme of themes.filter(t => !only.length || only.includes(t.slug))) {
    const key = `${theme.slug}-v${theme.version}`;
    for (const width of widths) {
      for (const motion of ['reduce', 'no-preference']) {
        const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, reducedMotion: motion});
        await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const page = await context.newPage();
        const response = await page.goto(`${base}/admin/dashboard?theme_preview=${key}`, {waitUntil: 'networkidle'});
        const label = `${theme.slug} ${width} ${motion}`;
        if (response.status() !== 200) { failures.push(`${label}: status ${response.status()}`); await context.close(); continue; }
        for (const text of amounts) {
          const spilled = await page.evaluate(async amount => {
            const spans = [...document.querySelectorAll('main .cl-ui-stat-card--small .cl-ui-stat-value > span')].filter(span => span.getClientRects().length);
            spans.forEach(span => { span.textContent = amount; });
            await new Promise(done => setTimeout(done, 700));
            return spans.filter(span => {
              const card = span.closest('.cl-ui-stat-card'), value = span.parentElement;
              return span.getBoundingClientRect().right > value.getBoundingClientRect().right + 1 || value.getBoundingClientRect().right > card.getBoundingClientRect().right + 1;
            }).map(span => (span.closest('.cl-ui-stat-card').querySelector('.cl-ui-stat-label')?.textContent || '').trim());
          }, text);
          checks++;
          if (spilled.length) failures.push(`${label}: "${text}" runs out of its card: ${spilled.join(', ')}`);
        }
        await context.close();
      }
    }
    console.log(`${theme.slug}: done`);
  }
  await browser.close();
  console.log(`${checks} checks, ${failures.length} failures.`);
  failures.slice(0, 60).forEach(failure => console.log('FAIL', failure));
  process.exit(failures.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
