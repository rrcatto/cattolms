/* Signing in with Telegram and two-step sign-in (owner, 2026-11-05, the approved mockup), in Chromium and Firefox, against a
   stand-in of Telegram, so no message goes to the real service:
   - the Sign in page offers Email address and Telegram account as feature cards in the site's own theme, at 1440 and 390
     pixels, showing only the chosen field, and without JavaScript shows both and reads the chosen one;
   - Account > Sign-in and security connects Telegram: the page notices by itself when Start is pressed (and has I have pressed
     Start without JavaScript), sends a test, and disconnects;
   - two-step is set up from the QR code read off the screen's pixels (jsQR), with a code worked out here (RFC 6238), and its
     eight recovery codes shown once;
   - an unknown username and a real one get the same page; only the real one gets a message, with no link preview; a code
     typed in another browser does not work, a wrong one says only "That code did not work. Check it, or send a new one.",
     Send a new code replaces the old; the right code goes to two-step's step, where a recovery code signs in; the link works
     once in another browser, and a used link says what a used email link says;
   - an administrator whom the policy names sets two-step up before getting in; Reset two-step on a person's page;
   - Settings: the four ticks, Login via Telegram, New Account, New Order and Payment made, on one row, saved in place;
     Two-step sign-in saves;
   - the Telegram log names who each message went to and holds no code or link; nothing scrolls sideways;
   - every message the site tried went to the stand-in, and the log has as many messages after as before.
   Fixture: php tests/Browser/telegram-sign-in-fixture.php create|cleanup (in env_php_1), run by this script itself.
   CATTO_SHOTS=<folder> keeps pictures. Judge it by its exit code. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const jsQR = require(path.join(path.dirname(process.env.CATTO_PLAYWRIGHT_MODULE || (process.env.HOME + '/tools/playwright/node_modules/playwright')), 'jsqr'));
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const shots = process.env.CATTO_SHOTS || '';
const root = path.resolve(__dirname, '..', '..');
const results = [];
const check = (name, ok, detail = '') => { results.push(Boolean(ok)); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + String(detail).slice(0, 300) + ')'}`); };
const fixture = (...args) => execFileSync('podman', ['exec', '-u', 'cattotest', 'env_php_1', 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/telegram-sign-in-fixture.php ${args.map((a) => `'${String(a).replace(/'/g, `'\\''`)}'`).join(' ')} 2>/dev/null`], {encoding: 'utf8'}).trim();
const sent = () => JSON.parse(fixture('sent') || '[]');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const sideways = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
// A page's words, less a site notification's countdown, which ticks between two loads (the owner's own notification may be on).
const mainWords = (page) => page.locator('main').evaluate((main) => [...main.querySelectorAll('[data-site-countdown]')].reduce((words, timer) => words.replace(timer.innerText, ''), main.innerText));
const shot = async (page, name, full = true) => { if (shots) await page.screenshot({path: `${shots}/${name}.png`, fullPage: full}); };
// The site's own theme (Saharan Sunset, the one bundled theme since 2026-11-07): a visitor cannot preview one, and no
// check switches it.
const themes = [require('./site-theme.cjs').siteTheme()];
const WRONG = 'That code did not work. Check it, or send a new one.';

// A one-time code worked out here, not by the code under test (RFC 6238).
const decode = (text) => { let bits = ''; for (const c of text.replace(/[\s=]/g, '').toUpperCase()) bits += 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5, '0'); return Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2))); };
const codeAt = (secret, seconds) => {
    const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(seconds / 30)));
    const hash = crypto.createHmac('sha1', decode(secret)).update(counter).digest();
    return String((hash.readUInt32BE(hash[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
};
// A code of a step after the last one used: wait for the next 30 seconds when the last was this step's.
const freshCode = async (secret, used) => { let now = Date.now() / 1000; if (Math.floor(now / 30) <= used) { await sleep(((used + 1) * 30 - now) * 1000 + 300); now = Date.now() / 1000; } return {code: codeAt(secret, now), step: Math.floor(now / 30)}; };
// What a phone would read: the page's pixels, decoded by a QR reader that knows nothing of the code under test.
const readQr = async (page) => {
    const picture = (await page.locator('.cl-two-step-qr').first().screenshot()).toString('base64');
    const blank = await page.context().newPage();
    const pixels = await blank.evaluate(async (png) => {
        const image = await createImageBitmap(await (await fetch('data:image/png;base64,' + png)).blob());
        const canvas = Object.assign(document.createElement('canvas'), {width: image.width, height: image.height});
        const drawing = canvas.getContext('2d'); drawing.drawImage(image, 0, 0);
        return {width: image.width, height: image.height, data: [...drawing.getImageData(0, 0, image.width, image.height).data]};
    }, picture);
    await blank.close();
    return jsQR(Uint8ClampedArray.from(pixels.data), pixels.width, pixels.height)?.data || '';
};
const newestTo = async (chat, after) => {
    for (let i = 0; i < 40; i++) { const all = sent().filter((m) => m.chat_id === String(chat)); if (all.length > after) return all[all.length - 1]; await sleep(250); }
    return null;
};
const codeOf = (message) => (message?.text.match(/Your code is\n(\d{6})\n/) || [])[1] || '';
const linkOf = (message) => (message?.text.match(/https?:\/\/\S+\/auth\/consume\?token=\S+/) || [])[0] || '';

async function context(browser, {token = null, js = true, width = 1440} = {}) {
    const ctx = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width, height: 1000}, javaScriptEnabled: js, reducedMotion: 'reduce'});
    if (token) await ctx.addCookies([{name: 'catto_learning_session', value: token, url: base, secure: true}]);
    const page = await ctx.newPage();
    page.errors = [];
    page.on('pageerror', (e) => page.errors.push(e.message));
    page.on('dialog', (dialog) => dialog.accept());
    return {ctx, page};
}
const submitIn = (page, selector) => Promise.all([page.waitForNavigation(), page.locator(selector).click()]);
async function askTelegram(page, who, js = true) {
    await page.goto(`${base}/login`, {waitUntil: 'load'});
    if (js) await page.locator('label.cl-ui-picture-choice-item:has(input[name=how][value=telegram])').click();
    await page.locator('#sign-in-telegram').fill(who);
    await submitIn(page, 'button[value=telegram]');
}

async function signInPageInEveryTheme(browsers) {
    for (const theme of themes) {
        for (const [name, type] of browsers) {
            for (const width of [1440, 390]) {
                const {ctx, page} = await context(type, {width});
                await page.goto(`${base}/login`, {waitUntil: 'load'});
                const label = `${name}, ${theme.name}, ${width}`;
                check(`${label}: drawn in that theme`, await page.locator(`link[href*="/${theme.slug}-v${theme.version}/"]`).count() > 0);
                const cards = await page.locator('.cl-ui-picture-choice--cards input[name=how]').count();
                const emailShown = await page.locator('#email').isVisible();
                const telegramHidden = !(await page.locator('#sign-in-telegram').isVisible());
                // Clicked once the page has settled: late fonts and the site notification's countdown can move the card between
                // Playwright working out where it is and clicking there.
                await page.evaluate(() => document.fonts.ready.then(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done)))));
                await page.locator('label.cl-ui-picture-choice-item:has(input[name=how][value=telegram])').click();
                await page.locator('#sign-in-telegram').waitFor({state: 'visible', timeout: 3000}).catch(() => {});
                const swapped = await page.locator('#sign-in-telegram').isVisible() && !(await page.locator('#email').isVisible());
                const chosen = await page.locator('input[name=how]:checked').getAttribute('value').catch(() => 'none');
                check(`${label}: two feature cards, and only the chosen way shows`, cards === 2 && emailShown && telegramHidden && swapped, `${cards} ${emailShown} ${telegramHidden} ${swapped}, chosen ${chosen}`);
                check(`${label}: nothing scrolls sideways`, await sideways(page) <= 0, String(await sideways(page)));
                await shot(page, `login-${theme.slug}-${width}-${name}`);
                check(`${label}: no script errors`, page.errors.length === 0, page.errors.join(' | '));
                await ctx.close();
            }
        }
    }
}

async function run(type, name, state) {
    const browser = await type.launch();
    const learner = state.learners[name.toLowerCase()];
    const chat = name === 'Chromium' ? 6100001 : 6100002;
    const username = name === 'Chromium' ? 'learner_chromium' : 'learner_firefox';
    const adminChat = name === 'Chromium' ? 6200001 : 6200002;

    // Account > Sign-in and security: connect Telegram.
    const me = await context(browser, {token: learner.token});
    await me.page.goto(`${base}/account/security`, {waitUntil: 'load'});
    check(`${name}: Sign-in and security has its two cards, Telegram not connected and two-step off`, await me.page.locator('#security-telegram').count() === 1 && await me.page.locator('#security-two-step').count() === 1
        && (await me.page.locator('#security-telegram .cl-ui-section-head').textContent()).includes('Not connected') && (await me.page.locator('#security-two-step .cl-ui-section-head').textContent()).includes('Off'));
    check(`${name}: it is in the Account menu`, await me.page.locator('a[href="/account/security"]').count() >= 1);
    await shot(me.page, `security-start-${name}`);
    await me.page.locator('#security-telegram button:has-text("Connect Telegram")').click();
    await me.page.locator('[data-security-telegram="waiting"]').waitFor();
    const link = await me.page.locator('#security-telegram a[href^="https://t.me/catto_check_bot?start="]').getAttribute('href');
    check(`${name}: Connect Telegram changes the card in place to a button to the bot`, Boolean(link) && me.page.url().endsWith('/account/security'), link);
    await shot(me.page, `security-waiting-${name}`);
    fixture('writes', chat, username, `/start ${new URL(link).searchParams.get('start')}`);
    let on = false;
    try { await me.page.locator('[data-security-telegram="on"]').waitFor({timeout: 20000}); on = true; } catch (_) { /* reported below */ }
    check(`${name}: the page notices by itself when Start is pressed`, on);
    check(`${name}: the card says who is connected`, (await me.page.locator('#security-telegram .cl-ui-section-head').textContent()).includes(`Connected to @${username}`));
    check(`${name}: and the bot said so`, (await newestTo(chat, 0))?.text.includes('Your Telegram account is now connected to'));
    const before = sent().filter((m) => m.chat_id === String(chat)).length;
    await me.page.locator('#security-telegram button:has-text("Send a test message")').click();
    await me.page.locator('#security-telegram [data-security-note]').waitFor();
    check(`${name}: Send a test message says it went, and it did`, (await me.page.locator('#security-telegram [data-security-note]').innerText()).includes('Sent. Check your phone.') && (await newestTo(chat, before))?.text.startsWith('[DEMO] Test message from'));
    await shot(me.page, `security-connected-${name}`);

    // Two-step, set up from the QR code on the screen.
    await me.page.locator('#security-two-step button:has-text("Set up two-step")').click();
    await me.page.locator('[data-security-two-step="scan"]').waitFor();
    const address = await readQr(me.page);
    const secret = (address.match(/[?&]secret=([A-Z2-7]+)/) || [])[1] || '';
    check(`${name}: the QR code read off the screen is an authenticator app's address`, address.startsWith('otpauth://totp/') && secret.length === 32, address.replace(/secret=[A-Z2-7]+/, 'secret=…'));
    check(`${name}: the key to type is the same key`, (await me.page.locator('[data-two-step-key]').innerText()).replace(/\s/g, '') === secret);
    await shot(me.page, `security-scan-${name}`);
    await me.page.locator('#two-step-setup-code').fill('000000' === codeAt(secret, Date.now() / 1000) ? '111111' : '000000');
    await me.page.locator('#security-two-step button:has-text("Turn on two-step")').click();
    await me.page.locator('#security-two-step [data-security-note]').waitFor();
    check(`${name}: a wrong first code is refused`, (await me.page.locator('#security-two-step [data-security-note]').innerText()).includes('That code did not work'));
    let used = Math.floor(Date.now() / 1000 / 30);
    await me.page.locator('#two-step-setup-code').fill(codeAt(secret, Date.now() / 1000));
    await me.page.locator('#security-two-step button:has-text("Turn on two-step")').click();
    await me.page.locator('[data-security-two-step="codes"]').waitFor();
    const recovery = await me.page.locator('[data-recovery-codes] li').allInnerTexts();
    check(`${name}: the right code turns it on and shows eight recovery codes`, recovery.length === 8 && recovery.every((c) => /^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/.test(c)), recovery.join(' '));
    await shot(me.page, `security-codes-${name}`);
    await me.page.locator('#security-two-step button:has-text("I have kept them")').click();
    await me.page.locator('[data-security-two-step="on"]').waitFor();
    check(`${name}: then it is on, with 8 codes left, and they are not shown again`, (await me.page.locator('#security-two-step').innerText()).includes('You have 8 recovery codes left') && await me.page.locator('[data-recovery-codes]').count() === 0);
    await me.page.goto(`${base}/account/security?two_step=off`, {waitUntil: 'load'});
    check(`${name}: Turn off asks for a current code first`, await me.page.locator('#two-step-current').count() === 1);
    await me.page.setViewportSize({width: 390, height: 900});
    check(`${name}: Sign-in and security does not scroll sideways on a phone`, await sideways(me.page) <= 0, String(await sideways(me.page)));
    await shot(me.page, `security-on-390-${name}`);
    check(`${name}: no script errors on Sign-in and security`, me.page.errors.length === 0, me.page.errors.join(' | '));

    // Signing in with Telegram: the same page whether or not the account exists.
    const stranger = await context(browser);
    await askTelegram(stranger.page, '@nobody_by_that_name');
    const strangerWords = await mainWords(stranger.page);
    const waitingBefore = sent().filter((m) => m.chat_id === String(chat)).length;
    const asker = await context(browser);
    await askTelegram(asker.page, `@${username.toUpperCase()}`);
    const askerWords = await mainWords(asker.page);
    check(`${name}: an unknown username and a real one get the same page`, asker.page.url().endsWith('/login/telegram') && stranger.page.url().endsWith('/login/telegram') && strangerWords === askerWords, `${strangerWords.length} ${askerWords.length}`);
    check(`${name}: it says what the approved mockup says`, askerWords.includes('Check your Telegram') && askerWords.includes('If this account has Telegram connected, a message is on its way. Open the link in it, or type its code here.'));
    let message = await newestTo(chat, waitingBefore);
    check(`${name}: only the real one got a message, with its link preview off`, Boolean(codeOf(message)) && Boolean(linkOf(message)) && message.link_preview_options?.is_disabled === true, JSON.stringify(message || {}).slice(0, 200));
    await shot(asker.page, `login-telegram-${name}`);
    // A code typed in another browser.
    await stranger.page.locator('#telegram-code').fill(codeOf(message));
    await submitIn(stranger.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: the code does not work in another browser`, (await stranger.page.locator('main').innerText()).includes(WRONG) && stranger.page.url().endsWith('/login/telegram'));
    await stranger.ctx.close();
    await asker.page.locator('#telegram-code').fill(codeOf(message) === '000000' ? '111111' : '000000');
    await submitIn(asker.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: a wrong code says only that it did not work`, (await asker.page.locator('main').innerText()).includes(WRONG));
    const oldCode = codeOf(message);
    await submitIn(asker.page, 'form[action="/login/telegram/again"] button');
    check(`${name}: Send a new code says the older one no longer works`, (await asker.page.locator('[data-telegram-resent]').innerText()).includes('A new message is on its way. The older code no longer works.'));
    message = await newestTo(chat, waitingBefore + 1);
    await asker.page.locator('#telegram-code').fill(oldCode);
    await submitIn(asker.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: the older code no longer works`, (await asker.page.locator('main').innerText()).includes(WRONG) || oldCode === codeOf(message));
    await asker.page.locator('#telegram-code').fill(codeOf(message));
    await submitIn(asker.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: the right code goes to two-step's step, not in yet`, asker.page.url().endsWith('/login/two-step') && (await asker.page.locator('h1').innerText()).includes('One more step'));
    const signedIn = async (page) => (await page.context().cookies()).some((c) => c.name === 'catto_learning_session');
    check(`${name}: and there is no session before it`, !(await signedIn(asker.page)));
    await shot(asker.page, `login-two-step-${name}`);
    await submitIn(asker.page, 'a[href="/login/two-step?use=recovery"]');
    await asker.page.locator('#two-step-recovery').fill(recovery[0].toLowerCase());
    await submitIn(asker.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: a recovery code signs in`, await signedIn(asker.page) && (await asker.page.locator('body').innerText()).includes('You are signed in.'), asker.page.url());
    await asker.ctx.close();

    // The administrator: the person's Sign-in card and Reset two-step, the log, Settings.
    const admin = await context(browser, {token: state.admin_token});
    await admin.page.goto(`${base}/admin/people/${learner.account_reference}`, {waitUntil: 'load'});
    const card = (await admin.page.locator('#sign-in').innerText()).toLowerCase();
    check(`${name}: the person's page says their Telegram and their two-step`, card.includes(`@${username}, connected`.toLowerCase()) && card.includes('7 recovery codes left'), card);
    if (shots) await admin.page.locator('#sign-in').screenshot({path: `${shots}/person-sign-in-${name}.png`});
    await submitIn(admin.page, '#sign-in button:has-text("Reset two-step")');
    check(`${name}: Reset two-step turns it off for them`, (await admin.page.locator('#sign-in dd').nth(1).innerText()).trim().toLowerCase() === 'off', await admin.page.locator('#sign-in').innerText());
    await admin.page.goto(`${base}/admin/people/${state.admin.account_reference}`, {waitUntil: 'load'});
    check(`${name}: an administrator's own page has no Reset two-step`, await admin.page.locator('#sign-in button:has-text("Reset two-step")').count() === 0);

    await admin.page.goto(`${base}/admin/site-analytics/telegram-log?list=sign_in`, {waitUntil: 'load'});
    const logWords = await admin.page.locator('main').innerText();
    const logLower = logWords.toLowerCase();
    const codes = sent().map(codeOf).filter(Boolean);
    const tokens = sent().map(linkOf).filter(Boolean).map((l) => new URL(l).searchParams.get('token'));
    check(`${name}: on a wide screen, To is its own column and not repeated under the message`, !(await admin.page.locator('.cl-telegram-to-inline').first().isVisible()));
    check(`${name}: the log names who each sign-in went to, by name and username`, logLower.includes(learner.name.toLowerCase()) && logLower.includes(`@${username}`), logWords.slice(0, 400));
    check(`${name}: and keeps no code or link`, logLower.includes('(not kept)') && codes.every((c) => !logWords.includes(c)) && tokens.every((t) => !(logWords.includes(t))));
    check(`${name}: it has the mockup's cards`, ['All', 'Sign-ins', 'Connections', 'Registrations', 'Orders', 'Sales', 'Test messages', 'Not sent'].every((w) => logLower.includes(w.toLowerCase())));
    await shot(admin.page, `telegram-log-${name}`);
    await admin.page.setViewportSize({width: 390, height: 900});
    check(`${name}: the log does not scroll sideways on a phone, and says who under each message`, await sideways(admin.page) <= 0 && await admin.page.locator('.cl-telegram-to-inline').first().isVisible(), String(await sideways(admin.page)));
    await shot(admin.page, `telegram-log-390-${name}`);
    await admin.page.setViewportSize({width: 1440, height: 1000});

    await admin.page.goto(`${base}/admin/settings?section=owner-messages#settings-owner-messages`, {waitUntil: 'load'});
    // One row of four ticks in the owner's order (owner, 2026-11-06: "Put them in this order: sign in, new account, new order, payment").
    const tick = () => admin.page.locator('input[name=telegram_sign_in]');
    const labels = (await admin.page.locator('#owner-messages-events .cl-ui-picture-choice-label').allInnerTexts()).map((l) => l.trim());
    check(`${name}: Settings has the four ticks in the owner's order`, JSON.stringify(labels) === JSON.stringify(['Login via Telegram', 'New Account', 'New Order', 'Payment made']), labels.join(' | '));
    const tops = await admin.page.locator('#owner-messages-events .cl-ui-picture-choice-label').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top));
    check(`${name}: all four on one row at 1440 pixels`, tops.length === 4 && Math.max(...tops) - Math.min(...tops) < 3, tops.map(Math.round).join(','));
    check(`${name}: Login via Telegram ticked`, await tick().isChecked());
    check(`${name}: with the line that a bot serves one site`, (await admin.page.locator('[data-telegram-one-site]').innerText()).includes('A bot can serve one site for sign-in'));
    const saved = admin.page.waitForResponse((r) => r.url().endsWith('/owner-messages/choices'));
    await tick().setChecked(false, {force: true});
    await saved;
    await admin.page.reload({waitUntil: 'load'});
    check(`${name}: unticked, it is saved in place`, !(await tick().isChecked()) && await admin.page.locator('input[name=on_order]').isChecked());
    if (shots) await admin.page.locator('#settings-owner-messages').screenshot({path: `${shots}/settings-telegram-off-${name}.png`});
    const back = admin.page.waitForResponse((r) => r.url().endsWith('/owner-messages/choices'));
    await tick().setChecked(true, {force: true});
    await back;
    await admin.page.goto(`${base}/admin/settings?section=two-step#settings-two-step`, {waitUntil: 'load'});
    check(`${name}: Settings > Two-step sign-in has the three choices`, await admin.page.locator('#settings-two-step input[name=two_step]').count() === 3);
    await admin.page.locator('#settings-two-step label.cl-ui-picture-choice-item:has(input[value=ADMINS])').click();
    await submitIn(admin.page, '#settings-two-step form[action="/admin/settings/two-step"] button[type=submit]');
    check(`${name}: and saves`, (await admin.page.locator('body').innerText()).includes('Saved. Administrators must, others may choose.') && await admin.page.locator('#settings-two-step input[value=ADMINS]').isChecked());
    if (shots) await admin.page.locator('#settings-two-step').screenshot({path: `${shots}/settings-two-step-${name}.png`});
    await admin.ctx.close();

    // An administrator the policy names sets two-step up before getting in.
    fixture('connect', state.admin.id, adminChat, `site_admin_${name.toLowerCase()}`);
    fixture('reset-two-step', state.admin.id);
    const boss = await context(browser);
    await askTelegram(boss.page, `site_admin_${name.toLowerCase()}`);
    message = await newestTo(adminChat, 0);
    await boss.page.locator('#telegram-code').fill(codeOf(message));
    await submitIn(boss.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: an administrator goes to set two-step up first`, boss.page.url().endsWith('/login/two-step/setup') && !(await signedIn(boss.page)));
    const bossSecret = ((await readQr(boss.page)).match(/[?&]secret=([A-Z2-7]+)/) || [])[1] || '';
    await shot(boss.page, `login-setup-${name}`);
    await boss.page.locator('#two-step-setup-code').fill(codeAt(bossSecret, Date.now() / 1000));
    await submitIn(boss.page, 'form[action="/login/two-step/setup"] button[type=submit]');
    check(`${name}: shown the recovery codes, still not in`, await boss.page.locator('[data-recovery-codes] li').count() === 8 && !(await signedIn(boss.page)));
    await submitIn(boss.page, 'form[action="/login/two-step/setup/kept"] button');
    check(`${name}: then signed in`, await signedIn(boss.page) && (await boss.page.locator('body').innerText()).includes('You are signed in.'));
    await boss.ctx.close();
    fixture('policy', 'CHOICE');

    // The link, in another browser, works once; used again it says what a used email link says.
    const linkAsker = await context(browser);
    const sentBefore = sent().filter((m) => m.chat_id === String(chat)).length;
    await askTelegram(linkAsker.page, username);
    message = await newestTo(chat, sentBefore);
    const other = await context(browser);
    await other.page.goto(linkOf(message), {waitUntil: 'load'});
    check(`${name}: the link signs in in another browser (two-step now reset)`, await signedIn(other.page));
    const again = await context(browser);
    await again.page.goto(linkOf(message), {waitUntil: 'load'});
    const usedWords = await mainWords(again.page);
    await again.page.goto(`${base}/auth/consume?token=not-a-real-link-at-all-just-letters`, {waitUntil: 'load'});
    const emailWords = await mainWords(again.page);
    check(`${name}: a used link says what a used email link says`, usedWords.includes('invalid or has expired') && usedWords === emailWords, usedWords.slice(0, 200));
    await linkAsker.page.locator('#telegram-code').fill(codeOf(message));
    await submitIn(linkAsker.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: and its code is used up with it`, (await linkAsker.page.locator('main').innerText()).includes(WRONG));
    for (const c of [linkAsker, other, again]) await c.ctx.close();

    // Disconnect, then everything again without JavaScript.
    await me.page.setViewportSize({width: 1440, height: 1000});
    await me.page.goto(`${base}/account/security`, {waitUntil: 'load'});
    await me.page.locator('#security-telegram button:has-text("Disconnect")').click();
    await me.page.locator('[data-security-telegram="off"]').waitFor();
    check(`${name}: Disconnect forgets it`, (await me.page.locator('#security-telegram').innerText()).includes('Telegram is disconnected'));
    await me.ctx.close();

    const plain = await context(browser, {token: learner.token, js: false});
    await plain.page.goto(`${base}/account/security`, {waitUntil: 'load'});
    await submitIn(plain.page, '#security-telegram button:has-text("Connect Telegram")');
    const plainLink = await plain.page.locator('#security-telegram a[href^="https://t.me/"]').getAttribute('href');
    fixture('writes', chat, username, `/start ${new URL(plainLink).searchParams.get('start')}`);
    await submitIn(plain.page, '#security-telegram button:has-text("I have pressed Start")');
    check(`${name}: without JavaScript, I have pressed Start connects it`, await plain.page.locator('[data-security-telegram="on"]').count() === 1);
    await plain.ctx.close();
    const plainIn = await context(browser, {js: false});
    await plainIn.page.goto(`${base}/login`, {waitUntil: 'load'});
    check(`${name}: without JavaScript, both ways show`, await plainIn.page.locator('#email').isVisible() && await plainIn.page.locator('#sign-in-telegram').isVisible());
    await shot(plainIn.page, `login-nojs-${name}`);
    const count = sent().filter((m) => m.chat_id === String(chat)).length;
    await plainIn.page.locator('#sign-in-telegram').fill(username);
    await submitIn(plainIn.page, 'button[value=telegram]');
    message = await newestTo(chat, count);
    check(`${name}: and Send to my Telegram reads the Telegram field`, plainIn.page.url().endsWith('/login/telegram') && Boolean(codeOf(message)));
    await plainIn.page.locator('#telegram-code').fill(codeOf(message));
    await submitIn(plainIn.page, '.cl-sign-in-code button[type=submit]');
    check(`${name}: and the code signs in`, await signedIn(plainIn.page));
    await plainIn.ctx.close();
    await browser.close();
}

(async () => {
    let state;
    try {
        state = JSON.parse(fixture('create'));
        const before = JSON.parse(fixture('counts'));
        check('the site writes to the stand-in, never to Telegram', /^http:\/\/127\.0\.0\.1:\d+$/.test(before.address || ''), before.address);
        const browsers = [['Chromium', await chromium.launch()], ['Firefox', await firefox.launch()]];
        await signInPageInEveryTheme(browsers);
        for (const [, browser] of browsers) await browser.close();
        await run(chromium, 'Chromium', state);
        await run(firefox, 'Firefox', state);
        const after = JSON.parse(fixture('counts'));
        const delivered = sent().length;
        check('every message the site sent went to the stand-in', after.messages - before.messages === delivered, `${after.messages - before.messages} kept, ${delivered} at the stand-in`);
    } catch (error) {
        console.error(error);
        results.push(false);
    } finally {
        const cleaned = JSON.parse(fixture('cleanup') || '{}');
        check('the Telegram log has as many messages after the check as before', cleaned.messages_before === cleaned.messages_after, JSON.stringify(cleaned));
        const failed = results.filter((ok) => !ok).length;
        console.log(`${results.length} checks, ${failed} failures`);
        process.exit(failed ? 1 : 0);
    }
})();
