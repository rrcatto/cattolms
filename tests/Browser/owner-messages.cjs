/* Messages to the owner's Telegram (owner, 2026-11-05: "yes telegram, paid orders only"), Settings > Messages to you, in Chromium and
   Firefox, at 1440 and 390 pixels wide, against a stand-in of Telegram, so no message goes to the real service:
   - the section comes straight after Site notification, says how to make a bot, and its ticks are feature cards, both on by default;
   - a key of the wrong shape is refused, a good one is saved and never appears in the page again;
   - Connect to Telegram connects only the account Settings names, in any case, never a stranger who pressed Start on the bot, and says
     what to do until that account has, and Send a test message reaches that account's chat, naming the site;
   - a tick unticked stays unticked;
   - with Telegram down the test says so, a waiting message shows its failure and goes when Telegram is back, and the key never shows;
   - Disconnect forgets the key; nothing scrolls sideways; saving works without JavaScript.
   Fixture: php tests/Browser/owner-messages-fixture.php create|cleanup (in env_php_1). CATTO_SHOTS=<folder> keeps pictures. */
const {chromium, firefox} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-owner-messages-state.json'));
const KEY = '123456789:stand-in-key-for-checks-only';
const shots = process.env.CATTO_SHOTS || '';
const results = [];
const check = (name, ok, detail = '') => { results.push(Boolean(ok)); console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok || !detail ? '' : ' (' + detail + ')'}`); };
const fixture = (...args) => execFileSync('podman', ['exec', '-u', 'cattotest', 'env_php_1', 'sh', '-lc', `SHELL_VERBOSITY=-1 php tests/Browser/owner-messages-fixture.php ${args.join(' ')} 2>/dev/null`], {encoding: 'utf8'});
const section = '#settings-owner-messages';
const open = (page) => page.goto(`${base}/admin/settings?section=owner-messages`, {waitUntil: 'load'});
const note = async (page) => (await page.locator(`${section} [data-owner-messages-note]`).allInnerTexts()).join(' ');
const title = (page) => page.locator(`${section} > summary .cl-ui-accordion-status`).innerText();
const stateOf = (page) => page.locator(`${section} [data-owner-messages]`).getAttribute('data-owner-messages');
const boxWords = (page) => page.locator(`${section} [data-owner-messages] > .cl-ui-notice`).innerText();
const press = async (page, form) => Promise.all([page.waitForNavigation(), page.locator(`${section} form[action="/admin/settings/owner-messages${form}"] button[type=submit]`).click()]);
const sideways = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const connect = async (page, key, username = '@your_username') => { await page.locator('#owner-messages-key').fill(key); await page.locator('#owner-messages-username').fill(username); await press(page, '/connect'); };

async function run(browserType, name) {
    const browser = await browserType.launch();
    const errors = [];
    const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
    await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const page = await context.newPage();
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('dialog', (dialog) => dialog.accept());
    fixture('started', 'off'); fixture('down', 'off');

    await open(page);
    const order = await page.locator('.cl-admin-workspace details[id^="settings-"]').evaluateAll((all) => all.map((d) => d.id));
    check(`${name}: it comes straight after Site notification`, order.indexOf('settings-owner-messages') === order.indexOf('settings-site-notification') + 1, order.slice(0, 4).join(', '));
    check(`${name}: the title says it is not connected`, (await title(page)).trim() === 'Not connected', await title(page));
    check(`${name}: so does a status message`, /Not connected|Disconnected/.test(await boxWords(page)), await boxWords(page));
    check(`${name}: the two fields and Connect to Telegram show, and no test or disconnect`, await page.locator('#owner-messages-key').count() === 1 && await page.locator('#owner-messages-username').count() === 1 && await page.locator(`${section} button:has-text("Connect to Telegram")`).count() === 1 && await page.locator(`${section} form[action$="/test"]`).count() === 0 && await page.locator(`${section} form[action$="/disconnect"]`).count() === 0);
    check(`${name}: the fields say whose username it is`, (await page.locator('label[for=owner-messages-username]').innerText()).toLowerCase().includes('your personal telegram username') && (await page.locator('label[for=owner-messages-key]').innerText()).toLowerCase().includes('bot key'));
    check(`${name}: the ticks are feature cards, both on by default`, await page.locator(`${section} .cl-ui-picture-choice--cards input[name=on_register]:checked`).count() === 1 && await page.locator(`${section} .cl-ui-picture-choice--cards input[name=on_sale]:checked`).count() === 1);
    const words = await page.locator(section).innerText();
    check(`${name}: no long explanations and no old words`, !/find my telegram|install telegram|\/newbot/i.test(words) && words.length < 500, String(words.length));
    if (shots) await page.locator(section).screenshot({path: `${shots}/${name}-start-1440.png`});

    await press(page, '/connect');
    check(`${name}: no key is refused beside the button`, (await note(page)).includes('Paste the bot key'), await note(page));
    await connect(page, 'not a key');
    check(`${name}: a key of the wrong shape is refused`, (await note(page)).includes('not a bot key'), await note(page));
    await connect(page, '999999:a-key-the-stand-in-does-not-know');
    check(`${name}: a key Telegram refuses is refused`, (await note(page)).includes('did not accept the key'), await note(page));
    check(`${name}: and is not saved`, await stateOf(page) === 'none' && (await title(page)).trim() === 'Not connected');
    await connect(page, KEY, 'not valid!');
    check(`${name}: a username of the wrong shape is refused`, (await note(page)).includes('not a Telegram username'), await note(page));
    check(`${name}: and the good key is kept, so it need not be pasted again`, await page.locator('#owner-messages-key').getAttribute('placeholder') === 'Saved');
    await open(page);
    check(`${name}: the note goes once it has been read`, (await note(page)) === '', await note(page));
    await page.locator('#owner-messages-username').fill('catto_check_bot');
    await press(page, '/connect');
    check(`${name}: the bot's own username is refused`, (await note(page)).includes('That is the bot'), await note(page));
    await page.locator('#owner-messages-username').fill('@your_username');
    await press(page, '/connect');
    check(`${name}: a stranger having written to the bot connects nobody, and it says what to do`, await stateOf(page) === 'waiting' && (await boxWords(page)).includes('Not connected yet') && (await boxWords(page)).includes('send it hello'), await boxWords(page));
    check(`${name}: the title still says not connected`, (await title(page)).trim() === 'Not connected');
    check(`${name}: there is a button straight to the bot`, await page.locator(`${section} a[href="https://t.me/catto_check_bot"]`).count() === 1);
    check(`${name}: the key is saved and never appears again`, !(await page.content()).includes('stand-in-key') && await page.locator('#owner-messages-key').inputValue() === '' && await page.locator('#owner-messages-key').getAttribute('placeholder') === 'Saved');
    check(`${name}: the username is kept, without its @`, await page.locator('#owner-messages-username').inputValue() === 'your_username');
    if (shots) await page.locator(section).screenshot({path: `${shots}/${name}-waiting-1440.png`});

    fixture('started', 'on');
    await press(page, '/connect');
    check(`${name}: once that account has written, it connects, whatever the case`, await stateOf(page) === 'connected', await boxWords(page));
    check(`${name}: the title says what is connected to what`, (await title(page)).includes('Connected: @catto_check_bot to @your_username'), await title(page));
    check(`${name}: so does a status message`, (await boxWords(page)).includes('Connected') && (await boxWords(page)).includes('@catto_check_bot sends your messages to @your_username.'), await boxWords(page));
    check(`${name}: the fields are gone`, await page.locator('#owner-messages-key').count() === 0 && await page.locator('#owner-messages-username').count() === 0 && await page.locator(`${section} button:has-text("Connect to Telegram")`).count() === 0);
    check(`${name}: Send test message and Disconnect show`, await page.locator(`${section} button:has-text("Send test message")`).count() === 1 && await page.locator(`${section} button:has-text("Disconnect")`).count() === 1);
    const status = () => page.locator(`${section} [data-owner-messages-status]`).innerText();

    await press(page, '/test');
    check(`${name}: Send test message says it went, beside the buttons`, (await note(page)).includes('Test message sent'), await note(page));
    const asked = fixture('log').trim().split('\n').filter(Boolean).map((line) => JSON.parse(line)).filter((one) => one.path.endsWith('/sendMessage'));
    check(`${name}: and only that account's chat got it, marked as a demo and naming the site`, /^\[DEMO\] Test message.* \([^)]+\)$/.test(asked.at(-1)?.body?.text || '') && asked.every((one) => one.body.chat_id === asked[0].body.chat_id), JSON.stringify(asked.at(-1) || {}));

    if (!state.noJs) {
        const saved = page.waitForResponse((response) => response.url().endsWith('/owner-messages/choices'));
        await page.locator(`${section} input[name=on_sale]`).setChecked(false, {force: true});
        const answer = await saved;
        check(`${name}: unticking saves at once, with no reload`, answer.status() === 204 && await stateOf(page) === 'connected', String(answer.status()));
        await open(page);
        check(`${name}: it stays unticked, and still connected`, await page.locator(`${section} input[name=on_sale]:checked`).count() === 0 && await page.locator(`${section} input[name=on_register]:checked`).count() === 1 && await stateOf(page) === 'connected');
        const back = page.waitForResponse((response) => response.url().endsWith('/owner-messages/choices'));
        await page.locator(`${section} input[name=on_sale]`).setChecked(true, {force: true});
        await back;
    }

    fixture('down', 'on');
    await press(page, '/test');
    check(`${name}: with Telegram down, the test says so`, (await note(page)).includes('having trouble'), await note(page));
    fixture('job');
    await open(page);
    check(`${name}: a message that could not go shows why, and waits`, await stateOf(page) === 'failing' && (await status()).includes('having trouble') && (await status()).includes('Waiting to go: 1'), await status());
    check(`${name}: the title says so too`, (await title(page)).includes('Not getting through: @catto_check_bot to @your_username'), await title(page));
    check(`${name}: the key is not in the page`, !(await page.content()).includes('stand-in-key'));
    if (shots) await page.locator(section).screenshot({path: `${shots}/${name}-failing-1440.png`});
    fixture('down', 'off');
    fixture('retry');
    await open(page);
    check(`${name}: it goes when Telegram is back`, await stateOf(page) === 'connected', await boxWords(page));

    // The Telegram log (owner: "so I can see what messages you sent and I can check if I got them").
    await open(page);
    const logLink = page.locator(`${section} a[href="/admin/site-analytics/telegram-log"]`);
    check(`${name}: Settings links to the Telegram log`, await logLink.count() === 1);
    await Promise.all([page.waitForNavigation(), logLink.click()]);
    check(`${name}: it opens the Telegram log`, (await page.locator('h1').innerText()).includes('Telegram log'));
    const menu = await page.locator('a[href^="/admin/site-analytics"]').evaluateAll((all) => all.map((a) => a.getAttribute('href')));
    check(`${name}: in the menu it comes straight after Site Log`, menu.indexOf('/admin/site-analytics/telegram-log') === menu.indexOf('/admin/site-analytics/log') + 1, menu.join(' '));
    const logWords = await page.locator('main').innerText();
    check(`${name}: it lists the test message and the sale, each with its status`, /Test message: your messages from this site will arrive here/.test(logWords) && /A message from the browser check\./.test(logWords) && /Sent/i.test(logWords) && /Not sent/i.test(logWords), logWords.slice(0, 400));
    const dates = await page.locator('[data-telegram-message] td:first-child').allInnerTexts();
    check(`${name}: newest first`, dates.length >= 2);
    await page.locator('input[name=list][value=test]').check({force: true});
    await page.waitForURL(/list=test/);
    await page.locator('[data-telegram-message]').first().waitFor();
    const kinds = await page.locator('[data-telegram-message] td:nth-child(2)').allInnerTexts();
    check(`${name}: a card shows only that kind, in place`, kinds.length >= 2 && kinds.every((kind) => kind.trim() === 'Test'), kinds.join(','));
    await page.setViewportSize({width: 390, height: 900});
    // The page fits its columns again 200 ms after the window changes size (platform-overrides.js): measure what it settles on.
    await page.waitForTimeout(500);
    check(`${name}: the log does not scroll sideways on a phone`, await sideways(page) <= 0, String(await sideways(page)));
    const phoneColumns = await page.locator('[data-telegram-message]').first().locator('td').evaluateAll((cells) => cells.map((cell) => `${getComputedStyle(cell).display}:${Math.round(cell.getBoundingClientRect().left)}-${Math.round(cell.getBoundingClientRect().right)}`));
    const phoneBox = await page.locator('.cl-ui-table').first().evaluate((box) => `${box.scrollLeft}/${box.scrollWidth}/${box.clientWidth}`);
    const wide = (column) => { const [from, to] = column.split(':')[1].split('-').map(Number); return !column.startsWith('none') && to - from >= 40; };
    check(`${name}: on a phone the log says when, the message and the status, without scrolling inside its box`, wide(phoneColumns[0]) && wide(phoneColumns[3]) && wide(phoneColumns[4]) && phoneBox.split('/')[1] === phoneBox.split('/')[2], `${phoneColumns.join(' ')} box ${phoneBox}`);
    if (shots) await page.screenshot({path: `${shots}/${name}-log-390.png`, fullPage: true});
    await page.setViewportSize({width: 1440, height: 1000});
    if (shots) { await page.goto(`${base}/admin/site-analytics/telegram-log`, {waitUntil: 'load'}); await page.screenshot({path: `${shots}/${name}-log-1440.png`, fullPage: true}); }

    for (const width of [1440, 390]) {
        await page.setViewportSize({width, height: 900});
        await open(page);
        check(`${name}: nothing scrolls sideways at ${width}`, await sideways(page) <= 0, String(await sideways(page)));
        if (shots) await page.locator(section).screenshot({path: `${shots}/${name}-connected-${width}.png`});
    }
    await page.setViewportSize({width: 1440, height: 1000});

    await press(page, '/disconnect');
    check(`${name}: Disconnect says Disconnected`, await stateOf(page) === 'none' && (await boxWords(page)).includes('Disconnected') && (await title(page)).trim() === 'Not connected', await boxWords(page));
    check(`${name}: and the fields are back, empty`, await page.locator('#owner-messages-key').count() === 1 && await page.locator('#owner-messages-username').inputValue() === '' && await page.locator('#owner-messages-key').getAttribute('placeholder') !== 'Saved');
    await page.setViewportSize({width: 390, height: 900});
    await open(page);
    check(`${name}: nothing scrolls sideways at 390 before connecting`, await sideways(page) <= 0, String(await sideways(page)));
    if (shots) await page.locator(section).screenshot({path: `${shots}/${name}-start-390.png`});
    await page.setViewportSize({width: 1440, height: 1000});

    const plain = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1000}, javaScriptEnabled: false, reducedMotion: 'reduce'});
    await plain.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
    const still = await plain.newPage();
    await open(still);
    await connect(still, KEY);
    check(`${name}: without JavaScript it connects`, (await title(still)).includes('Connected: @catto_check_bot to @your_username'), await title(still));
    await still.locator(`${section} input[name=on_register]`).setChecked(false, {force: true});
    await Promise.all([still.waitForNavigation(), still.locator(`${section} form[data-owner-messages-choices] button[type=submit]`).click()]);
    check(`${name}: and the ticks save with their own button`, await still.locator(`${section} input[name=on_register]:checked`).count() === 0);
    await still.locator(`${section} input[name=on_register]`).setChecked(true, {force: true});
    await Promise.all([still.waitForNavigation(), still.locator(`${section} form[data-owner-messages-choices] button[type=submit]`).click()]);
    await press(still, '/disconnect');
    check(`${name}: and it disconnects`, (await title(still)).trim() === 'Not connected');
    await plain.close();
    fixture('started', 'off');

    check(`${name}: no script errors`, errors.length === 0, errors.join(' | '));
    await browser.close();
}

(async () => {
    await run(chromium, 'Chromium');
    await run(firefox, 'Firefox');
    const failed = results.filter((ok) => !ok).length;
    console.log(`${results.length} checks, ${failed} failures`);
    process.exit(failed ? 1 : 0);
})().catch((error) => { console.error(error); process.exit(1); });
