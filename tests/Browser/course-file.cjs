/* A course written out by the site keeps the course's styling and works (owner, 2026-11-03: put the styling back;
   v1.0.4 and v1.0.5 lost 144 of 172 rules to one broken line, and every course's progress strip, results panel and
   script). What it holds is checked by course-file-contents.php: everything on the site, not what the old file said. Each file
   is opened beside its original, in Chromium and Firefox, with the network off:
   - stylesheet: the browser accepts at least as many rules from the course's CSS tab as it did from the original (rules a
     browser throws away are the fault);
   - look: font, size, colours, corners and shadow of the same elements are the same, panels open and closed;
   - build: the strip, masthead, coloured word, pills, scale, reset button, results panel, modules and icons are all there,
     every panel a <details> and none a button, and the cover shows;
   - behaviour: the same learner, answering every question, gets the same results from the file's own script: each module
     opened and marked, progress, final assessment, results, a reload, the reset;
   - parts: every word of every slide and flash card reaches the screen, which the original had no way to fail;
   - without JavaScript, every panel opens and closes.
   The list comes from course-file-fixture.php (a made-up course: run it in env_php_1, then copy /tmp/catto-course-file/ to
   the host), or from a JSON file named as the first argument: [{name, original, file}], paths absolute or beside the list. A
   pair's `allow` names what an edit on the site has since taken away, such as {rules: 5}: rules taken off the CSS tab. */
const pw = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const listFile = process.argv[2] || '/tmp/catto-course-file/pairs.json';
const pairs = JSON.parse(fs.readFileSync(listFile));
const engines = (process.env.CATTO_BROWSERS || 'chromium,firefox').split(',');
let checks = 0, failures = 0;
const check = (ok, what) => { checks++; if (!ok) { failures++; console.log('  FAIL ' + what); } };
const same = (a, b, what) => { const x = JSON.stringify(a), y = JSON.stringify(b); check(x === y, what + ': the original ' + x + ', the file ' + y); };

