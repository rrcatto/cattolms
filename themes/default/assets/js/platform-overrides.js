(() => {
  'use strict';
  const switcher = document.querySelector('[data-core-palette-switcher]');
  if (!switcher) return;
  const theme = switcher.dataset.themeSlug || 'default';
  const serverPalette = switcher.dataset.serverPalette || '';
  const paletteKey = `cattoLearning.palette.${theme}`;
  const hiddenKey = 'cattoLearning.paletteChooser.hidden';
  const dock = switcher.querySelector('[data-palette-dock]');
  const reveal = switcher.querySelector('[data-palette-reveal]');
  const buttons = [...switcher.querySelectorAll('[data-palette-choice]')];
  const valid = new Set(buttons.map(button => button.dataset.paletteChoice));
  const apply = (palette, remember = true) => {
    const selected = valid.has(palette) ? palette : (valid.has(serverPalette) ? serverPalette : buttons[0]?.dataset.paletteChoice || '');
    if (!selected) return;
    document.documentElement.dataset.palette = selected;
    document.body.dataset.palette = selected;
    buttons.forEach(button => {
      const active = button.dataset.paletteChoice === selected;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    if (remember) localStorage.setItem(paletteKey, selected);
  };
  const setHidden = hidden => {
    switcher.classList.toggle('is-hidden', hidden);
    if (dock) dock.hidden = hidden;
    if (reveal) reveal.hidden = !hidden;
    localStorage.setItem(hiddenKey, hidden ? '1' : '0');
  };
  buttons.forEach(button => button.addEventListener('click', () => apply(button.dataset.paletteChoice || '')));
  switcher.querySelector('[data-palette-hide]')?.addEventListener('click', () => setHidden(true));
  reveal?.addEventListener('click', () => setHidden(false));
  const stored = localStorage.getItem(paletteKey);
  apply(stored && valid.has(stored) ? stored : serverPalette, false);
  setHidden(localStorage.getItem(hiddenKey) === '1');
})();

(() => {
  'use strict';
  const tabs = [...document.querySelectorAll('[data-studio-mode]')];
  const panels = [...document.querySelectorAll('[data-studio-panel]')];
  if (tabs.length) tabs.forEach(tab => tab.addEventListener('click', () => {
    const mode = tab.dataset.studioMode;
    tabs.forEach(item => item.classList.toggle('active', item === tab));
    panels.forEach(panel => panel.hidden = panel.dataset.studioPanel !== mode);
  }));

  document.querySelectorAll('[data-hex-input]').forEach(input => {
    const preview = input.parentElement?.querySelector('[data-hex-preview]');
    const update = () => {
      const value = String(input.value || '').trim();
      const valid = /^#[0-9A-Fa-f]{6}$/.test(value);
      input.classList.toggle('is-invalid', !valid);
      if (preview) preview.style.background = valid ? value : 'transparent';
    };
    input.addEventListener('input', update); update();
  });

  const sourceForm = document.querySelector('[data-theme-source-form]');
  const previewButton = document.querySelector('[data-preview-unsaved-source]');
  const frame = document.getElementById('themePreviewFrame');
  if (sourceForm && previewButton && frame) {
    previewButton.addEventListener('click', async () => {
      const data = new FormData(sourceForm);
      data.set('preview_path', document.querySelector('[data-preview-path-select]')?.value || '/');
      previewButton.disabled = true;
      try {
        const response = await fetch(sourceForm.dataset.previewEndpoint, {method:'POST',credentials:'same-origin',headers:{'Accept':'application/json'},body:data});
        const result = await response.json();
        if (!response.ok || !result.url) throw new Error(result.error || 'Unable to preview source.');
        frame.src = result.url;
        document.querySelector('[data-studio-mode="preview"]')?.click();
      } catch (error) { alert(error instanceof Error ? error.message : 'Unable to preview source.'); }
      finally { previewButton.disabled = false; }
    });
  }

  document.querySelector('[data-preview-path-select]')?.addEventListener('change', event => {
    if (!frame) return; const path = event.target.value || '/'; const theme = frame.dataset.themeSlug || '';
    frame.src = path + (path.includes('?') ? '&' : '?') + 'theme_preview=' + encodeURIComponent(theme);
  });
})();

(() => {
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

(() => {
  'use strict';
  const form = document.querySelector('[data-semantic-colour-form]');
  if (!(form instanceof HTMLFormElement)) return;

  const frame = document.getElementById('themePreviewFrame');
  const designPaletteKey = String(form.querySelector('input[name="design_palette_key"]')?.value || '');
  const roleRows = [...form.querySelectorAll('.theme-role-row[data-role]')];
  const designPaletteSelect = form.querySelector('[data-design-palette-select]');
  const autoButton = form.querySelector('[data-auto-map-palette]');

  const paletteCard = () => document.querySelector(`[data-theme-palette-card="${CSS.escape(designPaletteKey)}"]`);
  const paletteColours = () => {
    const card = paletteCard();
    if (!card) return [];
    return [...card.querySelectorAll('[data-palette-colour-input]')].map(input => {
      const value = String(input.value || '').trim().toUpperCase();
      return /^#[0-9A-F]{6}$/.test(value) ? value : '#000000';
    });
  };

  const contrast = hex => {
    const match = /^#([0-9A-F]{2})([0-9A-F]{2})([0-9A-F]{2})$/i.exec(hex || '');
    if (!match) return '#111827';
    const values = match.slice(1).map(part => parseInt(part, 16) / 255).map(value => value <= .03928 ? value / 12.92 : Math.pow((value + .055) / 1.055, 2.4));
    const lum = .2126 * values[0] + .7152 * values[1] + .0722 * values[2];
    const white = 1.05 / (lum + .05);
    const dark = (lum + .05) / .0596;
    return white >= dark ? '#FFFFFF' : '#111827';
  };

  const rowByRole = role => roleRows.find(row => row.dataset.role === role) || null;
  const resolveRole = (role, seen = new Set()) => {
    if (seen.has(role)) return '#000000';
    seen.add(role);
    const row = rowByRole(role);
    if (!row) return '#000000';
    const source = row.querySelector('[data-role-source]');
    const hex = row.querySelector('[data-role-hex]');
    const value = String(source?.value || 'custom');
    const colours = paletteColours();
    const paletteMatch = /^palette:([0-4])$/.exec(value);
    if (paletteMatch) return colours[Number(paletteMatch[1])] || '#000000';
    const autoMatch = /^auto:([a-z_]+)$/.exec(value);
    if (autoMatch) return contrast(resolveRole(autoMatch[1], seen));
    const custom = String(hex?.value || '').trim().toUpperCase();
    return /^#[0-9A-F]{6}$/.test(custom) ? custom : '#000000';
  };

  const updateSourceLabels = () => {
    const colours = paletteColours();
    roleRows.forEach(row => {
      const select = row.querySelector('[data-role-source]');
      if (!(select instanceof HTMLSelectElement)) return;
      for (let i = 0; i < 5; i++) {
        const option = select.querySelector(`option[value="palette:${i}"]`);
        if (option) option.textContent = `Colour ${i + 1} · ${colours[i] || '#000000'}`;
      }
    });
  };

  const updateRoleRows = () => {
    roleRows.forEach(row => {
      const source = row.querySelector('[data-role-source]');
      const preview = row.querySelector('[data-role-preview]');
      const hex = row.querySelector('[data-role-hex]');
      if (!(source instanceof HTMLSelectElement) || !(hex instanceof HTMLInputElement)) return;
      const isCustom = source.value === 'custom';
      hex.readOnly = !isCustom;
      if (!isCustom) hex.value = resolveRole(String(row.dataset.role || ''));
      const resolved = resolveRole(String(row.dataset.role || ''));
      if (preview instanceof HTMLElement) preview.style.background = resolved;
      hex.classList.toggle('is-invalid', !/^#[0-9A-F]{6}$/i.test(hex.value));
    });
  };

  const resolvedRoles = () => {
    const roles = {};
    roleRows.forEach(row => { roles[String(row.dataset.role || '')] = resolveRole(String(row.dataset.role || '')); });
    return roles;
  };

  const applyToPreview = () => {
    if (!(frame instanceof HTMLIFrameElement) || !frame.contentDocument) return;
    const doc = frame.contentDocument;
    if (!doc.documentElement || !doc.body) return;
    const roles = resolvedRoles();
    const props = {
      '--cl-page-background': roles.page_background,
      '--cl-surface': roles.surface,
      '--cl-primary': roles.primary,
      '--cl-secondary': roles.secondary,
      '--cl-accent': roles.accent,
      '--cl-heading-text': roles.heading_text,
      '--cl-body-text': roles.body_text,
      '--cl-links': roles.links,
      '--cl-nav-background': roles.navigation_background,
      '--cl-nav-text': roles.navigation_text,
      '--cl-nav-active': roles.navigation_active,
      '--cl-breadcrumb-background': roles.breadcrumb_background,
      '--cl-breadcrumb-text': roles.breadcrumb_text,
      '--cl-hero-background': roles.hero_background,
      '--cl-hero-overlay': roles.hero_overlay,
      '--cl-hero-text': roles.hero_text,
      '--cl-button-primary-background': roles.button_primary_background,
      '--cl-button-primary-text': roles.button_primary_text,
      '--bg': roles.page_background,
      '--bg2': `color-mix(in srgb, ${roles.page_background} 88%, ${roles.secondary})`,
      '--bg-2': `color-mix(in srgb, ${roles.page_background} 88%, ${roles.secondary})`,
      '--bg3': `color-mix(in srgb, ${roles.page_background} 78%, ${roles.secondary})`,
      '--bg-3': `color-mix(in srgb, ${roles.page_background} 78%, ${roles.secondary})`,
      '--paper': roles.surface,
      '--paper2': `color-mix(in srgb, ${roles.surface} 86%, ${roles.secondary})`,
      '--paper-2': `color-mix(in srgb, ${roles.surface} 86%, ${roles.secondary})`,
      '--ink': roles.body_text,
      '--on-dark': roles.body_text,
      '--accent': roles.primary,
      '--accent-hi': roles.accent,
      '--accent-lo': roles.secondary,
      '--line': `color-mix(in srgb, ${roles.body_text} 20%, ${roles.page_background})`,
      '--muted': `color-mix(in srgb, ${roles.body_text} 62%, ${roles.page_background})`
    };
    doc.documentElement.dataset.palette = designPaletteKey;
    doc.body.dataset.palette = designPaletteKey;
    Object.entries(props).forEach(([name, value]) => {
      doc.documentElement.style.setProperty(name, value || '');
      doc.body.style.setProperty(name, value || '');
    });
  };

  const refresh = () => { updateSourceLabels(); updateRoleRows(); applyToPreview(); };

  roleRows.forEach(row => {
    row.querySelector('[data-role-source]')?.addEventListener('change', event => {
      const hex = row.querySelector('[data-role-hex]');
      if (event.target instanceof HTMLSelectElement && event.target.value === 'custom' && hex instanceof HTMLInputElement) {
        hex.readOnly = false;
        hex.focus();
        hex.select();
      }
      refresh();
    });
    row.querySelector('[data-role-hex]')?.addEventListener('input', refresh);
  });
  paletteCard()?.querySelectorAll('[data-palette-colour-input]').forEach(input => input.addEventListener('input', refresh));

  autoButton?.addEventListener('click', () => {
    roleRows.forEach(row => {
      const suggested = String(row.dataset.suggestedSource || '');
      const select = row.querySelector('[data-role-source]');
      if (suggested && select instanceof HTMLSelectElement && [...select.options].some(option => option.value === suggested)) select.value = suggested;
    });
    refresh();
  });

  designPaletteSelect?.addEventListener('change', event => {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const slug = String(event.target.dataset.themeSlug || form.dataset.themeSlug || '');
    if (!slug) return;
    location.href = `/admin/themes/${encodeURIComponent(slug)}?design_palette=${encodeURIComponent(event.target.value)}#colours`;
  });

  frame?.addEventListener('load', refresh);
  refresh();
})();
