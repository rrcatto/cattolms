/* A list put in order by dragging, on Administration > Courses (owner, 2026-10-20: "a drag handle so I can drag the course
 * into position - this will change its position number") and Administration > Bundles (owner, 2026-11-12: "just like
 * courses").
 *
 * The server draws each row (tr[data-order-code]) with its code, the address its move is sent to (data-order-url), its
 * title (data-order-title), its number ([data-position]) and a hidden handle; this shows the handles, moves a row while it
 * is dragged by its handle or moved with the arrow keys, Home or End on it, and saves the move: the row goes after the row
 * now above it (or after the one before this page, data-list-order-before-value, or first). The server answers with each
 * row whose number changed, by its code, and those numbers are shown; when it refuses or cannot be reached the row goes
 * back. Without JavaScript the ⋯ menu's Move up, Move down and Move to the top do the same, and a number can be typed.
 * No markup is made here: rows are only moved. data-list-order-thing-value names what is moved, for the words. */
import { Controller } from '@hotwired/stimulus';

const ROW = 'tr[data-order-code]';

export default class extends Controller {
    static targets = ['handle', 'status'];
    static values = { csrf: String, before: String, thing: { type: String, default: 'item' } };

    handleTargetConnected(handle) { handle.hidden = false; }

    rows() { return [...this.element.querySelectorAll(ROW)]; }

    // Only a row picked up by its handle drags, so text in a row can still be selected.
    arm(event) {
        const row = event.currentTarget.closest(ROW);
        if (!row || this.busy) return;
        row.draggable = true;
        this.armed = row;
        document.addEventListener('pointerup', () => { if (!this.dragging && this.armed) { this.armed.draggable = false; this.armed = null; } }, { once: true });
    }

    start(event) {
        const row = event.target instanceof Element ? event.target.closest(ROW) : null;
        if (!row || row !== this.armed) return;
        this.dragging = row;
        this.origin = row.nextElementSibling;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', row.dataset.orderCode);
        row.classList.add('is-dragging');
    }

    over(event) {
        if (!this.dragging) return;
        const row = event.target instanceof Element ? event.target.closest(ROW) : null;
        event.preventDefault();
        if (!row || row === this.dragging) return;
        const box = row.getBoundingClientRect();
        row.parentNode.insertBefore(this.dragging, event.clientY > box.top + box.height / 2 ? row.nextElementSibling : row);
    }

    drop(event) { if (this.dragging) event.preventDefault(); }

    end() {
        const row = this.dragging;
        if (!row) return;
        row.classList.remove('is-dragging');
        row.draggable = false;
        this.dragging = null;
        this.armed = null;
        if (row.nextElementSibling !== this.origin) this.save(row, this.origin);
    }

    key(event) {
        const row = event.currentTarget.closest(ROW);
        const rows = this.rows();
        const at = rows.indexOf(row);
        const place = { ArrowUp: at > 0 ? rows[at - 1] : undefined, ArrowDown: at < rows.length - 1 ? rows[at + 1].nextElementSibling : undefined, Home: rows[0], End: null };
        if (!(event.key in place) || this.busy) return;
        event.preventDefault();
        const target = place[event.key];
        if (target === undefined || target === row || (event.key === 'End' && at === rows.length - 1)) { this.say(`That ${this.thingValue} cannot move any further that way.`, true); return; }
        const origin = row.nextElementSibling;
        row.parentNode.insertBefore(row, target);
        event.currentTarget.focus();
        this.save(row, origin);
    }

    async save(row, origin) {
        const above = row.previousElementSibling && row.previousElementSibling.matches(ROW) ? row.previousElementSibling.dataset.orderCode : this.beforeValue;
        const body = new FormData();
        body.append('csrf', this.csrfValue);
        body.append('after', above);
        this.busy = true;
        this.element.setAttribute('aria-busy', 'true');
        try {
            let response;
            try {
                response = await fetch(row.dataset.orderUrl, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            } catch (unreachable) {
                throw new Error('The move could not be saved because the server could not be reached. The previous order is shown.');
            }
            const answer = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(answer.error || 'The move could not be saved. The previous order is shown.');
            // Each row whose number changed, found by its code.
            for (const [code, position] of Object.entries(answer.positions || {})) {
                const number = this.element.querySelector(`${ROW}[data-order-code="${CSS.escape(code)}"] [data-position]`);
                if (number) number.textContent = String(position);
            }
            this.say(`${row.dataset.orderTitle} moved.`, false);
        } catch (error) {
            row.parentNode.insertBefore(row, origin);
            this.say(error.message, true);
        } finally {
            this.busy = false;
            this.element.removeAttribute('aria-busy');
        }
    }

    say(text, error) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = text;
        this.statusTarget.classList.toggle('is-error', error);
    }
}
