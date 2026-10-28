/* The sign-up check from the outside (owner, 2026-10-26; REPORTS/signup-risk-2026-10-26.md), on the demo site in the
   owner's theme at 1440 and 390px:
   - the Register page says the account will be held (the owner's words), carries the signed opening time and the hidden
     field (never shown), and fills in the browser's time zone; without JavaScript it still registers;
   - after sending, the "check your email" page says it again; the email is "Finish your registration" with the words;
     its link leads to the waiting page, which says it once more and promises nothing;
   - the attempt is listed at Sign-up Attempts with its risk and outcome, and opens with every signal in words;
   - the waiting account is on Needs your attention with its risk badge; its page shows the Sign-up check and Recent
     sign-ins (the Sign-up risk card, in the side column since 2026-10-26); People shows its risk and sorts by it; Settings shows the Sign-up checks section.
   It registers as a person does, through the pages, as people with real names nobody on the site has (signup-checks-fixture.php
   names, at a domain that receives no mail). Fixtures: section-pages-fixture.php create (an ADMIN to look with), then signup-checks-fixture.php cleanup and
   section-pages-fixture.php cleanup afterwards (in env_php_1). Mailpit: CATTO_MAILPIT_URL (http://127.0.0.1:8025). */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://127.0.0.1:8025';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-section-pages-state.json'));
const notice = 'After completing your registration, your account will be held pending approval because the site is currently in DEMO mode. Once the site has gone LIVE, your account status will be processed.';
const failures = [];
let checks = 0;
const check = (ok, what) => { checks++; if (!ok) failures.push(what); };
const text = async (page) => (await page.locator('main').innerText()).replace(/\s+/g, ' ');

