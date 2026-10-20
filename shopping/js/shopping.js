// /shopping/js/shopping.js — shared helpers for every Shopping page.
// window.FWS: $, $$, esc, icon, api (JSON over /shopping/api with the CSRF
// header), toast, popover, modal / dialog, money and dates, initials / hue,
// busy, the progress ring, the theme switch, the menu (switch company, sign
// out), the narrow-screen drawer, "Find a list or an item", keyboard
// shortcuts and the "New list" dialog.
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#${/^(is|ih|if|i)-/.test(name) ? name : 'i-' + name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const BOOT = JSON.parse(($('#shBoot') || {}).textContent || '{}');
  BOOT.caps = BOOT.caps || {};
  BOOT.prefs = BOOT.prefs || {};
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const typingIn = (t) => t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));

  // ------------------------------------------------------------------ API
  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data || {}; }
  }
  function handle(status, data) {
    if (status === 401) {
      location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search + location.hash);
      throw new ApiError('Your session has expired.', 401);
    }
    if (status === 419) throw new ApiError('Your security token expired — reload the page and try again.', 419);
    if (status < 200 || status >= 300 || !data || data.ok === false) {
      throw new ApiError((data && data.error) || `Something went wrong (${status}).`, status, data);
    }
    return data;
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
    return handle(res.status, data);
  }
  const qs = (params) => new URLSearchParams(Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
  const api = {
    get: (path, params) => { const q = qs(params); return request('GET', '/shopping/api/' + path + (q ? '?' + q : '')); },
    post: (path, body) => request('POST', '/shopping/api/' + path, body || {}),
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
    const ms = opts.timeout != null ? opts.timeout : (opts.type === 'error' ? 8000 : (opts.action ? 7000 : 3500));
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
    el.addEventListener('click', (ev) => { if (ev.target.closest('[data-pop-close], a.fwm-pop__item')) setTimeout(closePop, 0); });
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
    m.innerHTML = `<div class="fwm-dialog ${opts.wide ? 'fwm-dialog--wide' : ''} ${opts.cls || ''}" role="dialog" aria-modal="true" style="${opts.width ? 'width:min(' + opts.width + 'px,100%)' : ''}">${html}</div>`;
    const h = $('h2', m);
    if (h) { h.id = h.id || 'fwsDlg' + (++dlgSeq); $('[role=dialog]', m).setAttribute('aria-labelledby', h.id); }
    document.body.appendChild(m);
    m.addEventListener('click', (e) => {
      if (e.target === m || e.target.closest('[data-close]')) closeModal(m);
    });
    return m;
  }
  function openModal(m) {
    closePop();
    m._returnFocus = document.activeElement;
    m.hidden = false;
    openModals.push(m);
    document.body.style.overflow = 'hidden';
    const f = $('[autofocus]', m) || $('input:not([type=hidden]):not([disabled]):not([type=radio]):not([type=checkbox]), select, textarea, button.btn--primary', m);
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
    if (m._returnFocus && m._returnFocus.focus && m._returnFocus.isConnected) m._returnFocus.focus({ preventScroll: true });
    if (!m.dataset.keep) setTimeout(() => m.remove(), 10);
  }
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (activePop) { const a = activePop.anchor; closePop(); if (a) a.focus(); return; }
    if (openModals.length) { e.preventDefault(); closeModal(openModals[openModals.length - 1]); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab' || !openModals.length) return;
    const m = openModals[openModals.length - 1];
    const f = $$('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', m).filter((x) => x.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /** Promise dialog: a confirmation, a text input ({input}) or a choice ({choices: [{value, label, hint}]}). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, input = null, choices = null }) {
    return new Promise((resolve) => {
      const m = modal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body" novalidate>${body ? `<p class="fwm-dialog__lead">${body}</p>` : ''}
          ${choices ? `<div class="fws-choices" role="radiogroup">${choices.map((c, i) => `<label class="fws-choice"><input type="radio" name="dlgChoice" value="${esc(c.value)}"${(c.checked ?? i === 0) ? ' checked' : ''}><span><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}</div>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}<input class="input" id="dlgInput" type="${esc(input.type || 'text')}" maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autocomplete="off" autofocus ${input.inputmode ? `inputmode="${esc(input.inputmode)}"` : ''}></div>` : ''}
        </form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn ${danger ? 'btn--danger-solid' : 'btn--primary'}" type="button" data-act="yes">${esc(confirm)}</button></div>`, { width: 480 });
      let done = false;
      const value = () => {
        if (choices) { const c = $('input[name=dlgChoice]:checked', m); return c ? c.value : null; }
        return input ? $('#dlgInput', m).value.trim() : true;
      };
      const finish = (v) => { if (done) return; done = true; closeModal(m); resolve(v); };
      m._onClose = () => { if (!done) { done = true; resolve(input || choices ? null : false); } };
      m.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        finish(b.dataset.act === 'yes' ? value() : (input || choices ? null : false));
      });
      $('form', m).addEventListener('submit', (e) => { e.preventDefault(); finish(value()); });
      openModal(m);
    });
  }

  // ------------------------------------------------------------- small helpers
  function initials(name) {
    const n = String(name || '').trim();
    if (!n) return '?';
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
  const ymd = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  function fmtDay(s, year = 'auto') {
    const d = s ? new Date(String(s).slice(0, 10) + 'T00:00:00') : null;
    if (!d || isNaN(d)) return '';
    const showYear = year === true || (year === 'auto' && d.getFullYear() !== new Date().getFullYear());
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (showYear ? ' ' + d.getFullYear() : '');
  }
  /** "today", "tomorrow", "in 4 days", "2 days late", "12 Nov" */
  function due(s) {
    if (!s) return '';
    const d = new Date(String(s).slice(0, 10) + 'T00:00:00');
    if (isNaN(d)) return '';
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const n = Math.round((d - today) / 86400000);
    if (n === 0) return 'today';
    if (n === 1) return 'tomorrow';
    if (n === -1) return 'yesterday';
    if (n < 0) return (-n) + ' days late';
    if (n < 7) return 'in ' + n + ' days';
    return fmtDay(s);
  }
  const isLate = (s) => !!s && String(s).slice(0, 10) < ymd(new Date());
  /** "just now", "12 min ago", "3 h ago", "yesterday", "4 Oct" */
  function ago(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : null;
    if (!d || isNaN(d)) return '';
    const sec = (Date.now() - d.getTime()) / 1000;
    if (sec < 60) return 'just now';
    if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
    if (sec < 86400 && d.getDate() === new Date().getDate()) return Math.floor(sec / 3600) + ' h ago';
    if (sec < 172800) return 'yesterday';
    return fmtDay(s);
  }
  /** cents → "R 12 345.67" */
  function money(cents, opts = {}) {
    if (cents === null || cents === undefined || cents === '') return opts.empty != null ? opts.empty : '—';
    const n = Math.round(Number(cents) || 0);
    const abs = Math.abs(n);
    const whole = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    const s = opts.round && abs % 100 === 0 ? whole : whole + '.' + String(abs % 100).padStart(2, '0');
    return (n < 0 ? '−' : '') + (opts.symbol === false ? '' : 'R ') + s;
  }
  /** "1 234,50" / "R1,234.50" / "95" → cents, or null. */
  function toCents(v) {
    let s = String(v == null ? '' : v).trim().replace(/[R\s ]/gi, '').replace('−', '-');
    if (s === '') return null;
    if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
    else if (/^-?\d+,\d{1,2}$/.test(s)) s = s.replace(',', '.');
    if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
    return Math.round(parseFloat(s) * 100);
  }
  const rand = (cents) => (cents == null ? '' : (cents / 100).toFixed(2));
  const unitText = (qty, unit) => (unit === 'ea' ? qty + ' ×' : qty + ' ' + unit);
  /** A progress ring (bought of total). */
  function ring(done, total, opts = {}) {
    const p = total > 0 ? done / total : 0;
    const r = 18, c = 2 * Math.PI * r;
    return `<span class="fws-ring${p >= 1 && total ? ' is-full' : ''}${opts.cls ? ' ' + opts.cls : ''}" role="img" aria-label="${done} of ${total} bought">
      <svg viewBox="0 0 44 44" aria-hidden="true"><circle class="fws-ring__bg" cx="22" cy="22" r="${r}"/><circle class="fws-ring__fg" cx="22" cy="22" r="${r}" stroke-dasharray="${c.toFixed(2)}" stroke-dashoffset="${(c * (1 - p)).toFixed(2)}"/></svg>
      <b>${p >= 1 && total ? icon('check', 'i--sm') : (opts.label != null ? opts.label : Math.round(p * 100) + '%')}</b></span>`;
  }
  const catOf = (key) => (BOOT.categories && BOOT.categories[key]) || { label: 'Everything else', icon: 'is-tag', color: '#85766b' };

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
          ${cos.map((c) => `<button type="button" class="fwm-pop__item fws-menu__company" role="menuitem" data-fw-switch-company="${c.id}"${c.active ? ' disabled aria-current="true"' : ''}>
            <span class="fwm-avatar" style="--h:${hue(c.name)}">${esc(initials(c.name))}</span><span class="fws-menu__name">${esc(c.name)}</span>${c.active ? icon('check', 'i--sm') : ''}</button>`).join('')}
          <div class="fwm-pop__sep" role="separator"></div>` : ''}
        ${BOOT.caps.write ? `<button class="fwm-pop__item" role="menuitem" type="button" data-new-list>${icon('plus')}New list</button>` : ''}
        <a class="fwm-pop__item" role="menuitem" href="/shopping/templates.php">${icon('template')}Templates</a>
        <a class="fwm-pop__item" role="menuitem" href="/shopping/prices.php">${icon('is-price')}Prices &amp; stores</a>
        <a class="fwm-pop__item" role="menuitem" href="/home.php">${icon('home')}Flowwork home</a>
        <a class="fwm-pop__item" role="menuitem" href="/account/">${icon('ih-devices')}Signed-in devices</a>
        <button class="fwm-pop__item" role="menuitem" type="button" data-keys>${icon('keyboard')}Keyboard shortcuts</button>
        <div class="fwm-pop__sep" role="separator"></div>
        <a class="fwm-pop__item" role="menuitem" href="/logout.php">${icon('ih-logout')}Sign out</a>`, { align: 'end', cls: 'fws-menu' });
      const k = $('[data-keys]', activePop.el);
      if (k) k.addEventListener('click', () => { closePop(); shortcuts(); });
    });

    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
    $$('#side a, #side [data-new-list]').forEach((a) => a.addEventListener('click', () => toggleDrawer(false)));

    document.addEventListener('click', (e) => {
      const b = e.target.closest('[data-new-list]');
      if (b) { e.preventDefault(); closePop(); newList(); }
    });

    // g + letter jumps between pages
    let gAt = 0;
    document.addEventListener('keydown', (e) => {
      if (typingIn(e.target) || e.ctrlKey || e.metaKey || e.altKey || openModals.length) return;
      if (e.key === '?') { e.preventDefault(); shortcuts(); return; }
      if (e.key === 'g') { gAt = Date.now(); return; }
      if (Date.now() - gAt < 1200) {
        const map = { o: '', t: 'templates.php', p: 'prices.php', s: 'preferences.php' };
        if (map[e.key] !== undefined) { e.preventDefault(); gAt = 0; location.href = '/shopping/' + map[e.key]; }
        return;
      }
      if (e.key === 'n' && BOOT.caps.write && document.body.dataset.page !== 'list') { e.preventDefault(); newList(); }
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
    const rows = [['/ or Ctrl K', 'Find a list or an item'], ['n', 'New list (or add, on a list)'], ['s', 'Start or finish shopping (on a list)'], ['e', 'Edit the item under focus'],
      ['g then o', 'Overview'], ['g then t', 'Templates'], ['g then p', 'Prices & stores'], ['g then s', 'Preferences'], ['Esc', 'Close'], ['?', 'This list']];
    const m = modal(`<div class="fwm-dialog__head"><h2>Keyboard shortcuts</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fwm-kbd-grid">${rows.map(([k, l]) => `<div class="fwm-kbd-row"><span>${esc(l)}</span><span>${k.split(' then ').map((x) => `<kbd class="fwm-kbd">${esc(x)}</kbd>`).join(' then ')}</span></div>`).join('')}</div></div>`, { width: 480 });
    openModal(m);
  }

  // ------------------------------------------------------ "New list"
  function newList(prefill = {}) {
    const purposes = BOOT.purposes || { general: 'General' };
    const def = prefill.purpose || BOOT.prefs.default_purpose || 'general';
    const m = modal(`<div class="fwm-dialog__head"><h2>A new list</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fws-form" novalidate>
        <div class="field"><label class="label" for="nlName">Name</label><input class="input" id="nlName" maxlength="120" placeholder="Site supplies, Office this month, Braai on Saturday…" value="${esc(prefill.name || '')}" autofocus autocomplete="off"></div>
        <div class="field"><span class="label">What is it for?</span>
          <div class="fws-purpose" role="radiogroup">${Object.entries(purposes).map(([k, l]) => `<label class="fws-purpose__opt"><input type="radio" name="nlPurpose" value="${esc(k)}"${k === def ? ' checked' : ''}><span>${icon(k === 'procurement' ? 'is-crate' : (k === 'groceries' ? 'is-basket' : 'is-list'))}${esc(l)}</span></label>`).join('')}</div></div>
        <div class="row">
          <div class="field"><label class="label" for="nlBudget">Budget <span class="fws-muted">(optional)</span></label><input class="input" id="nlBudget" inputmode="decimal" placeholder="R 0.00" autocomplete="off"></div>
          <div class="field"><label class="label" for="nlNeed">Needed by <span class="fws-muted">(optional)</span></label><input class="input" id="nlNeed" type="date"></div>
        </div>
        <label class="switch" style="margin-top:12px"><input type="checkbox" id="nlTeam"${def === 'procurement' ? ' checked' : ''}><span class="switch__track"></span>
          <span class="switch__text"><span class="switch__title">Share with the team</span><span class="hint">Everyone in the company can see it and tick things off. Off: only you.</span></span></label>
        <div class="field" style="margin-top:12px"><label class="label" for="nlText">Items <span class="fws-muted">(optional — one a line)</span></label>
          <textarea class="textarea" id="nlText" rows="4" placeholder="10 bags cement&#10;2x PVC elbow 20mm">${esc(prefill.text || '')}</textarea></div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="nlGo">${icon('is-list')}Make the list</button></div>`, { width: 540 });
    $$('input[name=nlPurpose]', m).forEach((r) => r.addEventListener('change', () => { $('#nlTeam', m).checked = r.value === 'procurement' || $('#nlTeam', m).checked; }));
    const go = async () => {
      const btn = $('#nlGo', m);
      const budget = $('#nlBudget', m).value.trim();
      if (budget && toCents(budget) === null) { toast('The budget is not an amount in rand.', { type: 'error' }); return; }
      busy(btn, true);
      try {
        const r = await api.post('lists.php', {
          op: 'create', name: $('#nlName', m).value.trim(), purpose: ($('input[name=nlPurpose]:checked', m) || {}).value || 'general',
          budget: budget ? toCents(budget) / 100 : '', needed_by: $('#nlNeed', m).value, shared: $('#nlTeam', m).checked ? 'team' : 'private', text: $('#nlText', m).value,
        });
        location.href = r.url;
      } catch (e) { busy(btn, false); toastError(e); }
    };
    $('#nlGo', m).addEventListener('click', go);
    $('form', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
    $('#nlName', m).addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); go(); } });
    openModal(m);
  }

  // ------------------------------------------------------ "Find a list or an item"
  function initJump() {
    const input = $('#jumpInput');
    const list = $('#jumpList');
    if (!input || !list) return;
    const pages = BOOT.pages || [];
    let shown = [];
    let active = -1;
    let seq = 0;
    const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const mark = (name, q) => {
      if (!q) return esc(name);
      const i = norm(name).indexOf(q);
      return i < 0 ? esc(name) : esc(name.slice(0, i)) + '<mark>' + esc(name.slice(i, i + q.length)) + '</mark>' + esc(name.slice(i + q.length));
    };
    function draw(found, q, loading) {
      const pg = q ? pages.filter((p) => norm(p.name + ' ' + p.hint).includes(q)).slice(0, 3) : pages;
      const lists = (found.lists || []).map((l) => ({ name: l.name, href: '/shopping/list.php?id=' + l.id, icon: l.status === 'buying' ? 'is-trolley' : (l.status === 'done' ? 'check' : 'is-list'), hint: l.status === 'buying' ? 'Out shopping now' : (l.status === 'done' ? 'Done' : 'List'), group: 'Lists' }));
      const items = (found.items || []).map((i) => ({ name: i.name, href: '/shopping/list.php?id=' + i.list_id + '#item-' + i.id, icon: i.bought ? 'is-trolley' : 'is-tag', hint: unitText(i.qty, i.unit) + ' · ' + i.list + (i.bought ? ' · bought' : ''), group: 'Items' }));
      shown = [...lists, ...items, ...pg.map((p) => ({ ...p, group: q ? 'Pages' : 'Go to' }))];
      active = shown.length ? 0 : -1;
      let html = '';
      let group = '';
      shown.forEach((d, i) => {
        if (d.group !== group) { group = d.group; html += `<div class="fws-jump__group" role="presentation">${esc(group)}</div>`; }
        html += `<div class="fws-opt" role="option" id="jumpOpt${i}" data-i="${i}" aria-selected="${i === active}">
          <span class="fws-opt__icon">${icon(d.icon || 'is-list')}</span>
          <span class="fws-opt__text"><span class="fws-opt__name">${mark(d.name, q)}</span><span class="fws-opt__hint">${esc(d.hint || '')}</span></span>
          <span class="fws-opt__enter" aria-hidden="true">↵</span></div>`;
      });
      if (loading) html += '<div class="fws-jump__none"><span class="fws-spin"></span> Looking…</div>';
      else if (!shown.length) html += `<div class="fws-jump__none">Nothing matches “${esc(input.value.trim())}”.</div>`;
      list.innerHTML = html;
      setActive(active);
    }
    const search = debounce(async (q, n) => {
      try {
        const res = await api.get('lists.php', { op: 'search', q });
        if (n === seq) draw(res, norm(q), false);
      } catch (e) {
        if (n === seq) draw({}, norm(q), false);
      }
    }, 200);
    function render() {
      const q = input.value.trim();
      const n = ++seq;
      if (q.length < 2) { draw({}, norm(q), false); return; }
      draw({}, norm(q), true);
      search(q, n);
    }
    function setActive(i) {
      active = i;
      $$('.fws-opt', list).forEach((o) => o.setAttribute('aria-selected', String(+o.dataset.i === i)));
      const el = i >= 0 ? $('#jumpOpt' + i, list) : null;
      if (el) { input.setAttribute('aria-activedescendant', el.id); el.scrollIntoView({ block: 'nearest' }); }
      else input.removeAttribute('aria-activedescendant');
    }
    const open = () => { closePop(); render(); list.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };
    const go = (d) => { if (d) location.href = d.href; };
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
    list.addEventListener('click', (ev) => { const o = ev.target.closest('.fws-opt'); if (o) go(shown[+o.dataset.i]); });
    list.addEventListener('pointermove', (ev) => { const o = ev.target.closest('.fws-opt'); if (o && +o.dataset.i !== active) setActive(+o.dataset.i); });
    input.addEventListener('blur', () => setTimeout(close, 120));
    document.addEventListener('keydown', (ev) => {
      if (openModals.length) return;
      if ((ev.key === '/' && !typingIn(ev.target) && !ev.ctrlKey && !ev.metaKey && !ev.altKey) || ((ev.ctrlKey || ev.metaKey) && (ev.key === 'k' || ev.key === 'K'))) {
        ev.preventDefault();
        input.focus();
        input.select();
      }
    });
  }

  // "3 min ago" labels keep themselves fresh
  function tickAgo() { $$('[data-ago]').forEach((el) => { el.textContent = ago(el.dataset.ago); }); }

  window.FWS = {
    $, $$, esc, icon, api, ApiError, toast, toastError, popover, closePop, modal, openModal, closeModal, dialog, debounce, typingIn,
    initials, hue, busy, fmtDay, due, isLate, ago, ymd, money, toCents, rand, unitText, ring, catOf, newList, tickAgo, boot: BOOT,
    get modalOpen() { return openModals.length > 0; },
  };
  initChrome();
  initJump();
  tickAgo();
  setInterval(tickAgo, 60000);
})();
