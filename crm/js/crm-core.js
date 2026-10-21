// /crm/js/crm-core.js — shared helpers for every CRM page.
// window.CRM: $, $$, esc, icon, api (JSON over /crm/api with the CSRF header),
// toast, popover, modal/dialog, money & date formatting, monogram avatars,
// status and stage pills, the theme switch, the kebab and "create" menus,
// the narrow-screen drawer, the command palette (Ctrl K) and shortcuts.
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#${/^(ic|iq|ih|i)-/.test(name) ? name : 'i-' + name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const narrow = () => window.matchMedia('(max-width: 820px)').matches;
  const BOOT = JSON.parse(($('#crmBoot') || {}).textContent || '{}');
  const L = (k) => (BOOT.config && BOOT.config.labels && BOOT.config.labels[k]) || k;
  const lower = (k) => L(k).toLowerCase();

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
    get: (path, params) => { const q = qs(params); return request('GET', '/crm/api/' + path + (q ? '?' + q : '')); },
    post: (path, body) => request('POST', '/crm/api/' + path, body || {}),
    upload: (path, formData) => request('POST', '/crm/api/' + path, formData, true),
  };

  // ---------------------------------------------------------------- toasts
  function toast(message, opts = {}) {
    let host = $('#toasts');
    if (!host) { host = document.createElement('div'); host.id = 'toasts'; host.className = 'fwm-toasts'; host.setAttribute('aria-live', 'polite'); document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'fwm-toast' + (opts.type === 'error' ? ' fwm-toast--error' : '');
    el.setAttribute('role', opts.type === 'error' ? 'alert' : 'status');
    el.innerHTML = `<span class="fwm-toast__msg"></span>${(opts.actions || (opts.action ? [opts.action] : [])).map((a, i) => `<button class="fwm-toast__action" type="button" data-i="${i}">${esc(a.label)}</button>`).join('')}<button class="fwm-toast__close" type="button" aria-label="Dismiss">${icon('x', 'i--sm')}</button>`;
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
    const acts = opts.actions || (opts.action ? [opts.action] : []);
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
    if (closer) { closeModal(closer.closest('.fwm-modal')); return; }
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
    if (h) { h.id = h.id || 'fwcDlg' + (++dlgSeq); $('[role=dialog]', m).setAttribute('aria-labelledby', h.id); }
    document.body.appendChild(m);
    return m;
  }
  /** Promise dialog: confirm, a text input ({input:{value, placeholder, label, type, multiline}}) or choices ([{v, label, hint}]). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, choices = null, value = null, input = null, other = false }) {
    return new Promise((resolve) => {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body">${body ? `<p class="fwm-dialog__lead" style="margin-bottom:${choices || input ? 14 : 0}px">${body}</p>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}${input.multiline
            ? `<textarea class="textarea" id="dlgInput" maxlength="${input.max || 500}" placeholder="${esc(input.placeholder || '')}" autofocus>${esc(input.value || '')}</textarea>`
            : `<input class="input" id="dlgInput" type="${esc(input.type || 'text')}" maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autofocus>`}</div>` : ''}
          ${choices ? `<div class="fwm-results">${choices.map((c, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="dlgChoice" value="${esc(c.v)}" ${(value ? c.v === value : i === 0) ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}
            ${other ? `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="dlgChoice" value="__other" style="accent-color:var(--accent)"><span class="fwm-result__text" style="flex:1"><b>Something else</b><input class="input" id="dlgOther" style="margin-top:6px" maxlength="200" placeholder="Say why"></span></label>` : ''}</div>` : ''}</form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn btn--primary${danger ? ' fwc-danger' : ''}" type="button" data-act="yes">${esc(confirm)}</button></div>`, '', 'width:min(480px,100%)');
      let done = false;
      const result = () => {
        if (input) return $('#dlgInput', m).value.trim();
        if (choices) {
          const v = ($('input[name=dlgChoice]:checked', m) || {}).value || null;
          return v === '__other' ? ($('#dlgOther', m).value.trim() || null) : v;
        }
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
      const oth = $('#dlgOther', m);
      if (oth) oth.addEventListener('focus', () => { const r = $('input[value="__other"]', m); if (r) r.checked = true; });
      $('form', m).addEventListener('submit', (e) => { e.preventDefault(); finish(result()); });
      openModal(m);
    });
  }

  // ------------------------------------------------------- money and dates
  function group(v, places = 2) {
    const neg = v < 0;
    const s = Math.abs(v).toFixed(places);
    const [int, dec] = s.split('.');
    return (neg ? '−' : '') + int.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (dec ? '.' + dec : '');
  }
  const money = (v, symbol = 'R') => (symbol ? symbol + ' ' : '') + group(Number(v) || 0);
  const money0 = (v, symbol = 'R') => (symbol ? symbol + ' ' : '') + group(Math.round(Number(v) || 0), 0);
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
  function parseDt(s) {
    const d = s ? new Date(String(s).replace(' ', 'T')) : null;
    return d && !isNaN(d) ? d : null;
  }
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  function fmtDate(s, withYear = true) {
    const d = parseYmd(s);
    if (!d) return '';
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (withYear && d.getFullYear() !== new Date().getFullYear() ? ' ' + d.getFullYear() : (withYear === 'always' ? ' ' + d.getFullYear() : ''));
  }
  const fmtTime = (s) => { const d = parseDt(s); return d ? pad(d.getHours()) + ':' + pad(d.getMinutes()) : ''; };
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
  const ago = (s) => { const d = parseDt(s); if (!d) return null; return Math.floor((Date.now() - d.getTime()) / 86400000); };

  // ------------------------------------------------------------ appearance
  function hue(name) {
    let h = 0;
    for (const ch of String(name || '?')) h = (h * 31 + ch.codePointAt(0)) % 360;
    return h;
  }
  function initials(name) {
    const words = String(name || '?').replace(/\(.*?\)/g, ' ').replace(/\b(pty|ltd|cc|inc|the)\b\.?/gi, ' ').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return '?';
    const a = Array.from(words[0])[0] || '';
    const b = words.length > 1 ? Array.from(words[words.length - 1])[0] : (Array.from(words[0])[1] || '');
    return (a + b).toUpperCase();
  }
  const avatar = (name, cls = '', star = false) => `<span class="fwc-ava ${cls}" style="--h:${hue(name)}" aria-hidden="true">${esc(initials(name))}${star ? `<span class="fwc-ava__star">${icon('star')}</span>` : ''}</span>`;
  const person = (id) => (BOOT.people || []).find((p) => p.id === Number(id)) || null;
  const personAva = (id, name) => { const p = person(id); const n = (p && p.name) || name || '?'; return `<span class="fwc-ava fwc-ava--xs" style="--h:${hue(n)}" title="${esc(n)}">${esc(initials(n))}</span>`; };
  function statusMeta(key) {
    const s = (BOOT.config.statuses || {})[key] || { label: key, color: '#85766b' };
    return s;
  }
  const statusPill = (key) => { const s = statusMeta(key); return `<span class="fwc-pill" style="--c:${esc(s.color)}">${esc(s.label)}</span>`; };
  const stageMeta = (key) => (BOOT.stages || []).find((s) => s.key === key) || { key, label: key, color: '#85766b', kind: 'open', probability: 0 };
  const stagePill = (key) => { const s = stageMeta(key); return `<span class="fwc-pill" style="--c:${esc(s.color)}">${esc(s.label)}</span>`; };
  const tone = (t) => ({ success: 'var(--success)', warning: 'var(--warning)', danger: 'var(--danger)', info: 'var(--info)', muted: 'var(--ink-4)', gold: 'var(--gold)' }[t] || 'var(--gold)');
  const pill = (status) => `<span class="fwc-pill" data-tone="${esc(status.tone || 'muted')}">${esc(status.label || '')}</span>`;
  const tagChip = (t, removable = false) => `<span class="fwc-tag" style="--c:${esc(t.color || '#85766b')}" data-tag="${t.id}"><span>${esc(t.name)}</span>${removable ? `<button type="button" data-untag="${t.id}" aria-label="Remove tag ${esc(t.name)}">${icon('x', 'i--xs')}</button>` : ''}</span>`;
  const ACT_ICON = { note: 'ic-note', call: 'phone', email: 'mail', meeting: 'ic-meeting', visit: 'ic-visit', whatsapp: 'whatsapp', issue: 'ic-issue' };
  const actIcon = (t) => icon(ACT_ICON[t] || 'ic-note');
  const telHref = (p) => 'tel:' + String(p || '').replace(/[^\d+]/g, '');
  function waHref(p) {
    let d = String(p || '').replace(/\D/g, '');
    if (d.startsWith('0') && d.length === 10) d = '27' + d.slice(1);
    return 'https://wa.me/' + d;
  }
  const fmtPhone = (p) => {
    const s = String(p || '');
    const m = /^\+27(\d{2})(\d{3})(\d{4})$/.exec(s);
    if (m) return `+27 ${m[1]} ${m[2]} ${m[3]}`;
    const n = /^0(\d{2})(\d{3})(\d{4})$/.exec(s);
    if (n) return `0${n[1]} ${n[2]} ${n[3]}`;
    return s;
  };
  const accountUrl = (id, hash = '') => `/crm/account_view.php?id=${encodeURIComponent(id)}${hash ? '#' + hash : ''}`;
  const dealUrl = (id) => `/crm/opp_view.php?id=${encodeURIComponent(id)}`;
  function sparkline(vals, color, h = 52) {
    if (!vals.length || vals.every((v) => !v)) return '';
    const max = Math.max(...vals, 1);
    const pts = vals.map((v, i) => `${(i / Math.max(1, vals.length - 1)) * 100},${h - 4 - (v / max) * (h - 12)}`).join(' ');
    return `<svg class="fwc-spark" viewBox="0 0 100 ${h}" preserveAspectRatio="none" aria-hidden="true"><polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/><polygon points="0,${h} ${pts} 100,${h}" fill="${color}" opacity=".08"/></svg>`;
  }
  const emptyState = (ic, title, text, actionHtml = '', small = false) => `<div class="fwc-empty${small ? ' fwc-empty--sm' : ''}"><span class="fwc-empty__art">${icon(ic)}</span><h3>${esc(title)}</h3>${text ? `<p>${text}</p>` : ''}${actionHtml}</div>`;
  const errorBox = (e) => emptyState('alert', 'That did not load', esc(e.message || String(e)), `<button class="btn" type="button" onclick="location.reload()">${icon('refresh')}Try again</button>`);

  // ---------------------------------------------------------------- boot
  async function refreshBoot() {
    const d = await api.get('boot.php');
    Object.assign(BOOT, d.boot);
    document.dispatchEvent(new CustomEvent('crm:boot'));
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
    c.textContent = n ? n : (key === 'customers' || key === 'suppliers' ? '0' : '');
    if (key === 'tasks') c.classList.toggle('fwc-due', !!n);
  }

  // ---------------------------------------------------------------- chrome
  const newItems = () => {
    const m = BOOT.config.modules;
    const out = [];
    if (m.customers) out.push(['customer', 'ic-customer', 'New ' + lower('customer')]);
    if (m.suppliers) out.push(['supplier', 'ic-supplier', 'New ' + lower('supplier')]);
    if (m.pipeline) out.push(['deal', 'i-kanban', 'New ' + lower('deal')]);
    if (m.tasks) out.push(['task', 'i-check-square', 'New ' + lower('task')]);
    return out;
  };
  function createMenu(anchor, align = 'start') {
    popover(anchor, `<div class="fwm-pop__title">Create</div>${newItems().map(([v, ic, label]) => `<button class="fwm-pop__item" type="button" data-v="${v}">${icon(ic)}${esc(label)}</button>`).join('')}`,
      { align, onClick: (b) => { CRM.create(b.dataset.v); } });
  }
  /** Pages register what "create X" does (forms are in crm-forms.js). */
  function create(kind, opts = {}) {
    if (!CRM.forms) return;
    if (kind === 'customer' || kind === 'supplier') return CRM.forms.account({ type: kind, ...opts });
    if (kind === 'deal') return CRM.forms.deal(opts);
    if (kind === 'task') return CRM.forms.task(opts);
  }
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
        ${BOOT.is_admin ? `<a class="fwm-pop__item" data-v="i" href="/crm/import.php">${icon('upload')}Import &amp; export</a>
        <a class="fwm-pop__item" data-v="d" href="/crm/dedupe.php">${icon('ic-merge')}Find duplicates</a>` : ''}
        <a class="fwm-pop__item" data-v="s" href="/crm/settings.php">${icon('ic-sliders')}Make it yours</a>
        <button class="fwm-pop__item" type="button" data-v="k">${icon('keyboard')}Keyboard shortcuts</button>
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="qi" href="/qi/">${icon('iq-invoice')}Quotes &amp; Invoices</a>
        <a class="fwm-pop__item" data-v="cal" href="/calendar/">${icon('calendar')}Calendar</a>
        <a class="fwm-pop__item" data-v="mail" href="/mail/">${icon('mail')}Mail</a>
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
      if (typing || e.ctrlKey || e.metaKey || e.altKey || $('.fwm-modal:not([hidden])') || $('.fwc-palette')) return;
      if (e.key === '/') { e.preventDefault(); palette(); return; }
      if (e.key === '?') { e.preventDefault(); shortcuts(); return; }
      if (e.key === 'c' && BOOT.can_write) { e.preventDefault(); const q = $('#quickNew'); if (q) createMenu(q, 'end'); return; }
      if (e.key === 'g') { gPrefix = Date.now(); return; }
      if (Date.now() - gPrefix < 1200) {
        const map = { o: 'overview', c: 'customers', s: 'suppliers', p: 'pipeline', t: 'tasks', k: 'contacts', a: 'activity' };
        if (map[e.key]) { e.preventDefault(); gPrefix = 0; go(map[e.key]); }
      }
    });
  }
  function go(route) {
    if (location.pathname === '/crm/' || location.pathname === '/crm/index.php') location.hash = '#' + route;
    else location.href = '/crm/#' + route;
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
    const rows = [['Ctrl K or /', 'Search everything'], ['c', 'Create…'], ['g then o', 'Overview'], ['g then c', L('customers')], ['g then s', L('suppliers')],
      ['g then p', L('pipeline')], ['g then t', L('tasks')], ['g then k', L('contacts')], ['g then a', 'Activity'], ['Esc', 'Close'], ['?', 'This list']];
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Keyboard shortcuts</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fwm-kbd-grid">${rows.map(([k, l]) => `<div class="fwm-kbd-row"><span>${esc(l)}</span><span>${k.split(' then ').map((x) => `<kbd class="fwm-kbd">${esc(x)}</kbd>`).join(' then ')}</span></div>`).join('')}</div></div>`, '', 'width:min(460px,100%)');
    openModal(m);
  }

  // ------------------------------------------------------ command palette
  let palOpen = false;
  function palette() {
    if (palOpen) return;
    palOpen = true;
    const wrap = document.createElement('div');
    wrap.className = 'fwc-palette';
    wrap.innerHTML = `<div class="fwc-palette__box" role="dialog" aria-modal="true" aria-label="Search the CRM">
      <label class="fwc-palette__input">${icon('search')}<input type="search" placeholder="Search ${esc(lower('customers'))}, ${esc(lower('suppliers'))}, people, ${esc(lower('deals'))}…" autocomplete="off" spellcheck="false"><kbd class="fwm-kbd">Esc</kbd></label>
      <div class="fwc-palette__list" role="listbox"></div>
      <div class="fwc-palette__foot"><span><kbd class="fwm-kbd">↑</kbd> <kbd class="fwm-kbd">↓</kbd> move</span><span><kbd class="fwm-kbd">Enter</kbd> open</span><span><kbd class="fwm-kbd">Esc</kbd> close</span></div></div>`;
    document.body.appendChild(wrap);
    const input = $('input', wrap);
    const list = $('.fwc-palette__list', wrap);
    let items = [];
    let active = 0;
    let seq = 0;
    const close = () => { palOpen = false; wrap.remove(); escHandlers.pop(); };
    escHandlers.push(close);
    wrap.addEventListener('mousedown', (e) => { if (e.target === wrap) close(); });
    const actions = () => {
      const m = BOOT.config.modules;
      const out = [];
      if (BOOT.can_write) newItems().forEach(([v, ic, label]) => out.push({ group: 'Create', icon: ic, title: label, run: () => create(v) }));
      const nav = [['overview', 'ic-overview', 'Overview', true], ['customers', 'ic-customer', L('customers'), m.customers], ['suppliers', 'ic-supplier', L('suppliers'), m.suppliers],
        ['pipeline', 'i-kanban', L('pipeline'), m.pipeline], ['tasks', 'i-check-square', L('tasks'), m.tasks], ['contacts', 'i-users', L('contacts'), m.contacts],
        ['activity', 'ic-activity', 'Activity', m.activity], ['compliance', 'i-shield', 'Compliance', m.compliance && m.suppliers]];
      nav.filter((n) => n[3]).forEach(([r, ic, label]) => out.push({ group: 'Go to', icon: ic, title: label, run: () => go(r) }));
      out.push({ group: 'Go to', icon: 'ic-sliders', title: 'Make it yours (settings)', run: () => { location.href = '/crm/settings.php'; } });
      if (BOOT.is_admin) out.push({ group: 'Go to', icon: 'upload', title: 'Import & export', run: () => { location.href = '/crm/import.php'; } });
      return out;
    };
    const render = () => {
      let lastGroup = '';
      list.innerHTML = items.length ? items.map((it, i) => {
        const head = it.group !== lastGroup ? `<div class="fwc-palette__group">${esc(it.group)}</div>` : '';
        lastGroup = it.group;
        return `${head}<button class="fwc-palette__item${i === active ? ' is-active' : ''}" type="button" role="option" data-i="${i}">${it.html || icon(it.icon)}<span class="fwc-palette__text"><b>${esc(it.title)}</b>${it.sub ? `<small>${esc(it.sub)}</small>` : ''}</span>${it.side || ''}</button>`;
      }).join('') : `<div class="fwc-empty fwc-empty--sm"><p>Nothing matches “${esc(input.value)}”.</p></div>`;
      const a = $('.is-active', list);
      if (a) a.scrollIntoView({ block: 'nearest' });
    };
    const search = debounce(async () => {
      const q = input.value.trim();
      const my = ++seq;
      if (!q) { items = actions(); active = 0; render(); return; }
      const acts = actions().filter((a) => a.title.toLowerCase().includes(q.toLowerCase()));
      try {
        const d = await api.get('search.php', { q });
        if (my !== seq) return;
        items = [
          ...d.accounts.map((a) => ({ group: 'Accounts', html: avatar(a.name, 'fwc-ava--sm'), title: a.name, sub: [L(a.type), a.sub].filter(Boolean).join(' · '), side: statusPill(a.status), run: () => { location.href = accountUrl(a.id); } })),
          ...d.contacts.map((c) => ({ group: 'People', html: avatar(c.name, 'fwc-ava--sm fwc-ava--round'), title: c.name, sub: [c.role, c.account, c.email || c.phone].filter(Boolean).join(' · '), run: () => { location.href = accountUrl(c.account_id, 'contacts'); } })),
          ...d.deals.map((o) => ({ group: L('deals'), icon: 'i-kanban', title: o.title, sub: o.account + ' · ' + money0(o.amount), side: `<span class="fwc-pill" style="--c:${esc(o.color)}">${esc(o.stage)}</span>`, run: () => { location.href = dealUrl(o.id); } })),
          ...acts,
        ];
      } catch (e) { items = acts; }
      active = 0;
      render();
    }, 160);
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
  /** Make the children of `host` matching `sel` reorderable by their `.fwc-srow__grip` (or whole row). onEnd(orderedEls). */
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
      const after = (e.clientY - r.top) > r.height / 2;
      over.parentNode.insertBefore(dragging, after ? over.nextSibling : over);
    });
    host.addEventListener('dragend', () => {
      if (!dragging) return;
      dragging.classList.remove('is-dragging');
      dragging = null;
      onEnd($$(sel, host));
    });
    // keyboard: Alt+↑/↓ on a focused row
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

  window.CRM = {
    $, $$, esc, icon, csrf, debounce, narrow, api, ApiError, toast, toastError, popover, closePop,
    openModal, closeModal, tempModal, dialog, BOOT, L, lower, money, money0, compact, group, fmtDate, fmtTime, parseYmd, parseDt, ymd, today,
    daysFrom, relDay, relTime, ago, hue, initials, avatar, person, personAva, statusMeta, statusPill, stageMeta, stagePill, tone, pill, tagChip,
    actIcon, ACT_ICON, telHref, waHref, fmtPhone, accountUrl, dealUrl, sparkline, emptyState, errorBox, refreshBoot, savePrefs, setCount,
    initChrome, toggleDrawer, create, createMenu, palette, shortcuts, sortable, go, escHandlers, forms: null,
  };
})();
