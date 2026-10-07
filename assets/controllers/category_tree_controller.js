/* The category tree on /admin/courses/categories.
 *
 * The server renders the tree closed except for the branches asked for (and those around a category
 * just created, moved or edited); its disclosure buttons submit a GET form, and the ⋯ menu's moves
 * and Move into are form posts, so the tree works without JavaScript. The shared sortable tree
 * (assets/lib/sortable_tree_controller.js) adds dragging by the handle with before, inside and after
 * drop markers, keyboard moves on the handle, opening a closed category after hovering over it while
 * dragging, branches that open and close in place and are remembered for the browser session, and
 * saving a move without a page load. The server decides every move (CategoryHierarchyService): it
 * answers with the saved tree and counts, or the stored ones and the reason when it refused.
 *
 * This controller adds what is particular to categories: one category moves at a time, the request
 * tells the server which parent the page showed it under, so a tree changed by someone else is
 * refused rather than guessed at, and the counts above the tree are refreshed with it. Add category
 * and Add subcategory load their form into the page's modal with htmx. */
import SortableTreeController, { NODE } from '../lib/sortable_tree_controller.js';

export default class extends SortableTreeController {
    get expandedByDefault() { return false; }

    storageKey() { return 'catto.admin.category-tree.open'; }

    messages() {
        return {
            ...super.messages(),
            edge: 'That category cannot move any further that way.',
            deep: 'Course categories go three levels deep, so it cannot go there.',
        };
    }

    moveRequest(moving, parent, index) {
        const row = moving[0];
        const from = row.parentElement.closest('.cl-tree-children, .cl-tree-list');
        const body = new FormData();
        body.append('parent_id', parent);
        body.append('index', index);
        body.append('from_parent', from ? (from.dataset.parent || '') : '');
        return { url: `/admin/courses/categories/${row.dataset.nodeId}/arrange`, body };
    }

    // The counts above the tree come back with it: moving a category between levels changes them.
    received(answer) {
        const fresh = answer.getElementById('category-statistics');
        const current = this.element.querySelector('#category-statistics');
        if (fresh && current) current.replaceWith(document.importNode(fresh, true));
    }

    // Add category and Add subcategory are loading their form into the modal: show that, and close
    // the row's menu if the link was in one.
    loading(event) {
        const ctx = event.detail && event.detail.ctx;
        const body = document.getElementById('category-create-body');
        if (!body || !ctx || ctx.target !== body) return;
        const menu = ctx.sourceElement instanceof Element ? ctx.sourceElement.closest('details') : null;
        if (menu) menu.open = false;
        body.textContent = 'Loading…';
    }

    // A category opened from the address (#category-12) has its branch open and is in view.
    connect() {
        super.connect();
        const target = window.location.hash.startsWith('#category-') ? document.getElementById(window.location.hash.slice(1)) : null;
        if (!target || !target.matches(NODE) || !this.element.contains(target)) return;
        target.scrollIntoView({ block: 'center' });
    }
}
