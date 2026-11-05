/* Every word on every slide of every presentation can be read (owner, 2026-11-02: "check every single presentation to
   ensure the contrast is not borked on any page and that every single slide is readable"; "You have ways of checking this
   before you publish"). Each presentation is opened where it is read and played, in Chromium at 1440, every slide built to
   its last step and every quick question also answered, wrongly, so the marks and the explanation show. For each piece
   of text, the text inside a drawing included, it measures:
   - contrast: the text's colour against the real pixels behind it, from a picture of the slide with the text hidden, at
     WCAG AA, 4.5:1, or 3:1 for large text (24px, or 18.66px bold);
   - invisible: the text changes nothing on the screen, because something covers it or it is the colour of what is behind it;
   - small: under 10px as drawn, the usual trouble with text in a drawing that has been shrunk;
   - cut off: past the side of the slide, or past the edge of its drawing;
   - overlap: two pieces of text in one drawing on top of each other;
   - past its frame: at this width, text below the bottom of the slide, which shrinks to fit before it scrolls;
   - out of view: a step or an answer the player has just shown lies below the frame;
   - unmeasured: a slide that scrolled, with text no pass of the check reached.
   A slide that still scrolls is measured all the way down, a screenful at a time.
   The fixture's own course draws every layout on every background in both looks, so what an author could choose is
   checked as well as what has been written, in every bundled theme at 1440 and 390 and without JavaScript. A disabled
   button is left out, being meant to look disabled.
   Fixture: php tests/Browser/presentation-readable-fixture.php create|cleanup (in env_php_1), the state copied to the host.
   The front page's link to the presentation that introduces the site is followed in every bundled theme, on ordinary desktop
   and laptop screens: the whole player must be in view, its buttons included, with nothing scrolling sideways.
   CATTO_READABLE_REPORT names a file for the findings as JSON; CATTO_READABLE_ONLY=own runs only the fixture's course,
   CATTO_READABLE_ONLY=site only the site's own, CATTO_READABLE_ONLY=front only the front page's link, and
   CATTO_READABLE_CODES=A-101,B-202 only the presentations of the courses with those codes, after changing them. */
