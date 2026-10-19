// /qi/js/qi-app.js — Quotes & Invoices home: the overview and the lists.
// Routes (hash): #overview · #quotes · #invoices · #credit_notes · #recurring
// with ?status=&q=&sort=&dir=&page=&from=&to=. Data: /qi/api/overview.php and
// /qi/api/list.php. Documents open in /qi/view.php; recurring templates in the editor.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toastError, popover, money, compact, fmtDate, relDue, relTime, pill, typeIcon, viewUrl, editUrl, BOOT } = QI;

  const ROUTES = {
    overview: { title: 'Overview' },
    quotes: { type: 'quote', title: 'Quotes', noun: 'quote', chips: [['all', 'All'], ['draft', 'Drafts'], ['open', 'Open'], ['accepted', 'Accepted'], ['declined', 'Declined'], ['expired', 'Expired']] },
    invoices: { type: 'invoice', title: 'Invoices', noun: 'invoice', chips: [['all', 'All'], ['draft', 'Drafts'], ['due', 'Awaiting payment'], ['overdue', 'Overdue'], ['paid', 'Paid'], ['void', 'Void & written off'], ['archived', 'Archived']] },
    credit_notes: { type: 'credit_note', title: 'Credit notes', noun: 'credit note', chips: [['all', 'All'], ['draft', 'Drafts'], ['approved', 'Approved'], ['applied', 'Applied'], ['cancelled', 'Cancelled']] },
    recurring: { type: 'recurring', title: 'Recurring invoices', noun: 'recurring invoice', chips: [['all', 'All'], ['active', 'Active'], ['paused', 'Paused'], ['ended', 'Ended']] },
  };
  const SORTS = {
    quote: [['date', 'Date'], ['number', 'Number'], ['customer', 'Customer'], ['due', 'Valid until'], ['amount', 'Amount'], ['status', 'Status']],
    invoice: [['date', 'Date'], ['number', 'Number'], ['customer', 'Customer'], ['due', 'Due date'], ['amount', 'Amount'], ['balance', 'Balance'], ['status', 'Status']],
    credit_note: [['date', 'Date'], ['number', 'Number'], ['customer', 'Customer'], ['amount', 'Amount'], ['status', 'Status']],
    recurring: [['date', 'Next run'], ['number', 'Name'], ['customer', 'Customer'], ['status', 'Status']],
  };
  const S = { route: 'overview', params: new URLSearchParams(), seq: 0, counts: {} };
  const el = { head: $('#head'), tools: $('#tools'), view: $('#view'), search: $('#searchInput'), clear: $('#searchClear') };

  // ------------------------------------------------------------- routing
  function readRoute() {
    const h = location.hash.replace(/^#/, '');
    const i = h.indexOf('?');
    const base = i >= 0 ? h.slice(0, i) : h;
    S.route = ROUTES[base] ? base : 'overview';
    S.params = new URLSearchParams(i >= 0 ? h.slice(i + 1) : '');
    if (el.search && document.activeElement !== el.search) {
      el.search.value = S.params.get('q') || '';
      el.clear.hidden = !el.search.value;
    }
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
    const r = ROUTES[S.route];
    const newType = r.type && r.type !== 'recurring' ? r.type : (S.route === 'recurring' ? 'recurring' : 'invoice');
    const newHref = editUrl(newType);
    const label = 'New ' + (newType === 'recurring' ? 'recurring invoice' : QI.TYPE_NAMES[newType].toLowerCase());
    ['newBtn', 'fab'].forEach((id) => { const a = $('#' + id); if (a) a.href = newHref; });
    ['newLabel', 'fabLabel'].forEach((id) => { const s = $('#' + id); if (s) s.textContent = label; });
    document.title = r.title + ' · ' + BOOT.company;
    QI.toggleDrawer(false);
    el.view.classList.remove('is-entering');
    void el.view.offsetWidth;
    el.view.classList.add('is-entering');
    if (S.route === 'overview') return renderOverview();
    return renderList();
  }

  // ------------------------------------------------------------ overview
  async function renderOverview() {
    el.tools.hidden = true;
    const hour = new Date().getHours();
    const greet = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    el.head.innerHTML = `<div class="fwq-head__title"><div class="fwq-eyebrow">${esc(BOOT.company)}</div><h1>Overview</h1></div>
      <div class="fwq-head__actions">${BOOT.can_write ? `<a class="btn" href="${editUrl('quote')}">${icon('iq-quote')}New quote</a><a class="btn btn--primary" href="${editUrl('invoice')}">${icon('plus')}New invoice</a>` : ''}</div>`;
    el.view.innerHTML = `<div class="fwq-ov"><div class="fwq-kpis">${'<div class="fwq-kpi fwq-skel" style="height:150px"></div>'.repeat(4)}</div></div>`;
    const seq = ++S.seq;
    let d;
    try { d = await api.get('overview.php'); } catch (e) { if (seq === S.seq) el.view.innerHTML = errorBox(e); return; }
    if (seq !== S.seq) return;
    S.counts = d.counts;
    paintCounts();
    const k = d.kpi;
    const change = k.collected_last_month > 0 ? Math.round((k.collected_month - k.collected_last_month) / k.collected_last_month * 100) : null;
    const brief = [];
    if (k.overdue_count) brief.push(`<b>${k.overdue_count} overdue ${k.overdue_count === 1 ? 'invoice' : 'invoices'}</b> worth ${esc(compact(k.overdue))}`);
    if (k.due_week) brief.push(`${k.due_week} due this week`);
    if (k.expiring) brief.push(`${k.expiring} ${k.expiring === 1 ? 'quote expires' : 'quotes expire'} within 7 days`);
    if (k.draft_invoices) brief.push(`${k.draft_invoices} draft ${k.draft_invoices === 1 ? 'invoice' : 'invoices'} not sent yet`);
    const coll = d.series.map((m) => m.collected);
    const inv = d.series.map((m) => m.invoiced);
    el.view.innerHTML = `<div class="fwq-ov">
      <div class="fwq-hello"><div><div class="fwq-eyebrow">${esc(fmtDate(BOOT.today))}</div>
        <h2>${esc(greet)}${BOOT.first_name ? `, <em>${esc(BOOT.first_name)}</em>` : ''}.</h2>
        <p>${brief.length ? brief.join(' · ') + '.' : 'Nothing is overdue and nothing is waiting on you. Lovely.'}</p></div></div>
      <div class="fwq-kpis">
        ${kpi('Outstanding', k.outstanding, `${k.open_invoices} open ${k.open_invoices === 1 ? 'invoice' : 'invoices'}`, '#invoices?status=due', 'var(--gold)', inv)}
        ${kpi('Overdue', k.overdue, k.overdue_count ? `${k.overdue_count} ${k.overdue_count === 1 ? 'invoice' : 'invoices'} past due` : 'All current', '#invoices?status=overdue', 'var(--danger)', null)}
        ${kpi('Collected this month', k.collected_month, change === null ? (k.collection_rate !== null ? `${k.collection_rate}% collected · 90 days` : 'Receipts this month') : `<span class="${change >= 0 ? 'up' : 'down'}">${change >= 0 ? '▲' : '▼'} ${Math.abs(change)}%</span> vs last month`, '#invoices?status=paid', 'var(--success)', coll)}
        ${kpi('Open quotes', k.pipeline, `${k.open_quotes} open${k.win_rate !== null ? ` · ${k.win_rate}% won` : ''}`, '#quotes?status=open', 'var(--info)', null)}
      </div>
      <div class="fwq-grid2">
        <section class="fwq-panel"><div class="fwq-panel__head"><h3>Cash collected</h3><div class="fwq-legend"><span><i style="background:var(--gold)"></i>Collected</span><span><i style="background:var(--accent);opacity:.35"></i>Invoiced</span></div></div>
          ${bars(d.series)}</section>
        <section class="fwq-panel"><div class="fwq-panel__head"><h3>Receivables by age</h3><a href="#invoices?status=overdue">Overdue</a></div>${aging(d.aging)}
          ${d.debtors.length ? `<div class="fwq-panel__head" style="margin-top:18px"><h3 style="font-size:17px">Largest balances</h3></div><div class="fwq-attn">${d.debtors.map((x) => `
            <a href="/crm/account_view.php?id=${x.id}"><span class="fwq-type" data-t="invoice">${icon('users')}</span><span class="fwq-attn__text"><b>${esc(x.name)}</b><small>${x.count} open · oldest due ${esc(fmtDate(x.oldest))}</small></span><span class="fwq-attn__amt">${esc(money(x.amount))}</span></a>`).join('')}</div>` : ''}
        </section>
      </div>
      <div class="fwq-grid2">
        <section class="fwq-panel"><div class="fwq-panel__head"><h3>Needs attention</h3></div>${d.attention.length ? `<div class="fwq-attn">${d.attention.map((a) => `
          <a href="${viewUrl(a.type, a.id)}">${typeIcon(a.type)}<span class="fwq-attn__text"><b>${esc(a.number)} · ${esc(a.customer || '—')}</b><small>${a.kind === 'overdue' ? `Due ${esc(fmtDate(a.date))}` : `Valid until ${esc(fmtDate(a.date))}`}</small></span>
          <span class="fwq-attn__amt">${esc(money(a.amount, a.symbol))}<small style="${a.kind === 'expiring' ? 'color:var(--warning)' : ''}">${a.kind === 'overdue' ? `${a.days} ${a.days === 1 ? 'day' : 'days'} late` : (a.days === 0 ? 'expires today' : `expires in ${a.days} ${a.days === 1 ? 'day' : 'days'}`)}</small></span></a>`).join('')}</div>`
          : `<p class="fwq-muted" style="margin:6px 0 10px">Nothing needs you right now.</p>`}</section>
        <section class="fwq-panel"><div class="fwq-panel__head"><h3>Recently changed</h3></div>${d.recent.length ? `<div class="fwq-attn">${d.recent.map((r) => `
          <a href="${viewUrl(r.type, r.id)}">${typeIcon(r.type)}<span class="fwq-attn__text"><b>${esc(r.number)} · ${esc(r.customer || '—')}</b><small>${esc(relTime(r.updated_at))}</small></span>
          <span class="fwq-attn__amt">${esc(money(r.total, r.symbol))}<small style="color:inherit">${pill(r.status)}</small></span></a>`).join('')}</div>`
          : emptyState('overview')}</section>
      </div></div>`;
  }
  function kpi(label, value, note, href, color, spark) {
    return `<a class="fwq-kpi" href="${href}" style="--c:${color}"><span class="fwq-kpi__label">${esc(label)}</span>
      <span class="fwq-kpi__value"><small>R</small>${esc(compact(value, ''))}</span><span class="fwq-kpi__note">${note}</span>${spark ? sparkline(spark, color) : ''}</a>`;
  }
  function sparkline(vals, color) {
    const max = Math.max(...vals, 1);
    const pts = vals.map((v, i) => `${(i / (vals.length - 1)) * 100},${48 - (v / max) * 40}`).join(' ');
    return `<svg class="fwq-spark" viewBox="0 0 100 52" preserveAspectRatio="none" aria-hidden="true"><polyline points="${pts}" fill="none" stroke="${color}" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/><polygon points="0,52 ${pts} 100,52" fill="${color}" opacity=".08"/></svg>`;
  }
  function bars(series) {
    const max = Math.max(...series.map((m) => Math.max(m.collected, m.invoiced)), 1);
    return `<div class="fwq-bars">${series.map((m) => `
      <div class="fwq-bar" data-tip="${esc(m.label + ' ' + m.year + ': ' + money(m.collected) + ' collected · ' + money(m.invoiced) + ' invoiced')}">
        <div class="fwq-bar__stack" style="position:relative">
          <div class="fwq-bar__fill is-invoiced" style="position:absolute;left:0;right:0;bottom:0;height:${(m.invoiced / max) * 100}%"></div>
          <div class="fwq-bar__fill" style="position:relative;width:62%;margin:0 auto;height:${(m.collected / max) * 100}%"></div>
        </div><span class="fwq-bar__label">${esc(m.label)}</span></div>`).join('')}</div>`;
  }
  function aging(rows) {
    const max = Math.max(...rows.map((r) => r.amount), 1);
    const colors = { current: 'var(--success)', 30: 'var(--gold)', 60: 'var(--warning)', 90: '#c4622d', '90p': 'var(--danger)' };
    return `<div class="fwq-aging">${rows.map((r) => `<div class="fwq-aging__row"><span>${esc(r.label)}</span>
      <span class="fwq-aging__track"><i style="--p:${(r.amount / max) * 100}%;--c:${colors[r.key]}"></i></span><b>${esc(compact(r.amount))}</b></div>`).join('')}</div>`;
  }

  // ---------------------------------------------------------------- lists
  async function renderList() {
    const r = ROUTES[S.route];
    const type = r.type;
    const status = param('status', 'all');
    const sort = param('sort');
    const dir = param('dir', 'desc');
    el.head.innerHTML = `<div class="fwq-head__title"><div class="fwq-eyebrow">${esc(BOOT.company)}</div><h1>${esc(r.title)}</h1></div>
      <div class="fwq-head__actions">
        <button class="btn btn--sm btn--ghost" id="rangeBtn" type="button">${icon('calendar')}${param('from') || param('to') ? esc([fmtDate(param('from')), fmtDate(param('to'))].filter(Boolean).join(' – ')) : 'Any date'}</button>
        <button class="btn btn--sm btn--ghost" id="sortBtn" type="button">${icon('sort')}${esc((SORTS[type].find((s) => s[0] === sort) || SORTS[type][0])[1])}</button>
        ${BOOT.can_write ? `<a class="btn btn--primary" href="${editUrl(type)}">${icon('plus')}New ${esc(r.noun)}</a>` : ''}</div>`;
    el.tools.hidden = false;
    el.tools.innerHTML = `<div class="fwq-chips" role="tablist">${r.chips.map(([k, l]) => `<button class="fwq-chip${status === k ? ' is-on' : ''}" type="button" role="tab" aria-selected="${status === k}" data-chip="${k}">${esc(l)} <small data-chip-count="${k}">${S.counts[type + ':' + k] ?? ''}</small></button>`).join('')}</div>`;
    if (!el.view.querySelector('.fwq-table-wrap')) el.view.innerHTML = loadingRows();
    const seq = ++S.seq;
    let d;
    try {
      d = await api.get('list.php', { type, status, q: param('q'), sort, dir, page: param('page', 1), from: param('from'), to: param('to') });
    } catch (e) { if (seq === S.seq) el.view.innerHTML = errorBox(e); return; }
    if (seq !== S.seq) return;
    Object.entries(d.counts).forEach(([k, v]) => { S.counts[type + ':' + k] = v; const c = $(`[data-chip-count="${k}"]`, el.tools); if (c) c.textContent = v; });
    if (status === 'all') { S.counts[S.route] = d.counts.all; paintCounts(); }
    if (!d.rows.length) { el.view.innerHTML = param('q') || status !== 'all' || param('from') || param('to') ? noMatch() : emptyState(S.route); return; }
    el.view.innerHTML = (type === 'recurring' ? recurringTable(d) : docTable(type, d)) + pager(d);
  }

  function th(label, key, cls = '') {
    const sort = param('sort');
    const on = sort === key;
    const dir = on && param('dir', 'desc') === 'asc' ? 'desc' : 'asc';
    return `<th class="${cls}${on ? ' is-sorted' : ''}"><button type="button" data-sort="${key}" data-dir="${on ? dir : (key === 'customer' || key === 'number' ? 'asc' : 'desc')}">${esc(label)}${on ? (param('dir', 'desc') === 'asc' ? ' ↑' : ' ↓') : ''}</button></th>`;
  }

  function docTable(type, d) {
    const isInv = type === 'invoice';
    const isQuote = type === 'quote';
    const rows = d.rows.map((x) => {
      const late = isInv && x.status.key === 'overdue';
      const dueDate = isInv ? x.due_date : isQuote ? x.expiry_date : null;
      return `<tr class="fwq-row" tabindex="0" data-href="${viewUrl(type, x.id)}">
        <td><div class="fwq-doc">${typeIcon(type)}<div class="fwq-doc__text"><a class="fwq-doc__num" href="${viewUrl(type, x.id)}">${esc(x.number)}</a>${x.reference ? `<span class="fwq-doc__sub">${esc(x.reference)}</span>` : ''}</div></div></td>
        <td><span class="fwq-cust">${esc(x.customer || '—')}</span></td>
        <td>${esc(fmtDate(x.issue_date))}</td>
        ${type === 'credit_note' ? '' : `<td class="${late ? 'fwq-due-late' : ''}">${esc(fmtDate(dueDate))}${late ? `<small style="display:block;font-weight:500">${esc(relDue(dueDate))}</small>` : ''}</td>`}
        <td class="r"><span class="fwq-amount">${esc(money(x.total, x.symbol))}${x.currency !== 'ZAR' ? `<small>${esc(x.currency)}</small>` : ''}</span></td>
        ${isInv ? `<td class="r"><span class="fwq-amount">${x.balance > 0.004 && !['void', 'written_off', 'draft'].includes(x.status.key) ? esc(money(x.balance, x.symbol)) : '<span class="fwq-muted">—</span>'}</span></td>` : ''}
        <td>${pill(x.status)}</td></tr>`;
    }).join('');
    const cards = d.rows.map((x) => `<a class="fwq-card" href="${viewUrl(type, x.id)}">${typeIcon(type)}
      <span class="fwq-card__main"><span class="fwq-card__num">${esc(x.number)}</span><span class="fwq-card__cust" style="display:block">${esc(x.customer || '—')}</span></span>
      <span class="fwq-card__side"><span class="fwq-card__amount">${esc(money(isInv && x.balance > 0.004 && x.status.key !== 'draft' ? x.balance : x.total, x.symbol))}</span>${pill(x.status)}</span></a>`).join('');
    const sum = `<div class="fwq-sum"><span><b>${d.total}</b> ${d.total === 1 ? ROUTES[S.route].noun : ROUTES[S.route].noun + 's'}</span><span>Total <b>${esc(money(d.sum_zar))}</b></span>${d.balance_zar !== null ? `<span>Outstanding <b>${esc(money(d.balance_zar))}</b></span>` : ''}</div>`;
    return `${sum}<div class="fwq-table-wrap"><table class="fwq-table"><thead><tr>
      ${th('Number', 'number')}${th('Customer', 'customer')}${th('Date', 'date')}${type === 'credit_note' ? '' : th(isInv ? 'Due' : 'Valid until', 'due')}${th('Amount', 'amount', 'r')}${isInv ? th('Balance', 'balance', 'r') : ''}${th('Status', 'status')}
      </tr></thead><tbody>${rows}</tbody></table></div><div class="fwq-cards">${cards}</div>`;
  }

  function recurringTable(d) {
    const rows = d.rows.map((x) => `<tr class="fwq-row" tabindex="0" data-href="${editUrl('recurring', x.id)}">
      <td><div class="fwq-doc">${typeIcon('recurring')}<div class="fwq-doc__text"><a class="fwq-doc__num" href="${editUrl('recurring', x.id)}">${esc(x.number)}</a><span class="fwq-doc__sub">${esc(x.frequency)}${x.auto_send ? ' · sent automatically' : ''}</span></div></div></td>
      <td><span class="fwq-cust">${esc(x.customer || '—')}</span></td>
      <td>${esc(x.status.key === 'active' ? fmtDate(x.next_run) : '—')}${x.end_date ? `<small style="display:block" class="fwq-muted">until ${esc(fmtDate(x.end_date))}</small>` : ''}</td>
      <td class="r"><span class="fwq-amount">${esc(money(x.total, x.symbol))}</span></td>
      <td>${x.generated !== null ? `${x.generated}` : '—'}</td>
      <td>${pill(x.status)}</td></tr>`).join('');
    const cards = d.rows.map((x) => `<a class="fwq-card" href="${editUrl('recurring', x.id)}">${typeIcon('recurring')}
      <span class="fwq-card__main"><span class="fwq-card__num">${esc(x.number)}</span><span class="fwq-card__cust" style="display:block">${esc(x.customer || '—')} · ${esc(x.frequency)}</span></span>
      <span class="fwq-card__side"><span class="fwq-card__amount">${esc(money(x.total, x.symbol))}</span>${pill(x.status)}</span></a>`).join('');
    return `<div class="fwq-sum"><span><b>${d.total}</b> ${d.total === 1 ? 'template' : 'templates'}</span></div><div class="fwq-table-wrap"><table class="fwq-table"><thead><tr>
      ${th('Name', 'number')}${th('Customer', 'customer')}${th('Next invoice', 'date')}<th class="r">Amount</th><th>Invoices made</th>${th('Status', 'status')}
      </tr></thead><tbody>${rows}</tbody></table></div><div class="fwq-cards">${cards}</div>`;
  }

  function pager(d) {
    const pages = Math.max(1, Math.ceil(d.total / d.per));
    if (pages <= 1) return '';
    return `<div class="fwq-pager"><span>Page ${d.page} of ${pages}</span><span style="display:flex;gap:6px">
      <button class="btn btn--sm" type="button" data-page="${d.page - 1}" ${d.page <= 1 ? 'disabled' : ''}>${icon('chev-left')}Previous</button>
      <button class="btn btn--sm" type="button" data-page="${d.page + 1}" ${d.page >= pages ? 'disabled' : ''}>Next${icon('chev-right')}</button></span></div>`;
  }
  const loadingRows = () => `<div style="padding:18px 20px">${'<div class="fwq-skel" style="height:44px;margin-bottom:10px"></div>'.repeat(6)}</div>`;
  const errorBox = (e) => `<div class="fwq-empty"><div class="fwm-empty__art">${icon('alert')}</div><h3>Could not load this</h3><p>${esc(e.message)}</p><button class="btn" type="button" onclick="location.reload()">Try again</button></div>`;
  function emptyState(route) {
    const r = ROUTES[route] || ROUTES.invoices;
    const art = { quotes: 'iq-quote', invoices: 'iq-invoice', credit_notes: 'iq-credit', recurring: 'iq-recurring', overview: 'iq-invoice' }[route] || 'iq-invoice';
    const msg = {
      quotes: 'Send a beautiful quote your customer can accept online — it turns into an invoice in one click.',
      invoices: 'Create an invoice, send it as a PDF and record payments as they come in.',
      credit_notes: 'Credit notes correct an invoice after it has been issued. Make one from an invoice, or start fresh.',
      recurring: 'Bill retainers, rentals and subscriptions automatically — weekly, monthly, quarterly or yearly.',
      overview: 'Your recent quotes and invoices will appear here.',
    }[route];
    const t = r.type || 'invoice';
    return `<div class="fwq-empty"><div class="fwm-empty__art">${icon(art)}</div><h3>${route === 'overview' ? 'Nothing yet' : 'No ' + esc(r.title.toLowerCase()) + ' yet'}</h3><p>${esc(msg)}</p>
      ${BOOT.can_write && route !== 'overview' ? `<a class="btn btn--primary" href="${editUrl(t)}">${icon('plus')}New ${esc(r.noun)}</a>` : ''}</div>`;
  }
  const noMatch = () => `<div class="fwq-empty"><div class="fwm-empty__art">${icon('search')}</div><h3>Nothing matches</h3><p>Try another search, status or date range.</p><button class="btn" type="button" data-act="clear-filters">Clear filters</button></div>`;

  function paintCounts() {
    const c = S.counts;
    const map = { quotes: c.quotes, invoices: c.invoices, credit_notes: c.credit_notes, recurring: c.recurring };
    Object.entries(map).forEach(([k, v]) => { const n = $(`[data-count="${k}"]`); if (n && v !== undefined) n.textContent = v || ''; });
  }

  // --------------------------------------------------------------- events
  el.tools.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-chip]');
    if (chip) go(S.route, withParams({ status: chip.dataset.chip, page: 1 }));
  });
  el.view.addEventListener('click', (e) => {
    const sort = e.target.closest('[data-sort]');
    if (sort) { go(S.route, withParams({ sort: sort.dataset.sort, dir: sort.dataset.dir, page: 1 })); return; }
    const pg = e.target.closest('[data-page]');
    if (pg && !pg.disabled) { go(S.route, withParams({ page: pg.dataset.page })); el.view.scrollTop = 0; return; }
    if (e.target.closest('[data-act="clear-filters"]')) { go(S.route, {}); return; }
    if (e.target.closest('a')) return;
    const row = e.target.closest('tr[data-href]');
    if (row) { if (e.metaKey || e.ctrlKey) window.open(row.dataset.href, '_blank'); else location.href = row.dataset.href; }
  });
  el.view.addEventListener('keydown', (e) => {
    const row = e.target.closest('tr[data-href]');
    if (!row) return;
    if (e.key === 'Enter') location.href = row.dataset.href;
    if (e.key === 'ArrowDown') { e.preventDefault(); (row.nextElementSibling || row).focus(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); (row.previousElementSibling || row).focus(); }
  });
  el.head.addEventListener('click', (e) => {
    const sortBtn = e.target.closest('#sortBtn');
    const r = ROUTES[S.route];
    if (sortBtn && r.type) {
      popover(sortBtn, SORTS[r.type].map(([k, l]) => `<button class="fwm-pop__item${param('sort') === k ? ' is-active' : ''}" data-v="${k}">${esc(l)}${param('sort') === k ? `<small>${param('dir', 'desc') === 'asc' ? 'Ascending' : 'Descending'}</small>` : ''}</button>`).join('')
        + `<div class="fwm-pop__sep"></div><button class="fwm-pop__item" data-v="__dir">${icon('sort')}${param('dir', 'desc') === 'asc' ? 'Descending' : 'Ascending'}</button>`, {
        onClick: (it) => {
          if (it.dataset.v === '__dir') go(S.route, withParams({ dir: param('dir', 'desc') === 'asc' ? 'desc' : 'asc', sort: param('sort') || 'date' }));
          else go(S.route, withParams({ sort: it.dataset.v, page: 1 }));
        },
      });
    }
    const rangeBtn = e.target.closest('#rangeBtn');
    if (rangeBtn) {
      const t = new Date();
      const ymd = QI.ymd;
      const first = new Date(t.getFullYear(), t.getMonth(), 1);
      const lastMonthStart = new Date(t.getFullYear(), t.getMonth() - 1, 1);
      const lastMonthEnd = new Date(t.getFullYear(), t.getMonth(), 0);
      const fyStart = t.getMonth() >= 2 ? new Date(t.getFullYear(), 2, 1) : new Date(t.getFullYear() - 1, 2, 1);
      const presets = [['This month', ymd(first), ymd(t)], ['Last month', ymd(lastMonthStart), ymd(lastMonthEnd)], ['Last 90 days', ymd(new Date(t.getTime() - 90 * 86400000)), ymd(t)],
        ['This financial year (Mar–Feb)', ymd(fyStart), ymd(t)], ['This calendar year', ymd(new Date(t.getFullYear(), 0, 1)), ymd(t)]];
      popover(rangeBtn, `<div class="fwm-pop__title">Date range</div>${presets.map(([l, f, to], i) => `<button class="fwm-pop__item" data-v="p${i}">${esc(l)}</button>`).join('')}
        <div class="fwm-pop__sep"></div><div style="display:flex;gap:6px;padding:4px 6px"><input class="fwm-pop__input" type="date" id="rFrom" value="${esc(param('from'))}" aria-label="From"><input class="fwm-pop__input" type="date" id="rTo" value="${esc(param('to'))}" aria-label="To"></div>
        <div style="display:flex;gap:6px;padding:4px 6px 6px"><button class="btn btn--sm btn--primary" data-v="apply" style="flex:1">Apply</button><button class="btn btn--sm" data-v="clear">Any date</button></div>`, {
        width: 280, noFocus: true,
        onClick: (it, ev) => {
          const v = it.dataset.v;
          if (v === 'apply') go(S.route, withParams({ from: $('#rFrom').value, to: $('#rTo').value, page: 1 }));
          else if (v === 'clear') go(S.route, withParams({ from: '', to: '', page: 1 }));
          else { const p = presets[+v.slice(1)]; go(S.route, withParams({ from: p[1], to: p[2], page: 1 })); }
        },
      });
    }
  });
  if (el.search) {
    const run = QI.debounce(() => {
      const q = el.search.value.trim();
      el.clear.hidden = !q;
      const route = S.route === 'overview' ? 'invoices' : S.route;
      go(route, Object.assign(withParams({ q, page: 1 }), S.route === 'overview' ? { status: '' } : {}), true);
    }, 260);
    el.search.addEventListener('input', run);
    $('#searchForm').addEventListener('submit', (e) => { e.preventDefault(); run(); });
    el.clear.addEventListener('click', () => { el.search.value = ''; run(); el.search.focus(); });
  }
  const more = $('#newMore');
  if (more) more.addEventListener('click', () => popover(more, `
    <a class="fwm-pop__item" data-v="q" href="${editUrl('quote')}">${icon('iq-quote')}New quote</a>
    <a class="fwm-pop__item" data-v="i" href="${editUrl('invoice')}">${icon('iq-invoice')}New invoice</a>
    <a class="fwm-pop__item" data-v="c" href="${editUrl('credit_note')}">${icon('iq-credit')}New credit note</a>
    <a class="fwm-pop__item" data-v="r" href="${editUrl('recurring')}">${icon('iq-recurring')}New recurring invoice</a>`, { align: 'end' }));

  // ----------------------------------------------------------------- boot
  QI.initChrome();
  const lg = BOOT.legacy || {};
  if (!location.hash && lg.tab) {
    const status = { overdue: 'overdue', paid: 'paid', draft: 'draft', sent: S.route === 'quotes' ? 'open' : 'due', viewed: 'open', 'part-paid': 'due', accepted: 'accepted', declined: 'declined', expired: 'expired', active: 'active', inactive: 'paused', approved: 'approved', applied: 'applied' }[lg.status] || '';
    history.replaceState(null, '', location.pathname + '#' + lg.tab + (status ? '?status=' + status : ''));
  }
  readRoute();
  api.get('overview.php').then((d) => { S.counts = Object.assign(S.counts, d.counts); paintCounts(); }).catch(() => {});
})();
