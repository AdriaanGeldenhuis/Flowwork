// /crm/js/crm-app.js — the CRM home: overview, customers, suppliers, pipeline,
// tasks, contacts, activity and compliance.
// Routes (hash): #overview · #customers · #suppliers · #pipeline · #tasks ·
// #contacts · #activity · #compliance, each with ?q=&status=&sort=&dir=&page=…
// Older links (?tab=suppliers, ?type=supplier) become routes on load.
(function () {
  'use strict';
  const C = window.CRM;
  const { $, $$, esc, icon, api, toast, toastError, popover, dialog, BOOT, L, lower, money, money0, compact, fmtDate, fmtTime, relTime, relDay, avatar, statusPill, stageMeta, tagChip, pill } = C;
  const F = () => C.forms;

  const ROUTES = {
    overview: { title: 'Overview', on: () => true },
    customers: { title: () => L('customers'), on: () => BOOT.config.modules.customers, type: 'customer' },
    suppliers: { title: () => L('suppliers'), on: () => BOOT.config.modules.suppliers, type: 'supplier' },
    pipeline: { title: () => L('pipeline'), on: () => BOOT.config.modules.pipeline },
    tasks: { title: () => L('tasks'), on: () => BOOT.config.modules.tasks },
    contacts: { title: () => L('contacts'), on: () => BOOT.config.modules.contacts },
    activity: { title: 'Activity', on: () => BOOT.config.modules.activity },
    compliance: { title: 'Compliance', on: () => BOOT.config.modules.compliance && BOOT.config.modules.suppliers },
  };
  const S = { route: 'overview', params: new URLSearchParams(), seq: 0, selected: new Set(), rows: [], deals: [], board: null };
  const el = { head: $('#head'), tools: $('#tools'), view: $('#view') };
  const title = (r) => (typeof ROUTES[r].title === 'function' ? ROUTES[r].title() : ROUTES[r].title);

  // ------------------------------------------------------------- routing
  function readRoute() {
    const h = location.hash.replace(/^#/, '');
    const i = h.indexOf('?');
    const base = i >= 0 ? h.slice(0, i) : h;
    const landing = BOOT.prefs.landing && ROUTES[BOOT.prefs.landing] && ROUTES[BOOT.prefs.landing].on() ? BOOT.prefs.landing : 'overview';
    S.route = ROUTES[base] && ROUTES[base].on() ? base : landing;
    S.params = new URLSearchParams(i >= 0 ? h.slice(i + 1) : '');
    render();
  }
  function go(route, params = {}, replace = false) {
    const p = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '' && !(k === 'page' && Number(v) === 1) && !(k === 'status' && v === 'all')) p.set(k, v); });
    const hash = '#' + route + (p.toString() ? '?' + p.toString() : '');
    if (replace) { history.replaceState(null, '', hash); readRoute(); }
    else if (location.hash === hash) readRoute();
    else location.hash = hash;
  }
  const param = (k, d = '') => S.params.get(k) || d;
  const withParams = (changes) => { const o = Object.fromEntries(S.params.entries()); Object.assign(o, changes); return o; };
  window.addEventListener('hashchange', readRoute);

  function render() {
    $$('#nav [data-route]').forEach((a) => a.classList.toggle('is-active', a.dataset.route === S.route));
    const cur = S.route + (S.params.toString() ? '?' + S.params.toString() : '');
    $$('#viewsNav a').forEach((a) => a.classList.toggle('is-active', a.getAttribute('href').replace(/^.*#/, '') === cur));
    document.title = title(S.route) + ' · ' + BOOT.company;
    C.toggleDrawer(false);
    F().closeSheet();
    S.selected.clear();
    const newBtn = $('#newBtn');
    if (newBtn) {
      const kind = S.route === 'suppliers' ? 'supplier' : S.route === 'pipeline' ? 'deal' : S.route === 'tasks' ? 'task' : (BOOT.config.modules.customers ? 'customer' : 'supplier');
      newBtn.dataset.new = kind;
      $('#newLabel').textContent = 'New ' + lower(kind);
      const fab = $('#fab');
      if (fab) { fab.dataset.new = kind; $('#fabLabel').textContent = 'New ' + lower(kind); }
    }
    el.view.classList.remove('is-entering');
    void el.view.offsetWidth;
    el.view.classList.add('is-entering');
    el.tools.hidden = true;
    el.tools.innerHTML = '';
    ({ overview: renderOverview, customers: renderAccounts, suppliers: renderAccounts, pipeline: renderPipeline, tasks: renderTasks,
       contacts: renderContacts, activity: renderActivity, compliance: renderCompliance })[S.route]();
  }
  function head(eyebrow, h1, actions = '', count = null) {
    el.head.innerHTML = `<div class="fwc-head__title"><div class="fwc-eyebrow">${esc(eyebrow)}</div><h1>${esc(h1)}${count !== null ? `<small>${esc(count)}</small>` : ''}</h1></div><div class="fwc-head__actions">${actions}</div>`;
  }
  const saveViewBtn = () => `<button class="btn btn--sm btn--ghost" type="button" id="saveView" data-tip="Save this view in the sidebar">${icon('bookmark')}<span>Save view</span></button>`;
  function bindSaveView() {
    const b = $('#saveView');
    if (!b) return;
    b.addEventListener('click', async () => {
      const name = await dialog({ title: 'Save this view', body: 'It appears under “My views” in the sidebar, with the filters you have now.', input: { label: 'Name', placeholder: 'Key accounts in Gauteng', max: 40 }, confirm: 'Save view' });
      if (!name) return;
      try {
        const prefs = await C.api.post('prefs.php', { op: 'view_add', name, route: S.route, query: S.params.toString() });
        BOOT.prefs = prefs.prefs;
        paintViews();
        toast('View saved');
      } catch (e) { toastError(e); }
    });
  }
  function paintViews() {
    const host = $('#viewsNav');
    if (!host) return;
    $('#savedViews').hidden = !BOOT.prefs.views.length;
    host.innerHTML = BOOT.prefs.views.map((v) => `<a class="fwm-nav__item fwc-view-link" href="#${esc(v.route)}${v.query ? '?' + esc(v.query) : ''}" data-view="${esc(v.id)}">${icon('bookmark')}<span class="fwm-nav__label">${esc(v.name)}</span></a>`).join('');
  }
  document.addEventListener('contextmenu', (e) => {
    const a = e.target.closest('[data-view]');
    if (!a) return;
    e.preventDefault();
    popover({ x: e.clientX, y: e.clientY }, `<button class="fwm-pop__item is-danger" type="button" data-v="rm">${icon('trash')}Remove this view</button>`, {
      onClick: async () => { try { const p = await api.post('prefs.php', { op: 'view_remove', id: a.dataset.view }); BOOT.prefs = p.prefs; paintViews(); } catch (er) { toastError(er); } },
    });
  });

  // ============================================================ overview
  async function renderOverview() {
    const h = new Date().getHours();
    const greet = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    head(BOOT.company, 'Overview', `${BOOT.is_admin ? `<a class="btn btn--sm btn--ghost" href="/crm/settings.php#layout">${icon('ic-layout')}<span>Arrange</span></a>` : ''}
      ${BOOT.can_write && BOOT.config.modules.pipeline ? `<button class="btn btn--primary" type="button" data-create="deal">${icon('plus')}<span>New ${esc(lower('deal'))}</span></button>` : ''}`);
    el.head.querySelectorAll('[data-create]').forEach((b) => b.addEventListener('click', () => C.create(b.dataset.create, { onSaved: renderOverview })));
    el.view.innerHTML = `<div class="fwc-ov"><div class="fwc-kpis">${'<div class="fwc-kpi fwc-skel" style="height:138px"></div>'.repeat(4)}</div><div class="fwc-grid">${'<div class="fwc-panel fwc-skel" style="height:240px"></div>'.repeat(4)}</div></div>`;
    const seq = ++S.seq;
    let d;
    try { d = await api.get('overview.php'); } catch (e) { if (seq === S.seq) el.view.innerHTML = C.errorBox(e); return; }
    if (seq !== S.seq) return;
    C.setCount('tasks', (d.tasks.overdue || 0) + (d.tasks.today || 0));
    const p = d.panels;
    const tones = { danger: 'var(--danger)', warning: 'var(--warning)', gold: 'var(--gold)', info: 'var(--info)' };
    const order = BOOT.config.overview.filter((k) => p[k] !== undefined && k !== 'kpis');
    const html = order.map((k) => panel(k, p)).filter(Boolean).join('');
    el.view.innerHTML = `<div class="fwc-ov">
      <div class="fwc-hello"><div><div class="fwc-eyebrow">${esc(fmtDate(BOOT.today, 'always'))}</div>
        <h2>${esc(greet)}${BOOT.me.first_name ? `, <em>${esc(BOOT.me.first_name)}</em>` : ''}.</h2>
        ${d.brief.length ? `<div class="fwc-brief">${d.brief.map((b) => `<a href="${esc(b.href)}" style="--c:${tones[b.tone] || 'var(--gold)'}"><i></i>${esc(b.text)}</a>`).join('')}</div>`
          : `<p>Nothing is overdue and nothing is waiting on you. A good day to call a customer you have not spoken to in a while.</p>`}</div></div>
      ${p.kpis ? `<div class="fwc-kpis">${p.kpis.map(kpi).join('')}</div>` : ''}
      <div class="fwc-grid">${html}</div></div>`;
    bindOverview(el.view, p);
  }
  function kpi(k) {
    const val = k.money ? `<small>R</small>${esc(compact(k.value, ''))}` : esc(String(k.value));
    const ch = k.change != null ? `<span class="${k.change >= 0 ? 'up' : 'down'}">${k.change >= 0 ? '▲' : '▼'} ${Math.abs(k.change)}%</span> vs last month · ` : '';
    return `<a class="fwc-kpi" href="${esc(k.href)}" style="--c:${k.color}"><span class="fwc-kpi__label">${esc(k.label)}</span><span class="fwc-kpi__value">${val}</span><span class="fwc-kpi__note">${ch}${esc(k.note)}</span></a>`;
  }
  function panel(k, p) {
    const box = (titleHtml, link, body, cls = '') => `<section class="fwc-panel ${cls}" data-panel="${k}"><div class="fwc-panel__head"><h3>${titleHtml}</h3>${link || ''}</div>${body}</section>`;
    if (k === 'funnel') {
      const max = Math.max(...p.funnel.map((s) => s.value), 1);
      const total = p.funnel.reduce((a, s) => a + s.value, 0);
      return box(`${esc(L('pipeline'))}<small>${esc(money0(total))}</small>`, `<a href="#pipeline">Open board</a>`, p.funnel.length && total > 0 ? `<div class="fwc-funnel">${p.funnel.map((s) => `
        <a class="fwc-funnel__row" href="#pipeline" style="--c:${esc(s.color)}"><span><i></i><span class="fwc-ellip">${esc(s.label)}</span></span>
          <span class="fwc-funnel__track"><i style="--p:${Math.max(2, (s.value / max) * 100)}%"></i><b>${s.count}</b></span><b>${esc(compact(s.value))}</b></a>`).join('')}</div>`
        : C.emptyState('i-kanban', 'No open ' + lower('deals'), 'Add a ' + lower('deal') + ' to see your pipeline here.', BOOT.can_write ? `<button class="btn btn--sm" type="button" data-create="deal">${icon('plus')}New ${esc(lower('deal'))}</button>` : '', true));
    }
    if (k === 'forecast') {
      const max = Math.max(...p.forecast.map((m) => Math.max(m.won, m.total)), 1);
      const any = p.forecast.some((m) => m.won || m.weighted);
      return box('Won &amp; forecast', `<div class="fwc-legend"><span><i style="background:var(--gold)"></i>Won</span><span><i style="background:color-mix(in srgb,var(--accent) 40%,transparent)"></i>Weighted forecast</span></div>`,
        any ? `<div class="fwc-bars">${p.forecast.map((m) => {
          const tip = m.future ? `${m.label} ${m.year}: ${money0(m.weighted)} weighted of ${money0(m.total)} expected` : `${m.label} ${m.year}: ${money0(m.won)} won` + (m.current && m.weighted ? ` · ${money0(m.weighted)} still expected` : '');
          const showForecast = m.future || m.current;
          return `<div class="fwc-bar${m.current ? ' is-current' : ''}" data-tip="${esc(tip)}"><div class="fwc-bar__stack">
            ${showForecast && m.total ? `<div class="fwc-bar__ghost" style="height:${(m.total / max) * 100}%"></div>` : ''}
            ${m.won ? `<div class="fwc-bar__fill" style="height:${(m.won / max) * 100}%"></div>` : ''}
            ${showForecast && m.weighted ? `<div class="fwc-bar__fill is-forecast" style="height:${(m.weighted / max) * 100}%"></div>` : ''}
            </div><span class="fwc-bar__label">${esc(m.label)}</span></div>`;
        }).join('')}</div>` : C.emptyState('ic-trend', 'Nothing to forecast yet', 'Give open ' + lower('deals') + ' an expected close date and they show up here.', '', true));
    }
    if (k === 'tasks') {
      const rows = p.tasks.rows;
      return box(`My ${esc(lower('tasks'))}${p.tasks.counts.overdue ? `<small style="color:var(--danger)">${p.tasks.counts.overdue} overdue</small>` : ''}`, `<a href="#tasks">All ${esc(lower('tasks'))}</a>`,
        `<div id="ovTasks">${rows.length ? rows.map(F().taskRow).join('') : C.emptyState('i-check-square', 'All caught up', 'Nothing is due. Plan your next follow-up.', '', true)}</div>
        ${BOOT.can_write ? `<button class="btn btn--sm btn--ghost" type="button" data-create="task" style="margin-top:6px">${icon('plus')}Add a ${esc(lower('task'))}</button>` : ''}`);
    }
    if (k === 'attention') {
      return box(`Needs attention`, `<a href="#pipeline?close=overdue">Past close date</a>`, p.attention.length ? `<div class="fwc-rows">${p.attention.map((x) => `
        <div class="fwc-rowlink" data-deal="${x.id}"><span class="fwc-type" data-t="deal">${icon('i-kanban')}</span><span class="fwc-rows__text"><b>${esc(x.title)}</b><small>${esc(x.account)} · ${esc(x.why)}</small></span>
          <span class="fwc-rows__side">${esc(money0(x.amount))}<small>${C.stagePill(x.stage)}</small></span></div>`).join('')}</div>`
        : C.emptyState('ic-trophy', 'Every deal is moving', 'No deal is stuck or past its expected close date.', '', true));
    }
    if (k === 'cold') {
      return box(`Going cold<small>${p.cold_total ? p.cold_total + ' with no contact in ' + BOOT.config.cold_days + ' days' : ''}</small>`, p.cold_total ? `<a href="#customers?status=cold">See all</a>` : '',
        p.cold.length ? `<div class="fwc-rows">${p.cold.map((x) => `
          <a href="${C.accountUrl(x.id)}">${avatar(x.name, 'fwc-ava--sm')}<span class="fwc-rows__text"><b>${esc(x.name)}</b><small>${x.last_at ? 'Last contact ' + esc(relTime(x.last_at)) : 'Never contacted'}${x.owner ? ' · ' + esc(x.owner) : ''}</small></span>
            <span class="fwc-rows__side">${x.billed12 ? esc(compact(x.billed12)) + '<small>billed · 12 mo</small>' : ''}</span></a>`).join('')}</div>`
        : C.emptyState('ic-snow', 'Nobody is going cold', `Every active ${esc(lower('customer'))} heard from you in the last ${BOOT.config.cold_days} days.`, '', true));
    }
    if (k === 'top') {
      return box(`Top ${esc(lower('customers'))}<small>billed · 12 months</small>`, `<a href="#customers?sort=revenue&dir=desc">All</a>`, p.top.length ? `<div class="fwc-rows">${p.top.map((x, i) => `
        <a href="${C.accountUrl(x.id)}">${avatar(x.name, 'fwc-ava--sm', i === 0)}<span class="fwc-rows__text"><b>${esc(x.name)}</b><small>${x.balance > 0.004 ? esc(money0(x.balance)) + ' outstanding' : 'Nothing outstanding'}</small></span>
          <span class="fwc-rows__side">${esc(compact(x.billed))}</span></a>`).join('')}</div>`
        : C.emptyState('iq-invoice', 'No invoices in the last year', 'Once you invoice ' + lower('customers') + ' in Quotes & Invoices, your best ones show up here.', '', true));
    }
    if (k === 'compliance') {
      const c = p.compliance.counts;
      return box('Compliance', `<a href="#compliance">Open</a>`, `<div class="fwc-compl">
          <a href="#compliance" style="--c:var(--danger)"><b>${c.missing}</b><span>Missing documents</span></a>
          <a href="#compliance" style="--c:var(--danger)"><b>${c.expired}</b><span>Expired</span></a>
          <a href="#compliance" style="--c:var(--warning)"><b>${c.expiring}</b><span>Expiring in ${p.compliance.window} days</span></a>
          <a href="#compliance" style="--c:var(--success)"><b>${c.valid}</b><span>In order</span></a></div>
        ${p.compliance.soon.length ? `<div class="fwc-rows">${p.compliance.soon.map((x) => `<a href="${C.accountUrl(x.account_id, 'compliance')}"><span class="fwc-type" data-t="${x.status === 'expired' ? 'issue' : 'meeting'}">${icon('shield')}</span>
          <span class="fwc-rows__text"><b>${esc(x.type)}</b><small>${esc(x.account)}</small></span><span class="fwc-rows__side"><small class="${x.days < 0 ? 'is-late' : ''}">${x.days < 0 ? 'Expired ' + esc(relDay(x.expiry_date)) : 'Expires ' + esc(relDay(x.expiry_date))}</small></span></a>`).join('')}</div>` : ''}`);
    }
    if (k === 'activity') {
      return box('Recent activity', `<a href="#activity">All activity</a>`, p.activity.length ? `<div class="fwc-tl">${p.activity.slice(0, 6).map((a) => F().activityItem(a)).join('')}</div>`
        : C.emptyState('ic-activity', 'No activity yet', 'Log calls, meetings and notes on an account and they show up here.', '', true));
    }
    return '';
  }
  function bindOverview(root, p) {
    $$('[data-create]', root).forEach((b) => b.addEventListener('click', () => C.create(b.dataset.create, { onSaved: renderOverview })));
    $$('[data-deal]', root).forEach((r) => r.addEventListener('click', () => F().sheet(+r.dataset.deal, { onChange: renderOverview })));
    const tasks = $('#ovTasks', root);
    if (tasks && p.tasks) bindTaskList(tasks, p.tasks.rows, renderOverview);
    const act = $('[data-panel="activity"]', root);
    if (act) F().bindActivity(act, renderOverview);
  }
  function bindTaskList(host, rows, reload) {
    host.addEventListener('click', async (e) => {
      if (e.target.closest('[data-stop]')) return;
      const tick = e.target.closest('[data-tick]');
      const row = e.target.closest('[data-task]');
      if (tick) {
        e.stopPropagation();
        const t = rows.find((x) => x.id === +tick.dataset.tick);
        if (!BOOT.can_write || !t) return;
        tick.closest('.fwc-task').classList.toggle('is-done', !t.done);
        try { await F().toggleTask(t, !t.done); reload(); } catch (er) { toastError(er); reload(); }
        return;
      }
      if (row && BOOT.can_write) { const t = rows.find((x) => x.id === +row.dataset.task); F().task({ task: t, onSaved: reload }); }
    });
  }
  document.addEventListener('crm:tasks', () => { if (S.route === 'overview' || S.route === 'tasks') render(); });

  // ===================================================== customers / suppliers
  function listColumns(type) {
    const key = type === 'customer' ? 'customers' : 'suppliers';
    const cfFields = (BOOT.fields || []).filter((f) => f.entity === 'account' && !f.archived && f.show_in_list && (f.applies_to === 'all' || f.applies_to === type));
    let cols = (BOOT.prefs.columns[key] || []).filter((c) => BOOT.columns[c] || cfFields.some((f) => 'cf_' + f.id === c));
    if (!BOOT.config.modules.pipeline) cols = cols.filter((c) => c !== 'open_deals');
    if (type === 'customer') cols = cols.filter((c) => c !== 'compliance');
    return { cols, cfFields, key };
  }
  const colLabel = (c, cfFields, type) => (c.startsWith('cf_') ? (cfFields.find((f) => 'cf_' + f.id === c) || {}).label || c : c === 'revenue' && type === 'supplier' ? 'Spend (12 months)' : BOOT.columns[c]);
  const SORTABLE = { name: 'name', status: 'status', owner: 'owner', city: 'city', industry: 'industry', balance: 'balance', revenue: 'revenue', open_deals: 'open_deals', last_activity: 'last_activity', created: 'created' };

  async function renderAccounts() {
    const r = ROUTES[S.route];
    const type = r.type;
    const status = param('status', 'all');
    const mode = C.narrow() ? 'cards' : (BOOT.prefs.list_view[S.route] || 'table');
    head(BOOT.company, title(S.route), `
      <div class="seg hide-narrow" role="group" aria-label="Layout"><button type="button" data-mode="table" class="${mode === 'table' ? 'is-on' : ''}" aria-label="Table">${icon('table')}</button><button type="button" data-mode="cards" class="${mode === 'cards' ? 'is-on' : ''}" aria-label="Cards">${icon('ic-cards')}</button></div>
      <button class="btn btn--sm btn--ghost hide-narrow" type="button" id="colsBtn">${icon('columns')}<span>Columns</span></button>
      ${BOOT.is_admin ? `<a class="btn btn--sm btn--ghost hide-narrow" id="exportBtn" href="/crm/api/transfer.php?op=export&what=${S.route}">${icon('download')}<span>Export</span></a>` : ''}
      ${BOOT.can_write ? `<button class="btn btn--primary" type="button" id="addAcc">${icon('plus')}<span>New ${esc(lower(type))}</span></button>` : ''}`, null);
    const chips = [['all', 'All'], ['active', BOOT.config.statuses.active.label], ['prospect', BOOT.config.statuses.prospect.label], ['inactive', BOOT.config.statuses.inactive.label],
      ['banned', BOOT.config.statuses.banned.label], ['preferred', 'Preferred'], ['mine', 'Mine'], ['cold', 'Going cold'], ['deleted', 'Deleted']];
    el.tools.hidden = false;
    el.tools.innerHTML = `<div class="fwc-chips" role="tablist">${chips.map(([k, l]) => `<button class="fwc-chip${status === k ? ' is-on' : ''}" type="button" role="tab" aria-selected="${status === k}" data-chip="${k}">${k === 'cold' ? icon('ic-snow') : k === 'preferred' ? icon('star') : ''}${esc(l)} <small data-chip-count="${k}"></small></button>`).join('')}</div>
      <span class="fwc-grow"></span><div class="fwc-filters" id="filters"></div>
      <label class="fwc-qbox"><svg class="i"><use href="#i-search"/></svg><input type="search" id="listQ" placeholder="Filter…" value="${esc(param('q'))}"></label>
      <button class="btn btn--sm btn--ghost" type="button" id="filterBtn">${icon('filter')}<span>Filter</span></button>${saveViewBtn()}`;
    paintFilters();
    $$('[data-chip]', el.tools).forEach((b) => b.addEventListener('click', () => go(S.route, withParams({ status: b.dataset.chip, page: 1 }))));
    const qIn = $('#listQ');
    qIn.addEventListener('input', C.debounce(() => go(S.route, withParams({ q: qIn.value.trim(), page: 1 }), true), 300));
    $('#filterBtn').addEventListener('click', (e) => filterMenu(e.currentTarget));
    bindSaveView();
    $$('[data-mode]', el.head).forEach((b) => b.addEventListener('click', async () => {
      BOOT.prefs.list_view[S.route] = b.dataset.mode;
      renderAccounts();
      try { await C.savePrefs({ list_view: { [S.route]: b.dataset.mode } }); } catch (er) { toastError(er); }
    }));
    const cb = $('#colsBtn');
    if (cb) cb.addEventListener('click', () => columnsMenu(cb, type));
    const add = $('#addAcc');
    if (add) add.addEventListener('click', () => F().account({ type }));
    if (!el.view.querySelector('.fwc-table-wrap, .fwc-cards')) el.view.innerHTML = `<div style="padding:18px">${'<div class="fwc-skel" style="height:52px;margin-bottom:8px"></div>'.repeat(8)}</div>`;
    const seq = ++S.seq;
    let d;
    try {
      d = await api.get('accounts.php', { type, status, q: param('q'), tag: param('tag'), industry: param('industry'), region: param('region'), owner: param('owner'), sort: param('sort'), dir: param('dir'), page: param('page', 1), per: 50 });
    } catch (e) { if (seq === S.seq) el.view.innerHTML = C.errorBox(e); return; }
    if (seq !== S.seq) return;
    Object.entries(d.counts).forEach(([k, v]) => { const c = $(`[data-chip-count="${k}"]`, el.tools); if (c) c.textContent = v || ''; });
    C.setCount(S.route, d.counts.all);
    el.head.querySelector('h1').innerHTML = `${esc(title(S.route))}<small>${d.counts.all}</small>`;
    S.rows = d.rows;
    const ex = $('#exportBtn');
    if (ex) ex.href = `/crm/api/transfer.php?op=export&what=${S.route}` + (param('tag') ? '&tag=' + encodeURIComponent(param('tag')) : '') + (['active', 'prospect', 'inactive', 'banned'].includes(status) ? '&status=' + status : '');
    if (!d.rows.length) {
      const filtered = param('q') || status !== 'all' || param('tag') || param('industry') || param('region') || param('owner');
      el.view.innerHTML = filtered ? C.emptyState('search', 'Nothing matches', 'Try another filter or clear the search.', `<button class="btn" type="button" id="clearF">Clear filters</button>`)
        : C.emptyState(type === 'customer' ? 'ic-customer' : 'ic-supplier', `Your first ${lower(type)}`, `Add ${esc(lower(S.route))} one by one, or bring them all in at once from a spreadsheet.`,
          (BOOT.can_write ? `<button class="btn btn--primary" type="button" id="emptyAdd">${icon('plus')}New ${esc(lower(type))}</button> ` : '') + (BOOT.is_admin ? `<a class="btn" href="/crm/import.php">${icon('upload')}Import a spreadsheet</a>` : ''));
      const cf = $('#clearF');
      if (cf) cf.addEventListener('click', () => go(S.route, {}));
      const ea = $('#emptyAdd');
      if (ea) ea.addEventListener('click', () => F().account({ type }));
      return;
    }
    const { cols, cfFields } = listColumns(type);
    const sum = `<div class="fwc-sum"><span><b>${d.total}</b> ${esc(d.total === 1 ? lower(type) : lower(S.route))}</span>${d.rows.length ? `<span>Owing on this page <b>${esc(money0(d.rows.reduce((a, x) => a + (x.money.balance || 0), 0)))}</b></span>` : ''}</div>`;
    const table = mode === 'table' ? accountTable(d.rows, cols, cfFields, type) : '';
    const cards = accountCards(d.rows, type, mode === 'table');
    el.view.innerHTML = `<div id="bulk"></div>${sum}${table}${cards}${pager(d)}`;
    bindAccountList(type);
  }
  function cell(x, c, type, cfFields) {
    switch (c) {
      case 'contact': return x.contact ? `<div class="fwc-cell2"><span class="fwc-ellip">${esc(x.contact.name)}</span><small>${esc(x.contact.role || x.contact.email || '')}</small></div>` : '<span class="fwc-muted">—</span>';
      case 'email': return x.email ? `<a href="mailto:${esc(x.email)}" data-stop>${esc(x.email)}</a>` : '<span class="fwc-muted">—</span>';
      case 'phone': { const ph = x.phone || (x.contact && (x.contact.mobile || x.contact.phone)); return ph ? `<a href="${C.telHref(ph)}" data-stop class="fwc-nowrap">${esc(C.fmtPhone(ph))}</a>` : '<span class="fwc-muted">—</span>'; }
      case 'owner': return x.owner ? `<span class="fwc-nowrap">${C.personAva(x.owner.id, x.owner.name)} ${esc(x.owner.name.split(' ')[0])}</span>` : '<span class="fwc-muted">—</span>';
      case 'tags': return x.tags.length ? `<div class="fwc-tags fwc-tags--nowrap">${x.tags.slice(0, 3).map((t) => tagChip(t)).join('')}${x.tags.length > 3 ? `<span class="fwc-muted">+${x.tags.length - 3}</span>` : ''}</div>` : '';
      case 'industry': return esc(x.industry);
      case 'region': return esc(x.region);
      case 'city': return esc(x.city);
      case 'balance': return x.money.balance > 0.004 ? `<span class="fwc-money${x.money.overdue > 0.004 ? ' is-late' : ''}">${esc(money0(x.money.balance))}${x.money.overdue > 0.004 ? `<small>${esc(money0(x.money.overdue))} overdue</small>` : ''}</span>` : '<span class="fwc-muted">—</span>';
      case 'revenue': return x.money.billed12 > 0.004 ? `<span class="fwc-money">${esc(compact(x.money.billed12))}</span>` : '<span class="fwc-muted">—</span>';
      case 'open_deals': return x.open_deals.count ? `<span class="fwc-money">${esc(compact(x.open_deals.value))}</span> <span class="fwc-muted">· ${x.open_deals.count}</span>` : '<span class="fwc-muted">—</span>';
      case 'compliance': return complianceBadge(x.compliance);
      case 'last_activity': { const n = C.ago(x.last_activity); return x.last_activity ? `<span class="${n > BOOT.config.cold_days ? 'fwc-money is-late' : ''}" title="${esc(x.last_activity)}">${esc(relTime(x.last_activity))}</span>` : '<span class="fwc-muted">Never</span>'; }
      case 'vat_no': return esc(x.vat_no);
      case 'created': return esc(fmtDate(String(x.created_at).slice(0, 10)));
      case 'status': return statusPill(x.status);
      default: return c.startsWith('cf_') ? esc(x.custom[c] || '') : '';
    }
  }
  const NUMERIC = ['balance', 'revenue', 'open_deals'];
  function complianceBadge(c) {
    const map = { none: ['muted', '—'], valid: ['success', 'In order'], expiring: ['warning', 'Expiring'], expired: ['danger', 'Expired'], missing: ['danger', 'Missing'] };
    const [t, l] = map[c.status] || map.none;
    return c.status === 'none' ? '<span class="fwc-muted">—</span>' : `<span class="fwc-pill" data-tone="${t}">${esc(l)}${c.blocking ? ' · blocked' : ''}</span>`;
  }
  function th(label, key, cls = '') {
    if (!key) return `<th class="${cls}">${esc(label)}</th>`;
    const sort = param('sort');
    const on = sort === key;
    const dir = on && param('dir', 'desc') === 'asc' ? 'desc' : 'asc';
    return `<th class="${cls}${on ? ' is-sorted' : ''}"><button type="button" data-sort="${key}" data-dir="${on ? dir : (['name', 'owner', 'city', 'industry', 'status'].includes(key) ? 'asc' : 'desc')}">${esc(label)}${on ? (param('dir', 'desc') === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>`;
  }
  function accountTable(rows, cols, cfFields, type) {
    const canSel = BOOT.can_write;
    const body = rows.map((x) => `<tr class="fwc-row${x.deleted_at ? ' is-deleted' : ''}" tabindex="0" data-id="${x.id}">
      ${canSel ? `<td class="c-check"><span class="fwm-check" role="checkbox" aria-checked="false" data-sel="${x.id}" tabindex="0">${icon('check')}</span></td>` : ''}
      <td><div class="fwc-acc">${avatar(x.name, '', x.preferred)}<div class="fwc-acc__text"><a class="fwc-acc__name" href="${C.accountUrl(x.id)}">${esc(x.name)}</a><span class="fwc-acc__sub">${esc([x.legal_name !== x.name ? x.legal_name : '', x.city, x.industry].filter(Boolean).join(' · ') || L(type))}</span></div></div></td>
      ${cols.map((c) => `<td class="${NUMERIC.includes(c) ? 'r' : ''}">${cell(x, c, type, cfFields)}</td>`).join('')}
      <td class="r"><span class="fwc-rowacts">${x.phone || (x.contact && (x.contact.mobile || x.contact.phone)) ? `<a class="icon-btn icon-btn--sm" href="${C.telHref(x.phone || x.contact.mobile || x.contact.phone)}" data-stop aria-label="Call" data-tip="Call">${icon('phone', 'i--sm')}</a>` : ''}
        ${BOOT.can_write ? `<button class="icon-btn icon-btn--sm" type="button" data-log="${x.id}" aria-label="Log activity" data-tip="Log activity">${icon('message-plus', 'i--sm')}</button>
        <button class="icon-btn icon-btn--sm" type="button" data-rowmenu="${x.id}" aria-label="More">${icon('more-h', 'i--sm')}</button>` : ''}</span></td></tr>`).join('');
    return `<div class="fwc-table-wrap"><table class="fwc-table"><thead><tr>
      ${canSel ? `<th class="c-check"><span class="fwm-check" role="checkbox" aria-checked="false" data-selall tabindex="0" aria-label="Select all">${icon('check')}</span></th>` : ''}
      ${th('Name', 'name')}${cols.map((c) => th(colLabel(c, cfFields, type), SORTABLE[c] || '', NUMERIC.includes(c) ? 'r' : '')).join('')}<th></th>
      </tr></thead><tbody>${body}</tbody></table></div>`;
  }
  function accountCards(rows, type, auto) {
    return `<div class="fwc-cards${auto ? ' is-auto' : ''}">${rows.map((x) => {
      const ph = x.phone || (x.contact && (x.contact.mobile || x.contact.phone));
      return `<div class="fwc-card" data-id="${x.id}" tabindex="0">
        ${BOOT.can_write && !auto ? `<span class="fwm-check fwc-card__check" role="checkbox" aria-checked="false" data-sel="${x.id}" tabindex="0">${icon('check')}</span>` : ''}
        <div class="fwc-card__top">${avatar(x.name, '', x.preferred)}<div style="min-width:0"><div class="fwc-card__name">${esc(x.name)}</div><div class="fwc-card__sub">${esc(x.contact ? x.contact.name + (x.contact.role ? ' · ' + x.contact.role : '') : (x.city || L(type)))}</div></div></div>
        <div class="fwc-card__facts">
          <span>${type === 'customer' ? 'Owes' : 'We owe'}<b class="${x.money.overdue > 0.004 ? 'fwc-money is-late' : ''}">${x.money.balance > 0.004 ? esc(money0(x.money.balance)) : '—'}</b></span>
          <span>Last contact<b>${x.last_activity ? esc(relTime(x.last_activity)) : 'Never'}</b></span>
          ${BOOT.config.modules.pipeline ? `<span>Open ${esc(lower('deals'))}<b>${x.open_deals.count ? esc(compact(x.open_deals.value)) : '—'}</b></span>` : ''}
          <span>Health<b><span class="fwc-health" title="${esc(x.health.why.join(' · '))}"><span class="fwc-health__bar"><i style="--p:${x.health.score}%;--c:${C.tone(x.health.tone)}"></i></span>${x.health.score}</span></b></span>
        </div>
        ${x.tags.length ? `<div class="fwc-tags">${x.tags.slice(0, 4).map((t) => tagChip(t)).join('')}</div>` : ''}
        <div class="fwc-card__foot">${statusPill(x.status)}${type === 'supplier' && x.compliance.status !== 'none' ? complianceBadge(x.compliance) : ''}<span class="fwc-grow"></span>
          ${ph ? `<a class="icon-btn icon-btn--sm" href="${C.telHref(ph)}" data-stop aria-label="Call">${icon('phone', 'i--sm')}</a><a class="icon-btn icon-btn--sm" href="${C.waHref(ph)}" target="_blank" rel="noopener" data-stop aria-label="WhatsApp">${icon('whatsapp', 'i--sm')}</a>` : ''}
          ${x.email ? `<a class="icon-btn icon-btn--sm" href="mailto:${esc(x.email)}" data-stop aria-label="Email">${icon('mail', 'i--sm')}</a>` : ''}</div></div>`;
    }).join('')}</div>`;
  }
  function pager(d) {
    const pages = Math.max(1, Math.ceil(d.total / d.per));
    if (pages <= 1) return '';
    return `<div class="fwc-pager"><span>Page ${d.page} of ${pages}</span><span style="display:flex;gap:6px">
      <button class="btn btn--sm" type="button" data-page="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>${icon('chev-left')}Previous</button>
      <button class="btn btn--sm" type="button" data-page="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>Next${icon('chev-right')}</button></span></div>`;
  }
  function bindAccountList(type) {
    const v = el.view;
    v.onclick = async (e) => {
      if (e.target.closest('[data-stop]')) return;
      const sel = e.target.closest('[data-sel]');
      const all = e.target.closest('[data-selall]');
      if (sel) { e.stopPropagation(); toggleSel(+sel.dataset.sel); return; }
      if (all) { const on = S.selected.size !== S.rows.length; S.rows.forEach((x) => (on ? S.selected.add(x.id) : S.selected.delete(x.id))); paintSel(); return; }
      const sort = e.target.closest('[data-sort]');
      if (sort) { go(S.route, withParams({ sort: sort.dataset.sort, dir: sort.dataset.dir, page: 1 })); return; }
      const pg = e.target.closest('[data-page]');
      if (pg) { go(S.route, withParams({ page: pg.dataset.page })); el.view.scrollTop = 0; return; }
      const log = e.target.closest('[data-log]');
      if (log) { e.stopPropagation(); location.href = C.accountUrl(+log.dataset.log, 'log'); return; }
      const menu = e.target.closest('[data-rowmenu]');
      if (menu) { e.stopPropagation(); rowMenu(menu, S.rows.find((x) => x.id === +menu.dataset.rowmenu), type); return; }
      if (e.target.closest('a')) return;
      const row = e.target.closest('[data-id]');
      if (row) location.href = C.accountUrl(+row.dataset.id);
    };
    v.onkeydown = (e) => {
      if (e.key !== 'Enter' && e.key !== ' ') return;
      const sel = e.target.closest('[data-sel], [data-selall]');
      if (sel) { e.preventDefault(); sel.click(); return; }
      const row = e.target.closest('tr[data-id], .fwc-card[data-id]');
      if (row && e.key === 'Enter') location.href = C.accountUrl(+row.dataset.id);
    };
  }
  function toggleSel(id) { if (S.selected.has(id)) S.selected.delete(id); else S.selected.add(id); paintSel(); }
  function paintSel() {
    $$('[data-sel]', el.view).forEach((c) => { const on = S.selected.has(+c.dataset.sel); c.classList.toggle('is-on', on); c.setAttribute('aria-checked', on); const tr = c.closest('tr'); if (tr) tr.classList.toggle('is-selected', on); });
    const all = $('[data-selall]', el.view);
    if (all) { all.classList.toggle('is-on', S.selected.size && S.selected.size === S.rows.length); all.classList.toggle('is-mixed', S.selected.size > 0 && S.selected.size < S.rows.length); }
    const bulk = $('#bulk');
    if (!bulk) return;
    if (!S.selected.size) { bulk.innerHTML = ''; return; }
    const deleted = param('status') === 'deleted';
    bulk.innerHTML = `<div class="fwc-bulk"><b>${S.selected.size} selected</b>
      ${deleted ? `<button class="btn btn--sm" type="button" data-bulk="restore">${icon('undo')}Restore</button>`
        : `<button class="btn btn--sm" type="button" data-bulk="status">${icon('status')}Status</button>
        ${BOOT.rules.tags ? `<button class="btn btn--sm" type="button" data-bulk="tag">${icon('tag')}Tag</button>` : ''}
        ${BOOT.is_admin ? `<button class="btn btn--sm" type="button" data-bulk="owner">${icon('user')}Owner</button>` : ''}
        <button class="btn btn--sm" type="button" data-bulk="preferred">${icon('star')}Preferred</button>
        ${BOOT.is_admin ? `<a class="btn btn--sm" href="/crm/api/transfer.php?op=export&what=${S.route}&ids=${[...S.selected].join(',')}">${icon('download')}Export</a>` : ''}
        <button class="btn btn--sm btn--danger" type="button" data-bulk="delete">${icon('trash')}Delete</button>`}
      <span class="fwc-grow"></span><button class="btn btn--sm btn--ghost" type="button" data-bulk="clear">Clear</button></div>`;
    $$('[data-bulk]', bulk).forEach((b) => b.addEventListener('click', () => bulkAction(b.dataset.bulk, b)));
  }
  async function bulkAction(kind, btn) {
    const ids = [...S.selected];
    const run = async (action, value, msg) => {
      try { const d = await api.post('accounts.php', { op: 'bulk', ids, action, value }); toast(msg.replace('{n}', d.changed)); S.selected.clear(); renderAccounts(); } catch (e) { toastError(e); }
    };
    if (kind === 'clear') { S.selected.clear(); paintSel(); return; }
    if (kind === 'status') {
      popover(btn, Object.entries(BOOT.config.statuses).map(([k, s]) => `<button class="fwm-pop__item" type="button" data-v="${k}">${statusPill(k)}</button>`).join(''), { onClick: (b) => run('status', b.dataset.v, 'Status changed on {n}') });
    } else if (kind === 'owner') {
      popover(btn, `<button class="fwm-pop__item" type="button" data-v="0">Nobody</button>` + BOOT.people.map((p) => `<button class="fwm-pop__item" type="button" data-v="${p.id}">${C.personAva(p.id, p.name)}${esc(p.name)}</button>`).join(''), { onClick: (b) => run('owner', +b.dataset.v, 'Owner changed on {n}') });
    } else if (kind === 'tag') {
      popover(btn, `<input class="fwm-pop__input" placeholder="Tag name — Enter to add" maxlength="64">${BOOT.tags.map((t) => `<button class="fwm-pop__item" type="button" data-v="${esc(t.name)}">${tagChip(t)}</button>`).join('')}`, {
        onClick: (b) => run('tag_add', b.dataset.v, 'Tagged {n}'),
      });
      const inp = $('.fwm-pop .fwm-pop__input');
      if (inp) inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && inp.value.trim()) { C.closePop(); run('tag_add', inp.value.trim(), 'Tagged {n}'); } });
    } else if (kind === 'preferred') {
      popover(btn, `<button class="fwm-pop__item" type="button" data-v="1">${icon('star')}Mark preferred</button><button class="fwm-pop__item" type="button" data-v="0">Not preferred</button>`, { onClick: (b) => run('preferred', b.dataset.v === '1', 'Updated {n}') });
    } else if (kind === 'delete') {
      if (await dialog({ title: `Delete ${ids.length} ${ids.length === 1 ? 'account' : 'accounts'}?`, body: 'They move to Deleted and can be restored. Their documents and history are kept.', confirm: 'Delete', danger: true })) run('delete', null, 'Deleted {n}');
    } else if (kind === 'restore') run('restore', null, 'Restored {n}');
  }
  function rowMenu(btn, x, type) {
    const deleted = !!x.deleted_at;
    popover(btn, deleted ? `<button class="fwm-pop__item" type="button" data-v="restore">${icon('undo')}Restore</button>${BOOT.is_admin ? `<button class="fwm-pop__item is-danger" type="button" data-v="purge">${icon('trash')}Delete for good</button>` : ''}`
      : `<a class="fwm-pop__item" data-v="open" href="${C.accountUrl(x.id)}">${icon('external')}Open</a>
      <button class="fwm-pop__item" type="button" data-v="task">${icon('i-check-square')}New ${esc(lower('task'))}</button>
      ${BOOT.config.modules.pipeline ? `<button class="fwm-pop__item" type="button" data-v="deal">${icon('i-kanban')}New ${esc(lower('deal'))}</button>` : ''}
      ${type === 'customer' ? `<a class="fwm-pop__item" data-v="q" href="/qi/edit.php?type=quote&customer_id=${x.id}">${icon('iq-quote')}New quote</a><a class="fwm-pop__item" data-v="i" href="/qi/edit.php?type=invoice&customer_id=${x.id}">${icon('iq-invoice')}New invoice</a>` : ''}
      <button class="fwm-pop__item" type="button" data-v="pref">${icon('star')}${x.preferred ? 'Not preferred' : 'Mark preferred'}</button>
      <div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" type="button" data-v="delete">${icon('trash')}Delete</button>`, {
      align: 'end',
      onClick: async (b) => {
        const v = b.dataset.v;
        try {
          if (v === 'task') F().task({ account: { id: x.id, name: x.name } });
          else if (v === 'deal') F().deal({ account: { id: x.id, name: x.name } });
          else if (v === 'pref') { await api.post('accounts.php', { op: 'preferred', id: x.id, on: !x.preferred }); renderAccounts(); }
          else if (v === 'delete') { await api.post('accounts.php', { op: 'delete', ids: [x.id] }); toast(x.name + ' deleted', { action: { label: 'Undo', run: () => api.post('accounts.php', { op: 'restore', ids: [x.id] }).then(renderAccounts) } }); renderAccounts(); }
          else if (v === 'restore') { await api.post('accounts.php', { op: 'restore', ids: [x.id] }); toast('Restored'); renderAccounts(); }
          else if (v === 'purge') {
            if (!(await dialog({ title: 'Delete ' + x.name + ' for good?', body: 'Contacts, activity, tasks, deals and compliance documents are removed. This cannot be undone.', confirm: 'Delete for good', danger: true }))) return;
            await api.post('accounts.php', { op: 'purge', id: x.id }); toast('Deleted for good'); renderAccounts();
          }
        } catch (e) { toastError(e); }
      },
    });
  }
  function paintFilters() {
    const host = $('#filters');
    if (!host) return;
    const f = [];
    const tag = BOOT.tags.find((t) => String(t.id) === param('tag'));
    if (tag) f.push(['tag', 'Tag: ' + tag.name]);
    const ind = BOOT.lookups.industries.find((x) => String(x.id) === param('industry'));
    if (ind) f.push(['industry', ind.name]);
    const reg = BOOT.lookups.regions.find((x) => String(x.id) === param('region'));
    if (reg) f.push(['region', reg.name]);
    if (param('owner')) { const p = param('owner') === 'me' ? { name: 'Me' } : param('owner') === 'none' ? { name: 'Nobody' } : C.person(param('owner')); f.push(['owner', 'Owner: ' + ((p && p.name) || '?')]); }
    host.innerHTML = f.map(([k, l]) => `<span class="fwc-fpill">${esc(l)}<button type="button" data-unf="${k}" aria-label="Remove filter">${icon('x', 'i--xs')}</button></span>`).join('');
    $$('[data-unf]', host).forEach((b) => b.addEventListener('click', () => go(S.route, withParams({ [b.dataset.unf]: '', page: 1 }))));
  }
  function filterMenu(anchor) {
    const sel = (name, items, cur) => `<select class="select" data-f="${name}" style="height:34px">${['<option value="">Any</option>', ...items.map(([v, l]) => `<option value="${esc(v)}"${String(v) === cur ? ' selected' : ''}>${esc(l)}</option>`)].join('')}</select>`;
    popover(anchor, `<div style="padding:8px;display:flex;flex-direction:column;gap:10px;min-width:260px">
      ${BOOT.tags.length ? `<div class="field"><span class="label">Tag</span>${sel('tag', BOOT.tags.map((t) => [t.id, t.name + ' (' + t.count + ')']), param('tag'))}</div>` : ''}
      <div class="field"><span class="label">Owner</span>${sel('owner', [['me', 'Me'], ['none', 'Nobody'], ...BOOT.people.map((p) => [p.id, p.name])], param('owner'))}</div>
      <div class="field"><span class="label">Industry</span>${sel('industry', BOOT.lookups.industries.map((x) => [x.id, x.name]), param('industry'))}</div>
      <div class="field"><span class="label">Region</span>${sel('region', BOOT.lookups.regions.map((x) => [x.id, x.name]), param('region'))}</div></div>`, { align: 'end', role: 'dialog', noFocus: true });
    $$('.fwm-pop [data-f]').forEach((s) => s.addEventListener('change', () => go(S.route, withParams({ [s.dataset.f]: s.value, page: 1 }))));
  }
  function columnsMenu(anchor, type) {
    const { cols, cfFields, key } = listColumns(type);
    const all = [...Object.keys(BOOT.columns).filter((c) => !(type === 'customer' && c === 'compliance') && !(c === 'open_deals' && !BOOT.config.modules.pipeline) && c !== 'status'), ...cfFields.map((f) => 'cf_' + f.id), 'status'];
    const ordered = [...cols, ...all.filter((c) => !cols.includes(c))];
    popover(anchor, `<div class="fwm-pop__title">Columns · drag to reorder</div><div id="colList">${ordered.map((c) => `
      <label class="fwm-pop__item" draggable="true" data-col="${c}" style="cursor:grab">${icon('ic-drag')}<input type="checkbox" ${cols.includes(c) ? 'checked' : ''} style="accent-color:var(--accent)"><span>${esc(colLabel(c, cfFields, type))}</span></label>`).join('')}</div>
      <div class="fwm-pop__sep"></div><button class="fwm-pop__item" type="button" data-v="reset">${icon('undo')}Back to the default</button>`, {
      align: 'end', width: 260, noFocus: true,
      onClick: async (b) => { if (b.dataset.v === 'reset') { await saveCols(key, null); } },
    });
    const list = $('#colList');
    const persist = () => saveCols(key, $$('[data-col]', list).filter((x) => $('input', x).checked).map((x) => x.dataset.col));
    list.addEventListener('change', persist);
    C.sortable(list, '[data-col]', persist);
  }
  /** cols null = back to the default columns */
  async function saveCols(key, cols) {
    try {
      await C.savePrefs({ columns: { [key]: cols } });
      renderAccounts();
    } catch (e) { toastError(e); }
  }

  // ============================================================ pipeline
  async function renderPipeline() {
    const view = param('view', BOOT.prefs.pipeline_view || 'board');
    const owner = param('owner', BOOT.prefs.pipeline_mine ? 'me' : '');
    head(BOOT.company, L('pipeline'), `
      <div class="seg" role="group" aria-label="Layout"><button type="button" data-view="board" class="${view === 'board' ? 'is-on' : ''}">${icon('i-kanban')}<span class="hide-narrow">Board</span></button><button type="button" data-view="list" class="${view === 'list' ? 'is-on' : ''}">${icon('list')}<span class="hide-narrow">List</span></button></div>
      ${BOOT.is_admin ? `<a class="btn btn--sm btn--ghost hide-narrow" href="/crm/settings.php#pipeline">${icon('ic-sliders')}<span>Stages</span></a>` : ''}
      ${BOOT.can_write ? `<button class="btn btn--primary" type="button" id="addDeal">${icon('plus')}<span>New ${esc(lower('deal'))}</span></button>` : ''}`);
    const closes = [['', 'Any time'], ['overdue', 'Past close date'], ['month', 'This month'], ['quarter', 'Next 3 months'], ['none', 'No date']];
    el.tools.hidden = false;
    el.tools.innerHTML = `<div class="fwc-chips"><button class="fwc-chip${owner === '' ? ' is-on' : ''}" type="button" data-owner="">Everyone</button><button class="fwc-chip${owner === 'me' ? ' is-on' : ''}" type="button" data-owner="me">${icon('user')}Mine</button></div>
      <span class="fwc-sep"></span><div class="fwc-chips">${closes.map(([k, l]) => `<button class="fwc-chip${param('close') === k ? ' is-on' : ''}" type="button" data-close-f="${k}">${esc(l)}</button>`).join('')}</div>
      <span class="fwc-grow"></span><span class="fwc-muted hide-narrow" id="pipeTotals" style="font-size:12.5px"></span>
      <label class="fwc-qbox"><svg class="i"><use href="#i-search"/></svg><input type="search" id="pipeQ" placeholder="Filter ${esc(lower('deals'))}…" value="${esc(param('q'))}"></label>${saveViewBtn()}`;
    bindSaveView();
    $$('[data-view]', el.head).forEach((b) => b.addEventListener('click', () => { go('pipeline', withParams({ view: b.dataset.view })); C.savePrefs({ pipeline_view: b.dataset.view }).catch(() => {}); }));
    $$('[data-owner]', el.tools).forEach((b) => b.addEventListener('click', () => { go('pipeline', withParams({ owner: b.dataset.owner || '' })); C.savePrefs({ pipeline_mine: b.dataset.owner === 'me' }).catch(() => {}); }));
    $$('[data-close-f]', el.tools).forEach((b) => b.addEventListener('click', () => go('pipeline', withParams({ close: b.dataset.closeF }))));
    const q = $('#pipeQ');
    q.addEventListener('input', C.debounce(() => go('pipeline', withParams({ q: q.value.trim() }), true), 300));
    const add = $('#addDeal');
    if (add) add.addEventListener('click', () => F().deal({ onSaved: renderPipeline }));
    if (!$('.fwc-board, .fwc-table-wrap', el.view)) el.view.innerHTML = `<div class="fwc-board">${'<div class="fwc-col fwc-skel"></div>'.repeat(4)}</div>`;
    const seq = ++S.seq;
    let d;
    try { d = await api.get('deals.php', { q: param('q'), owner, close: param('close'), include: view === 'list' ? (param('include') || 'all') : 'board' }); } catch (e) { if (seq === S.seq) el.view.innerHTML = C.errorBox(e); return; }
    if (seq !== S.seq) return;
    S.board = d;
    BOOT.stages = d.stages;
    const open = d.deals.filter((x) => x.kind === 'open');
    const totalOpen = open.reduce((a, x) => a + x.amount, 0);
    const weighted = open.reduce((a, x) => a + x.amount * x.probability / 100, 0);
    C.setCount('pipeline', open.length);
    $('#pipeTotals').innerHTML = `${open.length} open · <b style="color:var(--ink)">${esc(money0(totalOpen))}</b> · weighted <b style="color:var(--ink)">${esc(money0(weighted))}</b>`;
    if (!d.deals.length && !param('q') && !param('close') && !owner) {
      el.view.innerHTML = C.emptyState('i-kanban', `Your ${lower('pipeline')} is empty`, `Track every opportunity from first contact to signed order. Drag ${lower('deals')} between stages as they move.`,
        BOOT.can_write ? `<button class="btn btn--primary" type="button" id="emptyDeal">${icon('plus')}New ${esc(lower('deal'))}</button>` : '');
      const e = $('#emptyDeal');
      if (e) e.addEventListener('click', () => F().deal({ onSaved: renderPipeline }));
      return;
    }
    if (view === 'list') { renderDealList(d); return; }
    renderBoard(d);
  }
  function dealCard(x) {
    const late = x.kind === 'open' && x.close_date && C.daysFrom(x.close_date) < 0;
    const stale = x.kind === 'open' && x.days_in_stage > BOOT.config.stale_days;
    return `<article class="fwc-deal" draggable="${BOOT.can_write ? 'true' : 'false'}" data-deal="${x.id}" style="--c:${esc(x.stage_color)}" tabindex="0" aria-label="${esc(x.title)}">
      <div class="fwc-deal__title">${esc(x.title)}</div>
      <div class="fwc-deal__acc">${esc(x.account)}</div>
      <div class="fwc-deal__row"><span class="fwc-deal__amt">${esc(money0(x.amount))}</span>
        ${x.close_date ? `<span class="fwc-deal__date${late ? ' is-late' : ''}">${icon('calendar')}${esc(fmtDate(x.close_date))}</span>` : ''}</div>
      <div class="fwc-deal__row">${C.personAva(x.owner_id, x.owner)}${stale ? `<span class="fwc-deal__stale" title="In this stage for ${x.days_in_stage} days">${icon('hourglass', 'i--xs')}${x.days_in_stage}d</span>` : ''}
        ${x.kind === 'lost' && x.loss_reason ? `<span class="fwc-muted" style="font-size:11.5px">${esc(x.loss_reason)}</span>` : ''}<span class="fwc-grow"></span><span class="fwc-muted" style="font-size:11.5px">${x.kind === 'open' ? Math.round(x.probability) + '%' : ''}</span></div>
      ${BOOT.can_write ? `<button class="icon-btn icon-btn--sm fwc-deal__menu" type="button" data-dmenu="${x.id}" aria-label="Move or change">${icon('more-h', 'i--sm')}</button>` : ''}
    </article>`;
  }
  function renderBoard(d) {
    const cols = d.stages.map((s) => {
      const items = d.deals.filter((x) => x.column === s.key);
      const t = d.totals[s.key] || { count: 0, value: 0, weighted: 0 };
      const closed = s.kind !== 'open';
      return `<section class="fwc-col${closed ? ' is-closed' : ''}" data-col="${esc(s.key)}" style="--c:${esc(s.color)}" aria-label="${esc(s.label)}">
        <header class="fwc-col__head"><div class="fwc-col__title">${s.kind === 'won' ? icon('ic-trophy', 'i--sm') : s.kind === 'lost' ? icon('ic-lost', 'i--sm') : ''}${esc(s.label)}<small>${items.length}</small></div>
          <div class="fwc-col__sum"><b>${esc(money0(t.value))}</b>${s.kind === 'open' ? ` · ${s.probability}% · ${esc(compact(t.weighted))} weighted` : ' · last 30 days'}</div></header>
        <div class="fwc-col__list" data-list="${esc(s.key)}">${items.map(dealCard).join('')}</div>
        ${BOOT.can_write && !closed ? `<button class="fwc-col__add" type="button" data-quick="${esc(s.key)}">${icon('plus', 'i--sm')}Add</button>` : ''}
      </section>`;
    }).join('');
    el.view.innerHTML = `<div class="fwc-board" id="board">${cols}</div>`;
    bindBoard();
  }
  function bindBoard() {
    const board = $('#board');
    let drag = null;
    board.addEventListener('dragstart', (e) => {
      const card = e.target.closest('.fwc-deal');
      if (!card) return;
      drag = card;
      card.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      try { e.dataTransfer.setData('text/plain', card.dataset.deal); } catch (x) { /* old browsers */ }
    });
    board.addEventListener('dragend', () => { if (drag) drag.classList.remove('is-dragging'); drag = null; $$('.fwc-col.is-over', board).forEach((c) => c.classList.remove('is-over')); });
    board.addEventListener('dragover', (e) => {
      if (!drag) return;
      const col = e.target.closest('.fwc-col');
      if (!col) return;
      e.preventDefault();
      $$('.fwc-col.is-over', board).forEach((c) => c !== col && c.classList.remove('is-over'));
      col.classList.add('is-over');
      const list = $('.fwc-col__list', col);
      const after = [...list.querySelectorAll('.fwc-deal:not(.is-dragging)')].find((c) => e.clientY < c.getBoundingClientRect().top + c.offsetHeight / 2);
      if (after) list.insertBefore(drag, after); else list.appendChild(drag);
    });
    board.addEventListener('drop', async (e) => {
      if (!drag) return;
      e.preventDefault();
      const col = e.target.closest('.fwc-col');
      const card = drag;
      if (!col) return;
      col.classList.remove('is-over');
      const id = +card.dataset.deal;
      const stage = col.dataset.col;
      const deal = S.board.deals.find((x) => x.id === id);
      const order = $$('.fwc-deal', $('.fwc-col__list', col)).map((c) => +c.dataset.deal);
      moveDeal(deal, stage, order);
    });
    board.addEventListener('click', (e) => {
      const menu = e.target.closest('[data-dmenu]');
      if (menu) { e.stopPropagation(); dealMenu(menu, S.board.deals.find((x) => x.id === +menu.dataset.dmenu)); return; }
      const quick = e.target.closest('[data-quick]');
      if (quick) { quickAdd(quick); return; }
      const card = e.target.closest('.fwc-deal');
      if (card) F().sheet(+card.dataset.deal, { onChange: renderPipeline });
    });
    board.addEventListener('keydown', (e) => {
      const card = e.target.closest('.fwc-deal');
      if (card && e.key === 'Enter') F().sheet(+card.dataset.deal, { onChange: renderPipeline });
    });
  }
  async function moveDeal(deal, stage, order) {
    if (!deal) return;
    const s = stageMeta(stage);
    let reason;
    if (stage === deal.stage && order) {
      try { await api.post('deals.php', { op: 'move', id: deal.id, stage, order }); } catch (e) { toastError(e); renderPipeline(); }
      return;
    }
    if (s.kind === 'lost') {
      reason = await F().lostReason(deal);
      if (reason === null) { renderPipeline(); return; }
    }
    try {
      const r = await api.post('deals.php', { op: 'move', id: deal.id, stage, order: order || [], ...(s.kind === 'lost' ? { loss_reason: reason || '' } : {}) });
      if (s.kind === 'won') {
        toast(`Won! ${deal.title} · ${money0(deal.amount)}`, { actions: [
          ...(r.deal && deal.account_id ? [{ label: 'Invoice', run: () => { location.href = '/qi/edit.php?type=invoice&customer_id=' + deal.account_id; } }] : []),
          { label: 'Start project', run: () => F().convert({ ...deal, stage: 'won' }) },
        ] });
        celebrate();
      } else toast(`${deal.title} → ${s.label}`, { action: { label: 'Undo', run: () => api.post('deals.php', { op: 'move', id: deal.id, stage: deal.stage, order: [] }).then(renderPipeline) } });
      renderPipeline();
    } catch (e) { toastError(e); renderPipeline(); }
  }
  function celebrate() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const host = document.createElement('div');
    host.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:200;overflow:hidden';
    const colors = ['#d8bd8a', '#b08d57', '#7a1f30', '#2f7457', '#e3a3ae'];
    for (let i = 0; i < 70; i++) {
      const p = document.createElement('i');
      const size = 5 + Math.random() * 6;
      p.style.cssText = `position:absolute;left:${50 + (Math.random() - 0.5) * 30}%;top:38%;width:${size}px;height:${size * 0.5}px;background:${colors[i % colors.length]};border-radius:2px;opacity:.95;transform:rotate(${Math.random() * 360}deg)`;
      host.appendChild(p);
      const dx = (Math.random() - 0.5) * 900, dy = -200 - Math.random() * 300;
      p.animate([{ transform: `translate(0,0) rotate(0deg)` }, { transform: `translate(${dx}px, ${dy}px) rotate(${Math.random() * 720}deg)`, offset: 0.4 }, { transform: `translate(${dx * 1.2}px, ${window.innerHeight}px) rotate(${Math.random() * 1080}deg)`, opacity: 0 }],
        { duration: 1800 + Math.random() * 900, easing: 'cubic-bezier(.2,.7,.3,1)', fill: 'forwards' });
    }
    document.body.appendChild(host);
    setTimeout(() => host.remove(), 3000);
  }
  function dealMenu(btn, deal) {
    popover(btn, `<div class="fwm-pop__title">Move to</div>${BOOT.stages.filter((s) => s.key !== deal.stage).map((s) => `<button class="fwm-pop__item" type="button" data-v="${esc(s.key)}"><span class="fwc-prio" style="display:inline-block;background:${esc(s.color)};width:10px;height:10px"></span>${esc(s.label)}</button>`).join('')}
      <div class="fwm-pop__sep"></div><button class="fwm-pop__item" type="button" data-v="__open">${icon('external')}Open</button><a class="fwm-pop__item" data-v="__acc" href="${C.accountUrl(deal.account_id)}">${icon('building')}${esc(deal.account)}</a>`, {
      align: 'end', onClick: (b) => { if (b.dataset.v === '__open') F().sheet(deal.id, { onChange: renderPipeline }); else if (b.dataset.v !== '__acc') moveDeal(deal, b.dataset.v, null); },
    });
  }
  function quickAdd(btn) {
    const stage = btn.dataset.quick;
    const form = document.createElement('form');
    form.className = 'fwc-quickadd';
    form.innerHTML = `<input class="input" name="title" placeholder="${esc(L('deal'))} name" maxlength="190" required>
      <input class="input" name="acc" placeholder="With (search)" autocomplete="off">
      <div class="fwc-quickadd__row"><input class="input" name="amount" inputmode="decimal" placeholder="R value"><button class="btn btn--sm btn--primary" type="submit">${icon('check')}</button><button class="btn btn--sm" type="button" data-cancel>${icon('x')}</button></div>`;
    btn.replaceWith(form);
    let accountId = 0;
    F().accountPicker($('[name=acc]', form), (a) => { accountId = a ? a.id : 0; });
    $('[name=title]', form).focus();
    $('[data-cancel]', form).addEventListener('click', () => renderBoard(S.board));
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!accountId) { toastError(new Error('Choose who the ' + lower('deal') + ' is with.')); $('[name=acc]', form).focus(); return; }
      try { await api.post('deals.php', { op: 'save', title: form.title.value, account_id: accountId, amount: form.amount.value, stage }); renderPipeline(); } catch (er) { toastError(er); }
    });
  }
  function renderDealList(d) {
    const sort = param('lsort', 'close');
    const dir = param('ldir', 'asc') === 'desc' ? -1 : 1;
    const key = { title: (x) => x.title.toLowerCase(), account: (x) => x.account.toLowerCase(), amount: (x) => x.amount, prob: (x) => x.probability, weighted: (x) => x.amount * x.probability / 100,
      close: (x) => x.close_date || '9999', stage: (x) => BOOT.stages.findIndex((s) => s.key === x.stage), owner: (x) => x.owner }[sort] || ((x) => x.close_date || '9999');
    const rows = d.deals.slice().sort((a, b) => (key(a) > key(b) ? 1 : key(a) < key(b) ? -1 : 0) * dir);
    const lth = (label, k, cls = '') => `<th class="${cls}${sort === k ? ' is-sorted' : ''}"><button type="button" data-lsort="${k}">${esc(label)}${sort === k ? (dir > 0 ? ' ↑' : ' ↓') : ''}</button></th>`;
    const incl = param('include', 'all');
    el.view.innerHTML = `<div class="fwc-tools" style="min-height:48px"><div class="fwc-chips">${[['all', 'All'], ['open', 'Open'], ['won', 'Won'], ['lost', 'Lost']].map(([k, l]) => `<button class="fwc-chip${incl === k ? ' is-on' : ''}" type="button" data-incl="${k}">${esc(l)}</button>`).join('')}</div></div>
      <div class="fwc-table-wrap"><table class="fwc-table"><thead><tr>${lth(L('deal'), 'title')}${lth('With', 'account')}${lth('Stage', 'stage')}${lth('Value', 'amount', 'r')}${lth('%', 'prob', 'r')}${lth('Weighted', 'weighted', 'r')}${lth('Close', 'close')}${lth('Owner', 'owner')}</tr></thead>
      <tbody>${rows.map((x) => { const late = x.kind === 'open' && x.close_date && C.daysFrom(x.close_date) < 0; return `<tr class="fwc-row" tabindex="0" data-deal="${x.id}">
        <td><b>${esc(x.title)}</b>${x.loss_reason ? `<div class="fwc-muted" style="font-size:12px">${esc(x.loss_reason)}</div>` : ''}</td><td><a href="${C.accountUrl(x.account_id)}" data-stop>${esc(x.account)}</a></td>
        <td>${C.stagePill(x.stage)}</td><td class="r"><span class="fwc-money">${esc(money0(x.amount))}</span></td><td class="r">${Math.round(x.probability)}</td><td class="r">${esc(money0(x.amount * x.probability / 100))}</td>
        <td class="${late ? 'fwc-money is-late' : ''}">${esc(x.close_date ? fmtDate(x.close_date) : '—')}</td><td>${C.personAva(x.owner_id, x.owner)} ${esc((x.owner || '').split(' ')[0])}</td></tr>`; }).join('')}</tbody></table></div>
      <div class="fwc-cards is-auto">${rows.map((x) => `<div class="fwc-card" data-deal="${x.id}"><div class="fwc-card__top"><span class="fwc-type" data-t="deal">${icon('i-kanban')}</span><div style="min-width:0"><div class="fwc-card__name">${esc(x.title)}</div><div class="fwc-card__sub">${esc(x.account)}</div></div></div>
        <div class="fwc-card__foot">${C.stagePill(x.stage)}<span class="fwc-grow"></span><b class="fwc-money">${esc(money0(x.amount))}</b></div></div>`).join('')}</div>`;
    el.view.onclick = (e) => {
      if (e.target.closest('[data-stop]')) return;
      const s = e.target.closest('[data-lsort]');
      if (s) { go('pipeline', withParams({ lsort: s.dataset.lsort, ldir: sort === s.dataset.lsort && dir > 0 ? 'desc' : 'asc' }), true); return; }
      const inc = e.target.closest('[data-incl]');
      if (inc) { go('pipeline', withParams({ include: inc.dataset.incl })); return; }
      const r = e.target.closest('[data-deal]');
      if (r) F().sheet(+r.dataset.deal, { onChange: renderPipeline });
    };
  }
  document.addEventListener('crm:deals', () => { if (S.route === 'pipeline' && !$('.fwc-sheet')) renderPipeline(); });

  // =============================================================== tasks
  async function renderTasks() {
    const scope = param('scope', 'mine');
    const when = param('when', 'open');
    head(BOOT.company, L('tasks'), BOOT.can_write ? `<button class="btn btn--primary" type="button" id="addTask">${icon('plus')}<span>New ${esc(lower('task'))}</span></button>` : '');
    el.tools.hidden = false;
    const chips = [['open', 'To do'], ['overdue', 'Overdue'], ['today', 'Today'], ['week', 'Next 7 days'], ['later', 'Later'], ['nodate', 'No date'], ['done', 'Done']];
    el.tools.innerHTML = `<div class="fwc-chips">${chips.map(([k, l]) => `<button class="fwc-chip${when === k ? ' is-on' : ''}${k === 'overdue' ? ' fwc-chip--danger' : ''}" type="button" data-when="${k}">${esc(l)} <small data-tc="${k}"></small></button>`).join('')}</div>
      <span class="fwc-grow"></span><select class="select" id="taskScope" style="height:34px;width:auto"><option value="mine"${scope === 'mine' ? ' selected' : ''}>Assigned to me</option><option value="all"${scope === 'all' ? ' selected' : ''}>Everyone</option>${BOOT.people.filter((p) => p.id !== BOOT.me.id).map((p) => `<option value="${p.id}"${scope === String(p.id) ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>${saveViewBtn()}`;
    bindSaveView();
    $$('[data-when]', el.tools).forEach((b) => b.addEventListener('click', () => go('tasks', withParams({ when: b.dataset.when }))));
    $('#taskScope').addEventListener('change', (e) => go('tasks', withParams({ scope: e.target.value })));
    const add = $('#addTask');
    if (add) add.addEventListener('click', () => F().task({ onSaved: renderTasks }));
    const seq = ++S.seq;
    let d;
    try { d = await api.get('tasks.php', { scope, when }); } catch (e) { if (seq === S.seq) el.view.innerHTML = C.errorBox(e); return; }
    if (seq !== S.seq) return;
    Object.entries(d.counts).forEach(([k, v]) => { const c = $(`[data-tc="${k}"]`, el.tools); if (c) c.textContent = v || ''; });
    if (scope === 'mine') C.setCount('tasks', (d.counts.overdue || 0) + (d.counts.today || 0));
    const quick = BOOT.can_write && when !== 'done' ? `<form class="fwc-taskadd" id="taskQuick">${icon('plus')}<input type="text" name="title" placeholder="Add a ${esc(lower('task'))} — press Enter" maxlength="190" aria-label="New task">
      <input class="input" type="date" name="due" value="${C.ymd(new Date(Date.now() + 86400000))}" aria-label="Due date"><button class="btn btn--sm btn--primary" type="submit">Add</button></form>` : '';
    if (!d.rows.length) {
      el.view.innerHTML = `<div class="fwc-tasks">${quick}${C.emptyState(when === 'done' ? 'i-check-square' : 'ic-sun-rise', when === 'done' ? 'Nothing finished yet' : 'Nothing to do here', when === 'overdue' ? 'Nothing is overdue. Well done.' : 'Add follow-ups as you go, or log a call with a follow-up task from any account.')}</div>`;
    } else {
      const groups = [];
      if (when === 'open') {
        const g = { overdue: [], today: [], tomorrow: [], week: [], later: [], nodate: [] };
        const tm = C.ymd(new Date(Date.now() + 86400000));
        d.rows.forEach((t) => {
          if (t.state === 'overdue') g.overdue.push(t);
          else if (t.state === 'today') g.today.push(t);
          else if (!t.due_at) g.nodate.push(t);
          else if (t.due_at.slice(0, 10) === tm) g.tomorrow.push(t);
          else if (C.daysFrom(t.due_at.slice(0, 10)) <= 7) g.week.push(t);
          else g.later.push(t);
        });
        [['overdue', 'Overdue'], ['today', 'Today'], ['tomorrow', 'Tomorrow'], ['week', 'This week'], ['later', 'Later'], ['nodate', 'Some day']].forEach(([k, l]) => { if (g[k].length) groups.push([k, l, g[k]]); });
      } else groups.push([when, chips.find((c) => c[0] === when)[1], d.rows]);
      el.view.innerHTML = `<div class="fwc-tasks">${quick}${groups.map(([k, l, rows]) => `<div class="fwc-taskgroup${k === 'overdue' ? ' is-late' : ''}"><h4>${esc(l)} <small>${rows.length}</small></h4>${rows.map(F().taskRow).join('')}</div>`).join('')}</div>`;
    }
    bindTaskList($('.fwc-tasks', el.view), d.rows, renderTasks);
    const qf = $('#taskQuick');
    if (qf) qf.addEventListener('submit', async (e) => {
      e.preventDefault();
      const t = qf.title.value.trim();
      if (!t) return;
      try { await api.post('tasks.php', { op: 'save', title: t, due_date: qf.due.value, assigned_to: scope !== 'mine' && scope !== 'all' ? scope : BOOT.me.id, remind: 'at' }); toast('Task added'); renderTasks(); } catch (er) { toastError(er); }
    });
  }

  // ============================================================ contacts
  async function renderContacts() {
    head(BOOT.company, L('contacts'), BOOT.is_admin ? `<a class="btn btn--sm btn--ghost" href="/crm/api/transfer.php?op=export&what=contacts">${icon('download')}<span>Export</span></a>` : '');
    el.tools.hidden = false;
    const type = param('type');
    el.tools.innerHTML = `<div class="fwc-chips">${[['', 'Everyone'], ['customer', L('customers')], ['supplier', L('suppliers')]].map(([k, l]) => `<button class="fwc-chip${type === k ? ' is-on' : ''}" type="button" data-ctype="${k}">${esc(l)}</button>`).join('')}</div>
      <span class="fwc-grow"></span><label class="fwc-qbox"><svg class="i"><use href="#i-search"/></svg><input type="search" id="cQ" placeholder="Name, email, phone, company…" value="${esc(param('q'))}"></label>
      <select class="select" id="cSort" style="height:34px;width:auto"><option value="name">A–Z</option><option value="account"${param('sort') === 'account' ? ' selected' : ''}>By company</option><option value="recent"${param('sort') === 'recent' ? ' selected' : ''}>Newest</option></select>`;
    $$('[data-ctype]', el.tools).forEach((b) => b.addEventListener('click', () => go('contacts', withParams({ type: b.dataset.ctype, page: 1 }))));
    $('#cSort').addEventListener('change', (e) => go('contacts', withParams({ sort: e.target.value, page: 1 })));
    const q = $('#cQ');
    q.addEventListener('input', C.debounce(() => go('contacts', withParams({ q: q.value.trim(), page: 1 }), true), 300));
    const seq = ++S.seq;
    let d;
    try { d = await api.get('contacts.php', { q: param('q'), type, sort: param('sort'), page: param('page', 1) }); } catch (e) { if (seq === S.seq) el.view.innerHTML = C.errorBox(e); return; }
    if (seq !== S.seq) return;
    el.head.querySelector('h1').innerHTML = `${esc(L('contacts'))}<small>${d.total}</small>`;
    if (!d.rows.length) { el.view.innerHTML = C.emptyState('i-users', param('q') ? 'Nobody matches' : 'No people yet', param('q') ? 'Try another search.' : 'Add the people you deal with on each customer or supplier.'); return; }
    const row = (c) => {
      const ph = c.mobile || c.phone;
      return `<tr class="fwc-row" data-acc="${c.account.id}" tabindex="0"><td><div class="fwc-acc">${avatar(c.name, 'fwc-ava--round fwc-ava--sm')}<div class="fwc-acc__text"><span class="fwc-acc__name">${esc(c.name)}${c.primary ? ` <span class="fwc-muted" title="Main contact">${icon('star', 'i--xs')}</span>` : ''}</span><span class="fwc-acc__sub">${esc(c.role)}</span></div></div></td>
        <td><a href="${C.accountUrl(c.account.id, 'contacts')}" data-stop>${esc(c.account.name)}</a><div class="fwc-muted" style="font-size:11.5px">${esc(L(c.account.type))}</div></td>
        <td>${c.email ? `<a href="mailto:${esc(c.email)}" data-stop>${esc(c.email)}</a>` : '<span class="fwc-muted">—</span>'}</td>
        <td class="fwc-nowrap">${ph ? `<a href="${C.telHref(ph)}" data-stop>${esc(C.fmtPhone(ph))}</a>` : '<span class="fwc-muted">—</span>'}</td>
        <td class="r"><span class="fwc-rowacts">${ph ? `<a class="icon-btn icon-btn--sm" href="${C.waHref(ph)}" target="_blank" rel="noopener" data-stop aria-label="WhatsApp" data-tip="WhatsApp">${icon('whatsapp', 'i--sm')}</a><a class="icon-btn icon-btn--sm" href="${C.telHref(ph)}" data-stop aria-label="Call" data-tip="Call">${icon('phone', 'i--sm')}</a>` : ''}${c.email ? `<a class="icon-btn icon-btn--sm" href="mailto:${esc(c.email)}" data-stop aria-label="Email" data-tip="Email">${icon('mail', 'i--sm')}</a>` : ''}</span></td></tr>`;
    };
    el.view.innerHTML = `<div class="fwc-table-wrap"><table class="fwc-table"><thead><tr><th>Name</th><th>Company</th><th>Email</th><th>Phone</th><th></th></tr></thead><tbody>${d.rows.map(row).join('')}</tbody></table></div>
      <div class="fwc-cards is-auto">${d.rows.map((c) => { const ph = c.mobile || c.phone; return `<div class="fwc-card" data-acc="${c.account.id}"><div class="fwc-card__top">${avatar(c.name, 'fwc-ava--round')}<div style="min-width:0"><div class="fwc-card__name">${esc(c.name)}</div><div class="fwc-card__sub">${esc([c.role, c.account.name].filter(Boolean).join(' · '))}</div></div></div>
        <div class="fwc-card__foot"><span class="fwc-grow"></span>${ph ? `<a class="icon-btn icon-btn--sm" href="${C.telHref(ph)}" data-stop aria-label="Call">${icon('phone', 'i--sm')}</a><a class="icon-btn icon-btn--sm" href="${C.waHref(ph)}" target="_blank" rel="noopener" data-stop aria-label="WhatsApp">${icon('whatsapp', 'i--sm')}</a>` : ''}${c.email ? `<a class="icon-btn icon-btn--sm" href="mailto:${esc(c.email)}" data-stop aria-label="Email">${icon('mail', 'i--sm')}</a>` : ''}</div></div>`; }).join('')}</div>${pager(d)}`;
    el.view.onclick = (e) => {
      if (e.target.closest('[data-stop]') || e.target.closest('a')) return;
      const pg = e.target.closest('[data-page]');
      if (pg) { go('contacts', withParams({ page: pg.dataset.page })); return; }
      const r = e.target.closest('[data-acc]');
      if (r) location.href = C.accountUrl(+r.dataset.acc, 'contacts');
    };
  }

  // ============================================================ activity
  async function renderActivity() {
    head(BOOT.company, 'Activity', BOOT.is_admin ? `<a class="btn btn--sm btn--ghost" href="/crm/api/transfer.php?op=export&what=activity">${icon('download')}<span>Export</span></a>` : '');
    const type = param('type');
    const by = param('by');
    el.tools.hidden = false;
    el.tools.innerHTML = `<div class="fwc-chips"><button class="fwc-chip${!type ? ' is-on' : ''}" type="button" data-atype="">Everything</button>${(BOOT.activity_types || []).map((t) => `<button class="fwc-chip${type === t.key ? ' is-on' : ''}" type="button" data-atype="${t.key}">${C.actIcon(t.key)}${esc(t.label)}</button>`).join('')}</div>
      <span class="fwc-grow"></span><select class="select" id="aBy" style="height:34px;width:auto"><option value="">By anyone</option><option value="me"${by === 'me' ? ' selected' : ''}>By me</option>${BOOT.people.filter((p) => p.id !== BOOT.me.id).map((p) => `<option value="${p.id}"${by === String(p.id) ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}</select>`;
    $$('[data-atype]', el.tools).forEach((b) => b.addEventListener('click', () => go('activity', withParams({ type: b.dataset.atype }))));
    $('#aBy').addEventListener('change', (e) => go('activity', withParams({ by: e.target.value })));
    const seq = ++S.seq;
    el.view.innerHTML = `<div class="fwc-feed" id="feed"></div>`;
    const feed = $('#feed');
    let before = '';
    let lastDay = '';
    const load = async () => {
      let d;
      try { d = await api.get('activity.php', { type, by, before, limit: 40 }); } catch (e) { if (seq === S.seq) feed.innerHTML = C.errorBox(e); return; }
      if (seq !== S.seq) return;
      $$('[data-more]', feed).forEach((x) => x.remove());
      if (!d.rows.length && !before) { feed.innerHTML = C.emptyState('ic-activity', 'No activity yet', 'Calls, meetings, emails and notes logged on any account appear here.'); return; }
      let html = '';
      d.rows.forEach((a) => {
        const day = a.at.slice(0, 10);
        if (day !== lastDay) { html += `</div><div class="fwc-day">${esc(day === BOOT.today ? 'Today' : C.daysFrom(day) === -1 ? 'Yesterday' : fmtDate(day, 'always'))}</div><div class="fwc-tl">`; lastDay = day; }
        html += F().activityItem(a);
      });
      feed.insertAdjacentHTML('beforeend', '<div class="fwc-tl">' + html + '</div>' + (d.more ? `<button class="btn btn--block" type="button" data-more style="margin-top:12px">Load more</button>` : ''));
      before = d.rows.length ? d.rows[d.rows.length - 1].at : before;
      const m = $('[data-more]', feed);
      if (m) m.addEventListener('click', load);
    };
    F().bindActivity(feed, renderActivity);
    load();
  }

  // ========================================================== compliance
  async function renderCompliance() {
    head(BOOT.company, 'Compliance', BOOT.is_admin ? `<a class="btn btn--sm btn--ghost" href="/crm/settings.php#compliance">${icon('ic-sliders')}<span>Document types &amp; policy</span></a>` : '');
    const state = param('state');
    const seq = ++S.seq;
    el.view.innerHTML = `<div style="padding:18px">${'<div class="fwc-skel" style="height:52px;margin-bottom:8px"></div>'.repeat(6)}</div>`;
    let d;
    try { d = await api.get('compliance.php'); } catch (e) { if (seq === S.seq) el.view.innerHTML = C.errorBox(e); return; }
    if (seq !== S.seq) return;
    const c = d.counts;
    el.tools.hidden = false;
    const chips = [['', 'All', c.missing + c.expired + c.expiring + c.valid + c.none], ['missing', 'Missing', c.missing], ['expired', 'Expired', c.expired], ['expiring', 'Expiring', c.expiring], ['valid', 'In order', c.valid]];
    el.tools.innerHTML = `<div class="fwc-chips">${chips.map(([k, l, n]) => `<button class="fwc-chip${state === k ? ' is-on' : ''}${k === 'missing' || k === 'expired' ? ' fwc-chip--danger' : ''}" type="button" data-state="${k}">${esc(l)} <small>${n || ''}</small></button>`).join('')}</div>
      <span class="fwc-grow"></span><span class="fwc-muted" style="font-size:12.5px">${d.policy.block ? `${icon('lock', 'i--xs')} ${esc(L('suppliers'))} with missing or expired documents are blocked${c.blocked ? ` (${c.blocked})` : ''}` : 'Blocking is off'}</span>`;
    $$('[data-state]', el.tools).forEach((b) => b.addEventListener('click', () => go('compliance', withParams({ state: b.dataset.state }))));
    if (!d.types.length) {
      el.view.innerHTML = C.emptyState('shield', 'No document types yet', `Tell Flowwork which documents you collect from ${lower('suppliers')} — tax clearance, B-BBEE, insurance — and it keeps track of what is missing and what is about to expire.`,
        BOOT.is_admin ? `<a class="btn btn--primary" href="/crm/settings.php#compliance">${icon('plus')}Set up document types</a>` : '');
      return;
    }
    const rows = d.accounts.filter((a) => !state || a.status === state);
    const map = { none: ['muted', 'Nothing required'], valid: ['success', 'In order'], expiring: ['warning', 'Expiring'], expired: ['danger', 'Expired'], missing: ['danger', 'Missing'] };
    el.view.innerHTML = `${d.soon.length && !state ? `<div style="padding:16px 20px 0"><section class="fwc-panel"><div class="fwc-panel__head"><h3>Expired recently or expiring within ${d.policy.window} days</h3></div><div class="fwc-rows">${d.soon.map((x) => `
        <a href="${C.accountUrl(x.account_id, 'compliance')}"><span class="fwc-type" data-t="${x.status === 'expired' ? 'issue' : 'meeting'}">${icon('shield')}</span><span class="fwc-rows__text"><b>${esc(x.type)} · ${esc(x.account)}</b><small>${esc(x.reference || 'No reference')}</small></span>
        <span class="fwc-rows__side">${esc(fmtDate(x.expiry_date, 'always'))}<small class="${x.days < 0 ? 'is-late' : ''}">${x.days < 0 ? 'expired ' + esc(relDay(x.expiry_date)) : esc(relDay(x.expiry_date))}</small></span></a>`).join('')}</div></section></div>` : ''}
      ${rows.length ? `<div class="fwc-table-wrap" style="margin-top:8px"><table class="fwc-table"><thead><tr><th>Account</th><th>State</th><th>Needs attention</th><th class="r">Documents</th><th></th></tr></thead><tbody>${rows.map((a) => {
        const [t, l] = map[a.status];
        const issues = [...a.missing.map((x) => 'Missing: ' + x), ...a.expired.map((x) => 'Expired: ' + x), ...a.expiring.map((x) => 'Expiring: ' + x)];
        return `<tr class="fwc-row" data-acc="${a.id}" tabindex="0"><td><div class="fwc-acc">${avatar(a.name, 'fwc-ava--sm')}<div class="fwc-acc__text"><span class="fwc-acc__name">${esc(a.name)}</span><span class="fwc-acc__sub">${esc(L(a.type))}</span></div></div></td>
          <td><span class="fwc-pill" data-tone="${t}">${esc(l)}${a.blocking ? ' · blocked' : ''}</span></td><td style="font-size:12.5px;color:var(--ink-2)">${esc(issues.join(' · ') || '—')}</td><td class="r">${a.docs}</td>
          <td class="r">${BOOT.can_write ? `<button class="btn btn--sm" type="button" data-upload="${a.id}">${icon('upload')}Add</button>` : ''}</td></tr>`;
      }).join('')}</tbody></table></div>
      <div class="fwc-cards is-auto">${rows.map((a) => { const [t, l] = map[a.status]; return `<div class="fwc-card" data-acc="${a.id}"><div class="fwc-card__top">${avatar(a.name)}<div style="min-width:0"><div class="fwc-card__name">${esc(a.name)}</div><div class="fwc-card__sub">${esc([...a.missing, ...a.expired].join(', ') || L(a.type))}</div></div></div><div class="fwc-card__foot"><span class="fwc-pill" data-tone="${t}">${esc(l)}</span></div></div>`; }).join('')}</div>`
      : C.emptyState('shield', 'Nothing here', 'No accounts in this state.', '', true)}`;
    el.view.onclick = (e) => {
      const up = e.target.closest('[data-upload]');
      if (up) { e.stopPropagation(); F().complianceDoc({ account_id: +up.dataset.upload, types: d.types, onSaved: renderCompliance }); return; }
      const r = e.target.closest('[data-acc]');
      if (r) location.href = C.accountUrl(+r.dataset.acc, 'compliance');
    };
  }

  // ---------------------------------------------------------------- start
  C.initChrome();
  const fab = $('#fab');
  if (fab) fab.addEventListener('click', () => C.create(fab.dataset.new, { onSaved: () => render() }));
  // legacy links: ?tab=suppliers · ?type=supplier · ?new=customer
  const qp = new URLSearchParams(location.search);
  const legacy = { suppliers: 'suppliers', customers: 'customers', overview: 'overview', pipeline: 'pipeline', supplier: 'suppliers', customer: 'customers' }[qp.get('tab') || qp.get('type') || ''];
  if (legacy && !location.hash) history.replaceState(null, '', '/crm/#' + legacy);
  else if (qp.toString()) history.replaceState(null, '', '/crm/' + location.hash);
  readRoute();
  if (['customer', 'supplier', 'deal', 'task'].includes(qp.get('new') || '')) setTimeout(() => C.create(qp.get('new')), 50);
  document.addEventListener('crm:boot', render);
})();
