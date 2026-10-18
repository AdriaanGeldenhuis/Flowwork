/* =============================================================================
   Flowwork Calendar — client
   Same chrome and helpers as Mail (topbar, sidebar, frosted panes, popovers,
   dialogs, toasts). Views: day · week (3 days on phones) · month · year ·
   schedule, plus search. Times from the API are wall-clock strings
   ('YYYY-MM-DD HH:MM:SS') and are always parsed as local dates — never via
   toISOString(), which shifts them into UTC.
   ============================================================================= */
(function () {
  'use strict';

  const {
    $, $$, esc, icon, debounce, narrow, finePointer, api, toast, toastError, avatar, fileKind, fmtSize,
    popover, closePop, openModal, closeModal, tempModal, dialog, askScope, toggleDrawer,
    C, BOOT, calById, addableCalendars, defaultCalId, loadPeople, PALETTE,
    calendarDialog, shareDialog, feedDialog, deleteCalendar, pickImport, saveCalendar,
  } = window.FWCal;
  const popOpen = () => window.FWCal.activePop;

  // ===========================================================================
  // Dates
  // ===========================================================================
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const hm = (d) => pad(d.getHours()) + ':' + pad(d.getMinutes());
  const fmtDT = (d) => ymd(d) + ' ' + hm(d) + ':' + pad(d.getSeconds());
  function parseDT(s) {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!m) return null;
    return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
  }
  // "Now" on the company's wall clock. Event times are company times, so today,
  // the now-line and new-event defaults follow the company zone, not the device's.
  const coClock = (() => {
    try {
      return new Intl.DateTimeFormat('en-GB', { timeZone: C.settings.timezone, hourCycle: 'h23', year: 'numeric', month: '2-digit',
                                                day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
    } catch (e) { return null; }
  })();
  function nowCo() {
    if (!coClock) return new Date();
    const p = {};
    coClock.formatToParts(new Date()).forEach((x) => { p[x.type] = x.value; });
    return new Date(+p.year, +p.month - 1, +p.day, +p.hour % 24, +p.minute, +p.second);
  }
  const day0 = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const endOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23, 59, 59);
  const addDays = (d, n) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n, d.getHours(), d.getMinutes(), d.getSeconds());
  const addMinutes = (d, n) => new Date(d.getTime() + n * 60000);
  const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  const daysBetween = (a, b) => Math.round((day0(b) - day0(a)) / 86400000);
  const isWeekend = (d) => d.getDay() === 0 || d.getDay() === 6;
  const startOfWeek = (d) => { const ws = S.settings.week_start; const diff = (d.getDay() - ws + 7) % 7; return day0(addDays(d, -diff)); };
  const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
  const DOW = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
  const mon3 = (d) => MONTHS[d.getMonth()].slice(0, 3);
  const dow3 = (d) => DOW[d.getDay()].slice(0, 3);
  function isoWeek(d) {
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dn = t.getUTCDay() || 7;
    t.setUTCDate(t.getUTCDate() + 4 - dn);
    return Math.ceil(((t - new Date(Date.UTC(t.getUTCFullYear(), 0, 1))) / 86400000 + 1) / 7);
  }
  function fmtTime(d) {
    const h = d.getHours(), m = d.getMinutes();
    if (S.settings.time_24h) return pad(h) + ':' + pad(m);
    return (h % 12 || 12) + (m ? ':' + pad(m) : '') + (h < 12 ? 'am' : 'pm');
  }
  const fmtDay = (d, withYear) => `${DOW[d.getDay()]}, ${d.getDate()} ${MONTHS[d.getMonth()]}${withYear ? ' ' + d.getFullYear() : ''}`;
  const fmtShort = (d) => `${dow3(d)} ${d.getDate()} ${mon3(d)}`;
  function whenText(e) {
    const s = e.start, en = e.end;
    const yr = s.getFullYear() !== nowCo().getFullYear();
    if (e.allDay) {
      if (sameDay(s, en)) return fmtDay(s, yr);
      return `${fmtShort(s)} – ${fmtShort(en)}${yr ? ' ' + en.getFullYear() : ''}`;
    }
    if (sameDay(s, en)) return `${fmtDay(s, yr)} · ${fmtTime(s)} – ${fmtTime(en)}`;
    return `${fmtShort(s)}, ${fmtTime(s)} – ${fmtShort(en)}, ${fmtTime(en)}`;
  }
  const NTH = ['', 'first', 'second', 'third', 'fourth', 'fifth'];
  const RR_DAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

  // ===========================================================================
  // State
  // ===========================================================================
  const S = {
    settings: C.settings,
    sources: C.sources,
    view: 'week',
    anchor: day0(nowCo()),
    events: [],
    items: [],
    loadedKey: '',
    reqSeq: 0,
    hidden: new Set(),
    selKey: null,
    detail: null,
    search: null,
    agendaDays: 60,
    alldayOpen: false,
    focusKey: null,
    scrolledOnce: false,
  };
  S.hidden = new Set((S.settings.hidden_calendars || []).map(String));

  const el = {
    view: $('#view'), title: $('#viewTitle'), eyebrow: $('#viewEyebrow'), seg: $('#viewSeg'), progress: $('#progress'),
    mini: $('#miniMonth'), navMine: $('#navMine'), navShared: $('#navShared'), navSources: $('#navSources'),
    sharedHead: $('#sharedHead'), sourcesHead: $('#sourcesHead'), sideFoot: $('#sideFoot'), detail: $('#detailPane'),
    search: $('#searchInput'), searchClear: $('#searchClear'),
  };
  const sourceMeta = (id) => S.sources.find((s) => s.id === id) || { label: id, color: '#85766b', icon: 'calendar' };
  const sourceOn = (id) => S.settings.sources[id] !== false;
  const effView = () => (S.view === 'week' && narrow() ? 'days3' : S.view);

  // ===========================================================================
  // Entries: events and live items, normalised to local Date ranges
  // ===========================================================================
  function entryFromEvent(ev) {
    const start = parseDT(ev.start);
    const end = ev.all_day ? endOfDay(parseDT(ev.end)) : parseDT(ev.end);
    return { kind: 'event', key: ev.key, ev, title: ev.title, start, end: end < start ? start : end, allDay: !!ev.all_day, color: ev.color || '#7a1f30', editable: !!ev.editable };
  }
  function entryFromItem(it) {
    const start = parseDT(it.start);
    return { kind: 'item', key: it.key, it, title: it.title, start, end: endOfDay(parseDT(it.end || it.start)), allDay: true, color: it.color, editable: false };
  }
  function entries() {
    const out = [];
    for (const ev of S.events) {
      if (ev.via_invite ? S.hidden.has('inv') : S.hidden.has(String(ev.calendar_id))) continue;
      out.push(entryFromEvent(ev));
    }
    for (const it of S.items) if (sourceOn(it.source)) out.push(entryFromItem(it));
    return out;
  }
  const entryByKey = (key) => entries().find((e) => e.key === key) || null;
  const isBar = (e) => e.allDay || (e.end - e.start) >= 86400000 - 1000;
  function evClasses(e, extra = []) {
    const c = ['fwc-ev', ...extra];
    if (e.end < nowCo()) c.push('is-past');
    if (e.kind === 'item') {
      c.push('is-src');
      if (e.it.status === 'overdue') c.push('is-overdue');
      if (e.it.status === 'done') c.push('is-done');
    } else {
      if (e.ev.busy) c.push('is-busy');
      if (e.ev.invited && e.ev.response === 'pending') c.push('is-pending');
      if (e.ev.invited && e.ev.response === 'tentative') c.push('is-tentative');
    }
    if (S.selKey === e.key) c.push('is-selected');
    if (S.focusKey === e.key) c.push('is-focus');
    return c.join(' ');
  }
  function evIcons(e) {
    if (e.kind !== 'event' || e.ev.busy) return '';
    const i = [];
    if (e.ev.recurring) i.push(icon('repeat'));
    if (e.ev.visibility === 'private') i.push(icon('lock'));
    if (e.ev.attendees > 1) i.push(icon('users'));
    return i.length ? `<span class="fwc-ev__icons">${i.join('')}</span>` : '';
  }
  const evLabel = (e) => `${e.title}, ${whenText(e)}${e.kind === 'event' && e.ev.location ? ', ' + e.ev.location : ''}`;

  // ===========================================================================
  // Ranges and loading
  // ===========================================================================
  function viewDays() {
    const v = effView();
    if (v === 'day') return [S.anchor];
    if (v === 'days3') return [0, 1, 2].map((i) => addDays(S.anchor, i));
    const s = startOfWeek(S.anchor);
    const days = [0, 1, 2, 3, 4, 5, 6].map((i) => addDays(s, i));
    return S.settings.show_weekends ? days : days.filter((d) => !isWeekend(d));
  }
  function monthGrid() {
    const first = new Date(S.anchor.getFullYear(), S.anchor.getMonth(), 1);
    const start = startOfWeek(first);
    const last = new Date(S.anchor.getFullYear(), S.anchor.getMonth() + 1, 0);
    const weeks = Math.ceil((daysBetween(start, last) + 1) / 7);
    return { start, weeks, end: addDays(start, weeks * 7 - 1) };
  }
  function range() {
    if (S.search) return null;
    const v = effView();
    if (v === 'month') { const g = monthGrid(); return [g.start, g.end]; }
    if (v === 'year') return [new Date(S.anchor.getFullYear(), 0, 1), new Date(S.anchor.getFullYear(), 11, 31)];
    if (v === 'agenda') return [S.anchor, addDays(S.anchor, S.agendaDays - 1)];
    const d = viewDays();
    const all = v === 'week' ? [startOfWeek(S.anchor), addDays(startOfWeek(S.anchor), 6)] : [d[0], d[d.length - 1]];
    return all;
  }
  async function load(opts = {}) {
    const r = range();
    if (!r) return;
    const key = ymd(r[0]) + '|' + ymd(r[1]);
    if (key === S.loadedKey && !opts.force) { render(opts); return; }
    const seq = ++S.reqSeq;
    el.progress.classList.add('is-on');
    if (S.loadedKey) el.view.classList.add('is-loading');
    try {
      const data = await api.get('feed.php', { from: ymd(r[0]), to: ymd(r[1]) });
      if (seq !== S.reqSeq) return;
      S.events = data.events || [];
      S.items = data.items || [];
      S.loadedKey = key;
      render(opts);
    } catch (e) {
      if (seq === S.reqSeq) toastError(e);
    } finally {
      if (seq === S.reqSeq) { el.progress.classList.remove('is-on'); el.view.classList.remove('is-loading'); }
    }
  }
  const reload = () => load({ force: true, keepScroll: true });

  // ===========================================================================
  // Rendering
  // ===========================================================================
  function render(opts = {}) {
    renderHead();
    renderMini();
    renderNav();
    const scrollTop = el.view.scrollTop;
    const v = S.search ? 'search' : effView();
    el.view.dataset.view = v;
    if (v === 'search') renderSearch();
    else if (v === 'month') renderMonth();
    else if (v === 'year') renderYear();
    else if (v === 'agenda') renderAgenda();
    else renderTimeGrid(viewDays());
    if (opts.dir) {
      el.view.classList.remove('is-entering', 'from-left', 'from-right');
      void el.view.offsetWidth;
      el.view.classList.add('is-entering', opts.dir < 0 ? 'from-left' : 'from-right');
    }
    if (opts.keepScroll) el.view.scrollTop = scrollTop;
    else if (v === 'day' || v === 'week' || v === 'days3') scrollToWork();
    else el.view.scrollTop = 0;
    syncUrl();
    if (S.focusKey) {
      const f = $(`.fwc-ev[data-key="${CSS.escape(S.focusKey)}"], .fwc-row[data-key="${CSS.escape(S.focusKey)}"]`, el.view);
      if (f) setTimeout(() => f.scrollIntoView({ block: 'center', behavior: 'smooth' }), 60);
    }
  }

  function renderHead() {
    const a = S.anchor;
    const v = S.search ? 'search' : effView();
    $$('button', el.seg).forEach((b) => b.classList.toggle('is-on', !S.search && b.dataset.view === S.view));
    const yr = (d) => `<em>${d.getFullYear()}</em>`;
    let title = '', eyebrow = '';
    if (v === 'search') {
      title = 'Search';
      eyebrow = S.search.results ? `${S.search.results.length} result${S.search.results.length === 1 ? '' : 's'} for “${esc(S.search.q)}”` : 'Searching…';
    } else if (v === 'day') {
      title = `${a.getDate()} ${MONTHS[a.getMonth()]} ${yr(a)}`;
      eyebrow = `${DOW[a.getDay()]} · Week ${isoWeek(a)}`;
    } else if (v === 'week' || v === 'days3') {
      const d = viewDays();
      const s = d[0], e = d[d.length - 1];
      const mName = (d) => (narrow() ? mon3(d) : MONTHS[d.getMonth()]);
      title = s.getMonth() === e.getMonth() ? `${s.getDate()} – ${e.getDate()} ${mName(s)} ${yr(e)}`
        : `${s.getDate()} ${mon3(s)} – ${e.getDate()} ${mon3(e)} ${yr(e)}`;
      eyebrow = `Week ${isoWeek(s)}${v === 'days3' ? ' · 3 days' : ''}`;
    } else if (v === 'month') {
      title = `${MONTHS[a.getMonth()]} ${yr(a)}`;
      const n = S.events.filter((e) => { const d = parseDT(e.start); return d.getMonth() === a.getMonth() && d.getFullYear() === a.getFullYear(); }).length;
      eyebrow = n ? `${n} event${n === 1 ? '' : 's'}` : 'Month';
    } else if (v === 'year') {
      title = yr(a);
      eyebrow = 'The year at a glance';
    } else {
      title = 'Schedule';
      eyebrow = `From ${a.getDate()} ${MONTHS[a.getMonth()]}${a.getFullYear() !== nowCo().getFullYear() ? ' ' + a.getFullYear() : ''}`;
    }
    el.title.innerHTML = title;
    el.eyebrow.innerHTML = eyebrow;
    document.title = (v === 'search' ? 'Search' : el.title.textContent) + ' · Calendar';
  }

  // ---------------------------------------------------------------- mini month
  let miniMonth = null;
  function renderMini() {
    if (!el.mini) return;
    if (!miniMonth || S._miniFollow !== ymd(S.anchor)) { miniMonth = new Date(S.anchor.getFullYear(), S.anchor.getMonth(), 1); S._miniFollow = ymd(S.anchor); }
    const first = miniMonth;
    const start = startOfWeek(first);
    const today = nowCo();
    const r = range();
    const busy = new Set();
    for (const e of entries()) {
      for (let d = day0(e.start), i = 0; d <= e.end && i < 62; d = addDays(d, 1), i++) busy.add(ymd(d));
    }
    let html = `<div class="fwc-mini__head"><button class="fwc-mini__title" type="button" data-mini="pick">${MONTHS[first.getMonth()]} <em>${first.getFullYear()}</em></button>
      <button class="icon-btn icon-btn--sm" type="button" data-mini="prev" aria-label="Previous month">${icon('chev-left', 'i--sm')}</button>
      <button class="icon-btn icon-btn--sm" type="button" data-mini="next" aria-label="Next month">${icon('chev-right', 'i--sm')}</button></div><div class="fwc-mini__grid">`;
    for (let i = 0; i < 7; i++) html += `<div class="fwc-mini__dow">${DOW[(S.settings.week_start + i) % 7][0]}</div>`;
    for (let i = 0; i < 42; i++) {
      const d = addDays(start, i);
      const k = ymd(d);
      const inRange = r && !S.search && d >= day0(r[0]) && d <= day0(r[1]) && ['day', 'week', 'days3'].includes(effView());
      const cls = ['fwc-mini__day', d.getMonth() !== first.getMonth() ? 'is-other' : '', sameDay(d, today) ? 'is-today' : '',
        sameDay(d, S.anchor) ? 'is-sel' : '', busy.has(k) ? 'is-busy' : '',
        inRange ? 'is-range' : '', inRange && sameDay(d, day0(r[0])) ? 'is-range-start' : '', inRange && sameDay(d, day0(r[1])) ? 'is-range-end' : ''];
      html += `<button type="button" class="${cls.filter(Boolean).join(' ')}" data-date="${k}" aria-label="${esc(fmtDay(d, true))}"><span>${d.getDate()}</span></button>`;
    }
    el.mini.innerHTML = html + '</div>';
  }
  if (el.mini) el.mini.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.mini === 'prev' || b.dataset.mini === 'next') {
      // Browse months without moving the selected date.
      miniMonth = new Date(miniMonth.getFullYear(), miniMonth.getMonth() + (b.dataset.mini === 'next' ? 1 : -1), 1);
      S._miniFollow = ymd(S.anchor);
      return renderMini();
    }
    if (b.dataset.mini === 'pick') return goToDate(b);
    if (b.dataset.date) {
      clearSearch(false);
      S.anchor = parseDT(b.dataset.date);
      if (S.view === 'year' || S.view === 'agenda') S.view = 'day';
      if (narrow()) toggleDrawer(false);
      load();
    }
  });

  // ---------------------------------------------------------------- sidebar
  function calItem(c) {
    const off = S.hidden.has(String(c.id));
    const sub = c.type === 'team' ? 'Shared with the company' : c.type === 'project' ? 'Project calendar' : (c.mine ? 'Only you' : (c.owner ? c.owner + "'s calendar" : ''));
    return `<div class="fwm-nav__item fwc-cal ${off ? 'is-off' : ''}" style="--c:${esc(c.color)}" data-cal="${c.id}" role="checkbox" aria-checked="${off ? 'false' : 'true'}" tabindex="0" title="${esc(sub)}">
      <span class="fwc-swatch-check">${icon('check')}</span><span class="fwm-nav__label">${esc(c.name)}</span>
      <button class="icon-btn icon-btn--sm fwc-cal__more" type="button" data-calmenu="${c.id}" aria-label="Options for ${esc(c.name)}">${icon('more-h', 'i--sm')}</button></div>`;
  }
  function renderNav() {
    const mine = C.calendars.filter((c) => c.type === 'personal' && (c.mine || !c.owner));
    const shared = C.calendars.filter((c) => !mine.includes(c));
    const hasInvites = S.events.some((e) => e.via_invite) || S.hidden.has('inv');
    el.navMine.innerHTML = mine.map(calItem).join('') + (hasInvites ? `<div class="fwm-nav__item fwc-cal ${S.hidden.has('inv') ? 'is-off' : ''}" style="--c:var(--gold)" data-cal="inv" role="checkbox" aria-checked="${S.hidden.has('inv') ? 'false' : 'true'}" tabindex="0" title="Events you were invited to on calendars you do not follow">
      <span class="fwc-swatch-check">${icon('check')}</span><span class="fwm-nav__label">Invitations</span></div>` : '');
    el.navShared.innerHTML = shared.map(calItem).join('');
    el.sharedHead.hidden = !shared.length;
    const srcs = S.sources.filter((s) => s.available);
    el.navSources.innerHTML = srcs.map((s) => {
      const off = !sourceOn(s.id);
      const n = S.items.filter((i) => i.source === s.id).length;
      return `<div class="fwm-nav__item fwc-cal fwc-src ${off ? 'is-off' : ''}" style="--c:${esc(s.color)}" data-src="${s.id}" role="checkbox" aria-checked="${off ? 'false' : 'true'}" tabindex="0">
        <span class="fwc-swatch-check">${icon('check')}</span><span class="fwm-nav__label">${esc(s.label)}</span>
        ${n && !off ? `<span class="fwm-nav__count">${n}</span>` : `<span class="fwc-src-icon">${icon(s.icon, 'i--sm')}</span>`}</div>`;
    }).join('');
    el.sourcesHead.hidden = !srcs.length;
    el.sideFoot.innerHTML = `<div class="fwc-side__links">
        <button type="button" data-side="import">${icon('upload')}Import .ics</button>
        <a href="/calendar/settings.php">${icon('settings')}Settings</a></div>
      <div class="fwc-tz" title="Event times are in the company's time zone">${icon('globe')}${esc(S.settings.timezone || '')}</div>`;
  }
  const savePrefs = debounce(async (patch) => {
    try {
      const d = await api.post('settings.php', patch);
      Object.assign(C.settings, d.settings);
    } catch (e) { toastError(e); }
  }, 600);
  function toggleCalendar(key) {
    if (S.hidden.has(key)) S.hidden.delete(key); else S.hidden.add(key);
    savePrefs({ hidden_calendars: Array.from(S.hidden) });
    render({ keepScroll: true });
  }
  function toggleSource(id) {
    S.settings.sources[id] = !sourceOn(id);
    savePrefs({ sources: { [id]: S.settings.sources[id] } });
    render({ keepScroll: true });
  }
  $('#side').addEventListener('click', (e) => {
    const more = e.target.closest('[data-calmenu]');
    if (more) { e.stopPropagation(); return calendarMenu(more, calById(more.dataset.calmenu)); }
    const cal = e.target.closest('[data-cal]');
    if (cal) return toggleCalendar(cal.dataset.cal);
    const src = e.target.closest('[data-src]');
    if (src) return toggleSource(src.dataset.src);
    const side = e.target.closest('[data-side]');
    if (side && side.dataset.side === 'import') return pickImport(defaultCalId());
  });
  $('#side').addEventListener('keydown', (e) => {
    if ((e.key === ' ' || e.key === 'Enter') && e.target.matches('[data-cal], [data-src]')) { e.preventDefault(); e.target.click(); }
  });

  // ---------------------------------------------------------------- time grid
  function hourH() { return parseFloat(getComputedStyle(el.view).getPropertyValue('--hour-h')) || 52; }
  const minsOf = (d) => d.getHours() * 60 + d.getMinutes();
  /**
   * Overlapping events in a day share columns; each one then widens into free
   * columns to its right and overlaps its neighbour a little, so titles stay
   * readable (the later event sits on top).
   */
  function layoutDay(segs) {
    segs.sort((a, b) => a.s - b.s || (b.e - b.s) - (a.e - a.s));
    let cluster = [], cols = [], end = -1;
    const flush = () => {
      const n = cols.length;
      for (const g of cluster) {
        let span = 1;
        for (let c = g.col + 1; c < n; c++) {
          if (cluster.some((o) => o.col === c && o.s < g.e && o.e > g.s)) break;
          span++;
        }
        g.left = g.col / n;
        g.width = g.col + span >= n ? span / n : Math.min((span + 0.7) / n, 1 - g.left);
      }
      cluster = []; cols = [];
    };
    for (const g of segs) {
      if (g.s >= end && cluster.length) flush();
      let c = cols.findIndex((ce) => ce <= g.s);
      if (c < 0) { c = cols.length; cols.push(g.e); } else cols[c] = g.e;
      g.col = c;
      cluster.push(g);
      end = Math.max(end, g.e);
    }
    flush();
    return segs;
  }
  function renderTimeGrid(days) {
    const n = days.length;
    const today = nowCo();
    const hh = hourH();
    const list = entries();
    const [ws, we] = [S.settings.work_start, S.settings.work_end].map((t) => { const [h, m] = t.split(':'); return +h * 60 + +m; });
    const tz = (() => { try { return new Intl.DateTimeFormat('en', { timeZone: S.settings.timezone, timeZoneName: 'short' }).formatToParts(new Date()).find((p) => p.type === 'timeZoneName').value; } catch (e) { return ''; } })();

    let head = `<div class="fwc-tg__tz">${esc(tz)}</div>`;
    for (const d of days) {
      head += `<button type="button" class="fwc-dayhead ${sameDay(d, today) ? 'is-today' : ''} ${isWeekend(d) ? 'is-weekend' : ''}" data-goto="${ymd(d)}" aria-label="${esc(fmtDay(d, true))}">
        <span class="fwc-dayhead__dow">${dow3(d)}</span><span class="fwc-dayhead__num">${d.getDate()}</span></button>`;
    }

    // All-day lane
    const bars = [];
    const dayKeys = days.map(ymd);
    for (const e of list) {
      if (!isBar(e)) continue;
      const cols = [];
      dayKeys.forEach((k, i) => { const d = parseDT(k); if (d >= day0(e.start) && d <= e.end) cols.push(i); });
      if (!cols.length) continue;
      bars.push({ e, a: cols[0], b: cols[cols.length - 1], contL: day0(e.start) < parseDT(dayKeys[cols[0]]), contR: day0(e.end) > parseDT(dayKeys[cols[cols.length - 1]]) });
    }
    bars.sort((x, y) => x.a - y.a || (y.b - y.a) - (x.b - x.a) || (x.e.kind === 'item') - (y.e.kind === 'item'));
    const lanes = [];
    for (const b of bars) {
      let l = lanes.findIndex((end) => end < b.a);
      if (l < 0) { l = lanes.length; lanes.push(b.b); } else lanes[l] = b.b;
      b.lane = l;
    }
    const maxLanes = S.alldayOpen ? 99 : 3;
    const shownLanes = Math.min(lanes.length, lanes.length > maxLanes ? maxLanes - 1 : maxLanes);
    const rowH = 24;
    let alldayHtml = '';
    const hiddenPer = new Array(n).fill(0);
    for (const b of bars) {
      if (b.lane >= shownLanes) { for (let i = b.a; i <= b.b; i++) hiddenPer[i]++; continue; }
      const e = b.e;
      alldayHtml += `<div class="${evClasses(e, [b.contL ? 'is-cont-l' : '', b.contR ? 'is-cont-r' : ''])}" style="--c:${esc(e.color)};left:calc(${b.a} * 100% / ${n} + 2px);width:calc(${b.b - b.a + 1} * 100% / ${n} - 5px);top:${b.lane * rowH + 3}px"
        data-key="${esc(e.key)}" role="button" tabindex="0" aria-label="${esc(evLabel(e))}">${e.kind === 'item' ? icon(e.it.icon || 'calendar') : ''}<span class="fwc-ev__title">${esc(e.title)}</span>${evIcons(e)}</div>`;
    }
    hiddenPer.forEach((c, i) => {
      if (c) alldayHtml += `<button type="button" class="fwc-allday__more" style="left:calc(${i} * 100% / ${n} + 2px);top:${shownLanes * rowH + 4}px" data-allday-more>+${c} more</button>`;
    });
    if (S.alldayOpen && lanes.length > 3) alldayHtml += `<button type="button" class="fwc-allday__more" style="right:6px;top:${lanes.length * rowH + 4}px" data-allday-less>Show less</button>`;
    const alldayH = Math.max(30, (hiddenPer.some(Boolean) || (S.alldayOpen && lanes.length > 3) ? shownLanes + 1 : shownLanes) * rowH + 8);

    // Hours + columns
    let hours = '';
    for (let h = 0; h < 24; h++) hours += `<div class="fwc-hour"><span>${fmtTime(new Date(2000, 0, 1, h))}</span></div>`;
    let cols = '';
    days.forEach((d, i) => {
      const k = dayKeys[i];
      const dStart = day0(d), dEnd = addDays(dStart, 1);
      const segs = [];
      for (const e of list) {
        if (isBar(e) || e.end <= dStart || e.start >= dEnd) continue;
        const s = e.start < dStart ? 0 : minsOf(e.start);
        const en = e.end >= dEnd ? 1440 : Math.max(minsOf(e.end), s + 1);
        segs.push({ ent: e, s, e: en, cont: e.end > dEnd });
      }
      layoutDay(segs);
      let evHtml = '';
      for (const g of segs) {
        const e = g.ent;
        const top = g.s / 60 * hh;
        const h = Math.max((g.e - g.s) / 60 * hh, 18);
        const short = h < 34;
        const time = `${fmtTime(e.start)} – ${fmtTime(e.end)}`;
        const loc = e.kind === 'event' && e.ev.location ? `<span class="fwc-ev__meta">${esc(e.ev.location)}</span>` : '';
        evHtml += `<div class="${evClasses(e, [short ? 'is-short' : '', g.col > 0 ? 'is-stacked' : ''])}" style="--c:${esc(e.color)};--top:${top.toFixed(1)}px;--h:${(h - 2).toFixed(1)}px;--l:${g.left.toFixed(4)};--w:${g.width.toFixed(4)};z-index:${1 + g.col}"
          data-key="${esc(e.key)}" data-day="${k}" role="button" tabindex="0" aria-label="${esc(evLabel(e))}">
          <span class="fwc-ev__title">${esc(e.title)}${short ? '' : evIcons(e)}</span><span class="fwc-ev__time">${short ? fmtTime(e.start) : time}</span>${short ? '' : loc}
          ${e.editable && !g.cont ? '<span class="fwc-ev__resize" data-resize></span>' : ''}</div>`;
      }
      const off = [];
      if (ws > 0) off.push(`<div class="fwc-col__off" style="top:0;height:${ws / 60 * hh}px"></div>`);
      if (we < 1440) off.push(`<div class="fwc-col__off" style="top:${we / 60 * hh}px;bottom:0"></div>`);
      const isToday = sameDay(d, today);
      const now = isToday ? `<div class="fwc-now" style="top:${(minsOf(today) / 60 * hh).toFixed(1)}px"></div>` : '';
      cols += `<div class="fwc-col ${isWeekend(d) ? 'is-weekend' : ''} ${isToday ? 'is-today' : ''}" data-date="${k}">${isWeekend(d) ? '' : off.join('')}${now}<div class="fwc-col__events">${evHtml}</div></div>`;
    });

    el.view.innerHTML = `<div class="fwc-tg ${n === 1 ? 'fwc-tg--one' : ''}" style="--days:${n}">
      <div class="fwc-tg__head">${head}</div>
      <div class="fwc-allday" style="top:var(--tg-head-h, 57px)"><div class="fwc-allday__label">All day</div>
        <div class="fwc-allday__lanes" style="height:${alldayH}px" data-allday><div class="fwc-allday__cols">${days.map((d) => `<div data-date="${ymd(d)}"></div>`).join('')}</div>${alldayHtml}</div></div>
      <div class="fwc-tg__body"><div class="fwc-hours">${hours}</div><div class="fwc-cols" data-cols>${cols}</div></div></div>`;
    const headEl = $('.fwc-tg__head', el.view);
    if (headEl) $('.fwc-tg', el.view).style.setProperty('--tg-head-h', headEl.offsetHeight + 'px');
  }
  function scrollToWork() {
    const hh = hourH();
    const days = viewDays();
    const now = nowCo();
    let mins = (+S.settings.work_start.split(':')[0]) * 60 - 30;
    if (days.some((d) => sameDay(d, now))) mins = Math.max(0, Math.min(minsOf(now) - 120, mins + 240));
    el.view.scrollTop = Math.max(0, mins / 60 * hh);
  }
  function tickNow() {
    const now = nowCo();
    const hh = hourH();
    $$('.fwc-now', el.view).forEach((n) => { n.style.top = (minsOf(now) / 60 * hh).toFixed(1) + 'px'; });
    if (!$('.fwc-col.is-today', el.view) && $$('.fwc-col', el.view).some((c) => c.dataset.date === ymd(now))) render({ keepScroll: true });
  }
  setInterval(tickNow, 60000);

  // ---------------------------------------------------------------- month
  function renderMonth() {
    const g = monthGrid();
    const today = nowCo();
    const showWe = S.settings.show_weekends;
    const allDays = [];
    for (let i = 0; i < g.weeks * 7; i++) allDays.push(addDays(g.start, i));
    const colDays = (w) => allDays.slice(w * 7, w * 7 + 7).filter((d) => showWe || !isWeekend(d));
    const ncols = showWe ? 7 : 5;
    const list = entries();
    const viewH = el.view.clientHeight || 600;
    const rowH = Math.max(narrow() ? 78 : 96, (viewH - 36) / g.weeks);
    const laneH = narrow() ? 21 : 23;
    const maxLanes = Math.max(1, Math.floor((rowH - (narrow() ? 31 : 36)) / laneH));

    let dow = '';
    colDays(0).forEach((d) => { dow += `<div>${narrow() ? DOW[d.getDay()][0] : dow3(d)}</div>`; });
    let weeksHtml = '';
    for (let w = 0; w < g.weeks; w++) {
      const days = colDays(w);
      const keys = days.map(ymd);
      const segs = [];
      for (const e of list) {
        const cols = [];
        days.forEach((d, i) => { if (d >= day0(e.start) && d <= e.end) cols.push(i); });
        if (!cols.length) continue;
        segs.push({ e, a: cols[0], b: cols[cols.length - 1], bar: isBar(e) || cols.length > 1,
          contL: day0(e.start) < days[cols[0]], contR: day0(e.end) > days[cols[cols.length - 1]] });
      }
      segs.sort((x, y) => (y.bar - x.bar) || x.a - y.a || (y.b - y.a) - (x.b - x.a) || (x.e.kind === 'item') - (y.e.kind === 'item') || x.e.start - y.e.start);
      const lanesAt = days.map(() => []);
      for (const s of segs) {
        let l = 0;
        while (true) { let free = true; for (let i = s.a; i <= s.b; i++) if (lanesAt[i][l]) { free = false; break; } if (free) break; l++; }
        s.lane = l;
        for (let i = s.a; i <= s.b; i++) lanesAt[i][l] = true;
      }
      const count = days.map((_, i) => segs.filter((s) => s.a <= i && s.b >= i).length);
      const limit = count.map((c) => (c > maxLanes ? maxLanes - 1 : maxLanes));
      let lanesHtml = '';
      const hidden = days.map(() => 0);
      for (const s of segs) {
        let lim = Infinity;
        for (let i = s.a; i <= s.b; i++) lim = Math.min(lim, limit[i]);
        if (s.lane >= lim) { for (let i = s.a; i <= s.b; i++) hidden[i]++; continue; }
        const e = s.e;
        const dot = !s.bar && !e.allDay && e.kind === 'event';
        const cls = evClasses(e, [dot ? 'is-dot' : '', s.contL ? 'is-cont-l' : '', s.contR ? 'is-cont-r' : '']);
        lanesHtml += `<div class="${cls}" style="--c:${esc(e.color)};--x:${s.a};--span:${s.b - s.a + 1};--lane:${s.lane}" data-key="${esc(e.key)}" data-day="${keys[s.a]}" role="button" tabindex="0" aria-label="${esc(evLabel(e))}">
          ${e.kind === 'item' ? icon(e.it.icon || 'calendar') : ''}${dot ? `<span class="fwc-ev__time">${fmtTime(e.start)}</span>` : ''}<span class="fwc-ev__title">${esc(e.title)}</span></div>`;
      }
      hidden.forEach((h, i) => { if (h) lanesHtml += `<button type="button" class="fwc-mo__more" style="--x:${i};--lane:${limit[i]}" data-more="${keys[i]}">+${h} more</button>`; });
      let cells = '';
      days.forEach((d) => {
        const cls = ['fwc-mo__cell', d.getMonth() !== S.anchor.getMonth() ? 'is-other' : '', sameDay(d, today) ? 'is-today' : '', isWeekend(d) ? 'is-weekend' : ''];
        cells += `<div class="${cls.filter(Boolean).join(' ')}" data-date="${ymd(d)}"><button type="button" class="fwc-mo__num" data-goto="${ymd(d)}" aria-label="${esc(fmtDay(d, true))}">${d.getDate()}</button>${d.getDate() === 1 ? `<span class="fwc-mo__first">${mon3(d)}</span>` : ''}</div>`;
      });
      weeksHtml += `<div class="fwc-mo__week">${cells}<div class="fwc-mo__lanes">${lanesHtml}</div></div>`;
    }
    el.view.innerHTML = `<div class="fwc-mo" style="--weeks:${g.weeks};--cols:${ncols}"><div class="fwc-mo__dow">${dow}</div><div class="fwc-mo__weeks">${weeksHtml}</div></div>`;
  }
  function dayPopover(anchor, dateKey) {
    const d = parseDT(dateKey);
    const list = entries().filter((e) => d >= day0(e.start) && d <= e.end).sort((a, b) => (b.allDay - a.allDay) || a.start - b.start);
    popover(anchor, `<h4>${d.getDate()} <small>${DOW[d.getDay()]}</small></h4>${list.map((e) => `<div class="${evClasses(e, [!e.allDay && e.kind === 'event' ? 'is-dot' : ''])}" style="--c:${esc(e.color)}" data-key="${esc(e.key)}" data-v="ev" role="button" tabindex="0">
        ${e.kind === 'item' ? icon(e.it.icon || 'calendar') : ''}${!e.allDay ? `<span class="fwc-ev__time">${fmtTime(e.start)}</span> ` : ''}<span class="fwc-ev__title" style="display:inline">${esc(e.title)}</span></div>`).join('')}`,
      { cls: 'fwc-daypop', role: 'dialog', onClick: (it) => { openEntry(it.dataset.key); } });
  }

  // ---------------------------------------------------------------- year
  function renderYear() {
    const y = S.anchor.getFullYear();
    const today = nowCo();
    const counts = {};
    for (const e of entries()) {
      for (let d = day0(e.start), i = 0; d <= e.end && i < 400; d = addDays(d, 1), i++) { const k = ymd(d); counts[k] = (counts[k] || 0) + 1; }
    }
    let html = '';
    for (let m = 0; m < 12; m++) {
      const first = new Date(y, m, 1);
      const start = startOfWeek(first);
      const total = Object.keys(counts).filter((k) => k.startsWith(`${y}-${pad(m + 1)}`)).reduce((s, k) => s + counts[k], 0);
      let cells = '';
      for (let i = 0; i < 7; i++) cells += `<div class="fwc-mini__dow">${DOW[(S.settings.week_start + i) % 7][0]}</div>`;
      for (let i = 0; i < 42; i++) {
        const d = addDays(start, i);
        if (i >= 35 && d.getMonth() !== m) break;
        const inMonth = d.getMonth() === m;
        const c = inMonth ? counts[ymd(d)] || 0 : 0;
        const heat = c >= 5 ? 3 : c >= 3 ? 2 : c >= 1 ? 1 : 0;
        cells += inMonth ? `<button type="button" class="fwc-mini__day ${sameDay(d, today) ? 'is-today' : ''} ${heat ? 'heat-' + heat : ''}" data-goto="${ymd(d)}" title="${c ? c + ' item' + (c === 1 ? '' : 's') : ''}"><span>${d.getDate()}</span></button>` : '<span></span>';
      }
      html += `<section class="fwc-yr__m ${today.getFullYear() === y && today.getMonth() === m ? 'is-current' : ''}"><h3><button type="button" data-month="${m}">${MONTHS[m]}</button>${total ? `<small>${total}</small>` : ''}</h3><div class="fwc-mini__grid">${cells}</div></section>`;
    }
    el.view.innerHTML = `<div class="fwc-yr">${html}</div>`;
  }

  // ---------------------------------------------------------------- schedule / search
  const CAL_ART = `<svg class="fwm-art fwc-art" viewBox="0 0 190 132" aria-hidden="true">
    <ellipse class="a-shadow" cx="95" cy="122" rx="62" ry="6"/>
    <g class="a-float"><rect class="a-paper" x="46" y="22" width="98" height="92" rx="12"/><path class="a-top" d="M46 34a12 12 0 0 1 12-12h74a12 12 0 0 1 12 12v8H46z"/>
    <rect class="a-gold" x="66" y="14" width="5" height="16" rx="2.5"/><rect class="a-gold" x="119" y="14" width="5" height="16" rx="2.5"/>
    <path class="a-grid" d="M58 58h74M58 74h74M58 90h74M80 46v58M102 46v58M124 46v58"/>
    <rect class="a-mark" x="104" y="76" width="18" height="12" rx="3"/>
    <circle class="a-seal" cx="138" cy="102" r="13"/><circle class="a-seal-ring" cx="138" cy="102" r="9.5"/><circle class="a-seal-hi" cx="134" cy="98" r="4"/></g>
    <path class="a-spark" d="M30 40l2 5 5 2-5 2-2 5-2-5-5-2 5-2z"/><path class="a-spark" d="M162 28l1.5 3.5 3.5 1.5-3.5 1.5-1.5 3.5-1.5-3.5-3.5-1.5 3.5-1.5z"/></svg>`;
  function emptyState(title, text, actions = '') {
    return `<div class="fwm-empty fwc-empty"><div class="fwm-empty__art fwm-empty__art--letter">${CAL_ART}</div><h3>${title}</h3><p>${text}</p>${actions}</div>`;
  }
  function rowHtml(e, day) {
    let time;
    if (e.allDay) time = 'All day';
    else if (sameDay(e.start, e.end)) time = `${fmtTime(e.start)} – ${fmtTime(e.end)}`;
    else if (day && sameDay(day, e.start)) time = `${fmtTime(e.start)} →`;
    else if (day && sameDay(day, e.end)) time = `→ ${fmtTime(e.end)}`;
    else time = 'All day';
    if (!day) time = `${fmtShort(e.start)} · ${time}`;
    const meta = [];
    let side = '';
    if (e.kind === 'event') {
      if (e.ev.location) meta.push(`<span>${icon('pin')}${esc(e.ev.location)}</span>`);
      if (e.ev.recurring) meta.push(`<span>${icon('repeat')}Repeats</span>`);
      if (e.ev.calendar_name) side += `<span class="fwm-tag" style="--c:${esc(e.color)}">${esc(e.ev.calendar_name)}</span>`;
      if (e.ev.invited) side += `<span class="fwm-tag fwm-tag--plain">${{ pending: 'Awaiting reply', accepted: 'Going', tentative: 'Maybe', declined: 'Declined' }[e.ev.response] || 'Invited'}</span>`;
    } else {
      if (e.it.sub) meta.push(`<span>${icon(e.it.icon || 'calendar')}${esc(e.it.sub)}</span>`);
      side += `<span class="fwm-tag" style="--c:${esc(e.color)}">${esc(sourceMeta(e.it.source).label)}</span>`;
      if (e.it.status === 'overdue') side += '<span class="fwm-tag fwm-tag--danger">Overdue</span>';
      if (e.it.status === 'done') side += '<span class="fwm-tag fwm-tag--plain">Done</span>';
    }
    const cls = ['fwc-row', e.end < nowCo() ? 'is-past' : '', e.kind === 'item' ? 'is-src' : '', e.kind === 'item' && e.it.status === 'done' ? 'is-done' : '',
      e.kind === 'event' && e.ev.invited && e.ev.response === 'pending' ? 'is-pending' : '', S.selKey === e.key ? 'is-selected' : ''];
    return `<button type="button" class="${cls.filter(Boolean).join(' ')}" style="--c:${esc(e.kind === 'item' && e.it.status === 'overdue' ? 'var(--danger)' : e.color)}" data-key="${esc(e.key)}">
      <span class="fwc-row__time">${esc(time)}</span>
      <span class="fwc-row__main"><span class="fwc-row__title">${esc(e.title)}</span>${meta.length ? `<span class="fwc-row__meta">${meta.join('')}</span>` : ''}</span>
      <span class="fwc-row__side">${side}</span></button>`;
  }
  function renderAgenda() {
    const list = entries();
    const today = nowCo();
    let html = '';
    for (let i = 0; i < S.agendaDays; i++) {
      const d = addDays(S.anchor, i);
      const ds = day0(d), de = endOfDay(d);
      const items = list.filter((e) => e.start <= de && e.end >= ds).sort((a, b) => (b.allDay - a.allDay) || a.start - b.start || (a.kind === 'item') - (b.kind === 'item'));
      if (!items.length) continue;
      html += `<section class="fwc-ag__day ${sameDay(d, today) ? 'is-today' : ''}"><div class="fwc-ag__date" data-goto="${ymd(d)}" role="button" tabindex="0">
        <span class="fwc-ag__num">${d.getDate()}</span><span class="fwc-ag__dow">${dow3(d)}</span><span class="fwc-ag__mon">${mon3(d)}${d.getFullYear() !== today.getFullYear() ? ' ' + d.getFullYear() : ''}</span></div>
        <div class="fwc-ag__list">${items.map((e) => rowHtml(e, d)).join('')}</div></section>`;
    }
    el.view.innerHTML = html
      ? `<div class="fwc-ag">${html}<div class="fwc-ag__more"><button class="btn" type="button" data-agenda-more>${icon('chev-down')}Show the next 60 days</button></div></div>`
      : emptyState('A clear horizon', `Nothing scheduled for the next ${S.agendaDays} days.`, `<button class="btn btn--primary" type="button" data-new>${icon('plus')}New event</button>`);
  }
  function renderSearch() {
    const res = S.search.results;
    if (!res) { el.view.innerHTML = '<div class="fwc-ag"><div class="fwm-list__more">Searching…</div></div>'; return; }
    if (!res.length) { el.view.innerHTML = emptyState('Nothing found', `No events match “${esc(S.search.q)}”. Try a different word, a place or a person's name.`); return; }
    el.view.innerHTML = `<div class="fwc-ag"><div class="fwc-ag__list">${res.map((ev) => rowHtml(entryFromEvent(ev), null)).join('')}</div></div>`;
  }

  // ===========================================================================
  // Interactions in the views
  // ===========================================================================
  el.view.addEventListener('click', (e) => {
    // The click a browser may fire at the end of a drag is not a click.
    if (Date.now() - (S.dragEndedAt || 0) < 350) return;
    const t = e.target;
    const go = t.closest('[data-goto]');
    if (go && !t.closest('.fwc-ev')) { S.anchor = parseDT(go.dataset.goto); S.view = 'day'; clearSearch(false); return load(); }
    const month = t.closest('[data-month]');
    if (month) { S.anchor = new Date(S.anchor.getFullYear(), +month.dataset.month, 1); S.view = 'month'; return load(); }
    const more = t.closest('[data-more]');
    if (more) return dayPopover(more, more.dataset.more);
    if (t.closest('[data-allday-more]')) { S.alldayOpen = true; return render({ keepScroll: true }); }
    if (t.closest('[data-allday-less]')) { S.alldayOpen = false; return render({ keepScroll: true }); }
    if (t.closest('[data-agenda-more]')) { S.agendaDays += 60; return load({ keepScroll: true }); }
    if (t.closest('[data-new]')) return openEditor({ mode: 'create' });
    const ev = t.closest('[data-key]');
    if (ev) return openEntry(ev.dataset.key);
    const cell = t.closest('.fwc-mo__cell');
    if (cell) return quickCreate(cell, { start: parseDT(cell.dataset.date), end: parseDT(cell.dataset.date), allDay: true });
    const lane = t.closest('[data-allday]');
    if (lane) {
      const col = colAt($('.fwc-allday__cols', lane), e.clientX);
      if (col) return quickCreate({ x: e.clientX, y: e.clientY }, { start: parseDT(col.dataset.date), end: parseDT(col.dataset.date), allDay: true });
    }
    // Taps on an empty time slot (touch): a one-hour event at that time.
    const tcol = t.closest('.fwc-col');
    if (tcol && !finePointer()) {
      const start = slotAt(tcol, e.clientY);
      return quickCreate({ x: e.clientX, y: e.clientY }, { start, end: addMinutes(start, S.settings.default_duration), allDay: false });
    }
  });
  el.view.addEventListener('keydown', (e) => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches('[data-key], [data-goto]')) { e.preventDefault(); e.target.click(); }
  });
  el.view.addEventListener('dblclick', (e) => {
    const cell = e.target.closest('.fwc-mo__cell');
    if (cell && !e.target.closest('.fwc-ev')) { closePop(); openEditor({ mode: 'create', prefill: { start: parseDT(cell.dataset.date), end: parseDT(cell.dataset.date), allDay: true } }); }
  });

  function colAt(container, x) {
    const cols = $$(':scope > [data-date]', container);
    return cols.find((c) => { const r = c.getBoundingClientRect(); return x >= r.left && x < r.right; }) || null;
  }
  function slotAt(col, y, snap = 15) {
    const r = col.getBoundingClientRect();
    const mins = Math.max(0, Math.min(1440 - snap, Math.floor(((y - r.top) / hourH() * 60) / snap) * snap));
    const d = parseDT(col.dataset.date);
    d.setMinutes(mins);
    return d;
  }

  // Drag: create on empty slots, move / resize events (mouse and pen).
  el.view.addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || e.pointerType === 'touch' || S.search) return;
    const evEl = e.target.closest('.fwc-ev');
    const v = effView();
    if (evEl) {
      const ent = entryByKey(evEl.dataset.key);
      if (!ent || !ent.editable || ent.kind !== 'event') return;
      if (evEl.closest('.fwc-col')) return dragTimed(e, evEl, ent, e.target.closest('[data-resize]') ? 'resize' : 'move');
      if (evEl.closest('.fwc-mo') || evEl.closest('.fwc-allday')) return dragDays(e, evEl, ent);
      return;
    }
    const col = e.target.closest('.fwc-col');
    if (col && (v === 'day' || v === 'week' || v === 'days3')) dragCreate(e, col);
  });

  function dragCreate(e, col) {
    const startSlot = slotAt(col, e.clientY);
    let endSlot = addMinutes(startSlot, 15);
    let moved = false;
    const sel = document.createElement('div');
    sel.className = 'fwc-sel';
    const hh = hourH();
    const paint = () => {
      const a = startSlot < endSlot ? startSlot : endSlot, b = startSlot < endSlot ? endSlot : addMinutes(startSlot, 15);
      sel.style.top = (minsOf(a) / 60 * hh) + 'px';
      sel.style.height = Math.max(14, ((b - a) / 60000) / 60 * hh - 2) + 'px';
      sel.textContent = `${fmtTime(a)} – ${fmtTime(b)}`;
    };
    const onMove = (ev) => {
      if (!moved && Math.abs(ev.clientY - e.clientY) < 5) return;
      if (!moved) { moved = true; col.appendChild(sel); }
      const s = slotAt(col, ev.clientY);
      endSlot = s >= startSlot ? addMinutes(s, 15) : s;
      paint();
    };
    const onUp = (ev) => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      if (!moved) {
        S.dragEndedAt = Date.now();
        const st = startSlot;
        st.setMinutes(Math.floor(st.getMinutes() / 30) * 30);
        const rangeSel = { start: st, end: addMinutes(st, S.settings.default_duration), allDay: false };
        sel.remove();
        col.appendChild(sel);
        const hh2 = hourH();
        sel.style.top = (minsOf(rangeSel.start) / 60 * hh2) + 'px';
        sel.style.height = (S.settings.default_duration / 60 * hh2 - 2) + 'px';
        sel.textContent = `${fmtTime(rangeSel.start)} – ${fmtTime(rangeSel.end)}`;
        return quickCreate(sel, rangeSel, () => sel.remove());
      }
      S.dragEndedAt = Date.now();
      const a = startSlot < endSlot ? startSlot : endSlot, b = startSlot < endSlot ? endSlot : addMinutes(startSlot, 15);
      quickCreate(sel, { start: a, end: b, allDay: false }, () => sel.remove());
    };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  }

  function dragTimed(e, evEl, ent, mode) {
    e.preventDefault();
    const cols = $('[data-cols]', el.view);
    const colEls = $$('.fwc-col', cols);
    const hh = hourH();
    const origCol = evEl.closest('.fwc-col');
    const grabMin = (e.clientY - origCol.getBoundingClientRect().top) / hh * 60;
    // Minutes from the grabbed column's midnight to the event start (negative if it began the day before).
    const grabOffset = grabMin - (ent.start - parseDT(origCol.dataset.date)) / 60000;
    const dur = (ent.end - ent.start) / 60000;
    let moved = false, ghost = null, newStart = ent.start, newEnd = ent.end;
    const onMove = (ev) => {
      if (!moved && Math.hypot(ev.clientX - e.clientX, ev.clientY - e.clientY) < 5) return;
      if (!moved) {
        moved = true;
        closePop();
        ghost = evEl.cloneNode(true);
        ghost.classList.add('is-ghost');
        ghost.classList.remove('is-selected');
        evEl.classList.add('is-dragging');
      }
      const col = colEls.find((c) => { const r = c.getBoundingClientRect(); return ev.clientX >= r.left && ev.clientX < r.right; }) || evEl.closest('.fwc-col');
      const r = col.getBoundingClientRect();
      const pointerMin = (ev.clientY - r.top) / hh * 60;
      const day = parseDT(col.dataset.date);
      if (mode === 'move') {
        const m = Math.round((pointerMin - grabOffset) / 15) * 15;
        const startOnDay = Math.max(-dur + 15, Math.min(1440 - 15, m));
        newStart = addMinutes(day, startOnDay);
        newEnd = addMinutes(newStart, dur);
      } else {
        const m = Math.max(15, Math.round(pointerMin / 15) * 15);
        const end = addMinutes(day, m);
        newStart = ent.start;
        newEnd = end <= addMinutes(ent.start, 15) ? addMinutes(ent.start, 15) : end;
      }
      const gDayStart = parseDT(col.dataset.date);
      const top = Math.max(0, (newStart - gDayStart) / 60000) / 60 * hh;
      const bottomMin = Math.min(1440, (newEnd - gDayStart) / 60000);
      ghost.style.setProperty('--top', top + 'px');
      ghost.style.setProperty('--h', Math.max(16, (bottomMin / 60 * hh) - top - 2) + 'px');
      ghost.style.setProperty('--l', 0);
      ghost.style.setProperty('--w', 1);
      const t = $('.fwc-ev__time', ghost);
      if (t) t.textContent = `${fmtTime(newStart)} – ${fmtTime(newEnd)}`;
      const host = $('.fwc-col__events', mode === 'move' ? col : origCol);
      if (ghost.parentNode !== host) host.appendChild(ghost);
    };
    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      if (!moved) return;
      S.dragEndedAt = Date.now();
      if (ghost) ghost.remove();
      evEl.classList.remove('is-dragging');
      if (+newStart === +ent.start && +newEnd === +ent.end) return;
      commitMove(ent, newStart, newEnd);
    };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  }

  function dragDays(e, evEl, ent) {
    e.preventDefault();
    const inMonth = !!evEl.closest('.fwc-mo');
    // By geometry: the pointer is often over another event bar, not the cell itself.
    const monthCells = inMonth ? $$('.fwc-mo__cell', el.view) : [];
    const cellAt = (x, y) => {
      if (inMonth) return monthCells.find((c) => { const r = c.getBoundingClientRect(); return x >= r.left && x < r.right && y >= r.top && y < r.bottom; }) || null;
      return colAt($('.fwc-allday__cols', el.view), x);
    };
    const grab = cellAt(e.clientX, e.clientY);
    if (!grab) return;
    let moved = false, ghost = null, over = null;
    const onMove = (ev) => {
      if (!moved && Math.hypot(ev.clientX - e.clientX, ev.clientY - e.clientY) < 5) return;
      if (!moved) {
        moved = true;
        closePop();
        const r = evEl.getBoundingClientRect();
        ghost = evEl.cloneNode(true);
        ghost.classList.add('is-ghost');
        Object.assign(ghost.style, { position: 'fixed', left: r.left + 'px', top: r.top + 'px', width: Math.min(r.width, 220) + 'px', height: r.height + 'px', margin: 0 });
        ghost._dx = e.clientX - r.left; ghost._dy = e.clientY - r.top;
        document.body.appendChild(ghost);
        evEl.classList.add('is-dragging');
      }
      ghost.style.left = (ev.clientX - ghost._dx) + 'px';
      ghost.style.top = (ev.clientY - ghost._dy) + 'px';
      const c = cellAt(ev.clientX, ev.clientY);
      if (c !== over) { if (over) over.classList.remove('is-drop'); over = c; if (over && inMonth) over.classList.add('is-drop'); }
    };
    const onUp = () => {
      document.removeEventListener('pointermove', onMove);
      document.removeEventListener('pointerup', onUp);
      if (!moved) return;
      S.dragEndedAt = Date.now();
      if (ghost) ghost.remove();
      if (over) over.classList.remove('is-drop');
      evEl.classList.remove('is-dragging');
      if (!over) return;
      const delta = daysBetween(parseDT(grab.dataset.date), parseDT(over.dataset.date));
      if (!delta) return;
      commitMove(ent, addDays(ent.start, delta), addDays(ent.end, delta));
    };
    document.addEventListener('pointermove', onMove);
    document.addEventListener('pointerup', onUp);
  }

  async function commitMove(ent, start, end) {
    let scope = 'all';
    if (ent.ev.recurring) {
      scope = await askScope('Move');
      if (!scope) return;
    }
    const before = { start: ent.ev.start, end: ent.ev.end };
    const payload = { action: 'move', id: ent.ev.id, scope, occ: ent.ev.occ || undefined,
      start: ent.allDay ? ymd(start) : fmtDT(start), end: ent.allDay ? ymd(end) : fmtDT(end) };
    // Show it in the new place straight away.
    const local = S.events.find((x) => x.key === ent.key);
    if (local) { local.start = payload.start; local.end = payload.end; render({ keepScroll: true }); }
    try {
      await api.post('event.php', payload);
      toast('Event moved', ent.ev.recurring ? {} : {
        action: { label: 'Undo', run: async () => {
          try { await api.post('event.php', { action: 'move', id: ent.ev.id, start: before.start, end: before.end }); reload(); } catch (x) { toastError(x); }
        } },
      });
    } catch (x) { toastError(x); }
    reload();
    if (S.detail && S.detail.id === ent.ev.id) closeDetail();
  }

  // Swipe left / right on phones to change period.
  (() => {
    let sx = 0, sy = 0, st = 0;
    el.view.addEventListener('touchstart', (e) => { const t = e.touches[0]; sx = t.clientX; sy = t.clientY; st = Date.now(); }, { passive: true });
    el.view.addEventListener('touchend', (e) => {
      const t = e.changedTouches[0];
      const dx = t.clientX - sx, dy = t.clientY - sy;
      if (Date.now() - st < 600 && Math.abs(dx) > 70 && Math.abs(dx) > Math.abs(dy) * 2 && !S.search && effView() !== 'agenda') step(dx < 0 ? 1 : -1);
    }, { passive: true });
  })();

  // ===========================================================================
  // Navigation
  // ===========================================================================
  function step(dir) {
    const v = effView();
    const a = S.anchor;
    if (v === 'day') S.anchor = addDays(a, dir);
    else if (v === 'days3') S.anchor = addDays(a, dir * 3);
    else if (v === 'week') S.anchor = addDays(a, dir * 7);
    else if (v === 'month') S.anchor = new Date(a.getFullYear(), a.getMonth() + dir, 1);
    else if (v === 'year') S.anchor = new Date(a.getFullYear() + dir, 0, 1);
    else S.anchor = addDays(a, dir * 30);
    load({ dir });
  }
  function setView(v) {
    if (!['day', 'week', 'month', 'year', 'agenda'].includes(v)) return;
    clearSearch(false);
    S.view = v;
    load({ dir: 0 });
  }
  function goToday() { clearSearch(false); S.anchor = day0(nowCo()); load(); }
  function goToDate(anchor) {
    popover(anchor || el.title, `<div style="padding:6px"><label class="label" for="gotoDate" style="display:block;margin:4px 4px 8px">Go to date</label>
      <input class="input" type="date" id="gotoDate" value="${ymd(S.anchor)}"><div style="display:flex;justify-content:flex-end;margin-top:10px"><button class="btn btn--primary btn--sm" data-v="go">Go</button></div></div>`,
    { width: 240, role: 'dialog', onClick: () => { const v = parseDT($('#gotoDate').value); if (v) { S.anchor = v; clearSearch(false); load(); } } });
    const inp = $('#gotoDate');
    if (inp) inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('[data-v=go]', popOpen().el).click(); } });
  }
  $('#todayBtn').addEventListener('click', goToday);
  $('#prevBtn').addEventListener('click', () => step(-1));
  $('#nextBtn').addEventListener('click', () => step(1));
  el.seg.addEventListener('click', (e) => { const b = e.target.closest('[data-view]'); if (b) setView(b.dataset.view); });
  el.title.addEventListener('click', () => goToDate(el.title));
  el.title.style.cursor = 'pointer';
  $('#viewMoreBtn').addEventListener('click', (e) => {
    const chk = (on) => `<span class="fwm-pop__check ${on ? 'is-on' : ''}">${on ? icon('check') : ''}</span>`;
    popover(e.currentTarget, `
      <button class="fwm-pop__item" data-v="weekends">${chk(S.settings.show_weekends)}Show weekends</button>
      <button class="fwm-pop__item" data-v="h24">${chk(S.settings.time_24h)}24-hour clock</button>
      <div class="fwm-pop__sep"></div>
      <button class="fwm-pop__item" data-v="goto">${icon('calendar')}Go to date… <small>g</small></button>
      <button class="fwm-pop__item" data-v="refresh">${icon('refresh')}Refresh</button>
      <button class="fwm-pop__item" data-v="print">${icon('print')}Print</button>`, {
      align: 'end',
      onClick: (it) => {
        const v = it.dataset.v;
        if (v === 'weekends') { S.settings.show_weekends = !S.settings.show_weekends; savePrefs({ show_weekends: S.settings.show_weekends }); render(); }
        if (v === 'h24') { S.settings.time_24h = !S.settings.time_24h; savePrefs({ time_24h: S.settings.time_24h }); render({ keepScroll: true }); }
        if (v === 'goto') setTimeout(() => goToDate(el.title), 0);
        if (v === 'refresh') reload();
        if (v === 'print') window.print();
      },
    });
  });

  // ---------------------------------------------------------------- search
  async function runSearch(q) {
    q = q.trim();
    if (q.length < 2) { if (!q) clearSearch(); return; }
    S.search = { q, results: null };
    closeDetail();
    render();
    try {
      const d = await api.get('search.php', { q });
      if (!S.search || S.search.q !== q) return;
      S.search.results = d.results || [];
      render();
    } catch (e) { toastError(e); }
  }
  function clearSearch(rerender = true) {
    if (!S.search) return;
    S.search = null;
    if (el.search) el.search.value = '';
    if (el.searchClear) el.searchClear.hidden = true;
    if (rerender) load();
  }
  if (el.search) {
    $('#searchForm').addEventListener('submit', (e) => { e.preventDefault(); runSearch(el.search.value); });
    el.search.addEventListener('input', debounce(() => { el.searchClear.hidden = !el.search.value; if (el.search.value.trim().length >= 2) runSearch(el.search.value); else if (!el.search.value) clearSearch(); }, 350));
    el.search.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); clearSearch(); el.search.blur(); } });
    el.searchClear.addEventListener('click', () => { clearSearch(); el.search.focus(); });
  }

  // ===========================================================================
  // Event panel
  // ===========================================================================
  function openEntry(key) {
    closePop();
    const ent = entryByKey(key) || (S.search && S.search.results ? (S.search.results.map(entryFromEvent).find((x) => x.key === key) || null) : null);
    if (!ent) return;
    if (ent.kind === 'event' && ent.ev.busy) { toast('This time is marked busy — details are private.'); return; }
    S.selKey = key;
    $$('.is-selected', el.view).forEach((x) => x.classList.remove('is-selected'));
    $$(`[data-key="${CSS.escape(key)}"]`, el.view).forEach((x) => x.classList.add('is-selected'));
    if (ent.kind === 'item') return showItem(ent);
    showEvent(ent.ev.id, ent.ev.occ);
  }
  function detailShell(bar, body) {
    el.detail.innerHTML = `<div class="fwm-reader__bar">${bar}</div><div class="fwc-detail__scroll scroll">${body}</div>`;
    if (el.detail.hidden) {
      el.detail.hidden = false;
      document.body.classList.add('is-detail');
      if (narrow()) history.pushState({ calDetail: 1 }, '', location.href);
    }
  }
  function closeDetail(fromPop) {
    if (el.detail.hidden) return;
    el.detail.hidden = true;
    el.detail.innerHTML = '';
    document.body.classList.remove('is-detail');
    S.detail = null;
    S.selKey = null;
    $$('.is-selected', el.view).forEach((x) => x.classList.remove('is-selected'));
    if (narrow() && !fromPop && history.state && history.state.calDetail) history.back();
    syncUrl();
  }
  window.addEventListener('popstate', () => { if (!el.detail.hidden) closeDetail(true); });

  function showItem(ent) {
    const it = ent.it;
    const m = sourceMeta(it.source);
    const status = it.status === 'overdue' ? '<span class="fwm-tag fwm-tag--danger">Overdue</span>' : it.status === 'done' ? '<span class="fwm-tag fwm-tag--plain">Done</span>' : '';
    const openLabel = { projects: 'Open project', tasks: 'Open task', invoices: 'Open invoice', quotes: 'Open quote', deals: 'Open deal', payroll: 'Open pay run' }[it.source] || 'Open';
    S.detail = { item: it };
    detailShell(`<button class="icon-btn" data-d="close" aria-label="Close" data-tip="Close">${icon('x')}</button><span class="grow"></span>
      <a class="btn btn--sm btn--primary" href="${esc(it.url)}">${icon('external', 'i--sm')}${esc(openLabel)}</a>`,
    `<div class="fwc-d__cal" style="--c:${esc(m.color)}"><i></i>${esc(m.label)} · from Flowwork</div>
      <h1 class="fwc-d__title">${esc(it.title)}</h1>
      <div class="fwc-d__tags">${status}</div>
      <div class="fwc-d__rows">
        <div class="fwc-d__row">${icon('clock')}<div><b>${esc(whenText(ent))}</b><small>Shown live from the ${esc(m.label.toLowerCase())} — change the date there.</small></div></div>
        ${it.sub ? `<div class="fwc-d__row">${icon(it.icon || m.icon)}<div>${esc(it.sub)}</div></div>` : ''}
      </div>
      <div class="fwc-d__section"><h4>Plan around it</h4><button class="btn" type="button" data-d="plan">${icon('calendar-plus')}Schedule time for this</button></div>`);
  }

  async function showEvent(id, occ) {
    if (!S.detail || S.detail.id !== id) detailShell(`<button class="icon-btn" data-d="close" aria-label="Close">${icon('x')}</button>`, '<div class="fwm-list__more">Loading…</div>');
    try {
      const d = await api.get('event.php', { id, occ: occ || undefined });
      S.detail = d.event;
      renderEventDetail();
    } catch (e) {
      toastError(e);
      closeDetail();
    }
  }
  function linkify(text) {
    return esc(text).replace(/\bhttps?:\/\/[^\s<]+[^\s<.,;:!?)\]'"]/g, (u) => `<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`);
  }
  function meetingUrl(loc) {
    const m = String(loc || '').match(/https?:\/\/[^\s]+/);
    return m ? m[0] : null;
  }
  function remText(r) {
    const m = r.minutes_before;
    const t = m === 0 ? 'At the start' : m % 10080 === 0 ? `${m / 10080} week${m > 10080 ? 's' : ''} before` : m % 1440 === 0 ? `${m / 1440} day${m > 1440 ? 's' : ''} before`
      : m % 60 === 0 ? `${m / 60} hour${m > 60 ? 's' : ''} before` : `${m} minutes before`;
    return t + (r.channel === 'email' ? ' · email' : ' · notification');
  }
  function renderEventDetail() {
    const ev = S.detail;
    const ent = { start: parseDT(ev.start), end: ev.all_day ? endOfDay(parseDT(ev.end)) : parseDT(ev.end), allDay: ev.all_day };
    const color = ev.color || ev.calendar.color || '#7a1f30';
    const can = ev.perms.edit;
    const bar = `<button class="icon-btn" data-d="close" aria-label="Close" data-tip="Close (Esc)">${icon(narrow() ? 'arrow-left' : 'x')}</button><span class="grow"></span>
      ${can ? `<button class="icon-btn" data-d="edit" aria-label="Edit" data-tip="Edit (e)">${icon('pen')}</button>` : ''}
      <button class="icon-btn" data-d="duplicate" aria-label="Duplicate" data-tip="Duplicate">${icon('copy')}</button>
      ${can ? `<button class="icon-btn" data-d="delete" aria-label="Delete" data-tip="Delete (#)">${icon('trash')}</button>` : ''}
      <button class="icon-btn" data-d="more" aria-label="More" data-tip="More" data-tip-pos="left">${icon('more-v')}</button>`;
    const tags = [];
    if (ev.visibility === 'private') tags.push(`<span class="fwm-tag fwm-tag--plain">${icon('lock', 'i--xs')}Private</span>`);
    if (ev.visibility === 'busy') tags.push('<span class="fwm-tag fwm-tag--plain">Others see “busy”</span>');
    if (ev.detached) tags.push('<span class="fwm-tag fwm-tag--plain">Changed occurrence</span>');
    const rows = [];
    rows.push(`<div class="fwc-d__row">${icon('clock')}<div><b>${esc(whenText(ent))}</b>${ev.recurrence_text ? `<small>${icon('repeat', 'i--xs')} ${esc(ev.recurrence_text)}</small>` : ''}</div></div>`);
    if (ev.location) {
      const url = meetingUrl(ev.location);
      rows.push(`<div class="fwc-d__row">${icon(url ? 'video' : 'pin')}<div>${url ? linkify(ev.location) : esc(ev.location)}
        ${url ? `<a class="btn btn--sm btn--primary fwc-join" href="${esc(url)}" target="_blank" rel="noopener noreferrer">Join</a>`
              : `<small><a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(ev.location)}" target="_blank" rel="noopener noreferrer">Open in Maps</a></small>`}</div></div>`);
    }
    if (ev.reminders.length) rows.push(`<div class="fwc-d__row">${icon('bell')}<div>${ev.reminders.map((r) => esc(remText(r))).join('<br>')}</div></div>`);
    const rsvp = ev.perms.respond ? `<div class="fwc-rsvp"><span>Going?</span><div class="seg" role="group" aria-label="Reply">
        ${[['accepted', 'Yes'], ['tentative', 'Maybe'], ['declined', 'No']].map(([v, l]) => `<button type="button" data-r="${v}" class="${ev.my_response === v ? 'is-on' : ''}">${l}</button>`).join('')}</div></div>` : '';
    const going = ev.participants.filter((p) => p.response === 'accepted').length;
    const waiting = ev.participants.filter((p) => p.response === 'pending').length;
    const guests = ev.participants.length > 1 ? `<div class="fwc-d__section"><h4>${ev.participants.length} guests</h4>
      <div class="hint" style="margin:-2px 0 8px">${going} yes${waiting ? ` · ${waiting} awaiting` : ''}${ev.participants.some((p) => p.response === 'declined') ? ` · ${ev.participants.filter((p) => p.response === 'declined').length} no` : ''}</div>
      ${ev.participants.map((p) => `<div class="fwc-guest">${avatar(p.name, p.email).replace('</span>', `<span class="fwc-guest__st is-${p.response}">${icon(p.response === 'accepted' ? 'check' : p.response === 'declined' ? 'x' : p.response === 'tentative' ? 'minus' : 'clock')}</span></span>`)}
        <span class="fwc-guest__who"><b>${esc(p.name)}${p.user_id === C.me.id ? ' (you)' : ''}</b><small>${p.role === 'organizer' ? 'Organiser' : p.role === 'optional' ? 'Optional' : ''}${p.role !== 'organizer' ? (p.role === 'optional' ? ' · ' : '') + ({ accepted: 'Going', declined: 'Not going', tentative: 'Maybe', pending: 'Awaiting reply' }[p.response]) : ''}</small></span></div>`).join('')}</div>` : '';
    const desc = ev.description ? `<div class="fwc-d__section"><h4>Notes</h4><div class="fwc-d__desc">${linkify(ev.description)}</div></div>` : '';
    const links = ev.links.length ? `<div class="fwc-d__section"><h4>Linked</h4><div class="fwc-links">${ev.links.map((l) => `<a class="fwc-link" href="${esc(l.url)}">
        <span class="fwc-link__icon">${icon(l.icon)}</span><span class="fwc-link__text"><span class="fwc-link__kind">${esc(l.kind)}</span><b>${esc(l.label)}</b>${l.sub ? `<small>${esc(l.sub)}</small>` : ''}</span>${icon('chev-right', 'i--sm')}</a>`).join('')}</div></div>` : '';
    const files = (ev.attachments.length || can) ? `<div class="fwc-d__section"><h4>Files</h4><div class="fwc-files">${ev.attachments.map((a) => {
      const [k, lbl] = fileKind(a.name, a.mime);
      return `<a class="fwm-file" href="${esc(a.url)}" title="Download ${esc(a.name)}"><span class="fwm-file__icon ${k ? 'fwm-file__icon--' + k : ''}">${esc(lbl)}</span>
        <span class="fwm-file__meta"><span class="fwm-file__name">${esc(a.name)}</span><span class="fwm-file__size">${fmtSize(a.size)}</span></span>
        ${can ? `<span class="fwm-file__act"><button class="icon-btn icon-btn--sm" type="button" data-rmfile="${a.id}" aria-label="Remove ${esc(a.name)}">${icon('x', 'i--sm')}</button></span>` : ''}</a>`;
    }).join('')}</div>${can ? `<button class="btn btn--sm" type="button" data-d="attach" style="margin-top:10px">${icon('clip', 'i--sm')}Attach a file</button>` : ''}</div>` : '';
    const foot = `<div class="fwc-d__foot">${ev.created_by ? `Created by ${esc(ev.created_by.name)}` : ''}${ev.created_at ? ` · ${esc(fmtShort(parseDT(ev.created_at)))}` : ''}${ev.updated_at && ev.updated_at !== ev.created_at ? `<br>Last changed ${esc(fmtShort(parseDT(ev.updated_at)))}` : ''}</div>`;
    detailShell(bar, `<div class="fwc-d__cal" style="--c:${esc(color)}"><i></i>${esc(ev.calendar.name || 'Invitation')}</div>
      <h1 class="fwc-d__title">${esc(ev.title)}</h1>${tags.length ? `<div class="fwc-d__tags">${tags.join('')}</div>` : ''}${rsvp}
      <div class="fwc-d__rows">${rows.join('')}</div>${guests}${desc}${links}${files}${foot}`);
  }

  el.detail.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-d], [data-r], [data-rmfile]');
    if (!b) return;
    if (b.dataset.rmfile) {
      e.preventDefault();
      if (!(await dialog({ title: 'Remove this file?', confirm: 'Remove', danger: true }))) return;
      try { await api.post('attachment.php', { action: 'delete', id: +b.dataset.rmfile }); showEvent(S.detail.id, S.detail.occ); reload(); } catch (x) { toastError(x); }
      return;
    }
    if (b.dataset.r) {
      try {
        const d = await api.post('event.php', { action: 'respond', id: S.detail.id, response: b.dataset.r });
        S.detail = Object.assign(d.event, { occ: S.detail.occ, start: S.detail.start, end: S.detail.end });
        renderEventDetail();
        toast({ accepted: 'You are going', tentative: 'Marked as maybe', declined: 'Declined — it is hidden from your calendar' }[b.dataset.r]);
        reload();
      } catch (x) { toastError(x); }
      return;
    }
    const a = b.dataset.d;
    const ev = S.detail;
    if (a === 'close') return closeDetail();
    if (a === 'plan' && ev && ev.item) {
      const d = parseDT(ev.item.start);
      const [h, m] = S.settings.work_start.split(':');
      d.setHours(+h, +m);
      return openEditor({ mode: 'create', prefill: { title: ev.item.title, start: d, end: addMinutes(d, S.settings.default_duration), allDay: false, links: itemLink(ev.item) } });
    }
    if (!ev || ev.item) return;
    if (a === 'edit') return openEditor({ mode: 'edit', event: ev });
    if (a === 'duplicate') return openEditor({ mode: 'create', prefill: prefillFrom(ev) });
    if (a === 'delete') return deleteEvent(ev);
    if (a === 'attach') { const f = $('#attachFile'); f.value = ''; f.onchange = () => uploadFiles(ev.id, Array.from(f.files)).then(() => { showEvent(ev.id, ev.occ); reload(); }); f.click(); return; }
    if (a === 'more') {
      popover(b, `<button class="fwm-pop__item" data-v="copy">${icon('link')}Copy link</button>
        ${ev.calendar.id && calById(ev.calendar.id) ? `<button class="fwm-pop__item" data-v="only">${icon('eye')}Show only this calendar</button>` : ''}
        <button class="fwm-pop__item" data-v="print">${icon('print')}Print</button>`, {
        align: 'end',
        onClick: (it) => {
          if (it.dataset.v === 'copy') {
            const url = location.origin + '/calendar/?event=' + ev.id + '&date=' + String(ev.start).slice(0, 10);
            (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(() => toast('Link copied'), () => toast(url, { timeout: 10000 }));
          }
          if (it.dataset.v === 'only') { S.hidden = new Set(C.calendars.filter((c) => c.id !== ev.calendar.id).map((c) => String(c.id))); savePrefs({ hidden_calendars: Array.from(S.hidden) }); render({ keepScroll: true }); }
          if (it.dataset.v === 'print') window.print();
        },
      });
    }
  });
  function itemLink(it) {
    const [type, id] = String(it.key).split(':');
    const map = { 'project-start': 'project', 'project-due': 'project', item: 'item', invoice: 'invoice', quote: 'quote', deal: 'crm_opportunity', payrun: 'pay_run' };
    return map[type] ? [{ type: map[type], id: +id, label: it.title, kind: sourceMeta(it.source).label, icon: it.icon }] : [];
  }
  function prefillFrom(ev) {
    return {
      title: ev.title, description: ev.description, location: ev.location, calendar_id: calById(ev.calendar.id) && calById(ev.calendar.id).can_add ? ev.calendar.id : undefined,
      color: ev.color, allDay: ev.all_day, start: parseDT(ev.start), end: parseDT(ev.end), visibility: ev.visibility,
      participants: ev.participants.filter((p) => p.user_id !== C.me.id).map((p) => ({ user_id: p.user_id, name: p.name, email: p.email })),
      reminders: ev.reminders, links: ev.links,
    };
  }
  async function deleteEvent(ev) {
    let scope = 'all';
    if (ev.recurrence && !ev.detached) {
      scope = await askScope('Delete');
      if (!scope) return;
    } else {
      const guests = ev.participants.length > 1;
      if (!(await dialog({ title: 'Delete this event?', body: guests ? 'Your guests will be told it is cancelled.' : '', confirm: 'Delete', danger: true }))) return;
    }
    try {
      await api.post('event.php', { action: 'delete', id: ev.id, scope, occ: ev.occ || (ev.recurrence ? ev.start : undefined) });
      closeDetail();
      toast(scope === 'this' ? 'Occurrence deleted' : 'Event deleted');
      reload();
    } catch (x) { toastError(x); }
  }
  async function uploadFiles(eventId, files) {
    for (const f of files) {
      if (f.size > 15 * 1024 * 1024) { toastError(new Error(`${f.name} is larger than 15 MB.`)); continue; }
      const fd = new FormData();
      fd.append('event_id', eventId);
      fd.append('file', f);
      const t = toast(`Uploading ${f.name}…`, { timeout: 0 });
      try { await api.upload('attachment.php', fd); t.close(); } catch (x) { t.close(); toastError(x); }
    }
  }

  // ===========================================================================
  // Quick create
  // ===========================================================================
  function quickCreate(anchor, r, onClose) {
    const cals = addableCalendars();
    if (!cals.length) { toast('You do not have a calendar you can add events to.'); if (onClose) onClose(); return; }
    const when = r.allDay ? (sameDay(r.start, r.end) ? fmtDay(r.start) : `${fmtShort(r.start)} – ${fmtShort(r.end)}`) : `${fmtShort(r.start)} · ${fmtTime(r.start)} – ${fmtTime(r.end)}`;
    const pop = popover(anchor, `<input class="fwc-ed__title" id="qTitle" placeholder="Add a title" maxlength="500" autocomplete="off">
      <div class="fwc-quick__when">${icon('clock')}${esc(when)}${r.allDay ? ' · all day' : ''}</div>
      ${cals.length > 1 ? `<select class="select" id="qCal" aria-label="Calendar">${cals.map((c) => `<option value="${c.id}" ${c.id === defaultCalId() ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select>` : ''}
      <div class="fwc-quick__foot"><button class="btn btn--ghost btn--sm" data-v="more">More options</button><button class="btn btn--primary btn--sm" data-v="save">Save</button></div>`, {
      cls: 'fwc-quick', role: 'dialog', align: anchor.getBoundingClientRect && !narrow() ? 'side' : undefined, onClose,
      onClick: (it) => {
        const title = $('#qTitle').value.trim();
        const calendarId = $('#qCal') ? +$('#qCal').value : defaultCalId();
        if (it.dataset.v === 'more') { setTimeout(() => openEditor({ mode: 'create', prefill: { title, start: r.start, end: r.end, allDay: r.allDay, calendar_id: calendarId } }), 0); return; }
        if (!title) { $('#qTitle').focus(); return false; }
        createQuick(title, calendarId, r);
      },
    });
    $('#qTitle', pop.el).addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); $('[data-v=save]', pop.el).click(); } });
  }
  async function createQuick(title, calendarId, r) {
    try {
      const d = await api.post('event.php', { action: 'create', title, calendar_id: calendarId, all_day: r.allDay,
        start: r.allDay ? ymd(r.start) : fmtDT(r.start), end: r.allDay ? ymd(r.end) : fmtDT(r.end) });
      toast('Event created', { action: { label: 'Undo', run: async () => { try { await api.post('event.php', { action: 'delete', id: d.id }); reload(); } catch (x) { toastError(x); } } } });
      await reload();
    } catch (x) { toastError(x); }
  }

  // ===========================================================================
  // Editor
  // ===========================================================================
  const REMIND_OPTS = [[0, 'At the start'], [5, '5 minutes before'], [10, '10 minutes before'], [15, '15 minutes before'], [30, '30 minutes before'], [60, '1 hour before'], [120, '2 hours before'], [1440, '1 day before'], [2880, '2 days before'], [10080, '1 week before']];

  function rrulePresets(d) {
    const wd = RR_DAYS[d.getDay()];
    const nth = Math.ceil(d.getDate() / 7);
    const isLast = addDays(d, 7).getMonth() !== d.getMonth();
    const list = [
      ['', 'Does not repeat'],
      ['FREQ=DAILY', 'Daily'],
      [`FREQ=WEEKLY;BYDAY=${wd}`, `Weekly on ${DOW[d.getDay()]}`],
      ['FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 'Every weekday (Mon–Fri)'],
      [`FREQ=MONTHLY;BYMONTHDAY=${d.getDate()}`, `Monthly on day ${d.getDate()}`],
    ];
    if (nth <= 4) list.push([`FREQ=MONTHLY;BYDAY=${nth}${wd}`, `Monthly on the ${NTH[nth]} ${DOW[d.getDay()]}`]);
    if (isLast) list.push([`FREQ=MONTHLY;BYDAY=-1${wd}`, `Monthly on the last ${DOW[d.getDay()]}`]);
    list.push([`FREQ=YEARLY`, `Annually on ${d.getDate()} ${MONTHS[d.getMonth()]}`]);
    return list;
  }
  function parseRule(rule) {
    const r = { freq: 'WEEKLY', interval: 1, byday: [], bymonthday: null, count: null, until: null };
    String(rule || '').split(';').forEach((p) => {
      const [k, v] = p.split('=');
      if (!v) return;
      if (k === 'FREQ') r.freq = v;
      if (k === 'INTERVAL') r.interval = +v || 1;
      if (k === 'BYDAY') r.byday = v.split(',');
      if (k === 'BYMONTHDAY') r.bymonthday = +v.split(',')[0];
      if (k === 'COUNT') r.count = +v;
      if (k === 'UNTIL') r.until = `${v.slice(0, 4)}-${v.slice(4, 6)}-${v.slice(6, 8)}`;
    });
    return r;
  }
  const baseRule = (rule) => String(rule || '').split(';').filter((p) => !/^(COUNT|UNTIL)=/.test(p)).join(';');

  async function openEditor({ mode, event = null, prefill = {} }) {
    closePop();
    const cals = addableCalendars();
    if (!cals.length) { toast('You do not have a calendar you can add events to.'); return; }
    const isEdit = mode === 'edit' && event;
    const now = nowCo();
    let start, end, allDay;
    if (isEdit) {
      allDay = event.all_day; start = parseDT(event.start); end = parseDT(event.end);
    } else {
      allDay = !!prefill.allDay;
      if (prefill.start) { start = prefill.start; end = prefill.end || addMinutes(prefill.start, S.settings.default_duration); }
      else {
        const base = sameDay(S.anchor, now) || !['day', 'days3', 'week'].includes(effView()) ? now : S.anchor;
        start = new Date(base.getFullYear(), base.getMonth(), base.getDate(), sameDay(base, now) ? now.getHours() + 1 : +S.settings.work_start.split(':')[0], 0);
        end = addMinutes(start, S.settings.default_duration);
      }
    }
    const src = isEdit ? event : prefill;
    const st = {
      participants: (isEdit ? event.participants.filter((p) => p.role !== 'organizer') : (prefill.participants || [])).map((p) => ({ user_id: p.user_id, name: p.name, email: p.email, role: p.role === 'optional' ? 'optional' : 'required' })),
      reminders: isEdit ? event.reminders.map((r) => ({ ...r })) : (prefill.reminders || (S.settings.default_reminder > 0 ? [{ minutes_before: S.settings.default_reminder, channel: 'in_app' }] : [])),
      links: (src.links || []).map((l) => ({ type: l.type, id: l.id, label: l.label, kind: l.kind, icon: l.icon })),
      color: src.color || '',
      files: [],
      rule: isEdit ? (event.recurrence || '') : (prefill.recurrence || ''),
      dirty: false,
      busy: [],
    };
    const calId = (isEdit ? event.calendar.id : prefill.calendar_id) || defaultCalId();
    const calOpts = cals.map((c) => `<option value="${c.id}" ${c.id === calId ? 'selected' : ''}>${esc(c.name)}${c.type === 'team' ? ' · shared' : ''}</option>`).join('');
    const extraCal = isEdit && !cals.some((c) => c.id === calId) ? `<option value="${calId}" selected>${esc(event.calendar.name || 'Current calendar')}</option>` : '';

    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>${isEdit ? 'Edit event' : 'New event'}</h2>
        <button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fwc-ed" id="edForm" autocomplete="off" novalidate>
        <input class="fwc-ed__title" name="title" placeholder="Add a title" maxlength="500" value="${esc(src.title || '')}" autofocus>
        <div class="fwc-ed__line">${icon('clock')}<div>
          <div class="fwc-ed__when">
            <input class="input" type="date" name="start_date" aria-label="Start date" required>
            <input class="input" type="time" name="start_time" aria-label="Start time" step="300">
            <span class="fwc-ed__dash">–</span>
            <input class="input" type="time" name="end_time" aria-label="End time" step="300">
            <input class="input" type="date" name="end_date" aria-label="End date" required>
          </div>
          <div class="fwc-ed__sub">
            <label class="switch"><input type="checkbox" name="all_day" ${allDay ? 'checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">All day</span></span></label>
            <select class="select" name="repeat" aria-label="Repeat"></select>
            <span class="hint">${esc(S.settings.timezone || '')}</span>
          </div>
          <div class="fwc-custom" id="edCustom" hidden></div>
        </div></div>
        <div class="fwc-ed__line">${icon('pin')}<div><input class="input" name="location" placeholder="Add a place or a meeting link" maxlength="255" value="${esc(src.location || '')}"></div></div>
        <div class="fwc-ed__line">${icon('users')}<div>
          <div class="fwc-guestbox" id="edGuests"><input id="edGuestInput" placeholder="Invite colleagues" aria-label="Invite colleagues"></div>
          <div id="edBusy"></div></div></div>
        <div class="fwc-ed__line">${icon('calendar')}<div class="row">
          <div class="field"><select class="select" name="calendar_id" aria-label="Calendar">${extraCal}${calOpts}</select></div>
          <div class="field"><select class="select" name="visibility" aria-label="Who can see the details">
            <option value="default">Visible to the calendar</option><option value="busy">Others see “busy”</option><option value="private">Private (only guests)</option></select></div>
        </div></div>
        <div class="fwc-ed__line">${icon('palette')}<div class="fwm-swatches" id="edColors"></div></div>
        <div class="fwc-ed__line">${icon('bell')}<div><div id="edReminders"></div><button class="btn btn--sm btn--ghost" type="button" data-ed="addrem">${icon('plus', 'i--sm')}Add reminder</button></div></div>
        <div class="fwc-ed__line">${icon('text')}<div><textarea class="textarea" name="description" rows="3" placeholder="Notes, agenda, dial-in details…" maxlength="20000">${esc(src.description || '')}</textarea></div></div>
        <div class="fwc-ed__line">${icon('link')}<div class="fwc-linkpick"><div class="fwc-chips" id="edLinks"></div>
          <input class="input" id="edLinkInput" placeholder="Link a project, customer, deal, invoice or quote" style="margin-top:6px"></div></div>
        ${isEdit ? '' : `<div class="fwc-ed__line">${icon('clip')}<div><div class="fwc-chips" id="edFiles"></div><button class="btn btn--sm btn--ghost" type="button" data-ed="addfile">${icon('plus', 'i--sm')}Attach files</button><input type="file" id="edFileInput" multiple hidden></div></div>`}
      </form>
      <div class="fwm-dialog__foot"><span class="fwc-ed__err" id="edErr"></span><button class="btn" data-close>Cancel</button><button class="btn btn--primary" id="edSave">${icon('check')}Save</button></div>`, 'fwm-dialog--wide');
    const f = $('#edForm', m);
    f.visibility.value = src.visibility || 'default';
    const setWhen = () => {
      f.start_date.value = ymd(start); f.end_date.value = ymd(end);
      f.start_time.value = hm(start); f.end_time.value = hm(end);
      f.start_time.hidden = f.end_time.hidden = f.all_day.checked;
    };
    setWhen();
    const readWhen = () => {
      const sd = parseDT(f.start_date.value), ed = parseDT(f.end_date.value);
      if (!sd || !ed) return null;
      if (f.all_day.checked) return { start: sd, end: ed };
      const [sh, sm] = (f.start_time.value || '09:00').split(':'), [eh, em] = (f.end_time.value || '10:00').split(':');
      return { start: new Date(sd.getFullYear(), sd.getMonth(), sd.getDate(), +sh, +sm), end: new Date(ed.getFullYear(), ed.getMonth(), ed.getDate(), +eh, +em) };
    };
    // Keep the duration when the start moves.
    let lastStart = readWhen().start;
    const onStart = () => {
      const w = readWhen();
      if (!w) return;
      const dur = end - start;
      const delta = w.start - lastStart;
      start = w.start;
      end = new Date(+readWhen().end + delta);
      if (end < start) end = new Date(+start + Math.max(dur, 0));
      lastStart = start;
      setWhen();
      renderRepeat();
      checkBusy();
    };
    f.start_date.addEventListener('change', onStart);
    f.start_time.addEventListener('change', onStart);
    const onEnd = () => { const w = readWhen(); if (!w) return; end = w.end < w.start ? w.start : w.end; start = w.start; setWhen(); checkBusy(); };
    f.end_date.addEventListener('change', onEnd);
    f.end_time.addEventListener('change', onEnd);
    f.all_day.addEventListener('change', () => {
      if (!f.all_day.checked && sameDay(start, end) && hm(start) === '00:00') { start.setHours(+S.settings.work_start.split(':')[0], 0); end = addMinutes(start, S.settings.default_duration); }
      setWhen(); checkBusy();
    });

    // Repeat
    const custom = $('#edCustom', m);
    function renderRepeat() {
      const presets = rrulePresets(start);
      const base = baseRule(st.rule);
      const match = presets.find(([v]) => v === base && !/COUNT|UNTIL/.test(st.rule));
      let opts = presets.map(([v, l]) => `<option value="${v}" ${(match ? match[0] === v : false) || (!st.rule && !v) ? 'selected' : ''}>${esc(l)}</option>`).join('');
      opts += `<option value="custom" ${st.rule && !match ? 'selected' : ''}>Custom…</option>`;
      f.repeat.innerHTML = opts;
      custom.hidden = !(st.rule && !match);
      if (!custom.hidden) renderCustom();
    }
    function renderCustom() {
      const r = parseRule(st.rule || `FREQ=WEEKLY;BYDAY=${RR_DAYS[start.getDay()]}`);
      const unit = { DAILY: 'day', WEEKLY: 'week', MONTHLY: 'month', YEARLY: 'year' };
      const monthByDay = r.byday.length && r.freq === 'MONTHLY';
      const nth = Math.ceil(start.getDate() / 7);
      custom.innerHTML = `<div class="fwc-custom__line">Every <input class="input" type="number" min="1" max="99" name="c_interval" value="${r.interval}">
          <select class="select" name="c_freq">${Object.entries(unit).map(([k, v]) => `<option value="${k}" ${r.freq === k ? 'selected' : ''}>${v}${r.interval > 1 ? 's' : ''}</option>`).join('')}</select></div>
        ${r.freq === 'WEEKLY' ? `<div class="fwc-custom__line">On <div class="fwc-wd">${[1, 2, 3, 4, 5, 6, 0].map((i) => `<button type="button" data-wd="${RR_DAYS[i]}" class="${(r.byday.length ? r.byday : [RR_DAYS[start.getDay()]]).includes(RR_DAYS[i]) ? 'is-on' : ''}" aria-label="${DOW[i]}">${DOW[i][0]}</button>`).join('')}</div></div>` : ''}
        ${r.freq === 'MONTHLY' ? `<div class="fwc-custom__line"><select class="select" name="c_mmode"><option value="day">On day ${start.getDate()}</option>${nth <= 4 ? `<option value="nth" ${monthByDay ? 'selected' : ''}>On the ${NTH[nth]} ${DOW[start.getDay()]}</option>` : ''}</select></div>` : ''}
        <div class="fwc-custom__line">Ends <select class="select" name="c_end"><option value="never">never</option><option value="until" ${r.until ? 'selected' : ''}>on</option><option value="count" ${r.count ? 'selected' : ''}>after</option></select>
          <input class="input" type="date" name="c_until" value="${r.until || ymd(addDays(start, 90))}" ${r.until ? '' : 'hidden'}>
          <input class="input" type="number" min="1" max="999" name="c_count" value="${r.count || 10}" ${r.count ? '' : 'hidden'}> <span ${r.count ? '' : 'hidden'} data-c="times">times</span></div>`;
    }
    function readCustom() {
      const freq = custom.querySelector('[name=c_freq]').value;
      const parts = ['FREQ=' + freq];
      const iv = Math.max(1, Math.min(99, +custom.querySelector('[name=c_interval]').value || 1));
      if (iv > 1) parts.push('INTERVAL=' + iv);
      if (freq === 'WEEKLY') {
        const days = $$('.fwc-wd button.is-on', custom).map((b) => b.dataset.wd);
        parts.push('BYDAY=' + (days.length ? days.join(',') : RR_DAYS[start.getDay()]));
      }
      if (freq === 'MONTHLY') {
        const mm = custom.querySelector('[name=c_mmode]');
        if (mm && mm.value === 'nth') parts.push('BYDAY=' + Math.ceil(start.getDate() / 7) + RR_DAYS[start.getDay()]);
        else parts.push('BYMONTHDAY=' + start.getDate());
      }
      const endMode = custom.querySelector('[name=c_end]').value;
      if (endMode === 'until') parts.push('UNTIL=' + custom.querySelector('[name=c_until]').value.replace(/-/g, ''));
      if (endMode === 'count') parts.push('COUNT=' + Math.max(1, +custom.querySelector('[name=c_count]').value || 1));
      return parts.join(';');
    }
    f.repeat.addEventListener('change', () => {
      if (f.repeat.value === 'custom') { st.rule = st.rule || `FREQ=WEEKLY;BYDAY=${RR_DAYS[start.getDay()]}`; custom.hidden = false; renderCustom(); }
      else { st.rule = f.repeat.value; custom.hidden = true; }
    });
    custom.addEventListener('click', (e) => { const b = e.target.closest('[data-wd]'); if (b) { b.classList.toggle('is-on'); st.rule = readCustom(); } });
    custom.addEventListener('change', (e) => {
      if (e.target.name === 'c_end') {
        custom.querySelector('[name=c_until]').hidden = e.target.value !== 'until';
        custom.querySelector('[name=c_count]').hidden = e.target.value !== 'count';
        custom.querySelector('[data-c=times]').hidden = e.target.value !== 'count';
      }
      st.rule = readCustom();
      if (e.target.name === 'c_freq' || e.target.name === 'c_interval') renderCustom();
    });
    renderRepeat();

    // Colours
    const colorsEl = $('#edColors', m);
    const renderColors = () => {
      const calColor = (calById(+f.calendar_id.value) || {}).color || '#7a1f30';
      colorsEl.innerHTML = `<button type="button" class="fwm-swatch ${!st.color ? 'is-on' : ''}" style="background:${esc(calColor)}" data-color="" title="Calendar colour" aria-label="Calendar colour"></button>`
        + PALETTE.filter((c) => c !== calColor).map((c) => `<button type="button" class="fwm-swatch ${st.color === c ? 'is-on' : ''}" style="background:${c}" data-color="${c}" aria-label="Colour ${c}"></button>`).join('');
    };
    colorsEl.addEventListener('click', (e) => { const b = e.target.closest('[data-color]'); if (b) { st.color = b.dataset.color; renderColors(); } });
    f.calendar_id.addEventListener('change', renderColors);
    renderColors();

    // Reminders
    const remEl = $('#edReminders', m);
    const renderRem = () => {
      remEl.innerHTML = st.reminders.map((r, i) => `<div class="fwc-rem" data-i="${i}">
        <select class="select" data-rk="minutes_before" aria-label="When">${REMIND_OPTS.concat(REMIND_OPTS.some(([v]) => v === r.minutes_before) ? [] : [[r.minutes_before, remText(r).split(' · ')[0]]]).map(([v, l]) => `<option value="${v}" ${v === r.minutes_before ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>
        <select class="select" data-rk="channel" aria-label="How"><option value="in_app">Notification</option><option value="email" ${r.channel === 'email' ? 'selected' : ''}>Email</option></select>
        <button class="icon-btn icon-btn--sm" type="button" data-rmrem="${i}" aria-label="Remove reminder">${icon('x', 'i--sm')}</button></div>`).join('') || '<div class="hint" style="padding:9px 0 4px">No reminders</div>';
    };
    remEl.addEventListener('change', (e) => { const row = e.target.closest('[data-i]'); if (row) st.reminders[+row.dataset.i][e.target.dataset.rk] = e.target.dataset.rk === 'channel' ? e.target.value : +e.target.value; });
    remEl.addEventListener('click', (e) => { const b = e.target.closest('[data-rmrem]'); if (b) { st.reminders.splice(+b.dataset.rmrem, 1); renderRem(); } });
    renderRem();

    // Guests
    const guestsEl = $('#edGuests', m);
    const gInput = $('#edGuestInput', m);
    const busyEl = $('#edBusy', m);
    const renderGuests = () => {
      $$('.fwm-rchip', guestsEl).forEach((x) => x.remove());
      const busyIds = new Set(st.busy);
      st.participants.forEach((p, i) => {
        const c = document.createElement('span');
        c.className = 'fwm-rchip' + (busyIds.has(p.user_id) ? ' is-busy' : '');
        c.innerHTML = `${avatar(p.name, p.email)}<span>${esc(p.name)}${p.role === 'optional' ? ' (optional)' : ''}</span><button type="button" data-rmguest="${i}" aria-label="Remove ${esc(p.name)}">${icon('x', 'i--xs')}</button>`;
        guestsEl.insertBefore(c, gInput);
      });
      gInput.placeholder = st.participants.length ? 'Add more' : 'Invite colleagues';
    };
    guestsEl.addEventListener('click', (e) => {
      const b = e.target.closest('[data-rmguest]');
      if (b) { st.participants.splice(+b.dataset.rmguest, 1); renderGuests(); checkBusy(); return; }
      gInput.focus();
    });
    let sugg = null, suggIdx = 0;
    const closeSugg = () => { if (sugg) { sugg.remove(); sugg = null; } };
    const showSugg = async () => {
      const q = gInput.value.trim().toLowerCase();
      const people = await loadPeople();
      const taken = new Set([C.me.id, ...st.participants.map((p) => p.user_id)]);
      const list = people.filter((p) => !taken.has(p.id) && (!q || p.name.toLowerCase().includes(q) || String(p.email).toLowerCase().includes(q))).slice(0, 8);
      closeSugg();
      if (!list.length || document.activeElement !== gInput) return;
      sugg = document.createElement('div');
      sugg.className = 'fwm-suggest';
      sugg.innerHTML = list.map((p, i) => `<div class="fwm-suggest__item ${i === 0 ? 'is-active' : ''}" data-uid="${p.id}">${avatar(p.name, p.email)}<div class="fwm-suggest__text"><div class="fwm-suggest__name">${esc(p.name)}</div><div class="fwm-suggest__email">${esc(p.email)}</div></div></div>`).join('');
      document.body.appendChild(sugg);
      const r = guestsEl.getBoundingClientRect();
      sugg.style.left = Math.min(r.left, window.innerWidth - sugg.offsetWidth - 12) + 'px';
      sugg.style.top = (r.bottom + 4 + sugg.offsetHeight > window.innerHeight ? r.top - sugg.offsetHeight - 4 : r.bottom + 4) + 'px';
      suggIdx = 0;
      sugg.addEventListener('mousedown', (e) => { e.preventDefault(); const it = e.target.closest('[data-uid]'); if (it) addGuest(+it.dataset.uid); });
    };
    const addGuest = (uid) => {
      const p = (C.people || []).find((x) => x.id === uid);
      if (!p) return;
      st.participants.push({ user_id: p.id, name: p.name, email: p.email, role: 'required' });
      gInput.value = '';
      renderGuests(); closeSugg(); checkBusy();
      setTimeout(showSugg, 0);
    };
    gInput.addEventListener('focus', showSugg);
    gInput.addEventListener('input', showSugg);
    gInput.addEventListener('blur', () => setTimeout(closeSugg, 120));
    gInput.addEventListener('keydown', (e) => {
      const items = sugg ? $$('[data-uid]', sugg) : [];
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!items.length) return;
        e.preventDefault();
        suggIdx = (suggIdx + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
        items.forEach((x, i) => x.classList.toggle('is-active', i === suggIdx));
      }
      if (e.key === 'Enter' && items.length) { e.preventDefault(); addGuest(+items[suggIdx].dataset.uid); }
      if (e.key === 'Backspace' && !gInput.value && st.participants.length) { st.participants.pop(); renderGuests(); checkBusy(); }
      if (e.key === 'Escape' && sugg) { e.stopPropagation(); closeSugg(); }
    });
    const checkBusy = debounce(async () => {
      const w = readWhen();
      if (!w || !st.participants.length) { st.busy = []; busyEl.innerHTML = ''; renderGuests(); return; }
      try {
        const d = await api.get('people.php', { busy: 1, users: st.participants.map((p) => p.user_id).join(','), from: ymd(w.start), to: ymd(w.end), exclude: isEdit ? event.id : undefined });
        const s0 = f.all_day.checked ? day0(w.start) : w.start, e0 = f.all_day.checked ? endOfDay(w.end) : w.end;
        const clash = new Set(d.busy.filter((b) => { const bs = parseDT(b.start), be = parseDT(b.end); return bs < e0 && be > s0 && !(b.all_day && !f.all_day.checked); }).map((b) => b.user_id));
        st.busy = Array.from(clash);
        renderGuests();
        const names = st.participants.filter((p) => clash.has(p.user_id)).map((p) => p.name.split(' ')[0]);
        busyEl.innerHTML = names.length
          ? `<div class="fwc-hintline">${icon('alert')}<span>${esc(names.join(', '))} ${names.length === 1 ? 'has' : 'have'} something else at this time.</span></div>`
          : `<div class="fwc-hintline is-ok">${icon('check')}<span>Everyone is free.</span></div>`;
      } catch (x) { busyEl.innerHTML = ''; }
    }, 450);
    renderGuests();
    if (st.participants.length) checkBusy();

    // Links
    const linksEl = $('#edLinks', m);
    const linkInput = $('#edLinkInput', m);
    const renderLinks = () => {
      linksEl.innerHTML = st.links.map((l, i) => `<span class="fwm-tag fwm-tag--link">${icon(l.icon || 'link')}${esc((l.kind ? l.kind + ': ' : '') + l.label)}<button type="button" data-rmlink="${i}" aria-label="Remove link">${icon('x', 'i--xs')}</button></span>`).join('');
    };
    linksEl.addEventListener('click', (e) => { const b = e.target.closest('[data-rmlink]'); if (b) { st.links.splice(+b.dataset.rmlink, 1); renderLinks(); } });
    let linkSugg = null;
    const closeLinkSugg = () => { if (linkSugg) { linkSugg.remove(); linkSugg = null; } };
    linkInput.addEventListener('input', debounce(async () => {
      const q = linkInput.value.trim();
      closeLinkSugg();
      if (q.length < 2) return;
      try {
        const d = await api.get('links.php', { q });
        closeLinkSugg();
        const list = (d.results || []).filter((r) => !st.links.some((l) => l.type === r.type && l.id === r.id));
        if (!list.length || document.activeElement !== linkInput) return;
        linkSugg = document.createElement('div');
        linkSugg.className = 'fwm-suggest';
        linkSugg.innerHTML = list.map((r, i) => `<div class="fwm-suggest__item" data-li="${i}"><span class="fwc-link__icon">${icon(r.icon)}</span><div class="fwm-suggest__text"><div class="fwm-suggest__src">${esc(r.kind)}</div><div class="fwm-suggest__name">${esc(r.label)}</div>${r.sub ? `<div class="fwm-suggest__email">${esc(r.sub)}</div>` : ''}</div></div>`).join('');
        document.body.appendChild(linkSugg);
        const rr = linkInput.getBoundingClientRect();
        linkSugg.style.left = rr.left + 'px';
        linkSugg.style.top = (rr.bottom + 4 + linkSugg.offsetHeight > window.innerHeight ? rr.top - linkSugg.offsetHeight - 4 : rr.bottom + 4) + 'px';
        linkSugg.addEventListener('mousedown', (e) => {
          e.preventDefault();
          const it = e.target.closest('[data-li]');
          if (!it) return;
          st.links.push(list[+it.dataset.li]);
          linkInput.value = '';
          closeLinkSugg();
          renderLinks();
        });
      } catch (x) { /* ignore */ }
    }, 300));
    linkInput.addEventListener('blur', () => setTimeout(closeLinkSugg, 150));
    linkInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); if (e.key === 'Escape' && linkSugg) { e.stopPropagation(); closeLinkSugg(); } });
    renderLinks();

    // Files (new events: uploaded after the event is saved)
    if (!isEdit) {
      const filesEl = $('#edFiles', m);
      const fi = $('#edFileInput', m);
      const renderFiles = () => { filesEl.innerHTML = st.files.map((fl, i) => `<span class="fwm-att">${icon('clip', 'i--sm')}<span>${esc(fl.name)}</span><small>${fmtSize(fl.size)}</small><button class="icon-btn icon-btn--sm" type="button" data-rmf="${i}" aria-label="Remove">${icon('x', 'i--sm')}</button></span>`).join(''); };
      fi.addEventListener('change', () => { st.files.push(...Array.from(fi.files)); fi.value = ''; renderFiles(); });
      filesEl.addEventListener('click', (e) => { const b = e.target.closest('[data-rmf]'); if (b) { st.files.splice(+b.dataset.rmf, 1); renderFiles(); } });
      $('[data-ed=addfile]', m).addEventListener('click', () => fi.click());
    }
    $('[data-ed=addrem]', m).addEventListener('click', () => { if (st.reminders.length >= 5) return; st.reminders.push({ minutes_before: S.settings.default_reminder || 15, channel: 'in_app' }); renderRem(); });

    // Unsaved changes guard
    f.addEventListener('input', () => { st.dirty = true; });
    m.dataset.guard = '1';
    m._guard = () => !st.dirty || confirm('Discard your changes to this event?');
    m._onClose = () => { closeSugg(); closeLinkSugg(); };

    const errEl = $('#edErr', m);
    const save = async () => {
      errEl.textContent = '';
      const w = readWhen();
      const title = f.title.value.trim();
      if (!title) { errEl.textContent = 'Give the event a title.'; f.title.focus(); return; }
      if (!w) { errEl.textContent = 'Choose when it starts and ends.'; return; }
      if (w.end < w.start) { errEl.textContent = 'The event ends before it starts.'; return; }
      if (f.repeat.value === 'custom') st.rule = readCustom();
      const body = {
        title, calendar_id: +f.calendar_id.value, all_day: f.all_day.checked,
        start: f.all_day.checked ? ymd(w.start) : fmtDT(w.start), end: f.all_day.checked ? ymd(w.end) : fmtDT(w.end),
        location: f.location.value, description: f.description.value, visibility: f.visibility.value, color: st.color,
        recurrence: st.rule || '', participants: st.participants.map((p) => ({ user_id: p.user_id, role: p.role })),
        reminders: st.reminders, links: st.links.map((l) => ({ type: l.type, id: l.id })),
      };
      let scope = 'all';
      if (isEdit && event.recurrence && !event.detached) {
        scope = await askScope('Save');
        if (!scope) return;
      }
      const btn = $('#edSave', m);
      btn.classList.add('is-busy');
      try {
        let d;
        if (isEdit) {
          // An unchanged rule is not sent for one occurrence or a split, so the server keeps the series' own count.
          if (scope !== 'all' && body.recurrence === (event.recurrence || '')) delete body.recurrence;
          if (body.calendar_id === event.calendar.id) delete body.calendar_id;
          d = await api.post('event.php', Object.assign({ action: 'update', id: event.id, scope, occ: event.occ || (event.recurrence ? event.start : undefined) }, body));
        } else {
          d = await api.post('event.php', Object.assign({ action: 'create' }, body));
          if (st.files.length) await uploadFiles(d.id, st.files);
        }
        st.dirty = false;
        closeModal(m);
        toast(isEdit ? 'Event saved' : (body.participants.length ? 'Event created — invitations sent' : 'Event created'));
        const r = range();
        if (r && (day0(w.start) < day0(r[0]) || day0(w.start) > day0(r[1]))) S.anchor = day0(w.start);
        await load({ force: true, keepScroll: true });
        if (isEdit || !el.detail.hidden) showEvent(d.id, null);
      } catch (x) {
        errEl.textContent = x.message;
      } finally {
        btn.classList.remove('is-busy');
      }
    };
    $('#edSave', m).addEventListener('click', save);
    f.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    m.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); } });
    openModal(m);
    setTimeout(() => { f.title.focus(); if (!f.title.value) f.title.select(); }, 40);
    if (prefill.links && prefill.links.length && !prefill.links[0].label) {
      // Deep link (?link=type:id): describe the record before showing the chip.
      st.links = [];
      for (const l of prefill.links) {
        try { const d = await api.get('links.php', { type: l.type, id: l.id }); st.links.push(d.link); } catch (x) { /* no access */ }
      }
      renderLinks();
    }
  }

  // ===========================================================================
  // Calendars: menu, create/edit, share, subscribe, import
  // ===========================================================================
  function calendarMenu(anchor, c) {
    if (!c) return;
    const sw = c.can_manage ? `<div style="padding:6px 8px 8px"><div class="fwm-swatches">${PALETTE.map((col) => `<button type="button" class="fwm-swatch ${c.color === col ? 'is-on' : ''}" style="background:${col};width:22px;height:22px" data-v="color" data-color="${col}" aria-label="Colour"></button>`).join('')}</div></div><div class="fwm-pop__sep"></div>` : '';
    popover(anchor, `<div class="fwm-pop__title">${esc(c.name)}</div>${sw}
      <button class="fwm-pop__item" data-v="only">${icon('eye')}Show only this</button>
      ${c.can_add ? `<button class="fwm-pop__item" data-v="default">${icon('check')}Use for new events${S.settings.default_calendar_id === c.id || (!S.settings.default_calendar_id && defaultCalId() === c.id) ? ' <small>default</small>' : ''}</button>` : ''}
      ${c.can_manage ? `<button class="fwm-pop__item" data-v="edit">${icon('pen')}Rename &amp; describe…</button>` : ''}
      ${c.can_manage && c.type === 'personal' ? `<button class="fwm-pop__item" data-v="share">${icon('share')}Share with colleagues…</button>` : ''}
      ${c.can_manage ? `<button class="fwm-pop__item" data-v="feed">${icon('rss')}Subscribe on your phone…</button>` : ''}
      ${c.can_add ? `<button class="fwm-pop__item" data-v="import">${icon('upload')}Import .ics into this</button>` : ''}
      <a class="fwm-pop__item" data-v="export" href="/calendar/api/export.php?calendar_id=${c.id}">${icon('download')}Export .ics</a>
      ${c.can_manage ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete calendar…</button>` : ''}`, {
      align: 'end', width: 250,
      onClick: (it) => {
        const v = it.dataset.v;
        if (v === 'color') { saveCalendar(c, { color: it.dataset.color }); return; }
        if (v === 'only') { S.hidden = new Set(C.calendars.filter((x) => x.id !== c.id).map((x) => String(x.id))); S.hidden.delete(String(c.id)); savePrefs({ hidden_calendars: Array.from(S.hidden) }); render({ keepScroll: true }); }
        if (v === 'default') { S.settings.default_calendar_id = c.id; savePrefs({ default_calendar_id: c.id }); toast(`New events go to ${c.name}`); }
        if (v === 'edit') setTimeout(() => calendarDialog(c), 0);
        if (v === 'share') setTimeout(() => shareDialog(c), 0);
        if (v === 'feed') setTimeout(() => feedDialog(c), 0);
        if (v === 'import') pickImport(c.id);
        if (v === 'delete') setTimeout(() => deleteCalendar(c), 0);
      },
    });
  }
  $('#newCalBtn').addEventListener('click', () => calendarDialog(null));

  // ===========================================================================
  // Keyboard
  // ===========================================================================
  document.addEventListener('keydown', (e) => {
    if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.target.closest('input, textarea, select, [contenteditable]')) return;
    if ($$('.fwm-modal:not([hidden])').length) return;
    if (e.key === 'Escape') { if (popOpen()) return closePop(); if (!el.detail.hidden) return closeDetail(); if (S.search) return clearSearch(); return; }
    const k = e.key;
    const map = { t: goToday, j: () => step(1), n: () => step(1), k: () => step(-1), p: () => step(-1), d: () => setView('day'), w: () => setView('week'), m: () => setView('month'), y: () => setView('year'), a: () => setView('agenda'), s: () => setView('agenda'),
      c: () => openEditor({ mode: 'create' }), g: () => goToDate(el.title), '/': () => el.search && el.search.focus(), '?': () => openModal('shortcutsModal'),
      e: () => { if (S.detail && S.detail.id && S.detail.perms && S.detail.perms.edit) openEditor({ mode: 'edit', event: S.detail }); },
      '#': () => { if (S.detail && S.detail.id && S.detail.perms && S.detail.perms.edit) deleteEvent(S.detail); },
      Delete: () => { if (S.detail && S.detail.id && S.detail.perms && S.detail.perms.edit) deleteEvent(S.detail); } };
    if (map[k]) { e.preventDefault(); map[k](); }
  });

  // ===========================================================================
  // URL
  // ===========================================================================
  function syncUrl() {
    const q = new URLSearchParams();
    q.set('view', S.view);
    q.set('date', ymd(S.anchor));
    if (S.detail && S.detail.id) q.set('event', S.detail.id);
    const url = location.pathname + '?' + q.toString();
    if (url !== location.pathname + location.search && !(history.state && history.state.calDetail)) history.replaceState(history.state, '', url);
  }
  function readUrl() {
    const p = new URLSearchParams(location.search);
    const v = p.get('view');
    S.view = ['day', 'week', 'month', 'year', 'agenda'].includes(v) ? v : (S.settings.default_view || 'week');
    const d = parseDT(p.get('date') || '');
    if (d) S.anchor = day0(d);
    if (p.get('focus')) S.focusKey = p.get('focus');
    return p;
  }

  // ===========================================================================
  // Boot
  // ===========================================================================
  async function boot() {
    C.onChange = () => reload();
    const p = readUrl();
    $('#newEventBtn').addEventListener('click', () => { if (narrow()) toggleDrawer(false); openEditor({ mode: 'create' }); });
    $('#fabNew').addEventListener('click', () => openEditor({ mode: 'create' }));
    window.addEventListener('resize', debounce(() => { if (['month'].includes(effView())) render({ keepScroll: true }); }, 200));
    let lastNarrow = narrow();
    window.addEventListener('resize', debounce(() => { if (narrow() !== lastNarrow) { lastNarrow = narrow(); load({ keepScroll: true }); } }, 200));
    await load();
    if (p.get('event')) {
      await showEvent(+p.get('event'), p.get('date') && /^\d{4}-\d{2}-\d{2}$/.test(p.get('date')) ? p.get('date') : null);
      if (p.get('edit') && S.detail && S.detail.perms && S.detail.perms.edit) openEditor({ mode: 'edit', event: S.detail });
    }
    if (S.focusKey) {
      const ent = entryByKey(S.focusKey);
      if (ent) openEntry(ent.key);
      setTimeout(() => { S.focusKey = null; }, 4000);
    }
    if (p.get('new')) {
      const date = parseDT(p.get('date') || '') || day0(nowCo());
      const allDay = p.get('all_day') === '1';
      let start = new Date(date), end;
      const tm = (s) => (/^\d{2}:\d{2}$/.test(s || '') ? s.split(':').map(Number) : null);
      const st = tm(p.get('start')), en = tm(p.get('end'));
      if (!allDay) { start.setHours(st ? st[0] : +S.settings.work_start.split(':')[0], st ? st[1] : 0); end = en ? new Date(date.getFullYear(), date.getMonth(), date.getDate(), en[0], en[1]) : addMinutes(start, S.settings.default_duration); if (end < start) end = addMinutes(start, S.settings.default_duration); }
      else end = new Date(date);
      const link = (p.get('link') || '').match(/^([a-z_]+):(\d+)$/);
      openEditor({ mode: 'create', prefill: { title: (p.get('title') || '').slice(0, 500), location: (p.get('location') || '').slice(0, 255), description: (p.get('description') || '').slice(0, 5000),
        start, end, allDay, calendar_id: +p.get('calendar_id') || undefined, links: link ? [{ type: link[1], id: +link[2] }] : [] } });
    }
    // Keep the sidebar dots and today line fresh when returning to the tab.
    document.addEventListener('visibilitychange', () => { if (!document.hidden) reload(); });
  }
  boot();
})();
