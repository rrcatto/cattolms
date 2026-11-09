/* A bundle's price and was price (owner, 2026-11-09: "When I type in the bundle price, can you display the percentage
   discount?"), on its page and in its row or card on Administration > Bundles. As either is typed, the line beside them
   says what percentage the price is off the was price and what the buyer saves, rounded down as the server rounds every
   saving (BundleService::discountWords says the same when the page is drawn). "Use the courses' total" puts the courses'
   prices added up into the was price; it shows only with JavaScript, and without it the total is in the words beside the
   field to type. Amounts are read as the server reads them: spaces and commas left out. */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['price', 'was', 'discount'];
    static values = { total: String, currency: String, locale: String };

    connect() {
        // The button that uses the courses' total is drawn hidden: it needs this script.
        if (this.totalValue !== '') this.element.querySelectorAll('[data-action*="bundle-price#useTotal"]').forEach((button) => { button.hidden = false; });
        this.update();
    }

    /* The courses' total as the was price. */
    useTotal(event) {
        event.preventDefault();
        if (!this.hasWasTarget || this.totalValue === '') return;
        this.wasTarget.value = this.totalValue;
        this.wasTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.update();
    }

    update() {
        if (!this.hasDiscountTarget || !this.hasPriceTarget || !this.hasWasTarget) return;
        const price = this.cents(this.priceTarget.value);
        const was = this.cents(this.wasTarget.value);
        let words = '';
        if (price !== null && was !== null) {
            words = was > price && was > 0
                ? `${Math.floor(((was - price) * 100) / was)}% off the was price: the buyer saves ${this.money(was - price)}.`
                : 'The was price must be higher than the bundle price.';
        }
        this.discountTarget.textContent = words;
        this.discountTarget.hidden = words === '';
    }

    /* An amount typed as the server reads it, in cents; null when it is empty or not an amount. */
    cents(typed) {
        const text = String(typed || '').replace(/[,\s]/g, '');
        if (!/^\d+(?:\.\d{1,2})?$/.test(text)) return null;
        const [whole, part = ''] = text.split('.');
        return Number(whole) * 100 + Number((part + '00').slice(0, 2));
    }

    money(cents) {
        try {
            return new Intl.NumberFormat(this.localeValue || 'en-ZA', { style: 'currency', currency: this.currencyValue || 'ZAR' }).format(cents / 100);
        } catch {
            return `${this.currencyValue || 'ZAR'} ${(cents / 100).toFixed(2)}`;
        }
    }
}
