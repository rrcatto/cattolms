/* The search result and shared link previews beside a page's search title and description (the SEO brief, 2026-11-09;
 * partials/admin/search-preview.html.twig). As either field is typed the previews follow: an empty field shows the page's
 * own words, the result's title carries the site's name after it, and each is cut where a search result cuts it, about
 * titleShown and descriptionShown characters, with the counts beneath. Without this script the previews show what is saved. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['title', 'description', 'cardTitle', 'cardDescription', 'count'];
    static values = {
        site: String, titleField: String, descriptionField: String, defaultTitle: String, defaultDescription: String,
        titleShown: { type: Number, default: 60 }, descriptionShown: { type: Number, default: 160 },
    };

    connect() {
        this.update = this.update.bind(this);
        this.fields().forEach((field) => field.addEventListener('input', this.update));
        this.update();
    }

    disconnect() {
        this.fields().forEach((field) => field.removeEventListener('input', this.update));
    }

    fields() {
        return [this.titleFieldValue, this.descriptionFieldValue].map((id) => document.getElementById(id)).filter(Boolean);
    }

    update() {
        const [titleField, descriptionField] = [document.getElementById(this.titleFieldValue), document.getElementById(this.descriptionFieldValue)];
        const title = this.plain(titleField ? titleField.value : '') || this.defaultTitleValue;
        const description = this.plain(descriptionField ? descriptionField.value : '') || this.defaultDescriptionValue;
        const result = `${title} · ${this.siteValue}`;
        this.titleTarget.textContent = this.cut(result, this.titleShownValue);
        this.descriptionTarget.textContent = this.cut(description, this.descriptionShownValue);
        this.cardTitleTarget.textContent = title;
        this.cardDescriptionTarget.textContent = description;
        const long = title.length > this.titleShownValue || description.length > this.descriptionShownValue;
        this.countTarget.textContent = `The title is ${title.length} characters and the description ${description.length}: a search result shows about ${this.titleShownValue} and ${this.descriptionShownValue}.`
            + (long ? ' What is longer is cut, as above.' : '') + ' Search engines decide for themselves in the end, so this is a likeness.';
        this.countTarget.dataset.state = long ? 'long' : '';
    }

    plain(text) {
        return String(text).replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
    }

    cut(text, length) {
        return text.length > length ? `${text.slice(0, length - 1).trim()}…` : text;
    }
}
