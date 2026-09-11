/* Gilded Noir 1.1.7 — presentation-only theme script.
 *
 * This file must never take over platform behaviour. Dropdown open/close,
 * Escape handling, click-away, Administration tabs, modal dismissal, CSRF,
 * confirmation prompts and the palette system are all owned by Catto Learning
 * core and are deliberately left untouched here.
 *
 * It does exactly three presentational things:
 *   1. opens and closes the theme's own responsive navigation drawer;
 *   2. raises the sticky header once the page is scrolled;
 *   3. adds a client-side search box above substantial tables that the
 *      platform has not already given a filter, per THEME-COMPONENTS.md §6.
 *
 * On (3), two limits added in 1.1.5. The box only ever hides rows already on
 * the page, so it stands down entirely wherever core marks a dataset as
 * searched server-side: a real search sitting beside one that quietly
 * disagrees with it is worse than either alone. It also rebuilds after an htmx
 * swap, because it captures its row list once and would otherwise hold
 * references to rows that are no longer in the document.
 */
(function () {
  'use strict';

  var MIN_ROWS_FOR_SEARCH = 8;

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

  /* 3. Presentation-only table search ------------------------------------ */

  function tableRegion(table) {
    return table.closest('.admin-panel, .tab-panel, .card, .gn-main') || document.body;
  }

  function alreadyFiltered(table) {
    var region = tableRegion(table);

    /* The platform now marks any dataset it searches server-side. That search reaches the whole
       table; the box added below only hides rows already on the page, so offering both would put
       a real search next to one that silently disagrees with it. Core owns search semantics, so
       the theme stands down wherever core says it has them. */
    if (table.closest('[data-server-search-target]') || region.querySelector('[data-server-search]')) {
      return true;
    }

    return !!region.querySelector('[data-filter-input], [data-gn-filter]');
  }

  function buildSearch(table, index) {
    var id = 'gn-table-search-' + index;
    var wrap = document.createElement('div');
    wrap.className = 'gn-table-search';

    var label = document.createElement('label');
    label.className = 'gn-table-search-label';
    label.setAttribute('for', id);
    label.textContent = 'Search this table';

    var field = document.createElement('div');
    field.className = 'gn-table-search-field';

    var input = document.createElement('input');
    input.type = 'search';
    input.id = id;
    input.className = 'gn-table-search-input';
    input.setAttribute('data-gn-filter', '');
    input.setAttribute('autocomplete', 'off');
    input.placeholder = 'Type to filter the rows below';

    var count = document.createElement('span');
    count.className = 'gn-table-search-count';
    count.setAttribute('aria-live', 'polite');

    field.appendChild(input);
    field.appendChild(count);
    wrap.appendChild(label);
    wrap.appendChild(field);

    var rows = Array.prototype.slice.call(table.tBodies[0].rows);

    input.addEventListener('input', function () {
      var query = input.value.toLowerCase().trim();
      var shown = 0;
      rows.forEach(function (row) {
        var match = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
        row.hidden = !match;
        if (match) shown += 1;
      });
      count.textContent = query === '' ? '' : shown + ' of ' + rows.length + ' rows';
      table.classList.toggle('gn-table-empty-result', shown === 0);
    });

    return wrap;
  }

  function initTableSearch() {
    var index = 0;
    document.querySelectorAll('.gn-main table').forEach(function (table) {
      if (!table.tBodies.length) return;
      if (table.tBodies[0].rows.length < MIN_ROWS_FOR_SEARCH) return;
      if (table.closest('.cl-course-content, .cl-certificate, .modal-card')) return;
      if (alreadyFiltered(table)) return;

      var anchor = table.closest('.table-wrap, .table-responsive') || table;
      if (!anchor.parentNode) return;

      index += 1;
      anchor.parentNode.insertBefore(buildSearch(table, index), anchor);
    });
  }

  ready(function () {
    initNavigation();
    initHeaderState();
    initTableSearch();
  });

  /* The platform swaps table regions in place with htmx. The search box built above captures its
     row list once, so after a swap it holds references to rows that are no longer in the document
     and silently stops matching anything. Rebuilding after each swap keeps it honest, and the
     stale box is removed first so a swapped region never ends up with two. */
  document.body.addEventListener('htmx:afterSwap', function (event) {
    var scope = event.target && event.target.closest ? event.target.closest('.gn-main') : null;
    if (!scope) return;
    scope.querySelectorAll('.gn-table-search').forEach(function (box) { box.remove(); });
    initTableSearch();
  });
})();
