(() => {
  'use strict';

  const ready = callback => {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback, {once:true});
    else callback();
  };

  ready(() => {
    let modalTrigger = null;
    const closeModal = modal => {
      if (!(modal instanceof Element)) return;
      modal.classList.remove('open');
      modal.setAttribute('aria-hidden', 'true');
      modal.hidden = true;
      if (!document.querySelector('.modal-backdrop.open')) {
        document.body.classList.remove('modal-open');
        const trigger = modalTrigger; modalTrigger = null;
        if (trigger && typeof trigger.focus === 'function') setTimeout(() => trigger.focus(), 10);
      }
    };
    const openModal = (id, trigger = null) => {
      const modal = document.getElementById(id || ''); if (!modal) return;
      document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
      modalTrigger = trigger || document.activeElement;
      modal.hidden = false;
      modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); document.body.classList.add('modal-open');
      const focusTarget = modal.querySelector('input:not([type="hidden"]),select,textarea,button,a');
      if (focusTarget) setTimeout(() => focusTarget.focus(), 10);
    };
    document.querySelectorAll('.modal-backdrop').forEach(modal => { if (!modal.classList.contains('open')) { modal.hidden = true; modal.setAttribute('aria-hidden', 'true'); } });

    /* Delegated from document rather than bound per element, so a trigger or a close button that
       arrives in an htmx swap works without rebinding. The Switch Company picker replaces its own
       results region on every search, and directly bound handlers would be lost with the markup
       they were attached to. */
    document.addEventListener('click', event => {
      if (!(event.target instanceof Element)) return;
      const opener = event.target.closest('[data-open-modal]');
      if (opener) { event.preventDefault(); openModal(opener.dataset.openModal, opener); return; }
      const closer = event.target.closest('[data-close-modal]');
      if (closer) { closeModal(closer.closest('.modal-backdrop')); return; }
      if (event.target.classList.contains('modal-backdrop')) closeModal(event.target);
    });

    /* Escape closes the top-most open modal. Without this a keyboard user who opens the company
       picker has no way out of it except a mouse. */
    document.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      const open = document.querySelector('.modal-backdrop.open');
      if (open) { event.preventDefault(); closeModal(open); }
    });

    document.querySelectorAll('[data-tab-target]').forEach(button => button.addEventListener('click', () => {
      const group = button.dataset.tabGroup || 'default'; const target = button.dataset.tabTarget || '';
      document.querySelectorAll(`[data-tab-group="${CSS.escape(group)}"]`).forEach(item => item.classList.toggle('active', item === button));
      document.querySelectorAll(`[data-tab-panel-group="${CSS.escape(group)}"]`).forEach(panel => panel.classList.toggle('active', panel.dataset.tabPanel === target));
      if (button.dataset.tabHistory !== 'off') {
        const url = new URL(location.href); url.searchParams.set('tab', target); history.replaceState({}, '', url);
      }
    }));

    const dropdownSelector = 'details.account-menu,details.rl-account-menu,details[data-cl-dropdown]';
    const dropdowns = [...document.querySelectorAll(dropdownSelector)];
    const closeDropdowns = except => dropdowns.forEach(menu => { if (menu !== except) menu.open = false; });
    dropdowns.forEach(menu => menu.addEventListener('toggle', () => { if (menu.open) closeDropdowns(menu); }));
    document.addEventListener('click', event => { const target = event.target instanceof Element ? event.target : null; if (!target || !target.closest(dropdownSelector)) closeDropdowns(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeDropdowns(); document.querySelectorAll('.modal-backdrop.open').forEach(closeModal); } });

    const mobileMenu = document.getElementById('mobileMenu'); const sidebar = document.getElementById('sidebar');
    if (mobileMenu && sidebar) mobileMenu.addEventListener('click', () => sidebar.classList.toggle('open'));

    document.querySelectorAll('[data-flash-message]').forEach(flash => {
      const dismiss = () => { flash.classList.add('flash-hiding'); setTimeout(() => flash.remove(), 180); };
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

    const activityTable=document.querySelector('[data-activity-table]'), activityFeed=activityTable?.querySelector('[data-activity-feed]');
    if(activityTable&&activityFeed){const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])); const poll=async()=>{if(document.hidden)return;const url=new URL('/admin/activity/feed',location.origin),current=new URL(location.href);['from','to','family','actor_id','course_id','company_id','q'].forEach(key=>{const value=current.searchParams.get(key);if(value)url.searchParams.set(key,value);});url.searchParams.set('after_id',activityTable.dataset.lastEventId||'0');try{const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin'});if(!response.ok)return;const data=await response.json();const events=Array.isArray(data.events)?data.events:[];events.slice().reverse().forEach(event=>activityFeed.insertAdjacentHTML('afterbegin',`<tr data-activity-id="${Number(event.id)||0}"><td class="small">${escapeHtml(event.created_at)}</td><td>${escapeHtml(event.actor_name)}</td><td>${escapeHtml(event.company_name||'—')}</td><td><strong>${escapeHtml(event.event_label)}</strong><div class="small muted">${escapeHtml(event.family_label)}</div></td><td>${escapeHtml(event.subject)}</td><td>${escapeHtml(event.result)}</td><td class="small">${escapeHtml(event.ip_address||'—')}</td><td class="small">${escapeHtml(event.geo_location||'—')}</td><td><span class="badge info">${escapeHtml(event.source_label)}</span></td><td><a class="btn btn-ghost btn-sm" href="/admin/activity/${Number(event.id)||0}">Details</a></td></tr>`));if(events.length){activityTable.dataset.lastEventId=String(Math.max(...events.map(event=>Number(event.id)||0),Number(activityTable.dataset.lastEventId)||0));activityTable.querySelector('[data-activity-empty]')?.setAttribute('hidden','hidden');}}catch(_){}};setInterval(poll,15000);}

    const passwordInput=document.querySelector('form[action="/admin/settings/mail"] input[name="smtp_password"]');
    if(passwordInput instanceof HTMLInputElement){const form=passwordInput.closest('form'),csrfInput=form?.querySelector('input[name="csrf"]');if(csrfInput instanceof HTMLInputElement&&csrfInput.value){const control=document.createElement('div');control.className='cl-password-control';passwordInput.parentNode.insertBefore(control,passwordInput);control.appendChild(passwordInput);const toggle=document.createElement('button');toggle.type='button';toggle.className='btn btn-ghost cl-password-toggle';toggle.disabled=true;toggle.textContent='Show';control.appendChild(toggle);toggle.addEventListener('click',()=>{const visible=passwordInput.type==='password';passwordInput.type=visible?'text':'password';toggle.textContent=visible?'Hide':'Show';});fetch('/admin/settings/mail/password',{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({csrf:csrfInput.value}).toString(),cache:'no-store'}).then(response=>response.ok?response.json():null).then(data=>{if(!data)return;passwordInput.value=typeof data.password==='string'?data.password:'';toggle.disabled=false;}).catch(()=>{});}}

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

    const preview = new URL(location.href).searchParams.get('theme_preview');
    const paletteUrl = new URL('/theme/palette', location.origin); if (preview) paletteUrl.searchParams.set('theme_preview', preview);
    fetch(paletteUrl,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'}).then(response=>response.ok?response.json():null).then(config=>{
      if(!config?.enabled||!Array.isArray(config.palettes)||config.palettes.length<1)return;
      const themeKey=String(config.theme_key||'theme'),storagePrefix=`catto-learning:${themeKey}:palette`,paletteCount=config.palettes.length;let selected=0,hidden=false;try{selected=Number(localStorage.getItem(storagePrefix)||0);hidden=localStorage.getItem(`${storagePrefix}:hidden`)==='1';}catch(_){} if(!Number.isInteger(selected)||selected<0||selected>=paletteCount)selected=0;
      const apply=index=>{selected=index;document.body.dataset.clPaletteManaged='1';document.body.dataset.clPalette=String(index);document.querySelectorAll('.cl-palette-option').forEach((button,i)=>{button.classList.toggle('active',i===index);button.setAttribute('aria-pressed',i===index?'true':'false');});try{localStorage.setItem(storagePrefix,String(index));}catch(_){}};
      apply(selected);
      if(config.switcher_visible===false||paletteCount<2)return;
      const dock=document.createElement('aside');dock.className='cl-palette-switcher';dock.setAttribute('aria-label','Colour palette');dock.innerHTML='<div class="cl-palette-head"><strong>Colour palette</strong><button class="cl-palette-hide" type="button">Hide</button></div><div class="cl-palette-options"></div>';const options=dock.querySelector('.cl-palette-options');
      config.palettes.forEach((palette,index)=>{const button=document.createElement('button');button.type='button';button.className='cl-palette-option';button.setAttribute('aria-pressed','false');const name=document.createElement('span');name.className='cl-palette-name';name.textContent=String(palette.name||`Palette ${index+1}`);const swatches=document.createElement('span');swatches.className='cl-palette-swatches';(palette.colors||[]).forEach(colour=>{const swatch=document.createElement('i');swatch.className='cl-palette-swatch';swatch.style.background=String(colour);swatches.appendChild(swatch);});button.append(name,swatches);button.addEventListener('click',()=>apply(index));options.appendChild(button);});
      const show=document.createElement('button');show.type='button';show.className='cl-palette-show';show.textContent='Colours';const setHidden=value=>{hidden=value;dock.hidden=value;show.hidden=!value;try{localStorage.setItem(`${storagePrefix}:hidden`,value?'1':'0');}catch(_){}};dock.querySelector('.cl-palette-hide').addEventListener('click',()=>setHidden(true));show.addEventListener('click',()=>setHidden(false));document.body.append(dock,show);apply(selected);setHidden(hidden);
    }).catch(()=>{});
  });
})();