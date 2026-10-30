/* A presentation's slides on its page, /admin/course-items/{id} (owner, 2026-10-30: "I need both a list and grid view of
 * all the slides and I should have a drag and drop editor to rearrange the order of the slides").
 *
 * The shared sortable tree (assets/lib/sortable_tree_controller.js) with one level, in a list or a grid: a slide goes
 * before or after another, never inside one. In the list the upper and lower halves of a row mean before and after it;
 * in the grid the left and right halves of a card do, and the left and right arrow keys move a slide one place. A move
 * sends the slide, its new place and the order the page showed; the server (PresentationEditor) refuses a move when the
 * stored order differs and answers with the saved list, or the stored one and the reason. Without JavaScript the ⋯
 * menu's forms move a slide.
 *
 * A slide's picture shows that slide in the preview above; without JavaScript it is a link to the preview. */
import SortableTreeController, { NODE } from '../lib/sortable_tree_controller.js';

export default class extends SortableTreeController {
    static values = { url: String };

    get maxDepth() { return 1; }

    storageKey() { return 'catto.admin.presentation-slides.open'; }

    messages() {
        return {
            ...super.messages(),
            edge: 'That slide cannot move any further that way.',
            deep: 'Slides go one after another; one cannot go inside another.',
        };
    }

    grid() { return this.treeTarget.dataset.view === 'grid'; }

    over(event) {
        this.pointerX = event.clientX;
        super.over(event);
    }

    zone(target, row, y) {
        if (!this.grid()) return super.zone(target, row, y);
        if (this.moving.some((moved) => moved.contains(target))) return 'invalid';
        const rect = row.getBoundingClientRect();
        return this.pointerX < rect.left + rect.width / 2 ? 'before' : 'after';
    }

    key(event) {
        const sideways = event.key === 'ArrowLeft' || event.key === 'ArrowRight';
        if (sideways && this.grid() && !event.altKey && !event.ctrlKey && !event.metaKey) {
            event.preventDefault();
            this.step(event.currentTarget.closest(NODE), event.key === 'ArrowLeft' ? 'up' : 'down', true);
            return;
        }
        if (sideways) return;
        super.key(event);
    }

    moveRequest(moving, parent, index) {
        const body = new FormData();
        body.append('index', index);
        (this.treeTarget.dataset.order || '').split(',').filter(Boolean).forEach((id) => body.append('order[]', id));
        return { url: `${this.urlValue}/${moving[0].dataset.nodeId}/arrange`, body };
    }

    show(event) {
        const deck = document.querySelector('#presentation-preview .cw-deck');
        const player = deck && window.Catwalk ? window.Catwalk.mount(deck) : null;
        if (!player) return;
        event.preventDefault();
        player.go(Number(event.currentTarget.dataset.slideNumber) || 1);
        const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        deck.scrollIntoView({ block: 'center', behavior: still ? 'auto' : 'smooth' });
        deck.focus({ preventScroll: true });
    }
}
