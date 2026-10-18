/* Optional animation of the 30 most used tags, each sized by how many courses it labels, as the list
   beside it is (owner, 2026-10-18: a tag cloud shows the most popular tags biggest). More than 30 words
   on one sphere overlap into a ball nobody can read. The full list of tags in use stays visible beside
   it and works without JavaScript. */
import { Controller } from '@hotwired/stimulus';
import TagCloud from 'TagCloud';

/* Font sizes of the five weights on the sphere: the list's steps, a little smaller, so words do not collide. */
const SIZES = { 1: '0.75rem', 2: '0.88rem', 3: '1.02rem', 4: '1.2rem', 5: '1.45rem' };
const WEIGHTS = { 1: '400', 2: '500', 3: '600', 4: '650', 5: '700' };

export default class extends Controller {
    static targets = ['source', 'canvas'];
    static values = { radius: { type: Number, default: 260 }, limit: { type: Number, default: 30 } };

    connect() {
        this.motion = window.matchMedia('(prefers-reduced-motion: reduce)');
        this.motion.addEventListener('change', this.refresh);
        this.canvasTarget.addEventListener('click', this.onClick);
        this.refresh();
        this.observer = new ResizeObserver(() => {
            const width = this.canvasTarget.clientWidth;
            if (width && width !== this.lastWidth) this.refresh();
        });
        this.observer.observe(this.canvasTarget);
    }

    disconnect() {
        this.motion.removeEventListener('change', this.refresh);
        this.observer?.disconnect();
        this.canvasTarget.removeEventListener('click', this.onClick);
        this.stop();
    }

    stop() {
        this.cloud?.destroy();
        this.cloud = null;
        this.canvasTarget.replaceChildren();
        this.element.classList.remove('is-animated');
    }

    refresh = () => {
        this.stop();
        if (this.motion.matches || typeof TagCloud !== 'function') return;
        // The most used tags, not the first ones alphabetically.
        const links = Array.from(this.sourceTarget.querySelectorAll('a[href]'))
            .sort((a, b) => Number(b.dataset.tagCount || 0) - Number(a.dataset.tagCount || 0))
            .slice(0, Math.min(30, this.limitValue));
        if (!links.length) return;
        this.hrefs = links.map(link => link.getAttribute('href'));
        this.element.classList.add('is-animated');
        this.lastWidth = this.canvasTarget.clientWidth;
        // As large as the canvas allows, so the words spread over the whole sphere.
        const radius = Math.max(1, Math.min(this.radiusValue, this.lastWidth / 2 - 50));
        try {
            this.cloud = TagCloud([this.canvasTarget], links.map(link => link.dataset.tagLabel), {
                radius, maxSpeed: 'normal', initSpeed: 'normal', keep: true,
                useContainerInlineStyles: true,
            });
            // TagCloud draws every word alike; each takes its tag's size, in the order given.
            this.canvasTarget.querySelectorAll('.tagcloud--item').forEach((item, index) => {
                const weight = Number(links[index]?.dataset.tagWeight || 1);
                item.style.fontSize = SIZES[weight] || SIZES[1];
                item.style.fontWeight = WEIGHTS[weight] || WEIGHTS[1];
            });
        } catch {
            this.stop();
        }
    };

    onClick = event => {
        const item = event.target.closest('.tagcloud--item');
        if (!item) return;
        const index = Array.from(this.canvasTarget.querySelectorAll('.tagcloud--item')).indexOf(item);
        if (this.hrefs[index]) window.location.assign(this.hrefs[index]);
    };
}
