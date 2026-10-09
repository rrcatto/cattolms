/* The sections of a course landing page in its editor, /admin/courses/{id}/landing.
 *
 * A flat list: the shared sortable tree (assets/lib/sortable_tree_controller.js) with one level, so
 * a section can go before or after another but never inside one. It adds dragging by the handle,
 * keyboard moves on the handle (up, down, top, bottom) and saving the ⋯ menu's moves without a page
 * load; without JavaScript the menu's forms move a section. The hero sits above the list and never
 * moves.
 *
 * A move sends the section, where it goes among the others and the order the page showed. The server
 * (LandingPageService) refuses a move when the stored order differs, and answers with the saved list,
 * or the stored one and the reason. */
import SortableTreeController from '../lib/sortable_tree_controller.js';

export default class extends SortableTreeController {
    static values = { url: String };

    get maxDepth() { return 1; }

    storageKey() { return 'catto.admin.landing-sections.open'; }

    messages() {
        return {
            ...super.messages(),
            edge: 'That section cannot move any further that way.',
            deep: 'Sections sit one after another; one cannot go inside another.',
        };
    }

    moveRequest(moving, parent, index) {
        const body = new FormData();
        body.append('index', index);
        (this.treeTarget.dataset.order || '').split(',').filter(Boolean).forEach((id) => body.append('order[]', id));
        return { url: `${this.urlValue}/${moving[0].dataset.nodeId}/arrange`, body };
    }
}
