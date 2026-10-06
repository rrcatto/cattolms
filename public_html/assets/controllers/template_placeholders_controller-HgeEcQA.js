/* Inserts a registered placeholder into the document template's HTML where the cursor is.
 *
 * The placeholder list is server-rendered with the exact syntax of each placeholder, so without
 * JavaScript an administrator copies it from the list; the Insert buttons are rendered hidden and
 * revealed here. Inserting puts text into the textarea and nothing else: it builds no markup, and
 * the server still validates every placeholder when the draft is saved, previewed or published.
 * Focus returns to the editor with the cursor after the inserted text, so the keyboard flow is
 * Insert, then carry on typing. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source'];

    connect() {
        // The Insert buttons are canonical action buttons, which carry the action but not a target.
        this.element.querySelectorAll('[data-action~="template-placeholders#insert"]').forEach((button) => { button.hidden = false; });
        this.remember = () => {
            this.start = this.sourceTarget.selectionStart;
            this.end = this.sourceTarget.selectionEnd;
        };
        this.sourceTarget.addEventListener('blur', this.remember);
    }

    disconnect() {
        if (this.remember) { this.sourceTarget.removeEventListener('blur', this.remember); }
    }

    insert(event) {
        event.preventDefault();
        const token = event.currentTarget.value;
        const source = this.sourceTarget;
        const start = this.start ?? source.value.length;
        const end = this.end ?? start;
        source.setRangeText(token, start, end, 'end');
        this.start = this.end = start + token.length;
        source.focus();
        source.setSelectionRange(this.start, this.end);
        source.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
