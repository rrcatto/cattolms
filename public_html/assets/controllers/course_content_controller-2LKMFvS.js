/* Course Content tree on /admin/courses/{id}/content.
 *
 * The server renders the whole tree open with a ⋯ menu of form posts on every row, so every move
 * works without JavaScript. This controller adds the drag handle, keyboard moves on the handle,
 * collapsible branches remembered for the browser session, and saving without a page load.
 *
 * Every move goes to one endpoint that validates and saves the whole arrangement. The moved rows
 * are shown in place straight away; the server answers with its copy of the tree, which replaces
 * this one. When the server refuses the move it answers with the authoritative tree and the
 * reason; when the request itself fails the tree as it was before the move comes back. No row
 * markup is generated here: the controller only moves server-rendered rows and swaps in the
 * server's tree.
 *
 * "+ Add here" loads its form into the insert modal with htmx. The controller shows a loading state,
 * starts the rich-text and question editors on the form that arrives and focuses its first field;
 * a successful insertion (an HX-Trigger event on the modal) closes the modal and swaps in the
 * server's tree with the new row, keeping the scroll position and the open and closed branches. */
import { Controller } from '@hotwired/stimulus';

const MAX_DEPTH = 3;
const NODE = 'li[data-node-id]';
const KEYS = { ArrowUp: 'up', ArrowDown: 'down', Home: 'top', End: 'bottom', ArrowLeft: 'out', ArrowRight: 'in' };

class MoveFailed extends Error {}

export default class extends Controller {
    static targets = ['tree', 'handle', 'toggle', 'enhanced', 'status', 'error', 'insertBody'];
    static values = { course: Number };

    connect() {
        this.moving = [];
        this.armed = null;
        this.marked = null;
        this.busy = false;
    }

    disconnect() {
        if (this.insertObserver) this.insertObserver.disconnect();
    }

    // Each form htmx places in the insert modal gets its editors and the focus.
    insertBodyTargetConnected(body) {
        this.insertObserver = new MutationObserver(() => this.insertLoaded(body));
        this.insertObserver.observe(body, {childList: true});
    }

    insertLoaded(body) {
        const panel = body.querySelector('.cl-course-insert-panel, form');
        if (!panel) return;
        if (window.CattoLearningEditors) window.CattoLearningEditors.initialise(body);
        if (window.CattoQuestionEditor) window.CattoQuestionEditor.initialise(body);
        const card = body.closest('.cl-ui-modal-card');
        if (card) card.scrollTop = 0;
        const field = body.querySelector('input:not([type="hidden"]):not([readonly]):not([type="radio"]):not([type="checkbox"]), select, textarea, input[type="radio"]:checked');
        if (field) field.focus();
    }

    // A "+ Add here" link is fetching its form: clear the previous one first.
    loading(event) {
        const ctx = event.detail && event.detail.ctx;
        if (!this.hasInsertBodyTarget || !ctx || ctx.target !== this.insertBodyTarget || !(ctx.sourceElement instanceof HTMLAnchorElement)) return;
        const menu = ctx.sourceElement.closest('details');
        if (menu) menu.open = false;
        this.clearInsert();
        this.insertBodyTarget.textContent = 'Loading…';
    }

    // The modal's form is about to be replaced: release its rich-text editors and empty the modal.
    // htmx settles a swap by copying attributes between old and new elements that share an id, which
    // would undo the editor's hiding of the new form's textareas; with nothing left there is nothing
    // to copy from.
    unloading(event) {
        const ctx = event.detail && event.detail.ctx;
        if (this.hasInsertBodyTarget && ctx && ctx.target === this.insertBodyTarget) this.clearInsert();
    }

    clearInsert() {
        if (window.CattoLearningEditors) window.CattoLearningEditors.destroy(this.insertBodyTarget);
        this.insertBodyTarget.replaceChildren();
    }

