/* Gilded Noir presentation: responsive navigation and scrolled header state. Platform components own all dataset and modal behaviour. */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
      fn();
    }
  }

  /* 1. Responsive navigation drawer ------------------------------------- */

  function initNavigation() {
    var toggle = document.querySelector('[data-gn-nav-toggle]');
    var header = document.querySelector('.gn-header');
    if (!toggle || !header) return;

    function setOpen(open) {
      header.classList.toggle('gn-nav-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    toggle.addEventListener('click', function () {
      setOpen(!header.classList.contains('gn-nav-open'));
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && header.classList.contains('gn-nav-open')) setOpen(false);
    });

    // Following a link should always leave the drawer closed behind it.
    header.addEventListener('click', function (event) {
      if (event.target.closest('.gn-primary a')) setOpen(false);
    });

    window.addEventListener('resize', function () {
      if (window.innerWidth > 1080) setOpen(false);
    });
  }

  /* 2. Scrolled header ---------------------------------------------------- */

  function initHeaderState() {
    var header = document.querySelector('.gn-header');
    if (!header) return;
    var ticking = false;

    function update() {
      header.classList.toggle('gn-header-scrolled', window.scrollY > 12);
      ticking = false;
    }

    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(update);
    }, { passive: true });

    update();
  }

  ready(function () {
    initNavigation();
    initHeaderState();
  });
})();
