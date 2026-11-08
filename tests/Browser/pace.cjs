/* How the browser checks wait for a page and run several at once (owner, 2026-11-07: "you must figure out how to do the
   tests in minutes, not hours!!").
   - settle(page): the page as a reader sees it once it has loaded: its fonts in, then two animation frames, so the
     scripts that fit tables, figures and pagination to the page have run. Every page is drawn by the server, so
     nothing a check reads arrives later; "networkidle" waited half a second of quiet on every page for nothing. A
     check that waits for an htmx request it started still waits for that request itself.
   - open(page, url, options): goes to the address and settles, answering what page.goto answers.
   - within(ms, words, work): work, or an error in those words once ms have passed.
   - pool(items, size, work): does work(item, worker) for every item, at most size at a time, each worker number from 0
     to size - 1 its own, so a worker can keep its own browser context. Results come back in the items' order.
   CATTO_PAGES sets how many pages a crawl opens at once (5 when unset). */
const PAGES = Math.max(1, Number(process.env.CATTO_PAGES || 5));

async function settle(page, limit = 5000) {
    // At most a few seconds: a web font that never finishes, or a page that never draws a frame, must not stop a check.
    let timer;
    const late = new Promise((done) => { timer = setTimeout(done, limit); });
    await Promise.race([page.evaluate(() => document.fonts.ready.then(() => new Promise((done) => requestAnimationFrame(() => requestAnimationFrame(done))))).catch(() => {}), late]);
    clearTimeout(timer);
}

/** Runs work, or fails it with the given words once ms have passed, so one stuck page fails its own check, not the run. */
async function within(ms, words, work) {
    let timer;
    const late = new Promise((_, fail) => { timer = setTimeout(() => fail(new Error(words)), ms); });
    try { return await Promise.race([work(), late]); } finally { clearTimeout(timer); }
}

async function open(page, url, options = {}) {
    // A minute and a half unless the check says otherwise: a ceiling for a page that is stuck, never a wait, since with
    // the gate's checks side by side a page can take many times as long as it does alone.
    const response = await page.goto(url, {waitUntil: 'load', timeout: 90000, ...options});
    await settle(page);
    return response;
}

async function pool(items, size, work) {
    const results = new Array(items.length);
    let next = 0;
    const worker = async (number) => {
        while (next < items.length) {
            const at = next++;
            results[at] = await work(items[at], number);
        }
    };
    await Promise.all(Array.from({length: Math.max(1, Math.min(size, items.length))}, (_, number) => worker(number)));
    return results;
}

module.exports = {PAGES, settle, open, pool, within};