const {chromium} = require(process.env.CATTO_PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const zlib = require('node:zlib');
const base = process.env.CATTO_BASE_URL || 'https://catto.test';
const state = JSON.parse(fs.readFileSync(process.env.CATTO_BROWSER_STATE || '/tmp/catto-presentation-readable-state.json'));
const only = process.env.CATTO_READABLE_ONLY || '';
const codes = (process.env.CATTO_READABLE_CODES || '').split(',').filter(Boolean);
const MIN_PX = 10;
const findings = [];
const scrolling = [];
let slidesSeen = 0, textsSeen = 0, decksSeen = 0;

/* A PNG as Chromium writes it: 8 bits, not interlaced. */
function decodePng(buffer) {
    let at = 8, width = 0, height = 0, channels = 4;
    const data = [];
    while (at < buffer.length) {
        const length = buffer.readUInt32BE(at), type = buffer.toString('latin1', at + 4, at + 8);
        if (type === 'IHDR') { width = buffer.readUInt32BE(at + 8); height = buffer.readUInt32BE(at + 12); channels = {0: 1, 2: 3, 4: 2, 6: 4}[buffer[at + 17]]; }
        if (type === 'IDAT') data.push(buffer.subarray(at + 8, at + 8 + length));
        at += 12 + length;
    }
    const raw = zlib.inflateSync(Buffer.concat(data));
    const stride = width * channels, out = Buffer.alloc(height * stride);
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)], from = y * (stride + 1) + 1, row = y * stride;
        for (let x = 0; x < stride; x++) {
            const value = raw[from + x];
            const left = x >= channels ? out[row + x - channels] : 0, up = y ? out[row - stride + x] : 0, corner = x >= channels && y ? out[row - stride + x - channels] : 0;
            let add = 0;
            if (filter === 1) add = left; else if (filter === 2) add = up; else if (filter === 3) add = (left + up) >> 1;
            else if (filter === 4) { const p = left + up - corner, pa = Math.abs(p - left), pb = Math.abs(p - up), pc = Math.abs(p - corner); add = pa <= pb && pa <= pc ? left : pb <= pc ? up : corner; }
            out[row + x] = (value + add) & 255;
        }
    }
    return {width, height, channels, pixels: out};
}
const linear = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
const luminance = (r, g, b) => 0.2126 * linear(r) + 0.7152 * linear(g) + 0.0722 * linear(b);
const ratio = (a, b) => (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
const hex = (c) => '#' + c.slice(0, 3).map((v) => Math.round(v).toString(16).padStart(2, '0')).join('');

/* In the page: every piece of text showing in a root, with its colour, size and boxes in page coordinates. */
function collect(rootSelector) {
    const paint = document.createElement('canvas').getContext('2d', {willReadFrequently: true});
    const rgba = (css) => {
        if (!css || css === 'none' || css.startsWith('url')) return null;
        paint.clearRect(0, 0, 1, 1); paint.fillStyle = '#000'; paint.fillStyle = css; paint.fillRect(0, 0, 1, 1);
        return [...paint.getImageData(0, 0, 1, 1).data];
    };
    const sx = window.scrollX, sy = window.scrollY;
    const box = (r) => ({left: r.left + sx, top: r.top + sy, right: r.right + sx, bottom: r.bottom + sy});
    const out = [];
    let index = 0;
    for (const root of document.querySelectorAll(rootSelector)) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            const text = node.nodeValue.replace(/\s+/g, ' ').trim();
            const el = node.parentElement;
            if (!text || !el || el.closest('style, script, title, desc, defs, clipPath, mask, symbol, metadata, [hidden], .cw-live, button[disabled]')) continue;
            const cs = getComputedStyle(el);
            if (cs.visibility !== 'visible' || cs.display === 'none') continue;
            const svg = el.closest('svg');
            let opacity = 1;
            for (let up = el; up && up !== document.body; up = up.parentElement) { const o = getComputedStyle(up); if (o.display === 'none' || o.visibility !== 'visible') { opacity = -1; break; } opacity *= Number(o.opacity); }
            if (opacity <= 0) continue;
            const range = document.createRange(); range.selectNodeContents(node);
            let rects = [...range.getClientRects()].filter((r) => r.width > 0.5 && r.height > 0.5);
            if (!rects.length && svg) rects = [el.getBoundingClientRect()].filter((r) => r.width > 0.5 && r.height > 0.5);
            if (!rects.length) continue;
            // As drawn: a drawing's own scale, whichever way its text turns, or the size the player shrank a full slide to.
            const matrix = svg && el.getScreenCTM ? el.getScreenCTM() : null;
            const scale = matrix ? Math.hypot(matrix.a, matrix.b) : (el.currentCSSZoom || 1);
            const slide = el.closest('.cw-slide');
            const frame = slide || root;
            const limit = svg ? svg.getBoundingClientRect() : null;
            out.push({index: index++, text: text.slice(0, 90), inSvg: Boolean(svg), part: el.closest('.cw-bar') ? 'bar' : el.closest('.cw-contents') ? 'contents' : el.closest('.cw-transcript') ? 'transcript' : el.closest('.cw-notes') ? 'notes' : 'slide',
                colour: rgba(svg ? cs.fill : (cs.webkitTextFillColor || cs.color)), alpha: opacity * (svg ? Number(cs.fillOpacity) : 1), px: parseFloat(cs.fontSize) * scale, weight: Number(cs.fontWeight) || 400,
                rects: rects.slice(0, 8).map(box), frame: box(frame.getBoundingClientRect()), limit: limit ? box(limit) : null, slideIndex: slide ? [...slide.parentElement.children].indexOf(slide) : -1});
        }
        // What a list draws for itself: a step's number, and a list's number or bullet.
        for (const li of root.querySelectorAll('.cw-steps li')) {
            const before = getComputedStyle(li, '::before'), r = li.getBoundingClientRect();
            if (getComputedStyle(li).visibility !== 'visible' || !before.content.startsWith('counter')) continue;
            const size = parseFloat(before.width) || 20;
            out.push({index: index++, text: "(a step's number)", inSvg: false, part: 'slide', pseudo: true, colour: rgba(before.color), behind: rgba(before.backgroundColor), alpha: 1, px: parseFloat(before.fontSize), weight: Number(before.fontWeight) || 700, rects: [box({left: r.left, top: r.top, right: r.left + size, bottom: r.top + size})], frame: box((li.closest('.cw-slide') || root).getBoundingClientRect()), limit: null});
        }
        for (const li of root.querySelectorAll('.cw-slide ul li, .cw-slide ol:not(.cw-steps):not(.cw-timeline):not(.cw-choices) li')) {
            const marker = getComputedStyle(li, '::marker'), r = li.getBoundingClientRect();
            if (getComputedStyle(li).visibility !== 'visible' || r.height < 1) continue;
            const em = parseFloat(marker.fontSize) || 16;
            out.push({index: index++, text: "(a list's marker)", inSvg: false, part: 'slide', marker: true, colour: rgba(marker.color), alpha: 1, px: 24, weight: 700, rects: [box({left: r.left - em * 1.2, top: r.top, right: r.left - em * 0.3, bottom: r.top + em})], frame: box((li.closest('.cw-slide') || root).getBoundingClientRect()), limit: null});
        }
    }
    return out;
}

