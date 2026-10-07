/* The behaviour every sortable tree on the platform shares: Course Content (course_content_controller)
 * and the course categories (category_tree_controller) extend it.
 *
 * The server renders the tree with the shared tree markup - li.cl-tree-node[data-node-id] rows with
 * data-depth (1 at the top) and data-height (1 for a row with nothing inside), each holding a
 * .cl-tree-row with a .cl-tree-handle, an optional .cl-tree-toggle and, for a row that can hold
 * others, an ol.cl-tree-children[data-parent] - and every move works through ordinary form posts.
 * This adds what needs JavaScript: the drag handle and its keyboard moves, drop markers that say
 * before, inside, after or not allowed, opening a closed branch after hovering over it while
 * dragging, branches that open and close in place and are remembered for the browser session, and
 * saving a move without a page load.
 *
 * A move is shown at once and sent to the server, which validates it against the stored tree and
 * answers with its copy of the tree: the saved one, or the authoritative one and the reason when it
 * refused. That copy replaces this one, so the server is always what the page ends up showing. When
 * the request itself fails the tree as it was before the move comes back. No row markup is
 * generated here: rows are only moved, and the server's tree is swapped in.
 *
 * A subclass says where moves go (moveRequest), where the branch state is remembered (storageKey),
 * whether branches start open (expandedByDefault) and how refusals are worded (messages). */
import { Controller } from '@hotwired/stimulus';

export const NODE = 'li[data-node-id]';
const KEYS = { ArrowUp: 'up', ArrowDown: 'down', Home: 'top', End: 'bottom', ArrowLeft: 'out', ArrowRight: 'in' };

export class MoveFailed extends Error {}

export default class SortableTreeController extends Controller {
    static targets = ['tree', 'handle', 'toggle', 'enhanced', 'status', 'error'];

    get maxDepth() { return 3; }

    get expandedByDefault() { return true; }

    storageKey() { return 'catto.tree'; }

    messages() {
        return {
            edge: 'That row cannot move any further that way.',
            deep: 'That move would make the tree deeper than three levels.',
            unreachable: 'The move could not be saved because the server could not be reached. The previous order is shown.',
            unreadable: 'The move could not be saved. The previous order is shown; reload the page to check the current order.',
            refused: 'The move could not be saved. The saved order is shown.',
            failed: 'The move could not be saved. The previous order is shown.',
        };
    }

    /** What to send for a move: { url, body } with the body a FormData. */
    moveRequest() { throw new MoveFailed(this.messages().failed); }

    /** Called with the server's whole answer after its tree is swapped in. */
    received() {}

    connect() {
        this.moving = [];
        this.armed = null;
        this.marked = null;
        this.busy = false;
    }

    // Stimulus calls these for the first tree and for every tree swapped in after a move.
    handleTargetConnected(handle) { handle.hidden = false; }

    enhancedTargetConnected(element) { element.hidden = false; }

    toggleTargetConnected(button) {
        this.set(button, this.startsExpanded(button));
        // A branch the server rendered open (a category just created or moved) stays open after the
        // next move, whose tree comes back closed.
        if (!this.rememberQueued) {
            this.rememberQueued = true;
            queueMicrotask(() => { this.rememberQueued = false; this.remember(); });
        }
    }

    startsExpanded(button) {
        const remembered = this.remembered().has(button.getAttribute('aria-controls'));
        return this.expandedByDefault ? !remembered : (remembered || button.getAttribute('aria-expanded') === 'true');
    }

