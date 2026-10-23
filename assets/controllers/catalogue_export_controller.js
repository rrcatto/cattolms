/* The courses to export at Import & Export Catalogue (owner, 2026-10-23: "manually ticking the courses does not change
   this msg ... the clear ticks button also does not work if I manually tick"). The count says how many are ticked as
   boxes are ticked by hand, and Tick every course, Tick the published courses and Clear the ticks tick in place.
   Without JavaScript those three buttons submit the form back to the page, which draws the boxes ticked. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['box', 'count'];

    connect() {
        this.update();
    }

    /* A tick button: every course, the published ones, or none, ticked here instead of reloading the page. */
    tick(event) {
        event.preventDefault();
        const which = event.currentTarget.value;
        for (const box of this.boxTargets) {
            box.checked = which === 'all' || (which === 'published' && box.dataset.status === 'published');
        }
        this.update();
    }

    update() {
        const ticked = this.boxTargets.filter((box) => box.checked).length;
        if (this.hasCountTarget) {
            this.countTarget.textContent = ticked === 0 ? 'No course is ticked.' : `${ticked} ${ticked === 1 ? 'course is' : 'courses are'} ticked.`;
        }
    }
}
