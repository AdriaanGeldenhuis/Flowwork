/* =============================================================================
   Flowwork Projects — shared client core
   Helpers (API, toasts, popovers, dialogs), the Mail chrome (theme, menu,
   drawer), dates and money, people and status primitives, and the dialogs
   every Projects page uses (new / edit project, new board) via window.FWP.
   Pages put their data in <script id="prBoot" type="application/json">.
   ============================================================================= */
(function () {
  'use strict';

  // ===========================================================================
  // Core helpers (mirrors /calendar/js/calendar-core.js)
  // ===========================================================================
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const narrow = () => window.matchMedia('(max-width: 820px)').matches;
  const finePointer = () => window.matchMedia('(pointer: fine)').matches;
  const safeColor = (c, fallback = '#85766b') => (/^#[0-9a-f]{6}$/i.test(String(c || '')) ? c : fallback);

  class ApiError extends Error {
    constructor(message, status, data) { super(message); this.status = status; this.data = data; }
  }
  async function request(method, url, body, isForm) {
    const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };
    if (method !== 'GET') opts.headers['X-CSRF-TOKEN'] = csrf();
    if (body !== undefined) {
      if (isForm) { opts.body = body; } else { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    }
    let res;
    try { res = await fetch(url, opts); } catch (e) {
      throw new ApiError('You appear to be offline. Check your connection and try again.', 0);
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
  const api = {
    get: (path, params) => {
      const qs = new URLSearchParams(Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
      return request('GET', '/projects/api/' + path + (qs ? '?' + qs : ''));
    },
    post: (path, body) => request('POST', '/projects/api/' + path, body || {}),
    upload: (path, formData, onProgress) => {
      if (!onProgress) return request('POST', '/projects/api/' + path, formData, true);
      // XHR so the page can show upload progress.
      return new Promise((resolve, reject) => {
        const x = new XMLHttpRequest();
        x.open('POST', '/projects/api/' + path);
        x.setRequestHeader('X-CSRF-TOKEN', csrf());
        x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.setRequestHeader('Accept', 'application/json');
        x.upload.onprogress = (e) => { if (e.lengthComputable) onProgress(e.loaded / e.total); };
        x.onload = () => {
          let d = null;
          try { d = JSON.parse(x.responseText); } catch (e) { d = null; }
          if (x.status === 401) { location.href = '/login.php'; return reject(new ApiError('Your session has expired.', 401)); }
          if (x.status >= 400 || !d || d.ok === false) return reject(new ApiError((d && d.error) || `Upload failed (${x.status}).`, x.status, d));
          resolve(d);
        };
        x.onerror = () => reject(new ApiError('The upload failed. Check your connection.', 0));
        x.send(formData);
      });
    },
  };

  function toast(message, opts = {}) {
    const host = $('#toasts');
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

  function hueOf(s) { let h = 0; for (const ch of String(s || '?')) h = (h * 31 + ch.charCodeAt(0)) % 360; return h; }
  function initials(name, email) {
    const n = String(name || '').trim();
    if (n && !n.includes('@')) {
      const p = n.split(/\s+/).filter(Boolean);
      return ((p[0] || '')[0] + (p.length > 1 ? p[p.length - 1][0] : (p[0] || '')[1] || '')).toUpperCase();
    }
    return String(email || n || '?').slice(0, 2).toUpperCase();
  }
  const avatar = (name, email, cls = '', title = true) => `<span class="fwm-avatar ${cls}" style="--h:${hueOf(email || name)}" aria-hidden="true"${title ? ` title="${esc(name)}"` : ''}>${esc(initials(name, email))}</span>`;
  function fileKind(name, mime) {
    const ext = String(name || '').split('.').pop().toLowerCase();
    if (/^image\//.test(mime || '') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'heif', 'bmp'].includes(ext)) return ['img', ext.toUpperCase()];
    if (ext === 'pdf' || mime === 'application/pdf') return ['pdf', 'PDF'];
    if (['doc', 'docx', 'odt', 'rtf', 'txt'].includes(ext)) return ['doc', ext.toUpperCase()];
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return ['xls', ext.toUpperCase()];
    if (['zip', 'rar', '7z', 'gz'].includes(ext)) return ['zip', ext.toUpperCase()];
    return ['', (ext || 'FILE').slice(0, 4).toUpperCase()];
  }
  const isImage = (f) => /^image\/(png|jpe?g|gif|webp)$/.test(f.mime || '');
  const fmtSize = (n) => { n = Number(n) || 0; return n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; };

  // ---------------------------------------------------------------- popovers
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
      let left = opts.align === 'end' ? r.right - pw : (opts.align === 'side' ? r.right + 8 : (opts.align === 'center' ? r.left + r.width / 2 - pw / 2 : r.left));
      if (opts.align === 'side' && left + pw > window.innerWidth - 8) left = r.left - pw - 8;
      left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
      let top = opts.align === 'side' ? r.top : r.bottom + 6;
      if (top + ph > window.innerHeight - 8) top = Math.max(8, (opts.align === 'side' ? window.innerHeight - ph - 8 : r.top - ph - 6));
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
      if (!keep && activePop === pop) closePop();
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

  // ---------------------------------------------------------------- dialogs
  let lastFocus = null;
  function openModal(m) {
    if (typeof m === 'string') m = document.getElementById(m);
    if (!m) return null;
    closePop();
    lastFocus = document.activeElement;
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
    if (lastFocus && lastFocus.focus && lastFocus.isConnected) lastFocus.focus();
  }
  document.addEventListener('click', (e) => {
    const closer = e.target.closest('[data-close]');
    if (closer) { closeModal(closer.closest('.fwm-modal')); return; }
    if (e.target.classList && e.target.classList.contains('fwm-modal')) closeModal(e.target);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (activePop) return;
    const open = $$('.fwm-modal:not([hidden])').pop();
    if (open) { e.preventDefault(); e.stopPropagation(); closeModal(open); }
  }, true);

  function tempModal(html, cls = '', style = '') {
    const m = document.createElement('div');
    m.className = 'fwm-modal';
    m.dataset.temp = '1';
    m.hidden = true;
    m.innerHTML = `<div class="fwm-dialog ${cls}" role="dialog" aria-modal="true" style="${style}">${html}</div>`;
    document.body.appendChild(m);
    return m;
  }
  /** Promise-based dialog: confirm, a text input ({input:{value, placeholder, label}}) or choices ([{v, label, hint}]). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, choices = null, value = null, input = null }) {
    return new Promise((resolve) => {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body">${body ? `<p class="fwm-dialog__lead" style="margin-bottom:${choices || input ? 14 : 0}px">${body}</p>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}<input class="input" id="dlgInput" maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autofocus></div>` : ''}
          ${choices ? `<div class="fwm-results">${choices.map((c, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="dlgChoice" value="${esc(c.v)}" ${(value ? c.v === value : i === 0) ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}</div>` : ''}</form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn btn--primary" type="button" data-act="yes" ${danger ? 'style="background:linear-gradient(140deg,#c0392b,#a8281e 50%,#7d1a12)"' : ''}>${esc(confirm)}</button></div>`, '', 'width:min(460px,100%)');
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

  // ===========================================================================
  // Boot data, people, dates, money
  // ===========================================================================
  const BOOT = JSON.parse(($('#prBoot') || {}).textContent || '{}');
  const P = {
    me: BOOT.me || { id: 0, name: '' },
    role: BOOT.role || 'viewer',
    admin: !!BOOT.admin,
    people: BOOT.people || [],
    today: BOOT.today || null,
    templates: BOOT.templates || { boards: [], projects: [] },
    canCreate: BOOT.can_create !== false,
  };
  const personById = (id) => P.people.find((p) => p.id === Number(id)) || null;
  const personName = (id) => { const p = personById(id); return p ? p.name : 'Former member'; };
  const personAvatar = (id, cls = '') => { const p = personById(id) || { name: '?', email: '' }; return avatar(p.name, p.email, cls); };
  function faces(ids, max = 3, cls = '') {
    ids = (ids || []).filter(Boolean);
    if (!ids.length) return '';
    const shown = ids.slice(0, max).map((id) => personAvatar(id)).join('');
    const more = ids.length > max ? `<span class="fwp-faces__more" title="${esc(ids.slice(max).map(personName).join(', '))}">+${ids.length - max}</span>` : '';
    return `<span class="fwp-faces ${cls}">${shown}${more}</span>`;
  }

  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  function parseYmd(s) {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }
  function parseDT(s) {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)) : null;
  }
  const today = () => parseYmd(P.today) && ymd(new Date()) !== P.today && Math.abs(new Date() - parseYmd(P.today)) < 2 * 86400000 ? ymd(new Date()) : (P.today || ymd(new Date()));
  const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n);
  const daysBetween = (a, b) => Math.round((new Date(b.getFullYear(), b.getMonth(), b.getDate()) - new Date(a.getFullYear(), a.getMonth(), a.getDate())) / 86400000);
  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const DOW = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const mon3 = (d) => MONTHS[d.getMonth()].slice(0, 3);
  /** "21 Oct", with the year when it is not this year. */
  function fmtDate(s, opts = {}) {
    const d = typeof s === 'string' ? parseYmd(s) : s;
    if (!d) return '';
    const yr = d.getFullYear() !== new Date().getFullYear() || opts.year;
    return `${opts.dow ? DOW[d.getDay()].slice(0, 3) + ' ' : ''}${d.getDate()} ${mon3(d)}${yr ? ' ' + d.getFullYear() : ''}`;
  }
  function fmtRange(a, b) {
    const s = parseYmd(a), e = parseYmd(b);
    if (!s) return '';
    if (!e || a === b) return fmtDate(s);
    if (s.getFullYear() === e.getFullYear() && s.getMonth() === e.getMonth()) return `${s.getDate()}–${e.getDate()} ${mon3(e)}${e.getFullYear() !== new Date().getFullYear() ? ' ' + e.getFullYear() : ''}`;
    return `${fmtDate(s)} – ${fmtDate(e)}`;
  }
  /** "in 3 days", "2 days ago", "today", "tomorrow". */
  function relDay(s) {
    const d = parseYmd(s);
    if (!d) return '';
    const n = daysBetween(parseYmd(today()), d);
    if (n === 0) return 'today';
    if (n === 1) return 'tomorrow';
    if (n === -1) return 'yesterday';
    if (n > 1 && n < 14) return `in ${n} days`;
    if (n < -1 && n > -14) return `${-n} days ago`;
    if (n >= 14 && n < 70) return `in ${Math.round(n / 7)} weeks`;
    if (n <= -14 && n > -70) return `${Math.round(-n / 7)} weeks ago`;
    return fmtDate(d);
  }
  /** "just now", "5 min ago", "14:20", "Mon 14:20", "21 Oct". */
  function relTime(s) {
    const d = parseDT(s);
    if (!d) return '';
    const sec = (Date.now() - d.getTime()) / 1000;
    if (sec < 50) return 'just now';
    if (sec < 3600) return Math.max(1, Math.round(sec / 60)) + ' min ago';
    const hm = pad(d.getHours()) + ':' + pad(d.getMinutes());
    if (ymd(d) === ymd(new Date())) return hm;
    if (sec < 6 * 86400) return DOW[d.getDay()].slice(0, 3) + ' ' + hm;
    return fmtDate(d);
  }
  function fmtMoney(n, opts = {}) {
    const v = Number(n) || 0;
    const abs = Math.abs(v);
    let s;
    if (opts.short && abs >= 1e6) s = (abs / 1e6).toFixed(abs >= 1e7 ? 1 : 2).replace(/\.0+$/, '') + 'm';
    else if (opts.short && abs >= 1e4) s = (abs / 1e3).toFixed(abs >= 1e5 ? 0 : 1).replace(/\.0$/, '') + 'k';
    else s = abs.toLocaleString('en-ZA', { minimumFractionDigits: opts.cents === false ? 0 : 2, maximumFractionDigits: opts.cents === false ? 0 : 2 }).replace(/,/g, ' ');
    return (v < 0 ? '−' : '') + 'R ' + s;
  }
  const fmtNum = (v, p = 2) => (Number(v) || 0).toLocaleString('en-ZA', { minimumFractionDigits: p, maximumFractionDigits: p }).replace(/,/g, ' ');
  const STATUS_NAMES = { draft: 'Planning', active: 'Active', on_hold: 'On hold', completed: 'Completed', cancelled: 'Cancelled', archived: 'Archived' };
  const STATUS_COLORS = { draft: '#7a5ab8', active: '#2f8a5f', on_hold: '#d39a32', completed: '#2f5c84', cancelled: '#85766b', archived: '#5b6670' };
  const projectStatusPill = (s, soft = true) => `<span class="fwp-pill ${soft ? 'fwp-pill--soft' : ''}" style="--c:${STATUS_COLORS[s] || '#85766b'}">${esc(STATUS_NAMES[s] || s)}</span>`;
  const monogram = (name, color, cls = '') => {
    const w = String(name || '?').replace(/[^\p{L}\p{N}\s]/gu, '').trim().split(/\s+/);
    const m = ((w[0] || '?')[0] + (w.length > 1 ? w[1][0] : (w[0] || '')[1] || '')).toUpperCase();
    return `<span class="fwp-mono ${cls}" style="--c:${safeColor(color, '#7a1f30')}" aria-hidden="true">${esc(m)}</span>`;
  };
  /** Progress ring (SVG) — percentage 0..100. */
  function ring(pct, size = 58) {
    const p = Math.max(0, Math.min(100, Math.round(pct || 0)));
    const r = 26, c = 2 * Math.PI * r;
    return `<span class="fwp-ring" style="--size:${size}px"><svg viewBox="0 0 60 60" aria-hidden="true"><circle class="r-bg" cx="30" cy="30" r="${r}"/><circle class="r-fg" cx="30" cy="30" r="${r}" stroke-dasharray="${c.toFixed(1)}" stroke-dashoffset="${(c * (1 - p / 100)).toFixed(1)}"/></svg><b>${p}<small>%</small></b></span>`;
  }
  // Gradient used by rings, defined once.
  document.body.insertAdjacentHTML('afterbegin', '<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="fwpRingGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#d8bd8a"/><stop offset="1" stop-color="#7a1f30"/></linearGradient></defs></svg>');

  /** Text with links made clickable and @[Name](id) mentions shown as chips — always escaped. */
  function richText(s) {
    const parts = String(s || '').split(/(@\[[^\]]{1,120}\]\(\d{1,10}\)|https?:\/\/[^\s<]+)/g);
    return parts.map((part) => {
      const m = part.match(/^@\[([^\]]+)\]\((\d+)\)$/);
      if (m) return `<span class="fwp-mention ${Number(m[2]) === P.me.id ? 'is-me' : ''}">@${esc(m[1])}</span>`;
      if (/^https?:\/\//.test(part)) {
        const url = part.replace(/[).,;:!?]+$/, '');
        const tail = part.slice(url.length);
        return `<a href="${esc(url)}" target="_blank" rel="noopener noreferrer">${esc(url)}</a>${esc(tail)}`;
      }
      return esc(part);
    }).join('');
  }

  // ===========================================================================
  // People picker (popover with search, single or multi)
  // ===========================================================================
  /** pickPeople(anchor, selectedIds, {multi, title, onChange(ids), people}) */
  function pickPeople(anchor, selected, opts = {}) {
    let sel = new Set((selected || []).map(Number));
    const list = opts.people || P.people;
    const render = (q = '') => {
      const ql = q.trim().toLowerCase();
      const rows = list.filter((p) => !ql || p.name.toLowerCase().includes(ql) || String(p.email || '').toLowerCase().includes(ql)).slice(0, 80);
      return rows.map((p) => `<button class="fwm-pop__item ${sel.has(p.id) ? 'is-on' : ''}" data-v="${p.id}" type="button">${avatar(p.name, p.email, '', false)}<span style="min-width:0;overflow:hidden;text-overflow:ellipsis">${esc(p.name)}</span></button>`).join('') || '<div class="fwm-nav__empty">No one matches.</div>';
    };
    const pop = popover(anchor, `<div class="fwp-pick">${opts.title ? `<div class="fwm-pop__title">${esc(opts.title)}</div>` : ''}<input class="fwm-pop__input" placeholder="Search people" aria-label="Search people"><div class="fwp-plist">${render()}</div>
      ${sel.size && opts.multi !== false ? `<div class="fwp-pick__foot"><button class="btn btn--sm btn--ghost" data-v="__clear" type="button">Clear</button></div>` : ''}</div>`, {
      cls: 'fwp-pop-people',
      onClick: (it) => {
        if (it.dataset.v === '__clear') { sel = new Set(); opts.onChange && opts.onChange([]); return true; }
        const id = Number(it.dataset.v);
        if (opts.multi === false) { opts.onChange && opts.onChange([id]); return true; }
        if (sel.has(id)) sel.delete(id); else sel.add(id);
        it.classList.toggle('is-on', sel.has(id));
        opts.onChange && opts.onChange(Array.from(sel), true);
        return false;
      },
      onClose: () => opts.onClose && opts.onClose(Array.from(sel)),
    });
    const input = $('.fwm-pop__input', pop.el);
    input.addEventListener('input', () => { $('.fwp-plist', pop.el).innerHTML = render(input.value); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); const f = $('.fwp-plist [data-v]', pop.el); if (f) f.click(); }
    });
    return pop;
  }

  /** Pick a date: quick choices and a calendar input. onPick('YYYY-MM-DD' | null). */
  function pickDate(anchor, current, onPick, opts = {}) {
    const t = parseYmd(today());
    const nextMon = addDays(t, ((8 - t.getDay()) % 7) || 7);
    const quick = [['Today', t], ['Tomorrow', addDays(t, 1)], ['Next week', nextMon], ['In 2 weeks', addDays(t, 14)]];
    const pop = popover(anchor, `<div class="fwp-pick" style="width:270px">${opts.title ? `<div class="fwm-pop__title">${esc(opts.title)}</div>` : ''}
        <div class="fwp-quickdates">${quick.map(([l, d]) => `<button type="button" data-v="${ymd(d)}">${l}</button>`).join('')}</div>
        <input class="input" type="date" value="${esc(current || '')}" aria-label="Date">
        <div class="fwp-pick__foot"><button class="btn btn--sm btn--ghost" type="button" data-v="__clear" ${current ? '' : 'disabled'}>Clear</button><button class="btn btn--sm btn--primary" type="button" data-v="__ok">Set date</button></div></div>`, {
      onClick: (it) => {
        const v = it.dataset.v;
        if (v === '__clear') onPick(null);
        else if (v === '__ok') { const val = $('input[type=date]', pop.el).value; if (val) onPick(val); else return false; }
        else onPick(v);
        return true;
      },
    });
    const inp = $('input[type=date]', pop.el);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && inp.value) { e.preventDefault(); closePop(); onPick(inp.value); } });
    return pop;
  }

  // ===========================================================================
  // Chrome (same as Mail and Calendar)
  // ===========================================================================
  function initChrome() {
    const body = document.body;
    const themeBtn = $('#themeToggle');
    const applyTheme = (t) => {
      body.dataset.theme = t;
      const meta = $('meta[name="theme-color"]');
      if (meta) meta.content = t === 'dark' ? '#0d0a09' : '#f2ece3';
      if (themeBtn) { themeBtn.innerHTML = icon(t === 'dark' ? 'sun' : 'moon'); themeBtn.setAttribute('data-tip', t === 'dark' ? 'Light theme' : 'Dark theme'); }
    };
    applyTheme(body.dataset.theme === 'light' ? 'light' : 'dark');
    if (themeBtn) themeBtn.addEventListener('click', () => {
      const t = body.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(t);
      document.cookie = 'fw_theme=' + t + ';max-age=' + (365 * 86400) + ';path=/;SameSite=Lax';
    });
    const kebab = $('#kebabBtn');
    if (kebab) kebab.addEventListener('click', () => {
      const extra = (window.FWP && window.FWP.kebabExtra) ? window.FWP.kebabExtra() : '';
      popover(kebab, `${extra}${extra ? '<div class="fwm-pop__sep"></div>' : ''}
        <a class="fwm-pop__item" data-v="home" href="/projects/">${icon('grid')}All projects</a>
        <a class="fwm-pop__item" data-v="mywork" href="/projects/#mywork">${icon('check-square')}My work</a>
        <button class="fwm-pop__item" data-v="keys">${icon('keyboard')}Keyboard shortcuts <small>?</small></button>
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="cal" href="/calendar/">${icon('calendar')}Calendar</a>
        <a class="fwm-pop__item" data-v="mail" href="/mail/">${icon('mail')}Mail</a>
        <a class="fwm-pop__item" data-v="crm" href="/crm/">${icon('building')}CRM</a>
        <a class="fwm-pop__item" data-v="fwhome" href="/home.php">${icon('home')}Flowwork home</a>`, {
        align: 'end',
        onClick: (it) => {
          if (it.dataset.v === 'keys') openModal('shortcutsModal');
          if (window.FWP && window.FWP.onKebab) window.FWP.onKebab(it.dataset.v, it);
        },
      });
    });
    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
    // "/" focuses search; "?" shows shortcuts.
    document.addEventListener('keydown', (e) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;
      const t = e.target;
      if (t.closest && t.closest('input, textarea, select, [contenteditable="true"]')) return;
      if (e.key === '/' && $('#searchInput')) { e.preventDefault(); $('#searchInput').focus(); }
      if (e.key === '?' && $('#shortcutsModal')) { e.preventDefault(); openModal('shortcutsModal'); }
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

  // ===========================================================================
  // Shared dialogs: new / edit project, new board
  // ===========================================================================
  const PALETTE = ['#7a1f30', '#b08d57', '#2f7457', '#2f5c84', '#7a5ab8', '#b4577a', '#c08a2b', '#1f7a7a', '#a8281e', '#4b4fa8', '#6b7d2b', '#5b6670'];
  const BOARD_ICONS = { tasks: 'check-square', schedule: 'timeline', costing: 'wallet', procurement: 'truck', snags: 'target', safety: 'shield', blank: 'grid',
                        general: 'check-square', quality: 'target' };
  /** A board's icon: by its type, or for older untyped boards by its name. */
  function boardIcon(b) {
    if (b.type && b.type !== 'general' && BOARD_ICONS[b.type]) return BOARD_ICONS[b.type];
    const t = String(b.title || '').toLowerCase();
    if (/schedul|programme|program|timeline|plan/.test(t)) return 'timeline';
    if (/cost|budget|boq|wage|pay|invoice|quote/.test(t)) return 'wallet';
    if (/procure|order|purchas|deliver|supplier/.test(t)) return 'truck';
    if (/snag|quality|defect|inspect/.test(t)) return 'target';
    if (/safety|incident|h&s/.test(t)) return 'shield';
    return 'check-square';
  }

  /** Client picker: CRM customers by name (typeahead), or free text. */
  function clientField(m, current) {
    const box = $('.fwp-acsel', m);
    const input = $('input', box);
    let chosen = current && current.id ? { id: current.id, name: current.name } : null;
    let list = null;
    const close = () => { if (list) { list.remove(); list = null; } };
    const search = debounce(async () => {
      const q = input.value.trim();
      if (q.length < 2) { close(); return; }
      try {
        const d = await api.get('pickers.php', { type: 'clients', q });
        const rows = (d.clients || []).slice(0, 8);
        close();
        if (!rows.length) return;
        list = document.createElement('div');
        list.className = 'fwp-acsel__list';
        list.innerHTML = rows.map((a) => `<button class="fwm-pop__item" type="button" data-id="${Number(a.id)}">${icon('building')}<span>${esc(a.name)}</span></button>`).join('');
        box.appendChild(list);
        list.addEventListener('mousedown', (e) => {
          const b = e.target.closest('[data-id]');
          if (!b) return;
          e.preventDefault();
          chosen = { id: Number(b.dataset.id), name: b.textContent.trim() };
          input.value = chosen.name;
          close();
        });
      } catch (e) { close(); }
    }, 220);
    input.addEventListener('input', () => { chosen = null; search(); });
    input.addEventListener('blur', () => setTimeout(close, 150));
    return () => (chosen ? { client_id: chosen.id } : { client_id: 0, client: input.value.trim() });
  }

  /** New project (opts.project → edit). Resolves with the saved id or null. */
  function projectDialog(project, opts = {}) {
    const isNew = !project;
    const p = project || { name: '', color: PALETTE[Math.floor(Math.random() * PALETTE.length)], visibility: 'members', status: 'active', budget: 0 };
    let color = safeColor(p.color, PALETTE[0]);
    let template = 'construction_standard';
    const tpls = (P.templates.projects || []);
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${isNew ? 'New project' : 'Project details'}</h2><button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="prForm" autocomplete="off">
        <div class="field"><label class="label" for="prName">Name</label><input class="input" id="prName" maxlength="255" value="${esc(p.name)}" placeholder="e.g. Riverside townhouses — phase 2" autofocus></div>
        ${isNew && tpls.length ? `<div class="field"><span class="label">Start with</span><div class="fwp-tpls" id="prTpl">${tpls.map((t) => `<button type="button" class="fwp-tplcard ${t.key === template ? 'is-on' : ''}" data-t="${esc(t.key)}"><span>${icon(t.key === 'construction_standard' ? 'building' : t.key === 'service' ? 'wallet' : t.key === 'empty' ? 'grid' : 'check-square')}</span><b>${esc(t.label)}</b><small>${esc(t.hint)}</small></button>`).join('')}</div></div>` : ''}
        <div class="fwp-form-grid" style="margin-top:16px">
          <div class="field"><label class="label" for="prClient">Client</label><div class="fwp-acsel"><input class="input" id="prClient" maxlength="255" value="${esc(p.client || '')}" placeholder="Search the CRM or type a name"></div></div>
          <div class="field"><label class="label" for="prNumber">Project number</label><input class="input" id="prNumber" maxlength="50" value="${esc(p.number || '')}" placeholder="${isNew ? 'Automatic (PRJ-0001)' : ''}"></div>
          <div class="field"><label class="label" for="prStart">Start</label><input class="input" type="date" id="prStart" value="${esc(p.start || '')}"></div>
          <div class="field"><label class="label" for="prEnd">Due</label><input class="input" type="date" id="prEnd" value="${esc(p.end || '')}"></div>
          <div class="field"><label class="label" for="prManager">Project manager</label><select class="select" id="prManager"><option value="">—</option>${P.people.map((u) => `<option value="${u.id}" ${(p.manager_id || (isNew ? P.me.id : 0)) === u.id ? 'selected' : ''}>${esc(u.name)}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="prBudget">Budget (R, excl. VAT)</label><input class="input" id="prBudget" inputmode="decimal" value="${p.budget ? esc(p.budget) : ''}" placeholder="0.00"></div>
          <div class="field is-wide"><label class="label" for="prSite">Site address</label><input class="input" id="prSite" maxlength="1000" value="${esc(p.site || '')}" placeholder="Optional"></div>
          ${isNew ? '' : `<div class="field"><label class="label" for="prStatus">Status</label><select class="select" id="prStatus">${Object.entries(STATUS_NAMES).map(([k, v]) => `<option value="${k}" ${p.status === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div>`}
          <div class="field ${isNew ? 'is-wide' : ''}"><span class="label">Who can see it</span><div class="seg" id="prVis"><button type="button" data-vis="members" class="${p.visibility !== 'company' ? 'is-on' : ''}">Project members</button><button type="button" data-vis="company" class="${p.visibility === 'company' ? 'is-on' : ''}">Everyone in the company</button></div></div>
          <div class="field is-wide"><span class="label">Colour</span><div class="fwm-swatches" id="prColors"></div></div>
          <div class="field is-wide"><label class="label" for="prDesc">Description</label><textarea class="textarea" id="prDesc" rows="3" maxlength="5000" placeholder="Scope, notes, anything the team should know">${esc(p.description || '')}</textarea></div>
        </div>
      </form>
      <div class="fwm-dialog__foot"><span class="fwp-err" id="prErr"></span><button class="btn" data-close type="button">Cancel</button><button class="btn btn--primary" id="prSave" type="button">${isNew ? 'Create project' : 'Save'}</button></div>`, 'fwm-dialog--wide');
    const paint = () => { $('#prColors', m).innerHTML = PALETTE.map((c) => `<button type="button" class="fwm-swatch ${color === c ? 'is-on' : ''}" style="background:${c}" data-color="${c}" aria-label="Colour"></button>`).join(''); };
    paint();
    $('#prColors', m).addEventListener('click', (e) => { const b = e.target.closest('[data-color]'); if (b) { color = b.dataset.color; paint(); } });
    let vis = p.visibility === 'company' ? 'company' : 'members';
    $('#prVis', m).addEventListener('click', (e) => { const b = e.target.closest('[data-vis]'); if (!b) return; vis = b.dataset.vis; $$('#prVis button', m).forEach((x) => x.classList.toggle('is-on', x === b)); });
    const tplBox = $('#prTpl', m);
    if (tplBox) tplBox.addEventListener('click', (e) => { const b = e.target.closest('[data-t]'); if (!b) return; template = b.dataset.t; $$('[data-t]', tplBox).forEach((x) => x.classList.toggle('is-on', x === b)); });
    const client = clientField(m, p.client_id ? { id: p.client_id, name: p.client } : null);
    return new Promise((resolve) => {
      let done = false;
      m._onClose = () => { if (!done) resolve(null); };
      const go = async () => {
        const name = $('#prName', m).value.trim();
        if (!name) { $('#prName', m).focus(); $('#prErr', m).textContent = 'Give the project a name.'; return; }
        const body = Object.assign({
          action: isNew ? 'create' : 'update', id: project ? project.id : undefined, name, color, visibility: vis,
          project_number: $('#prNumber', m).value.trim(), start_date: $('#prStart', m).value, end_date: $('#prEnd', m).value,
          manager_id: Number($('#prManager', m).value) || 0, budget: $('#prBudget', m).value.trim(), site_address: $('#prSite', m).value.trim(),
          description: $('#prDesc', m).value,
        }, client());
        if (isNew) body.template = template;
        else body.status = $('#prStatus', m).value;
        if (opts.extra) Object.assign(body, opts.extra);
        const btn = $('#prSave', m);
        btn.classList.add('is-busy');
        try {
          const d = await api.post('projects.php', body);
          done = true;
          closeModal(m);
          resolve(isNew ? d.id : project.id);
          if (isNew) location.href = d.url;
          else toast('Project saved');
        } catch (x) { $('#prErr', m).textContent = x.message; } finally { btn.classList.remove('is-busy'); }
      };
      $('#prSave', m).addEventListener('click', go);
      $('#prForm', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
      openModal(m);
    });
  }

  /** New board on a project, from a blueprint. */
  function boardDialog(projectId) {
    let tpl = 'tasks';
    const boards = (P.templates.boards || []);
    const m = tempModal(`<div class="fwm-dialog__head"><h2>New board</h2><button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="bdForm" autocomplete="off">
        <div class="field"><label class="label" for="bdTitle">Board name</label><input class="input" id="bdTitle" maxlength="255" placeholder="e.g. Site tasks" autofocus></div>
        <div class="field"><span class="label">Blueprint</span><div class="fwp-tpls" id="bdTpl">${boards.map((b) => `<button type="button" class="fwp-tplcard ${b.key === tpl ? 'is-on' : ''}" data-t="${esc(b.key)}"><span>${icon(BOARD_ICONS[b.key] || b.icon || 'grid')}</span><b>${esc(b.title)}</b><small>${esc(b.description)}</small><i>${esc(b.columns.join(' · '))}</i></button>`).join('')}</div></div>
      </form>
      <div class="fwm-dialog__foot"><span class="fwp-err" id="bdErr"></span><button class="btn" data-close type="button">Cancel</button><button class="btn btn--primary" id="bdSave" type="button">Create board</button></div>`, 'fwm-dialog--wide');
    $('#bdTpl', m).addEventListener('click', (e) => {
      const b = e.target.closest('[data-t]');
      if (!b) return;
      tpl = b.dataset.t;
      $$('#bdTpl [data-t]', m).forEach((x) => x.classList.toggle('is-on', x === b));
      const t = $('#bdTitle', m);
      if (!t.value.trim() || t.dataset.auto) { t.value = tpl === 'blank' ? '' : (boards.find((x) => x.key === tpl) || {}).title || ''; t.dataset.auto = '1'; }
    });
    $('#bdTitle', m).addEventListener('input', (e) => { delete e.target.dataset.auto; });
    const go = async () => {
      const btn = $('#bdSave', m);
      btn.classList.add('is-busy');
      try {
        const d = await api.post('boards.php', { action: 'create', project_id: projectId, template: tpl, title: $('#bdTitle', m).value.trim() });
        closeModal(m);
        location.href = d.url;
      } catch (x) { $('#bdErr', m).textContent = x.message; } finally { btn.classList.remove('is-busy'); }
    };
    $('#bdSave', m).addEventListener('click', go);
    $('#bdForm', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
    openModal(m);
  }

  /** Empty-state illustration: a stack of boards sealed with wax. */
  const emptyArt = () => `<svg class="fwp-art" viewBox="0 0 190 132" aria-hidden="true">
      <ellipse class="a-shadow" cx="95" cy="122" rx="62" ry="6"/>
      <g class="a-float">
        <rect class="a-card-2" x="40" y="16" width="112" height="78" rx="10" transform="rotate(-6 96 55)"/>
        <rect class="a-card" x="36" y="22" width="118" height="86" rx="11"/>
        <rect class="a-row" x="50" y="38" width="44" height="5" rx="2.5"/><rect class="a-chip-1" x="104" y="35" width="38" height="11" rx="4"/>
        <rect class="a-row" x="50" y="56" width="36" height="5" rx="2.5"/><rect class="a-chip-2" x="104" y="53" width="38" height="11" rx="4"/>
        <rect class="a-row" x="50" y="74" width="48" height="5" rx="2.5"/><rect class="a-chip-3" x="104" y="71" width="38" height="11" rx="4"/>
        <circle class="a-seal" cx="150" cy="100" r="15"/><circle class="a-seal-ring" cx="150" cy="100" r="11"/>
        <path d="M144 100h12M144 96h8M144 104h10" stroke="#ead2a2" stroke-width="1.6" stroke-linecap="round"/>
      </g>
      <path class="a-spark" d="M24 30l2 5 5 2-5 2-2 5-2-5-5-2 5-2z"/><path class="a-spark" d="M170 20l1.5 3.5 3.5 1.5-3.5 1.5-1.5 3.5-1.5-3.5-3.5-1.5 3.5-1.5z"/>
    </svg>`;
  function emptyState(title, text, actions = '', opts = {}) {
    return `<div class="fwm-empty fwp-empty">${opts.art === false ? '' : `<div class="fwm-empty__art fwm-empty__art--letter">${emptyArt()}</div>`}
      ${opts.eyebrow ? `<div class="fwm-empty__eyebrow">${esc(opts.eyebrow)}</div>` : ''}<h3>${esc(title)}</h3><p>${text}</p>${actions}</div>`;
  }

  // ===========================================================================
  // Project sidebar (project page and board page)
  // ===========================================================================
  const PROJECT_TABS = [['overview', 'grid', 'Overview'], ['schedule', 'calendar', 'Schedule'], ['money', 'coins', 'Money'], ['files', 'clip', 'Files'],
                        ['team', 'team', 'Team'], ['activity', 'history', 'Activity'], ['settings', 'settings', 'Settings']];
  /**
   * renderProjectSide({project, boards, active: 'tab:overview' | 'board:12', onTab(tab), boardsChanged(boards)})
   * Tabs link to the project page; on the project page onTab switches in place.
   */
  function renderProjectSide(o) {
    const side = $('#side');
    if (!side) return;
    const p = o.project;
    const manage = (p.level || 0) >= 3;
    const tabs = PROJECT_TABS.filter(([k]) => k !== 'settings' || manage || (p.level || 0) >= 1);
    const url = (tab) => `/projects/view.php?project_id=${p.id}${tab === 'overview' ? '' : '&tab=' + tab}`;
    const boards = (o.boards || []).filter((b) => !b.archived);
    side.innerHTML = `
      ${manage ? `<button class="fwm-compose-btn" id="sideNewBoard" type="button">${icon('plus')}<span>New board</span></button>` : ''}
      <div class="fwm-side__scroll scroll">
        <a class="fwm-page__back" href="/projects/" style="margin-bottom:6px">${icon('arrow-left')}All projects</a>
        <a class="fwp-sideproj" href="${url('overview')}">${monogram(p.name, p.color)}<span class="fwp-sideproj__text"><small>${esc(p.number || STATUS_NAMES[p.status] || '')}</small><b>${esc(p.name)}</b></span></a>
        <nav class="fwm-nav" aria-label="Project">${tabs.map(([k, ic, l]) => `<a class="fwm-nav__item ${o.active === 'tab:' + k ? 'is-active' : ''}" href="${url(k)}" data-tab="${k}">${icon(ic)}<span class="fwm-nav__label">${l}</span></a>`).join('')}</nav>
        <div class="fwm-nav__head"><h3>Boards</h3>${manage ? `<button class="icon-btn icon-btn--sm" id="sideAddBoard" type="button" aria-label="New board" data-tip="New board">${icon('plus', 'i--sm')}</button>` : ''}</div>
        <nav class="fwm-nav fwp-nav-board" id="sideBoards" aria-label="Boards">${boards.map((b) => `<a class="fwm-nav__item ${o.active === 'board:' + b.id ? 'is-active' : ''}" href="${esc(b.url)}" data-bid="${b.id}" ${manage ? 'draggable="true"' : ''}>${icon(boardIcon(b))}<span class="fwm-nav__label">${esc(b.title)}</span>${b.starred ? icon('star', 'i--xs') : ''}<span class="fwp-board-pct">${b.items ? Math.round(b.done * 100 / b.items) + '%' : ''}</span></a>`).join('') || '<div class="fwm-nav__empty">No boards yet.</div>'}</nav>
      </div>
      <div class="fwm-side__foot"><div class="fwp-side__links"><a href="/calendar/?view=month">${icon('calendar')}Calendar</a><a href="/projects/#mywork">${icon('check-square')}My work</a></div></div>`;
    side.querySelectorAll('[data-tab]').forEach((a) => a.addEventListener('click', (e) => {
      if (!o.onTab || e.ctrlKey || e.metaKey) return;
      e.preventDefault();
      o.onTab(a.dataset.tab);
      if (narrow()) toggleDrawer(false);
    }));
    const nb = () => boardDialog(p.id);
    ['#sideNewBoard', '#sideAddBoard'].forEach((s) => { const b = $(s, side); if (b) b.addEventListener('click', nb); });
    if (manage) {
      const nav = $('#sideBoards', side);
      let drag = null;
      nav.addEventListener('dragstart', (e) => { drag = e.target.closest('[data-bid]'); if (drag) { drag.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; } });
      nav.addEventListener('dragover', (e) => {
        if (!drag) return;
        e.preventDefault();
        const over = e.target.closest('[data-bid]');
        $$('.is-dropline', nav).forEach((x) => x.classList.remove('is-dropline'));
        if (over && over !== drag) over.classList.add('is-dropline');
      });
      nav.addEventListener('drop', async (e) => {
        e.preventDefault();
        const over = e.target.closest('[data-bid]');
        if (drag && over && over !== drag) {
          nav.insertBefore(drag, over);
          const ids = $$('[data-bid]', nav).map((x) => Number(x.dataset.bid));
          try { await api.post('projects.php', { action: 'boards_reorder', id: p.id, ids }); if (o.boardsChanged) o.boardsChanged(ids); } catch (x) { toastError(x); }
        }
      });
      nav.addEventListener('dragend', () => { if (drag) drag.classList.remove('is-dragging'); drag = null; $$('.is-dropline', nav).forEach((x) => x.classList.remove('is-dropline')); });
    }
  }

  // ---------------------------------------------------------------- activity feed
  /** One activity entry as a sentence. ctx.item: the feed is about that item ("this item"). */
  function actText(a, ctx = {}) {
    const d = a.details || {};
    const who = `<b>${esc(a.user)}</b>`;
    const item = ctx.item && a.item_id === ctx.item ? 'this item' : a.item ? (a.board_id && a.item_id ? `<a href="/projects/board.php?board_id=${a.board_id}&item=${a.item_id}">${esc(a.item)}</a>` : `<q>${esc(a.item)}</q>`) : '';
    const board = a.board ? `<a href="/projects/board.php?board_id=${a.board_id}">${esc(a.board)}</a>` : (d.title ? `<q>${esc(d.title)}</q>` : 'a board');
    switch (a.action) {
      case 'project_created': return `${who} created the project`;
      case 'project_updated': return `${who} updated ${d.status ? 'the status to <b>' + esc(STATUS_NAMES[d.status] || d.status) + '</b>' : 'the project details'}`;
      case 'member_added': return `${who} added <b>${esc(personName(d.user_id))}</b> as ${esc(d.role)}`;
      case 'member_role': return `${who} made <b>${esc(personName(d.user_id))}</b> ${esc(d.role)}`;
      case 'member_removed': return `${who} removed <b>${esc(personName(d.user_id))}</b>`;
      case 'board_created': return `${who} created ${board}`;
      case 'board_renamed': return `${who} renamed <q>${esc(d.from)}</q> to ${board}`;
      case 'board_archived': return `${who} archived ${board}`;
      case 'board_restored': return `${who} restored ${board}`;
      case 'board_deleted': return `${who} deleted <q>${esc(d.title)}</q>`;
      case 'item_created': return `${who} added ${item} to ${board}`;
      case 'item_renamed': return `${who} renamed <q>${esc(d.from)}</q> to ${item}`;
      case 'value_changed': return `${who} set <b>${esc(d.column)}</b> on ${item} to ${d.to ? `<q>${esc(d.to)}</q>` : '<i>empty</i>'}${d.automation ? ' <small class="fwp-muted">(automation)</small>' : ''}`;
      case 'item_moved': return `${who} moved ${item} to <b>${esc(d.to)}</b>`;
      case 'item_archived': return `${who} archived ${item || `<q>${esc(d.title)}</q>`}`;
      case 'item_restored': return `${who} restored ${item}`;
      case 'item_deleted': return `${who} deleted <q>${esc(d.title)}</q>`;
      case 'update_added': return `${who} ${d.reply ? 'replied' : 'posted an update'} on ${item}: <q>${esc(d.excerpt)}</q>`;
      case 'file_added': return `${who} added <b>${esc(d.name)}</b> to ${item}`;
      case 'file_removed': return `${who} removed <b>${esc(d.name)}</b> from ${item}`;
      case 'subitem_added': return `${who} added subitem <q>${esc(d.title)}</q> to ${item}`;
      case 'subitem_done': return `${who} ${d.done ? 'completed' : 'reopened'} <q>${esc(d.title)}</q> on ${item}`;
      case 'group_created': return `${who} added group <b>${esc(d.name)}</b> to ${board}`;
      case 'column_created': return `${who} added column <b>${esc(d.name)}</b> to ${board}`;
      case 'cost_saved': return `${who} recorded a cost: <q>${esc(d.description)}</q> (${esc(fmtMoney(d.amount))})`;
      case 'email_linked': return `${who} linked an email to ${item}`;
      case 'guest_invited': return `${who} invited <b>${esc(d.email)}</b> to ${board}`;
      case 'automation_saved': return `${who} saved automation <q>${esc(d.name)}</q> on ${board}`;
      case 'board_exported': return `${who} exported ${board}`;
      case 'board_imported': return `${who} imported ${d.items} items into ${board}`;
      case 'board_updated': return `${who} updated the settings of ${board}`;
      case 'board_moved': return `${who} moved ${board} to another project`;
      case 'column_renamed': return `${who} renamed column <q>${esc(d.from)}</q> to <b>${esc(d.to)}</b>`;
      case 'column_updated': return `${who} changed the settings of column <b>${esc(d.name)}</b>`;
      case 'column_deleted': return `${who} deleted column <b>${esc(d.name)}</b>`;
      case 'group_renamed': return `${who} renamed group <q>${esc(d.from)}</q> to <b>${esc(d.to)}</b>`;
      case 'groups_reordered': return `${who} reordered the groups on ${board}`;
      case 'guest_revoked': return `${who} removed guest <b>${esc(d.email)}</b> from ${board}`;
      case 'item_described': return `${who} updated the description of ${item}`;
      case 'item_moved_board': return d.arrived ? `${who} moved ${item} here from another board` : `${who} moved ${item} to <b>${esc(d.to_board || 'another board')}</b>`;
      case 'subitem_deleted': return `${who} deleted subitem <q>${esc(d.title)}</q> from ${item}`;
      case 'update_deleted': return `${who} deleted an update on ${item}`;
      case 'dependency_added': return `${who} set ${item} to wait on <q>${esc(d.waiting_on)}</q>`;
      case 'dependency_removed': return `${who} removed a dependency from ${item}`;
      case 'project_deleted': return `${who} deleted the project`;
      // Entries written by the boards before this version
      case 'group_added': return `${who} added group <b>${esc(d.name || '')}</b> to ${board}`;
      case 'group_deleted': return `${who} deleted a group on ${board}`;
      case 'column_added': return `${who} added column <b>${esc(d.name || '')}</b> to ${board}`;
      case 'status_changed': return `${who} changed the status of ${item}${d.new_status ? ` to <q>${esc(d.new_status)}</q>` : ''}`;
      case 'item_updated': return `${who} updated ${d.column_name ? `<b>${esc(d.column_name)}</b> on ` : ''}${item}`;
      case 'comment_added': return `${who} posted an update on ${item}`;
      case 'bulk_update': return `${who} changed ${d.count || 'several'} items on ${board}`;
      case 'created item': return `${who} added ${item} to ${board}`;
      case 'created column': return `${who} added a column to ${board}`;
      default: return `${who} ${esc(String(a.action).replace(/_/g, ' '))}${item ? ' · ' + item : ''}`;
    }
  }
  function activityHTML(list, days = true, ctx = {}) {
    let lastDay = '';
    return list.map((a) => {
      const day = String(a.at).slice(0, 10);
      const hdr = days && day !== lastDay ? `<div class="fwp-act-day">${esc(day === today() ? 'Today' : fmtDate(day, { dow: true }))}</div>` : '';
      lastDay = day;
      return `${hdr}<div class="fwp-act">${personAvatar(a.user_id)}<div class="fwp-act__text">${actText(a, ctx)}<span class="fwp-act__when">${esc(relTime(a.at))}</span></div></div>`;
    }).join('');
  }

  window.FWP = {
    renderProjectSide, PROJECT_TABS,
    $, $$, esc, icon, debounce, narrow, finePointer, safeColor, api, ApiError, toast, toastError, avatar, initials, hueOf, fileKind, isImage, fmtSize,
    popover, closePop, openModal, closeModal, tempModal, dialog, toggleDrawer,
    get activePop() { return activePop; },
    P, BOOT, personById, personName, personAvatar, faces,
    pad, ymd, parseYmd, parseDT, today, addDays, daysBetween, MONTHS, DOW, mon3, fmtDate, fmtRange, relDay, relTime, fmtMoney, fmtNum,
    STATUS_NAMES, STATUS_COLORS, projectStatusPill, monogram, ring, richText,
    pickPeople, pickDate, PALETTE, BOARD_ICONS, boardIcon, projectDialog, boardDialog, emptyState, emptyArt, actText, activityHTML,
    kebabExtra: null, onKebab: null,
  };
  initChrome();
})();
