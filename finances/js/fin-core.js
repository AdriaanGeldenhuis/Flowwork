// /finances/js/fin-core.js — shared helpers for every Finance page.
// window.FIN: $, $$, esc, icon, api (JSON over /finances/api with the CSRF header),
// toast, popover, modal/dialog, side sheet, money & date formatting (the
// person's money format), status pills, the account picker, the period
// picker, small SVG charts, the theme switch, the kebab and "create" menus,
// the narrow-screen drawer, the command palette (Ctrl K) and shortcuts.
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#${/^(if|ic|iq|ih|i)-/.test(name) ? name : 'i-' + name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const narrow = () => window.matchMedia('(max-width: 820px)').matches;
  const BOOT = JSON.parse(($('#finBoot') || {}).textContent || '{}');
  const L = (k) => (BOOT.config && BOOT.config.labels && BOOT.config.labels[k]) || k;
  const lower = (k) => L(k).toLowerCase();
  const can = (k) => !!(BOOT.caps && BOOT.caps[k]);
  const mod = (k) => !!(BOOT.config && BOOT.config.modules && BOOT.config.modules[k]);

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
    get: (path, params) => { const q = qs(params); return request('GET', '/finances/api/' + path + (q ? '?' + q : '')); },
    post: (path, body) => request('POST', '/finances/api/' + path, body || {}),
    upload: (path, formData) => request('POST', '/finances/api/' + path, formData, true),
    url: (path, params) => { const q = qs(params); return '/finances/api/' + path + (q ? '?' + q : ''); },
  };

  // ---------------------------------------------------------------- toasts
  function toast(message, opts = {}) {
    let host = $('#toasts');
    if (!host) { host = document.createElement('div'); host.id = 'toasts'; host.className = 'fwm-toasts'; host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'fwm-toast' + (opts.type === 'error' ? ' fwm-toast--error' : '');
    el.setAttribute('role', opts.type === 'error' ? 'alert' : 'status');
    const acts = opts.actions || (opts.action ? [opts.action] : []);
    el.innerHTML = `<span class="fwm-toast__msg"></span>${acts.map((a, i) => `<button class="fwm-toast__action" type="button" data-i="${i}">${esc(a.label)}</button>`).join('')}<button class="fwm-toast__close" type="button" aria-label="Dismiss">${icon('x', 'i--sm')}</button>`;
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
    $$('.fwm-toast__action', el).forEach((b) => b.addEventListener('click', () => { close(); acts[+b.dataset.i].run(); }));
    $('.fwm-toast__close', el).addEventListener('click', close);
    const ms = opts.timeout != null ? opts.timeout : (opts.type === 'error' ? 8000 : (acts.length ? 7000 : 4000));
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
    const pop = { el, onClose: opts.onClose, anchor: anchor && anchor.nodeType ? anchor : null, place };
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
    if (closer) { const m = closer.closest('.fwm-modal'); if (m) { closeModal(m); return; } }
    if (e.target.classList && e.target.classList.contains('fwm-modal')) closeModal(e.target);
  });
  const escHandlers = [];
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Tab') { trapTab(e); return; }
    if (e.key !== 'Escape') return;
    if (activePop) {
      if (!activePop.el.contains(e.target)) { e.preventDefault(); e.stopPropagation(); const a = activePop.anchor; closePop(); if (a && a.isConnected && a.focus) a.focus(); }
      return;
    }
    const open = $$('.fwm-modal:not([hidden])').pop();
    if (open) { e.preventDefault(); e.stopPropagation(); closeModal(open); return; }
    const h = escHandlers[escHandlers.length - 1];
    if (h) { e.preventDefault(); h(); }
  }, true);
  function trapTab(e) {
    const m = $$('.fwm-modal:not([hidden])').pop() || $('.fwf-sheet.is-open');
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
    if (h) { h.id = h.id || 'fwfDlg' + (++dlgSeq); $('[role=dialog]', m).setAttribute('aria-labelledby', h.id); }
    document.body.appendChild(m);
    return m;
  }
  /** Promise dialog: confirm, a text input ({input:{value, placeholder, label, type, multiline}}) or choices ([{v, label, hint}]). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, choices = null, value = null, input = null }) {
    return new Promise((resolve) => {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body">${body ? `<div class="fwm-dialog__lead" style="margin-bottom:${choices || input ? 14 : 0}px">${body}</div>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}${input.multiline
            ? `<textarea class="textarea" id="dlgInput" maxlength="${input.max || 500}" placeholder="${esc(input.placeholder || '')}" autofocus>${esc(input.value || '')}</textarea>`
            : `<input class="input" id="dlgInput" type="${esc(input.type || 'text')}" maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autofocus>`}</div>` : ''}
          ${choices ? `<div class="fwm-results">${choices.map((c, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="dlgChoice" value="${esc(c.v)}" ${(value ? c.v === value : i === 0) ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}</div>` : ''}</form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn btn--primary${danger ? ' fwf-danger' : ''}" type="button" data-act="yes">${esc(confirm)}</button></div>`, '', 'width:min(500px,100%)');
      let done = false;
      const result = () => {
        if (input) return $('#dlgInput', m).value.trim();
        if (choices) return ($('input[name=dlgChoice]:checked', m) || {}).value || null;
        return true;
      };
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
  /** A form dialog: html body, returns {m, close}. onSubmit(form) may return a promise; errors stay in the dialog. */
  function formDialog({ title, html, submit = 'Save', wide = false, danger = false, onSubmit, onOpen, extraFoot = '' }) {
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fwf-form" novalidate>${html}<div class="fwf-formerr" role="alert" hidden></div><button type="submit" hidden></button></form>
      <div class="fwm-dialog__foot">${extraFoot}<span class="fwf-grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary${danger ? ' fwf-danger' : ''}" type="button" data-submit>${esc(submit)}</button></div>`,
      wide ? 'fwm-dialog--wide' : '', wide ? 'width:min(860px,100%)' : 'width:min(560px,100%)');
    const form = $('form', m);
    const err = $('.fwf-formerr', m);
    const btn = $('[data-submit]', m);
    const go = async () => {
      if (btn.disabled) return;
      err.hidden = true;
      btn.disabled = true;
      const label = btn.textContent;
      btn.innerHTML = `<span class="fwf-spin" aria-hidden="true"></span>${esc(label)}`;
      try {
        const r = await onSubmit(form, m);
        if (r !== false) closeModal(m);
      } catch (e) {
        err.textContent = e.message || String(e);
        err.hidden = false;
      } finally {
        btn.disabled = false;
        btn.textContent = label;
      }
    };
    btn.addEventListener('click', go);
    form.addEventListener('submit', (e) => { e.preventDefault(); go(); });
    openModal(m);
    if (onOpen) onOpen(form, m);
    return { m, form, close: () => closeModal(m) };
  }

  // ------------------------------------------------------------ side sheet
  let sheetEl = null;
  function sheet({ title, sub = '', html, actions = '', wide = false, onClose }) {
    closeSheet();
    sheetEl = document.createElement('aside');
    sheetEl.className = 'fwf-sheet' + (wide ? ' fwf-sheet--wide' : '');
    sheetEl.setAttribute('role', 'dialog');
    sheetEl.setAttribute('aria-modal', 'false');
    sheetEl.setAttribute('aria-label', title);
    sheetEl.innerHTML = `<div class="fwf-sheet__head"><div class="fwf-sheet__title"><div class="fwf-eyebrow">${esc(sub)}</div><h2>${esc(title)}</h2></div>
      <div class="fwf-sheet__acts">${actions}</div><button class="icon-btn" type="button" data-sheet-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwf-sheet__body scroll">${html}</div>`;
    document.body.appendChild(sheetEl);
    sheetEl._onClose = onClose;
    requestAnimationFrame(() => sheetEl && sheetEl.classList.add('is-open'));
    $('[data-sheet-close]', sheetEl).addEventListener('click', closeSheet);
    escHandlers.push(closeSheet);
    setTimeout(() => { const b = sheetEl && $('[data-sheet-close]', sheetEl); if (b) b.focus(); }, 40);
    return sheetEl;
  }
  function closeSheet() {
    if (!sheetEl) return;
    const s = sheetEl;
    sheetEl = null;
    const i = escHandlers.indexOf(closeSheet);
    if (i >= 0) escHandlers.splice(i, 1);
    s.classList.remove('is-open');
    setTimeout(() => s.remove(), 220);
    if (s._onClose) s._onClose();
  }

  // ------------------------------------------------------- money and dates
  function group(v, places = 2) {
    const neg = v < 0;
    const s = Math.abs(v).toFixed(places);
    const [int, dec] = s.split('.');
    return (neg ? '−' : '') + int.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (dec ? '.' + dec : '');
  }
  const r2 = (v) => Math.round((Number(v) || 0) * 100) / 100;
  const money = (v, symbol = 'R') => (symbol ? symbol + ' ' : '') + group(r2(v));
  const money0 = (v, symbol = 'R') => (symbol ? symbol + ' ' : '') + group(Math.round(Number(v) || 0), 0);
  const compact = (v, symbol = 'R') => {
    v = Number(v) || 0;
    const a = Math.abs(v);
    const s = a >= 1e6 ? (a / 1e6).toFixed(a >= 1e7 ? 1 : 2).replace(/\.?0+$/, '') + 'm' : a >= 1e4 ? Math.round(a / 1e3) + 'k' : group(a, a % 1 ? 2 : 0);
    return (v < 0 ? '−' : '') + (symbol ? symbol + ' ' : '') + s;
  };
  /** The person's choice: full rands and cents, or compact (R 1.2m). Tables always show cents. */
  const amount = (v) => (BOOT.prefs && BOOT.prefs.money === 'compact' ? compact(v) : money(v));
  const dc = (v) => (Math.abs(Number(v) || 0) < 0.005 ? '' : group(r2(v)));
  /** Parse "1 234,50" / "R1,234.50" / "(12.00)" into a number, or null. */
  function parseMoney(s) {
    s = String(s == null ? '' : s).trim();
    if (!s) return null;
    let neg = false;
    if (/^\(.*\)$/.test(s)) { neg = true; s = s.slice(1, -1); }
    s = s.replace(/[R\s ]/gi, '').replace('−', '-');
    if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
    else if (/^-?\d+,\d{1,2}$/.test(s)) s = s.replace(',', '.');
    if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
    const n = Math.round(parseFloat(s) * 100) / 100;
    return neg ? -n : n;
  }
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  function parseYmd(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }
  function parseDt(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : null;
    return d && !isNaN(d) ? d : null;
  }
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  function fmtDate(s, withYear = true) {
    const d = parseYmd(s);
    if (!d) return '';
    const y = withYear === 'always' || (withYear && d.getFullYear() !== new Date().getFullYear());
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (y ? ' ' + d.getFullYear() : '');
  }
  const fmtMonth = (s) => { const d = parseYmd(s); return d ? MONTHS_LONG[d.getMonth()] + ' ' + d.getFullYear() : ''; };
  const today = () => BOOT.today || ymd(new Date());
  function daysFrom(s) {
    const d = parseYmd(s), t = parseYmd(today());
    if (!d || !t) return null;
    return Math.round((d - t) / 86400000);
  }
  function relDay(s) {
    const n = daysFrom(s);
    if (n === null) return '';
    if (n === 0) return 'today';
    if (n === 1) return 'tomorrow';
    if (n === -1) return 'yesterday';
    if (n > 1 && n < 7) return DAYS[parseYmd(s).getDay()];
    return n > 0 ? `in ${n} days` : `${-n} days ago`;
  }
  function relTime(s) {
    const d = parseDt(s);
    if (!d) return '';
    const sec = (Date.now() - d.getTime()) / 1000;
    if (sec < 60) return 'just now';
    if (sec < 3600) return Math.floor(sec / 60) + ' min ago';
    if (sec < 86400) return Math.floor(sec / 3600) + ' h ago';
    if (sec < 7 * 86400) return Math.floor(sec / 86400) + ' d ago';
    return fmtDate(String(s).slice(0, 10));
  }
  /** Named periods → [from, to] (financial year from the boot payload). */
  function periodRange(p) {
    const t = parseYmd(today());
    const y = t.getFullYear(), m = t.getMonth();
    const fy = parseYmd(BOOT.fy.start);
    const end = (d) => ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0));
    switch (p) {
      case 'last_month': { const f = new Date(y, m - 1, 1); return [ymd(f), end(f)]; }
      case 'quarter': { const q = Math.floor(m / 3) * 3; return [ymd(new Date(y, q, 1)), ymd(new Date(y, q + 3, 0))]; }
      case 'ytd': return [BOOT.fy.start, today()];
      case 'fy': return [BOOT.fy.start, BOOT.fy.end];
      case 'last_fy': { const f = new Date(fy.getFullYear() - 1, fy.getMonth(), 1); return [ymd(f), ymd(new Date(fy.getFullYear(), fy.getMonth(), 0))]; }
      case '12m': return [ymd(new Date(y, m - 11, 1)), today()];
      case 'all': return ['2000-01-01', '2099-12-31'];
      case 'month': default: return [ymd(new Date(y, m, 1)), end(new Date(y, m, 1))];
    }
  }
  const PERIOD_LABELS = { month: 'This month', last_month: 'Last month', quarter: 'This quarter', ytd: 'Year to date', fy: 'This financial year', last_fy: 'Last financial year', '12m': 'Last 12 months', all: 'All time' };
  function periodLabel(from, to) {
    for (const [k, l] of Object.entries(PERIOD_LABELS)) { const [f, t] = periodRange(k); if (f === from && t === to) return l; }
    return fmtDate(from, 'always') + ' – ' + fmtDate(to, 'always');
  }
  /** Period picker popover. onPick(from, to). */
  function periodPicker(anchor, from, to, onPick) {
    popover(anchor, `<div class="fwm-pop__title">Period</div>
      ${Object.entries(PERIOD_LABELS).map(([k, l]) => { const [f, t] = periodRange(k); return `<button class="fwm-pop__item${f === from && t === to ? ' is-on' : ''}" type="button" data-v="${k}">${esc(l)}<small class="fwf-pop-hint">${k === 'all' ? '' : esc(fmtDate(f) + ' – ' + fmtDate(t))}</small></button>`; }).join('')}
      <div class="fwm-pop__sep"></div>
      <form class="fwf-pop-range" data-range><label>From<input class="input" type="date" name="from" value="${esc(from)}" required></label><label>To<input class="input" type="date" name="to" value="${esc(to)}" required></label><button class="btn btn--sm btn--primary" type="submit">Apply</button></form>`, {
      width: 290, noFocus: true,
      onClick: (b) => { const [f, t] = periodRange(b.dataset.v); onPick(f, t); },
    });
    const f = $('[data-range]', activePop.el);
    f.addEventListener('submit', (e) => { e.preventDefault(); const a = f.from.value, b = f.to.value; if (!a || !b) return; closePop(); onPick(a <= b ? a : b, a <= b ? b : a); });
  }

  // ------------------------------------------------------------ appearance
  const TONE = { draft: 'muted', prepared: 'info', approved: 'info', posted: 'success', reversed: 'warning', reversal: 'warning',
    open: 'info', adjusted: 'gold', filed: 'success', paid: 'success', overdue: 'danger', partial: 'warning', 'part-paid': 'warning', sent: 'info',
    viewed: 'info', void: 'muted', voided: 'muted', cancelled: 'muted', written_off: 'muted', matched: 'success', unmatched: 'warning',
    active: 'success', disposed: 'muted', fully_depreciated: 'gold', locked: 'gold', closed: 'success' };
  const LABEL = { draft: 'Draft', prepared: 'Prepared', approved: 'Approved', posted: 'Posted', reversed: 'Reversed', reversal: 'Reversal', open: 'Open',
    adjusted: 'Adjusted', filed: 'Filed', paid: 'Paid', overdue: 'Overdue', partial: 'Part paid', 'part-paid': 'Part paid', sent: 'Sent', viewed: 'Viewed',
    void: 'Void', voided: 'Void', cancelled: 'Cancelled', written_off: 'Written off', matched: 'Matched', unmatched: 'To match', active: 'In use',
    disposed: 'Disposed', fully_depreciated: 'Fully depreciated', locked: 'Locked', closed: 'Closed' };
  const statusLabel = (s) => LABEL[s] || String(s || '').replace(/_/g, ' ');
  const pill = (status, label) => `<span class="fwf-pill" data-tone="${esc(TONE[status] || 'muted')}">${esc(label || LABEL[status] || String(status || '').replace(/_/g, ' '))}</span>`;
  const tonePill = (tone, label) => `<span class="fwf-pill" data-tone="${esc(tone)}">${esc(label)}</span>`;
  const TYPE_LABEL = { asset: 'Assets', liability: 'Liabilities', equity: 'Equity', revenue: 'Income', expense: 'Expenses' };
  const TYPE_COLOR = { asset: '#4f7cac', liability: '#a8281e', equity: '#8a6fb8', revenue: '#2f7457', expense: '#b07818' };
  function hue(name) { let h = 0; for (const ch of String(name || '?')) h = (h * 31 + ch.codePointAt(0)) % 360; return h; }
  function initials(name) {
    const words = String(name || '?').replace(/\(.*?\)/g, ' ').replace(/\b(pty|ltd|cc|inc|the)\b\.?/gi, ' ').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return '?';
    const a = Array.from(words[0])[0] || '';
    const b = words.length > 1 ? Array.from(words[words.length - 1])[0] : (Array.from(words[0])[1] || '');
    return (a + b).toUpperCase();
  }
  const avatar = (name, cls = '') => `<span class="fwf-ava ${cls}" style="--h:${hue(name)}" aria-hidden="true">${esc(initials(name))}</span>`;
  const person = (id) => (BOOT.people || []).find((p) => p.id === Number(id)) || null;
  const personName = (id) => { const p = person(id); return p ? p.name : ''; };
  const acct = (code) => (BOOT.accounts || []).find((a) => a.code === String(code)) || null;
  const acctById = (id) => (BOOT.accounts || []).find((a) => a.id === Number(id)) || null;
  const acctLabel = (code) => { const a = acct(code); return a ? `${a.code} · ${a.name}` : String(code || ''); };
  const taxCode = (id) => (BOOT.tax_codes || []).find((t) => t.id === Number(id)) || null;
  const emptyState = (ic, title, text, actionHtml = '', small = false) => `<div class="fwf-empty${small ? ' fwf-empty--sm' : ''}"><span class="fwf-empty__art">${icon(ic)}</span><h3>${esc(title)}</h3>${text ? `<p>${text}</p>` : ''}${actionHtml}</div>`;
  const errorBox = (e) => emptyState('alert', 'That did not load', esc(e.message || String(e)), `<button class="btn" type="button" onclick="location.reload()">${icon('refresh')}Try again</button>`);
  const skel = (n = 4, h = 52) => `<div class="fwf-skels">${`<div class="fwf-skel" style="height:${h}px"></div>`.repeat(n)}</div>`;

  // ---------------------------------------------------------------- charts
  /** Line sparkline from a list of numbers. */
  function sparkline(vals, color = 'var(--gold)', h = 52) {
    if (!vals.length || vals.every((v) => !v)) return '';
    const max = Math.max(...vals), min = Math.min(...vals, 0);
    const span = Math.max(1, max - min);
    const pts = vals.map((v, i) => `${(i / Math.max(1, vals.length - 1)) * 100},${h - 4 - ((v - min) / span) * (h - 12)}`).join(' ');
    return `<svg class="fwf-spark" viewBox="0 0 100 ${h}" preserveAspectRatio="none" aria-hidden="true"><polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/><polygon points="0,${h} ${pts} 100,${h}" fill="${color}" opacity=".08"/></svg>`;
  }
  /** Paired bars (in / out) per bucket with a net line: [{label, a, b, tip, current, future}]. */
  function pairBars(rows, { aLabel = 'In', bLabel = 'Out', net = true, height = 200 } = {}) {
    if (!rows.length) return '';
    const max = Math.max(1, ...rows.map((r) => Math.max(Math.abs(r.a), Math.abs(r.b))));
    const nets = rows.map((r) => r.a - r.b);
    const nmax = Math.max(1, ...nets.map(Math.abs));
    const w = 100 / rows.length;
    const line = net ? `<svg class="fwf-pair__net" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="var(--accent-solid)" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"
      points="${nets.map((v, i) => `${(i + 0.5) * w},${50 - (v / nmax) * 44}`).join(' ')}"/></svg>` : '';
    return `<div class="fwf-pair" style="--h:${height}px" role="img" aria-label="${esc(aLabel)} and ${esc(bLabel)} per period">
      ${line}${rows.map((r) => `<div class="fwf-pair__col${r.current ? ' is-current' : ''}${r.future ? ' is-future' : ''}" data-tip="${esc(r.tip || '')}">
        <div class="fwf-pair__bars"><i class="a" style="height:${(Math.abs(r.a) / max) * 100}%"></i><i class="b" style="height:${(Math.abs(r.b) / max) * 100}%"></i></div>
        <span>${esc(r.label)}</span></div>`).join('')}</div>
      <div class="fwf-legend"><span><i class="a"></i>${esc(aLabel)}</span><span><i class="b"></i>${esc(bLabel)}</span>${net ? '<span><i class="n"></i>Net</span>' : ''}</div>`;
  }
  /** A stacked horizontal bar of shares: [{label, value, color}]. */
  function shareBar(parts) {
    const total = parts.reduce((s, p) => s + Math.max(0, p.value), 0);
    if (total <= 0) return '';
    return `<div class="fwf-share" role="img" aria-label="${esc(parts.map((p) => p.label + ' ' + money0(p.value)).join(', '))}">${parts.filter((p) => p.value > 0).map((p) => `<i style="flex:${p.value};--c:${p.color}" data-tip="${esc(p.label + ' · ' + money0(p.value))}"></i>`).join('')}</div>`;
  }
  /** Aging buckets as bars: [{label, value, count, tone}]. */
  function agingBars(buckets, hrefFn) {
    const max = Math.max(1, ...buckets.map((b) => b.value));
    return `<div class="fwf-aging">${buckets.map((b, i) => `<a class="fwf-aging__b" ${hrefFn ? `href="${esc(hrefFn(b, i))}"` : ''} style="--p:${(b.value / max) * 100}%;--c:${b.tone || 'var(--gold)'}">
      <span class="fwf-aging__track"><i></i></span><b>${esc(compact(b.value))}</b><small>${esc(b.label)}${b.count ? ' · ' + b.count : ''}</small></a>`).join('')}</div>`;
  }

  // ------------------------------------------------------------ pickers
  /**
   * Turn an <input> into an account picker. The input shows "code · name"; the
   * chosen code is in input.dataset.code. opts: {filter(a), onPick(a), manualOnly}
   */
  function accountPicker(input, opts = {}) {
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    const list = () => (BOOT.accounts || []).filter((a) => a.active && (!opts.manualOnly || a.manual) && (!opts.filter || opts.filter(a)));
    const set = (a) => {
      input.dataset.code = a ? a.code : '';
      input.dataset.id = a ? a.id : '';
      input.value = a ? `${a.code} · ${a.name}` : '';
      input.classList.toggle('is-picked', !!a);
      if (opts.onPick) opts.onPick(a);
    };
    if (input.dataset.code) { const a = acct(input.dataset.code); if (a) set(a); }
    let pop = null, items = [], active = 0;
    const paint = () => {
      const q = input.value.trim().toLowerCase();
      const words = q.replace(/·/g, ' ').split(/\s+/).filter(Boolean);
      items = list().filter((a) => !words.length || words.every((w) => (a.code + ' ' + a.name + ' ' + (TYPE_LABEL[a.type] || '')).toLowerCase().includes(w))).slice(0, 60);
      if (q && /^\d/.test(q)) items.sort((x, y) => (y.code.startsWith(q) ? 1 : 0) - (x.code.startsWith(q) ? 1 : 0));
      active = Math.min(active, Math.max(0, items.length - 1));
      const html = items.length ? items.map((a, i) => `<button class="fwm-pop__item fwf-acpick${i === active ? ' is-active' : ''}" type="button" data-v="${esc(a.code)}" role="option" aria-selected="${i === active}"><b>${esc(a.code)}</b><span class="fwf-ellip">${esc(a.name)}</span><small style="--c:${TYPE_COLOR[a.type]}">${esc(TYPE_LABEL[a.type] || a.type)}</small></button>`).join('')
        : `<div class="fwf-pop-empty">No account matches “${esc(input.value)}”.</div>`;
      if (!pop || !pop.el.isConnected) {
        pop = popover(input, `<div class="fwf-acpick__list" role="listbox">${html}</div>`, { noFocus: true, width: Math.max(320, input.offsetWidth), cls: 'fwf-pop-scroll',
          onClick: (b) => { const a = acct(b.dataset.v); set(a); input.focus(); },
          onClose: () => input.setAttribute('aria-expanded', 'false') });
        input.setAttribute('aria-expanded', 'true');
      } else {
        $('.fwf-acpick__list', pop.el).innerHTML = html;
        pop.place();
      }
      const a = $('.is-active', pop.el);
      if (a) a.scrollIntoView({ block: 'nearest' });
    };
    input.addEventListener('focus', () => { input.select(); paint(); });
    input.addEventListener('input', () => { input.dataset.code = ''; input.classList.remove('is-picked'); active = 0; paint(); });
    input.addEventListener('keydown', (e) => {
      if (!pop || !pop.el.isConnected) { if (e.key === 'ArrowDown') { paint(); e.preventDefault(); } return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); paint(); }
      else if (e.key === 'Enter' || (e.key === 'Tab' && input.value && !input.dataset.code)) { if (items[active]) { if (e.key === 'Enter') e.preventDefault(); set(items[active]); closePop(); } }
      else if (e.key === 'Escape') { closePop(); }
    });
    input.addEventListener('blur', () => setTimeout(() => {
      if (pop && pop.el.contains(document.activeElement)) return;
      if (!input.dataset.code && input.value.trim()) {
        const v = input.value.trim().toLowerCase();
        const exact = list().find((a) => a.code.toLowerCase() === v || (a.code + ' · ' + a.name).toLowerCase() === v);
        if (exact) set(exact);
      }
      if (pop && activePop === pop) closePop();
    }, 120));
    return { set, get: () => acct(input.dataset.code) };
  }
  const accountOptions = (sel, filter) => `<option value="">— choose —</option>${(BOOT.accounts || []).filter((a) => a.active && (!filter || filter(a))).map((a) => `<option value="${a.id}"${String(sel) === String(a.id) ? ' selected' : ''}>${esc(a.code + ' · ' + a.name)}</option>`).join('')}`;
  const taxOptions = (sel, types) => `<option value="">No VAT</option>${(BOOT.tax_codes || []).filter((t) => !types || types.includes(t.type)).map((t) => `<option value="${t.id}"${String(sel) === String(t.id) ? ' selected' : ''}>${esc(t.code + ' · ' + t.rate + '%')}</option>`).join('')}`;
  const bankOptions = (sel) => (BOOT.banks || []).map((b) => `<option value="${b.id}"${String(sel) === String(b.id) ? ' selected' : ''}>${esc(b.name + (b.last4 ? ' ··' + b.last4 : ''))}</option>`).join('');

  // ---------------------------------------------------------------- boot
  async function refreshBoot() {
    const d = await api.get('boot.php');
    Object.assign(BOOT, d.boot);
    document.dispatchEvent(new CustomEvent('fin:boot'));
    paintCounts();
    return BOOT;
  }
  async function savePrefs(changes) {
    const d = await api.post('prefs.php', changes);
    BOOT.prefs = d.prefs;
    return d.prefs;
  }
  function setCount(key, n) {
    const c = $(`[data-count="${key}"]`);
    if (!c) return;
    c.textContent = n ? n : '';
    c.classList.toggle('fwf-due', !!n);
  }
  function paintCounts() { Object.entries(BOOT.counts || {}).forEach(([k, n]) => setCount(k, n)); }

  // ---------------------------------------------------------------- chrome
  const newItems = () => {
    if (!can('write')) return [];
    const out = [['journal', 'if-journal', 'New ' + lower('journal')]];
    if (mod('payables')) { out.push(['bill', 'if-out', 'New ' + lower('bill')]); out.push(['supplier_payment', 'if-cash', 'Pay a ' + lower('supplier')]); out.push(['vendor_credit', 'if-credit', lower('supplier') === 'supplier' ? 'Supplier credit note' : L('supplier') + ' credit note']); }
    if (mod('receivables')) out.push(['customer_payment', 'if-in', 'Receive a payment']);
    if (mod('bank')) out.push(['bank_import', 'upload', 'Import a bank statement']);
    if (mod('assets')) out.push(['asset', 'if-asset', 'New ' + lower('asset')]);
    out.push(['account', 'if-tree', 'New ' + lower('account')]);
    return out;
  };
  function createMenu(anchor, align = 'start') {
    popover(anchor, `<div class="fwm-pop__title">Create</div>${newItems().map(([v, ic, label]) => `<button class="fwm-pop__item" type="button" data-v="${v}">${icon(ic)}${esc(label)}</button>`).join('')}`,
      { align, onClick: (b) => { FIN.create(b.dataset.v); } });
  }
  /** Screens register what "create X" does. */
  const creators = {};
  function create(kind, opts = {}) {
    if (creators[kind]) return creators[kind](opts);
    location.href = '/finances/#' + ({ journal: 'journals?new=1', bill: 'payables?new=bill', supplier_payment: 'payables?new=payment', vendor_credit: 'payables?new=credit',
      customer_payment: 'receivables?new=payment', bank_import: 'bank?new=import', asset: 'assets?new=1', account: 'accounts?new=1' }[kind] || 'overview');
  }
  function initChrome(opts = {}) {
    const body = document.body;
    const themeBtn = $('#themeToggle');
    const applyTheme = (t) => {
      body.dataset.theme = t;
      const meta = $('meta[name="theme-color"]');
      if (meta) meta.setAttribute('content', t === 'dark' ? '#0d0a09' : '#f2ece3');
      if (themeBtn) { themeBtn.innerHTML = icon(t === 'dark' ? 'sun' : 'moon'); themeBtn.setAttribute('data-tip', t === 'dark' ? 'Light theme' : 'Dark theme'); }
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
      popover(kebab, `
        <a class="fwm-pop__item" data-v="s" href="/finances/settings.php">${icon('if-sliders')}Make it yours</a>
        <a class="fwm-pop__item" data-v="h" href="/finances/#close?view=health">${icon('if-health')}Health checks</a>
        <button class="fwm-pop__item" type="button" data-v="k">${icon('keyboard')}Keyboard shortcuts</button>
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="qi" href="/qi/">${icon('iq-invoice')}Quotes &amp; Invoices</a>
        <a class="fwm-pop__item" data-v="crm" href="/crm/">${icon('users')}CRM</a>
        <a class="fwm-pop__item" data-v="rc" href="/receipts/">${icon('receipt')}Receipts</a>
        <a class="fwm-pop__item" data-v="pr" href="/payroll/">${icon('if-payroll')}Payroll</a>
        <a class="fwm-pop__item" data-v="home" href="/home.php">${icon('home')}Flowwork home</a>`, { align: 'end', onClick: (b) => { if (b.dataset.v === 'k') shortcuts(); } });
    });
    const quick = $('#quickNew');
    if (quick) quick.addEventListener('click', () => createMenu(quick, 'end'));
    const newBtn = $('#newBtn');
    if (newBtn) newBtn.addEventListener('click', () => create(newBtn.dataset.new));
    const newMore = $('#newMore');
    if (newMore) newMore.addEventListener('click', () => createMenu(newMore));
    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
    const pal = $('#paletteBtn');
    if (pal) pal.addEventListener('click', () => palette());
    let gPrefix = 0;
    document.addEventListener('keydown', (e) => {
      const t = e.target;
      const typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); palette(); return; }
      if (typing || e.ctrlKey || e.metaKey || e.altKey || $('.fwm-modal:not([hidden])') || $('.fwf-palette')) return;
      if (e.key === '/') { e.preventDefault(); palette(); return; }
      if (e.key === '?') { e.preventDefault(); shortcuts(); return; }
      if (e.key === 'c' && can('write')) { e.preventDefault(); const q = $('#quickNew'); if (q) createMenu(q, 'end'); return; }
      if (e.key === 'n' && can('write')) { e.preventDefault(); create('journal'); return; }
      if (e.key === 'g') { gPrefix = Date.now(); return; }
      if (Date.now() - gPrefix < 1200) {
        const r = GO_KEYS[e.key];
        if (r && (BOOT.routes || []).some((x) => x.key === r)) { e.preventDefault(); gPrefix = 0; go(r); }
      }
    });
    paintCounts();
  }
  const GO_KEYS = { o: 'overview', j: 'journals', l: 'ledger', a: 'accounts', b: 'bank', r: 'receivables', p: 'payables', v: 'vat', f: 'assets', y: 'payroll', u: 'budgets', e: 'reports', c: 'close' };
  function go(route) {
    if (location.pathname === '/finances/' || location.pathname === '/finances/index.php') location.hash = '#' + route;
    else location.href = '/finances/#' + route;
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
    const names = Object.fromEntries((BOOT.routes || []).map((r) => [r.key, r.label]));
    const rows = [['Ctrl K or /', 'Search everything'], ['c', 'Create…'], ['n', 'New ' + lower('journal')],
      ...Object.entries(GO_KEYS).filter(([, r]) => names[r]).map(([k, r]) => ['g then ' + k, names[r]]),
      ['Ctrl Enter', 'Save the form you are in'], ['Esc', 'Close'], ['?', 'This list']];
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Keyboard shortcuts</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fwm-kbd-grid">${rows.map(([k, l]) => `<div class="fwm-kbd-row"><span>${esc(l)}</span><span>${k.split(' then ').map((x) => `<kbd class="fwm-kbd">${esc(x)}</kbd>`).join(' then ')}</span></div>`).join('')}</div></div>`, '', 'width:min(480px,100%)');
    openModal(m);
  }
  // Ctrl/⌘ Enter submits the open form dialog.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey)) return;
    const m = $$('.fwm-modal:not([hidden])').pop();
    const b = m && $('[data-submit]', m);
    if (b) { e.preventDefault(); b.click(); }
  });

  // ------------------------------------------------------ command palette
  let palOpen = false;
  function palette() {
    if (palOpen) return;
    palOpen = true;
    const wrap = document.createElement('div');
    wrap.className = 'fwf-palette';
    wrap.innerHTML = `<div class="fwf-palette__box" role="dialog" aria-modal="true" aria-label="Search Finance">
      <label class="fwf-palette__input">${icon('search')}<input type="search" placeholder="Search accounts, journals, invoices, ${esc(lower('bills'))}, ${esc(lower('suppliers'))}, amounts…" autocomplete="off" spellcheck="false" aria-controls="palList"><kbd class="fwm-kbd">Esc</kbd></label>
      <div class="fwf-palette__list" id="palList" role="listbox"></div>
      <div class="fwf-palette__foot"><span><kbd class="fwm-kbd">↑</kbd> <kbd class="fwm-kbd">↓</kbd> move</span><span><kbd class="fwm-kbd">Enter</kbd> open</span><span>Try “4000”, “INV-0012”, “1250.00” or “VAT”</span></div></div>`;
    document.body.appendChild(wrap);
    const input = $('input', wrap);
    const list = $('.fwf-palette__list', wrap);
    let items = [];
    let active = 0;
    let seq = 0;
    const close = () => { palOpen = false; wrap.remove(); const i = escHandlers.indexOf(close); if (i >= 0) escHandlers.splice(i, 1); };
    escHandlers.push(close);
    wrap.addEventListener('mousedown', (e) => { if (e.target === wrap) close(); });
    const ICONS = { overview: 'if-overview', journals: 'if-journal', ledger: 'if-ledger', accounts: 'if-tree', bank: 'if-bank', receivables: 'if-in', payables: 'if-out',
      vat: 'if-vat', assets: 'if-asset', payroll: 'if-payroll', budgets: 'if-budget', reports: 'if-report', close: 'if-close' };
    const actions = () => {
      const out = [];
      newItems().forEach(([v, ic, label]) => out.push({ group: 'Create', icon: ic, title: label, run: () => create(v) }));
      (BOOT.routes || []).forEach((r) => out.push({ group: 'Go to', icon: ICONS[r.key], title: r.label, run: () => go(r.key) }));
      [['Trial balance', 'reports?r=trial_balance'], ['Profit and loss', 'reports?r=income_statement'], ['Balance sheet', 'reports?r=balance_sheet'],
        ['Cash flow statement', 'reports?r=cashflow'], ['VAT201 return', 'vat'], ['Aged receivables', 'reports?r=ar_aging'], ['Aged payables', 'reports?r=ap_aging'],
        ['Health checks', 'close?tab=health'], ['Period locks', 'close']].forEach(([t, r]) => out.push({ group: 'Reports & tools', icon: 'if-report', title: t, run: () => go(r) }));
      out.push({ group: 'Reports & tools', icon: 'if-sliders', title: 'Make it yours (settings)', run: () => { location.href = '/finances/settings.php'; } });
      return out;
    };
    const render = () => {
      let lastGroup = '';
      list.innerHTML = items.length ? items.map((it, i) => {
        const head = it.group !== lastGroup ? `<div class="fwf-palette__group">${esc(it.group)}</div>` : '';
        lastGroup = it.group;
        return `${head}<button class="fwf-palette__item${i === active ? ' is-active' : ''}" type="button" role="option" aria-selected="${i === active}" data-i="${i}">${it.html || icon(it.icon)}<span class="fwf-palette__text"><b>${esc(it.title)}</b>${it.sub ? `<small>${esc(it.sub)}</small>` : ''}</span>${it.side || ''}</button>`;
      }).join('') : `<div class="fwf-empty fwf-empty--sm"><p>Nothing matches “${esc(input.value)}”.</p></div>`;
      const a = $('.is-active', list);
      if (a) a.scrollIntoView({ block: 'nearest' });
    };
    const search = debounce(async () => {
      const q = input.value.trim();
      const my = ++seq;
      if (!q) { items = actions(); active = 0; render(); return; }
      const ql = q.toLowerCase();
      const acts = actions().filter((a) => a.title.toLowerCase().includes(ql));
      const words = ql.split(/\s+/);
      const accs = (BOOT.accounts || []).filter((a) => words.every((w) => (a.code + ' ' + a.name).toLowerCase().includes(w))).slice(0, 6)
        .map((a) => ({ group: L('accounts'), html: `<span class="fwf-code">${esc(a.code)}</span>`, title: a.name, sub: (TYPE_LABEL[a.type] || a.type) + (a.active ? '' : ' · inactive'), run: () => go('ledger?account=' + encodeURIComponent(a.code)) }));
      items = [...accs, ...acts];
      active = 0;
      render();
      if (q.length < 2) return;
      try {
        const d = await api.get('search.php', { q });
        if (my !== seq) return;
        items = [...accs, ...d.results.map((r) => ({ group: r.group, icon: r.icon, title: r.title, sub: r.sub, side: r.amount != null ? `<span class="fwf-palette__amt">${esc(money(r.amount))}</span>` : (r.status ? pill(r.status) : ''), run: () => { if (r.href.startsWith('#')) go(r.href.slice(1)); else location.href = r.href; } })), ...acts];
        render();
      } catch (e) { /* keep local results */ }
    }, 140);
    input.addEventListener('input', search);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); render(); }
      else if (e.key === 'Enter') { e.preventDefault(); const it = items[active]; if (it) { close(); it.run(); } }
    });
    list.addEventListener('click', (e) => { const b = e.target.closest('[data-i]'); if (!b) return; const it = items[+b.dataset.i]; close(); it.run(); });
    items = actions();
    render();
    setTimeout(() => input.focus(), 20);
  }

  // --------------------------------------------------- drag to reorder
  function sortable(host, sel, onEnd) {
    let dragging = null;
    host.addEventListener('dragstart', (e) => {
      const row = e.target.closest(sel);
      if (!row || !host.contains(row)) return;
      dragging = row;
      row.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', 'x'); } catch (x) { /* old browsers */ }
    });
    host.addEventListener('dragover', (e) => {
      if (!dragging) return;
      e.preventDefault();
      const over = e.target.closest(sel);
      if (!over || over === dragging || !host.contains(over)) return;
      const r = over.getBoundingClientRect();
      over.parentNode.insertBefore(dragging, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
    });
    host.addEventListener('dragend', () => {
      if (!dragging) return;
      dragging.classList.remove('is-dragging');
      dragging = null;
      onEnd($$(sel, host));
    });
    host.addEventListener('keydown', (e) => {
      if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
      const row = e.target.closest(sel);
      if (!row) return;
      e.preventDefault();
      if (e.key === 'ArrowUp' && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
      if (e.key === 'ArrowDown' && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
      e.target.focus();
      onEnd($$(sel, host));
    });
  }

  /** Download helper for export links (keeps the page; shows a toast on failure). */
  function download(url) {
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
  }

  window.FIN = {
    $, $$, esc, icon, csrf, debounce, narrow, api, ApiError, toast, toastError, popover, closePop, openModal, closeModal, tempModal, dialog, formDialog,
    sheet, closeSheet, BOOT, L, lower, can, mod, money, money0, compact, amount, dc, group, r2, parseMoney, fmtDate, fmtMonth, parseYmd, parseDt, ymd, today,
    daysFrom, relDay, relTime, periodRange, periodLabel, periodPicker, PERIOD_LABELS, MONTHS, pill, tonePill, statusLabel, TONE, TYPE_LABEL, TYPE_COLOR, hue, initials, avatar,
    person, personName, acct, acctById, acctLabel, taxCode, emptyState, errorBox, skel, sparkline, pairBars, shareBar, agingBars, accountPicker,
    accountOptions, taxOptions, bankOptions, refreshBoot, savePrefs, setCount, paintCounts, initChrome, toggleDrawer, create, creators, createMenu,
    palette, shortcuts, sortable, go, escHandlers, download,
  };
})();
