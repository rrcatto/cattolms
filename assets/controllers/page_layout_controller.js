/* The Page Builder for course pages (owner, 2026-10-30, the accepted mockup): partials/admin/page-layout-builder.html.twig.
 *
 * Without JavaScript every button sends the form and the server saves what it asks. Here the same buttons change the
 * page in place: a block dragged by its handle or moved with its arrows, half or full width, hidden to the shelf or
 * shown again, a template applied, Reset; the sketch and the templates' "In use" follow. The layout is kept as a list of
 * {id, width, shown}, and drawing it only moves the blocks the server drew and sets their fields, so the form sends
 * exactly what is shown when Save layout is pressed. On a course using the site's default, the board shows that
 * default and stays locked until A layout of its own is chosen. No markup is made here. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['order', 'board', 'shelf', 'empty', 'tile', 'template', 'sketch', 'lockable'];
    static values = { templates: Object, default: String, locked: Boolean };

    connect() {
        this.tiles = new Map(this.tileTargets.map((tile) => [tile.dataset.block, tile]));
        this.layout = this.orderTarget.value.split(',').filter((id) => this.tiles.has(id)).map((id) => this.read(id));
        this.onChange = (event) => this.changed(event);
        this.element.addEventListener('change', this.onChange);
        this.draw(false);
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
    }

    read(id) {
        const tile = this.tiles.get(id);
        const width = tile.querySelector('input[type="radio"]:checked');
        return { id, width: width ? width.value : 'full', shown: tile.querySelector('input[name^="shown["]').value === '1' };
    }

    copy(layout) { return layout.map((block) => ({ id: block.id, width: block.width, shown: block.shown })); }

    siteDefault() { return this.defaultValue === '' ? this.copy(this.templatesValue.course) : this.copy(JSON.parse(this.defaultValue)); }

    block(id) { return this.layout.find((block) => block.id === id); }

    // A width chosen, or which layout the course uses.
    changed(event) {
        const field = event.target;
        if (!(field instanceof HTMLInputElement) || field.type !== 'radio') return;
        if (field.name === 'source') {
            this.lockedValue = field.value === 'default';
            if (this.lockedValue) this.layout = this.siteDefault();
            this.draw(true);
            return;
        }
        const match = field.name.match(/^width\[([a-z]+)\]$/);
        const block = match ? this.block(match[1]) : null;
        if (block) { block.width = field.value; this.draw(false); }
    }

    template(event) {
        event.preventDefault();
        if (this.lockedValue) return;
        const key = event.currentTarget.value.split(':')[1];
        if (!this.templatesValue[key]) return;
        this.layout = this.copy(this.templatesValue[key]);
        this.draw(true);
    }

    move(event) {
        event.preventDefault();
        if (this.lockedValue) return;
        const button = event.currentTarget;
        const [, id, direction] = button.value.split(':');
        const at = this.layout.findIndex((block) => block.id === id);
        const step = direction === 'up' ? -1 : 1;
        for (let to = at + step; to >= 0 && to < this.layout.length; to += step) {
            if (!this.layout[to].shown) continue;
            this.place(at, to);
            break;
        }
        this.draw(true);
        (button.disabled ? this.tiles.get(id).querySelector('button:not(:disabled)') : button)?.focus();
    }

    hide(event) { event.preventDefault(); this.shown(event.currentTarget.value.split(':')[1], false); }

    show(event) { event.preventDefault(); this.shown(event.currentTarget.value.split(':')[1], true); }

    shown(id, shown) {
        const block = this.block(id);
        if (this.lockedValue || !block) return;
        block.shown = shown;
        this.draw(true);
        this.tiles.get(id).querySelector(shown ? '.cl-page-builder-hide button' : '.cl-page-builder-show button')?.focus();
    }

    // A course goes back to the site's default; the site's default goes back to Course first.
    reset(event) {
        event.preventDefault();
        if (this.lockedValue) return;
        this.layout = this.defaultValue === '' ? this.copy(this.templatesValue.course) : this.siteDefault();
        const source = this.element.querySelector('input[name="source"][value="default"]');
        if (source) { source.checked = true; this.lockedValue = true; }
        this.draw(true);
    }

    place(from, to) {
        const [block] = this.layout.splice(from, 1);
        this.layout.splice(to, 0, block);
    }

    // Dragging starts only from a block's handle, so its buttons and widths stay clickable.
    grab(event) {
        if (this.lockedValue) return;
        const tile = event.currentTarget.closest('[data-block]');
        tile.draggable = true;
        window.addEventListener('pointerup', () => { if (this.dragging !== tile.dataset.block) tile.draggable = false; }, { once: true });
    }

    dragStart(event) {
        const tile = event.currentTarget;
        if (!tile.draggable || this.lockedValue) return;
        this.dragging = tile.dataset.block;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragging);
        // After the browser has taken its drag image of the block: changing it now would end the drag.
        window.setTimeout(() => { tile.dataset.dragging = 'true'; }, 0);
    }

    dragOver(event) {
        const tile = event.currentTarget;
        if (!this.dragging || tile.parentElement !== this.boardTarget) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        this.tileTargets.forEach((other) => other.toggleAttribute('data-drop', other === tile && tile.dataset.block !== this.dragging));
    }

    drop(event) {
        const tile = event.currentTarget;
        if (!this.dragging || tile.parentElement !== this.boardTarget) return;
        event.preventDefault();
        const from = this.layout.findIndex((block) => block.id === this.dragging);
        const to = this.layout.findIndex((block) => block.id === tile.dataset.block);
        if (from !== to) { this.place(from, to); this.draw(true); }
    }

    dragEnd() {
        this.tileTargets.forEach((tile) => { tile.draggable = false; delete tile.dataset.dragging; tile.removeAttribute('data-drop'); });
        this.dragging = null;
    }

    same(template) {
        return template.length === this.layout.length && template.every((block, i) => block.id === this.layout[i].id && block.width === this.layout[i].width && block.shown === this.layout[i].shown);
    }

    // The board, the shelf, the sketch and the templates drawn from the layout; changed says the form now differs.
    draw(changed) {
        const locked = this.lockedValue;
        const cells = new Map([...this.sketchTarget.querySelectorAll('[data-block]')].map((cell) => [cell.dataset.block, cell]));
        for (const block of this.layout) {
            const tile = this.tiles.get(block.id);
            (block.shown ? this.boardTarget : this.shelfTarget).append(tile);
            tile.dataset.width = block.width;
            tile.querySelectorAll('input[type="radio"]').forEach((radio) => { radio.checked = radio.value === block.width; });
            tile.querySelector('input[name^="shown["]').value = block.shown ? '1' : '0';
            const cell = cells.get(block.id);
            cell.dataset.width = block.width;
            cell.hidden = !block.shown;
            this.sketchTarget.append(cell);
        }
        this.lockableTargets.forEach((part) => {
            part.toggleAttribute('data-locked', locked);
            part.querySelectorAll('button, input[type="radio"]').forEach((control) => { control.disabled = locked; });
        });
        const shown = this.layout.filter((block) => block.shown);
        shown.forEach((block, i) => {
            const [up, down] = this.tiles.get(block.id).querySelectorAll('.cl-page-builder-arrows button');
            up.disabled = locked || i === 0;
            down.disabled = locked || i === shown.length - 1;
        });
        this.emptyTarget.hidden = shown.length !== this.layout.length;
        this.templateTargets.forEach((card) => { card.dataset.current = String(this.same(this.templatesValue[card.dataset.template] || [])); });
        this.orderTarget.value = this.layout.map((block) => block.id).join(',');
        if (changed) this.orderTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
