/* Preview on a slide's page (owner, 2026-10-30: "a Preview button instead which pops up a modal to show just the slide
 * you're currently editing ... None of this actually saves the work"). Put on the slide's form: Preview sends what the
 * form holds without saving it, and the slide the server draws is put in the Preview window (the preview value names
 * its body); the window itself is opened by the button's data-open-modal. Without JavaScript Preview draws the page
 * again with the slide above the form. No markup is made here: the server's is put in the window. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { preview: String };

    async preview(event) {
        const form = this.element;
        const body = document.querySelector(this.previewValue);
        if (!(form instanceof HTMLFormElement) || !body) return;
        event.preventDefault();
        const sent = new FormData(form);
        sent.set('slide_action', 'preview');
        body.textContent = 'Drawing the slide…';
        try {
            const response = await fetch(form.action, { method: 'POST', body: sent, headers: { 'HX-Request': 'true' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('refused');
            const answer = new DOMParser().parseFromString(await response.text(), 'text/html');
            body.replaceChildren(...[...answer.body.childNodes].map((node) => document.importNode(node, true)));
            if (window.Catwalk) window.Catwalk.mountAll(body);
        } catch {
            body.textContent = 'The preview could not be drawn. Check your connection and try again.';
        }
    }
}
