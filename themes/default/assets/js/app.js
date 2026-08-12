(() => {
  'use strict';

  // Palette switching is core platform behaviour handled by platform-overrides.js.

  let modalTrigger = null;

  function openModal(id, trigger = null) {
    const modal = document.getElementById(id);
    if (!modal) return;
    document.querySelectorAll('.modal-backdrop.open').forEach(item => {
      item.classList.remove('open');
      item.setAttribute('aria-hidden', 'true');
    });
    modalTrigger = trigger || document.activeElement;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    const focusTarget = modal.querySelector('input:not([type="hidden"]), select, textarea, button, a');
    if (focusTarget) window.setTimeout(() => focusTarget.focus(), 10);
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    const form = modal.querySelector('form');
    if (form) form.reset();
    if (!document.querySelector('.modal-backdrop.open')) {
      document.body.classList.remove('modal-open');
      const trigger = modalTrigger;
      modalTrigger = null;
      if (trigger && typeof trigger.focus === 'function') {
        window.setTimeout(() => trigger.focus(), 10);
      }
    }
  }

  document.querySelectorAll('[data-open-modal]').forEach(button => {
    button.addEventListener('click', event => {
      event.preventDefault();
      openModal(button.dataset.openModal, button);
    });
  });
  document.querySelectorAll('[data-close-modal]').forEach(button => {
    button.addEventListener('click', () => closeModal(button.closest('.modal-backdrop')));
  });
  document.querySelectorAll('.modal-backdrop').forEach(modal => {
    modal.addEventListener('click', event => {
      if (event.target === modal) closeModal(modal);
    });
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
    }
  });

  function activateAdminTab(target) {
    document.querySelectorAll('[data-admin-tab]').forEach(button => {
      button.classList.toggle('active', button.dataset.adminTab === target);
    });
    document.querySelectorAll('[data-admin-panel]').forEach(panel => {
      panel.classList.toggle('active', panel.dataset.adminPanel === target);
    });
    const url = new URL(location.href);
    url.searchParams.set('tab', target);
    history.replaceState({}, '', url);
  }

  document.querySelectorAll('[data-admin-tab]').forEach(button => {
    button.addEventListener('click', () => activateAdminTab(button.dataset.adminTab));
  });

  document.querySelectorAll('[data-tab-target]').forEach(button => {
    button.addEventListener('click', () => {
      const group = button.dataset.tabGroup || 'default';
      const target = button.dataset.tabTarget;
      document.querySelectorAll(`[data-tab-group="${group}"]`).forEach(item => {
        item.classList.toggle('active', item === button);
      });
      document.querySelectorAll(`[data-tab-panel-group="${group}"]`).forEach(panel => {
        panel.classList.toggle('active', panel.dataset.tabPanel === target);
      });
      const url = new URL(location.href);
      url.searchParams.set('tab', target);
      history.replaceState({}, '', url);
    });
  });

  const menu = document.getElementById('mobileMenu');
  const sidebar = document.getElementById('sidebar');
  if (menu && sidebar) {
    menu.addEventListener('click', () => sidebar.classList.toggle('open'));
  }


  // Flash messages are transient UI feedback, not permanent page content.
  document.querySelectorAll('[data-flash-message]').forEach(flash => {
    const close = flash.querySelector('[data-flash-close]');
    const dismiss = () => {
      flash.classList.add('flash-hiding');
      window.setTimeout(() => flash.remove(), 180);
    };
    if (close) close.addEventListener('click', dismiss);
    const type = flash.dataset.flashType || 'info';
    if (type === 'success' || type === 'info') {
      window.setTimeout(dismiss, type === 'success' ? 4500 : 6500);
    }
  });

  document.querySelectorAll('[data-confirm]').forEach(element => {
    element.addEventListener('click', event => {
      if (!confirm(element.dataset.confirm || 'Continue?')) event.preventDefault();
    });
  });

  document.querySelectorAll('[data-filter-input]').forEach(input => {
    input.addEventListener('input', () => {
      const query = input.value.toLowerCase().trim();
      const selector = input.dataset.filterInput;
      document.querySelectorAll(selector).forEach(row => {
        row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
      });
    });
  });

  document.querySelectorAll('[data-theme-file]').forEach(button => {
    button.addEventListener('click', () => {
      const key = button.dataset.themeFile;
      document.querySelectorAll('[data-theme-file]').forEach(item => item.classList.toggle('active', item === button));
      document.querySelectorAll('[data-theme-editor]').forEach(editor => {
        editor.hidden = editor.dataset.themeEditor !== key;
      });
    });
  });

  document.querySelectorAll('[data-category-picker]').forEach(picker => {
    const select = picker.querySelector('[data-category-select]');
    const toggle = picker.querySelector('[data-category-create-toggle]');
    const panel = picker.querySelector('[data-category-create-panel]');
    const cancel = picker.querySelector('[data-category-create-cancel]');
    const create = picker.querySelector('[data-category-create]');
    const name = picker.querySelector('[data-category-name]');
    const description = picker.querySelector('[data-category-description]');
    const error = picker.querySelector('[data-category-error]');
    if (!select || !toggle || !panel || !create || !name) return;

    const setOpen = open => {
      panel.classList.toggle('d-none', !open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) window.setTimeout(() => name.focus(), 10);
      if (!open && error) { error.textContent = ''; error.classList.add('d-none'); }
    };

    toggle.addEventListener('click', () => setOpen(panel.classList.contains('d-none')));
    if (cancel) cancel.addEventListener('click', () => setOpen(false));
    name.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        create.click();
      }
    });

    create.addEventListener('click', async () => {
      const categoryName = name.value.trim();
      if (!categoryName) {
        if (error) { error.textContent = 'Enter a category name.'; error.classList.remove('d-none'); }
        name.focus();
        return;
      }
      create.disabled = true;
      if (error) { error.textContent = ''; error.classList.add('d-none'); }
      const form = new FormData();
      form.append('csrf', panel.dataset.csrf || '');
      form.append('category_name', categoryName);
      form.append('category_description', description ? description.value.trim() : '');
      form.append('is_active', '1');
      try {
        const response = await fetch(panel.dataset.createUrl || '/admin/courses/categories/inline', {
          method: 'POST', body: form, credentials: 'same-origin', headers: {'Accept':'application/json'}
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.category) throw new Error(data.error || 'The category could not be created.');
        const option = document.createElement('option');
        option.value = String(data.category.id);
        option.textContent = String(data.category.name);
        option.selected = true;
        select.append(option);
        name.value = '';
        if (description) description.value = '';
        setOpen(false);
      } catch (exception) {
        if (error) { error.textContent = exception instanceof Error ? exception.message : 'The category could not be created.'; error.classList.remove('d-none'); }
      } finally {
        create.disabled = false;
      }
    });
  });
})();(() => {
  'use strict';

  const dropdowns = [...document.querySelectorAll('details.account-menu, details.rl-account-menu')];
  const closeDropdowns = (except = null) => {
    dropdowns.forEach(menu => {
      if (menu !== except) menu.open = false;
    });
  };

  dropdowns.forEach(menu => {
    menu.addEventListener('toggle', () => {
      if (menu.open) closeDropdowns(menu);
    });
  });

  document.addEventListener('click', event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target || !target.closest('details.account-menu, details.rl-account-menu')) {
      closeDropdowns();
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeDropdowns();
  });
})();

