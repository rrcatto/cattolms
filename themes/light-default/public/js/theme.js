/* Light Default 1.0.0 — presentation-only theme script.
 *
 * Scope is deliberately tiny. This file may fail to load without breaking any
 * workflow:
 *   - navigation destinations are plain links and forms that work without it;
 *   - the responsive drawer is only needed below 1120px and the stylesheet
 *     reveals the navigation list when scripting is unavailable;
 *   - tab decks, dropdown menus, modals, flash dismissal, confirmations and
 *     palette selection are all platform-owned and are not touched here.
 *
 * Nothing in this file makes permission, route, session, assessment, progress,
 * credit or commerce decisions.
 */
(function () {
  'use strict';

  var ready = function (fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  };

  ready(function () {
    var toggle = document.querySelector('[data-ld-nav-toggle]');
    var nav = document.getElementById('ld-primary-nav');
    if (!toggle || !nav) return;

    var setOpen = function (open) {
      nav.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', function () {
      setOpen(!nav.classList.contains('is-open'));
    });

    // Collapse the drawer after a destination is chosen.
    nav.addEventListener('click', function (event) {
      var target = event.target instanceof Element ? event.target : null;
      if (target && target.closest('a, button[type="submit"]')) setOpen(false);
    });

    // Escape closes the drawer. Core keeps its own Escape handling for
    // dropdowns and modals; this listener only clears theme presentation.
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && nav.classList.contains('is-open')) setOpen(false);
    });

    // A viewport that grows past the drawer breakpoint must not leave the
    // navigation stuck in its mobile state.
    if (window.matchMedia) {
      var wide = window.matchMedia('(min-width: 1121px)');
      var sync = function () { if (wide.matches) setOpen(false); };
      if (typeof wide.addEventListener === 'function') wide.addEventListener('change', sync);
      else if (typeof wide.addListener === 'function') wide.addListener(sync);
    }
  });
})();
