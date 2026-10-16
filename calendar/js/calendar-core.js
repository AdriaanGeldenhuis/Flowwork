/* =============================================================================
   Flowwork Calendar — shared client core
   Helpers (API, toasts, popovers, dialogs), the Mail chrome (theme, menu,
   drawer) and the calendar dialogs (create, edit, share, subscribe, delete,
   import) used by both the calendar and its settings page via window.FWCal.
   Pages put their data in <script id="calBoot" type="application/json">.
   ============================================================================= */
(function () {
  'use strict';

  // ===========================================================================
  // Core helpers (mirrors /mail/js/mail.js)
  // ===========================================================================
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (name, cls = '') => `<svg class="i ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  const narrow = () => window.matchMedia('(max-width: 820px)').matches;
  const finePointer = () => window.matchMedia('(pointer: fine)').matches;

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
      location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search);
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
      return request('GET', '/calendar/api/' + path + (qs ? '?' + qs : ''));
    },
    post: (path, body) => request('POST', '/calendar/api/' + path, body || {}),
    upload: (path, formData) => request('POST', '/calendar/api/' + path, formData, true),
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
  const avatar = (name, email, cls = '') => `<span class="fwm-avatar ${cls}" style="--h:${hueOf(email || name)}" aria-hidden="true">${esc(initials(name, email))}</span>`;
  function fileKind(name, mime) {
    const ext = String(name || '').split('.').pop().toLowerCase();
    if (/^image\//.test(mime || '') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic'].includes(ext)) return ['img', ext.toUpperCase()];
    if (ext === 'pdf' || mime === 'application/pdf') return ['pdf', 'PDF'];
    if (['doc', 'docx', 'odt', 'rtf', 'txt'].includes(ext)) return ['doc', ext.toUpperCase()];
    if (['xls', 'xlsx', 'csv', 'ods'].includes(ext)) return ['xls', ext.toUpperCase()];
    if (['zip', 'rar', '7z', 'gz'].includes(ext)) return ['zip', ext.toUpperCase()];
    return ['', (ext || 'FILE').slice(0, 4).toUpperCase()];
  }
  const fmtSize = (n) => { n = Number(n) || 0; return n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; };

  // ---------------------------------------------------------------- popovers
  let activePop = null;
  function closePop() { if (activePop) { const p = activePop; activePop = null; p.el.remove(); if (p.onClose) p.onClose(); } }
  /** popover(anchor | {x, y}, html, {onClick(el, ev), onClose, align, width, cls}) */
  function popover(anchor, html, opts = {}) {
    closePop();
    const el = document.createElement('div');
    el.className = 'fwm-pop ' + (opts.cls || '');
    el.setAttribute('role', opts.role || 'menu');
    if (opts.width) el.style.minWidth = opts.width + 'px';
    el.innerHTML = html;
    document.body.appendChild(el);
    const r = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : { left: anchor.x, right: anchor.x, top: anchor.y, bottom: anchor.y };
    const pw = el.offsetWidth, ph = el.offsetHeight;
    let left = opts.align === 'end' ? r.right - pw : (opts.align === 'side' ? r.right + 8 : r.left);
    if (opts.align === 'side' && left + pw > window.innerWidth - 8) left = r.left - pw - 8;
    left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
    let top = opts.align === 'side' ? r.top : r.bottom + 6;
    if (top + ph > window.innerHeight - 8) top = Math.max(8, (opts.align === 'side' ? window.innerHeight - ph - 8 : r.top - ph - 6));
    el.style.left = left + 'px';
    el.style.top = top + 'px';
    const pop = { el, onClose: opts.onClose, anchor: anchor && anchor.nodeType ? anchor : null };
    activePop = pop;
    el.addEventListener('click', (ev) => {
      const item = ev.target.closest('[data-v]');
      if (!item || !el.contains(item) || item.disabled) return;
      const keep = opts.onClick && opts.onClick(item, ev) === false;
      if (!keep) closePop();
    });
    el.addEventListener('keydown', (ev) => {
      const items = $$('[data-v]:not([disabled])', el);
      const i = items.indexOf(document.activeElement);
      if (ev.key === 'ArrowDown') { ev.preventDefault(); (items[i + 1] || items[0]).focus(); }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); (items[i - 1] || items[items.length - 1]).focus(); }
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); closePop(); if (pop.anchor && pop.anchor.focus) pop.anchor.focus(); }
    });
    const first = $('input:not([type=hidden])', el) || $('[data-v]', el);
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
    const f = $('[autofocus], input:not([type=hidden]):not([type=file]), select, textarea, button.btn--primary', m);
    setTimeout(() => f && f.focus(), 30);
    return m;
  }
  function closeModal(m) {
    if (typeof m === 'string') m = document.getElementById(m);
    if (!m || m.hidden) return;
    if (m.dataset.guard && m._guard && !m._guard()) return;
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
  /** Promise-based dialog: confirm, or choose one of `choices` ([{v, label, hint}]). */
  function dialog({ title, body = '', confirm = 'OK', cancel = 'Cancel', danger = false, choices = null, value = null }) {
    return new Promise((resolve) => {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2></div>
        <div class="fwm-dialog__body">${body ? `<p class="fwm-dialog__lead" style="margin-bottom:${choices ? 14 : 0}px">${body}</p>` : ''}
          ${choices ? `<div class="fwm-results">${choices.map((c, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="dlgChoice" value="${esc(c.v)}" ${(value ? c.v === value : i === 0) ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(c.label)}</b>${c.hint ? `<small>${esc(c.hint)}</small>` : ''}</span></label>`).join('')}</div>` : ''}</div>
        <div class="fwm-dialog__foot">${cancel ? `<button class="btn" data-act="no">${esc(cancel)}</button>` : ''}
          <button class="btn btn--primary" data-act="yes" ${danger ? 'style="background:var(--danger)"' : ''}>${esc(confirm)}</button></div>`, '', 'width:min(460px,100%)');
      let done = false;
      const finish = (v) => { if (done) return; done = true; closeModal(m); resolve(v); };
      m._onClose = () => { if (!done) { done = true; resolve(choices ? null : false); } };
      m.addEventListener('click', (e) => {
        const b = e.target.closest('[data-act]');
        if (!b) return;
        if (b.dataset.act !== 'yes') return finish(choices ? null : false);
        finish(choices ? ($('input[name=dlgChoice]:checked', m) || {}).value || null : true);
      });
      openModal(m);
    });
  }
  const askScope = (verb) => dialog({
    title: verb + ' repeating event', confirm: verb, choices: [
      { v: 'this', label: 'This event', hint: 'Only this occurrence' },
      { v: 'following', label: 'This and following events' },
      { v: 'all', label: 'All events', hint: 'Every occurrence in the series' },
    ],
  });

  // ===========================================================================
  // Shared state: calendars, settings, the signed-in user
  // ===========================================================================
  const BOOT = JSON.parse(($('#calBoot') || {}).textContent || '{}');
  const C = {
    calendars: BOOT.calendars || [],
    settings: BOOT.settings || { week_start: 1, work_start: '08:00', work_end: '17:00', default_view: 'week', default_reminder: 15, show_weekends: true, time_24h: true, default_duration: 60, hidden_calendars: [], sources: {}, timezone: 'Africa/Johannesburg' },
    sources: BOOT.sources || [],
    me: BOOT.me || {},
    role: BOOT.role || 'viewer',
    people: null,
    onChange: () => {},
  };
  const changed = (what) => { try { C.onChange(what); } catch (e) { console.error(e); } };
  const calById = (id) => C.calendars.find((c) => c.id === Number(id));
  const addableCalendars = () => C.calendars.filter((c) => c.can_add);
  const defaultCalId = () => {
    const pref = C.settings.default_calendar_id && calById(C.settings.default_calendar_id);
    if (pref && pref.can_add) return pref.id;
    const own = C.calendars.find((c) => c.mine && c.type === 'personal');
    return (own || addableCalendars()[0] || {}).id || 0;
  };

  async function loadPeople() {
    if (C.people) return C.people;
    try { C.people = (await api.get('people.php')).people || []; } catch (e) { C.people = []; }
    return C.people;
  }
  const PALETTE = ['#7a1f30', '#b08d57', '#2f7457', '#2f5c84', '#7a5ab8', '#b4577a', '#c08a2b', '#1f7a7a', '#a8281e', '#4b4fa8', '#6b7d2b', '#5b6670'];

  // ===========================================================================
  // Calendars: create / edit, share, subscribe, delete, import
  // ===========================================================================
  async function saveCalendar(c, patch) {
    try {
      const d = await api.post('calendars.php', Object.assign({ action: 'update', id: c.id, name: c.name, color: c.color, description: c.description }, patch));
      C.calendars = d.calendars;
      changed('calendars');
    } catch (x) { toastError(x); }
  }
  function calendarDialog(c) {
    const isNew = !c;
    let color = c ? c.color : PALETTE[(C.calendars.length + 1) % PALETTE.length];
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${isNew ? 'New calendar' : 'Edit calendar'}</h2><button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="calForm">
        <div class="field"><label class="label" for="calName">Name</label><input class="input" id="calName" maxlength="255" value="${esc(c ? c.name : '')}" placeholder="e.g. Site visits" autofocus></div>
        ${isNew && C.role !== 'viewer' ? `<div class="field"><span class="label">Who sees it</span><div class="seg" id="calType"><button type="button" class="is-on" data-t="personal">Only me</button><button type="button" data-t="team">Everyone in the company</button></div></div>` : ''}
        <div class="field"><span class="label">Colour</span><div class="fwm-swatches" id="calColors"></div></div>
        <div class="field"><label class="label" for="calDesc">Description</label><textarea class="textarea" id="calDesc" rows="2" maxlength="2000" placeholder="Optional">${esc(c ? c.description : '')}</textarea></div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="calSave">${isNew ? 'Create calendar' : 'Save'}</button></div>`, '', 'width:min(480px,100%)');
    const paint = () => { $('#calColors', m).innerHTML = PALETTE.map((col) => `<button type="button" class="fwm-swatch ${color === col ? 'is-on' : ''}" style="background:${col}" data-color="${col}" aria-label="Colour"></button>`).join(''); };
    paint();
    $('#calColors', m).addEventListener('click', (e) => { const b = e.target.closest('[data-color]'); if (b) { color = b.dataset.color; paint(); } });
    let type = 'personal';
    const seg = $('#calType', m);
    if (seg) seg.addEventListener('click', (e) => { const b = e.target.closest('[data-t]'); if (!b) return; type = b.dataset.t; $$('button', seg).forEach((x) => x.classList.toggle('is-on', x === b)); });
    const go = async () => {
      const name = $('#calName', m).value.trim();
      if (!name) { $('#calName', m).focus(); return; }
      try {
        const d = await api.post('calendars.php', isNew ? { action: 'create', name, color, type, description: $('#calDesc', m).value }
          : { action: 'update', id: c.id, name, color, description: $('#calDesc', m).value });
        C.calendars = d.calendars;
        closeModal(m);
        toast(isNew ? 'Calendar created' : 'Calendar saved');
        changed('calendars');
      } catch (x) { toastError(x); }
    };
    $('#calSave', m).addEventListener('click', go);
    $('#calForm', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
    openModal(m);
  }
  async function shareDialog(c) {
    const people = await loadPeople();
    let share = (c.share || []).map((s) => ({ ...s }));
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Share “${esc(c.name)}”</h2><button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><p class="fwm-dialog__lead">People you share with see this calendar in their sidebar. Private events stay private.</p>
        <div id="shareList"></div><select class="select" id="shareAdd" style="margin-top:10px"><option value="">Add a colleague…</option></select></div>
      <div class="fwm-dialog__foot"><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="shareSave">Save</button></div>`, '', 'width:min(500px,100%)');
    const paint = () => {
      $('#shareList', m).innerHTML = share.map((s, i) => { const p = people.find((x) => x.id === s.user_id) || { name: 'User ' + s.user_id, email: '' };
        return `<div class="fwc-guest">${avatar(p.name, p.email)}<span class="fwc-guest__who"><b>${esc(p.name)}</b><small>${esc(p.email)}</small></span>
          <select class="select" data-si="${i}" style="width:auto;height:34px"><option value="view">Can see</option><option value="edit" ${s.role === 'edit' ? 'selected' : ''}>Can add &amp; edit</option></select>
          <button class="icon-btn icon-btn--sm" type="button" data-sx="${i}" aria-label="Remove">${icon('x', 'i--sm')}</button></div>`; }).join('') || '<div class="hint">Not shared with anyone yet.</div>';
      const taken = new Set([C.me.id, ...share.map((s) => s.user_id)]);
      $('#shareAdd', m).innerHTML = '<option value="">Add a colleague…</option>' + people.filter((p) => !taken.has(p.id)).map((p) => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
    };
    paint();
    m.addEventListener('change', (e) => {
      if (e.target.id === 'shareAdd' && e.target.value) { share.push({ user_id: +e.target.value, role: 'view' }); paint(); }
      if (e.target.dataset.si) share[+e.target.dataset.si].role = e.target.value;
    });
    m.addEventListener('click', (e) => { const b = e.target.closest('[data-sx]'); if (b) { share.splice(+b.dataset.sx, 1); paint(); } });
    $('#shareSave', m).addEventListener('click', async () => {
      try { const d = await api.post('calendars.php', { action: 'share', id: c.id, share }); C.calendars = d.calendars; closeModal(m); toast('Sharing updated'); } catch (x) { toastError(x); }
    });
    openModal(m);
  }
  function feedDialog(c) {
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Subscribe to “${esc(c.name)}”</h2><button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><p class="fwm-dialog__lead">Add this private link to Google Calendar (<i>Other calendars → From URL</i>), Apple Calendar (<i>New calendar subscription</i>) or Outlook (<i>Add calendar → Subscribe from web</i>) to see these events on your phone. It updates about every hour. Anyone with the link can see the events, so keep it to yourself; private events are never included.</p>
        <div class="fwc-feed"><input class="input" id="feedUrl" readonly value="${esc(c.feed_url || '')}"><button class="btn" type="button" id="feedCopy">${icon('copy', 'i--sm')}Copy</button></div></div>
      <div class="fwm-dialog__foot"><button class="btn btn--ghost btn--danger" id="feedReset">${icon('refresh', 'i--sm')}Reset link</button><span class="grow"></span><button class="btn btn--primary" data-close>Done</button></div>`, '', 'width:min(560px,100%)');
    $('#feedCopy', m).addEventListener('click', () => { const i = $('#feedUrl', m); i.select(); (navigator.clipboard ? navigator.clipboard.writeText(i.value) : Promise.reject()).then(() => toast('Link copied'), () => document.execCommand('copy')); });
    $('#feedReset', m).addEventListener('click', async () => {
      if (!(await dialog({ title: 'Reset the link?', body: 'Apps using the old link stop updating. You will need to subscribe again with the new one.', confirm: 'Reset', danger: true }))) return;
      try { const d = await api.post('calendars.php', { action: 'reset_feed', id: c.id }); C.calendars = d.calendars; $('#feedUrl', m).value = (calById(c.id) || {}).feed_url || ''; toast('New link created'); } catch (x) { toastError(x); }
    });
    openModal(m);
  }
  async function deleteCalendar(c) {
    const ok = await dialog({ title: `Delete “${c.name}”?`, body: c.events ? `Its ${c.events} event${c.events === 1 ? '' : 's'} will be deleted too. This cannot be undone.` : 'This cannot be undone.', confirm: 'Delete calendar', danger: true });
    if (!ok) return;
    try { const d = await api.post('calendars.php', { action: 'delete', id: c.id }); C.calendars = d.calendars; toast('Calendar deleted'); changed('calendars'); } catch (x) { toastError(x); }
  }
  function pickImport(calendarId) {
    const f = $('#importFile');
    f.value = '';
    f.onchange = async () => {
      const file = f.files[0];
      if (!file) return;
      const fd = new FormData();
      fd.append('file', file);
      fd.append('calendar_id', calendarId || defaultCalId());
      const t = toast(`Importing ${file.name}…`, { timeout: 0 });
      try {
        const d = await api.upload('import.php', fd);
        t.close();
        const parts = [];
        if (d.added) parts.push(`${d.added} added`);
        if (d.updated) parts.push(`${d.updated} updated`);
        if (d.skipped) parts.push(`${d.skipped} skipped`);
        toast('Import finished: ' + (parts.join(', ') || 'nothing new') + (d.simplified ? ` · ${d.simplified} repeating event${d.simplified === 1 ? '' : 's'} kept as a single occurrence` : ''), { timeout: 9000 });
        changed('calendars');
      } catch (x) { t.close(); toastError(x); }
    };
    f.click();
  }
  // ===========================================================================
  // Chrome (same as Mail)
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
    const onApp = !!$('#calApp');
    if (kebab) kebab.addEventListener('click', () => {
      popover(kebab, `
        ${onApp ? `<a class="fwm-pop__item" data-v="settings" href="/calendar/settings.php">${icon('settings')}Calendar settings</a>` : `<a class="fwm-pop__item" data-v="cal" href="/calendar/">${icon('calendar')}Calendar</a>`}
        <button class="fwm-pop__item" data-v="import">${icon('upload')}Import .ics</button>
        ${onApp ? `<button class="fwm-pop__item" data-v="print">${icon('print')}Print</button>
        <button class="fwm-pop__item" data-v="keys">${icon('keyboard')}Keyboard shortcuts <small>?</small></button>` : ''}
        ${kebab.dataset.admin === '1' ? `<a class="fwm-pop__item" data-v="admin" href="/admin/calendar.php">${icon('shield')}Company calendar settings</a>` : ''}
        <div class="fwm-pop__sep"></div>
        <a class="fwm-pop__item" data-v="mail" href="/mail/">${icon('mail')}Mail</a>
        <a class="fwm-pop__item" data-v="home" href="/home.php">${icon('home')}Flowwork home</a>
        <a class="fwm-pop__item" data-v="devices" href="/account/">${icon('shield')}Signed-in devices</a>`, {
        align: 'end',
        onClick: (it) => {
          if (it.dataset.v === 'keys') openModal('shortcutsModal');
          if (it.dataset.v === 'import') pickImport(defaultCalId());
          if (it.dataset.v === 'print') window.print();
        },
      });
    });
    const drawerBtn = $('#drawerBtn');
    if (drawerBtn) drawerBtn.addEventListener('click', () => toggleDrawer(true));
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

  window.FWCal = {
    $, $$, esc, icon, debounce, narrow, finePointer, api, ApiError, toast, toastError, avatar, fileKind, fmtSize,
    popover, closePop, openModal, closeModal, tempModal, dialog, askScope, toggleDrawer,
    get activePop() { return activePop; },
    C, BOOT, calById, addableCalendars, defaultCalId, loadPeople, PALETTE,
    saveCalendar, calendarDialog, shareDialog, feedDialog, deleteCalendar, pickImport,
  };
  initChrome();
})();
