// /qi/js/qi-core.js — shared helpers for every Quotes & Invoices page.
// window.QI: $, $$, esc, icon, api (JSON over /qi/api and /qi/ajax with the
// CSRF header), toast, popover, modal/dialog, money & date formatting, the
// theme switch, the kebab menu and the narrow-screen drawer.
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#${name.startsWith('iq-') || name.startsWith('ih-') ? name : 'i-' + name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const narrow = () => window.matchMedia('(max-width: 820px)').matches;

  // ------------------------------------------------------------------ API
  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data; }
  }
  async function request(method, url, body, isForm) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    if (method !== 'GET') headers['X-CSRF-Token'] = csrf();
    if (body && !isForm) headers['Content-Type'] = 'application/json';
    let res;
    try {
      res = await fetch(url, { method, headers, credentials: 'same-origin', body: body ? (isForm ? body : JSON.stringify(body)) : undefined });
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
      throw new ApiError((data && (data.error || data.message)) || `Something went wrong (${res.status}).`, res.status, data);
    }
    return data;
  }
  const qs = (params) => new URLSearchParams(Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
  const api = {
    get: (path, params) => { const q = qs(params); return request('GET', '/qi/api/' + path + (q ? '?' + q : '')); },
    post: (path, body) => request('POST', '/qi/api/' + path, body || {}),
    upload: (path, formData) => request('POST', '/qi/api/' + path, formData, true),
    /** The module's older JSON endpoints in /qi/ajax (actions the new UI reuses). */
    ajax: (file, body) => request('POST', '/qi/ajax/' + file, body || {}),
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
    return { close, el };
  }
  const toastError = (e) => toast(e && e.message ? e.message : String(e), { type: 'error' });

  // -------------------------------------------------------------- popovers
  let activePop = null;
  function closePop() { if (activePop) { const p = activePop; activePop = null; p.el.remove(); if (p.onClose) p.onClose(); } }
  /** popover(anchor | {x, y}, html, {onClick(el, ev), onClose, align, width, cls, role, noFocus}) */
  function popover(anchor, html, opts = {}) {
    closePop();
    const el = document.createElement('div');
    el.className = 'fwm-pop ' + (opts.cls || '');
    el.setAttribute('role', opts.role || 'menu');
    if (opts.width) el.style.minWidth = opts.width + 'px';
    el.innerHTML = html;
    document.body.appendChild(el);
    const place = () => {
      const r = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : { left: anchor.x, right: anchor.x, top: anchor.y, bottom: anchor.y, width: 0 };
      const pw = el.offsetWidth, ph = el.offsetHeight;
      let left = opts.align === 'end' ? r.right - pw : (opts.align === 'center' ? r.left + r.width / 2 - pw / 2 : r.left);
      left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
      let top = r.bottom + 6;
      if (top + ph > window.innerHeight - 8) top = Math.max(8, r.top - ph - 6);
      el.style.left = left + 'px';
      el.style.top = top + 'px';
    };
    place();
    const pop = { el, onClose: opts.onClose, anchor: anchor && anchor.nodeType ? anchor : null };
    activePop = pop;
    el.addEventListener('click', (ev) => {
      const item = ev.target.closest('[data-v]');
      if (!item || !el.contains(item) || item.disabled) return;
      const keep = opts.onClick && opts.onClick(item, ev) === false;
      if (!keep && activePop === pop) {
        const back = pop.el.contains(document.activeElement) && pop.anchor;
        closePop();
        if (back && back.isConnected && back.focus) back.focus({ preventScroll: true });
      }
    });
    el.addEventListener('keydown', (ev) => {
      const items = $$('[data-v]:not([disabled])', el);
      const i = items.indexOf(document.activeElement);
      if (ev.key === 'ArrowDown' && items.length) { ev.preventDefault(); (items[i + 1] || items[0]).focus(); }
      if (ev.key === 'ArrowUp' && items.length) { ev.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); closePop(); if (pop.anchor && pop.anchor.focus) pop.anchor.focus(); }
    });
    const first = $('input:not([type=hidden]):not([type=checkbox]), textarea', el) || $('[data-v]', el);
    if (first && !opts.noFocus) setTimeout(() => first.focus(), 0);
    return pop;
  }
  document.addEventListener('mousedown', (e) => {
    if (activePop && !activePop.el.contains(e.target) && !(activePop.anchor && activePop.anchor.contains(e.target))) closePop();
  });
  window.addEventListener('resize', closePop);

  // --------------------------------------------------------------- dialogs
  const openers = [];
  function openModal(m) {
    if (typeof m === 'string') m = document.getElementById(m);
    if (!m) return null;
    const prev = activePop && activePop.el.contains(document.activeElement) ? activePop.anchor : document.activeElement;
    closePop();
    openers.push([m, prev]);
    m.hidden = false;
    const f = $('[autofocus], input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]), select, textarea, button.btn--primary', m);
    setTimeout(() => f && f.focus(), 30);
    return m;
  }
  function closeModal(m) {
    if (typeof m === 'string') m = document.getElementById(m);
    if (!m || m.hidden) return;
    if (m._guard && !m._guard()) return;
    m.hidden = true;
    if (m._onClose) m._onClose();
    if (m.dataset.temp) m.remove();
    const i = openers.findIndex((x) => x[0] === m);
    const back = i >= 0 ? openers.splice(i, 1)[0][1] : null;
    if (back && back.focus && back.isConnected) back.focus();
  }
  document.addEventListener('click', (e) => {
    const closer = e.target.closest('[data-close]');
    if (closer) { closeModal(closer.closest('.fwm-modal')); return; }
    if (e.target.classList && e.target.classList.contains('fwm-modal')) closeModal(e.target);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Tab') { trapTab(e); return; }
    if (e.key !== 'Escape') return;
    if (activePop) {
      if (!activePop.el.contains(e.target)) { e.preventDefault(); e.stopPropagation(); const a = activePop.anchor; closePop(); if (a && a.isConnected && a.focus) a.focus(); }
      return;
    }
    const open = $$('.fwm-modal:not([hidden])').pop();
    if (open) { e.preventDefault(); e.stopPropagation(); closeModal(open); }
  }, true);
  function trapTab(e) {
    const m = $$('.fwm-modal:not([hidden])').pop();
    if (!m || (activePop && activePop.el.contains(e.target))) return;
    const els = $$('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', m).filter((x) => x.offsetParent !== null);
    if (!els.length) return;
    const first = els[0], last = els[els.length - 1];
    if (!m.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  let dlgSeq = 0;
  function tempModal(html, cls = '', style = '') {
    const m = document.createElement('div');
    m.className = 'fwm-modal';
    m.dataset.temp = '1';
    m.hidden = true;
    m.innerHTML = `<div class="fwm-dialog ${cls}" role="dialog" aria-modal="true" style="${style}">${html}</div>`;
    const h = $('h2', m);
    if (h) { h.id = h.id || 'fwqDlg' + (++dlgSeq); $('[role=dialog]', m).setAttribute('aria-labelledby', h.id); }
    document.body.appendChild(m);
    return m;
  }
  /** Promise dialog: confirm, a text input ({input:{value, placeholder, label, type}}) or choices ([{v, label, hint}]). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, choices = null, value = null, input = null }) {
    return new Promise((resolve) => {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body">${body ? `<p class="fwm-dialog__lead" style="margin-bottom:${choices || input ? 14 : 0}px">${body}</p>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}${input.multiline
            ? `<textarea class="textarea" id="dlgInput" maxlength="${input.max || 500}" placeholder="${esc(input.placeholder || '')}" autofocus>${esc(input.value || '')}</textarea>`
            : `<input class="input" id="dlgInput" type="${esc(input.type || 'text')}" ${input.step ? `step="${esc(input.step)}"` : ''} maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autofocus>`}</div>` : ''}
          ${choices ? `<div class="fwm-results">${choices.map((c, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="dlgChoice" value="${esc(c.v)}" ${(value ? c.v === value : i === 0) ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}</div>` : ''}</form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn btn--primary${danger ? ' fwq-danger' : ''}" type="button" data-act="yes">${esc(confirm)}</button></div>`, '', 'width:min(480px,100%)');
      let done = false;
      const result = () => (input ? $('#dlgInput', m).value.trim() : choices ? ($('input[name=dlgChoice]:checked', m) || {}).value || null : true);
      const finish = (v) => { if (done) return; done = true; closeModal(m); resolve(v); };
      m._onClose = () => { if (!done) { done = true; resolve(input || choices ? null : false); } };
      m.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        if (b.dataset.act !== 'yes') return finish(input || choices ? null : false);
        finish(result());
      });
      $('form', m).addEventListener('submit', (e) => { e.preventDefault(); finish(result()); });
      openModal(m);
    });
  }

  // ------------------------------------------------------- money and dates
  const BOOT = JSON.parse(($('#qiBoot') || {}).textContent || '{}');
  function group(v, places = 2) {
    const neg = v < 0;
    const s = Math.abs(v).toFixed(places);
    const [int, dec] = s.split('.');
    return (neg ? '−' : '') + int.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (dec ? '.' + dec : '');
  }
  /** "R 1,234.56" — the symbol follows the document currency. */
  const money = (v, symbol = 'R') => (symbol ? symbol + ' ' : '') + group(Number(v) || 0);
  const compact = (v, symbol = 'R') => {
    v = Number(v) || 0;
    const a = Math.abs(v);
    const s = a >= 1e6 ? (a / 1e6).toFixed(a >= 1e7 ? 1 : 2).replace(/\.0+$/, '') + 'm' : a >= 1e4 ? Math.round(a / 1e3) + 'k' : group(a, a % 1 ? 2 : 0);
    return (v < 0 ? '−' : '') + (symbol ? symbol + ' ' : '') + s;
  };
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  function parseYmd(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fmtDate(s, withYear = true) {
    const d = parseYmd(s);
    if (!d) return '';
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (withYear ? ' ' + d.getFullYear() : '');
  }
  const today = () => BOOT.today || ymd(new Date());
  function daysFrom(s) {
    const d = parseYmd(s), t = parseYmd(today());
    if (!d || !t) return null;
    return Math.round((d - t) / 86400000);
  }
  function relDue(s) {
    const n = daysFrom(s);
    if (n === null) return '';
    if (n === 0) return 'today';
    if (n === 1) return 'tomorrow';
    if (n === -1) return 'yesterday';
    return n > 0 ? `in ${n} days` : `${-n} days ago`;
  }
  function relTime(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : null;
    if (!d || isNaN(d)) return '';
    const sec = (Date.now() - d.getTime()) / 1000;
    if (sec < 60) return 'just now';
    if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
    if (sec < 86400) return Math.floor(sec / 3600) + ' h ago';
    if (sec < 7 * 86400) return Math.floor(sec / 86400) + ' d ago';
    return fmtDate(String(s).slice(0, 10));
  }
  const pill = (status) => `<span class="fwq-pill" data-tone="${esc(status.tone || 'muted')}">${esc(status.label || '')}</span>`;
  const typeIcon = (t) => `<span class="fwq-type" data-t="${esc(t)}">${icon(t === 'quote' ? 'iq-quote' : t === 'invoice' ? 'iq-invoice' : t === 'recurring' ? 'iq-recurring' : 'iq-credit')}</span>`;
  const TYPE_NAMES = { quote: 'Quote', invoice: 'Invoice', credit_note: 'Credit note', recurring: 'Recurring invoice' };
  const viewUrl = (type, id) => `/qi/view.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`;
  const editUrl = (type, id) => `/qi/edit.php?type=${encodeURIComponent(type)}${id ? '&id=' + encodeURIComponent(id) : ''}`;

  // ---------------------------------------------------------------- chrome
  function initChrome(opts = {}) {
    const body = document.body;
    const themeBtn = $('#themeToggle');
    const applyTheme = (t) => {
      body.dataset.theme = t;
      const meta = $('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', t === 'dark' ? '#0d0a09' : '#f2ece3');
      if (themeBtn) {
        themeBtn.innerHTML = icon(t === 'dark' ? 'sun' : 'moon');
        themeBtn.setAttribute('data-tip', t === 'dark' ? 'Light theme' : 'Dark theme');
      }
    };
    applyTheme(body.dataset.theme === 'light' ? 'light' : 'dark');
    if (themeBtn) themeBtn.addEventListener('click', () => {
      const t = body.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(t);
      document.cookie = 'fw_theme=' + t + ';max-age=' + (365 * 86400) + ';path=/;SameSite=Lax';
      if (opts.onTheme) opts.onTheme(t);
    });
    const kebab = $('#kebabBtn');
    if (kebab) kebab.addEventListener('click', () => {
      if (activePop && activePop.anchor === kebab) return closePop();
      popover(kebab, `
        <a class="fwm-pop__item" data-v="q" href="/qi/edit.php?type=quote">${icon('iq-quote')}New quote</a>
        <a class="fwm-pop__item" data-v="i" href="/qi/edit.php?type=invoice">${icon('iq-invoice')}New invoice</a>
        <a class="fwm-pop__item" data-v="c" href="/qi/edit.php?type=credit_note">${icon('iq-credit')}New credit note</a>
        <a class="fwm-pop__item" data-v="r" href="/qi/#recurring">${icon('iq-recurring')}Recurring invoices</a>
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="d" href="/qi/designer.php">${icon('iq-designer')}Document designer</a>
        <a class="fwm-pop__item" data-v="s" href="/qi/settings.php">${icon('settings')}Settings</a>
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="crm" href="/crm/">${icon('users')}Customers (CRM)</a>
        <a class="fwm-pop__item" data-v="fin" href="/finances/">${icon('wallet')}Finance</a>
        <a class="fwm-pop__item" data-v="home" href="/home.php">${icon('home')}Flowwork home</a>`, { align: 'end' });
    });
    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
    document.addEventListener('keydown', (e) => {
      const t = e.target;
      const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
      if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey) {
        const s = $('#searchInput');
        if (s) { e.preventDefault(); s.focus(); s.select(); }
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

  window.QI = {
    $, $$, esc, icon, csrf, debounce, narrow, api, ApiError, toast, toastError, popover, closePop,
    openModal, closeModal, tempModal, dialog, BOOT, money, compact, group, fmtDate, parseYmd, ymd, today, daysFrom, relDue, relTime,
    pill, typeIcon, TYPE_NAMES, viewUrl, editUrl, initChrome, toggleDrawer,
  };
})();
