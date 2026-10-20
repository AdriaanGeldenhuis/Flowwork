// /receipts/js/receipts.js — shared helpers for every Receipts page.
// window.FWR: $, $$, esc, icon, api (JSON over /receipts/api with the CSRF
// header), toast, popover, modal / dialog, money and dates, initials / hue,
// busy, the theme switch, the menu (switch company, sign out), the
// narrow-screen drawer, the "Find a receipt" box, the settings form engine
// and the uploader: drop slips anywhere, paste a screenshot, snap a photo —
// big photos are made smaller on the device first, then each slip is sent
// in and read, with progress in the tray at the bottom right.
(function () {
  'use strict';

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#${/^(ic|iq|ih|if|ia|ip|ir|i)-/.test(name) ? name : 'i-' + name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const BOOT = JSON.parse(($('#rcBoot') || {}).textContent || '{}');
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
    get: (path, params) => { const q = qs(params); return request('GET', '/receipts/api/' + path + (q ? '?' + q : '')); },
    post: (path, body) => request('POST', '/receipts/api/' + path, body || {}),
    /** multipart upload with progress (XHR: fetch has no upload progress) */
    upload(path, form, onProgress) {
      return new Promise((resolve, reject) => {
        const x = new XMLHttpRequest();
        x.open('POST', '/receipts/api/' + path);
        x.setRequestHeader('X-CSRF-Token', csrf());
        x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        x.setRequestHeader('Accept', 'application/json');
        x.upload.onprogress = (e) => { if (e.lengthComputable && onProgress) onProgress(e.loaded / e.total); };
        x.onerror = () => reject(new ApiError('Could not reach Flowwork. Check your connection and try again.', 0));
        x.onload = () => {
          let data = null;
          try { data = JSON.parse(x.responseText); } catch (e) { data = null; }
          if (x.status === 413 && !data) data = { ok: false, error: 'That file is larger than the server accepts.' };
          try { resolve(handle(x.status, data)); } catch (e) { reject(e); }
        };
        x.send(form);
      });
    },
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
    if (h) { h.id = h.id || 'fwrDlg' + (++dlgSeq); $('[role=dialog]', m).setAttribute('aria-labelledby', h.id); }
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
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab' || !openModals.length) return;
    const m = openModals[openModals.length - 1];
    const f = $$('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', m).filter((x) => x.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  /** Promise dialog: a confirmation, a text input ({input}), or a choice ({choices: [{value, label, hint}]}). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, input = null, choices = null, note = null }) {
    return new Promise((resolve) => {
      const m = modal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <form class="fwm-dialog__body" novalidate>${body ? `<p class="fwm-dialog__lead">${body}</p>` : ''}
          ${choices ? `<div class="fwr-choices" role="radiogroup">${choices.map((c, i) => `<label class="fwr-choice"><input type="radio" name="dlgChoice" value="${esc(c.value)}"${i === 0 ? ' checked' : ''}><span><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}</div>` : ''}
          ${input ? `<div class="field">${input.label ? `<label class="label" for="dlgInput">${esc(input.label)}</label>` : ''}${input.multiline ? `<textarea class="textarea" id="dlgInput" rows="3" maxlength="${input.max || 500}" placeholder="${esc(input.placeholder || '')}" autofocus>${esc(input.value || '')}</textarea>` : `<input class="input" id="dlgInput" type="${esc(input.type || 'text')}" maxlength="${input.max || 255}" value="${esc(input.value || '')}" placeholder="${esc(input.placeholder || '')}" autocomplete="off" autofocus>`}</div>` : ''}
          ${note ? `<div class="field" style="margin-top:14px"><label class="label" for="dlgNote">${esc(note.label)}</label><input class="input" id="dlgNote" maxlength="180" placeholder="${esc(note.placeholder || '')}"></div>` : ''}
        </form>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" type="button" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn ${danger ? 'btn--danger-solid' : 'btn--primary'}" type="button" data-act="yes">${esc(confirm)}</button></div>`, { width: 500 });
      let done = false;
      const value = () => {
        if (choices) { const c = $('input[name=dlgChoice]:checked', m); return { choice: c ? c.value : null, note: note ? $('#dlgNote', m).value.trim() : '' }; }
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
  function fmtDay(s, year = 'auto') {
    const d = s ? new Date(String(s).slice(0, 10) + 'T00:00:00') : null;
    if (!d || isNaN(d)) return '';
    const showYear = year === true || (year === 'auto' && d.getFullYear() !== new Date().getFullYear());
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + (showYear ? ' ' + d.getFullYear() : '');
  }
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
  /** Rand → "R 12 345.67" (negative with a proper minus). */
  function money(rand, opts = {}) {
    if (rand === null || rand === undefined || rand === '') return opts.empty != null ? opts.empty : '—';
    return moneyC(Math.round(Number(rand) * 100), opts);
  }
  function moneyC(cents, opts = {}) {
    const n = Math.round(Number(cents) || 0);
    const abs = Math.abs(n);
    const s = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + '.' + String(abs % 100).padStart(2, '0');
    return (n < 0 ? '−' : '') + (opts.symbol === false ? '' : 'R ') + s;
  }
  /** "1 234,50" / "R1,234.50" / "1234.5" → cents, or null. */
  function toCents(v) {
    let s = String(v == null ? '' : v).trim().replace(/[R\s ]/gi, '').replace('−', '-');
    if (s === '') return null;
    if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
    else if (/^-?\d+,\d{1,2}$/.test(s)) s = s.replace(',', '.');
    if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
    return Math.round(parseFloat(s) * 100);
  }
  /** A quantity or rate as typed ("2,5", "1 000", "1,000.5") → a number, or null. Mirrors RcKit::number. */
  function toNum(v) {
    let s = String(v == null ? '' : v).trim().replace(/[\s\u00a0]/g, '').replace('\u2212', '-');
    if (s === '') return null;
    if (/^-?[1-9]\d{0,2}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
    else if (/^-?\d+,\d+$/.test(s)) s = s.replace(',', '.');
    if (!/^-?\d+(\.\d+)?$/.test(s)) return null;
    return parseFloat(s);
  }
  const STATE_TONE = { reading: 'info', review: 'gold', draft: 'accent', posted: 'success', paid: 'success', aside: 'muted' };
  const STATE_ICON = { reading: 'ir-scan', review: 'i-eye', draft: 'i-pen', posted: 'if-ledger', paid: 'if-cash', aside: 'i-archive' };
  const pill = (state, label) => `<span class="fwr-pill" data-tone="${STATE_TONE[state] || 'muted'}">${esc(label)}</span>`;
  /** A receipt as a card for the overview and the inbox. */
  function card(r, opts = {}) {
    const who = r.supplier || r.vendor || (r.state === 'reading' ? 'Being read…' : 'Supplier not known yet');
    const thumb = r.thumb ? `<img src="${esc(r.thumb)}" alt="" loading="lazy" decoding="async">` : `<span class="fwr-card__pdf">${icon(r.pdf ? 'ir-pdf' : 'i-image')}<small>${r.pdf ? 'PDF' : ''}</small></span>`;
    const flags = [];
    if (r.blocked) flags.push(`<span class="fwr-flag fwr-flag--danger">${icon('alert', 'i--xs')}Held by a check</span>`);
    if (r.read && r.read.state === 'failed' && !r.bill_id && r.state !== 'aside') flags.push(`<span class="fwr-flag">${icon('eye-off', 'i--xs')}Could not read</span>`);
    if (r.paid_on_spot && r.state !== 'paid') flags.push(`<span class="fwr-flag fwr-flag--info">${icon('ir-card', 'i--xs')}Paid on the spot</span>`);
    if (r.read && r.read.engine === 'ai' && r.state === 'review') flags.push(`<span class="fwr-flag fwr-flag--gold">${icon('ir-ai', 'i--xs')}Read by AI</span>`);
    return `<article class="fwr-card${opts.select ? ' is-selectable' : ''}" data-id="${r.id}" style="--h:${hue(who)}">
      ${opts.select ? `<label class="fwr-card__check" aria-label="Select"><input type="checkbox" data-select="${r.id}"><span>${icon('check', 'i--xs')}</span></label>` : ''}
      <a class="fwr-card__link" href="/receipts/review.php?id=${r.id}" aria-label="Open receipt ${r.id}: ${esc(who)}">
        <span class="fwr-card__thumb">${thumb}${r.state === 'reading' ? '<span class="fwr-card__scan" aria-hidden="true"></span>' : ''}</span>
        <span class="fwr-card__body">
          <span class="fwr-card__top"><b class="fwr-card__who">${esc(who)}</b><span class="fwr-card__amt">${r.amount != null ? esc(money(r.amount)) : ''}</span></span>
          <span class="fwr-card__meta">${esc([r.number ? '#' + r.number : '', r.date ? fmtDay(r.date) : '', opts.uploader !== false && r.uploader ? r.uploader : ''].filter(Boolean).join(' · ') || ('Sent in ' + ago(r.uploaded_at)))}</span>
          ${r.note ? `<span class="fwr-card__note">“${esc(r.note)}”</span>` : ''}
          <span class="fwr-card__foot">${pill(r.state, r.state_label)}${flags.join('')}<small>${esc(ago(r.uploaded_at))}</small></span>
        </span>
      </a>
    </article>`;
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
          ${cos.map((c) => `<button type="button" class="fwm-pop__item fwr-menu__company" role="menuitem" data-fw-switch-company="${c.id}"${c.active ? ' disabled aria-current="true"' : ''}>
            <span class="fwm-avatar" style="--h:${hue(c.name)}">${esc(initials(c.name))}</span><span class="fwr-menu__name">${esc(c.name)}</span>${c.active ? icon('check', 'i--sm') : ''}</button>`).join('')}
          <div class="fwm-pop__sep" role="separator"></div>` : ''}
        ${BOOT.caps && BOOT.caps.upload ? `<a class="fwm-pop__item" role="menuitem" href="/receipts/upload.php">${icon('ir-camera')}Snap a receipt</a>` : ''}
        ${BOOT.caps && BOOT.caps.all ? `<a class="fwm-pop__item" role="menuitem" href="/receipts/api/receipts.php?op=export&view=all">${icon('download')}Export receipts (CSV)</a>` : ''}
        <a class="fwm-pop__item" role="menuitem" href="/home.php">${icon('home')}Flowwork home</a>
        <a class="fwm-pop__item" role="menuitem" href="/account/">${icon('ih-devices')}Signed-in devices</a>
        <button class="fwm-pop__item" role="menuitem" type="button" data-keys>${icon('keyboard')}Keyboard shortcuts</button>
        <div class="fwm-pop__sep" role="separator"></div>
        <a class="fwm-pop__item" role="menuitem" href="/logout.php">${icon('ih-logout')}Sign out</a>`, { align: 'end', cls: 'fwr-menu' });
      const k = $('[data-keys]', activePop.el);
      if (k) k.addEventListener('click', () => { closePop(); shortcuts(); });
    });

    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
    $$('#side a, #side [data-capture]').forEach((a) => a.addEventListener('click', () => toggleDrawer(false)));

    // g + letter jumps between pages
    let gAt = 0;
    document.addEventListener('keydown', (e) => {
      if (typingIn(e.target) || e.ctrlKey || e.metaKey || e.altKey || openModals.length) return;
      if (e.key === '?') { e.preventDefault(); shortcuts(); return; }
      if (e.key === 'g') { gAt = Date.now(); return; }
      if (Date.now() - gAt < 1200) {
        const map = { o: '', i: 'inbox.php', b: 'inbox.php?view=done', a: 'inbox.php?view=aside', s: 'settings.php', u: 'upload.php' };
        if (map[e.key] !== undefined) { e.preventDefault(); gAt = 0; location.href = '/receipts/' + map[e.key]; }
        return;
      }
      if (e.key === 'n' && uploader.ready) { e.preventDefault(); uploader.pick(); }
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
    const rows = [['/ or Ctrl K', 'Find a receipt'], ['n', 'Send in receipts'], ['Ctrl V', 'Paste a screenshot of a slip'], ['g then o', 'Overview'], ['g then i', 'To do'],
      ['g then b', 'In the books'], ['g then s', 'Settings'], ['j / k', 'Next / previous receipt (review)'], ['Ctrl S', 'Save the draft bill'], ['Ctrl Enter', 'Post to the books'],
      ['Esc', 'Close'], ['?', 'This list']];
    const m = modal(`<div class="fwm-dialog__head"><h2>Keyboard shortcuts</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fwm-kbd-grid">${rows.map(([k, l]) => `<div class="fwm-kbd-row"><span>${esc(l)}</span><span>${k.split(' then ').map((x) => `<kbd class="fwm-kbd">${esc(x)}</kbd>`).join(' then ')}</span></div>`).join('')}</div></div>`, { width: 480 });
    openModal(m);
  }

  // ------------------------------------------------------ "Find a receipt"
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
      shown = [...found.map((r) => ({ name: r.supplier || r.vendor || r.name, href: '/receipts/review.php?id=' + r.id, icon: STATE_ICON[r.state] || 'ir-seal',
        hint: [r.number ? '#' + r.number : '', r.date ? fmtDay(r.date) : '', r.amount != null ? money(r.amount) : '', r.state_label].filter(Boolean).join(' · '), thumb: r.thumb })), ...pg];
      active = shown.length ? 0 : -1;
      let html = '';
      if (found.length) html += '<div class="fwr-jump__group" role="presentation">Receipts</div>';
      shown.forEach((d, i) => {
        if (i === found.length && pg.length) html += `<div class="fwr-jump__group" role="presentation">${q ? 'Pages' : 'Go to'}</div>`;
        html += `<div class="fwr-opt" role="option" id="jumpOpt${i}" data-i="${i}" aria-selected="${i === active}">
          <span class="fwr-opt__icon">${d.thumb ? `<img src="${esc(d.thumb)}" alt="" loading="lazy">` : icon(d.icon || 'ir-seal')}</span>
          <span class="fwr-opt__text"><span class="fwr-opt__name">${mark(d.name, q)}</span><span class="fwr-opt__hint">${esc(d.hint || '')}</span></span>
          <span class="fwr-opt__enter" aria-hidden="true">↵</span></div>`;
      });
      if (loading) html += '<div class="fwr-jump__none"><span class="fwr-spin"></span> Looking…</div>';
      else if (!shown.length) html += `<div class="fwr-jump__none">Nothing matches “${esc(input.value.trim())}”.</div>`;
      list.innerHTML = html;
      setActive(active);
    }
    const search = debounce(async (q, n) => {
      try {
        const res = await api.get('receipts.php', { op: 'list', view: 'all', q, sort: 'new' });
        if (n === seq) draw(res.rows.slice(0, 7), norm(q), false);
      } catch (e) {
        if (n === seq) draw([], norm(q), false);
      }
    }, 220);
    function render() {
      const q = input.value.trim();
      const n = ++seq;
      if (q.length < 2) { draw([], norm(q), false); return; }
      draw([], norm(q), true);
      search(q, n);
    }
    function setActive(i) {
      active = i;
      $$('.fwr-opt', list).forEach((o) => o.setAttribute('aria-selected', String(+o.dataset.i === i)));
      const el = i >= 0 ? $('#jumpOpt' + i, list) : null;
      if (el) { input.setAttribute('aria-activedescendant', el.id); el.scrollIntoView({ block: 'nearest' }); }
      else input.removeAttribute('aria-activedescendant');
    }
    const open = () => { closePop(); render(); list.hidden = false; input.setAttribute('aria-expanded', 'true'); };
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); };
    const go = (d) => { if (d) location.href = d.href; else if (input.value.trim()) location.href = '/receipts/inbox.php?view=all&q=' + encodeURIComponent(input.value.trim()); };
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
    list.addEventListener('click', (ev) => { const o = ev.target.closest('.fwr-opt'); if (o) go(shown[+o.dataset.i]); });
    list.addEventListener('pointermove', (ev) => { const o = ev.target.closest('.fwr-opt'); if (o && +o.dataset.i !== active) setActive(+o.dataset.i); });
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

  // ------------------------------------------------------ settings engine
  // A form marked data-settings holds rows with fields named by their
  // setting key. Values are compared with the saved ones; the save bar
  // appears while anything differs. Saving posts {op:'save', …changed keys}
  // to /receipts/api/settings.php, which checks every value.
  function initSettings() {
    const forms = $$('form[data-settings]');
    if (!forms.length) return;
    const bar = document.createElement('div');
    bar.className = 'fwr-savebar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Unsaved changes');
    bar.innerHTML = `<span class="fwr-savebar__msg"><span>Unsaved changes</span><small id="saveCount"></small></span>
      <button class="btn btn--ghost" type="button" id="saveDiscard">Discard</button>
      <button class="btn btn--primary" type="button" id="saveGo">${icon('check', 'i--sm')}Save<kbd>Ctrl S</kbd></button>`;
    document.body.appendChild(bar);
    const fieldValue = (f) => (f.type === 'checkbox' ? (f.checked ? '1' : '0') : (f.type === 'radio' ? (f.checked ? f.value : null) : f.value));
    const fieldsOf = (form) => $$('[name]', form).filter((f) => !f.disabled && f.name && !f.dataset.ignore && (f.type !== 'radio' || true));
    const valueOf = (form, name) => {
      const fs = $$(`[name="${name}"]`, form);
      if (fs.length && fs[0].type === 'radio') { const c = fs.find((x) => x.checked); return c ? c.value : ''; }
      return fs.length ? fieldValue(fs[0]) : '';
    };
    const names = (form) => Array.from(new Set(fieldsOf(form).map((f) => f.name)));
    const saved = new Map();
    forms.forEach((form) => {
      names(form).forEach((n) => saved.set(form.dataset.settings + ':' + n, valueOf(form, n)));
      form.addEventListener('input', () => refresh());
      form.addEventListener('change', () => refresh());
      form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    });
    function changed() {
      const out = [];
      forms.forEach((form) => names(form).forEach((n) => { const v = valueOf(form, n); if (v !== saved.get(form.dataset.settings + ':' + n)) out.push({ form, name: n, value: v }); }));
      return out;
    }
    function applyRequires() {
      $$('[data-requires]').forEach((row) => {
        const [name, want] = row.dataset.requires.split('=');
        const form = row.closest('form');
        const v = form ? valueOf(form, name) : '';
        row.classList.toggle('is-off', want !== undefined ? v !== want : (v === '' || v === '0'));
      });
    }
    function rowOf(form, name) { const f = $(`[name="${name}"]`, form); return f && (f.closest('.fwr-set') || f.closest('.field')); }
    function refresh() {
      applyRequires();
      const list = changed();
      $$('.fwr-set').forEach((r) => r.classList.remove('is-dirty'));
      list.forEach(({ form, name }) => { const row = rowOf(form, name); if (row) row.classList.add('is-dirty'); });
      bar.classList.toggle('is-on', list.length > 0);
      $('#saveCount', bar).textContent = list.length === 1 ? '1 setting changed' : list.length + ' settings changed';
      return list;
    }
    function clearErrors() { $$('form[data-settings] .is-invalid').forEach((r) => { r.classList.remove('is-invalid'); const e = $('.fwr-set__error', r); if (e) e.remove(); }); }
    function showError(key, msg) {
      let row = null;
      forms.forEach((form) => { row = row || rowOf(form, key); });
      if (!row) { toast(msg, { type: 'error' }); return null; }
      row.classList.add('is-invalid');
      const e = document.createElement('div');
      e.className = 'fwr-set__error';
      e.textContent = msg;
      row.appendChild(e);
      return row;
    }
    let saving = false;
    async function save() {
      if (saving) return;
      clearErrors();
      const list = refresh();
      if (!list.length) return;
      const bad = list.map(({ form, name }) => $(`[name="${name}"]`, form)).find((f) => f && f.checkValidity && !f.checkValidity());
      if (bad) { const r = showError(bad.name, bad.validationMessage || 'Check this value.'); if (r) r.scrollIntoView({ block: 'center', behavior: 'smooth' }); return; }
      const values = { op: 'save' };
      list.forEach(({ name, value }) => { values[name] = value; });
      saving = true;
      const btn = $('#saveGo', bar);
      busy(btn, true);
      try {
        const res = await api.post('settings.php', values);
        list.forEach(({ form, name, value }) => saved.set(form.dataset.settings + ':' + name, value));
        document.dispatchEvent(new CustomEvent('fwr:saved', { detail: res }));
        refresh();
        toast(list.length === 1 ? 'Setting saved' : list.length + ' settings saved');
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
      changed().forEach(({ form, name }) => {
        const was = saved.get(form.dataset.settings + ':' + name);
        $$(`[name="${name}"]`, form).forEach((f) => {
          if (f.type === 'checkbox') f.checked = was === '1';
          else if (f.type === 'radio') f.checked = f.value === was;
          else f.value = was;
        });
      });
      clearErrors();
      refresh();
      toast('Changes discarded');
    }
    $('#saveGo', bar).addEventListener('click', save);
    $('#saveDiscard', bar).addEventListener('click', discard);
    document.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save(); } });
    window.addEventListener('beforeunload', (e) => { if (changed().length) { e.preventDefault(); e.returnValue = ''; } });
    $$('.fwr-set--toggle').forEach((row) => row.addEventListener('click', (e) => {
      if (e.target.closest('input, a, button, label, select')) return;
      const cb = $('input[type=checkbox]', row);
      if (cb && !cb.disabled) { cb.checked = !cb.checked; cb.dispatchEvent(new Event('change', { bubbles: true })); }
    }));
    refresh();
    FWR.settings = { save, refresh, isDirty: () => changed().length > 0 };
  }

  // ------------------------------------------------------------- uploader
  const uploader = (() => {
    const caps = BOOT.caps || {};
    const ACCEPT = /^(application\/pdf|image\/(jpeg|png|webp)|application\/(x-)?zip(-compressed)?)$/;
    const items = [];
    let reading = 0;
    let uploading = 0;
    let noteFn = () => '';
    let seq = 0;
    const self = { ready: false, items };

    function tray() { return $('#rcTray'); }
    function okType(f) {
      if (ACCEPT.test(f.type)) return true;
      return /\.(pdf|jpe?g|png|webp|zip)$/i.test(f.name || '');
    }
    /** Phone photos are large: make them smaller (long side 2400 px) before they travel. */
    async function shrink(file) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 1600000) return file;
      try {
        let bmp;
        if (window.createImageBitmap) bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        else bmp = await new Promise((res, rej) => { const img = new Image(); img.onload = () => res(img); img.onerror = rej; img.src = URL.createObjectURL(file); });
        const w = bmp.width, h = bmp.height;
        const scale = Math.min(1, 2400 / Math.max(w, h));
        const c = document.createElement('canvas');
        c.width = Math.round(w * scale);
        c.height = Math.round(h * scale);
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(bmp, 0, 0, c.width, c.height);
        if (bmp.close) bmp.close();
        const blob = await new Promise((res) => c.toBlob(res, 'image/jpeg', 0.86));
        if (!blob || blob.size >= file.size) return file;
        return new File([blob], file.name.replace(/\.(png|webp|jpe?g)$/i, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
      } catch (e) {
        return file;
      }
    }
    function add(fileList, src) {
      if (!caps.upload) { toast('Viewers cannot send in receipts.', { type: 'error' }); return; }
      const files = Array.from(fileList || []).filter(Boolean);
      if (!files.length) return;
      const bad = files.filter((f) => !okType(f));
      bad.forEach((f) => toast(`“${f.name || 'That file'}” is not a PDF, photo or ZIP.`, { type: 'error' }));
      files.filter(okType).forEach((f) => {
        const isZip = /zip/.test(f.type) || /\.zip$/i.test(f.name || '');
        const it = { key: ++seq, file: f, name: f.name || (src === 'paste' ? 'Pasted slip.png' : 'Photo.jpg'), size: f.size, zip: isZip, state: 'queued', progress: 0, results: [],
                     preview: /^image\//.test(f.type) ? URL.createObjectURL(f) : null };
        items.push(it);
      });
      show();
      pump();
    }
    async function pump() {
      while (uploading < 2) {
        const it = items.find((x) => x.state === 'queued');
        if (!it) break;
        uploading++;
        send(it).finally(() => { uploading--; pump(); });
      }
      readNext();
    }
    async function send(it) {
      try {
        it.state = 'shrinking'; draw();
        const f = it.zip ? it.file : await shrink(it.file);
        if (!it.zip && f.size > (BOOT.maxBytes || 15728640)) throw new ApiError(`“${it.name}” is too large — ${Math.round((BOOT.maxBytes || 15728640) / 1048576)} MB at most.`, 413);
        it.state = 'uploading'; draw();
        const form = new FormData();
        form.append('file', f, f.name || it.name);
        const note = noteFn();
        if (note) form.append('note', note);
        const res = await api.upload('upload.php', form, (p) => { it.progress = p; draw(); });
        it.results = (res.files || []).map((x) => ({ id: x.file_id, name: x.name, duplicate: !!x.duplicate, receipt: x.receipt, state: x.duplicate ? 'duplicate' : 'toread' }));
        it.skipped = res.skipped || [];
        it.state = it.results.length ? 'sent' : 'error';
        if (!it.results.length) it.error = (it.skipped[0] && it.skipped[0].reason) || 'Nothing in it could be used.';
        document.dispatchEvent(new CustomEvent('fwr:uploaded', { detail: it }));
      } catch (e) {
        it.state = 'error';
        it.error = e.message;
      }
      draw();
    }
    async function readNext() {
      while (reading < 2) {
        let target = null, owner = null;
        for (const it of items) { const r = it.results.find((x) => x.state === 'toread'); if (r) { target = r; owner = it; break; } }
        if (!target) break;
        reading++;
        target.state = 'reading';
        draw();
        api.post('read.php', { id: target.id }).then((res) => {
          target.state = 'read';
          target.read = res.read;
          target.receipt = res.receipt;
          document.dispatchEvent(new CustomEvent('fwr:read', { detail: { id: target.id, read: res.read, receipt: res.receipt } }));
        }).catch((e) => { target.state = 'readfail'; target.error = e.message; })
          .finally(() => { reading--; draw(); readNext(); finishedCheck(); });
      }
    }
    function finishedCheck() {
      const busyNow = items.some((it) => ['queued', 'shrinking', 'uploading'].includes(it.state) || it.results.some((r) => ['toread', 'reading'].includes(r.state)));
      if (!busyNow) document.dispatchEvent(new CustomEvent('fwr:done'));
    }
    function lineFor(it, r) {
      if (r) {
        const rc = r.receipt || {};
        const link = `/receipts/review.php?id=${r.id}`;
        if (r.state === 'duplicate') return { state: 'dup', title: r.name, sub: rc.id ? 'Sent in before — open the one you already have' : 'This slip was already sent in', href: rc.id ? link : null };
        if (r.state === 'toread') return { state: 'wait', title: r.name, sub: 'Waiting to be read' };
        if (r.state === 'reading') return { state: 'busy', title: r.name, sub: 'Reading the slip…' };
        if (r.state === 'readfail') return { state: 'warn', title: r.name, sub: r.error || 'Not read — type it in', href: link };
        const rd = r.read || {};
        if (!rd.ok) return { state: 'warn', title: r.name, sub: rd.error || 'Could not be read — type the details in', href: link };
        return { state: 'ok', title: rd.vendor || r.name, sub: [rd.total != null ? money(rd.total) : '', rd.date ? fmtDay(rd.date) : '', rd.engine === 'ai' ? 'read by AI' : 'read'].filter(Boolean).join(' · '), href: link };
      }
      if (it.state === 'error') return { state: 'err', title: it.name, sub: it.error || 'Not sent in' };
      if (it.state === 'shrinking') return { state: 'busy', title: it.name, sub: 'Getting it ready…' };
      if (it.state === 'uploading') return { state: 'busy', title: it.name, sub: 'Sending… ' + Math.round(it.progress * 100) + '%', progress: it.progress };
      return { state: 'wait', title: it.name, sub: 'Waiting…' };
    }
    function draw() {
      const t = tray();
      if (!t) return;
      const rows = [];
      items.forEach((it) => {
        if (!it.results.length || it.state === 'error') rows.push([it, null]);
        else it.results.forEach((r) => rows.push([it, r]));
        (it.skipped || []).forEach((s) => rows.push([{ ...it, state: 'error', name: s.name, error: s.reason, preview: null }, null]));
      });
      $('#rcTrayList').innerHTML = rows.map(([it, r]) => {
        const l = lineFor(it, r);
        const img = r && r.receipt && r.receipt.thumb ? r.receipt.thumb : it.preview;
        const mark = { ok: icon('check', 'i--sm'), warn: icon('alert', 'i--sm'), err: icon('x', 'i--sm'), dup: icon('copy', 'i--sm'), busy: '<span class="fwr-spin"></span>', wait: '<span class="fwr-dotwait"></span>' }[l.state];
        const inner = `<span class="fwr-tray__thumb">${img ? `<img src="${esc(img)}" alt="">` : icon(it.zip && !r ? 'ir-zip' : 'ir-pdf')}</span>
          <span class="fwr-tray__text"><b>${esc(l.title)}</b><small>${esc(l.sub)}</small>${l.progress != null ? `<i class="fwr-tray__bar"><i style="width:${Math.round(l.progress * 100)}%"></i></i>` : ''}</span>
          <span class="fwr-tray__mark" data-s="${l.state}">${mark}</span>`;
        return `<li class="fwr-tray__row" data-s="${l.state}">${l.href ? `<a href="${esc(l.href)}">${inner}</a>` : `<div>${inner}</div>`}</li>`;
      }).join('');
      const all = rows.filter(([, r]) => r);
      const done = all.filter(([, r]) => ['read', 'readfail', 'duplicate'].includes(r.state)).length;
      const working = items.some((it) => ['queued', 'shrinking', 'uploading'].includes(it.state)) || all.some(([, r]) => ['toread', 'reading'].includes(r.state));
      $('#rcTrayTitle').textContent = working ? `Sending in · ${done} of ${Math.max(all.length, items.length)} ready` : `${all.length || 0} sent in`;
      const foot = $('#rcTrayFoot');
      const fresh = all.filter(([, r]) => r.state === 'read' || r.state === 'readfail').map(([, r]) => r.id);
      foot.hidden = working || !fresh.length || !caps.capture;
      if (!foot.hidden) foot.innerHTML = `<a class="btn btn--primary btn--sm" href="/receipts/review.php?id=${fresh[0]}">${icon('i-eye', 'i--sm')}Review ${fresh.length === 1 ? 'it' : 'them'} now</a><small>${fresh.length === 1 ? '' : 'Next and previous with j and k'}</small>`;
      t.classList.toggle('is-working', working);
    }
    function show() {
      const t = tray();
      if (!t) return;
      t.hidden = false;
      t.classList.remove('is-min');
      draw();
    }
    function init() {
      const pick = $('#rcPick');
      const cam = $('#rcCamera');
      if (!pick || !caps.upload) return;
      self.ready = true;
      pick.addEventListener('change', () => { add(pick.files, 'pick'); pick.value = ''; });
      if (cam) cam.addEventListener('change', () => { add(cam.files, 'camera'); cam.value = ''; });
      document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-capture]');
        if (!b) return;
        e.preventDefault();
        if (b.dataset.capture === 'camera' && cam) cam.click(); else pick.click();
      });
      // Drop anywhere
      const drop = $('#rcDrop');
      let depth = 0;
      const hasFiles = (e) => e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files');
      window.addEventListener('dragenter', (e) => { if (!hasFiles(e)) return; e.preventDefault(); depth++; drop.hidden = false; });
      window.addEventListener('dragover', (e) => { if (!hasFiles(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
      window.addEventListener('dragleave', (e) => { if (!hasFiles(e)) return; depth = Math.max(0, depth - 1); if (!depth) drop.hidden = true; });
      window.addEventListener('drop', (e) => { if (!hasFiles(e)) return; e.preventDefault(); depth = 0; drop.hidden = true; add(e.dataTransfer.files, 'drop'); });
      // Paste a screenshot or a copied file
      document.addEventListener('paste', (e) => {
        if (typingIn(e.target) && e.target.type !== 'file') {
          const hasText = (e.clipboardData && e.clipboardData.getData('text')) || '';
          if (hasText) return;
        }
        const files = Array.from((e.clipboardData && e.clipboardData.files) || []);
        if (!files.length) return;
        e.preventDefault();
        add(files.map((f, i) => (f.name && f.name !== 'image.png' ? f : new File([f], `Pasted slip ${new Date().toLocaleTimeString().replace(/:/g, '.')}${i ? ' ' + (i + 1) : ''}.png`, { type: f.type }))), 'paste');
      });
      $('#rcTrayClose').addEventListener('click', () => {
        const working = items.some((it) => ['queued', 'shrinking', 'uploading'].includes(it.state));
        if (working) { toast('Still sending — the tray will close when it is done.'); $('#rcTray').classList.add('is-min'); return; }
        $('#rcTray').hidden = true;
        items.length = 0;
      });
      $('#rcTrayMin').addEventListener('click', () => $('#rcTray').classList.toggle('is-min'));
      window.addEventListener('beforeunload', (e) => {
        if (items.some((it) => ['queued', 'shrinking', 'uploading'].includes(it.state))) { e.preventDefault(); e.returnValue = ''; }
      });
    }
    Object.assign(self, { add, init, pick: () => { const p = $('#rcPick'); if (p) p.click(); }, camera: () => { const c = $('#rcCamera'); if (c) c.click(); },
                          setNote: (fn) => { noteFn = fn; } });
    return self;
  })();

  const FWR = { $, $$, esc, icon, api, ApiError, toast, toastError, popover, closePop, modal, openModal, closeModal, dialog, initials, hue, busy, fmtDay, ago,
    money, moneyC, toCents, toNum, debounce, card, pill, typingIn, uploader, boot: BOOT, STATE_TONE };
  window.FWR = FWR;

  // A slip whose file is gone shows a placeholder instead of a broken picture.
  document.addEventListener('error', (e) => {
    const img = e.target;
    if (!(img instanceof HTMLImageElement)) return;
    const box = img.closest('.fwr-card__thumb, .fwr-rows__thumb, .fwr-tray__thumb, .fwr-opt__icon');
    if (!box) return;
    img.remove();
    box.insertAdjacentHTML('beforeend', box.classList.contains('fwr-card__thumb') ? `<span class="fwr-card__pdf">${icon('i-image')}</span>` : icon('i-image'));
  }, true);

  function init() {
    initChrome();
    initJump();
    initSettings();
    uploader.init();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