    async inserted(event) {
        const detail = event.detail || {};
        const modal = this.insertBodyTarget.closest('.cl-ui-modal');
        const close = modal ? modal.querySelector('[data-close-modal]') : null;
        if (close) close.click();
        this.clearInsert();
        try {
            const response = await fetch(`/admin/courses/${this.courseValue}/content/tree`, { headers: { 'HX-Request': 'true' }, credentials: 'same-origin' });
            const fresh = response.ok && !response.redirected ? this.parse(await response.text()) : null;
            if (!fresh) throw new MoveFailed('');
            this.prepare(fresh);
            this.treeTarget.replaceWith(fresh);
        } catch {
            // The insertion is saved; only the refresh failed. A reload shows it.
            window.location.reload();
            return;
        }
        const node = document.getElementById(`course-node-${detail.node}`);
        if (node) {
            // Open every branch above the new row, so it is visible.
            for (let list = node.parentElement.closest('.cl-course-tree-children'); list; list = list.parentElement.closest('.cl-course-tree-children')) {
                const toggle = this.element.querySelector(`[aria-controls="${CSS.escape(list.id)}"]`);
                if (toggle) this.set(toggle, true);
            }
            this.remember();
            node.scrollIntoView({ block: 'nearest' });
            this.focusHandle(detail.node);
        }
        this.say('saved', detail.message || 'Added');
    }

    // Stimulus calls these for the first tree and for every tree swapped in after a move.
    handleTargetConnected(handle) { handle.hidden = false; }
    enhancedTargetConnected(element) { element.hidden = false; }
    toggleTargetConnected(button) { this.set(button, !this.closed().has(button.getAttribute('aria-controls'))); }

    toggle(event) {
        const button = event.currentTarget;
        this.set(button, button.getAttribute('aria-expanded') !== 'true');
        this.remember();
    }

    expandAll() {
        this.toggleTargets.forEach((button) => this.set(button, true));
        this.remember();
    }

    collapseAll() {
        this.toggleTargets.forEach((button) => this.set(button, false));
        this.remember();
    }

    set(button, expanded) {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        const list = document.getElementById(button.getAttribute('aria-controls'));
        if (list) list.hidden = !expanded;
    }

    storageKey() { return `catto.admin.course-content.${this.courseValue}.closed`; }

