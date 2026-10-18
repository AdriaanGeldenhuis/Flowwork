/* =============================================================================
   Flowwork Calendar — settings page
   Uses the shared core (window.FWCal): API, toasts, dialogs and the calendar
   dialogs (create, edit, share, subscribe, delete, import).
   ============================================================================= */
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, C, calendarDialog, shareDialog, feedDialog, deleteCalendar, pickImport, defaultCalId } = window.FWCal;
  const isAdmin = $('#settingsApp').dataset.admin === '1';

  // ---------------------------------------------------------- tabs
  const TABS = ['general', 'calendars', 'sources', 'import'];
  function showTab() {
    const t = TABS.includes(location.hash.replace(/^#/, '')) ? location.hash.slice(1) : 'general';
    $$('.fwm-panel').forEach((p) => p.classList.toggle('is-active', p.dataset.panel === t));
    $$('#settingsNav [data-tab]').forEach((a) => a.classList.toggle('is-active', a.dataset.tab === t));
    document.title = $(`#settingsNav [data-tab="${t}"] .fwm-nav__label`).textContent + ' · Calendar settings';
  }
  window.addEventListener('hashchange', showTab);

  // ---------------------------------------------------------- saving
  let saveTimer = null;
  async function save(patch) {
    clearTimeout(saveTimer);
    try {
      const d = await api.post('settings.php', patch);
      Object.assign(C.settings, d.settings);
      saveTimer = setTimeout(() => toast('Saved'), 150);
    } catch (e) {
      toastError(e);
      renderGeneral();
    }
  }
  const sel = (name, value, opts) => `<select class="select" name="${name}">${opts.map(([v, l]) => `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
  const sw = (name, on, title, hint) => `<label class="switch"><input type="checkbox" name="${name}" ${on ? 'checked' : ''}><span class="switch__track"></span>
    <span class="switch__text"><span class="switch__title">${esc(title)}</span>${hint ? `<span class="hint">${esc(hint)}</span>` : ''}</span></label>`;

  // ---------------------------------------------------------- general
  function renderGeneral() {
    const s = C.settings;
    const cals = C.calendars.filter((c) => c.can_add);
    $('#generalForm').innerHTML = `
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('grid')}View</h3><p>What you see when you open the calendar.</p></div></div>
        <div class="fwm-card__body">
          <div class="row">
            <div class="field"><label class="label">Start in</label>${sel('default_view', s.default_view, [['day', 'Day'], ['week', 'Week'], ['month', 'Month'], ['year', 'Year'], ['agenda', 'Schedule']])}</div>
            <div class="field"><label class="label">Week starts on</label>${sel('week_start', s.week_start, [[1, 'Monday'], [0, 'Sunday']])}</div>
          </div>
          <div style="margin-top:12px">${sw('show_weekends', s.show_weekends, 'Show weekends', 'Hide Saturday and Sunday to give working days more room.')}
          ${sw('time_24h', s.time_24h, '24-hour clock', 'Show 14:30 instead of 2:30pm.')}</div>
        </div></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('clock')}Working hours</h3><p>Hours outside these are shaded in day and week views.</p></div></div>
        <div class="fwm-card__body"><div class="row">
          <div class="field"><label class="label" for="ws">From</label><input class="input" type="time" id="ws" name="work_start" value="${esc(s.work_start)}" step="900"></div>
          <div class="field"><label class="label" for="we">To</label><input class="input" type="time" id="we" name="work_end" value="${esc(s.work_end)}" step="900"></div>
        </div></div></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('calendar-plus')}New events</h3><p>Starting points for events you create. You can change them on each event.</p></div></div>
        <div class="fwm-card__body"><div class="row">
          <div class="field"><label class="label">Calendar</label>${sel('default_calendar_id', s.default_calendar_id || defaultCalId(), cals.map((c) => [c.id, c.name]))}</div>
          <div class="field"><label class="label">Length</label>${sel('default_duration', s.default_duration, [[15, '15 minutes'], [30, '30 minutes'], [45, '45 minutes'], [60, '1 hour'], [90, '1½ hours'], [120, '2 hours']])}</div>
          <div class="field"><label class="label">Reminder</label>${sel('default_reminder', s.default_reminder, [[0, 'None'], [5, '5 minutes before'], [10, '10 minutes before'], [15, '15 minutes before'], [30, '30 minutes before'], [60, '1 hour before'], [1440, '1 day before']])}</div>
        </div></div></div>
      <div class="fwm-card fwm-card--flat"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('globe')}Time zone · ${esc(s.timezone)}</h3>
        <p>Everyone in the company sees event times in this zone.${isAdmin ? ' Change it under <a href="/admin/calendar.php">Company defaults</a>.' : ' An admin can change it.'}</p></div></div></div>`;
  }
  $('#generalForm').addEventListener('change', (e) => {
    const t = e.target;
    if (!t.name) return;
    let v = t.type === 'checkbox' ? t.checked : t.value;
    if (['week_start', 'default_duration', 'default_reminder', 'default_calendar_id'].includes(t.name)) v = +v;
    if ((t.name === 'work_start' || t.name === 'work_end') && !v) return;
    save({ [t.name]: v });
  });

  // ---------------------------------------------------------- calendars
  function calCard(c) {
    const who = c.type === 'team' ? 'Everyone in the company' : c.type === 'project' ? 'Project members'
      : c.shared_with_me ? `Shared with you by ${c.owner}` : (c.share && c.share.length ? `You and ${c.share.length} colleague${c.share.length === 1 ? '' : 's'}` : 'Only you');
    const def = (C.settings.default_calendar_id || defaultCalId()) === c.id;
    return `<div class="fwm-card" data-cal="${c.id}"><div class="fwm-card__head">
        <span class="fwc-swatch-check" style="--c:${esc(c.color)};width:22px;height:22px;border-radius:8px">${icon('calendar', 'i--xs')}</span>
        <div class="fwm-card__title"><h3>${esc(c.name)}${def ? '<span class="fwm-badge fwm-badge--accent">Default</span>' : ''}</h3>
          <p>${esc(who)} · ${c.events} event${c.events === 1 ? '' : 's'}${c.description ? ' · ' + esc(c.description) : ''}</p></div>
        <div class="fwm-card__actions">
          ${c.can_manage ? `<button class="icon-btn" type="button" data-act="edit" aria-label="Edit" data-tip="Edit">${icon('pen')}</button>` : ''}
          ${c.can_manage && c.type === 'personal' ? `<button class="icon-btn" type="button" data-act="share" aria-label="Share" data-tip="Share">${icon('share')}</button>` : ''}
          ${c.can_manage ? `<button class="icon-btn" type="button" data-act="feed" aria-label="Subscribe on your phone" data-tip="Subscribe link">${icon('rss')}</button>` : ''}
          <a class="icon-btn" href="/calendar/api/export.php?calendar_id=${c.id}" aria-label="Export .ics" data-tip="Export .ics">${icon('download')}</a>
          ${c.can_manage ? `<button class="icon-btn" type="button" data-act="delete" aria-label="Delete" data-tip="Delete" data-tip-pos="left">${icon('trash')}</button>` : ''}
        </div></div></div>`;
  }
  function renderCalendars() {
    const mine = C.calendars.filter((c) => c.type === 'personal' && (c.mine || !c.owner));
    const shared = C.calendars.filter((c) => !mine.includes(c));
    $('#calendarsList').innerHTML = `<div class="fwm-section-title">Yours</div>${mine.map(calCard).join('') || '<div class="hint">None yet.</div>'}
      ${shared.length ? `<div class="fwm-section-title">Shared</div>${shared.map(calCard).join('')}` : ''}`;
  }
  $('#calendarsList').addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const c = C.calendars.find((x) => x.id === +b.closest('[data-cal]').dataset.cal);
    if (!c) return;
    ({ edit: calendarDialog, share: shareDialog, feed: feedDialog, delete: deleteCalendar })[b.dataset.act](c);
  });
  $('#addCalendar').addEventListener('click', () => calendarDialog(null));

  // ---------------------------------------------------------- sources
  const SOURCE_HINTS = {
    projects: 'Start and due dates of the projects you can open.',
    tasks: 'Board items you are on, from their start to their due date.',
    invoices: 'Unpaid invoices on their due date — overdue ones in red.',
    quotes: 'Sent quotes on the day they expire.',
    deals: 'Your open CRM deals on their expected close date.',
    payroll: 'Pay dates of payroll runs.',
  };
  function renderSources() {
    $('#sourcesList').innerHTML = C.sources.map((s) => {
      const on = C.settings.sources[s.id] !== false;
      const off = !s.available;
      return `<div class="fwm-card" style="${off ? 'opacity:.6' : ''}"><div class="fwm-card__head">
          <span class="fwc-link__icon" style="background:color-mix(in srgb, ${esc(s.color)} 14%, transparent);color:${esc(s.color)}">${icon(s.icon)}</span>
          <div class="fwm-card__title"><h3>${esc(s.label)}</h3><p>${esc(SOURCE_HINTS[s.id] || '')}${off ? (s.id === 'payroll' ? ' Available to admins and bookkeepers when the company turns it on.' : ' Turned off for the company.') + (isAdmin ? ' <a href="/admin/calendar.php">Company defaults</a>' : '') : ''}</p></div>
          <label class="switch" style="padding:0"><input type="checkbox" data-src="${s.id}" ${on && !off ? 'checked' : ''} ${off ? 'disabled' : ''} aria-label="Show ${esc(s.label)}"><span class="switch__track"></span></label>
        </div></div>`;
    }).join('');
  }
  $('#sourcesList').addEventListener('change', (e) => {
    const id = e.target.dataset.src;
    if (!id) return;
    C.settings.sources[id] = e.target.checked;
    save({ sources: { [id]: e.target.checked } });
  });

  // ---------------------------------------------------------- import & export
  function renderImport() {
    const addable = C.calendars.filter((c) => c.can_add);
    $('#importPanel').innerHTML = `
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('upload')}Import an .ics file</h3>
        <p>Export from Google Calendar (<i>Settings → Import &amp; export</i>), Outlook or Apple Calendar, then choose the file here. Importing the same file again updates events instead of duplicating them.</p></div></div>
        <div class="fwm-card__body"><div class="row"><div class="field"><label class="label">Into</label>
          <select class="select" id="importCal">${addable.map((c) => `<option value="${c.id}" ${c.id === defaultCalId() ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></div>
          <div class="field"><button class="btn btn--primary" type="button" id="importBtn">${icon('upload', 'i--sm')}Choose file…</button></div></div></div></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('download')}Export</h3><p>Download a calendar as an .ics file to keep or to open elsewhere.</p></div></div>
        <div class="fwm-card__body" style="display:flex;flex-wrap:wrap;gap:8px">${C.calendars.map((c) => `<a class="btn btn--sm" href="/calendar/api/export.php?calendar_id=${c.id}"><span class="fwc-swatch-check" style="--c:${esc(c.color)};width:12px;height:12px;border-radius:4px"></span>${esc(c.name)}</a>`).join('')}</div></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${icon('rss')}See Flowwork events on your phone</h3>
        <p>Each calendar you own has a private subscription link for Google, Apple or Outlook calendars. Open <a href="#calendars">Calendars</a> and choose the ${icon('rss', 'i--xs')} button.</p></div></div></div>`;
    const btn = $('#importBtn');
    if (btn) btn.addEventListener('click', () => pickImport(+$('#importCal').value));
  }

  async function refreshCalendars() {
    try { C.calendars = (await api.get('calendars.php')).calendars; } catch (e) { toastError(e); }
    renderCalendars(); renderImport(); renderGeneral();
  }
  C.onChange = refreshCalendars;

  renderGeneral(); renderCalendars(); renderSources(); renderImport();
  showTab();
})();