const HIDE = `.cw-deck *:not(svg *), .cw-deck *:not(svg *)::before, .cw-deck *:not(svg *)::marker { -webkit-text-fill-color: transparent !important; text-shadow: none !important; }
.cw-deck .cw-steps li::before { color: transparent !important; }
.cw-deck li::marker { color: transparent !important; }
.cw-deck svg text, .cw-deck svg tspan, .cw-deck svg textPath { fill: transparent !important; stroke: transparent !important; }`;

/* One pass over what shows now: pictures with and without the text, then each text against the pixels behind it. */
async function measure(page, rootSelector, where, area) {
    await page.evaluate(() => window.scrollTo(0, 0));
    const texts = await page.evaluate(collect, rootSelector);
    const clip = await page.evaluate((selector) => { const roots = [...document.querySelectorAll(selector)]; const rs = roots.map((r) => r.getBoundingClientRect()); const left = Math.min(...rs.map((r) => r.left)), top = Math.min(...rs.map((r) => r.top)); return {x: Math.max(0, Math.floor(left + window.scrollX)), y: Math.max(0, Math.floor(top + window.scrollY)), width: Math.ceil(Math.max(...rs.map((r) => r.right)) - left), height: Math.ceil(Math.max(...rs.map((r) => r.bottom)) - top)}; }, rootSelector);
    const shot = async () => decodePng(await page.screenshot({type: 'png', fullPage: true, clip, animations: 'disabled', caret: 'hide', scale: 'css'}));
    const shown = await shot();
    // Added by hand: a style tag's own helper waits for a load event that never comes without JavaScript.
    await page.evaluate((css) => { const style = document.createElement('style'); style.id = 'cw-check-hide'; style.textContent = css; document.head.appendChild(style); }, HIDE);
    const bare = await shot();
    await page.evaluate(() => document.getElementById('cw-check-hide').remove());
    const seenBoxes = [];
    const measured = new Set();
    let past = false;
    for (const item of texts) {
        // Text scrolled out of its frame is not on the screen: another pass reaches it.
        const showing = item.rects.filter((r) => r.top >= item.frame.top - 1 && r.bottom <= item.frame.bottom + 1);
        if (item.rects.some((r) => r.bottom > item.frame.bottom + 1)) past = true;
        if (!showing.length) continue;
        measured.add(item.index);
        textsSeen++;
        const large = item.px >= 24 || (item.px >= 18.66 && item.weight >= 700);
        const need = large ? 3 : 4.5;
        const scores = [];
        let peak = 0, bgSample = null;
        for (const rect of showing) {
            const x0 = Math.max(0, Math.floor(rect.left - clip.x)), x1 = Math.min(bare.width, Math.ceil(rect.right - clip.x));
            const y0 = Math.max(0, Math.floor(rect.top - clip.y)), y1 = Math.min(bare.height, Math.ceil(rect.bottom - clip.y));
            for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) {
                const at = (y * bare.width + x) * bare.channels;
                const bg = [bare.pixels[at], bare.pixels[at + 1], bare.pixels[at + 2]], now = [shown.pixels[at], shown.pixels[at + 1], shown.pixels[at + 2]];
                const behind = item.behind && item.behind[3] > 0 ? item.behind.slice(0, 3) : bg;
                if (!bgSample) bgSample = behind;
                const lumBehind = luminance(...behind);
                if (item.colour) {
                    const a = (item.colour[3] / 255) * item.alpha;
                    const ink = [0, 1, 2].map((i) => item.colour[i] * a + behind[i] * (1 - a));
                    scores.push(ratio(luminance(...ink), lumBehind));
                }
                const seen = ratio(luminance(...now), luminance(...bg));
                if (seen > peak) peak = seen;
            }
        }
        if (!scores.length && item.colour) continue; // Wholly past the picture: another pass reaches it.
        const finding = (kind, extra) => findings.push({...where, kind, part: item.part, text: item.text, px: Math.round(item.px * 10) / 10, ...extra});
        scores.sort((a, b) => a - b);
        const worst = scores.length ? scores[Math.floor(scores.length * 0.05)] : null;
        const colour = item.colour ? hex(item.colour) : 'gradient';
        if (worst !== null && worst < need) finding('contrast', {ratio: Math.round(worst * 100) / 100, need, colour, behind: bgSample ? hex(bgSample) : ''});
        else if (!item.pseudo && !item.marker && peak < 1.3) finding('invisible', {ratio: Math.round(peak * 100) / 100, colour, behind: bgSample ? hex(bgSample) : ''});
        if (!item.marker && item.px < MIN_PX) finding('small', {});
        for (const rect of item.rects) {
            if (rect.right > item.frame.right + 1 || rect.left < item.frame.left - 1) { finding('cut off', {detail: 'past the side of the slide'}); break; }
            if (item.limit && (rect.right > item.limit.right + 1 || rect.left < item.limit.left - 1 || rect.top < item.limit.top - 2 || rect.bottom > item.limit.bottom + 2)) { finding('cut off', {detail: 'past the edge of its drawing'}); break; }
        }
        if (item.inSvg) {
            for (const other of seenBoxes) {
                const r = item.rects[0], width = Math.min(r.right, other.rect.right) - Math.max(r.left, other.rect.left), height = Math.min(r.bottom, other.rect.bottom) - Math.max(r.top, other.rect.top);
                const smaller = Math.min((r.right - r.left) * (r.bottom - r.top), (other.rect.right - other.rect.left) * (other.rect.bottom - other.rect.top));
                if (width > 0 && height > 0 && width * height > smaller * 0.3 && other.text !== item.text) { finding('overlap', {detail: 'over "' + other.text + '"'}); break; }
            }
            seenBoxes.push({rect: item.rects[0], text: item.text});
        }
    }
    return {total: texts.length, measured, past};
}