    toggle(event) {
        // A disclosure may also be a submit button for the no-JavaScript view; here it toggles in place.
        event.preventDefault();
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

    // The branches that differ from the default: closed ones when branches start open, open ones
    // when they start closed.
    remembered() {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(this.storageKey()) || '[]');
            return new Set(Array.isArray(stored) ? stored : []);
        } catch {
            return new Set();
        }
    }

    remember() {
        const different = this.toggleTargets
            .filter((button) => (button.getAttribute('aria-expanded') === 'true') !== this.expandedByDefault)
            .map((button) => button.getAttribute('aria-controls'));
        this.store(new Set(different));
    }

    // One branch's state, for a list that has no toggle yet (a row that has just received its first child).
    rememberBranch(listId, expanded) {
        const set = this.remembered();
        if (expanded !== this.expandedByDefault) set.add(listId); else set.delete(listId);
        this.store(set);
    }

    store(set) {
        try {
            window.sessionStorage.setItem(this.storageKey(), JSON.stringify([...set]));
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

    // The rows a drag takes. One by default; Course Content takes every selected row.
    selection(node) { return [node]; }

    over(event) {
        if (!this.moving.length) return;
        const row = event.target instanceof Element ? event.target.closest('.cl-tree-row') : null;
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

    // The new parent's depth plus the height of the deepest moved subtree must stay within the limit.
    allowed(moving, target, zone) {
        const height = Math.max(...moving.map((row) => Number(row.dataset.height) || 1));
        const depth = Number(target.dataset.depth) || 1;
        if (zone === 'inside') return Boolean(this.childList(target)) && depth + height <= this.maxDepth;
        return depth - 1 + height <= this.maxDepth;
    }

    mark(target, zone) {
        if (this.marked && (this.marked !== target || !zone)) delete this.marked.dataset.drop;
        this.marked = target && zone ? target : null;
        if (this.marked) this.marked.dataset.drop = zone;
    }

    // Hovering inside a closed branch opens it, so a row can be dropped among its children.
    expandLater(target, zone) {
        if (zone === 'inside' && this.expandFor === target) return;
        window.clearTimeout(this.expandTimer);
        this.expandFor = zone === 'inside' ? target : null;
        const toggle = target.querySelector(':scope > .cl-tree-row .cl-tree-toggle');
        if (zone !== 'inside' || !toggle || toggle.getAttribute('aria-expanded') === 'true') return;
        this.expandTimer = window.setTimeout(() => { this.set(toggle, true); this.remember(); }, 700);
    }

    key(event) {
        const direction = KEYS[event.key];
        if (!direction || event.altKey || event.ctrlKey || event.metaKey) return;
        event.preventDefault();
        this.step(event.currentTarget.closest(NODE), direction, true);
    }

    // The ⋯ menu's move forms (data-tree-direction), saved without a page load. Other forms submit normally.
    menu(event) {
        const form = event.target;
        const direction = form instanceof HTMLFormElement ? form.dataset.treeDirection : '';
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
        if (!move) { this.say('error', this.messages().edge); return; }
        if (!this.allowed([node], move[0], move[1])) { this.say('error', this.messages().deep); return; }
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
        // Asked before the rows move, so the request can say where they came from.
        const request = this.moveRequest(moving, parent, index);
        request.body.append('csrf', this.csrf());
        const snapshot = this.treeTarget.cloneNode(true);
        if (zone === 'before') target.before(...moving);
        else if (zone === 'after') target.after(...moving);
        else {
            list.hidden = false;
            list.append(...moving);
            const toggle = target.querySelector(':scope > .cl-tree-row .cl-tree-toggle');
            if (toggle) this.set(toggle, true);
            // The destination stays open in the server's tree too, so the moved row can be seen.
            this.rememberBranch(list.id, true);
        }
        if (focusId) this.focusHandle(focusId);
        this.busy = true;
        this.treeTarget.setAttribute('aria-busy', 'true');
        this.say('saving', 'Saving…');

        try {
            let response;
            try {
                response = await fetch(request.url, { method: 'POST', body: request.body, headers: { 'HX-Request': 'true' }, credentials: 'same-origin' });
            } catch {
                throw new MoveFailed(this.messages().unreachable);
            }
            const answer = response.redirected ? null : this.parse(await response.text());
            const fresh = answer ? this.treeIn(answer) : null;
            if (!fresh) throw new MoveFailed(this.messages().unreadable);
            this.swapTree(fresh);
            this.received(answer);
            if (!response.ok || fresh.dataset.error) this.say('error', fresh.dataset.error || this.messages().refused);
            else this.say('saved', 'Saved');
        } catch (problem) {
            this.swapTree(snapshot);
            this.say('error', problem instanceof MoveFailed ? problem.message : this.messages().failed);
        } finally {
            this.busy = false;
            this.treeTarget.removeAttribute('aria-busy');
            if (focusId) this.focusHandle(focusId);
        }
    }

    // Every tree this controller puts in the page goes through here. htmx wires up only content it
    // swapped in itself, so links in the server's tree that load into a modal are handed to it.
    swapTree(tree) {
        this.prepare(tree);
        this.treeTarget.replaceWith(tree);
        if (window.htmx) window.htmx.process(tree);
    }

    // Reveal the handles and restore the branches before the tree is shown, so it does not flash
    // and a handle can take focus straight away.
    prepare(tree) {
        tree.querySelectorAll('.cl-tree-handle').forEach((handle) => { handle.hidden = false; });
        const remembered = this.remembered();
        tree.querySelectorAll('.cl-tree-toggle').forEach((button) => {
            const expanded = this.expandedByDefault !== remembered.has(button.getAttribute('aria-controls'));
            button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            const list = tree.querySelector(`#${CSS.escape(button.getAttribute('aria-controls'))}`);
            if (list) list.hidden = !expanded;
        });
    }

    parse(html) { return new DOMParser().parseFromString(html, 'text/html'); }

    treeIn(answer) {
        const tree = answer.getElementById(this.treeTarget.id);
        return tree ? document.importNode(tree, true) : null;
    }

    childList(node) { return node.querySelector(':scope > .cl-tree-children'); }

    focusHandle(id) {
        const node = this.element.querySelector(`li[data-node-id="${CSS.escape(String(id))}"]`);
        const handle = node ? node.querySelector(':scope > .cl-tree-row .cl-tree-handle') : null;
        if (handle) handle.focus();
    }

    csrf() {
        const input = this.element.querySelector('input[name="csrf"]');
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
