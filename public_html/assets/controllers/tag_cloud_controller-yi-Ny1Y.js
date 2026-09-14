/* Optional animation of the first 80 server-rendered tag links. The full vocabulary stays visible. */
import { Controller } from '@hotwired/stimulus';
import TagCloud from 'TagCloud';

export default class extends Controller {
    static targets = ['source', 'canvas'];
    static values = { radius: { type: Number, default: 260 }, limit: { type: Number, default: 80 } };

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
        const links = Array.from(this.sourceTarget.querySelectorAll('a[href]')).slice(0, Math.min(80, this.limitValue));
        if (!links.length) return;
        this.hrefs = links.map(link => link.getAttribute('href'));
        this.element.classList.add('is-animated');
        this.lastWidth = this.canvasTarget.clientWidth;
        const radius = Math.max(1, Math.min(this.radiusValue, this.lastWidth / 2 - 20));
        try {
            this.cloud = TagCloud([this.canvasTarget], links.map(link => link.dataset.tagLabel), {
                radius, maxSpeed: 'normal', initSpeed: 'normal', keep: true,
                useContainerInlineStyles: true,
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
