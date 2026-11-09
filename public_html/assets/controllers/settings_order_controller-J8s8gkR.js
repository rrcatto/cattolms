/* The sections of Administration > Settings, in the order the owner arranges them (owner, 2026-11-09; settings-order.html.twig,
 * Configuration\SettingsOrder).
 *
 * A flat list: the shared sortable tree (assets/lib/sortable_tree_controller.js) with one level, so a section goes before
 * or after another, never inside one. It adds dragging by the handle, keyboard moves on the handle (up, down, top,
 * bottom) and saving the menu's moves without a page load; without JavaScript the menu's forms move a section.
 *
 * A move sends the section, the section it now goes before (none for the end) and the order the page showed. The server
 * answers with the order alone, saved or, when it refused the move, stored, with why; the sections are put in that order
 * and never drawn again, so a section left open and what is typed in it stay as they are. */
import SortableTreeController, { NODE } from '../lib/sortable_tree_controller.js';

export default class extends SortableTreeController {
    static values = { url: String };

    get maxDepth() { return 1; }

    storageKey() { return 'catto.admin.settings-order.open'; }

    messages() {
        return {
            ...super.messages(),
            edge: 'That section cannot move any further that way.',
            deep: 'Sections sit one after another; one cannot go inside another.',
        };
    }

    moveRequest(moving, parent, index) {
        const others = [...this.treeTarget.children].filter((element) => element.matches(NODE) && !moving.includes(element));
        const before = others[Number(index)];
        const body = new FormData();
        body.append('before', before ? before.dataset.nodeId : '');
        (this.treeTarget.dataset.order || '').split(',').filter(Boolean).forEach((key) => body.append('order[]', key));
        return { url: `${this.urlValue}/${moving[0].dataset.nodeId}/arrange`, body };
    }

    // The answer, or the list as it was before a move that failed, gives an order: the sections are put in it.
    swapTree(tree) {
        const order = (tree.dataset.order || '').split(',').filter(Boolean);
        const list = this.treeTarget;
        const nodes = new Map([...list.children].filter((element) => element.matches(NODE)).map((node) => [node.dataset.nodeId, node]));
        order.forEach((key) => { const node = nodes.get(key); if (node) list.append(node); });
        list.dataset.order = order.join(',');
        this.refreshMenus();
    }

    // The first section cannot go up and the last cannot go down: their menus say so.
    refreshMenus() {
        const nodes = [...this.treeTarget.children].filter((element) => element.matches(NODE));
        nodes.forEach((node, at) => {
            node.querySelectorAll('form[data-tree-direction] button').forEach((button) => {
                const direction = button.closest('form').dataset.treeDirection;
                button.disabled = ((direction === 'top' || direction === 'up') && at === 0) || ((direction === 'down' || direction === 'bottom') && at === nodes.length - 1);
            });
        });
    }
}
