/* The row of slides at the top of a slide's page (owner, 2026-10-30: "left and right nav buttons to scroll backwards and
 * forwards through the slides ... First and Last Slide buttons (but use svg icons, not text"). The four buttons are shown
 * only here and scroll the row; without JavaScript the row scrolls by itself. The slide being edited starts in view. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['strip', 'scroller'];

    scrollerTargetConnected(button) { button.hidden = false; }

    connect() {
        const here = this.stripTarget.querySelector('[aria-current="page"]');
        if (here) this.stripTarget.scrollLeft = Math.max(0, here.offsetLeft - (this.stripTarget.clientWidth - here.offsetWidth) / 2);
    }

    behaviour() { return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'; }

    first() { this.stripTarget.scrollTo({ left: 0, behavior: this.behaviour() }); }

    last() { this.stripTarget.scrollTo({ left: this.stripTarget.scrollWidth, behavior: this.behaviour() }); }

    back() { this.stripTarget.scrollBy({ left: -this.stripTarget.clientWidth * 0.8, behavior: this.behaviour() }); }

    on() { this.stripTarget.scrollBy({ left: this.stripTarget.clientWidth * 0.8, behavior: this.behaviour() }); }
}
