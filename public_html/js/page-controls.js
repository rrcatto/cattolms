/* cattolms's own forms in the browser, after CattoPHP's (cattophp/js/cattophp.js sends a form on a change, sets one field
   from another, asks before a form is sent and counts a live page's seconds): feature cards that show only their own
   fields, the Register page's time zone and Share this course or bundle. The site's content policy runs only scripts
   from the site's own files. Every one is a convenience: without JavaScript each form still shows all its fields. */
(function () {
    'use strict';

    /* Feature cards that show only their own fields: the contact form's reason and who it is for, and how to sign in.
       The fields of a choice not made are hidden and left out of the form; without JavaScript every field shows. */
    function syncContact(form) {
        var chosen = form.querySelector('input[name=reason]:checked');
        form.querySelectorAll('[data-contact-reason]').forEach(function (set) {
            var on = !!chosen && set.dataset.contactReason === chosen.value;
            set.hidden = !on;
            set.disabled = !on;
        });
        var company = form.querySelector('[data-contact-company]');
        if (company) {
            var forCompany = form.querySelector('input[name=for_company]:checked');
            var on = !!forCompany && forCompany.value === 'yes';
            company.hidden = !on;
            company.querySelectorAll('input').forEach(function (field) { field.disabled = !on; });
        }
    }
    function syncSignIn(form) {
        var chosen = form.querySelector('input[name=how]:checked');
        var how = chosen ? chosen.value : 'email';
        form.querySelectorAll('[data-sign-in-how]').forEach(function (set) {
            var on = set.dataset.signInHow === how;
            set.hidden = !on;
            var button = set.querySelector('button[value]');
            if (button) { if (on) button.name = 'how'; else button.removeAttribute('name'); }
        });
    }
    function syncRegistration(form) {
        var company = form.querySelector('[data-registration-company]');
        if (!company) return;
        var chosen = form.querySelector('input[name=account_type]:checked');
        var isCompany = !!chosen && chosen.value === 'company';
        company.required = isCompany;
        var field = company.closest('.cl-ui-field');
        if (field) field.toggleAttribute('hidden', !isCompany);
    }
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || !input.form) return;
        var form = input.form;
        if (form.matches('[data-contact-form]') && (input.name === 'reason' || input.name === 'for_company')) syncContact(form);
        if (form.matches('[data-sign-in-form]') && input.name === 'how') {
            syncSignIn(form);
            var first = form.querySelector('[data-sign-in-how]:not([hidden]) input');
            if (first) first.focus();
        }
        if (input.name === 'account_type') syncRegistration(form);
    });

    /* The browser's time zone on the Register page, which the sign-up check compares with the country. */
    function fillTimeZone(root) {
        root.querySelectorAll('[data-signup-time-zone]').forEach(function (zone) {
            try { zone.value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (_) {}
        });
    }

    /* Share this course or bundle (partials/share-page.html.twig; owner, 2026-11-09): on a phone or tablet, a touch screen
       whose browser has its own Share, the Share link opens the device's share sheet instead of the window, which reaches
       every app the person has; anywhere else it opens the window (platform-overrides.js), where Copy link puts the address
       on the clipboard and says so. Each address is tagged with where it was shared (share_sheet, copied_link), so Site
       Analytics counts who arrives through it. Caught before the window's own opener hears the press. */
    var touchScreen = window.matchMedia ? window.matchMedia('(pointer: coarse)') : null;
    document.addEventListener('click', function (event) {
        var opener = event.target instanceof Element ? event.target.closest('[data-share] [data-open-modal]') : null;
        var box = opener ? opener.closest('[data-share]') : null;
        if (!box || typeof navigator.share !== 'function' || !touchScreen || !touchScreen.matches) return;
        event.preventDefault();
        event.stopPropagation();
        navigator.share({title: box.getAttribute('data-share-title') || document.title, url: box.getAttribute('data-share-sheet-url') || box.getAttribute('data-share-url') || location.href}).catch(function () {});
    }, true);
    function readyShare(root) {
        root.querySelectorAll('[data-share] [data-share-copy-item]').forEach(function (copy) {
            if (navigator.clipboard && window.isSecureContext) copy.hidden = false;
        });
    }
    document.addEventListener('click', function (event) {
        var copy = event.target instanceof Element ? event.target.closest('[data-share-copy]') : null;
        var box = copy ? copy.closest('[data-share]') : null;
        if (!box) return;
        navigator.clipboard.writeText(box.getAttribute('data-share-copy-url') || box.getAttribute('data-share-url') || location.href).then(function () {
            var done = box.querySelector('[data-share-done]');
            if (!done) return;
            done.hidden = false;
            setTimeout(function () { done.hidden = true; }, 4000);
        }).catch(function () {});
    });

    function ready(root) {
        readyShare(root);
        root.querySelectorAll('form[data-contact-form]').forEach(syncContact);
        root.querySelectorAll('form[data-sign-in-form]').forEach(syncSignIn);
        root.querySelectorAll('[data-registration-company]').forEach(function (company) { if (company.form) syncRegistration(company.form); });
        fillTimeZone(root);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { ready(document); }, {once: true}); else ready(document);
    document.addEventListener('htmx:after:swap', function () { ready(document); });

})();
