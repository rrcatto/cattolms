(() => {
  'use strict';

  const ready = callback => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, {once:true});
    else callback();
  };

  /*
    The navigation keeps its scroll position across a page load.

    Every link is an ordinary link, so following one is a full document load and the navigation -
    which is its own scroll container, fixed beside the page - comes back at the top. A reader who
    had scrolled down to reach a link found the menu had moved out from under the pointer, and had
    to scroll back before clicking anything else.

    The position is restored as early as the element exists rather than on DOMContentLoaded, so the
    menu is already where it was when the page first paints instead of jumping afterwards - which
    would be the same complaint with an extra step. Saved on scroll, and again on the way out, so a
    click that leaves immediately still records where the reader was.

    sessionStorage, not localStorage: this is where you were during this visit, and it should not
    outlive the tab. Every access is wrapped, because a browser set to refuse site data throws on
    the property itself rather than returning nothing.
  */
  const NAV_SCROLL_KEY = 'catto-learning:nav-scroll';
  const navScroller = () => document.querySelector('.cl-sidebar, .cl-navigation, [data-theme-nav]');

  const restoreNavScroll = () => {
    const nav = navScroller();
    if (!nav) return false;
    try {
      const saved = Number(sessionStorage.getItem(NAV_SCROLL_KEY) || 0);
      if (saved > 0 && nav.scrollHeight > nav.clientHeight) nav.scrollTop = saved;
    } catch (_) {}

    let pending = 0;
    const remember = () => {
      if (pending) return;
      pending = requestAnimationFrame(() => {
        pending = 0;
        try { sessionStorage.setItem(NAV_SCROLL_KEY, String(nav.scrollTop)); } catch (_) {}
      });
    };
    nav.addEventListener('scroll', remember, {passive:true});
    // pagehide rather than unload: unload is ignored by browsers that keep a page for the back
    // button, and this must be recorded on the way to the next page.
    window.addEventListener('pagehide', () => {
      try { sessionStorage.setItem(NAV_SCROLL_KEY, String(nav.scrollTop)); } catch (_) {}
    });

    return true;
  };

  if (!restoreNavScroll()) ready(restoreNavScroll);

  ready(() => {
    let modalTrigger = null;
    const closeModal = modal => {
      if (!(modal instanceof Element)) return;
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true'); modal.setAttribute('aria-modal', 'false');
      modal.hidden = true;
      if (!document.querySelector('.cl-ui-modal.open')) {
        document.body.classList.remove('modal-open');
        const trigger = modalTrigger; modalTrigger = null;
        if (trigger && typeof trigger.focus === 'function') setTimeout(() => trigger.focus(), 10);
      }
    };
    const openModal = (id, trigger = null) => {
      const modal = document.getElementById(id || ''); if (!modal) return;
      document.querySelectorAll('.cl-ui-modal.open').forEach(closeModal);
      modalTrigger = trigger || document.activeElement;
      modal.hidden = false;
      modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); modal.setAttribute('aria-modal', 'true'); document.body.classList.add('modal-open');
      const focusTarget = modal.querySelector('input:not([type="hidden"]),select,textarea,button,a');
      if (focusTarget) focusTarget.focus();
    };
    const initialiseModals = () => document.querySelectorAll('.cl-ui-modal').forEach(modal => {
      if (!modal.classList.contains('open')) {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('aria-modal', 'false');
      }
    });
    initialiseModals();
    document.addEventListener('htmx:afterSwap', initialiseModals);

    /* Delegated from document rather than bound per element, so a trigger or a close button that
       arrives in an htmx swap works without rebinding. The Switch Company picker replaces its own
       results region on every search, and directly bound handlers would be lost with the markup
       they were attached to. */
    document.addEventListener('click', event => {
      if (!(event.target instanceof Element)) return;
      const opener = event.target.closest('[data-open-modal]');
      if (opener) { event.preventDefault(); openModal(opener.dataset.openModal, opener); return; }
      const closer = event.target.closest('[data-close-modal]');
      if (closer) { closeModal(closer.closest('.cl-ui-modal')); return; }
      if (event.target.classList.contains('cl-ui-modal')) closeModal(event.target);
    });

    /* Escape closes the top-most open modal. Without this a keyboard user who opens the company
       picker has no way out of it except a mouse. */
    document.addEventListener('keydown', event => {
      const open = document.querySelector('.cl-ui-modal.open');
      if (!open) return;
      if (event.key === 'Escape') { event.preventDefault(); closeModal(open); }
      if (event.key === 'Tab') {
        const controls = [...open.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled):not([type="hidden"]),select:not(:disabled),textarea:not(:disabled),[tabindex="0"]')]
          .filter(element => element.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (!first) { event.preventDefault(); return; }
        if (event.shiftKey && (document.activeElement === first || !open.contains(document.activeElement))) {
          event.preventDefault(); last.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !open.contains(document.activeElement))) {
          event.preventDefault(); first.focus();
        }
      }
    });

    document.querySelectorAll('[data-tab-target]').forEach(button => button.addEventListener('click', () => {
      const group = button.dataset.tabGroup || 'default'; const target = button.dataset.tabTarget || '';
      document.querySelectorAll(`[data-tab-group="${CSS.escape(group)}"]`).forEach(item => item.classList.toggle('active', item === button));
      document.querySelectorAll(`[data-tab-panel-group="${CSS.escape(group)}"]`).forEach(panel => panel.classList.toggle('active', panel.dataset.tabPanel === target));
      if (button.dataset.tabHistory !== 'off') {
        const url = new URL(location.href); url.searchParams.set('tab', target); history.replaceState({}, '', url);
      }
    }));

    const dropdownSelector = 'details[data-cl-dropdown]';
    /* Queried at the moment it is needed rather than snapshotted at load. A row's actions menu
       arrives with the rows, and every page turn replaces them, so a list captured once holds
       elements that are no longer in the document and misses every one that is. */
    const closeDropdowns = except => document.querySelectorAll(dropdownSelector)
      .forEach(menu => { if (menu !== except) menu.open = false; });
    /* Delegated from document, for the same reason: `toggle` bubbles from a details element, so one
       listener covers the menus that exist now and the ones swapped in later. */
    document.addEventListener('toggle', event => {
      const menu = event.target instanceof Element ? event.target.closest(dropdownSelector) : null;
      if (menu && menu.open) closeDropdowns(menu);
    }, true);
    document.addEventListener('click', event => { const target = event.target instanceof Element ? event.target : null; if (!target || !target.closest(dropdownSelector)) closeDropdowns(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeDropdowns(); document.querySelectorAll('.cl-ui-modal.open').forEach(closeModal); } });

    const navToggle = document.querySelector('[data-cl-nav-toggle]');
    const primaryNav = document.getElementById('cl-primary-navigation');
    if (navToggle && primaryNav) {
      document.body.dataset.clNavReady = '1';
      const setNavOpen = open => {
        document.body.dataset.clNavOpen = open ? '1' : '0';
        navToggle.setAttribute('aria-expanded', String(open));
      };
      setNavOpen(false);
      navToggle.addEventListener('click', () => setNavOpen(navToggle.getAttribute('aria-expanded') !== 'true'));
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
          setNavOpen(false); navToggle.focus();
        }
      });
      primaryNav.addEventListener('click', event => {
        if (event.target instanceof Element && event.target.closest('a,button[type="submit"]')) setNavOpen(false);
      });
      window.matchMedia('(min-width:1121px)').addEventListener('change', () => setNavOpen(false));
    }

    /* A dismissed message takes its container with it when it was the last one.

       The message was being removed and the .cl-flash-stack around it was not, which is not an empty
       element with no consequences: every theme gives the stack a bottom margin (24px in Gilded
       Noir), so a saved-settings notice that had faded out left that margin sitting above the page
       head, and the header never returned to the top of its container. Nothing on screen explained
       the gap, because the thing that reserved it was gone.

       :empty cannot express this in CSS - the whitespace text nodes between the messages survive
       removal, so the stack is never empty in the selector's sense - which is why the sweep is
       here, where the removal happens. */
    const dropEmptyStack = stack => {
      if (stack && stack.isConnected && stack.querySelector('[data-flash-message]') === null) stack.remove();
    };
    document.querySelectorAll('[data-flash-message]').forEach(flash => {
      const stack = flash.closest('.cl-flash-stack');
      const dismiss = () => { flash.classList.add('flash-hiding'); setTimeout(() => { flash.remove(); dropEmptyStack(stack); }, 180); };
      flash.querySelector('[data-flash-close]')?.addEventListener('click', dismiss);
      const type = flash.dataset.flashType || 'info'; if (type === 'success' || type === 'info') setTimeout(dismiss, type === 'success' ? 4500 : 6500);
    });
    document.querySelectorAll('[data-confirm]').forEach(element => element.addEventListener('click', event => { if (!confirm(element.dataset.confirm || 'Continue?')) event.preventDefault(); }));
    /* Live dataset search needs nothing from this file. The debounce, the abort-in-flight and the
       loading indicator are declared on the input itself, and the submit button now lives inside a
       noscript element, so it is never in the document when scripting is available and is a real
       submit button when it is not.

       It used to be hidden here instead, and that was wrong twice over: this runs once at load, so
       every section arriving later by lazy load or htmx swap brought a button nothing hid, and the
       whole behaviour depended on a script file reaching the browser. Markup that is correct on
       arrival cannot drift out of step with a listener. */

    /* Current-page row filtering. This hides rows already rendered; it is not a search of the
       dataset, so it must never be attached to a paginated table - saying "3 of 25 rows" while
       the dataset holds thousands is a lie about what was searched. Surfaces with real
       server-side search mark themselves data-server-search and are skipped here, and themes are
       asked to stand down on the same marker. */
    document.querySelectorAll('[data-filter-input]').forEach(input => {
      if (input.closest('[data-server-search]')) return;
      input.addEventListener('input', () => { const q = input.value.toLowerCase().trim(); document.querySelectorAll(input.dataset.filterInput || '').forEach(row => { row.hidden = q !== '' && !row.textContent.toLowerCase().includes(q); }); });
    });

    document.querySelectorAll('[data-category-picker]').forEach(picker => {
      const select=picker.querySelector('[data-category-select]'), toggle=picker.querySelector('[data-category-create-toggle]'), panel=picker.querySelector('[data-category-create-panel]'), cancel=picker.querySelector('[data-category-create-cancel]'), create=picker.querySelector('[data-category-create]'), name=picker.querySelector('[data-category-name]'), description=picker.querySelector('[data-category-description]'), error=picker.querySelector('[data-category-error]');
      if (!select || !toggle || !panel || !create || !name) return;
      const setOpen=open=>{ panel.classList.toggle('d-none',!open); toggle.setAttribute('aria-expanded',open?'true':'false'); if(open)setTimeout(()=>name.focus(),10); if(!open&&error){error.textContent='';error.classList.add('d-none');} };
      toggle.addEventListener('click',()=>setOpen(panel.classList.contains('d-none'))); cancel?.addEventListener('click',()=>setOpen(false)); name.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();create.click();}});
      create.addEventListener('click',async()=>{ const categoryName=name.value.trim(); if(!categoryName){if(error){error.textContent='Enter a category name.';error.classList.remove('d-none');}name.focus();return;} create.disabled=true; const form=new FormData(); form.append('csrf',panel.dataset.csrf||''); form.append('category_name',categoryName); form.append('category_description',description?description.value.trim():''); form.append('is_active','1'); try{const response=await fetch(panel.dataset.createUrl||'/admin/courses/categories/inline',{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json().catch(()=>({}));if(!response.ok||!data.category)throw new Error(data.error||'The category could not be created.');const option=document.createElement('option');option.value=String(data.category.id);option.textContent=String(data.category.name);option.selected=true;select.append(option);name.value='';if(description)description.value='';setOpen(false);}catch(exception){if(error){error.textContent=exception instanceof Error?exception.message:'The category could not be created.';error.classList.remove('d-none');}}finally{create.disabled=false;} });
    });

    const passwordInput=document.querySelector('form[action="/admin/settings/mail"] input[name="smtp_password"]');
    if(passwordInput instanceof HTMLInputElement){const form=passwordInput.closest('form'),csrfInput=form?.querySelector('input[name="csrf"]');if(csrfInput instanceof HTMLInputElement&&csrfInput.value){const toggle=document.getElementById('smtp-password-toggle');if(!(toggle instanceof HTMLButtonElement))return;toggle.hidden=false;toggle.addEventListener('click',()=>{const visible=passwordInput.type==='password';passwordInput.type=visible?'text':'password';toggle.querySelector('span').textContent=visible?'Hide':'Show';});fetch('/admin/settings/mail/password',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({csrf:csrfInput.value}).toString(),cache:'no-store'}).then(response=>response.ok?response.json():null).then(data=>{if(!data)return;passwordInput.value=typeof data.password==='string'?data.password:'';toggle.disabled=false;}).catch(()=>{});}}

    /* Entity lookup selection.
       htmx fetches and swaps the results fragment; this only handles the click that commits a
       chosen result into the hidden field the surrounding form actually submits. Delegated
       from document so it works on fragments htmx inserts after page load. */
    document.addEventListener('click', event => {
      const choice = event.target instanceof Element ? event.target.closest('[data-lookup-target]') : null;
      if (choice) {
        const name = choice.getAttribute('data-lookup-target') || '';
        const value = document.getElementById(`${name}-value`);
        const selected = document.getElementById(`${name}-selected`);
        const search = document.getElementById(`${name}-search`);
        const results = document.getElementById(`${name}-results`);
        if (value instanceof HTMLInputElement) value.value = choice.getAttribute('data-lookup-id') || '';
        if (selected) selected.innerHTML = `<strong></strong>`, selected.firstChild.textContent = choice.getAttribute('data-lookup-label') || '';
        if (search instanceof HTMLInputElement) search.value = '';
        if (results) results.innerHTML = '';
        return;
      }
      const clear = event.target instanceof Element ? event.target.closest('[data-lookup-clear]') : null;
      if (clear) {
        const name = clear.getAttribute('data-lookup-clear') || '';
        const value = document.getElementById(`${name}-value`);
        const selected = document.getElementById(`${name}-selected`);
        if (value instanceof HTMLInputElement) value.value = '';
        if (selected) selected.innerHTML = '<span class="muted">Nothing selected</span>';
      }
    });

    /* Truncated table cells carry their full text as a tooltip.

       One row is one line, so anything wider than its column is cut off with an ellipsis. That is
       the right visual answer and the wrong informational one on its own: the reader can see that
       something was shortened and has no way to read it. A tooltip is the whole of the recovery.

       It is applied here rather than written into every template because it is a property of the
       rendered result, not of the markup: whether a cell overflows depends on the column width, the
       viewport and the content, and no template can know. Cells that fit get no tooltip, so
       hovering never produces a box repeating what is already on screen.

       A cell that already carries a title keeps it. Several tables set one deliberately - a person
       row shows the email address behind the name - and a deliberate tooltip always outranks this.

       Runs again after every htmx swap, because a page turn replaces the rows. */
    const applyCellTooltips = root => {
      const scope = root instanceof Element ? root : document;
      scope.querySelectorAll('.cl-ui-table td, .cl-ui-table th').forEach(cell => {
        if (cell.hasAttribute('title')) {
          if (cell.dataset.clAutoTitle !== '1') return;
        }
        const text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
        /* One pixel of slack: sub-pixel layout rounding reports a one-pixel overflow on cells that
           are not actually truncated, which would put a tooltip on most of the table. */
        if (text !== '' && cell.scrollWidth > cell.clientWidth + 1) {
          cell.setAttribute('title', text);
          cell.dataset.clAutoTitle = '1';
        } else if (cell.dataset.clAutoTitle === '1') {
          cell.removeAttribute('title');
          delete cell.dataset.clAutoTitle;
        }
      });
    };
    applyCellTooltips(document);
    document.body.addEventListener('htmx:afterSwap', event => applyCellTooltips(event.target));
    /* And when the viewport changes, because a column that fitted at one width may not at another. */
    let tooltipResize = 0;
    window.addEventListener('resize', () => {
      clearTimeout(tooltipResize);
      tooltipResize = setTimeout(() => applyCellTooltips(document), 200);
    });

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
