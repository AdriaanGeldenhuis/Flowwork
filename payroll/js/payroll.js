// /payroll/js/payroll.js — shared helpers for every Payroll page.
// window.FWP: $, $$, esc, icon, api (JSON over /payroll/api with the CSRF
// header), toast, modal / dialog, form errors, money and dates, copy,
// initials / hue for monograms, the theme switch, the menu (switch company,
// sign out), the narrow-screen drawer, the "Find a person or run" box and
// the settings form engine (dirty tracking, the unsaved-changes bar,
// validation, Ctrl/⌘ S).
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#${/^(ic|iq|ih|if|ia|ip|i)-/.test(name) ? name : 'i-' + name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const BOOT = JSON.parse(($('#payBoot') || {}).textContent || '{}');

  // ------------------------------------------------------------------ API
  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data || {}; }
  }
  async function request(method, url, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (method !== 'GET') { headers['X-CSRF-Token'] = csrf(); headers['Content-Type'] = 'application/json'; }
    let res;
    try {
      res = await fetch(url, { method, headers, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined });
    } catch (e) {
      throw new ApiError('Could not reach Flowwork. Check your connection and try again.', 0);
    }
    let data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (res.status === 401) {
      location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search + location.hash);
      throw new ApiError('Your session has expired.', 401);
    }
    if (res.status === 419) throw new ApiError('Your security token expired — reload the page and try again.', 419);
    if (!res.ok || !data || data.ok === false) {
      throw new ApiError((data && data.error) || `Something went wrong (${res.status}).`, res.status, data);
    }
    return data;
  }
  const qs = (params) => new URLSearchParams(Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
  const api = {
    get: (path, params) => { const q = qs(params); return request('GET', '/payroll/api/' + path + (q ? '?' + q : '')); },
    post: (path, body) => request('POST', '/payroll/api/' + path, body || {}),
  };

  // ---------------------------------------------------------------- toasts
  function toast(message, opts = {}) {
    let host = $('#toasts');
    if (!host) { host = document.createElement('div'); host.id = 'toasts'; host.className = 'fwm-toasts'; host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'fwm-toast' + (opts.type === 'error' ? ' fwm-toast--error' : '');
    el.setAttribute('role', opts.type === 'error' ? 'alert' : 'status');
    el.innerHTML = `<span class="fwm-toast__msg"></span>${opts.action ? `<button class="fwm-toast__action" type="button">${esc(opts.action.label)}</button>` : ''}<button class="fwm-toast__close" type="button" aria-label="Dismiss">${icon('x', 'i--sm')}</button>`;
    $('.fwm-toast__msg', el).textContent = message;
    host.appendChild(el);
    while (host.children.length > 3) host.firstElementChild.remove();
    let timer = null;
    const close = () => {
      if (!el.isConnected) return;
      clearTimeout(timer);
      el.classList.add('is-leaving');
      setTimeout(() => el.remove(), 200);
    };
    if (opts.action) $('.fwm-toast__action', el).addEventListener('click', () => { close(); opts.action.run(); });
    $('.fwm-toast__close', el).addEventListener('click', close);
    const ms = opts.timeout != null ? opts.timeout : (opts.type === 'error' ? 8000 : (opts.action ? 7000 : 4000));
    if (ms > 0) timer = setTimeout(close, ms);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => { if (ms > 0) timer = setTimeout(close, 2500); });
    return { close };
  }
  const toastError = (e) => toast(e && e.message ? e.message : String(e), { type: 'error' });

  // ---------------------------------------------------------------- popover
  let activePop = null;
  function closePop() { if (activePop) { const p = activePop; activePop = null; p.el.remove(); if (p.anchor) p.anchor.setAttribute('aria-expanded', 'false'); } }
  function popover(anchor, html, opts = {}) {
    closePop();
    const el = document.createElement('div');
    el.className = 'fwm-pop ' + (opts.cls || '');
    el.setAttribute('role', 'menu');
    el.innerHTML = html;
    document.body.appendChild(el);
    const r = anchor.getBoundingClientRect();
    const pw = el.offsetWidth, ph = el.offsetHeight;
    let left = opts.align === 'end' ? r.right - pw : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
    let top = r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
    el.style.left = left + 'px';
    el.style.top = top + 'px';
    activePop = { el, anchor };
    anchor.setAttribute('aria-expanded', 'true');
    el.addEventListener('keydown', (ev) => {
      const items = $$('a, button:not([disabled])', el);
      const i = items.indexOf(document.activeElement);
      if (ev.key === 'ArrowDown' && items.length) { ev.preventDefault(); (items[i + 1] || items[0]).focus(); }
      if (ev.key === 'ArrowUp' && items.length) { ev.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
    });
    const first = $('a, button:not([disabled])', el);
    if (first && opts.focus !== false) first.focus({ preventScroll: true });
    return el;
  }
  document.addEventListener('pointerdown', (e) => {
    if (activePop && !activePop.el.contains(e.target) && !activePop.anchor.contains(e.target)) closePop();
  });
  window.addEventListener('resize', closePop);

  // ----------------------------------------------------------------- modals
  let dlgSeq = 0;
  const openModals = [];
  function modal(html, opts = {}) {
    const m = document.createElement('div');
    m.className = 'fwm-modal';
    m.hidden = true;
    m.innerHTML = `<div class="fwm-dialog ${opts.wide ? 'fwm-dialog--wide' : ''}" role="dialog" aria-modal="true" style="${opts.width ? 'width:min(' + opts.width + 'px,100%)' : ''}">${html}</div>`;
    const h = $('h2', m);
    if (h) { h.id = h.id || 'fwpDlg' + (++dlgSeq); $('[role=dialog]', m).setAttribute('aria-labelledby', h.id); }
    document.body.appendChild(m);
    m.addEventListener('click', (e) => {
      if (e.target === m || e.target.closest('[data-close]')) closeModal(m);
    });
    return m;
  }
  function openModal(m) {
    m._returnFocus = document.activeElement;
    m.hidden = false;
    openModals.push(m);
    document.body.style.overflow = 'hidden';
    const f = $('[autofocus]', m) || $('input:not([type=hidden]):not([disabled]), select, textarea, button.btn--primary', m);
    if (f) setTimeout(() => f.focus({ preventScroll: true }), 30);
  }
  function closeModal(m) {
    if (!m || m.hidden) return;
    if (m._beforeClose && m._beforeClose() === false) return;
    m.hidden = true;
    const i = openModals.indexOf(m);
    if (i >= 0) openModals.splice(i, 1);
    if (!openModals.length) document.body.style.overflow = '';
    if (m._onClose) m._onClose();
    if (m._returnFocus && m._returnFocus.focus) m._returnFocus.focus({ preventScroll: true });
    if (!m.dataset.keep) setTimeout(() => m.remove(), 10);
  }
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (activePop) { const a = activePop.anchor; closePop(); if (a) a.focus(); return; }
    if (openModals.length) { e.preventDefault(); closeModal(openModals[openModals.length - 1]); }
  });
  // Keep Tab inside the top dialog
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab' || !openModals.length) return;
    const m = openModals[openModals.length - 1];
    const f = $$('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', m).filter((x) => x.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /** Promise dialog: a confirmation, or a text input ({input: {label, value, placeholder, type}}). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, input = null, typeToConfirm = null }) {
    return new Promise((resolve) => {
      const needsType = typeToConfirm != null;
      const m = modal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body" novalidate>${body ? `<p class="fwm-dialog__lead">${body}</p>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}<input class="input" id="dlgInput" type="${esc(input.type || 'text')}" maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autocomplete="off" autofocus></div>` : ''}
          ${needsType ? `<div class="field"><label class="label" for="dlgType">Type <b>${esc(typeToConfirm)}</b> to confirm</label><input class="input" id="dlgType" autocomplete="off" autofocus></div>` : ''}
        </form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn ${danger ? 'btn--danger-solid' : 'btn--primary'}" type="button" data-act="yes"${needsType ? ' disabled' : ''}>${esc(confirm)}</button></div>`, { width: 480 });
      let done = false;
      const value = () => (input ? $('#dlgInput', m).value.trim() : true);
      const finish = (v) => { if (done) return; done = true; closeModal(m); resolve(v); };
      m._onClose = () => { if (!done) { done = true; resolve(input ? null : false); } };
      m.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        finish(b.dataset.act === 'yes' ? value() : (input ? null : false));
      });
      if (needsType) {
        const t = $('#dlgType', m), yes = $('[data-act=yes]', m);
        t.addEventListener('input', () => { yes.disabled = t.value.trim() !== typeToConfirm; });
      }
      $('form', m).addEventListener('submit', (e) => {
        e.preventDefault();
        if (needsType && $('#dlgType', m).value.trim() !== typeToConfirm) return;
        finish(value());
      });
      openModal(m);
    });
  }

  // ------------------------------------------------------------- small helpers
  async function copy(text, label = 'Copied') {
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (x) { ta.remove(); toast('Could not copy — select it and copy by hand.', { type: 'error' }); return false; }
      ta.remove();
    }
    toast(label);
    return true;
  }
  function initials(name) {
    const n = String(name || '').trim();
    if (!n) return '?';
    if (n.includes('@')) return n[0].toUpperCase();
    const w = n.split(/\s+/);
    return (w[0][0] + (w.length > 1 ? w[w.length - 1][0] : '')).toUpperCase();
  }
  function hue(s) {
    let h = 0;
    for (const ch of String(s || '').toLowerCase()) h = (h * 31 + ch.codePointAt(0)) % 360;
    return h;
  }
  function busy(btn, on) {
    if (!btn) return;
    btn.classList.toggle('is-busy', !!on);
    btn.disabled = !!on;
  }
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fmtDate(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : null;
    if (!d || isNaN(d)) return '';
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (d.getFullYear() !== new Date().getFullYear() ? ' ' + d.getFullYear() : '');
  }

  // ------------------------------------------------------- theme, menu, drawer
  function initChrome() {
    const body = document.body;
    const themeBtn = $('#themeToggle');
    if (themeBtn) themeBtn.addEventListener('click', () => {
      const t = body.dataset.theme === 'dark' ? 'light' : 'dark';
      body.dataset.theme = t;
      const use = $('use', themeBtn);
      if (use) use.setAttribute('href', t === 'dark' ? '#i-sun' : '#i-moon');
      themeBtn.setAttribute('data-tip', t === 'dark' ? 'Light theme' : 'Dark theme');
      const meta = $('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', t === 'dark' ? '#0d0a09' : '#f2ece3');
      document.cookie = 'fw_theme=' + t + ';max-age=' + (365 * 86400) + ';path=/;SameSite=Lax';
    });

    const kebab = $('#kebabBtn');
    if (kebab) kebab.addEventListener('click', () => {
      if (activePop && activePop.anchor === kebab) return closePop();
      const cos = BOOT.companies || [];
      popover(kebab, `
        ${cos.length > 1 ? `<div class="fwm-pop__title">Switch company</div>
          ${cos.map((c) => `<button type="button" class="fwm-pop__item fwp-menu__company" role="menuitem" data-fw-switch-company="${c.id}"${c.active ? ' disabled aria-current="true"' : ''}>
            <span class="fwm-avatar" style="--h:${hue(c.name)}">${esc(initials(c.name))}</span><span class="fwp-menu__name">${esc(c.name)}</span>${c.active ? icon('check', 'i--sm') : ''}</button>`).join('')}
          <div class="fwm-pop__sep" role="separator"></div>` : ''}
        <a class="fwm-pop__item" role="menuitem" href="/home.php">${icon('home')}Flowwork home</a>
        <a class="fwm-pop__item" role="menuitem" href="/account/">${icon('ih-devices')}Signed-in devices</a>
        <button class="fwm-pop__item" role="menuitem" type="button" data-keys>${icon('keyboard')}Keyboard shortcuts</button>
        <div class="fwm-pop__sep" role="separator"></div>
        <a class="fwm-pop__item" role="menuitem" href="/logout.php">${icon('ih-logout')}Sign out</a>`, { align: 'end', cls: 'fwp-menu' });
      const k = $('[data-keys]', activePop.el);
      if (k) k.addEventListener('click', () => { closePop(); shortcuts(); });
    });

    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
    $$('#side a').forEach((a) => a.addEventListener('click', () => toggleDrawer(false)));

    // g + letter jumps between the busiest pages
    let gAt = 0;
    document.addEventListener('keydown', (e) => {
      const t = e.target;
      const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
      if (typing || e.ctrlKey || e.metaKey || e.altKey || openModals.length) return;
      if (e.key === '?') { e.preventDefault(); shortcuts(); return; }
      if (e.key === 'g') { gAt = Date.now(); return; }
      if (Date.now() - gAt < 1200) {
        const map = { o: '', r: 'runs.php', e: 'employees.php', i: 'payitems.php', s: 'reports.php', t: 'settings.php' };
        if (map[e.key] !== undefined) { e.preventDefault(); gAt = 0; location.href = '/payroll/' + map[e.key]; }
      }
    });
  }
  function toggleDrawer(open) {
    document.body.classList.toggle('drawer-open', open);
    let scrim = $('.fwm-scrim');
    if (open && !scrim) {
      scrim = document.createElement('div');
      scrim.className = 'fwm-scrim';
      scrim.addEventListener('click', () => toggleDrawer(false));
      document.body.appendChild(scrim);
    } else if (!open && scrim) scrim.remove();
  }
  function shortcuts() {
    const rows = [['/ or Ctrl K', 'Find a person or run'], ['Ctrl S', 'Save changes'], ['g then o', 'Overview'], ['g then r', 'Pay runs'], ['g then e', 'Employees'],
      ['g then i', 'Pay items'], ['g then s', 'SARS & reports'], ['g then t', 'Settings'], ['Esc', 'Close'], ['?', 'This list']];
    const m = modal(`<div class="fwm-dialog__head"><h2>Keyboard shortcuts</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fwm-kbd-grid">${rows.map(([k, l]) => `<div class="fwm-kbd-row"><span>${esc(l)}</span><span>${k.split(' then ').map((x) => `<kbd class="fwm-kbd">${esc(x)}</kbd>`).join(' then ')}</span></div>`).join('')}</div></div>`, { width: 460 });
    openModal(m);
  }

  // ------------------------------------------------------ "Find a setting"
  function initJump() {
    const input = $('#jumpInput');
    const list = $('#jumpList');
    if (!input || !list) return;
    const items = (BOOT.jump || []).filter((d) => d && d.name && d.href);
    let shown = [];
    let active = -1;
    const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const score = (d, q) => {
      const name = norm(d.name);
      if (name.startsWith(q)) return 0;
      if (name.split(/[\s&/,()-]+/).some((w) => w.startsWith(q))) return 1;
      if (name.includes(q)) return 2;
      if (norm(d.kw + ' ' + d.hint + ' ' + d.group).includes(q)) return 3;
      return -1;
    };
    const mark = (name, q) => {
      if (!q) return esc(name);
      const i = norm(name).indexOf(q);
      return i < 0 ? esc(name) : esc(name.slice(0, i)) + '<mark>' + esc(name.slice(i, i + q.length)) + '</mark>' + esc(name.slice(i + q.length));
    };
    function render() {
      const q = norm(input.value.trim());
      shown = q
        ? items.map((d) => ({ d, s: score(d, q) })).filter((x) => x.s >= 0).sort((a, b) => a.s - b.s || (a.d.page ? -1 : 0)).slice(0, 12).map((x) => x.d)
        : items.filter((d) => d.page);
      active = shown.length ? 0 : -1;
      let html = '';
      let group = null;
      shown.forEach((d, i) => {
        if (!q && d.group !== group) { group = d.group; html += `<div class="fwp-jump__group" role="presentation">${esc(group)}</div>`; }
        html += `<div class="fwp-opt" role="option" id="jumpOpt${i}" data-i="${i}" aria-selected="${i === active}">
          <span class="fwp-opt__icon">${icon(d.icon || 'settings')}</span>
          <span class="fwp-opt__text"><span class="fwp-opt__name">${mark(d.name, q)}</span><span class="fwp-opt__hint">${esc(q && !d.page ? d.group + (d.hint ? ' · ' + d.hint : '') : d.hint || '')}</span></span>
          <span class="fwp-opt__enter" aria-hidden="true">↵</span></div>`;
      });
      list.innerHTML = html || `<div class="fwp-jump__none">Nothing called “${esc(input.value.trim())}”.</div>`;
      setActive(active);
    }
    function setActive(i) {
      active = i;
      $$('.fwp-opt', list).forEach((o) => o.setAttribute('aria-selected', String(+o.dataset.i === i)));
      const el = i >= 0 ? $('#jumpOpt' + i, list) : null;
      if (el) { input.setAttribute('aria-activedescendant', el.id); el.scrollIntoView({ block: 'nearest' }); }
      else input.removeAttribute('aria-activedescendant');
    }
    const open = () => { closePop(); render(); list.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };
    const go = (d) => {
      if (!d) return;
      const [path, hash] = d.href.split('#');
      if (path === location.pathname && hash) { close(); input.value = ''; input.blur(); highlight(hash); history.replaceState(null, '', '#' + hash); }
      else location.href = d.href;
    };
    input.addEventListener('focus', open);
    input.addEventListener('input', () => { if (list.hidden) open(); else render(); });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault();
        if (list.hidden) return open();
        if (!shown.length) return;
        setActive(ev.key === 'ArrowDown' ? (active + 1) % shown.length : (active - 1 + shown.length) % shown.length);
      } else if (ev.key === 'Enter') { ev.preventDefault(); go(shown[active]); }
      else if (ev.key === 'Escape') { ev.preventDefault(); if (input.value) { input.value = ''; render(); } else { close(); input.blur(); } }
    });
    list.addEventListener('pointerdown', (ev) => ev.preventDefault());
    list.addEventListener('click', (ev) => { const o = ev.target.closest('.fwp-opt'); if (o) go(shown[+o.dataset.i]); });
    list.addEventListener('pointermove', (ev) => { const o = ev.target.closest('.fwp-opt'); if (o && +o.dataset.i !== active) setActive(+o.dataset.i); });
    input.addEventListener('blur', () => setTimeout(close, 120));
    document.addEventListener('keydown', (ev) => {
      const t = ev.target;
      const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
      if (openModals.length) return;
      if ((ev.key === '/' && !typing && !ev.ctrlKey && !ev.metaKey && !ev.altKey) || ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K'))) {
        ev.preventDefault();
        input.focus();
        input.select();
      }
    });
  }
  /** Scroll to an element by id (a setting row or a card) and make it glow. */
  function highlight(id) {
    const el = id && document.getElementById(id);
    if (!el) return;
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.remove('is-flash');
    void el.offsetWidth;
    el.classList.add('is-flash');
    const f = $('input:not([type=hidden]), select, textarea', el);
    if (f && !el.classList.contains('fwp-set--toggle')) setTimeout(() => f.focus({ preventScroll: true }), 350);
  }

  // ------------------------------------------------------ settings engine
  // A form marked data-settings holds rows with fields named by their
  // setting key. Values are compared with the saved ones; the save bar
  // appears while anything differs. Saving posts {op:'save', …changed keys}
  // to /payroll/api/settings.php, which checks every value.
  function initSettings() {
    const forms = $$('form[data-settings]');
    if (!forms.length) return;
    const bar = document.createElement('div');
    bar.className = 'fwp-savebar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Unsaved changes');
    bar.innerHTML = `<span class="fwp-savebar__msg"><span id="saveMsg">Unsaved changes</span><small id="saveCount"></small></span>
      <button class="btn btn--ghost" type="button" id="saveDiscard">Discard</button>
      <button class="btn btn--primary" type="button" id="saveGo">${icon('check', 'i--sm')}Save<kbd>Ctrl S</kbd></button>`;
    document.body.appendChild(bar);

    const fieldValue = (f) => (f.type === 'checkbox' ? (f.checked ? '1' : '0') : f.value);
    const isSecret = (f) => f.dataset.secret === '1' || f.type === 'password';
    const fieldsOf = (form) => $$('[name]', form).filter((f) => !f.disabled && f.name && !f.dataset.ignore);
    forms.forEach((form) => {
      fieldsOf(form).forEach((f) => { f.dataset.saved = fieldValue(f); });
      form.addEventListener('input', () => refresh());
      form.addEventListener('change', () => refresh());
      form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    });
    function changed() {
      const out = [];
      forms.forEach((form) => fieldsOf(form).forEach((f) => {
        if (isSecret(f) && f.value === '') return; // blank secret = keep the stored one
        if (fieldValue(f) !== f.dataset.saved) out.push({ form, f });
      }));
      return out;
    }
    function applyRequires() {
      $$('[data-requires]').forEach((row) => {
        const dep = document.querySelector(`[name="${row.dataset.requires}"]`);
        if (!dep) return;
        const on = dep.type === 'checkbox' ? dep.checked : dep.value !== '' && dep.value !== '0';
        row.classList.toggle('is-off', !on);
      });
    }
    function refresh() {
      applyRequires();
      const list = changed();
      $$('.fwp-set, form[data-settings] .field').forEach((r) => r.classList.remove('is-dirty'));
      list.forEach(({ f }) => { const row = f.closest('.fwp-set') || f.closest('.field'); if (row) row.classList.add('is-dirty'); });
      bar.classList.toggle('is-on', list.length > 0);
      $('#saveCount', bar).textContent = list.length === 1 ? '1 setting changed' : list.length + ' settings changed';
      return list;
    }
    function clearErrors() {
      $$('form[data-settings] .is-invalid').forEach((r) => { r.classList.remove('is-invalid'); const e = $('.fwp-set__error, .field__error', r); if (e) e.remove(); });
    }
    function showError(key, msg) {
      const f = document.querySelector(`form[data-settings] [name="${key}"]`);
      const row = f && (f.closest('.fwp-set') || f.closest('.field'));
      if (!row) { toast(msg, { type: 'error' }); return null; }
      row.classList.add('is-invalid');
      const e = document.createElement('div');
      e.className = row.classList.contains('field') ? 'field__error' : 'fwp-set__error';
      e.textContent = msg;
      row.appendChild(e);
      return row;
    }
    function validate(list) {
      let first = null;
      list.forEach(({ f }) => {
        if (f.checkValidity && !f.checkValidity()) {
          const row = showError(f.name, f.validationMessage || 'Check this value.');
          if (!first) first = row;
        }
      });
      return first;
    }
    let saving = false;
    async function save() {
      if (saving) return;
      clearErrors();
      const list = refresh();
      if (!list.length) return;
      const bad = validate(list);
      if (bad) { bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); toast('Some values need a second look.', { type: 'error' }); return; }
      const byArea = {};
      list.forEach(({ form, f }) => { (byArea[form.dataset.settings] = byArea[form.dataset.settings] || {})[f.name] = fieldValue(f); });
      saving = true;
      const btn = $('#saveGo', bar);
      busy(btn, true);
      try {
        let saved = 0;
        for (const [area, values] of Object.entries(byArea)) {
          const form = forms.find((x) => x.dataset.settings === area);
          const res = await api.post((form && form.dataset.endpoint) || 'settings.php', Object.assign({ op: 'save' }, values));
          saved += Object.keys(values).length;
          list.filter((x) => x.form === form).forEach(({ f }) => { f.dataset.saved = fieldValue(f); });
          document.dispatchEvent(new CustomEvent('fwp:saved', { detail: res }));
        }
        refresh();
        toast(saved === 1 ? 'Setting saved' : saved + ' settings saved');
      } catch (e) {
        const errs = (e.data && e.data.errors) || null;
        if (errs) {
          let first = null;
          Object.entries(errs).forEach(([k, msg]) => { const r = showError(k, msg); if (!first) first = r; });
          if (first) first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        toastError(e);
      } finally {
        saving = false;
        busy(btn, false);
      }
    }
    function discard() {
      changed().forEach(({ f }) => {
        if (f.type === 'checkbox') f.checked = f.dataset.saved === '1';
        else if (isSecret(f)) f.value = '';
        else f.value = f.dataset.saved;
      });
      clearErrors();
      refresh();
      toast('Changes discarded');
    }
    $('#saveGo', bar).addEventListener('click', save);
    $('#saveDiscard', bar).addEventListener('click', discard);
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); }
    });
    window.addEventListener('beforeunload', (e) => { if (changed().length) { e.preventDefault(); e.returnValue = ''; } });
    // Click anywhere on a toggle row flips it
    $$('.fwp-set--toggle').forEach((row) => row.addEventListener('click', (e) => {
      if (e.target.closest('input, a, button, label')) return;
      const cb = $('input[type=checkbox]', row);
      if (cb && !cb.disabled) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); }
    }));
    // Show / hide secrets
    $$('[data-reveal]').forEach((b) => b.addEventListener('click', () => {
      const f = b.parentElement.querySelector('input');
      if (!f) return;
      f.type = f.type === 'password' ? 'text' : 'password';
      b.setAttribute('aria-label', f.type === 'password' ? 'Show' : 'Hide');
      $('use', b).setAttribute('href', f.type === 'password' ? '#i-eye' : '#i-eye-off');
    }));
    refresh();
    FWP.settings = { save, refresh, isDirty: () => changed().length > 0 };
  }

  // ------------------------------------------------------ money and forms
  /** Cents → "R 12 345.67" (negative with a proper minus). */
  function money(cents, opts = {}) {
    const n = Math.round(Number(cents) || 0);
    const abs = Math.abs(n);
    const s = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0') + '.' + String(abs % 100).padStart(2, '0');
    return (n < 0 ? '−' : '') + (opts.symbol === false ? '' : 'R\u00a0') + s;
  }
  /** Cents → "12345.67" for an input. */
  const moneyInput = (cents) => (cents ? (Math.round(cents) / 100).toFixed(2) : '');
  function fmtDay(s, year = true) {
    const d = s ? new Date(String(s).slice(0, 10) + 'T00:00:00') : null;
    if (!d || isNaN(d)) return '';
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (year ? ' ' + d.getFullYear() : '');
  }
  const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  /** "2026-10-23" → "Friday 23 October" */
  function longDay(s) {
    const d = s ? new Date(String(s).slice(0, 10) + 'T00:00:00') : null;
    if (!d || isNaN(d)) return '';
    return DAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS_LONG[d.getMonth()];
  }
  /** Show per-field errors from a 422 answer under each field of a form; returns true when any were shown. */
  function fieldErrors(root, errors) {
    $$('.field.is-invalid', root).forEach((f) => { f.classList.remove('is-invalid'); const e = $('.field__error', f); if (e) e.remove(); });
    let first = null;
    Object.entries(errors || {}).forEach(([k, msg]) => {
      const input = $(`[name="${k}"]`, root);
      const field = input ? input.closest('.field') : null;
      if (!field) return;
      field.classList.add('is-invalid');
      const e = document.createElement('div');
      e.className = 'field__error';
      e.textContent = msg;
      field.appendChild(e);
      if (!first) first = input;
    });
    if (first) {
      const panel = first.closest('.fwp-panel');
      if (panel && panel.hidden && panel.dataset.tab) { const t = $(`[data-tab-btn="${panel.dataset.tab}"]`, root.closest('.fwm-dialog') || document); if (t) t.click(); }
      first.focus({ preventScroll: false });
    }
    return !!first;
  }
  /** Read a form's named fields into an object (checkboxes as 1/0). */
  function formData(root) {
    const out = {};
    $$('[name]', root).forEach((f) => {
      if (f.disabled) return;
      if (f.type === 'radio') { if (f.checked) out[f.name] = f.value; return; }
      out[f.name] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value;
    });
    return out;
  }
  /** What changed in other runs after saving someone, in one sentence (or ''). */
  function runsNote(runs) {
    if (!runs || !runs.length) return '';
    const up = runs.filter((r) => r.action === 'updated').map((r) => r.run);
    const out = runs.filter((r) => r.action === 'removed').map((r) => r.run);
    const stale = runs.filter((r) => r.action === 'stale').map((r) => r.run);
    const parts = [];
    if (up.length) parts.push('Updated in ' + up.join(', '));
    if (out.length) parts.push('taken out of ' + out.join(', '));
    if (stale.length) parts.push(stale.join(', ') + ' is approved and still has the old figures — send it back to update it');
    return parts.join('; ') + '.';
  }

  const FWP = { $, $$, esc, icon, api, ApiError, toast, toastError, popover, closePop, modal, openModal, closeModal, dialog, copy, initials, hue, busy, fmtDate, fmtDay, highlight,
    money, moneyInput, fieldErrors, formData, runsNote, longDay, boot: BOOT };
  window.FWP = FWP;

  function init() {
    initChrome();
    initJump();
    initSettings();
    if (location.hash.length > 1) setTimeout(() => highlight(decodeURIComponent(location.hash.slice(1))), 250);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
