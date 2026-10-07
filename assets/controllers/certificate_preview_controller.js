/* Keeps a certificate preview up to date while the form changes. The form already has an
 * "Update preview" button that posts it into the preview frame (formaction and formtarget), and
 * that button is how the preview works without JavaScript; this controller presses it for the
 * reader a moment after they stop typing, choosing or uploading. Every kind of field announces a
 * change with an input event (radios, selects, files and colours too), and the certificate editor
 * signals a change to its wording with one on its textarea; listening for change as well would
 * redraw a text field's preview a second time when it loses focus. A field marked
 * data-certificate-preview-ignore (the design's name, which is not printed) does not redraw it. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { button: String, delay: { type: Number, default: 900 } };

    connect() {
        this.timer = null;
        this.changed = (event) => this.schedule(event);
        this.element.addEventListener('input', this.changed);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('input', this.changed);
    }

    schedule(event) {
        if (event.target instanceof Element && event.target.closest('[data-certificate-preview-ignore]')) {
            return;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), this.delayValue);
    }

    refresh() {
        const button = document.getElementById(this.buttonValue);
        if (button instanceof HTMLButtonElement && !button.disabled) {
            this.element.requestSubmit(button);
        }
    }
}