    closed() {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(this.storageKey()) || '[]');
            return new Set(Array.isArray(stored) ? stored : []);
        } catch {
            return new Set();
        }
    }

    remember() {
        const closed = this.toggleTargets.filter((button) => button.getAttribute('aria-expanded') === 'false').map((button) => button.getAttribute('aria-controls'));
        try {
            window.sessionStorage.setItem(this.storageKey(), JSON.stringify(closed));
        } catch {
            // Storage can be unavailable (private windows, blocked site data); the tree still works.
        }
    }

    // Only the handle starts a drag: pressing it makes its row draggable until the drag ends.
    arm(event) {
        if (this.busy) return;
        this.disarm();
        this.armed = event.currentTarget.closest(NODE);
        this.armed.draggable = true;
        window.addEventListener('pointerup', () => { if (!this.moving.length) this.disarm(); }, { once: true });
    }

    disarm() {
        if (this.armed) this.armed.draggable = false;
        this.armed = null;
    }

    start(event) {
        const node = event.target instanceof Element ? event.target.closest(NODE) : null;
        if (!node || node !== this.armed || event.target !== node) return;
        this.moving = this.selection(node);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.moving.map((row) => row.dataset.nodeId).join(','));
        // After the browser has taken its drag image of the row.
        window.setTimeout(() => this.moving.forEach((row) => { row.dataset.dragging = 'true'; }), 0);
    }

    // Dragging a selected row takes every selected row; a selected row inside another goes with it.
    selection(node) {
        const box = node.querySelector(':scope > .cl-course-tree-row .cl-course-tree-select');
        if (!box || !box.checked) return [node];
        const checked = [...this.treeTarget.querySelectorAll('.cl-course-tree-select:checked')].map((input) => input.closest(NODE));
        return checked.filter((row) => !checked.some((other) => other !== row && other.contains(row)));
    }

    over(event) {
        if (!this.moving.length) return;
        const row = event.target instanceof Element ? event.target.closest('.cl-course-tree-row') : null;
        const target = row ? row.closest(NODE) : null;
        const zone = target ? this.zone(target, row, event.clientY) : null;
        this.mark(target, zone);
        if (!zone || zone === 'invalid') {
            event.dataTransfer.dropEffect = 'none';
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        this.expandLater(target, zone);
    }

    leave(event) {
        if (!(event.relatedTarget instanceof Node) || !this.element.contains(event.relatedTarget)) this.mark(null, null);
    }

    drop(event) {
        const target = this.marked;
        const zone = target ? target.dataset.drop : null;
        const moving = this.moving;
        if (!moving.length || !target || !zone || zone === 'invalid') return;
        event.preventDefault();
        this.end();
        this.commit(moving, target, zone, null);
    }

    end() {
        this.moving.forEach((row) => { delete row.dataset.dragging; });
        this.moving = [];
        this.disarm();
        this.mark(null, null);
        window.clearTimeout(this.expandTimer);
        this.expandFor = null;
    }

    // The upper and lower thirds of a row mean before and after it; the middle means inside it.
    // A zone that would break the depth limit falls back to the nearer edge, or is refused.
    zone(target, row, y) {
        if (this.moving.some((moved) => moved.contains(target))) return 'invalid';
        const rect = row.getBoundingClientRect();
        const ratio = (y - rect.top) / Math.max(rect.height, 1);
        const wanted = ratio < 0.3 ? 'before' : (ratio > 0.7 ? 'after' : 'inside');
        const nearer = ratio < 0.5 ? 'before' : 'after';
        return [wanted, nearer].find((zone) => this.allowed(this.moving, target, zone)) || 'invalid';
    }

    allowed(moving, target, zone) {
        const height = Math.max(...moving.map((row) => Number(row.dataset.height) || 1));
        const depth = Number(target.dataset.depth) || 1;
        if (zone === 'inside') return Boolean(this.childList(target)) && depth + height <= MAX_DEPTH;
        return depth - 1 + height <= MAX_DEPTH;
    }

    mark(target, zone) {
        if (this.marked && (this.marked !== target || !zone)) delete this.marked.dataset.drop;
        this.marked = target && zone ? target : null;
        if (this.marked) this.marked.dataset.drop = zone;
    }

    // Hovering inside a collapsed branch opens it, so a row can be dropped among its children.
    expandLater(target, zone) {
        if (zone === 'inside' && this.expandFor === target) return;
        window.clearTimeout(this.expandTimer);
        this.expandFor = zone === 'inside' ? target : null;
        const toggle = target.querySelector(':scope > .cl-course-tree-row .cl-course-tree-toggle');
        if (zone !== 'inside' || !toggle || toggle.getAttribute('aria-expanded') === 'true') return;
        this.expandTimer = window.setTimeout(() => { this.set(toggle, true); this.remember(); }, 700);
    }

    key(event) {
        const direction = KEYS[event.key];
        if (!direction || event.altKey || event.ctrlKey || event.metaKey) return;
        event.preventDefault();
        this.step(event.currentTarget.closest(NODE), direction, true);
    }

    // The ⋯ menu's move forms, saved without a page load. Other forms submit normally.
    menu(event) {
        const form = event.target;
        const direction = form instanceof HTMLFormElement ? form.dataset.courseContentDirection : '';
        if (!direction) return;
        event.preventDefault();
        const details = form.closest('details');
        if (details) details.open = false;
        this.step(form.closest(NODE), direction, false);
    }

    step(node, direction, refocus) {
        if (this.busy || !node) return;
        const siblings = [...node.parentElement.children].filter((element) => element.matches(NODE));
        const at = siblings.indexOf(node);
        const last = siblings.length - 1;
        const moves = {
            up: at > 0 ? [siblings[at - 1], 'before'] : null,
            down: at < last ? [siblings[at + 1], 'after'] : null,
            top: at > 0 ? [siblings[0], 'before'] : null,
            bottom: at < last ? [siblings[last], 'after'] : null,
            out: node.parentElement.closest(NODE) ? [node.parentElement.closest(NODE), 'after'] : null,
            in: at > 0 ? [siblings[at - 1], 'inside'] : null,
        };
        const move = moves[direction];
        if (!move) { this.say('error', 'That row cannot move any further that way.'); return; }
        if (!this.allowed([node], move[0], move[1])) { this.say('error', 'That move would make Course Content deeper than three levels.'); return; }
        this.commit([node], move[0], move[1], refocus ? node.dataset.nodeId : null);
    }

    async commit(moving, target, zone, focusId) {
        if (this.busy) return;
        const list = zone === 'inside' ? this.childList(target) : target.parentElement;
        const parent = zone === 'inside' ? target.dataset.nodeId : (list.dataset.parent || '');
        let index = '';
        if (zone !== 'inside') {
            const others = [...list.children].filter((element) => element.matches(NODE) && !moving.includes(element));
            index = String(others.indexOf(target) + (zone === 'after' ? 1 : 0));
        }
        const snapshot = this.treeTarget.cloneNode(true);
        if (zone === 'before') target.before(...moving);
        else if (zone === 'after') target.after(...moving);
        else {
            list.hidden = false;
            list.append(...moving);
            const toggle = target.querySelector(':scope > .cl-course-tree-row .cl-course-tree-toggle');
            if (toggle) this.set(toggle, true);
        }
        if (focusId) this.focusHandle(focusId);
        this.busy = true;
        this.treeTarget.setAttribute('aria-busy', 'true');
        this.say('saving', 'Saving…');

        const body = new FormData();
        body.append('csrf', this.csrf());
        moving.forEach((row) => body.append('node_ids[]', row.dataset.nodeId));
        body.append('parent_node_id', parent);
        body.append('index', index);
        try {
            let response;
            try {
                response = await fetch(`/admin/courses/${this.courseValue}/content/arrange`, { method: 'POST', body, headers: { 'HX-Request': 'true' }, credentials: 'same-origin' });
            } catch {
                throw new MoveFailed('The move could not be saved because the server could not be reached. The previous order is shown.');
            }
            const fresh = response.redirected ? null : this.parse(await response.text());
            if (!fresh) throw new MoveFailed('The move could not be saved. The previous order is shown; reload the page to check the current course.');
            this.prepare(fresh);
            this.treeTarget.replaceWith(fresh);
            if (!response.ok || fresh.dataset.error) this.say('error', fresh.dataset.error || 'The move could not be saved. The saved order is shown.');
            else this.say('saved', 'Saved');
        } catch (problem) {
            this.treeTarget.replaceWith(snapshot);
            this.say('error', problem instanceof MoveFailed ? problem.message : 'The move could not be saved. The previous order is shown.');
        } finally {
            this.busy = false;
            this.treeTarget.removeAttribute('aria-busy');
            if (focusId) this.focusHandle(focusId);
        }
    }

    // Reveal the handles and restore collapsed branches before the tree is shown, so it does not
    // flash open and a handle can take focus straight away.
    prepare(tree) {
        tree.querySelectorAll('.cl-course-tree-handle').forEach((handle) => { handle.hidden = false; });
        const closed = this.closed();
        tree.querySelectorAll('.cl-course-tree-toggle').forEach((button) => {
            const expanded = !closed.has(button.getAttribute('aria-controls'));
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const list = tree.querySelector(`#${CSS.escape(button.getAttribute('aria-controls'))}`);
            if (list) list.hidden = !expanded;
        });
    }

    parse(html) {
        const tree = new DOMParser().parseFromString(html, 'text/html').getElementById('course-content-tree');
        return tree ? document.importNode(tree, true) : null;
    }

    childList(node) { return node.querySelector(':scope > .cl-course-tree-children'); }

    focusHandle(id) {
        const node = document.getElementById(`course-node-${id}`);
        const handle = node ? node.querySelector(':scope > .cl-course-tree-row .cl-course-tree-handle') : null;
        if (handle) handle.focus();
    }

    csrf() {
        const input = this.element.querySelector('#move-selection input[name="csrf"]');
        return input ? input.value : '';
    }

    say(state, text) {
        window.clearTimeout(this.statusTimer);
        const error = state === 'error';
        this.errorTarget.hidden = !error;
        this.errorTarget.textContent = error ? text : '';
        this.statusTarget.dataset.state = state;
        this.statusTarget.textContent = error ? '' : text;
        if (state === 'saved') {
            this.statusTimer = window.setTimeout(() => {
                this.statusTarget.textContent = '';
                delete this.statusTarget.dataset.state;
            }, 2500);
        }
    }
}
