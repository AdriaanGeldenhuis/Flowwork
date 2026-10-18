/* =============================================================================
   Flowwork Projects — home
   Portfolio (cards · table · timeline) with live stats, My work across every
   board (overdue → today → this week → later), search, and the sidebar with
   starred and recent boards. Routes: #portfolio · #mywork · #archive · #search?q=
   ============================================================================= */
(function () {
  'use strict';

  const {
    $, $$, esc, icon, debounce, narrow, api, toast, toastError, popover, closePop, dialog, toggleDrawer,
    P, BOOT, faces, personName, ymd, parseYmd, today, addDays, daysBetween, MONTHS, mon3, fmtDate, fmtRange, relDay, fmtMoney,
    STATUS_NAMES, STATUS_COLORS, projectStatusPill, monogram, ring, emptyState, projectDialog, safeColor,
  } = window.FWP;

  const store = {
    get: (k, d) => { try { const v = localStorage.getItem('fwp.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: (k, v) => { try { localStorage.setItem('fwp.' + k, JSON.stringify(v)); } catch (e) { /* private mode */ } },
  };

  const S = {
    route: 'portfolio',
    projects: BOOT.projects || [],
    archived: null,
    side: BOOT.side || { starred: [], recent: [] },
    summary: BOOT.summary || { overdue: 0, today: 0, week: 0, open: 0, projects: 0 },
    filter: store.get('filter', 'open'),
    layout: store.get('layout', 'cards'),
    sort: store.get('sort', { key: 'recent', asc: false }),
    mywork: null,
    mwFilter: 'all',
    mwCollapsed: new Set(store.get('mwCollapsed', [])),
    q: '',
    results: null,
  };
  const el = { head: $('#head'), tools: $('#tools'), view: $('#view'), progress: $('#progress'), navMain: $('#navMain'), navStar: $('#navStar'),
               navRecent: $('#navRecent'), navProjects: $('#navProjects'), starHead: $('#starHead'), recentHead: $('#recentHead'), foot: $('#sideFoot'),
               search: $('#searchInput'), searchClear: $('#searchClear') };
  const busy = (on) => el.progress.classList.toggle('is-on', !!on);
  const OPEN = ['draft', 'active', 'on_hold'];

  // ===========================================================================
  // Routing
  // ===========================================================================
  function readRoute() {
    const h = location.hash.replace(/^#/, '');
    const qi = h.indexOf('?');
    const base = qi >= 0 ? h.slice(0, qi) : h;
    const params = new URLSearchParams(qi >= 0 ? h.slice(qi + 1) : '');
    if (base === 'search') { S.route = 'search'; S.q = params.get('q') || ''; if (el.search) { el.search.value = S.q; el.searchClear.hidden = !S.q; } }
    else if (base === 'mywork') S.route = 'mywork';
    else if (base === 'archive') S.route = 'archive';
    else { S.route = 'portfolio'; if (params.get('status')) S.filter = params.get('status'); }
    if (S.route !== 'search' && el.search && document.activeElement !== el.search) { el.search.value = ''; el.searchClear.hidden = true; }
    render();
  }
  const go = (hash) => { if (location.hash === hash) readRoute(); else location.hash = hash; };
  window.addEventListener('hashchange', readRoute);

  function render() {
    renderSide();
    el.view.classList.remove('is-entering');
    void el.view.offsetWidth;
    el.view.classList.add('is-entering');
    if (S.route === 'mywork') return renderMyWork();
    if (S.route === 'search') return renderSearch();
    if (S.route === 'archive') return renderArchive();
    return renderPortfolio();
  }

  // ===========================================================================
  // Sidebar
  // ===========================================================================
  function renderSide() {
    const open = S.projects.filter((p) => OPEN.includes(p.status));
    const navItem = (route, ic, label, count, extra = '') => `<a class="fwm-nav__item ${S.route === route ? 'is-active' : ''}" href="#${route}">${icon(ic)}<span class="fwm-nav__label">${label}</span>${extra}<span class="fwm-nav__count">${count || ''}</span></a>`;
    el.navMain.innerHTML = navItem('portfolio', 'grid', 'Portfolio', open.length)
      + navItem('mywork', 'check-square', 'My work', S.summary.open, S.summary.overdue ? `<span class="fwp-badge fwp-badge--danger" title="Overdue">${S.summary.overdue}</span>` : '')
      + navItem('archive', 'archive', 'Archive', '');
    const boardLink = (b) => `<a class="fwm-nav__item" href="${esc(b.url)}" title="${esc(b.project)}"><span class="fwp-dot fwp-dot--sq" style="--c:${safeColor(b.color)}"></span><span class="fwm-nav__label">${esc(b.title)}</span></a>`;
    el.starHead.hidden = !S.side.starred.length;
    el.navStar.innerHTML = S.side.starred.map(boardLink).join('');
    el.recentHead.hidden = !S.side.recent.length;
    el.navRecent.innerHTML = S.side.recent.map(boardLink).join('');
    const list = open.slice().sort((a, b) => (b.starred - a.starred) || a.name.localeCompare(b.name));
    el.navProjects.innerHTML = list.map((p) => `<a class="fwm-nav__item" href="${esc(p.url)}"><span class="fwp-dot" style="--c:${safeColor(p.color)}"></span><span class="fwm-nav__label">${esc(p.name)}</span><span class="fwm-nav__count" title="Open items you are on">${p.stats.mine || ''}</span></a>`).join('')
      || '<div class="fwm-nav__empty">No open projects yet.</div>';
    el.foot.innerHTML = `<div class="fwp-side__links"><a href="/calendar/?view=month">${icon('calendar')}Project dates in Calendar</a></div>`;
  }

  // ===========================================================================
  // Portfolio
  // ===========================================================================
  const FILTERS = [['open', 'All open'], ['active', 'Active'], ['draft', 'Planning'], ['on_hold', 'On hold'], ['completed', 'Completed'], ['mine', 'Mine'], ['all', 'Everything']];
  function filtered() {
    let list = S.projects.slice();
    const f = S.filter;
    if (f === 'open') list = list.filter((p) => OPEN.includes(p.status));
    else if (f === 'mine') list = list.filter((p) => OPEN.includes(p.status) && p.is_mine); // members is only the faces preview
    else if (f !== 'all') list = list.filter((p) => p.status === f);
    const { key, asc } = S.sort;
    const val = (p) => ({ name: p.name.toLowerCase(), due: p.end || '9999', progress: p.progress, overdue: p.stats.overdue, budget: p.budget, status: p.status,
                          client: (p.client || '~').toLowerCase(), recent: p.last_opened || p.updated_at || '' }[key]);
    list.sort((a, b) => {
      if (key === 'recent' && a.starred !== b.starred) return b.starred - a.starred;
      const x = val(a), y = val(b);
      const c = x < y ? -1 : x > y ? 1 : 0;
      return asc ? c : -c;
    });
    return list;
  }

  function renderPortfolio() {
    const list = filtered();
    const open = S.projects.filter((p) => OPEN.includes(p.status));
    el.head.innerHTML = `<div class="fwp-head__title"><span class="fwm-list__eyebrow">Portfolio · ${open.length} open</span><h2><span>Projects</span></h2></div>
      <div class="fwp-head__actions">
        <div class="seg" id="layoutSeg" role="tablist" aria-label="Layout">
          <button type="button" data-l="cards" class="${S.layout === 'cards' ? 'is-on' : ''}" role="tab">${icon('grid', 'i--sm')}<span class="hide-narrow">Cards</span></button>
          <button type="button" data-l="table" class="${S.layout === 'table' ? 'is-on' : ''}" role="tab">${icon('table', 'i--sm')}<span class="hide-narrow">Table</span></button>
          <button type="button" data-l="timeline" class="${S.layout === 'timeline' ? 'is-on' : ''}" role="tab">${icon('timeline', 'i--sm')}<span class="hide-narrow">Timeline</span></button>
        </div>
        <button class="btn btn--primary hide-narrow" id="headNew" type="button" ${P.canCreate ? '' : 'disabled'}>${icon('plus')}New project</button>
      </div>`;
    el.tools.hidden = false;
    el.tools.innerHTML = `<div class="fwm-list__filters" style="padding:0;flex:1;min-width:0">${FILTERS.map(([k, l]) => `<button class="fwm-chip ${S.filter === k ? 'is-on' : ''}" data-f="${k}" type="button">${l}</button>`).join('')}</div>
      <button class="fwp-tool" id="sortBtn" type="button">${icon('sort')}<span class="lbl">${{ recent: 'Recent', name: 'Name', due: 'Due date', progress: 'Progress', overdue: 'Overdue', budget: 'Budget', client: 'Client', status: 'Status' }[S.sort.key]}</span></button>`;
    const totals = open.reduce((a, p) => { a.week += p.stats.week; a.overdue += p.stats.overdue; a.items += p.stats.items; a.done += p.stats.done; a.budget += p.budget; return a; }, { week: 0, overdue: 0, items: 0, done: 0, budget: 0 });
    const onHold = open.filter((p) => p.status === 'on_hold').length;
    const kpis = `<div class="fwp-kpis">
        <div class="fwp-kpi"><span class="fwp-kpi__label">${icon('briefcase')}Open projects</span><span class="fwp-kpi__value">${open.length}</span><span class="fwp-kpi__sub">${onHold ? onHold + ' on hold · ' : ''}${totals.items ? Math.round(totals.done * 100 / totals.items) + '% of items done' : 'No items yet'}</span></div>
        <button class="fwp-kpi" type="button" data-go="#mywork"><span class="fwp-kpi__label">${icon('check-square')}My open items</span><span class="fwp-kpi__value">${S.summary.open}</span><span class="fwp-kpi__sub">${S.summary.today} due today · ${S.summary.week} this week</span></button>
        <div class="fwp-kpi"><span class="fwp-kpi__label">${icon('clock')}Due this week</span><span class="fwp-kpi__value">${totals.week}</span><span class="fwp-kpi__sub">Across all open projects</span></div>
        <div class="fwp-kpi ${totals.overdue ? 'is-danger' : 'is-good'}"><span class="fwp-kpi__label">${icon('alert')}Overdue</span><span class="fwp-kpi__value">${totals.overdue}</span><span class="fwp-kpi__sub">${totals.overdue ? 'Items past their due date' : 'Nothing is late — well done'}</span></div>
      </div>`;
    let body;
    if (!S.projects.length) {
      body = emptyState('Your first project', 'A project holds the boards your team works from — a programme, a costing, procurement, snags. Start from a construction template or a simple task board.',
        P.canCreate ? `<button class="btn btn--primary btn--lg" type="button" data-new>${icon('plus')}New project</button>` : '', { eyebrow: 'Projects' });
      el.view.innerHTML = `<div class="fwp-pad">${body}</div>`;
      return;
    }
    if (!list.length) body = emptyState('Nothing here', 'No projects match this filter.', `<button class="btn" type="button" data-f="open">Show all open projects</button>`, { art: false });
    else if (S.layout === 'table') body = tableHTML(list);
    else if (S.layout === 'timeline') body = timelineHTML(list);
    else body = `<div class="fwp-cards">${list.map(cardHTML).join('')}${P.canCreate && S.filter !== 'completed' ? `<button class="fwp-pcard is-new" type="button" data-new>${icon('plus')}<b>New project</b><span>From a template or blank</span></button>` : ''}</div>`;
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-wrap">${kpis}${body}</div></div>`;
    if (S.layout === 'timeline') scrollTimelineToToday();
  }

  function datesLine(p) {
    if (!p.start && !p.end) return '';
    const t = today();
    let tail = '';
    if (p.end && OPEN.includes(p.status)) {
      const n = daysBetween(parseYmd(t), parseYmd(p.end));
      tail = n < 0 ? ` · <b style="color:var(--danger)">${-n} day${n === -1 ? '' : 's'} late</b>` : n === 0 ? ' · due today' : n <= 30 ? ` · ${n} day${n === 1 ? '' : 's'} left` : '';
    }
    return `<span>${icon('calendar')}${esc(fmtRange(p.start || p.end, p.end || p.start))}${tail}</span>`;
  }
  function cardHTML(p, i) {
    const pct = p.progress;
    return `<a class="fwp-pcard is-entering" href="${esc(p.url)}" style="--c:${safeColor(p.color)};--i:${i}" data-pid="${p.id}">
      <div class="fwp-pcard__top">${monogram(p.name, p.color)}
        <div class="fwp-pcard__title">${p.number || p.client ? `<span class="fwp-eyebrow">${esc([p.number, p.client].filter(Boolean).join(' · '))}</span>` : ''}<h3>${esc(p.name)}</h3></div>
        <button class="icon-btn icon-btn--sm fwp-star ${p.starred ? 'is-on' : ''}" type="button" data-star="${p.id}" aria-label="${p.starred ? 'Unstar' : 'Star'}">${icon('star', 'i--sm')}</button>
        <button class="icon-btn icon-btn--sm fwp-pcard__menu" type="button" data-menu="${p.id}" aria-label="Project actions">${icon('more-h', 'i--sm')}</button>
      </div>
      <div class="fwp-pcard__meta">${projectStatusPill(p.status)}${datesLine(p)}${p.site ? `<span title="${esc(p.site)}">${icon('pin')}${esc(p.site.split(',')[0])}</span>` : ''}</div>
      <div class="fwp-pcard__prog"><span class="fwp-bar ${pct >= 100 ? 'is-done' : ''}" style="--p:${pct}%"><i></i></span><b>${pct}%</b></div>
      <div class="fwp-pcard__foot">${faces(p.members, 4, 'fwp-faces--sm')}<span class="grow"></span>
        <span class="fwp-pcard__stats"><span title="Boards"><b>${p.stats.boards}</b> boards</span><span title="Items"><b>${p.stats.items}</b> items</span>${p.stats.overdue ? `<span class="is-danger" title="Overdue items"><b>${p.stats.overdue}</b> late</span>` : ''}</span>
      </div></a>`;
  }
  function tableHTML(list) {
    const th = (k, l, num) => `<th data-sort="${k}" class="${num ? 'is-num' : ''} ${S.sort.key === k ? 'is-sorted' + (S.sort.asc ? ' is-asc' : '') : ''}">${l}</th>`;
    return `<div class="fwp-tbl-wrap"><table class="fwp-tbl"><thead><tr>${th('name', 'Project')}${th('status', 'Status')}${th('client', 'Client')}<th>Manager</th>${th('progress', 'Progress')}${th('due', 'Due')}${th('overdue', 'Late', true)}${th('budget', 'Budget', true)}<th></th></tr></thead><tbody>
      ${list.map((p) => `<tr data-href="${esc(p.url)}" data-pid="${p.id}">
        <td><div class="fwp-tbl__name">${monogram(p.name, p.color, 'fwp-mono--sm')}<div><b>${esc(p.name)}</b><small>${esc(p.number || '')}</small></div></div></td>
        <td>${projectStatusPill(p.status)}</td><td>${esc(p.client || '—')}</td>
        <td>${p.manager_id ? `<span class="fwp-person">${window.FWP.personAvatar(p.manager_id)}<span>${esc(personName(p.manager_id))}</span></span>` : '—'}</td>
        <td><span class="fwp-bar ${p.progress >= 100 ? 'is-done' : ''}" style="--p:${p.progress}%"><i></i></span><span class="fwp-num">${p.progress}%</span></td>
        <td>${p.end ? `<span class="fwp-date ${OPEN.includes(p.status) && p.end < today() ? 'is-overdue' : ''}">${esc(fmtDate(p.end))}</span>` : '—'}</td>
        <td class="is-num">${p.stats.overdue ? `<b style="color:var(--danger)">${p.stats.overdue}</b>` : '<span class="fwp-muted">0</span>'}</td>
        <td class="is-num">${p.budget ? esc(fmtMoney(p.budget, { short: true })) : '—'}</td>
        <td><button class="icon-btn icon-btn--sm" type="button" data-menu="${p.id}" aria-label="Project actions">${icon('more-h', 'i--sm')}</button></td></tr>`).join('')}
      </tbody></table></div>`;
  }
  function timelineHTML(list) {
    const dated = list.filter((p) => p.start || p.end);
    if (!dated.length) return emptyState('No dates yet', 'Give your projects a start and due date to see them on a timeline.', '', { art: false });
    const t = parseYmd(today());
    let min = dated.reduce((m, p) => { const d = parseYmd(p.start || p.end); return d < m ? d : m; }, t);
    let max = dated.reduce((m, p) => { const d = parseYmd(p.end || p.start); return d > m ? d : m; }, t);
    min = new Date(min.getFullYear(), min.getMonth(), 1);
    max = new Date(max.getFullYear(), max.getMonth() + 1, 0);
    const months = [];
    for (let d = new Date(min); d <= max && months.length < 36; d = new Date(d.getFullYear(), d.getMonth() + 1, 1)) months.push(new Date(d));
    const end = new Date(months[months.length - 1].getFullYear(), months[months.length - 1].getMonth() + 1, 0);
    const span = daysBetween(min, end) + 1;
    const pos = (d) => Math.max(0, Math.min(100, daysBetween(min, d) * 100 / span));
    const monthCols = months.map((m) => `${new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate()}fr`).join(' ');
    return `<div class="fwp-ptl" id="ptl"><div class="fwp-ptl__grid" style="min-width:${Math.max(900, 230 + months.length * 90)}px">
        <div class="fwp-ptl__months" style="grid-template-columns:${monthCols}">${months.map((m) => `<div>${mon3(m)}${m.getMonth() === 0 || m === months[0] ? ' ' + m.getFullYear() : ''}</div>`).join('')}</div>
        ${dated.map((p) => {
          const s = parseYmd(p.start || p.end), e = parseYmd(p.end || p.start);
          const left = pos(s), width = Math.max(1.2, pos(addDays(e, 1)) - left);
          return `<div class="fwp-ptl__row" data-href="${esc(p.url)}"><div class="fwp-ptl__label">${monogram(p.name, p.color, 'fwp-mono--sm')}<b>${esc(p.name)}</b></div>
            <div class="fwp-ptl__track"><span class="fwp-ptl__bar" style="--c:${safeColor(p.color)};left:${left}%;width:${width}%;--p:${p.progress}%" title="${esc(fmtRange(p.start || p.end, p.end || p.start))} · ${p.progress}%"><i></i><span>${p.progress}%</span></span>
            ${t >= min && t <= end ? `<span class="fwp-ptl__today" style="left:${pos(t)}%"></span>` : ''}</div></div>`;
        }).join('')}
      </div></div>`;
  }
  function scrollTimelineToToday() {
    const box = $('#ptl');
    const td = box && $('.fwp-ptl__today', box);
    if (box && td) box.scrollLeft = Math.max(0, td.getBoundingClientRect().left - box.getBoundingClientRect().left - box.clientWidth / 3);
  }

  function projectMenu(anchor, p) {
    // Only what the server allows this person: details and archive for managers, delete for owners.
    const lv = p.level || 0;
    const canEdit = lv >= 3;
    const end = `${canEdit ? (p.archived ? `<button class="fwm-pop__item" data-v="restore">${icon('undo')}Restore</button>` : `<button class="fwm-pop__item" data-v="archive">${icon('archive')}Archive</button>`) : ''}
      ${lv >= 4 ? `<button class="fwm-pop__item is-danger" data-v="delete">${icon('trash')}Delete…</button>` : ''}`;
    popover(anchor, `<a class="fwm-pop__item" data-v="open" href="${esc(p.url)}">${icon('external')}Open</a>
      ${canEdit ? `<button class="fwm-pop__item" data-v="edit">${icon('pen')}Details…</button>` : ''}
      <button class="fwm-pop__item" data-v="star">${icon('star')}${p.starred ? 'Unstar' : 'Star'}</button>
      ${lv >= 2 && P.canCreate ? `<button class="fwm-pop__item" data-v="dup">${icon('copy')}Duplicate</button>` : ''}
      ${end.trim() ? `<div class="fwm-pop__sep"></div>${end}` : ''}`, {
      align: 'end',
      onClick: (it) => { projectAction(it.dataset.v, p); },
    });
  }
  async function projectAction(a, p) {
    try {
      if (a === 'edit') { if (await projectDialog(p)) await refreshProjects(); }
      if (a === 'star') { await api.post('projects.php', { action: 'star', id: p.id, on: !p.starred }); p.starred = !p.starred; render(); }
      if (a === 'dup') {
        const d = await api.post('projects.php', { action: 'duplicate', id: p.id });
        toast('Project copied', { action: { label: 'Open', run: () => { location.href = d.url; } } });
        await refreshProjects();
      }
      if (a === 'archive' || a === 'restore') {
        await api.post('projects.php', { action: 'update', id: p.id, status: a === 'archive' ? 'archived' : 'completed' });
        toast(a === 'archive' ? `“${p.name}” archived` : `“${p.name}” restored`, a === 'archive' ? { action: { label: 'Undo', run: () => projectAction('restore', p) } } : {});
        S.archived = null;
        await refreshProjects();
      }
      if (a === 'delete') {
        const ok = await dialog({ title: `Delete “${p.name}”?`, body: `Its ${p.stats.boards} board${p.stats.boards === 1 ? '' : 's'}, ${p.stats.items} item${p.stats.items === 1 ? '' : 's'}, files and updates are deleted for good. Quotes and invoices stay, without the project. Archiving keeps everything instead.`, confirm: 'Delete project', danger: true });
        if (!ok) return;
        await api.post('projects.php', { action: 'delete', id: p.id });
        toast('Project deleted');
        S.archived = null;
        await refreshProjects();
      }
    } catch (x) { toastError(x); }
  }
  async function refreshProjects() {
    busy(true);
    try {
      const d = await api.get('projects.php', { status: 'all' });
      S.projects = d.projects;
      render();
    } catch (x) { toastError(x); } finally { busy(false); }
  }

  // ===========================================================================
  // Archive
  // ===========================================================================
  async function renderArchive() {
    el.head.innerHTML = `<div class="fwp-head__title"><span class="fwm-list__eyebrow">Projects</span><h2><span>Archive</span></h2></div>`;
    el.tools.hidden = true;
    if (!S.archived) {
      el.view.innerHTML = '<div class="fwp-pad"><div class="fwp-skel-row"><div></div><div></div><div></div><div></div><div></div></div></div>';
      busy(true);
      try { S.archived = (await api.get('projects.php', { archived: 1 })).projects; } catch (x) { toastError(x); S.archived = []; } finally { busy(false); }
      if (S.route !== 'archive') return;
    }
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-wrap">${S.archived.length ? `<div class="fwp-cards">${S.archived.map(cardHTML).join('')}</div>`
      : emptyState('The archive is empty', 'Archived projects keep all their boards and history, out of the way. Archive a finished project from its menu.', '', { art: false })}</div></div>`;
  }

  // ===========================================================================
  // My work
  // ===========================================================================
  const BUCKETS = [
    ['overdue', 'Overdue', 'var(--danger)'], ['today', 'Today', 'var(--accent-ink)'], ['week', 'This week', 'var(--gold-ink)'],
    ['next', 'Next week', 'var(--ink)'], ['later', 'Later', 'var(--ink-2)'], ['nodate', 'Without a date', 'var(--ink-3)'],
  ];
  function bucketOf(it) {
    if (!it.due) return 'nodate';
    const t = parseYmd(today());
    const d = parseYmd(it.due);
    const n = daysBetween(t, d);
    if (n < 0) return 'overdue';
    if (n === 0) return 'today';
    const endWeek = addDays(t, 7 - (t.getDay() || 7));
    if (d <= endWeek) return 'week';
    if (d <= addDays(endWeek, 7)) return 'next';
    return 'later';
  }
  async function loadMyWork() {
    busy(true);
    try {
      const d = await api.get('mywork.php');
      S.mywork = d.items;
      const c = { overdue: 0, today: 0, week: 0 };
      S.mywork.forEach((it) => { const b = bucketOf(it); if (b === 'overdue') c.overdue++; if (b === 'today') c.today++; if (b === 'week' || b === 'today') c.week++; });
      S.summary = Object.assign(S.summary, { open: S.mywork.length, overdue: c.overdue, today: c.today });
    } catch (x) { toastError(x); S.mywork = S.mywork || []; } finally { busy(false); }
  }
  async function renderMyWork() {
    const t = parseYmd(today());
    el.head.innerHTML = `<div class="fwp-head__title"><span class="fwm-list__eyebrow">${esc(window.FWP.DOW[t.getDay()])} ${t.getDate()} ${esc(MONTHS[t.getMonth()])}</span><h2><span>My work</span></h2></div>
      <div class="fwp-head__actions"><a class="btn btn--sm" href="/calendar/?view=week">${icon('calendar')}<span class="hide-narrow">In Calendar</span></a></div>`;
    el.tools.hidden = false;
    const chips = [['all', 'Everything'], ['overdue', 'Overdue'], ['today', 'Today'], ['week', 'This week'], ['nodate', 'No date']];
    el.tools.innerHTML = `<div class="fwm-list__filters" style="padding:0;flex:1;min-width:0">${chips.map(([k, l]) => `<button class="fwm-chip ${S.mwFilter === k ? 'is-on' : ''}" data-mw="${k}" type="button">${l}</button>`).join('')}</div>`;
    if (!S.mywork) {
      el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw">${'<div class="fwm-skel"><div class="fwm-skel__a"></div><div><div class="fwm-skel__l" style="width:60%"></div><div class="fwm-skel__l" style="width:35%"></div></div></div>'.repeat(5)}</div></div>`;
      await loadMyWork();
      if (S.route !== 'mywork') return;
      renderSide();
    }
    const items = S.mywork.filter((it) => {
      if (S.mwFilter === 'all') return true;
      const b = bucketOf(it);
      return S.mwFilter === 'week' ? ['today', 'week'].includes(b) : b === S.mwFilter;
    });
    if (!S.mywork.length) {
      el.view.innerHTML = `<div class="fwp-pad">${emptyState('Nothing on your plate', 'When someone puts you in a People column on any board, the item shows up here — sorted by when it is due.', `<a class="btn" href="#portfolio">${icon('grid')}Go to the portfolio</a>`, { eyebrow: 'All clear' })}</div>`;
      return;
    }
    const groups = BUCKETS.map(([k, label, color]) => ({ k, label, color, list: items.filter((it) => bucketOf(it) === k) })).filter((g) => g.list.length);
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw">${groups.map((g) => `<section class="fwp-mw__sec ${S.mwCollapsed.has(g.k) ? 'is-collapsed' : ''}" data-b="${g.k}">
        <div class="fwp-mw__head" style="--c:${g.color}" role="button" tabindex="0">${icon('chev-down')}<h3>${g.label}</h3><span class="fwp-badge">${g.list.length}</span></div>
        <div class="fwp-mw__list">${g.list.map(mwRow).join('')}</div></section>`).join('') || emptyState('Nothing here', 'No items in this filter.', '', { art: false })}</div></div>`;
  }
  function stBlock(st) {
    if (!st) return `<span class="fwp-st is-empty"><span>Set status</span></span>`;
    return `<span class="fwp-st ${st.done ? 'is-done' : ''}" style="--c:${safeColor(st.color)}"><span>${esc(st.label)}</span></span>`;
  }
  function mwRow(it) {
    const overdue = it.due && it.due < today() && !it.done;
    return `<div class="fwp-mwrow ${it.done ? 'is-done' : ''}" style="--c:${safeColor(it.color)}" data-iid="${it.id}" data-href="${esc(it.url)}">
      <div class="fwp-mwrow__status">${it.status_column ? `<button class="fwp-cell is-editable fwp-cell--status" style="border:0;background:none;width:100%;height:44px;padding:5px 0" data-st="${it.id}" type="button" aria-label="Change status">${stBlock(it.status)}</button>` : stBlock(it.status)}</div>
      <div class="fwp-mwrow__main"><b>${esc(it.title)}</b><small><span class="fwp-dot" style="--c:${safeColor(it.color)};width:7px;height:7px"></span>${esc(it.project)}<i>›</i>${esc(it.board)}${it.group ? `<i>›</i>${esc(it.group)}` : ''}</small></div>
      <span class="fwp-date ${overdue ? 'is-overdue' : it.due === today() ? 'is-today' : ''}">${it.due ? icon('calendar') + esc(relDay(it.due)) : '<span class="fwp-muted">No date</span>'}</span>
      <a class="icon-btn icon-btn--sm" href="${esc(it.url)}" aria-label="Open the item">${icon('chev-right', 'i--sm')}</a></div>`;
  }
  function statusMenu(anchor, it) {
    popover(anchor, `<div class="fwp-pick fwp-pick--status"><div class="fwm-pop__title">${esc(it.board)}</div><div class="fwp-statuses">
      ${it.statuses.map((l) => `<button class="fwp-st ${it.status && it.status.key === l.key ? 'is-current' : ''} ${l.done ? 'is-done' : ''}" style="--c:${safeColor(l.color)}" data-v="${esc(l.key)}" type="button"><span>${esc(l.label)}</span></button>`).join('')}
      <button class="fwp-st is-empty" data-v="" type="button"><span>Clear</span></button></div></div>`, {
      onClick: async (b) => {
        const prev = it.status ? it.status.key : null;
        const key = b.dataset.v || null;
        await setStatus(it, key);
        const lbl = it.statuses.find((l) => l.key === key);
        if (lbl && lbl.done) toast(`“${it.title}” is ${lbl.label.toLowerCase()}`, { action: { label: 'Undo', run: () => setStatus(it, prev) } });
      },
    });
  }
  async function setStatus(it, key) {
    try {
      await api.post('items.php', { action: 'set', id: it.id, column_id: it.status_column, value: key });
      const l = it.statuses.find((x) => x.key === key);
      it.status = l ? Object.assign({}, l) : null;
      it.done = !!(l && l.done);
      if (S.route === 'mywork') renderMyWork();
    } catch (x) { toastError(x); }
  }

  // ===========================================================================
  // Search
  // ===========================================================================
  const runSearch = debounce(async () => {
    const q = S.q;
    if (q.length < 2) { S.results = null; if (S.route === 'search') renderSearch(); return; }
    busy(true);
    try {
      const d = await api.get('search.php', { q });
      if (q !== S.q) return;
      S.results = d;
      if (S.route === 'search') renderSearch();
    } catch (x) { toastError(x); } finally { busy(false); }
  }, 260);
  function renderSearch() {
    el.head.innerHTML = `<div class="fwp-head__title"><span class="fwm-list__eyebrow">Search</span><h2><span>${S.q ? `“${esc(S.q)}”` : 'Search'}</span></h2></div>`;
    el.tools.hidden = true;
    const r = S.results;
    if (!r) { el.view.innerHTML = `<div class="fwp-pad">${emptyState('Search everything', 'Type at least two letters to search project names, numbers, clients, board names and the items on every board you can open.', '', { art: false })}</div>`; return; }
    const total = r.projects.length + r.boards.length + r.items.length;
    if (!total) { el.view.innerHTML = `<div class="fwp-pad">${emptyState('No matches', `Nothing matches “${esc(S.q)}”.`, '', { art: false })}</div>`; return; }
    const sec = (title, rows) => rows ? `<div class="fwp-sec"><h3>${title}</h3></div><div class="fwp-panelcard">${rows}</div>` : '';
    el.view.innerHTML = `<div class="fwp-pad"><div class="fwp-mw">
      ${sec('Projects', r.projects.map((p) => `<a class="fwp-due" href="${esc(p.url)}">${monogram(p.name, p.color, 'fwp-mono--sm')}<div class="fwp-due__main"><b>${esc(p.name)}</b><small>${esc(p.number || '')} ${esc(STATUS_NAMES[p.status] || '')}</small></div>${icon('chev-right')}</a>`).join(''))}
      ${sec('Boards', r.boards.map((b) => `<a class="fwp-due" href="${esc(b.url)}"><span class="fwp-btile__icon">${icon('grid')}</span><div class="fwp-due__main"><b>${esc(b.title)}</b><small>${esc(b.project)}</small></div>${icon('chev-right')}</a>`).join(''))}
      ${sec('Items', r.items.map((i) => `<a class="fwp-due" href="${esc(i.url)}"><span class="fwp-dot" style="--c:${safeColor(i.color)};margin:0 auto"></span><div class="fwp-due__main"><b>${esc(i.title)}</b><small>${esc(i.project)} › ${esc(i.board)}${i.status ? ' · ' + esc(i.status) : ''}</small></div>${i.due ? `<span class="fwp-date">${esc(fmtDate(i.due))}</span>` : ''}</a>`).join(''))}
    </div></div>`;
  }

  // ===========================================================================
  // Events
  // ===========================================================================
  const newProject = () => { if (P.canCreate) projectDialog(null); else toast('Your role cannot create projects.', { type: 'error' }); };
  $('#newProjectBtn').addEventListener('click', newProject);
  $('#newProjectSide').addEventListener('click', newProject);
  $('#fabNew').addEventListener('click', newProject);

  document.addEventListener('click', (e) => {
    const t = e.target;
    const star = t.closest('[data-star]');
    if (star) {
      e.preventDefault(); e.stopPropagation();
      const p = S.projects.find((x) => x.id === Number(star.dataset.star));
      if (p) projectAction('star', p);
      return;
    }
    const menu = t.closest('[data-menu]');
    if (menu) {
      e.preventDefault(); e.stopPropagation();
      const id = Number(menu.dataset.menu);
      const p = S.projects.find((x) => x.id === id) || (S.archived || []).find((x) => x.id === id);
      if (p) projectMenu(menu, p);
      return;
    }
    const st = t.closest('[data-st]');
    if (st) {
      e.preventDefault(); e.stopPropagation();
      const it = (S.mywork || []).find((x) => x.id === Number(st.dataset.st));
      if (it) statusMenu(st, it);
      return;
    }
    if (t.closest('[data-new]')) { newProject(); return; }
    const f = t.closest('[data-f]');
    if (f) { S.filter = f.dataset.f; store.set('filter', S.filter); if (S.route !== 'portfolio') go('#portfolio'); else renderPortfolio(); return; }
    const l = t.closest('#layoutSeg [data-l]');
    if (l) { S.layout = l.dataset.l; store.set('layout', S.layout); renderPortfolio(); return; }
    const mw = t.closest('[data-mw]');
    if (mw) { S.mwFilter = mw.dataset.mw; renderMyWork(); return; }
    const head = t.closest('.fwp-mw__head');
    if (head) {
      const k = head.closest('[data-b]').dataset.b;
      if (S.mwCollapsed.has(k)) S.mwCollapsed.delete(k); else S.mwCollapsed.add(k);
      store.set('mwCollapsed', Array.from(S.mwCollapsed));
      head.closest('[data-b]').classList.toggle('is-collapsed');
      return;
    }
    const goEl = t.closest('[data-go]');
    if (goEl) { go(goEl.dataset.go); return; }
    const th = t.closest('th[data-sort]');
    if (th) { const k = th.dataset.sort; S.sort = { key: k, asc: S.sort.key === k ? !S.sort.asc : !['progress', 'overdue', 'budget', 'recent'].includes(k) }; store.set('sort', S.sort); renderPortfolio(); return; }
    const href = t.closest('[data-href]');
    if (href && !t.closest('a, button')) { location.href = href.dataset.href; return; }
    if (t.closest('#headNew')) newProject();
    if (t.closest('#sortBtn')) {
      const opts = [['recent', 'Recently active'], ['name', 'Name'], ['due', 'Due date'], ['progress', 'Progress'], ['overdue', 'Most overdue'], ['budget', 'Budget'], ['client', 'Client']];
      popover(t.closest('#sortBtn'), `<div class="fwm-pop__title">Sort by</div>${opts.map(([k, lbl]) => `<button class="fwm-pop__item ${S.sort.key === k ? 'is-active' : ''}" data-v="${k}">${lbl}${S.sort.key === k ? '<small>✓</small>' : ''}</button>`).join('')}`, {
        onClick: (it) => { const k = it.dataset.v; S.sort = { key: k, asc: ['name', 'due', 'client'].includes(k) }; store.set('sort', S.sort); renderPortfolio(); },
      });
    }
  });
  document.addEventListener('keydown', (e) => {
    if (e.target.closest('.fwp-mw__head') && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); e.target.click(); return; }
    if (e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, textarea, select, [contenteditable="true"]') || window.FWP.activePop) return;
    if (document.querySelector('.fwm-modal:not([hidden])')) return;
    if (e.key === 'c') { e.preventDefault(); newProject(); }
    if (S.route === 'portfolio' && ['1', '2', '3'].includes(e.key)) { S.layout = ['cards', 'table', 'timeline'][+e.key - 1]; store.set('layout', S.layout); renderPortfolio(); }
    if (e.key === 'g') {
      const once = (ev) => { document.removeEventListener('keydown', once, true); if (ev.key === 'p') go('#portfolio'); if (ev.key === 'w') go('#mywork'); };
      document.addEventListener('keydown', once, true);
    }
  });

  if (el.search) {
    $('#searchForm').addEventListener('submit', (e) => { e.preventDefault(); runSearch(); });
    el.search.addEventListener('input', () => {
      S.q = el.search.value.trim();
      el.searchClear.hidden = !S.q;
      if (S.q) history.replaceState(null, '', '#search?q=' + encodeURIComponent(S.q)); // every keystroke: a reload or a link finds the whole query
      if (S.q) { S.route = 'search'; renderSide(); renderSearch(); runSearch(); }
      else go('#portfolio');
    });
    el.search.addEventListener('keydown', (e) => { if (e.key === 'Escape') { el.search.value = ''; el.search.dispatchEvent(new Event('input')); el.search.blur(); } });
    el.searchClear.addEventListener('click', () => { el.search.value = ''; el.search.dispatchEvent(new Event('input')); el.search.focus(); });
  }
  document.addEventListener('click', (e) => { if (narrow() && e.target.closest('#side a')) toggleDrawer(false); });

  // Back from a project: refresh the numbers (cheap) when the tab regains focus.
  let lastRefresh = Date.now();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && Date.now() - lastRefresh > 60000) {
      lastRefresh = Date.now();
      if (S.route === 'mywork') { S.mywork = null; renderMyWork(); } else refreshProjects();
    }
  });

  readRoute();
  if (S.route === 'search' && S.q) runSearch();
})();
