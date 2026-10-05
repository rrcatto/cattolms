/* Rotates the home page's Popular Courses through the groups the server already rendered. Every group
 * arrives with the page, so rotation never asks the server for anything; groups after the first are
 * `hidden`, which keeps them out of sight, out of the tab order and out of the accessibility tree
 * until shown. Without JavaScript the first, highest-ranked group simply stays.
 *
 * Rotation waits while the reader is using the grid: while the pointer is over it, while focus is
 * inside it, and while the page is in a background tab, so a card never disappears under someone
 * tabbing to it or clicking it. Pause stops rotation until Resume. A reader who prefers reduced
 * motion starts paused and, if they resume, sees groups change without the fade. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['group'];
    static values = { interval: Number };

    connect() {
        if (this.groupTargets.length < 2) { return; }
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        this.index = Math.max(0, this.groupTargets.findIndex((group) => !group.hidden));
        this.paused = this.reducedMotion;
        this.hovering = false;
        this.focused = false;
        this.timer = null;
        this.visibility = () => this.schedule();
        document.addEventListener('visibilitychange', this.visibility);
        this.region = this.element.querySelector('#popular-courses-groups');
        this.reflect(false);
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        if (this.visibility) { document.removeEventListener('visibilitychange', this.visibility); }
    }

    pause() {
        this.paused = true;
        this.reflect(true);
        this.schedule();
    }

    resume() {
        this.paused = false;
        this.reflect(true);
        this.schedule();
    }

    suspend(event) {
        if (event.type === 'focusin') { this.focused = true; } else { this.hovering = true; }
        this.schedule();
    }

    release(event) {
        if (event.type === 'focusout') {
            // Focus moving between cards inside the grid is still interaction.
            if (event.relatedTarget && this.region.contains(event.relatedTarget)) { return; }
            this.focused = false;
        } else {
            this.hovering = false;
        }
        this.schedule();
    }

    /* One timer, restarted from the full interval whenever rotation may run again, so a group
     * never changes the moment the reader looks away. */
    schedule() {
        clearTimeout(this.timer);
        this.timer = null;
        if (this.paused || this.hovering || this.focused || document.hidden) { return; }
        this.timer = setTimeout(() => this.advance(), Math.max(1, this.intervalValue) * 1000);
    }

    advance() {
        this.show((this.index + 1) % this.groupTargets.length);
        this.schedule();
    }

    show(next) {
        const current = this.groupTargets[this.index];
        const incoming = this.groupTargets[next];
        current.hidden = true;
        current.classList.remove('is-entering');
        incoming.hidden = false;
        if (!this.reducedMotion) {
            incoming.classList.add('is-entering');
            incoming.addEventListener('animationend', () => incoming.classList.remove('is-entering'), { once: true });
        }
        this.index = next;
    }

    /* Shows whichever of Pause and Resume applies, keeping focus on the control when one replaces
     * the other, and announces group changes only while rotation is paused, as the carousel
     * pattern recommends: an automatic change is not something to read aloud every 20 seconds. */
    reflect(moveFocus) {
        const pauseButton = this.element.querySelector('#popular-courses-pause');
        const resumeButton = this.element.querySelector('#popular-courses-resume');
        if (!pauseButton || !resumeButton) { return; }
        pauseButton.hidden = this.paused;
        resumeButton.hidden = !this.paused;
        if (moveFocus) { (this.paused ? resumeButton : pauseButton).focus(); }
        this.region.setAttribute('aria-live', this.paused ? 'polite' : 'off');
    }
}