/* Plays a presentation through in its player, measuring each slide with every step shown and each question answered. */
async function play(page, deck, label, width = 1440) {
    const current = '.cw-deck.cw-ready .cw-slide.cw-current';
    const next = page.locator('.cw-deck.cw-ready .cw-btn-primary').first();
    const total = await page.locator('.cw-deck.cw-ready .cw-slide').count();
    decksSeen++;
    for (let n = 1; n <= total; n++) {
        for (let press = 0; press < 12 && await page.locator(`${current} .cw-step-hidden`).count(); press++) { await next.click(); await page.waitForTimeout(30); }
        const about = await page.locator(current).evaluate((s) => ({slide_title: s.dataset.cwTitle || '', layout: s.dataset.cwLayout, tone: s.dataset.cwTone || 'base', over: s.scrollHeight - s.clientHeight}));
        const where = {at: label, code: deck.code || '', course: deck.course, deck: deck.title, item: Number(deck.item), look: deck.look, slide: n, ...about};
        slidesSeen++;
        // A slide that scrolls is measured a screenful at a time, from its top.
        const screens = async (stateName) => {
            const reached = new Set();
            let last = {total: 0, past: false}, first = null;
            for (let top = 0, turn = 0; turn < 12; turn++) {
                const size = await page.locator(current).evaluate((s, y) => { s.scrollTop = y; return {client: s.clientHeight, scroll: s.scrollHeight}; }, top);
                last = await measure(page, current, {...where, state: stateName + (top ? ', scrolled' : '')});
                if (first === null) first = last.past;
                last.measured.forEach((m) => reached.add(m));
                if (top + size.client >= size.scroll) break;
                top += Math.max(120, size.client - 60);
            }
            await page.locator(current).evaluate((s) => { s.scrollTop = 0; });
            if (reached.size < last.total) findings.push({...where, state: stateName, kind: 'unmeasured', part: 'slide', text: `${last.total - reached.size} of ${last.total} pieces of text`});
            return first;
        };
        const below = async (selector) => page.locator(current).evaluate((s, part) => { const all = s.querySelectorAll(part), box = all[all.length - 1]; return box ? box.getBoundingClientRect().bottom > s.getBoundingClientRect().bottom + 1 : false; }, selector);
        if (await below('.cw-step')) findings.push({...where, state: 'shown', kind: 'out of view', part: 'slide', text: 'the last step'});
        if (await screens('shown') && width >= 1000) findings.push({...where, state: 'shown', kind: 'past its frame', part: 'slide', text: `${about.over}px of the slide`});
        if (about.over > 1) scrolling.push({...where, over: about.over});
        const wrong = page.locator(`${current} .cw-choices li`);
        if (await wrong.count()) {
            const answer = await page.locator(`${current} .cw-choices`).getAttribute('data-cw-answer');
            await wrong.nth(Number(answer) === 0 ? 1 : 0).locator('.cw-choice').click();
            await page.waitForTimeout(30);
            if (await below('.cw-explain')) findings.push({...where, state: 'answered', kind: 'out of view', part: 'slide', text: 'the explanation'});
            await screens('answered');
        }
        if (n < total) { await next.click(); await page.waitForFunction((s) => document.querySelector(s) !== null, current); await page.waitForTimeout(30); }
    }
}