const open = async (browser, file, script = true) => {
    const context = await browser.newContext({javaScriptEnabled: script, viewport: {width: 1280, height: 900}, reducedMotion: 'reduce'});
    const page = await context.newPage();
    page.setDefaultTimeout(8000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route(url => !url.href.startsWith('file:'), route => route.abort());
    await page.goto('file://' + file, {waitUntil: 'load'});
    await page.waitForTimeout(60);
    return {context, page, errors};
};

/* The rules a browser accepted from the course's own stylesheets. */
const ruleCount = page => page.evaluate(() => [...document.styleSheets].filter(sheet => sheet.ownerNode.tagName === 'STYLE' && !sheet.ownerNode.hasAttribute('data-course-file')).reduce((sum, sheet) => sum + sheet.cssRules.length, 0));

const LOOK = ['body', '.progress-strip', '.masthead', '.masthead h1', '.masthead h1 em', '.masthead .eyebrow', '.meta span', '.info', '.scale-list li', '.module', '.module > .acc-trigger', '.m-name', '.m-titles small', '.node', '.module > .acc-trigger .chev', '.final', '.module-body', '.s-summary', '.s-summary > .acc-trigger', '.s-summary .sub-ico', '.s-summary .sub-label', '.s-summary .sub-inner', '.s-assess', '.s-assess .sub-ico', '.outcomes'];
const look = page => page.evaluate(names => Object.fromEntries(names.map(name => {
    const node = document.querySelector(name);
    if (!node) return [name, null];
    const style = getComputedStyle(node);
    return [name, [style.fontFamily, style.fontSize, style.fontWeight, style.color, style.backgroundColor, style.borderRadius, style.boxShadow, style.textAlign, style.cursor]];
})), LOOK);

const build = page => page.evaluate(() => {
    const has = selector => document.querySelectorAll(selector).length;
    return {strip: has('.progress-strip #psFill, #psFill'), pct: has('#psPct'), count: has('#psCount'), masthead: has('.masthead'), eyebrow: has('.masthead .eyebrow'), word: has('.masthead h1 em'), pills: has('.meta > *'), scale: has('.scale-list li'), reset: has('#resetBtn, #resetcourse, .reset-link'), results: has('#overallPanel'), modules: has('.module'), review: has('#mod-review'), final: has('#final'), diagnostic: has('.diag')};
});

const reading = page => page.evaluate(() => {
    const overall = document.getElementById('overallPanel');
    const text = id => (document.getElementById(id) || {}).textContent || '';
    return {pct: text('psPct'), count: text('psCount'), done: document.querySelectorAll('.module.done').length, chips: [...document.querySelectorAll('.module .m-grade')].filter(chip => chip.textContent.trim()).length, results: overall ? getComputedStyle(overall).display !== 'none' && overall.getBoundingClientRect().height > 0 : null};
});

const answer = (page, panel) => page.evaluate(selector => {
    const node = document.querySelector(selector);
    let radios = 0, marked = false;
    for (const quiz of node ? node.querySelectorAll('.quiz') : []) {
        const names = new Set();
        quiz.querySelectorAll('input[type=radio]').forEach(input => { if (!names.has(input.name)) { names.add(input.name); input.click(); } });
        radios += names.size;
        const pick = '[data-grade-btn], button.btn-grade, button[data-grade], button[data-mark]';
        const button = quiz.querySelector(pick) || (quiz.parentElement || quiz).querySelector(pick);
        if (button) { button.click(); marked = true; }
    }
    return {questions: radios > 0, marked};
}, panel);

/* One learner through the whole course. Which option each question's first radio is may be shuffled, so the scores themselves
   are not compared: only what is marked, how far along the course says it is, and what the script shows at each step. */
const walk = async page => {
    const steps = {start: await reading(page)};
    const openPanel = async selector => {
        const head = page.locator(selector + ' > .acc-trigger').first();
        if (!await head.count()) return null;
        await head.click();
        await page.waitForTimeout(40);
        return page.evaluate(name => {
            const node = document.querySelector(name);
            const inner = node.querySelector(':scope > .acc-panel');
            const opened = !!inner && inner.getBoundingClientRect().height > 0;
            node.querySelectorAll(':scope .sub > .acc-trigger').forEach(trigger => trigger.click());
            return opened;
        }, selector);
    };
    if (await page.locator('.diag').count()) { const opened = await openPanel('.diag'); await page.waitForTimeout(30); steps.diagnostic = {opened, ...await answer(page, '.diag')}; }
    steps.modules = [];
    for (const id of await page.$$eval('.module', nodes => nodes.map(node => node.id))) {
        const opened = await openPanel('#' + id);
        await page.waitForTimeout(30);
        const answered = await answer(page, '#' + id);
        await page.waitForTimeout(30);
        const hollow = await page.evaluate(name => [...document.querySelectorAll(name + ' .sub, ' + name + ' .block')].filter(panel => { const inner = panel.querySelector('.sub-inner, .block-inner'); return inner && !inner.textContent.trim() && !inner.querySelector('svg, img, iframe'); }).length, '#' + id);
        steps.modules.push({id, opened, hollow, ...answered, ...await reading(page)});
        await page.evaluate(name => document.querySelector(name + ' > .acc-trigger').click(), '#' + id);
    }
    if (await page.locator('#mod-review').count()) {
        const opened = await openPanel('#mod-review');
        await page.waitForTimeout(30);
        const answered = await answer(page, '#mod-review');
        await page.waitForTimeout(30);
        steps.review = {opened, ...answered, cards: await page.evaluate(() => (document.getElementById('reviewCards') || {children: []}).children.length), ...await reading(page)};
    }
    if (await page.locator('#final').count()) {
        const opened = await openPanel('#final');
        await page.waitForTimeout(30);
        const answered = await answer(page, '#final');
        await page.waitForTimeout(60);
        steps.final = {opened, ...answered, ...await reading(page)};
    }
    await page.reload({waitUntil: 'load'});
    await page.waitForTimeout(80);
    steps.reloaded = await reading(page);
    page.on('dialog', dialog => dialog.accept().catch(() => {}));
    const reset = page.locator('#resetBtn, #resetcourse, .reset-link').first();
    if (await reset.count()) {
        await reset.click();
        await page.waitForTimeout(150);
        steps.reset = await reading(page);
    }
    return steps;
};

/* What a slide or flash card holds that never reaches the screen. A slide's marks that are not words are left out. */
const unshown = page => page.evaluate(() => {
    const flat = text => String(text).replace(/<[^>]+>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' ').replace(/\s+/g, '').toLowerCase();
    const strings = (value, name, found) => {
        if (typeof value === 'string') found.push([name, value]);
        else if (Array.isArray(value)) value.forEach(entry => strings(entry, name, found));
        else if (value && typeof value === 'object') Object.entries(value).forEach(([key, entry]) => strings(entry, name + '.' + key, found));
        return found;
    };
    const decks = typeof PRESENTATIONS === 'undefined' ? {} : PRESENTATIONS, sets = typeof FLASHCARDS === 'undefined' ? {} : FLASHCARDS;
    const missing = [];
    let slides = 0, cards = 0;
    for (const box of document.querySelectorAll('.presentation[data-deck]')) {
        const deck = decks[box.getAttribute('data-deck')];
        if (!deck) { missing.push('no presentation for ' + box.getAttribute('data-deck')); continue; }
        for (const slide of deck.slides) {
            slides++;
            box.querySelectorAll('.slide button').forEach(button => button.click());
            const seen = flat(box.innerText || box.textContent);
            for (const [name, words] of strings(slide, '', [])) {
                if (/^\.(id|layout|narration|title|alt|picture|theme|tone|answer|ordered|build|actions)(\.|$)/.test(name) || !words.trim()) continue;
                if (!seen.includes(flat(words))) missing.push(slide.layout + name + ': ' + words.slice(0, 50));
            }
            const next = [...box.querySelectorAll('.slide-controls button')].find(button => /next/i.test(button.textContent));
            if (next && !next.disabled) next.click();
        }
    }
    for (const box of document.querySelectorAll('.flashcards[data-cards]')) {
        for (const card of sets[box.getAttribute('data-cards')] || []) {
            cards++;
            const turn = [...box.querySelectorAll('button')].find(button => /turn/i.test(button.textContent));
            if (turn) turn.click();
            const seen = flat(box.innerText || box.textContent);
            for (const side of ['front', 'back', 'hint']) if (card[side] && !seen.includes(flat(card[side]))) missing.push('card ' + side + ': ' + card[side].slice(0, 50));
            const next = [...box.querySelectorAll('button')].find(button => /next/i.test(button.textContent));
            if (next) next.click();
        }
    }
    return {slides, cards, missing};
});

(async () => {
    const located = name => path.isAbsolute(name) ? name : path.join(path.dirname(listFile), name);
    for (const engine of engines) {
        const browser = await pw[engine].launch();
        for (const pair of pairs) {
            console.log(engine + ': ' + pair.name);
            const before = await open(browser, located(pair.original));
            const after = await open(browser, located(pair.file));
            // Its CSS tab may hold one more, a fonts link the import turned into an @import.
            const rulesBefore = await ruleCount(before.page), rulesAfter = await ruleCount(after.page);
            const allow = pair.allow || {};
            check(rulesAfter + (allow.rules || 0) >= rulesBefore, 'stylesheet rules accepted: the original ' + rulesBefore + ', the file ' + rulesAfter);
            const builtBefore = await build(before.page), builtAfter = await build(after.page);
            for (const part of Object.keys(builtBefore)) check(builtAfter[part] + (allow[part] || 0) >= builtBefore[part] && (builtBefore[part] > 0 || builtAfter[part] === 0), 'the build\'s ' + part + ': the original ' + builtBefore[part] + ', the file ' + builtAfter[part]);
            const panels = await after.page.evaluate(() => ({buttons: document.querySelectorAll('button.acc-trigger').length, details: document.querySelectorAll('details.acc-item').length, loose: [...document.querySelectorAll('.acc-item')].filter(node => node.tagName !== 'DETAILS').length}));
            check(panels.buttons === 0 && panels.loose === 0 && panels.details > 0, 'every panel a <details>: ' + JSON.stringify(panels));
            const cover = await after.page.evaluate(() => { const drawing = document.querySelector('.course-cover svg'); return drawing ? drawing.getBoundingClientRect().height : null; });
            if (cover !== null) check(cover > 100, 'the cover shows: ' + cover + 'px high');
            const closedBefore = await look(before.page), closedAfter = await look(after.page);
            for (const name of LOOK) if (closedBefore[name]) same(closedBefore[name], closedAfter[name], 'the look of ' + name + ', panels closed');
            for (const opened of [before, after]) {
                await opened.page.evaluate(() => { const module = document.querySelector('.module > .acc-trigger'); if (module) module.click(); document.querySelectorAll('.module .sub > .acc-trigger').forEach(trigger => trigger.click()); });
                await opened.page.waitForTimeout(60);
            }
            const openBefore = await look(before.page), openAfter = await look(after.page);
            for (const name of LOOK) if (openBefore[name]) same(openBefore[name], openAfter[name], 'the look of ' + name + ', panels open');
            // A panel the original never had looks like its neighbour.
            const alike = await after.page.evaluate(() => {
                const paint = node => { if (!node) return null; const style = getComputedStyle(node); return [style.backgroundColor, style.color, style.borderRadius, style.fontFamily, style.fontWeight]; };
                const summary = document.querySelector('.s-summary');
                return !summary ? [] : [...document.querySelectorAll('.s-present[data-course-part], .s-cards[data-course-part]')].map(panel => [paint(panel), paint(panel.querySelector('.acc-trigger')), paint(panel.querySelector('.sub-ico'))].join('|') === [paint(summary), paint(summary.querySelector('.acc-trigger')), paint(summary.querySelector('.sub-ico'))].join('|'));
            });
            check(alike.every(Boolean), 'a new panel looks like the Summary panel: ' + alike.filter(Boolean).length + ' of ' + alike.length);
            await before.context.close();
            await after.context.close();

            const learnerBefore = await open(browser, located(pair.original));
            const learnerAfter = await open(browser, located(pair.file));
            const walkBefore = await walk(learnerBefore.page), walkAfter = await walk(learnerAfter.page);
            for (const step of Object.keys(walkBefore)) same(walkBefore[step], walkAfter[step], 'the learner at ' + step);
            check(learnerAfter.errors.length === 0, 'no script errors: ' + learnerAfter.errors.slice(0, 2).join(' / '));
            await learnerBefore.context.close();
            await learnerAfter.context.close();

            const played = await open(browser, located(pair.file));
            const parts = await unshown(played.page);
            check(parts.missing.length === 0, parts.missing.length + ' words never shown, first: ' + parts.missing.slice(0, 3).join(' | '));
            check(played.errors.length === 0, 'no script errors playing the parts: ' + played.errors.slice(0, 2).join(' / '));
            await played.context.close();

            const bare = await open(browser, located(pair.file), false);
            const module = bare.page.locator('.module').first();
            const head = module.locator('> summary');
            check(await head.count() === 1, 'without JavaScript: the first module opens from its summary');
            if (await head.count()) {
                await head.click();
                check(await module.locator('.module-body').isVisible(), 'without JavaScript: the module opens');
                const subs = module.locator('.sub > summary');
                for (let index = 0; index < await subs.count(); index++) {
                    await subs.nth(index).click();
                    check(await module.locator('.sub').nth(index).locator('.sub-inner').first().isVisible(), 'without JavaScript: panel ' + (index + 1) + ' opens');
                }
                await head.click();
                check(!await module.locator('.module-body').isVisible(), 'without JavaScript: the module closes');
            }
            await bare.context.close();
        }
        await browser.close();
    }
    console.log(checks + ' checks, ' + failures + ' failures.');
    process.exit(failures === 0 ? 0 : 1);
})().catch(error => { console.error(error); process.exit(1); });
