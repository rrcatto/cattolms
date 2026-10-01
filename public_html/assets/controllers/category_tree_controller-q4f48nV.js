/* Collapsible category tree on the category management page. The server renders every branch open,
 * so all categories stay reachable without JavaScript; this collapses them to the top level, toggles
 * already-rendered child lists, and remembers the open branches for the browser session so a move
 * or a visit to Manage returns to the same view. Collapsing a parent leaves its descendants' own
 * state alone, so reopening it shows them as they were. */
import { Controller } from '@hotwired/stimulus';

const STORAGE_KEY = 'catto.admin.category-tree.open';

export default class extends Controller {
    static targets = ['controls'];

    connect() {
        const open = this.remembered();
        this.toggles().forEach((button) => this.set(button, open.has(button.getAttribute('aria-controls'))));
        this.controlsTargets.forEach((controls) => { controls.hidden = false; });
    }

    toggle(event) {
        const button = event.currentTarget;
        this.set(button, button.getAttribute('aria-expanded') !== 'true');
        this.remember();
    }

    expandAll() {
        this.toggles().forEach((button) => this.set(button, true));
        this.remember();
    }

    collapseAll() {
        this.toggles().forEach((button) => this.set(button, false));
        this.remember();
    }

    toggles() {
        return [...this.element.querySelectorAll('button[aria-controls][data-action~="category-tree#toggle"]')];
    }

    set(button, expanded) {
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        const list = document.getElementById(button.getAttribute('aria-controls'));
        if (list) list.hidden = !expanded;
    }

    remembered() {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(STORAGE_KEY) || '[]');
            return new Set(Array.isArray(stored) ? stored : []);
        } catch {
            return new Set();
        }
    }

    remember() {
        const open = this.toggles().filter((button) => button.getAttribute('aria-expanded') === 'true').map((button) => button.getAttribute('aria-controls'));
        try {
            window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify(open));
        } catch {
            // Storage can be unavailable (private windows, blocked site data); the tree still works.
        }
    }
}
