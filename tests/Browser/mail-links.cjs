/* No row id in a link an email carries (owner, 2026-11-06: no row id in any address; the stage 2 brief: "A mail check
   reads Mailpit after the commerce, contact and approval checks"). Every link to the site in every email Mailpit received
   since CATTO_MAIL_SINCE (an ISO time; the run's start when it is not given) is judged as no-row-ids.cjs judges an
   address: a path segment of digits only, a query value of digits only unless its name is a count, or an anchor ending
   in a dash and digits fails, except a sent email's own link number (/m/c/{token}/12), which mailing keeps.

   It sends one contact message itself, through the Contact page as a visitor does, so the notice to the site is judged,
   and it requires the three kinds of link stage 2 moved: an order (/account/orders/CL-…, from the buyer's emails the
   commerce checks send), a person waiting for approval (/admin/people/CL…, from the notice signup-checks.cjs causes) and
   a contact message (/admin/messages/MSG-…). Run it after commerce-wallets.cjs (or financial-documents.cjs) and
   signup-checks.cjs, with CATTO_MAIL_SINCE set to before them. The contact message it sent is removed afterwards
   (no-row-ids-fixture.php forget-contact). results.json goes to CATTO_BROWSER_OUTPUT. Mailpit: CATTO_MAILPIT_URL. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const mailpit = process.env.CATTO_MAILPIT_URL || 'http://127.0.0.1:8025';
const output = process.env.CATTO_BROWSER_OUTPUT || '/tmp/catto-mail-links';
const started = new Date();
const since = new Date(process.env.CATTO_MAIL_SINCE || started.toISOString());
const COUNTS = /^(page|.+_page|.+_page_size|page_size|v|insert_index|reviews_stars|month)$/;
const REQUIRED = [
    {pattern: /^\/account\/orders\/CL-\d{8,}/, what: 'an order, from a buyer\'s email (run commerce-wallets.cjs or financial-documents.cjs first)'},
    {pattern: /^\/admin\/people\/[A-Z]{1,3}\d{12,26}[0-9A-Z](\/|$|#)/, what: 'a person waiting for approval, from the notice to administrators (run signup-checks.cjs first)'},
    {pattern: /^\/admin\/messages\/MSG-\d{6,}/, what: 'a contact message, from the notice to the site'},
];
const php = (args) => execFileSync('podman', ['exec', '-u', 'cattotest', process.env.CATTO_PHP_CONTAINER || 'env_php_1', 'sh', '-lc',
    `cd /home/cattotest/code/current && SHELL_VERBOSITY=-1 php ${args}`], {encoding: 'utf8'});

function rowIds(address) {
    const url = new URL(address);
    const found = [];
    const segments = url.pathname.split('/').filter(Boolean);
    segments.forEach((segment, at) => {
        if (!/^\d+$/.test(segment)) return;
        // A sent email's link number, behind the copy's own random token: mailing keeps its numbers.
        if (segments[0] === 'm' && segments[1] === 'c' && at === 3) return;
        found.push(`path segment ${segment}`);
    });
    for (const [name, value] of url.searchParams) {
        if ((/^\d+$/.test(value) && !COUNTS.test(name)) || (name === 'follow' && /:\d+$/.test(value))) found.push(`query ${name}=${value}`);
    }
    if (/-\d+$/.test(url.hash)) found.push(`anchor ${url.hash}`);
    return found;
}

async function json(address) {
    const response = await fetch(address);
    if (!response.ok) throw new Error(`Mailpit answered ${response.status} for ${address}`);
    return response.json();
}

/** Sends one message through the Contact page, as a visitor, and returns the sender's address. */
async function sendContact(browser) {
    const [person] = JSON.parse(php('tests/Browser/signup-checks-fixture.php names 1'));
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 900}, reducedMotion: 'reduce'});
    const page = await context.newPage();
    await page.goto(`${base}/contact`, {waitUntil: 'load'});
    await page.locator('input[name=reason][value=other]').check({force: true});
    await page.locator('#contact-name, input[name=name]').first().fill(`${person.first} ${person.last}`);
    await page.locator('#contact-email, input[name=email]').first().fill(person.email);
    await page.locator('textarea[name=message]').fill('A question about the courses, sent by the mail links check.');
    // A person takes longer than a script to write a message (FormGuard).
    await page.waitForTimeout(4000);
    await page.locator('form[action="/contact"] button[type=submit]').first().click();
    await page.waitForLoadState('load');
    await context.close();
    return person.email;
}

(async () => {
    fs.mkdirSync(output, {recursive: true});
    const failures = [];
    const browser = await chromium.launch();
    let sender = '';
    try { sender = await sendContact(browser); } catch (error) { failures.push(`the contact message could not be sent: ${error.message}`); }
    await browser.close();
    // The notice to the site and the acknowledgement leave at once; give them a moment to arrive.
    for (let i = 0; i < 30 && sender; i++) {
        const found = await json(`${mailpit}/api/v1/search?query=${encodeURIComponent('to:' + sender)}`);
        if ((found.messages || []).length) break;
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
    await new Promise((resolve) => setTimeout(resolve, 2000));

    const origin = new URL(base).host;
    const messages = [];
    for (let start = 0; ; start += 200) {
        const page = await json(`${mailpit}/api/v1/messages?start=${start}&limit=200`);
        const batch = page.messages || [];
        messages.push(...batch.filter((message) => new Date(message.Created) >= since));
        if (batch.length < 200 || batch.some((message) => new Date(message.Created) < since)) break;
    }
    const seen = new Set();
    let links = 0;
    for (const summary of messages) {
        const message = await json(`${mailpit}/api/v1/message/${summary.ID}`);
        const found = new Set();
        for (const match of String(message.HTML || '').matchAll(/href="([^"]+)"/g)) found.add(match[1].replace(/&amp;/g, '&'));
        for (const match of String(message.Text || '').matchAll(/https?:\/\/[^\s<>"')\]]+/g)) found.add(match[0]);
        for (const address of found) {
            let url;
            try { url = new URL(address); } catch { continue; }
            if (url.host !== origin) continue;
            links++;
            for (const entry of REQUIRED) if (entry.pattern.test(url.pathname)) seen.add(entry.what);
            const ids = rowIds(url.href);
            if (ids.length) failures.push(`"${summary.Subject}" to ${(summary.To || []).map((to) => to.Address).join(', ')}: ${url.pathname}${url.search}${url.hash} (${ids.join(', ')})`);
        }
    }
    for (const entry of REQUIRED) if (!seen.has(entry.what)) failures.push(`no email since ${since.toISOString()} links ${entry.what}`);
    if (sender) {
        try { php(`tests/Browser/no-row-ids-fixture.php forget-contact ${sender}`); } catch (error) { failures.push(`the contact message could not be removed: ${error.message}`); }
    }
    for (const failure of failures) console.log(`FAIL ${failure}`);
    const summary = {since: since.toISOString(), emails: messages.length, links_to_the_site: links, kinds_seen: [...seen], failures: failures.length};
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({summary, failures}, null, 2));
    console.log(JSON.stringify(summary, null, 2));
    process.exit(failures.length ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