async function mail(address) {
    for (let i = 0; i < 30; i++) {
        const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent('to:' + address)}`)).json();
        if ((found.messages || []).length) return await (await fetch(`${mailpit}/api/v1/message/${found.messages[0].ID}`)).json();
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    throw new Error('no email reached ' + address);
}

async function register(browser, width, javaScript, person) {
    const email = person.email;
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 900}, javaScriptEnabled: javaScript, reducedMotion: 'reduce'});
    const page = await context.newPage();
    const label = `${width}px ${javaScript ? 'with' : 'without'} JavaScript`;
    await page.goto(`${base}/register`, {waitUntil: 'load'});
    check((await text(page)).includes(notice), `${label}: the Register page says the account will be held`);
    check(/^\d{9,11}\.[0-9a-f]{16}$/.test(await page.locator('input[name=signup_opened]').inputValue()), `${label}: the form carries its signed opening time`);
    // Off the page and out of the accessibility tree, as the contact form's is: a person never sees or reaches it.
    check(await page.locator('input[name=website]').evaluate((input) => { const r = input.getBoundingClientRect(); return r.right <= 0 && input.closest('[aria-hidden="true"]') !== null && input.tabIndex === -1; }),
        `${label}: the hidden field is never shown`);
    const zone = await page.locator('input[name=signup_time_zone]').inputValue();
    check(javaScript ? zone !== '' : zone === '', `${label}: the time zone is ${javaScript ? 'filled in' : 'left empty'} (${zone || 'empty'})`);
    await page.locator('#first-name').fill(person.first);
    await page.locator('#last-name').fill(person.last);
    await page.locator('#registration-email').fill(email);
    // The country they live in starts as the one the IP address shows (South Africa when it shows none), and their ID number.
    check(await page.locator('#registration-country').inputValue() === 'ZA', `${label}: the country starts as South Africa`);
    await page.locator('#registration-id-number').fill(person.id_number);
    await page.waitForTimeout(3200);
    await page.locator('form[action="/register"] button[type=submit]').click();
    await page.waitForLoadState('load');
    check(page.url().endsWith('/login/sent?registered=1') && (await text(page)).includes(notice), `${label}: the "check your email" page says it again`);
    const message = await mail(email);
    check(/Finish your registration/.test(message.Subject) && message.Text.includes(notice), `${label}: the email is "Finish your registration" with the words (${message.Subject})`);
    const link = (message.Text.match(/https?:\/\/[^\s]+\/auth\/consume\?token=[^\s]+/) || [])[0];
    await page.goto(link.replace(/^https?:\/\/[^/]+/, base), {waitUntil: 'load'});
    const waiting = await text(page);
    check(page.url().endsWith('/register/awaiting-approval') && waiting.includes(notice) && !/as soon as (yours|it) is approved/.test(waiting), `${label}: the waiting page says it and promises nothing`);
    await context.close();
    return email;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    const widths = (process.env.CATTO_WIDTHS || '1440,390').split(',').map(Number);
    const people = JSON.parse(execFileSync('podman', ['exec', '-u', 'cattotest', process.env.CATTO_PHP_CONTAINER || 'env_php_1', 'sh', '-lc',
        `cd /home/cattotest/code/current && SHELL_VERBOSITY=-1 php tests/Browser/signup-checks-fixture.php names ${widths.length + 1}`], {encoding: 'utf8'}));
    const emails = [];
    for (const [i, width] of widths.entries()) {
        emails.push(await register(browser, width, true, people[i]));
    }
    emails.push(await register(browser, 1440, false, people[widths.length]));

    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const page = await context.newPage();
    const errors = [];
    page.on('response', (response) => { if (response.status() >= 500) errors.push(response.status() + ' ' + response.url()); });
    await page.goto(`${base}/admin/people/signup-attempts`, {waitUntil: 'networkidle'});
    for (const email of emails) {
        const row = page.locator('tr', {hasText: email});
        check(await row.count() === 1, `${email}: listed at Sign-up Attempts`);
        if (await row.count()) check(/(LOW|MEDIUM|HIGH) RISK \d+/i.test(await row.innerText()) && /Registered, held/i.test(await row.innerText()), `${email}: its risk and "Registered, held" (${(await row.innerText()).replace(/\s+/g, ' ')})`);
    }
    await page.locator('tr', {hasText: emails[0]}).locator('a', {hasText: 'Open'}).click();
    await page.waitForLoadState('networkidle');
    const attempt = await text(page);
    check(/\d+ points/.test(attempt) && /cannot receive email: it has no mail server/.test(attempt) && /IP address/.test(attempt), 'the attempt opens with its signals in words and where it came from');
    await page.goto(`${base}/admin/dashboard`, {waitUntil: 'networkidle'});
    const attention = page.locator('.cl-ui-list-item', {hasText: people[0].name}).first();
    check(await attention.count() === 1 && /RISK \d+/i.test(await attention.innerText()), 'the waiting account is on Needs your attention with its risk badge');
    await attention.locator('a', {hasText: 'Approve or disable'}).click();
    await page.waitForLoadState('networkidle');
    check(await page.locator('#signup-check').count() === 1 && /Sign-up risk/.test(await page.locator('#signup-check').innerText()), 'the person\'s page shows the Sign-up risk');
    check(await page.locator('#recent-sign-ins').count() === 1, 'and Recent sign-ins');
    await page.goto(`${base}/admin/people?people_view=list&people_sort=risk&people_dir=desc`, {waitUntil: 'networkidle'});
    check(/RISK \d+/i.test(await page.locator('table tbody tr').first().innerText()), 'People sorts by risk, the riskiest first');
    await page.goto(`${base}/admin/settings?section=signup-checks`, {waitUntil: 'networkidle'});
    check(await page.locator('#settings-signup-checks').count() === 1 && /What the check reads/.test(await page.locator('#settings-signup-checks').innerText()), 'Settings shows the Sign-up checks section');
    check(errors.length === 0, 'no server error: ' + errors.join(', '));
    await browser.close();
    console.log(`${checks} checks, ${failures.length} failures.`);
    failures.forEach((failure) => console.log('FAIL', failure));
    process.exit(failures.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
