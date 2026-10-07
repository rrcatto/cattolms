/* Course Content tree on /admin/courses/{id}/content.
 *
 * The server renders the whole tree open with a ⋯ menu of form posts on every row, so every move
 * works without JavaScript. The shared sortable tree (assets/lib/sortable_tree_controller.js) adds
 * the drag handle, keyboard moves on the handle, collapsible branches remembered for the browser
 * session, and saving without a page load; this controller adds what is particular to Course
 * Content: dragging a selected row takes every selected row, and "+ Add here" opens its form in the
 * insert modal.
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
import SortableTreeController, { MoveFailed, NODE } from '../lib/sortable_tree_controller.js';

export default class extends SortableTreeController {
    static targets = ['insertBody'];
    static values = { course: Number };

    storageKey() { return `catto.admin.course-content.${this.courseValue}.closed`; }

    messages() {
        return {
            ...super.messages(),
            deep: 'That move would make Course Content deeper than three levels.',
            unreadable: 'The move could not be saved. The previous order is shown; reload the page to check the current course.',
        };
    }

    moveRequest(moving, parent, index) {
        const body = new FormData();
        moving.forEach((row) => body.append('node_ids[]', row.dataset.nodeId));
        body.append('parent_node_id', parent);
        body.append('index', index);
        return { url: `/admin/courses/${this.courseValue}/content/arrange`, body };
    }

    // Dragging a selected row takes every selected row; a selected row inside another goes with it.
    selection(node) {
        const box = node.querySelector(':scope > .cl-tree-row .cl-course-tree-select');
        if (!box || !box.checked) return [node];
        const checked = [...this.treeTarget.querySelectorAll('.cl-course-tree-select:checked')].map((input) => input.closest(NODE));
        return checked.filter((row) => !checked.some((other) => other !== row && other.contains(row)));
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
            const fresh = response.ok && !response.redirected ? this.treeIn(this.parse(await response.text())) : null;
            if (!fresh) throw new MoveFailed('');
            this.swapTree(fresh);
        } catch {
            // The insertion is saved; only the refresh failed. A reload shows it.
            window.location.reload();
            return;
        }
        const node = document.getElementById(`course-node-${detail.node}`);
        if (node) {
            // Open every branch above the new row, so it is visible.
            for (let list = node.parentElement.closest('.cl-tree-children'); list; list = list.parentElement.closest('.cl-tree-children')) {
                const toggle = this.element.querySelector(`[aria-controls="${CSS.escape(list.id)}"]`);
                if (toggle) this.set(toggle, true);
            }
            this.remember();
            node.scrollIntoView({ block: 'nearest' });
            this.focusHandle(detail.node);
        }
        this.say('saved', detail.message || 'Added');
    }
}