(() => {
  'use strict';

  const table = document.querySelector('[data-activity-table]');
  const feed = table ? table.querySelector('[data-activity-feed]') : null;
  if (!table || !feed) return;

  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[char]));

  const rowHtml = event => `
    <tr data-activity-id="${Number(event.id) || 0}">
      <td class="small">${escapeHtml(event.created_at)}</td>
      <td>${escapeHtml(event.actor_name)}</td>
      <td>${escapeHtml(event.company_name || '—')}</td>
      <td><strong>${escapeHtml(event.event_label)}</strong><div class="small muted">${escapeHtml(event.family_label)}</div></td>
      <td>${escapeHtml(event.subject)}</td>
      <td>${escapeHtml(event.result)}</td>
      <td><span class="badge info">${escapeHtml(event.source_label)}</span></td>
      <td><a class="btn btn-ghost btn-sm" href="/admin/activity/${Number(event.id) || 0}">Details</a></td>
    </tr>`;

  const poll = async () => {
    if (document.hidden) return;
    const url = new URL('/admin/activity/feed', location.origin);
    const current = new URL(location.href);
    ['from','to','family','actor_id','course_id','company_id','q'].forEach(key => {
      const value = current.searchParams.get(key);
      if (value) url.searchParams.set(key, value);
    });
    url.searchParams.set('after_id', table.dataset.lastEventId || '0');
    try {
      const response = await fetch(url, {headers:{'Accept':'application/json'}, credentials:'same-origin'});
      if (!response.ok) return;
      const data = await response.json();
      const events = Array.isArray(data.events) ? data.events : [];
      if (!events.length) return;
      events.slice().reverse().forEach(event => feed.insertAdjacentHTML('afterbegin', rowHtml(event)));
      table.dataset.lastEventId = String(Math.max(...events.map(event => Number(event.id) || 0), Number(table.dataset.lastEventId) || 0));
      const empty = table.querySelector('[data-activity-empty]');
      if (empty) empty.hidden = true;
    } catch (_) {
      // Activity polling is opportunistic; the page remains usable if a poll fails.
    }
  };

  window.setInterval(poll, 15000);
})();


