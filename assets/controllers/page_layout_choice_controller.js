/* Which layout a course's page uses (owner, 2026-11-01, the accepted Course Page Layouts mockup), on the course's Page
 * layout tab: the default layout, one of the layouts, or its custom layout, for this course only.
 *
 * Without JavaScript the choice is sent with Save and the page drawn again shows what was chosen. Here choosing shows
 * at once what it means, saving nothing: Custom layout shows the builder (its own controller, page_layout); the other
 * two show the layout chosen, drawn for this course with the blocks it leaves out (lib/page_sketch.js), from the
 * layouts value ({id: {name, courses, blocks}}). The unsaved-changes controller on the form says it is not saved yet.
 * No markup is made here: the panels, names and sketch cells are the server's. */
import { Controller } from '@hotwired/stimulus';
import { drawSketch, listLeftOut, presentFrom } from '../lib/page_sketch.js';

export default class extends Controller {
    static targets = ['custom', 'shared', 'name', 'courses', 'kind', 'edit', 'sketch', 'leftOut', 'select', 'which'];
    static values = { layouts: Object, defaultId: String, present: String };

    connect() {
        this.onChange = (event) => this.changed(event);
        this.element.addEventListener('change', this.onChange);
        this.showWhich();
    }

    // The list of layouts shows only while One of your layouts is chosen.
    showWhich() {
        const source = this.element.querySelector('input[name="source"]:checked')?.value || 'default';
        if (this.hasWhichTarget) this.whichTarget.hidden = source !== 'layout';
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
    }

    changed(event) {
        const field = event.target;
        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) return;
        if (field.name === 'layout_id') {
            const named = this.element.querySelector('input[name="source"][value="layout"]');
            if (named && !named.checked) named.checked = true;
        } else if (field.name !== 'source') {
            return;
        }
        this.draw();
    }

    draw() {
        this.showWhich();
        const source = this.element.querySelector('input[name="source"]:checked')?.value || 'default';
        this.customTarget.hidden = source !== 'custom';
        this.sharedTarget.hidden = source === 'custom';
        if (source === 'custom') return;
        const id = source === 'layout' && this.hasSelectTarget ? this.selectTarget.value : this.defaultIdValue;
        const layout = this.layoutsValue[id];
        if (!layout) return;
        this.nameTarget.textContent = layout.name;
        this.kindTargets.forEach((kind) => { kind.hidden = kind.dataset.kind !== source; });
        this.coursesTarget.textContent = layout.courses === 1 ? '1 course uses it.' : `${layout.courses} courses use it.`;
        this.editTargets.forEach((wrap) => { const link = wrap.querySelector('a'); if (link) link.href = wrap.dataset.base + id; });
        const present = presentFrom(this.presentValue);
        drawSketch(this.sketchTarget, layout.blocks, present);
        listLeftOut(this.hasLeftOutTarget ? this.leftOutTarget : null, this.sketchTarget, layout.blocks, present);
    }
}
