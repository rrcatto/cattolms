/* Close, and every other way off an editing page, asks first while what was typed is not saved (owner, 2026-10-30: "if
 * you click close with pending changes, you can warn that changes will be lost and ask to confirm the action").
 *
 * Put on the page's main form, it watches the forms the forms value names (a selector; the slide's form and its
 * narration form, say). While any has a change not sent, every way off the page (the leave value, a selector) carries
 * the platform's confirmation (data-confirm, which platform-overrides.js asks), and the status targets say so (their
 * data-changed and data-saved words). Sending a form ends its changes. A page drawn again from what was typed (a layout
 * chosen, a save refused) starts with its own form changed (dirty value). A recording is kept on its own, so choosing
 * an audio file is not a change. A status target carries data-unsaved while its form's change is not saved, so a page can
 * mark it. No markup is made here. */
import { Controller } from '@hotwired/stimulus';

const QUESTION = 'Your changes are not saved. Leave without saving them?';

export default class extends Controller {
    static targets = ['status'];
    static values = { dirty: Boolean, leave: String, forms: String };

    connect() {
        this.changedForms = new Set(this.dirtyValue ? [this.element] : []);
        this.onChange = (event) => this.changed(event);
        this.onSubmit = (event) => this.sent(event);
        document.addEventListener('input', this.onChange);
        document.addEventListener('change', this.onChange);
        document.addEventListener('submit', this.onSubmit);
        this.show();
    }

    disconnect() {
        document.removeEventListener('input', this.onChange);
        document.removeEventListener('change', this.onChange);
        document.removeEventListener('submit', this.onSubmit);
    }

    watching(form) { return form instanceof HTMLFormElement && (form === this.element || (this.formsValue !== '' && form.matches(this.formsValue))); }

    changed(event) {
        const field = event.target instanceof Element ? event.target : null;
        const form = field && 'form' in field ? field.form : null;
        if (!this.watching(form) || (field.matches('input[type="file"]') && field.closest('.cl-narration'))) return;
        this.changedForms.add(form);
        this.show();
    }

    sent(event) {
        if (!this.watching(event.target)) return;
        this.changedForms.delete(event.target);
        this.show();
    }

    show() {
        const changed = this.changedForms.size > 0;
        if (this.leaveValue) {
            document.querySelectorAll(this.leaveValue).forEach((link) => {
                if (changed) link.dataset.confirm = QUESTION; else delete link.dataset.confirm;
            });
        }
        this.statusTargets.forEach((status) => {
            const unsaved = this.changedForms.has(this.element);
            const words = unsaved ? status.dataset.changed : status.dataset.saved;
            if (words) status.textContent = words;
            // A page can mark the words while they say the change is not saved (the Page Builder's save bar, 2026-10-31).
            status.toggleAttribute('data-unsaved', unsaved);
        });
    }
}
