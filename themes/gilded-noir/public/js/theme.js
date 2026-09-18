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

  /* 2. Scrolled header ---------------------------------------------------- */

  function initHeaderState() {
    var header = document.querySelector('.cl-header');
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
    initHeaderState();
  });
})();
