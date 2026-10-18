/* =============================================================================
   Flowwork Projects — a project
   Overview (progress, timeline, budget, team, boards, due soon, events,
   activity), Schedule (dates and events), Money (budget, quotes, invoices,
   costs, hours), Files, Team, Activity and Settings. Tabs switch in place and
   keep ?tab= in the address so links and reloads land on the same tab.
   ============================================================================= */
(function () {
  'use strict';

  const {
    $, $$, esc, icon, debounce, narrow, api, toast, toastError, popover, closePop, dialog, tempModal, openModal, closeModal,
    P, BOOT, avatar, faces, personName, personAvatar, ymd, parseYmd, parseDT, today, addDays, daysBetween, MONTHS, DOW, mon3, fmtDate, fmtRange, relDay, relTime,
    fmtMoney, fmtNum, STATUS_NAMES, STATUS_COLORS, projectStatusPill, monogram, ring, emptyState, projectDialog, boardDialog, renderProjectSide,
    safeColor, fileKind, isImage, fmtSize, pickPeople, BOARD_ICONS, activityHTML,
  } = window.FWP;

  const S = {
    tab: BOOT.tab || 'overview',
    project: BOOT.project,
    boards: BOOT.boards || [],
    breakdown: BOOT.breakdown || {},
    due: BOOT.due || [],
    members: BOOT.members || [],
    events: BOOT.events || [],
    activity: BOOT.activity || [],
    money: null,
    files: null,
    schedule: null,
    fileBoard: 0,
    activityMore: true,
  };
  if (!S.project) return;
  const p = () => S.project;
  const level = () => p().level || 0;
  const canEdit = () => level() >= 2;
  const canManage = () => level() >= 3;
  const el = { head: $('#head'), view: $('#view'), progress: $('#progress') };
  const busy = (on) => el.progress.classList.toggle('is-on', !!on);

  // ===========================================================================
  // Tabs
  // ===========================================================================
  function setTab(tab, push = true) {
    S.tab = tab;
    const u = new URL(location.href);
    if (tab === 'overview') u.searchParams.delete('tab'); else u.searchParams.set('tab', tab);
    if (push) history.pushState({ tab }, '', u.pathname + u.search); else history.replaceState({ tab }, '', u.pathname + u.search);
    render();
    el.view.scrollTop = 0;
  }
  window.addEventListener('popstate', () => { S.tab = new URL(location.href).searchParams.get('tab') || 'overview'; render(); });

  function side() {
    renderProjectSide({ project: p(), boards: S.boards, active: 'tab:' + S.tab, onTab: (t) => setTab(t),
                        boardsChanged: (ids) => { S.boards.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id)); } });
  }

  function head() {
    const pr = p();
    const crumbs = [pr.number, pr.client].filter(Boolean).join(' · ');
    el.head.innerHTML = `<div class="fwp-head__title"><span class="fwm-list__eyebrow">${esc(crumbs || 'Project')}</span>
        <h2><span>${esc(pr.name)}</span>${projectStatusPill(pr.status)}</h2></div>
      <div class="fwp-head__actions">
        <button class="icon-btn fwp-star ${pr.starred ? 'is-on' : ''}" id="starBtn" type="button" aria-label="${pr.starred ? 'Unstar' : 'Star'}" data-tip="${pr.starred ? 'Starred' : 'Star'}">${icon('star')}</button>
        ${faces(S.members.filter((m) => m.active !== false).map((m) => m.id), 5)}
        ${canManage() ? `<button class="btn btn--sm hide-narrow" id="editBtn" type="button">${icon('pen')}Details</button>` : ''}
        <button class="icon-btn" id="moreBtn" type="button" aria-label="More">${icon('more-h')}</button>
      </div>`;
  }

  function render() {
    side();
    head();
    el.view.classList.remove('is-entering');
    void el.view.offsetWidth;
    el.view.classList.add('is-entering');
    const fn = { overview: renderOverview, schedule: renderSchedule, money: renderMoney, files: renderFiles, team: renderTeam, activity: renderActivity, settings: renderSettings }[S.tab] || renderOverview;
    fn();
  }

  // ===========================================================================
  // Overview
  // ===========================================================================
  function timelineCard() {
    const pr = p();
    if (!pr.start && !pr.end) return `<div class="fwp-hero__card"><span class="fwp-kpi__label">${icon('calendar')}Timeline</span><div class="fwp-hero__dates fwp-muted">No dates yet</div>${canManage() ? `<button class="fwp-link" data-act="edit" type="button">${icon('pen')}Add dates</button>` : ''}</div>`;
    const t = parseYmd(today());
    const s = parseYmd(pr.start || pr.end), e = parseYmd(pr.end || pr.start);
    const total = Math.max(1, daysBetween(s, e));
    const gone = Math.max(0, Math.min(total, daysBetween(s, t)));
    const pct = Math.round(gone * 100 / total);
    const left = daysBetween(t, e);
    const open = ['draft', 'active', 'on_hold'].includes(pr.status);
    const sub = !open ? STATUS_NAMES[pr.status] : left < 0 ? `<b style="color:var(--danger)">${-left} days overdue</b>` : left === 0 ? 'Due today' : `${left} day${left === 1 ? '' : 's'} to go`;
    return `<div class="fwp-hero__card"><span class="fwp-kpi__label">${icon('calendar')}Timeline</span>
      <div class="fwp-hero__dates">${esc(fmtDate(s))}<i>→</i>${esc(fmtDate(e))}</div>
      <span class="fwp-bar ${left < 0 && open ? 'is-over' : ''}" style="--p:${pct}%"><i></i></span><span class="fwp-kpi__sub">${sub} · ${pct}% of the time used</span></div>`;
  }
  function budgetCard() {
    const pr = p();
    const m = S.money;
    if (!m) return `<div class="fwp-hero__card" id="budgetCard"><span class="fwp-kpi__label">${icon('coins')}Budget</span><span class="fwp-kpi__value">${pr.budget ? esc(fmtMoney(pr.budget, { short: true })) : '—'}</span><span class="fwp-kpi__sub">Working out costs…</span></div>`;
    const used = m.budget_used;
    return `<div class="fwp-hero__card" id="budgetCard" style="cursor:pointer" data-tab-go="money"><span class="fwp-kpi__label">${icon('coins')}Budget</span>
      <span class="fwp-kpi__value">${m.budget ? esc(fmtMoney(m.actual_cost, { short: true })) : esc(fmtMoney(m.actual_cost, { short: true }))}<small>${m.budget ? 'of ' + esc(fmtMoney(m.budget, { short: true })) : 'spent'}</small></span>
      ${m.budget ? `<span class="fwp-bar ${used > 100 ? 'is-over' : ''}" style="--p:${Math.min(100, used || 0)}%"><i></i></span>` : ''}
      <span class="fwp-kpi__sub">${m.budget ? `${used}% used · ` : ''}${esc(fmtMoney(m.invoiced, { short: true }))} invoiced</span></div>`;
  }
  function battery(bd, total) {
    if (!bd || !total) return '<div class="fwp-battery fwp-battery--thin"></div>';
    const parts = bd.labels.map((l) => `<i style="--c:${safeColor(l.color)};--n:${l.n}" title="${esc(l.label)}: ${l.n}"></i>`).join('');
    const empty = bd.empty ? `<i style="--c:var(--line-strong);--n:${bd.empty}" title="No status: ${bd.empty}"></i>` : '';
    return `<div class="fwp-battery fwp-battery--thin">${parts}${empty}</div>`;
  }
  function boardTile(b) {
    const bd = S.breakdown[b.id];
    const pct = b.items ? Math.round(b.done * 100 / b.items) : 0;
    return `<a class="fwp-btile" href="${esc(b.url)}">
      <div class="fwp-btile__top"><span class="fwp-btile__icon">${icon(window.FWP.boardIcon(b))}</span>
        <div style="min-width:0;flex:1"><b>${esc(b.title)}</b><small>${b.items} ${esc(b.item_noun)}${b.items === 1 ? '' : 's'} · ${pct}% done${bd && bd.overdue ? ` · <span style="color:var(--danger)">${bd.overdue} late</span>` : ''}</small></div></div>
      ${battery(bd, b.items)}</a>`;
  }
  function dueRow(d) {
    const dd = parseYmd(d.due);
    const late = d.due < today();
    return `<a class="fwp-due ${late ? 'is-overdue' : ''}" href="${esc(d.url)}"><span class="fwp-due__date"><b>${dd.getDate()}</b><small>${mon3(dd)}</small></span>
      <span class="fwp-due__main"><b>${esc(d.title)}</b><small>${esc(d.board)} · ${late ? 'overdue' : esc(relDay(d.due))}</small></span>${faces(d.people, 2, 'fwp-faces--sm')}</a>`;
  }
  function eventRow(ev) {
    const d = parseDT(ev.start_datetime);
    return `<a class="fwp-due" href="${esc(ev.url)}"><span class="fwp-due__date"><b>${d.getDate()}</b><small>${mon3(d)}</small></span>
      <span class="fwp-due__main"><b>${esc(ev.title)}</b><small>${ev.all_day ? 'All day' : window.FWP.pad(d.getHours()) + ':' + window.FWP.pad(d.getMinutes())} · ${esc(relDay(ymd(d)))}</small></span>${icon('chev-right')}</a>`;
  }
  function renderOverview() {
    const pr = p();
    const boards = S.boards.filter((b) => !b.archived);
    const st = pr.stats;
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-wrap">
      <div class="fwp-hero">
        <div class="fwp-hero__card"><span class="fwp-kpi__label">${icon('gauge')}Progress</span>
          <div class="fwp-hero__row">${ring(pr.progress, 64)}<div><span class="fwp-kpi__value" style="font-size:24px">${st.done}<small>of ${st.items} done</small></span><span class="fwp-kpi__sub">${st.overdue ? `<b style="color:var(--danger)">${st.overdue} overdue</b> · ` : ''}${st.week} due this week</span></div></div></div>
        ${timelineCard()}
        ${budgetCard()}
        <div class="fwp-hero__card" style="cursor:pointer" data-tab-go="team"><span class="fwp-kpi__label">${icon('team')}Team</span>
          <div class="fwp-hero__row">${faces(S.members.filter((m) => m.active !== false).map((m) => m.id), 5)}</div>
          <span class="fwp-kpi__sub">${S.members.length} member${S.members.length === 1 ? '' : 's'}${pr.manager_id ? ' · ' + esc(personName(pr.manager_id)) + ' manages' : ''}${pr.visibility === 'company' ? ' · open to the company' : ''}</span></div>
      </div>
      <div class="fwp-cols" style="margin-top:22px">
        <div>
          <div class="fwp-sec"><h3>Boards<small>${boards.length}</small></h3>${canManage() ? `<button class="btn btn--sm" type="button" data-act="newboard">${icon('plus')}New board</button>` : ''}</div>
          ${boards.length ? `<div class="fwp-btiles">${boards.map(boardTile).join('')}${canManage() ? `<button class="fwp-btile is-new" type="button" data-act="newboard">${icon('plus')}<span>New board</span></button>` : ''}</div>`
            : emptyState('No boards yet', 'Boards hold the work: tasks, a programme, a costing, procurement, snags. Start from a blueprint.', canManage() ? `<button class="btn btn--primary" type="button" data-act="newboard">${icon('plus')}New board</button>` : '', { art: false })}
          <div class="fwp-sec"><h3>Due soon</h3><a class="fwp-link" href="/projects/#mywork">${icon('check-square')}My work</a></div>
          <div class="fwp-panelcard">${S.due.length ? S.due.map(dueRow).join('') : '<div class="fwp-panelcard__empty">Nothing is due in the next two weeks.</div>'}</div>
          ${pr.description ? `<div class="fwp-sec"><h3>About</h3></div><div class="fwp-panelcard" style="padding:16px 18px;white-space:pre-wrap;line-height:1.6;color:var(--ink-2)">${esc(pr.description)}</div>` : ''}
        </div>
        <div>
          <div class="fwp-sec"><h3>Coming up</h3><a class="fwp-link" href="/calendar/?new=1&link=project:${pr.id}&title=${encodeURIComponent(pr.name + ' — ')}">${icon('calendar-plus')}Add event</a></div>
          <div class="fwp-panelcard">${S.events.length ? S.events.map(eventRow).join('') : `<div class="fwp-panelcard__empty">No meetings or site visits planned.</div>`}</div>
          <div class="fwp-sec"><h3>Latest activity</h3><button class="fwp-link" type="button" data-tab-go="activity">${icon('history')}All</button></div>
          <div class="fwp-panelcard">${activityHTML(S.activity.slice(0, 10), false) || '<div class="fwp-panelcard__empty">Nothing has happened yet.</div>'}</div>
        </div>
      </div></div></div>`;
    if (!S.money) loadMoney(true);
  }

  // ===========================================================================
  // Activity
  // ===========================================================================
  async function renderActivity() {
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw"><div class="fwp-panelcard" id="actList">${activityHTML(S.activity) || '<div class="fwp-panelcard__empty">Nothing has happened yet.</div>'}</div>
      <div style="display:flex;justify-content:center;padding:18px 0">${S.activityMore && S.activity.length >= 20 ? `<button class="btn" type="button" id="actMore">Show earlier</button>` : ''}</div></div></div>`;
    const more = $('#actMore');
    if (more) more.addEventListener('click', async () => {
      more.classList.add('is-busy');
      try {
        const last = S.activity[S.activity.length - 1];
        const d = await api.get('activity.php', { project_id: p().id, before: last ? last.id : 0, limit: 50 });
        S.activity = S.activity.concat(d.activity);
        S.activityMore = d.activity.length === 50;
        renderActivity();
      } catch (x) { toastError(x); more.classList.remove('is-busy'); }
    });
  }

  // ===========================================================================
  // Schedule
  // ===========================================================================
  async function renderSchedule() {
    const pr = p();
    const t = parseYmd(today());
    // Built from the dates the project has, so the range never ends before it starts
    // (only an old due date, or only a start far ahead).
    const s0 = pr.start ? parseYmd(pr.start) : (pr.end && parseYmd(pr.end) < t ? parseYmd(pr.end) : t);
    const e0 = pr.end ? parseYmd(pr.end) : addDays(s0 > t ? s0 : t, 120);
    let a = addDays(s0, -14), b = addDays(e0 < s0 ? s0 : e0, 30);
    // calendar.php answers about a year at a time: a longer project shows the stretch around today.
    if (daysBetween(a, b) > 395) { a = new Date(Math.max(a, Math.min(addDays(t, -60), addDays(b, -395)))); b = addDays(a, 395); }
    const from = ymd(a);
    const to = ymd(b);
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw">
      <div class="fwp-sec"><h3>Schedule</h3><a class="fwp-link" href="/calendar/?new=1&link=project:${pr.id}&title=${encodeURIComponent(pr.name + ' — ')}">${icon('calendar-plus')}Add event</a><a class="fwp-link" href="/calendar/?view=month&date=${esc(today())}">${icon('calendar')}Open Calendar</a></div>
      <div id="sched"><div class="fwm-skel"><div class="fwm-skel__a"></div><div><div class="fwm-skel__l" style="width:55%"></div><div class="fwm-skel__l" style="width:30%"></div></div></div></div></div></div>`;
    if (!S.schedule) {
      busy(true);
      try { S.schedule = await api.get('calendar.php', { project_id: pr.id, from, to: ymd(new Date(Math.min(parseYmd(to), addDays(parseYmd(from), 395)))) }); }
      catch (x) { toastError(x); S.schedule = { events: [], items: [] }; } finally { busy(false); }
      if (S.tab !== 'schedule') return;
    }
    const rows = [];
    (S.schedule.items || []).forEach((it) => rows.push({ date: it.start, title: it.title, sub: (it.sub || (it.source === 'projects' ? 'Project date' : '')) + (it.done ? ' · done' : ''), url: it.url, kind: it.source === 'projects' ? 'flag' : 'check-square', done: !!it.done }));
    (S.schedule.events || []).forEach((ev) => rows.push({ date: String(ev.start).slice(0, 10), title: ev.title, sub: ev.all_day ? 'All day' : String(ev.start).slice(11, 16) + ' · ' + (ev.calendar_name || 'Event'), url: '/calendar/?event=' + ev.id + '&date=' + String(ev.start).slice(0, 10), kind: 'calendar' }));
    rows.sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0));
    const box = $('#sched');
    if (!rows.length) { box.innerHTML = emptyState('Nothing scheduled', 'Due dates on this project’s boards, the project’s start and finish, and calendar events linked to it appear here.', '', { art: false }); return; }
    let lastMonth = '';
    box.innerHTML = rows.map((r) => {
      const d = parseYmd(r.date);
      const m = MONTHS[d.getMonth()] + ' ' + d.getFullYear();
      const hdr = m !== lastMonth ? `<div class="fwp-act-day" style="padding-top:20px">${esc(m)}</div>` : '';
      lastMonth = m;
      const late = r.kind === 'check-square' && !r.done && r.date < today();
      return `${hdr}<a class="fwp-due ${late ? 'is-overdue' : ''}" href="${esc(r.url)}" style="background:var(--glass-strong);margin-bottom:4px;box-shadow:var(--shadow-card)"><span class="fwp-due__date"><b>${d.getDate()}</b><small>${DOW[d.getDay()].slice(0, 3)}</small></span>
        <span class="fwp-due__main"><b>${esc(r.title)}</b><small>${esc(r.sub)}</small></span>${icon(r.kind)}</a>`;
    }).join('');
  }

  // ===========================================================================
  // Money
  // ===========================================================================
  async function loadMoney(fillOverview) {
    try {
      S.money = (await api.get('money.php', { project_id: p().id })).money;
      if (fillOverview && S.tab === 'overview') { const c = $('#budgetCard'); if (c) c.outerHTML = budgetCard(); }
    } catch (x) { if (!fillOverview) toastError(x); }
  }
  const docStatus = (s) => {
    const c = { draft: '#85766b', sent: '#2f5c84', viewed: '#7a5ab8', paid: '#2f8a5f', overdue: '#c2412f', cancelled: '#85766b', accepted: '#2f8a5f', converted: '#2f8a5f', declined: '#c2412f', expired: '#85766b' }[s] || '#85766b';
    return `<span class="fwp-pill fwp-pill--soft" style="--c:${c}">${esc(s.charAt(0).toUpperCase() + s.slice(1))}</span>`;
  };
  async function renderMoney() {
    const pr = p();
    if (!S.money) {
      el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-wrap"><div class="fwp-kpis">${'<div class="fwp-kpi"><div class="fwm-skel__l" style="width:50%"></div><div class="fwm-skel__l" style="width:70%;height:26px"></div></div>'.repeat(4)}</div></div></div>`;
      busy(true);
      await loadMoney(false);
      busy(false);
      if (S.tab !== 'money' || !S.money) return;
    }
    const m = S.money;
    const qiNew = (kind) => `/qi/${kind}_new.php?project_id=${pr.id}${pr.client_id ? '&customer_id=' + pr.client_id : ''}`;
    const costParts = [['Finance (bills, receipts)', m.gl_cost, '#7a1f30'], ['Recorded here', m.manual_cost, '#b08d57'], ['Labour (timesheets)', m.labour_cost, '#2f5c84']].filter((x) => x[1] > 0);
    const totalCost = m.actual_cost || 0;
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-wrap">
      <div class="fwp-kpis">
        <div class="fwp-kpi"><span class="fwp-kpi__label">${icon('target')}Budget</span><span class="fwp-kpi__value">${m.budget ? esc(fmtMoney(m.budget, { short: true })) : '—'}</span><span class="fwp-kpi__sub">${m.budget ? 'Excluding VAT' : canManage() ? '<button class="fwp-link" data-act="edit" type="button">Set a budget</button>' : 'No budget set'}</span></div>
        <div class="fwp-kpi ${m.budget_used > 100 ? 'is-danger' : ''}"><span class="fwp-kpi__label">${icon('wallet')}Actual cost</span><span class="fwp-kpi__value">${esc(fmtMoney(totalCost, { short: true }))}</span>
          ${m.budget ? `<span class="fwp-bar ${m.budget_used > 100 ? 'is-over' : ''}" style="--p:${Math.min(100, m.budget_used || 0)}%"><i></i></span>` : ''}<span class="fwp-kpi__sub">${m.budget ? `${m.budget_used}% of budget` : 'Costs so far'}${m.committed ? ' · ' + esc(fmtMoney(m.committed, { short: true })) + ' in bills not yet posted' : ''}${m.tracked && m.budget ? ` · <span style="${m.tracked > m.budget ? 'color:var(--danger);font-weight:600' : ''}">boards: ${Math.round(m.tracked * 100 / m.budget)}%</span>` : ''}</span></div>
        <div class="fwp-kpi"><span class="fwp-kpi__label">${icon('receipt')}Invoiced</span><span class="fwp-kpi__value">${esc(fmtMoney(m.invoiced, { short: true }))}</span><span class="fwp-kpi__sub">${esc(fmtMoney(m.paid, { short: true }))} paid · ${esc(fmtMoney(m.outstanding, { short: true }))} outstanding</span></div>
        <div class="fwp-kpi ${m.margin < 0 ? 'is-danger' : m.margin > 0 ? 'is-good' : ''}"><span class="fwp-kpi__label">${icon('gauge')}Margin</span><span class="fwp-kpi__value">${esc(fmtMoney(m.margin, { short: true }))}</span><span class="fwp-kpi__sub">${m.invoiced ? Math.round(m.margin * 100 / m.invoiced) + '% of invoiced' : 'Invoiced minus costs'}</span></div>
      </div>
      ${costParts.length ? `<div class="fwp-panelcard" style="padding:16px 18px"><span class="fwp-kpi__label">${icon('pie')}Where the money went</span>
        <div class="fwp-money-bar">${costParts.map(([l, v, c]) => `<i style="background:${c};width:${(v * 100 / totalCost).toFixed(2)}%" title="${esc(l)}: ${esc(fmtMoney(v))}"></i>`).join('')}</div>
        <div class="fwp-legend">${costParts.map(([l, v, c]) => `<span><i style="--c:${c}"></i>${esc(l)} · ${esc(fmtMoney(v, { short: true }))}</span>`).join('')}${m.labour_hours ? `<span>${icon('clock', 'i--xs')}${fmtNum(m.labour_hours, 1)} hours logged</span>` : ''}</div></div>` : ''}

      ${(m.board_costs || []).length ? `<div class="fwp-panelcard" style="padding:14px 18px;margin-top:14px"><span class="fwp-kpi__label">${icon('table')}Tracked on boards · ${esc(fmtMoney(m.tracked, { short: true }))}</span>
        <div class="fwp-legend" style="margin-top:8px">${m.board_costs.map((b) => `<a href="${esc(b.url)}"><i style="--c:#b08d57"></i>${esc(b.board)} · ${esc(b.column)}: <b>${esc(fmtMoney(b.total))}</b></a>`).join('')}</div>
        <p class="hint" style="margin:8px 0 0">As entered on the boards. Shown beside the actual cost, not added to it — a receipt posted to a board is in both.</p></div>` : ''}

      <div class="fwp-sec"><h3>Invoices<small>${m.invoices.length}</small></h3><a class="btn btn--sm" href="${esc(qiNew('invoice'))}">${icon('plus')}New invoice</a></div>
      ${m.invoices.length ? `<div class="fwp-tbl-wrap"><table class="fwp-tbl"><thead><tr><th>Number</th><th>Customer</th><th>Date</th><th>Status</th><th class="is-num">Excl. VAT</th><th class="is-num">Balance</th></tr></thead><tbody>
        ${m.invoices.map((v) => `<tr data-href="${esc(v.url)}"><td><b>${esc(v.number)}</b></td><td>${esc(v.customer)}</td><td>${esc(fmtDate(v.date))}</td><td>${docStatus(v.status)}</td><td class="is-num">${esc(fmtMoney(v.subtotal))}</td><td class="is-num">${v.status === 'paid' ? '<span class="fwp-muted">Paid</span>' : esc(fmtMoney(v.balance || v.total))}</td></tr>`).join('')}</tbody></table></div>`
        : `<div class="fwp-panelcard"><div class="fwp-panelcard__empty">No invoices on this project yet.</div></div>`}

      <div class="fwp-sec"><h3>Quotes<small>${m.quotes.length}</small></h3><a class="btn btn--sm" href="${esc(qiNew('quote'))}">${icon('plus')}New quote</a></div>
      ${m.quotes.length ? `<div class="fwp-tbl-wrap"><table class="fwp-tbl"><thead><tr><th>Number</th><th>Customer</th><th>Date</th><th>Status</th><th class="is-num">Excl. VAT</th></tr></thead><tbody>
        ${m.quotes.map((q) => `<tr data-href="${esc(q.url)}"><td><b>${esc(q.number)}</b></td><td>${esc(q.customer)}</td><td>${esc(fmtDate(q.date))}</td><td>${docStatus(q.status)}</td><td class="is-num">${esc(fmtMoney(q.subtotal))}</td></tr>`).join('')}</tbody></table></div>`
        : `<div class="fwp-panelcard"><div class="fwp-panelcard__empty">No quotes on this project yet.</div></div>`}

      <div class="fwp-sec"><h3>Costs recorded here<small>${esc(fmtMoney(m.manual_cost))}</small></h3>${canEdit() ? `<button class="btn btn--sm" type="button" data-act="cost">${icon('plus')}Add cost</button>` : ''}</div>
      ${m.cost_items.length ? `<div class="fwp-tbl-wrap"><table class="fwp-tbl"><thead><tr><th>Description</th><th>Type</th><th>Item</th><th>Supplier</th><th class="is-num">Qty</th><th class="is-num">Unit</th><th class="is-num">Amount</th><th></th></tr></thead><tbody>
        ${m.cost_items.map((c) => `<tr data-cost="${c.id}"><td><b>${esc(c.description)}</b><br><small class="fwp-muted">${esc(c.by)} · ${esc(fmtDate(String(c.at).slice(0, 10)))}</small></td><td>${esc(c.type)}</td><td>${esc(c.item || '—')}</td><td>${esc(c.supplier || '—')}</td>
          <td class="is-num">${fmtNum(c.qty, c.qty % 1 ? 2 : 0)}</td><td class="is-num">${esc(fmtMoney(c.unit_cost))}</td><td class="is-num"><b>${esc(fmtMoney(c.amount))}</b></td>
          <td>${canEdit() ? `<button class="icon-btn icon-btn--sm" type="button" data-costmenu="${c.id}" aria-label="Cost actions">${icon('more-h', 'i--sm')}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
        : `<div class="fwp-panelcard"><div class="fwp-panelcard__empty">Record materials, hire and other costs that do not go through Finance.</div></div>`}

      ${m.finance === false ? `<p class="hint" style="margin:14px 2px 0">Costs posted in Finance, bills awaiting posting and labour cost from timesheets are shown to the company’s finance roles.</p>` : ''}
      ${m.gl_lines.length ? `<div class="fwp-sec"><h3>Costs from Finance<small>${esc(fmtMoney(m.gl_cost))}</small></h3><a class="fwp-link" href="/finances/reports.php">${icon('external')}Reports</a></div>
        <div class="fwp-tbl-wrap"><table class="fwp-tbl"><thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Account</th><th class="is-num">Amount</th></tr></thead><tbody>
        ${m.gl_lines.map((l) => `<tr style="cursor:default"><td>${esc(fmtDate(l.date))}</td><td>${esc(l.reference)}</td><td>${esc(l.description)}</td><td>${esc(l.account)}</td><td class="is-num">${esc(fmtMoney(l.amount))}</td></tr>`).join('')}</tbody></table></div>` : ''}
    </div></div>`;
  }
  async function costDialog(c) {
    const items = [];
    try {
      for (const b of S.boards.filter((x) => !x.archived).slice(0, 12)) {
        const d = await api.get('pickers.php', { type: 'items', board_id: b.id });
        d.items.slice(0, 300).forEach((it) => items.push({ id: it.id, label: b.title + ' › ' + it.title }));
      }
    } catch (x) { /* items optional */ }
    // The cost's own item stays choosable (archived, or past the list's limits): saving must not unlink it.
    if (c && c.item_id && !items.some((it) => it.id === c.item_id)) items.unshift({ id: c.item_id, label: c.item || 'Item #' + c.item_id });
    const m = tempModal(`<div class="fwm-dialog__head"><h2>${c ? 'Edit cost' : 'Add a cost'}</h2><button class="icon-btn" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="costForm" autocomplete="off"><div class="fwp-form-grid">
        <div class="field is-wide"><label class="label" for="cDesc">Description</label><input class="input" id="cDesc" maxlength="2000" value="${esc(c ? c.description : '')}" placeholder="e.g. 50 bags of cement" autofocus></div>
        <div class="field"><label class="label" for="cType">Type</label><select class="select" id="cType">${['material', 'labour', 'equipment', 'other'].map((t) => `<option value="${t}" ${c && c.type === t ? 'selected' : ''}>${t.charAt(0).toUpperCase() + t.slice(1)}</option>`).join('')}</select></div>
        <div class="field"><label class="label" for="cItem">For item (optional)</label><select class="select" id="cItem"><option value="">—</option>${items.map((it) => `<option value="${it.id}" ${c && c.item_id === it.id ? 'selected' : ''}>${esc(it.label)}</option>`).join('')}</select></div>
        <div class="field"><label class="label" for="cQty">Quantity</label><input class="input" id="cQty" inputmode="decimal" value="${c ? c.qty : 1}"></div>
        <div class="field"><label class="label" for="cUnit">Unit cost (R, excl. VAT)</label><input class="input" id="cUnit" inputmode="decimal" value="${c ? c.unit_cost : ''}" placeholder="0.00"></div>
      </div></form>
      <div class="fwm-dialog__foot"><span class="fwp-err" id="cErr"></span><button class="btn" data-close type="button">Cancel</button><button class="btn btn--primary" id="cSave" type="button">${c ? 'Save' : 'Add cost'}</button></div>`, '', 'width:min(560px,100%)');
    const go = async () => {
      try {
        // supplier_id: an edit keeps the supplier the cost already has (the dialog does not show it).
        const d = await api.post('money.php', { action: 'cost_save', project_id: p().id, id: c ? c.id : undefined, description: $('#cDesc', m).value, type: $('#cType', m).value,
                                                item_id: Number($('#cItem', m).value) || 0, qty: $('#cQty', m).value, unit_cost: $('#cUnit', m).value,
                                                supplier_id: c ? (c.supplier_id || 0) : undefined });
        S.money = d.money;
        closeModal(m);
        toast(c ? 'Cost saved' : 'Cost added');
        if (S.tab === 'money') renderMoney();
      } catch (x) { $('#cErr', m).textContent = x.message; }
    };
    $('#cSave', m).addEventListener('click', go);
    $('#costForm', m).addEventListener('submit', (e) => { e.preventDefault(); go(); });
    openModal(m);
  }

  // ===========================================================================
  // Files
  // ===========================================================================
  async function renderFiles() {
    if (!S.files) {
      el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-fgrid">${'<div class="fwp-fcard"><div class="fwp-fcard__thumb"></div><div class="fwp-fcard__meta"><div class="fwm-skel__l" style="width:70%"></div></div></div>'.repeat(6)}</div></div>`;
      busy(true);
      try { S.files = (await api.get('files.php', { project_id: p().id })).files; } catch (x) { toastError(x); S.files = []; } finally { busy(false); }
      if (S.tab !== 'files') return;
    }
    const boards = Array.from(new Map(S.files.map((f) => [f.board_id, f.board])).entries());
    const list = S.files.filter((f) => !S.fileBoard || f.board_id === S.fileBoard);
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-wrap">
      <div class="fwp-sec"><h3>Files<small>${S.files.length}</small></h3>${boards.length > 1 ? `<select class="select" id="fileBoard" style="width:auto;height:34px;order:2"><option value="0">All boards</option>${boards.map(([id, t]) => `<option value="${id}" ${S.fileBoard === id ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select>` : ''}</div>
      ${list.length ? `<div class="fwp-fgrid">${list.map((f) => {
        const [kind, label] = fileKind(f.name, f.mime);
        return `<a class="fwp-fcard" href="/projects/api/files.php?id=${f.id}" target="_blank" rel="noopener"><div class="fwp-fcard__thumb">${isImage(f) ? `<img src="/projects/api/files.php?id=${f.id}" alt="" loading="lazy">` : `<span class="fwm-file__icon fwm-file__icon--${kind}">${esc(label)}</span>`}</div>
          <div class="fwp-fcard__meta"><b title="${esc(f.name)}">${esc(f.name)}</b><small>${esc(f.item)} · ${esc(f.board)}</small><small>${esc(fmtSize(f.size))} · ${esc(f.by)} · ${esc(relTime(f.at))}</small></div></a>`;
      }).join('')}</div>` : emptyState('No files yet', 'Photos, drawings, quotes and delivery notes added to items on this project’s boards collect here.', '', { art: false })}
    </div></div>`;
    const fb = $('#fileBoard');
    if (fb) fb.addEventListener('change', () => { S.fileBoard = Number(fb.value); renderFiles(); });
  }

  // ===========================================================================
  // Team
  // ===========================================================================
  const ROLE_HINTS = { owner: 'Everything, including deleting the project', manager: 'Settings, members, boards and automations', member: 'Edits items, comments and uploads files', viewer: 'Reads and comments' };
  function renderTeam() {
    const pr = p();
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw">
      <div class="fwp-sec"><h3>Team<small>${S.members.length}</small></h3>${canManage() ? `<button class="btn btn--sm btn--primary" type="button" id="addPeople">${icon('plus')}Add people</button>` : ''}</div>
      <div class="fwp-panelcard">${S.members.map((m) => `<div class="fwp-member">${avatar(m.name, m.email)}<div class="fwp-member__who"><b>${esc(m.name)}${m.id === P.me.id ? ' <span class="fwp-muted">(you)</span>' : ''}</b><small>${esc(m.email || '')}${m.active === false ? ' · no longer active' : ''}</small></div>
        ${canManage() && !m.implicit && (m.role !== 'owner' || level() >= 4) ? `<select class="select" data-role="${m.id}" aria-label="Role">${['owner', 'manager', 'member', 'viewer'].map((r) => `<option value="${r}" ${m.role === r ? 'selected' : ''} ${r === 'owner' && level() < 4 ? 'disabled' : ''}>${r.charAt(0).toUpperCase() + r.slice(1)}</option>`).join('')}</select>
          <button class="icon-btn icon-btn--sm" type="button" data-remove="${m.id}" aria-label="Remove from project" data-tip="Remove">${icon('x', 'i--sm')}</button>`
          : `<span class="fwm-badge ${m.role === 'owner' || m.role === 'manager' ? 'fwm-badge--accent' : ''}" title="${esc(ROLE_HINTS[m.role] || '')}">${esc(m.role)}</span>${m.id === P.me.id && !m.implicit && m.role !== 'owner' ? `<button class="btn btn--sm btn--ghost" type="button" data-remove="${m.id}">Leave</button>` : ''}`}</div>`).join('')}</div>
      <div class="fwp-sec"><h3>Who can see this project</h3></div>
      <div class="fwp-panelcard" style="padding:14px 16px">
        <div class="seg" id="visSeg" ${canManage() ? '' : 'aria-disabled="true"'}><button type="button" data-vis="members" class="${pr.visibility !== 'company' ? 'is-on' : ''}" ${canManage() ? '' : 'disabled'}>Project members</button><button type="button" data-vis="company" class="${pr.visibility === 'company' ? 'is-on' : ''}" ${canManage() ? '' : 'disabled'}>Everyone in the company</button></div>
        <p class="hint" style="margin:10px 2px 0">${pr.visibility === 'company' ? 'Everyone in the company can open this project and read its boards; members edit.' : 'Only the people above (and company admins) can open this project.'} Roles: ${Object.entries(ROLE_HINTS).map(([r, h]) => `<b>${r}</b> — ${esc(h.toLowerCase())}`).join('; ')}.</p>
        <p class="hint" style="margin:8px 2px 0">People outside the company can be invited to a single board as read-only guests from that board’s <b>Share</b> button.</p>
      </div></div></div>`;
    const add = $('#addPeople');
    if (add) add.addEventListener('click', () => {
      const have = new Set(S.members.map((m) => m.id));
      pickPeople(add, [], {
        title: 'Add to the project', people: P.people.filter((x) => !have.has(x.id)),
        onChange: async (ids, multi) => {
          if (multi) return;
        },
        onClose: async (ids) => {
          if (!ids.length) return;
          try {
            let d = null;
            for (const id of ids) d = await api.post('projects.php', { action: 'member_set', id: pr.id, user_id: id, role: 'member' });
            S.members = d.members;
            toast(ids.length === 1 ? `${personName(ids[0])} added` : `${ids.length} people added`);
            render();
          } catch (x) { toastError(x); }
        },
      });
    });
  }

  // ===========================================================================
  // Settings
  // ===========================================================================
  function renderSettings() {
    const pr = p();
    const boardsArchived = S.boards.filter((b) => b.archived);
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw">
      <div class="fwp-sec"><h3>Details</h3></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${esc(pr.name)}</h3><p>${esc([pr.number, pr.client, pr.site].filter(Boolean).join(' · ') || 'No number, client or site yet')}</p></div>
        <div class="fwm-card__actions">${canManage() ? `<button class="btn btn--sm" type="button" data-act="edit">${icon('pen')}Edit details</button>` : ''}</div></div>
        <div class="fwm-card__body"><dl class="fwp-kv"><dt>Status</dt><dd>${projectStatusPill(pr.status)}</dd><dt>Dates</dt><dd>${pr.start || pr.end ? esc(fmtRange(pr.start || pr.end, pr.end || pr.start)) : '—'}</dd>
          <dt>Budget</dt><dd>${pr.budget ? esc(fmtMoney(pr.budget)) : '—'}</dd><dt>Manager</dt><dd>${pr.manager_id ? esc(personName(pr.manager_id)) : '—'}</dd>
          <dt>Visible to</dt><dd>${pr.visibility === 'company' ? 'Everyone in the company' : 'Project members'}</dd></dl></div></div>
      ${canManage() ? `<div class="fwp-sec"><h3>Status</h3></div>
      <div class="fwm-card"><div class="fwm-presets" style="margin:0">${Object.entries(STATUS_NAMES).filter(([k]) => k !== 'archived').map(([k, v]) => `<button class="fwm-preset ${pr.status === k ? 'is-on' : ''}" type="button" data-status="${k}"><span class="fwp-dot" style="--c:${STATUS_COLORS[k]}"></span>${v}</button>`).join('')}</div>
        <p class="hint" style="margin:10px 0 0">Completed and cancelled projects leave My work, the Calendar and reminders but keep all their boards.</p></div>` : ''}
      ${boardsArchived.length ? `<div class="fwp-sec"><h3>Archived boards</h3></div><div class="fwp-panelcard">${boardsArchived.map((b) => `<div class="fwp-member"><span class="fwp-btile__icon">${icon('archive')}</span><div class="fwp-member__who"><b>${esc(b.title)}</b><small>${b.items} items</small></div>
        <a class="btn btn--sm btn--ghost" href="${esc(b.url)}">Open</a><button class="btn btn--sm" type="button" data-restoreboard="${b.id}">Restore</button></div>`).join('')}</div>` : ''}
      <div class="fwp-sec"><h3>More</h3></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>Duplicate</h3><p>A copy with the same boards, columns, groups and team — without items.</p></div><button class="btn btn--sm" type="button" data-act="dup">${icon('copy')}Duplicate</button></div></div>
      ${level() >= 4 ? `<div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3>${pr.archived ? 'Restore' : 'Archive'}</h3><p>${pr.archived ? 'Bring the project back to the portfolio.' : 'Hide the project from the portfolio and My work. Nothing is deleted.'}</p></div><button class="btn btn--sm" type="button" data-act="${pr.archived ? 'restore' : 'archive'}">${icon(pr.archived ? 'undo' : 'archive')}${pr.archived ? 'Restore' : 'Archive'}</button></div></div>
      <div class="fwm-card"><div class="fwm-card__head"><div class="fwm-card__title"><h3 style="color:var(--danger)">Delete project</h3><p>Deletes every board, item, update and file for good. Quotes and invoices stay, without the project.</p></div><button class="btn btn--sm btn--danger" type="button" data-act="delete">${icon('trash')}Delete…</button></div></div>` : ''}
    </div></div>`;
  }

  // ===========================================================================
  // Actions
  // ===========================================================================
  async function reloadProject() {
    try {
      const d = await api.get('projects.php', { id: p().id });
      S.project = d.project;
      S.boards = d.boards;
      S.members = d.members;
      S.events = d.events;
      S.activity = d.activity;
      S.breakdown = d.breakdown || {};
      S.due = d.due || [];
      render();
    } catch (x) { toastError(x); }
  }
  async function act(a, target) {
    const pr = p();
    try {
      if (a === 'edit') { if (await projectDialog(pr)) await reloadProject(); }
      else if (a === 'newboard') boardDialog(pr.id);
      else if (a === 'cost') costDialog(null);
      else if (a === 'dup') { const d = await api.post('projects.php', { action: 'duplicate', id: pr.id }); toast('Copy created', { action: { label: 'Open', run: () => { location.href = d.url; } } }); }
      else if (a === 'archive' || a === 'restore') {
        await api.post('projects.php', { action: 'update', id: pr.id, status: a === 'archive' ? 'archived' : 'completed' });
        toast(a === 'archive' ? 'Project archived' : 'Project restored');
        await reloadProject();
      } else if (a === 'delete') {
        const ok = await dialog({ title: `Delete “${pr.name}”?`, body: 'Type the project’s name to confirm. This cannot be undone.', input: { placeholder: pr.name }, confirm: 'Delete project', danger: true });
        if (ok === null) return;
        if (ok.trim().toLowerCase() !== pr.name.trim().toLowerCase()) { toast('The name did not match — nothing was deleted.', { type: 'error' }); return; }
        await api.post('projects.php', { action: 'delete', id: pr.id });
        location.href = '/projects/';
      } else if (a === 'star') {
        await api.post('projects.php', { action: 'star', id: pr.id, on: !pr.starred });
        pr.starred = !pr.starred;
        head();
      }
    } catch (x) { toastError(x); }
  }

  document.addEventListener('click', async (e) => {
    const t = e.target;
    const a = t.closest('[data-act]');
    if (a) { e.preventDefault(); act(a.dataset.act, a); return; }
    const tg = t.closest('[data-tab-go]');
    if (tg && !t.closest('a')) { setTab(tg.dataset.tabGo); return; }
    if (t.closest('#starBtn')) { act('star'); return; }
    if (t.closest('#editBtn')) { act('edit'); return; }
    if (t.closest('#moreBtn')) {
      const b = t.closest('#moreBtn');
      popover(b, `${canManage() ? `<button class="fwm-pop__item" data-v="edit">${icon('pen')}Details…</button><button class="fwm-pop__item" data-v="newboard">${icon('plus')}New board</button>` : ''}
        <a class="fwm-pop__item" data-v="cal" href="/calendar/?new=1&link=project:${p().id}&title=${encodeURIComponent(p().name + ' — ')}">${icon('calendar-plus')}Add a calendar event</a>
        <a class="fwm-pop__item" data-v="inv" href="/qi/invoice_new.php?project_id=${p().id}${p().client_id ? '&customer_id=' + p().client_id : ''}">${icon('receipt')}New invoice</a>
        <a class="fwm-pop__item" data-v="quote" href="/qi/quote_new.php?project_id=${p().id}${p().client_id ? '&customer_id=' + p().client_id : ''}">${icon('file-text')}New quote</a>
        ${p().client_id ? `<a class="fwm-pop__item" data-v="crm" href="/crm/account_view.php?id=${p().client_id}">${icon('building')}Client in CRM</a>` : ''}
        <div class="fwm-pop__sep"></div><button class="fwm-pop__item" data-v="dup">${icon('copy')}Duplicate</button>`, {
        align: 'end', onClick: (it) => { if (['edit', 'newboard', 'dup'].includes(it.dataset.v)) act(it.dataset.v); },
      });
      return;
    }
    const st = t.closest('[data-status]');
    if (st) {
      try { await api.post('projects.php', { action: 'update', id: p().id, status: st.dataset.status }); await reloadProject(); toast('Status updated'); } catch (x) { toastError(x); }
      return;
    }
    const vis = t.closest('#visSeg [data-vis]');
    if (vis && canManage()) {
      try { await api.post('projects.php', { action: 'update', id: p().id, visibility: vis.dataset.vis }); S.project.visibility = vis.dataset.vis; renderTeam(); toast('Visibility updated'); } catch (x) { toastError(x); }
      return;
    }
    const rm = t.closest('[data-remove]');
    if (rm) {
      const uid = Number(rm.dataset.remove);
      const self = uid === P.me.id;
      if (!(await dialog({ title: self ? 'Leave this project?' : `Remove ${personName(uid)}?`, body: self ? 'You will no longer see it unless someone adds you again.' : 'They keep anything they created, but can no longer open the project.', confirm: self ? 'Leave' : 'Remove', danger: true }))) return;
      try {
        const d = await api.post('projects.php', { action: 'member_remove', id: p().id, user_id: uid });
        if (self && !P.admin) { location.href = '/projects/'; return; }
        S.members = d.members;
        renderTeam();
      } catch (x) { toastError(x); }
      return;
    }
    const rb = t.closest('[data-restoreboard]');
    if (rb) { try { await api.post('boards.php', { action: 'restore', id: Number(rb.dataset.restoreboard) }); await reloadProject(); toast('Board restored'); } catch (x) { toastError(x); } return; }
    const cm = t.closest('[data-costmenu]');
    if (cm) {
      const c = S.money.cost_items.find((x) => x.id === Number(cm.dataset.costmenu));
      popover(cm, `<button class="fwm-pop__item" data-v="edit">${icon('pen')}Edit</button><button class="fwm-pop__item is-danger" data-v="del">${icon('trash')}Delete</button>`, {
        align: 'end',
        onClick: async (it) => {
          if (it.dataset.v === 'edit') costDialog(c);
          else {
            try { S.money = (await api.post('money.php', { action: 'cost_delete', project_id: p().id, id: c.id })).money; renderMoney(); toast('Cost deleted'); } catch (x) { toastError(x); }
          }
        },
      });
      return;
    }
    const href = t.closest('tr[data-href]');
    if (href && !t.closest('a, button')) location.href = href.dataset.href;
  });
  document.addEventListener('change', async (e) => {
    const r = e.target.closest('[data-role]');
    if (!r) return;
    try {
      const d = await api.post('projects.php', { action: 'member_set', id: p().id, user_id: Number(r.dataset.role), role: r.value });
      S.members = d.members;
      toast('Role updated');
      renderTeam();
    } catch (x) { toastError(x); renderTeam(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, textarea, select, [contenteditable="true"]') || window.FWP.activePop) return;
    if (document.querySelector('.fwm-modal:not([hidden])')) return;
    const tabs = ['overview', 'schedule', 'money', 'files', 'team', 'activity'];
    if (/^[1-6]$/.test(e.key)) { e.preventDefault(); setTab(tabs[+e.key - 1]); }
    if (e.key === 'b' && canManage()) { e.preventDefault(); boardDialog(p().id); }
  });
  const search = $('#searchInput');
  if (search) {
    $('#searchForm').addEventListener('submit', (e) => { e.preventDefault(); if (search.value.trim()) location.href = '/projects/#search?q=' + encodeURIComponent(search.value.trim()); });
  }
  window.FWP.kebabExtra = () => canManage() ? `<button class="fwm-pop__item" data-v="p-edit">${icon('pen')}Project details…</button><button class="fwm-pop__item" data-v="p-board">${icon('plus')}New board</button>` : '';
  window.FWP.onKebab = (v) => { if (v === 'p-edit') act('edit'); if (v === 'p-board') act('newboard'); };

  render();
})();