/* The player's own bars and panels, once for each presentation's look. */
async function chrome(page, deck, label) {
    const where = {at: label, code: deck.code || '', course: deck.course, deck: deck.title, item: Number(deck.item), look: deck.look, slide: 0, state: 'the player'};
    await measure(page, '.cw-deck.cw-ready .cw-bar', where);
    const transcript = page.locator('.cw-deck.cw-ready .cw-btn', {hasText: 'Transcript'}).first();
    if (await transcript.count()) {
        await transcript.click(); await page.waitForTimeout(30);
        if (await page.locator('.cw-deck.cw-ready .cw-transcript').count() && await page.locator('.cw-deck.cw-ready .cw-transcript').first().isVisible()) await measure(page, '.cw-deck.cw-ready .cw-transcript', {...where, state: 'the transcript'});
        await transcript.click();
    }
    const contents = page.locator('.cw-deck.cw-ready .cw-btn', {hasText: 'Contents'}).first();
    if (await contents.count()) {
        await contents.click(); await page.waitForTimeout(30);
        if (await page.locator('.cw-deck.cw-ready .cw-contents').first().isVisible().catch(() => false)) await measure(page, '.cw-deck.cw-ready .cw-contents', {...where, state: 'the contents list'});
        await contents.click();
    }
}

async function openDeck(page, url) {
    const response = await page.goto(base + url, {waitUntil: 'load'});
    if (response.status() !== 200) throw new Error(`${url} answered ${response.status()}`);
    await page.waitForSelector('.cw-deck.cw-ready');
    await page.evaluate(() => document.fonts.ready);
}

