/* cattolms's own behaviour in the browser, after CattoPHP's (cattophp/js/cattophp.js, CattoPHP Phase 3): the course
   reader's Course Modules list keeping its place, a course's category field with + New, the mail settings' password,
   the theme's colour palettes, the breadcrumb cart, an address's province, and the site notification's countdown. */
(() => {
  'use strict';
  const {ready, within, keepScrollerPlace} = window.CattoPHP;

  /*
    The course reader's Course Modules list keeps its place the same way (owner, 2026-11-01: "When I click in the
    Course Modules sidebar, while previewing a course, the sidebar resets to the top and I must scroll back down").
    Since every module lists its parts (v1.0.1) the list is long enough to scroll, and each part is its own page.
    Its place is kept per course (a key for each /learn/<course> or /courses/<course>), and the part being read is
    brought into view when it would be off the list (arriving by Next, or from elsewhere).
  */
  const courseOutlineKey = () => {
    const match = location.pathname.match(/^\/(learn|courses)\/([^/]+)/);
    return match ? `catto-learning:course-outline-scroll:${match[2]}` : null;
  };
  const outline = {find: () => document.querySelector('.cl-course-reader > .cl-course-outline'), key: courseOutlineKey, current: '[aria-current="page"], .is-current'};
  if (!keepScrollerPlace(outline)) ready(() => keepScrollerPlace(outline));

  ready(() => {

    /* A course's category field (partials/admin/course-category-field): the tree select and, for
       those who manage categories, + New, which creates a category in place without leaving the
       course or import form. The new category's place is chosen in its own tree select (Top level,
       a main category or a subcategory) and shown as the path it will have. The server checks the
       place again; on success the whole field is drawn again by the server with the new category
       chosen, and on a refusal the panel stays open with what was typed and the reason. Cancel
       creates nothing and leaves the chosen category as it was. */
    const initialiseCategoryFields = root => within(root, '[data-category-field]').forEach(field => {
      if (field.dataset.categoryFieldReady === 'true') return;
      const toggle = field.querySelector('[data-category-create-toggle]'), panel = field.querySelector('[data-category-create-panel]');
      if (!toggle || !panel) return;
      field.dataset.categoryFieldReady = 'true';
      const name = panel.querySelector('[data-category-name]'), description = panel.querySelector('[data-category-description]');
      const error = panel.querySelector('[data-category-error]'), create = panel.querySelector('[data-category-create]'), cancel = panel.querySelector('[data-category-create-cancel]');
      const place = panel.querySelector('.cl-ui-tree-select'), result = panel.querySelector('[data-category-create-path]');
      const enhanced = toggle.closest('[data-category-create-enhanced]');
      if (enhanced) enhanced.hidden = false;
      const parent = () => place ? place.querySelector('input[type="radio"]:checked') : null;
      const preview = () => {
        if (!result) return;
        const chosen = parent();
        const where = chosen && chosen.value !== '0' ? `${chosen.dataset.treeSelectPath} › ` : '';
        result.textContent = where + (name.value.trim() || 'the new category');
      };
      const say = message => { error.textContent = message; error.hidden = message === ''; };
      const setOpen = open => {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { preview(); window.setTimeout(() => name.focus(), 10); } else say('');
      };
      toggle.addEventListener('click', () => setOpen(panel.hidden));
      if (cancel) cancel.addEventListener('click', () => { setOpen(false); toggle.focus(); });
      name.addEventListener('input', preview);
      if (place) place.addEventListener('change', preview);
      name.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); create.click(); } });
      create.addEventListener('click', async () => {
        const categoryName = name.value.trim();
        if (categoryName === '') { say('Enter a category name.'); name.focus(); return; }
        create.disabled = true;
        const body = new FormData();
        body.append('csrf', panel.dataset.csrf || '');
        body.append('name', categoryName);
        body.append('description', description ? description.value.trim() : '');
        body.append('parent_id', parent() ? parent().value : '0');
        try {
          const response = await fetch(panel.dataset.createUrl || '/admin/courses/categories/inline', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
          const data = await response.json().catch(() => ({}));
          if (!response.ok || !data.category) throw new Error(data.error || 'The category could not be created.');
          const query = new URLSearchParams({ field_id: field.dataset.fieldId || '', field_name: field.dataset.fieldName || '', selected: String(data.category.slug) });
          const page = await fetch(`${field.dataset.refreshUrl}?${query}`, { credentials: 'same-origin', headers: { 'HX-Request': 'true' } });
          const fresh = page.ok ? new DOMParser().parseFromString(await page.text(), 'text/html').querySelector('[data-category-field]') : null;
          if (!fresh) throw new Error(`“${data.category.name}” was created, but the list could not be refreshed. Reload the page to choose it.`);
          const adopted = document.importNode(fresh, true);
          field.replaceWith(adopted);
          window.CattoTreeSelect.initialise(adopted);
          const chosen = adopted.querySelector('.cl-ui-tree-select-toggle');
          if (chosen) chosen.focus();
        } catch (exception) {
          say(exception instanceof Error ? exception.message : 'The category could not be created.');
        } finally {
          create.disabled = false;
        }
      });
    });
    window.CattoTreeSelect.extend(initialiseCategoryFields);
    initialiseCategoryFields(document);
    const passwordInput=document.querySelector('form[action="/admin/settings/mail"] input[name="smtp_password"]');
    if(passwordInput instanceof HTMLInputElement){const form=passwordInput.closest('form'),csrfInput=form?.querySelector('input[name="csrf"]');if(csrfInput instanceof HTMLInputElement&&csrfInput.value){const toggle=document.getElementById('smtp-password-toggle');if(!(toggle instanceof HTMLButtonElement))return;toggle.hidden=false;toggle.addEventListener('click',()=>{const visible=passwordInput.type==='password';passwordInput.type=visible?'text':'password';toggle.querySelector('span').textContent=visible?'Hide':'Show';});fetch('/admin/settings/mail/password',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({csrf:csrfInput.value}).toString(),cache:'no-store'}).then(response=>response.ok?response.json():null).then(data=>{if(!data)return;passwordInput.value=typeof data.password==='string'?data.password:'';toggle.disabled=false;}).catch(()=>{});}}
    if(document.body.dataset.clCourseReader==='1')return;
    const preview = new URL(location.href).searchParams.get('theme_preview');
    const paletteUrl = new URL('/theme/palette', location.origin); if (preview) paletteUrl.searchParams.set('theme_preview', preview);
    fetch(paletteUrl,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'}).then(response=>response.ok?response.json():null).then(config=>{
      if(!config?.enabled||!Array.isArray(config.palettes)||config.palettes.length<1)return;
      const themeKey=String(config.theme_key||'theme'),storagePrefix=`catto-learning:${themeKey}:palette`,paletteCount=config.palettes.length;let selected=0,hidden=false;try{selected=Number(localStorage.getItem(storagePrefix)||0);hidden=localStorage.getItem(`${storagePrefix}:hidden`)==='1';}catch(_){} if(!Number.isInteger(selected)||selected<0||selected>=paletteCount)selected=0;
      // The class is what the server renders from the cookie, so the first paint is already the
      // chosen palette. Setting it again here changes nothing on screen; the data attributes are
      // kept because the shipped stylesheets match both forms.
      const apply=index=>{selected=index;const body=document.body;body.dataset.clPaletteManaged='1';body.dataset.clPalette=String(index);body.classList.add('cl-palette-managed');body.className=body.className.replace(/\bcl-palette-\d+\b/g,'').trim()+' cl-palette-'+index;document.querySelectorAll('.cl-palette-option').forEach((button,i)=>{button.classList.toggle('active',i===index);button.setAttribute('aria-pressed',i===index?'true':'false');});try{localStorage.setItem(storagePrefix,String(index));}catch(_){}
        // A year, path-wide, lax: the palette is a display preference and is read on every request.
        try{document.cookie='cl_palette='+encodeURIComponent(String(index))+';path=/;max-age=31536000;samesite=lax';}catch(_){}};
      apply(selected);
      if(config.switcher_visible===false||paletteCount<2)return;
      const dock=document.createElement('aside');dock.className='cl-palette-switcher';dock.setAttribute('aria-label','Colour palette');dock.innerHTML='<div class="cl-palette-head"><strong>Colour palette</strong><button class="cl-palette-hide" type="button">Hide</button></div><div class="cl-palette-options"></div>';const options=dock.querySelector('.cl-palette-options');
      config.palettes.forEach((palette,index)=>{const button=document.createElement('button');button.type='button';button.className='cl-palette-option';button.setAttribute('aria-pressed','false');const name=document.createElement('span');name.className='cl-palette-name';name.textContent=String(palette.name||`Palette ${index+1}`);const swatches=document.createElement('span');swatches.className='cl-palette-swatches';(palette.colors||[]).forEach(colour=>{const swatch=document.createElement('i');swatch.className='cl-palette-swatch';swatch.style.background=String(colour);swatches.appendChild(swatch);});button.append(name,swatches);button.addEventListener('click',()=>apply(index));options.appendChild(button);});
      const show=document.createElement('button');show.type='button';show.className='cl-palette-show';show.textContent='Colours';const setHidden=value=>{hidden=value;dock.hidden=value;show.hidden=!value;try{localStorage.setItem(`${storagePrefix}:hidden`,value?'1':'0');}catch(_){}};dock.querySelector('.cl-palette-hide').addEventListener('click',()=>setHidden(true));show.addEventListener('click',()=>setHidden(false));document.body.append(dock,show);apply(selected);setHidden(hidden);
    }).catch(()=>{});
  });
})();
// The breadcrumb cart uses native disclosure semantics for keyboard and touch.
// Hover changes the same open state, so assistive technology sees the visible state.
document.querySelectorAll('.cl-cart-menu').forEach(function (cart) {
    cart.addEventListener('mouseenter', function () {
        if (window.matchMedia('(hover: hover)').matches) cart.open = true;
    });
    cart.addEventListener('mouseleave', function () {
        if (!cart.contains(document.activeElement)) cart.open = false;
    });
    cart.addEventListener('focusout', function (event) {
        if (!cart.contains(event.relatedTarget)) cart.open = false;
    });
    cart.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            cart.open = false;
            cart.querySelector('summary').focus();
            event.preventDefault();
        }
    });
    document.addEventListener('click', function (event) {
        if (!cart.contains(event.target)) cart.open = false;
    });
});

