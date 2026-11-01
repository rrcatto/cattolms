/* A course page drawn small (owner, 2026-11-01, the accepted Course Page Layouts mockup): the sketch the server drew,
 * one cell per block (data-block, data-width), redrawn for a layout and for what a course has. A cell is drawn when its
 * block is shown in the layout and has something to show on the course; the blocks a layout shows that the course
 * leaves out are listed with why, each line cloned from the list's own <template> (data-reason on the cells says why).
 * No markup is made here: the cells are the server's, moved and hidden. */

// layout: [{id, width, shown}], present: the block ids with something to show ([] when every block has).
export function drawSketch(sketch, layout, present) {
    const cells = new Map([...sketch.querySelectorAll('[data-block]')].map((cell) => [cell.dataset.block, cell]));
    for (const block of layout) {
        const cell = cells.get(block.id);
        if (!cell) continue;
        cell.dataset.width = block.width;
        cell.hidden = !block.shown || !has(present, block.id);
        sketch.append(cell);
    }
}

// The blocks shown in the layout that the course leaves out, as lines of the list; the box hides when there are none.
export function listLeftOut(box, sketch, layout, present) {
    if (!box) return;
    const list = box.querySelector('ul');
    const line = box.querySelector('template');
    if (!list || !line) return;
    const cells = new Map([...sketch.querySelectorAll('[data-block]')].map((cell) => [cell.dataset.block, cell]));
    list.replaceChildren();
    for (const block of layout) {
        if (!block.shown || has(present, block.id)) continue;
        const cell = cells.get(block.id);
        const item = line.content.firstElementChild.cloneNode(true);
        item.textContent = `${cell ? cell.textContent.trim() : block.id} (${cell?.dataset.reason || 'nothing to show'})`;
        list.append(item);
    }
    box.hidden = list.children.length === 0;
}

export function presentFrom(text) { return text ? text.split(',').filter(Boolean) : []; }

function has(present, id) { return present.length === 0 || present.includes(id); }