(async () => {
    const browser = await chromium.launch();
    const session = async (options = {}) => {
        const context = await browser.newContext({ignoreHTTPSErrors: true, viewport: {width: 1440, height: 1100}, reducedMotion: 'reduce', ...options});
        await context.addCookies([{name: 'catto_learning_session', value: state.token, url: base, secure: true}]);
        const page = await context.newPage();
        page.setDefaultTimeout(20000);
        return page;
    };
    const failedToOpen = [];
    if (codes.length === 0 && state.decks.some((d) => d.kind === 'site') && (only === '' || only === 'front')) {
        for (const theme of state.themes) {
            for (const [width, height] of [[1440, 900], [1366, 768], [1920, 1080], [390, 844]]) {
                const page = await session({viewport: {width, height}});
                const where = {at: `${theme} ${width}x${height}`, deck: 'the front page\'s link', course: 'the site', item: 0, look: '', slide: 0, state: 'followed'};
                await page.goto(`${base}/?theme_preview=${theme}`, {waitUntil: 'load'});
                const link = page.locator('a[href="/presentation#presentation"]').first();
                if (!await link.count()) { findings.push({...where, kind: 'no link', part: 'page', text: 'the front page has no link to the presentation'}); await page.context().close(); continue; }
                await link.click();
                await page.waitForSelector('.cw-deck.cw-ready');
                await page.waitForTimeout(150);
                const seen = await page.evaluate(() => { const deck = document.querySelector('.cw-deck').getBoundingClientRect(), next = document.querySelector('.cw-btn-primary').getBoundingClientRect(); return {top: deck.top, bottom: deck.bottom, next: next.bottom, high: window.innerHeight, sideways: document.documentElement.scrollWidth - window.innerWidth}; });
                if (seen.sideways > 1) findings.push({...where, kind: 'cut off', part: 'page', text: `the page scrolls sideways by ${seen.sideways}px`});
                // On a phone the player is taller than most screens, so there only its top must show.
                if (width >= 1000 ? (seen.top < 0 || seen.bottom > seen.high + 1) : (seen.top < 0 || seen.top > seen.high / 2)) findings.push({...where, kind: 'out of view', part: 'page', text: `the player runs from ${Math.round(seen.top)} to ${Math.round(seen.bottom)} on a screen ${seen.high} high`});
                await page.context().close();
            }
        }
        console.log('  the front page\'s link followed in every theme');
    }
    if (only !== 'own' && only !== 'front') {
        const page = await session();
        for (const deck of state.decks.filter((d) => !d.own && (codes.length === 0 || codes.includes(d.code)))) {
            try {
                await openDeck(page, deck.url);
                await chrome(page, deck, 'the reader');
                await play(page, deck, 'the reader');
            } catch (error) { failedToOpen.push(`${deck.code} ${deck.title} (item ${deck.item}): ${error.message.split('\n')[0]}`); }
            if (decksSeen % 25 === 0) console.log(`  ${decksSeen} presentations, ${slidesSeen} slides, ${textsSeen} pieces of text, ${findings.length} findings`);
        }
        await page.context().close();
    }
    if (codes.length === 0 && only !== 'site' && only !== 'front') {
        for (const deck of state.decks.filter((d) => d.own)) {
            for (const theme of state.themes) {
                for (const width of [1440, 390]) {
                    const page = await session({viewport: {width, height: 1100}});
                    const label = `${theme} ${width}`;
                    await openDeck(page, deck.url + (deck.url.includes('?') ? '&' : '?') + 'theme_preview=' + theme);
                    if (width === 1440) await chrome(page, deck, label);
                    await play(page, deck, label, width);
                    await page.context().close();
                }
                // Without JavaScript every slide shows in order, with its notes, as a page.
                const plain = await session({javaScriptEnabled: false});
                await plain.goto(base + deck.url + (deck.url.includes('?') ? '&' : '?') + 'theme_preview=' + theme, {waitUntil: 'load'});
                await measure(plain, '.cw-deck', {at: `${theme} without JavaScript`, course: deck.course, deck: deck.title, item: Number(deck.item), look: deck.look, slide: 0, state: 'as a page'});
                await plain.context().close();
            }
        }
    }
    await browser.close();

    const failing = findings;
    const byKind = {};
    for (const f of failing) byKind[f.kind] = (byKind[f.kind] || 0) + 1;
    console.log(`${decksSeen} presentations, ${slidesSeen} slides, ${textsSeen} pieces of text measured.`);
    console.log(`Findings: ${JSON.stringify(byKind)}. Slides taller than their frame (they scroll): ${scrolling.length}.`);
    for (const failure of failedToOpen) console.log(`FAIL could not be played: ${failure}`);
    const seen = new Set();
    for (const f of failing) {
        const key = [f.kind, f.at, f.item, f.slide, f.state, f.text].join('|');
        if (seen.has(key) || seen.size > 60) continue;
        seen.add(key);
        console.log(`FAIL ${f.kind}: ${f.code || ''} ${f.deck} slide ${f.slide} (${f.layout || ''}, ${f.tone || ''}, ${f.look}, ${f.at}${f.state ? ', ' + f.state : ''}): "${f.text}" ${f.ratio ? f.ratio + ':1, needs ' + (f.need || '') + ', ' + f.colour + ' on ' + f.behind : ''}${f.detail || ''}${f.kind === 'small' ? f.px + 'px' : ''}`);
    }
    if (process.env.CATTO_READABLE_REPORT) fs.writeFileSync(process.env.CATTO_READABLE_REPORT, JSON.stringify({findings, scrolling, failedToOpen, decksSeen, slidesSeen, textsSeen}, null, 1));
    process.exit(failing.length || failedToOpen.length ? 1 : 0);
})();