/* An address's province (2026-10-27): South Africa's nine provinces when the country is South Africa, a text box for
   any other country. Without this script both fields show, each saying when it is used, and the server reads the one
   that fits the chosen country. */
(function () {
    function sync(select) {
        var scope = select.closest('form') || document;
        var southAfrica = select.value === 'ZA';
        scope.querySelectorAll('[data-region-for]').forEach(function (field) {
            field.hidden = (field.getAttribute('data-region-for') === 'ZA') !== southAfrica;
        });
    }
    function initialise(root) {
        (root || document).querySelectorAll('select[data-billing-country]').forEach(sync);
    }
    document.addEventListener('change', function (event) {
        if (event.target instanceof HTMLSelectElement && event.target.matches('select[data-billing-country]')) sync(event.target);
    });
    document.addEventListener('htmx:after:swap', function () { initialise(document); });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { initialise(document); }, {once: true}); else initialise(document);
})();

/* The site notification's countdown (Settings > Site notification, owner 2026-11-05). The server draws the five units and
   says its own time, so a visitor's wrong clock cannot change the count; this only keeps the numbers ticking, finds the
   timer afresh each second because a page changing in place may replace it, and takes the notification away at zero. */
(function () {
    var LENGTHS = {week: 604800, day: 86400, hour: 3600, minute: 60, second: 1};
    function draw(timer) {
        var now = Date.now() / 1000;
        if (timer.cattoSeen !== timer.dataset.siteNow) {
            timer.cattoSeen = timer.dataset.siteNow;
            timer.cattoAhead = Number(timer.dataset.siteNow) - now;
        }
        var left = Math.floor(Number(timer.dataset.siteCountdown) - now - timer.cattoAhead);
        if (left <= 0) {
            var stack = timer.closest('[data-site-notification]');
            if (stack) stack.hidden = true;
            return;
        }
        var shown = false;
        timer.querySelectorAll('[data-unit]').forEach(function (unit) {
            var length = LENGTHS[unit.dataset.unit];
            var count = Math.floor(left / length);
            left -= count * length;
            shown = shown || count > 0 || length === 1;
            unit.hidden = !shown;
            unit.querySelector('strong').textContent = count;
            var name = unit.querySelector('[data-one]');
            name.textContent = count === 1 ? name.dataset.one : name.dataset.many;
        });
    }
    function tick() { document.querySelectorAll('[data-site-countdown]').forEach(draw); }
    setInterval(tick, 1000);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tick); else tick();
})();