(() => {
  'use strict';

  const passwordInput = document.querySelector('form[action="/admin/settings/mail"] input[name="smtp_password"]');
  if (!(passwordInput instanceof HTMLInputElement)) return;

  const form = passwordInput.closest('form');
  const csrfInput = form ? form.querySelector('input[name="csrf"]') : null;
  if (!(csrfInput instanceof HTMLInputElement) || !csrfInput.value) return;

  const control = document.createElement('div');
  control.className = 'cl-password-control';
  passwordInput.parentNode.insertBefore(control, passwordInput);
  control.appendChild(passwordInput);

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'btn btn-ghost cl-password-toggle';
  toggle.disabled = true;
  toggle.setAttribute('aria-label', 'Show SMTP password');
  toggle.setAttribute('aria-pressed', 'false');
  toggle.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5c5.5 0 9.5 5.1 9.7 5.3a1 1 0 0 1 0 1.4C21.5 11.9 17.5 17 12 17S2.5 11.9 2.3 11.7a1 1 0 0 1 0-1.4C2.5 10.1 6.5 5 12 5Zm0 2c-3.6 0-6.6 2.8-7.6 4 1 1.2 4 4 7.6 4s6.6-2.8 7.6-4c-1-1.2-4-4-7.6-4Zm0 1.5a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7Zm0 2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/></svg><span>Show</span>';
  control.appendChild(toggle);

  const setVisible = visible => {
    passwordInput.type = visible ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
    toggle.setAttribute('aria-label', visible ? 'Hide SMTP password' : 'Show SMTP password');
    const label = toggle.querySelector('span');
    if (label) label.textContent = visible ? 'Hide' : 'Show';
  };

  toggle.addEventListener('click', () => setVisible(passwordInput.type === 'password'));

  const loadEffectivePassword = async () => {
    const body = new URLSearchParams({csrf: csrfInput.value});
    try {
      const response = await fetch('/admin/settings/mail/password', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString(),
        cache: 'no-store'
      });
      if (!response.ok) return;
      const data = await response.json();
      passwordInput.value = typeof data.password === 'string' ? data.password : '';
      passwordInput.dataset.source = data.source === 'database' ? 'database' : 'environment';
      toggle.disabled = false;
      toggle.title = passwordInput.dataset.source === 'database'
        ? 'Show the SMTP password stored in the database override'
        : 'Show the SMTP password loaded from MAILER_DSN in .env';
      const help = passwordInput.closest('.field')?.querySelector('.help');
      if (help) {
        help.innerHTML = passwordInput.dataset.source === 'database'
          ? 'Loaded from the encrypted database override. Saving keeps the SMTP DSN encrypted using <span class="mono">APP_KEY</span>.'
          : 'Loaded from <span class="mono">MAILER_DSN</span> in <span class="mono">.env</span>. Saving this form creates an encrypted database override using <span class="mono">APP_KEY</span>.';
      }
    } catch (_) {
      // Keep the existing safe blank/keep-password behaviour if retrieval fails.
    }
  };

  loadEffectivePassword();
})();
