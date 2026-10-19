// /finances/js/fin-app.js — the Finance home: the router, the shared screen
// pieces (header, tools, period button, pager, sort headers, save view) and
// the overview. The other screens register themselves from fin-books.js
// (journals, ledger, accounts, close), fin-money.js (bank), fin-parties.js
// (receivables, payables), fin-tax.js (VAT, assets, payroll, budgets) and fin-reports.js.
// Routes (hash): #overview · #journals · #ledger · #accounts · #bank ·
// #receivables · #payables · #vat · #assets · #payroll · #budgets · #reports ·
// #close, each with ?key=value parameters.
(function () {
  'use strict';
  const F = window.FIN;
  const { $, $$, esc, icon, api, toast, toastError, popover, BOOT, L, lower, can, money, compact, amount, fmtDate, relTime, relDay } = F;

  const screens = {};
  const S = { route: 'overview', params: new URLSearchParams(), seq: 0 };
  const el = { head: $('#head'), tools: $('#tools'), view: $('#view') };
  const routeLabel = (r) => ((BOOT.routes || []).find((x) => x.key === r) || {}).label || r;
  const routeOn = (r) => (BOOT.routes || []).some((x) => x.key === r) && !!screens[r];

  // ------------------------------------------------------------- routing
  function readRoute() {
    const h = location.hash.replace(/^#/, '');
    const i = h.indexOf('?');
    const base = i >= 0 ? h.slice(0, i) : h;
    const landing = routeOn(BOOT.prefs.landing) ? BOOT.prefs.landing : 'overview';
    S.route = routeOn(base) ? base : landing;
    S.params = new URLSearchParams(i >= 0 ? h.slice(i + 1) : '');
    render();
  }
  function go(route, params = {}, replace = false) {
    const p = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '' && !(k === 'page' && Number(v) === 1)) p.set(k, v); });
    const hash = '#' + route + (p.toString() ? '?' + p.toString() : '');
    if (replace) { history.replaceState(null, '', hash); readRoute(); }
    else if (location.hash === hash) readRoute();
    else location.hash = hash;
  }
  /** Change parameters without drawing the page again (e.g. a sheet opening). */
  function setParams(changes) {
    const o = Object.fromEntries(S.params.entries());
    Object.assign(o, changes);
    const p = new URLSearchParams();
    Object.entries(o).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.set(k, v); });
    S.params = p;
    history.replaceState(null, '', '#' + S.route + (p.toString() ? '?' + p.toString() : ''));
  }
  const param = (k, d = '') => S.params.get(k) || d;
  const withParams = (changes) => { const o = Object.fromEntries(S.params.entries()); Object.assign(o, changes); return o; };
  window.addEventListener('hashchange', readRoute);

  /** opts.keep: redraw the screen behind an open sheet (after a change made in the sheet) without closing it. */
  function render(opts = {}) {
    const keep = !!opts.keep && !!document.querySelector('.fwf-sheet.is-open');
    $$('#side [data-route]').forEach((a) => a.classList.toggle('is-active', a.dataset.route === S.route));
    const cur = S.route + (S.params.toString() ? '?' + S.params.toString() : '');
    $$('#viewsNav a').forEach((a) => a.classList.toggle('is-active', a.getAttribute('href').replace(/^.*#/, '') === cur));
    document.title = routeLabel(S.route) + ' · ' + BOOT.company;
    F.toggleDrawer(false);
    if (!keep) {
      F.closeSheet();
      F.closePop();
      el.view.classList.remove('is-entering');
      void el.view.offsetWidth;
      el.view.classList.add('is-entering');
    }
    if (el.view._rowsOff) el.view._rowsOff();
    if (!keep) window.onbeforeunload = null;
    el.tools.hidden = true;
    el.tools.innerHTML = '';
    el.head.innerHTML = '';
    const scr = screens[S.route];
    const newKind = scr.newKind ? scr.newKind() : 'journal';
    const nb = $('#newBtn');
    if (nb && newKind) { nb.dataset.new = newKind; $('#newLabel').textContent = NEW_LABEL[newKind] ? NEW_LABEL[newKind]() : 'New'; }
    const fab = $('#fab');
    if (fab && newKind) { fab.dataset.new = newKind; $('#fabLabel').textContent = NEW_LABEL[newKind] ? NEW_LABEL[newKind]() : 'New'; }
    const seq = ++S.seq;
    try {
      const r = scr.render({ param, params: S.params, seq, keep, isCurrent: () => seq === S.seq });
      if (r && r.catch) r.catch((e) => { if (seq === S.seq) el.view.innerHTML = F.errorBox(e); });
    } catch (e) {
      el.view.innerHTML = F.errorBox(e);
      console.error(e);
    }
  }
  const NEW_LABEL = {
    journal: () => 'New ' + lower('journal'), bill: () => 'New ' + lower('bill'), supplier_payment: () => 'Pay a ' + lower('supplier'),
    customer_payment: () => 'Receive a payment', vendor_credit: () => 'New credit note', vat_period: () => 'New VAT period', bank_import: () => 'Import statement', asset: () => 'New ' + lower('asset'), account: () => 'New ' + lower('account'),
  };

  // ---------------------------------------------------------- shared pieces
  function head(eyebrow, h1, actions = '', count = null) {
    el.head.innerHTML = `<div class="fwf-head__title"><div class="fwf-eyebrow">${esc(eyebrow)}</div><h1>${esc(h1)}${count !== null && count !== '' ? `<small>${esc(count)}</small>` : ''}</h1></div><div class="fwf-head__actions">${actions}</div>`;
    return el.head;
  }
  function tools(html) {
    el.tools.hidden = !html;
    el.tools.innerHTML = html || '';
    return el.tools;
  }
  /** The person's default period from their prefs, or the one in the URL. */
  function period(defaultKey) {
    const from = F.parseYmd(param('from')) ? param('from') : null;
    const to = F.parseYmd(param('to')) ? param('to') : null;
    if (from && to) return [from, to];
    return F.periodRange(defaultKey || BOOT.prefs.period || 'month');
  }
  const periodBtn = (from, to, id = 'periodBtn') => `<button class="fwf-periodbtn" type="button" id="${id}" aria-haspopup="menu">${icon('calendar')}<span>${esc(F.periodLabel(from, to))}</span>${icon('chev-down', 'i--sm')}</button>`;
  function bindPeriod(root, from, to, route, id = 'periodBtn') {
    const b = $('#' + id, root || document);
    if (b) b.addEventListener('click', () => F.periodPicker(b, from, to, (f, t) => go(route || S.route, withParams({ from: f, to: t, page: 1 }))));
  }
  function chips(list, current, counts = {}, danger = []) {
    return `<div class="fwf-chips" role="tablist">${list.map(([k, l, ic]) => `<button class="fwf-chip${current === k ? ' is-on' : ''}${danger.includes(k) ? ' fwf-chip--danger' : ''}" type="button" role="tab" aria-selected="${current === k}" data-chip="${esc(k)}">${ic ? icon(ic) : ''}${esc(l)}${counts[k] != null ? ` <small>${esc(counts[k])}</small>` : ''}</button>`).join('')}</div>`;
  }
  function bindChips(root, key = 'status') {
    $$('[data-chip]', root).forEach((b) => b.addEventListener('click', () => go(S.route, withParams({ [key]: b.dataset.chip, page: 1 }))));
  }
  const qbox = (value, ph = 'Filter…') => `<label class="fwf-qbox"><svg class="i"><use href="#i-search"/></svg><input type="search" id="listQ" placeholder="${esc(ph)}" value="${esc(value)}" aria-label="${esc(ph)}"></label>`;
  function bindQbox(root) {
    const q = $('#listQ', root);
    if (q) q.addEventListener('input', F.debounce(() => go(S.route, withParams({ q: q.value.trim(), page: 1 }), true), 320));
  }
  function sortTh(key, label, cls = '') {
    const cur = param('sort'), dir = param('dir', 'desc');
    const on = cur === key;
    return `<th class="${cls}${on ? ' is-sorted' : ''}" aria-sort="${on ? (dir === 'asc' ? 'ascending' : 'descending') : 'none'}"><button type="button" data-sort="${esc(key)}">${esc(label)}${on ? icon(dir === 'asc' ? 'arrow-up' : 'arrow-down', 'i--xs') : ''}</button></th>`;
  }
  function bindSort(root) {
    $$('[data-sort]', root).forEach((b) => b.addEventListener('click', () => {
      const k = b.dataset.sort;
      const dir = param('sort') === k && param('dir', 'desc') === 'desc' ? 'asc' : 'desc';
      go(S.route, withParams({ sort: k, dir, page: 1 }));
    }));
  }
  function pager(page, per, total) {
    const pages = Math.max(1, Math.ceil(total / per));
    if (total <= per) return total ? `<div class="fwf-pager"><span>${total} ${total === 1 ? 'item' : 'items'}</span></div>` : '';
    return `<div class="fwf-pager"><span>${(page - 1) * per + 1}–${Math.min(total, page * per)} of ${total}</span><span>
      <button class="btn btn--sm" type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>${icon('chev-left')}Previous</button>
      <button class="btn btn--sm" type="button" data-page="${page + 1}" ${page >= pages ? 'disabled' : ''}>Next${icon('chev-right')}</button></span></div>`;
  }
  function bindPager(root) {
    $$('[data-page]', root).forEach((b) => b.addEventListener('click', () => { go(S.route, withParams({ page: b.dataset.page })); el.view.scrollTop = 0; }));
  }
  /** Keyboard: rows with [data-open] open on Enter/Space; ↑/↓ move between them. */
  function bindRows(root, onOpen) {
    // #view outlives every screen: drop the previous screen's handlers first
    if (root._rowsOff) root._rowsOff();
    const click = (e) => {
      if (e.target.closest('a, button, input, select, label, [data-stop]')) return;
      const r = e.target.closest('[data-open]');
      if (r) onOpen(r.dataset.open, r);
    };
    const key = (e) => {
      const r = e.target.closest('[data-open]');
      if (!r) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onOpen(r.dataset.open, r); }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        const all = $$('[data-open]', root).filter((x) => x.offsetParent !== null);
        const i = all.indexOf(r);
        const n = all[i + (e.key === 'ArrowDown' ? 1 : -1)];
        if (n) { e.preventDefault(); n.focus(); }
      }
    };
    root.addEventListener('click', click);
    root.addEventListener('keydown', key);
    root._rowsOff = () => { root.removeEventListener('click', click); root.removeEventListener('keydown', key); root._rowsOff = null; };
  }
  const saveViewBtn = () => `<button class="btn btn--sm btn--ghost hide-narrow" type="button" id="saveView" data-tip="Save this view in the sidebar">${icon('bookmark')}<span>Save view</span></button>`;
  function bindSaveView(root) {
    const b = $('#saveView', root);
    if (!b) return;
    b.addEventListener('click', async () => {
      const name = await F.dialog({ title: 'Save this view', body: 'It appears under “My views” in the sidebar, with the filters and period you have now.', input: { label: 'Name', placeholder: 'Unmatched FNB lines', max: 40 }, confirm: 'Save view' });
      if (!name) return;
      try {
        const d = await api.post('prefs.php', { op: 'view_add', name, route: S.route, query: S.params.toString() });
        BOOT.prefs = d.prefs;
        paintViews();
        toast('View saved');
      } catch (e) { toastError(e); }
    });
  }
  function paintViews() {
    const host = $('#viewsNav');
    if (!host) return;
    $('#savedViews').hidden = !BOOT.prefs.views.length;
    host.innerHTML = BOOT.prefs.views.map((v) => `<a class="fwm-nav__item fwf-view-link" href="#${esc(v.route)}${v.query ? '?' + esc(v.query) : ''}" data-view="${esc(v.id)}">${icon('bookmark')}<span class="fwm-nav__label">${esc(v.name)}</span></a>`).join('');
  }
  document.addEventListener('contextmenu', (e) => {
    const a = e.target.closest('[data-view]');
    if (!a) return;
    e.preventDefault();
    popover({ x: e.clientX, y: e.clientY }, `<button class="fwm-pop__item is-danger" type="button" data-v="rm">${icon('trash')}Remove this view</button>`, {
      onClick: async () => { try { const d = await api.post('prefs.php', { op: 'view_remove', id: a.dataset.view }); BOOT.prefs = d.prefs; paintViews(); } catch (er) { toastError(er); } },
    });
  });
  const exportBtn = (href, label = 'Export') => `<a class="btn btn--sm btn--ghost" href="${esc(href)}" download>${icon('download')}<span>${esc(label)}</span></a>`;
  /** A small upload/list block for supporting documents. */
  function filesBlock(entity, id, files, canAttach) {
    return `<div class="fwf-sheet__sec" data-files="${esc(entity)}:${id}"><h4>${icon('clip')}Supporting documents<small>${files.length || 'none yet'}</small></h4>
      <div class="fwf-files">${files.map((f) => `<div class="fwf-file">${icon(/pdf/.test(f.mime) ? 'file-text' : /image/.test(f.mime) ? 'image' : 'clip')}<span class="fwf-ellip"><a href="${esc(api.url('files.php', { id: f.id }))}" target="_blank" rel="noopener">${esc(f.name)}</a><small> · ${esc(Math.max(1, Math.round(f.size / 1024)))} KB · ${esc(f.by)}</small></span>
        ${canAttach ? `<button class="icon-btn" type="button" data-rmfile="${f.id}" aria-label="Remove ${esc(f.name)}">${icon('x')}</button>` : ''}</div>`).join('')}</div>
      ${canAttach ? `<label class="fwf-drop" style="margin-top:8px">${icon('upload')}<b>Attach a document</b><span>PDF, photo, Excel, Word or CSV · up to 10 MB</span><input type="file" hidden accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.heic,.csv,.txt,.xlsx,.xls,.docx,.doc"></label>` : ''}</div>`;
  }
  function bindFiles(root, onChange) {
    const box = $('[data-files]', root);
    if (!box) return;
    const [entity, id] = box.dataset.files.split(':');
    const input = $('input[type=file]', box);
    const drop = $('.fwf-drop', box);
    const send = async (file) => {
      const fd = new FormData();
      fd.append('entity', entity);
      fd.append('entity_id', id);
      fd.append('file', file);
      try { await api.upload('files.php', fd); toast('Document attached'); onChange(); } catch (e) { toastError(e); }
    };
    if (input) input.addEventListener('change', () => { if (input.files[0]) send(input.files[0]); });
    if (drop) {
      ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
      ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('is-over'); }));
      drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) send(f); });
    }
    $$('[data-rmfile]', box).forEach((b) => b.addEventListener('click', async () => {
      if (!(await F.dialog({ title: 'Remove this document?', body: 'It stays in the audit trail as removed.', confirm: 'Remove', danger: true }))) return;
      try { await api.post('files.php', { op: 'remove', id: +b.dataset.rmfile }); onChange(); } catch (e) { toastError(e); }
    }));
  }

  // ============================================================ overview
  const TONES = { danger: 'var(--danger)', warning: 'var(--warning)', gold: 'var(--gold)', info: 'var(--info)', success: 'var(--success)' };
  async function renderOverview(ctx) {
    const h = new Date().getHours();
    const greet = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
    head(BOOT.company, 'Overview', `
      <button class="btn btn--sm btn--ghost" type="button" id="ovHide">${icon('eye-off')}<span>Panels</span></button>
      ${BOOT.is_admin ? `<a class="btn btn--sm btn--ghost hide-narrow" href="/finances/settings.php#layout">${icon('if-layout')}<span>Arrange</span></a>` : ''}
      ${can('write') ? `<button class="btn btn--primary" type="button" data-create="journal">${icon('plus')}<span>New ${esc(lower('journal'))}</span></button>` : ''}`);
    $$('[data-create]', el.head).forEach((b) => b.addEventListener('click', () => F.create(b.dataset.create)));
    $('#ovHide').addEventListener('click', (e) => panelMenu(e.currentTarget));
    el.view.innerHTML = `<div class="fwf-ov"><div class="fwf-kpis">${'<div class="fwf-kpi fwf-skel" style="height:138px"></div>'.repeat(4)}</div><div class="fwf-grid">${'<div class="fwf-panel fwf-skel" style="height:260px"></div>'.repeat(4)}</div></div>`;
    const d = await api.get('overview.php');
    if (!ctx.isCurrent()) return;
    paintOverview(d, greet);
  }
  function paintOverview(d, greet) {
    const p = d.panels;
    const order = BOOT.config.overview.filter((k) => p[k] !== undefined && k !== 'kpis');
    el.view.innerHTML = `<div class="fwf-ov">
      <div class="fwf-hello"><div><div class="fwf-eyebrow">${esc(fmtDate(d.today, 'always'))}</div>
        <h2>${esc(greet)}${BOOT.me.first_name ? `, <em>${esc(BOOT.me.first_name)}</em>` : ''}.</h2>
        ${d.brief.length ? `<div class="fwf-brief">${d.brief.map((b) => `<a href="${esc(b.href)}" style="--c:${TONES[b.tone] || 'var(--gold)'}"><i></i>${esc(b.text)}</a>`).join('')}</div>` : ''}</div></div>
      ${p.kpis ? `<div class="fwf-kpis">${p.kpis.map(kpi).join('')}</div>` : ''}
      <div class="fwf-grid">${order.map((k) => panel(k, p)).filter(Boolean).join('')}</div></div>`;
    bindOverview(el.view, p);
  }
  function kpi(k) {
    const neg = k.value < 0;
    const v = `<small>${neg ? '−R' : 'R'}</small>${esc(compact(Math.abs(k.value), ''))}`;
    const ch = k.change != null ? `<span class="${k.change >= 0 ? 'up' : 'down'}">${k.change >= 0 ? '▲' : '▼'} ${Math.abs(k.change)}%</span>` : '';
    return `<a class="fwf-kpi" href="${esc(k.href)}" style="--c:${k.color}" title="${esc(money(k.value))}"><span class="fwf-kpi__label">${esc(k.label)}</span><span class="fwf-kpi__value${neg ? ' fwf-neg' : ''}">${v}</span>
      <span class="fwf-kpi__note">${ch}${esc(k.note || '')}</span>${k.spark ? F.sparkline(k.spark, k.color, 46) : ''}</a>`;
  }
  const box = (k, titleHtml, link, body, cls = '') => `<section class="fwf-panel ${cls}" data-panel="${k}"><div class="fwf-panel__head"><h3>${titleHtml}</h3>${link || ''}</div>${body}</section>`;
  function panel(k, p) {
    if (k === 'cashflow') {
      const c = p.cashflow;
      const hist = c.history.map((m) => ({ label: m.label, a: m.in, b: m.out, current: m.current, tip: `${m.label} ${m.year}: in ${money(m.in)} · out ${money(m.out)} · net ${money(m.in - m.out)}` }));
      const fc = c.forecast.map((w) => ({ label: w.label, a: w.in, b: w.out, future: true, tip: `Week of ${fmtDate(w.from)}: expected in ${money(w.in)} · out ${money(w.out)} · bank after ${money(w.balance)}` }));
      const last = c.forecast.length ? c.forecast[c.forecast.length - 1] : null;
      const low = c.forecast.reduce((m, w) => (m === null || w.balance < m.balance ? w : m), null);
      return box(k, `Cash flow<small>money in and out of the bank</small>`, `<div class="seg" role="group" aria-label="Cash flow view"><button type="button" class="is-on" data-cf="hist">12 months</button><button type="button" data-cf="fc">Next ${c.days} days</button></div>`,
        `<div data-cfview="hist">${hist.some((r) => r.a || r.b) ? F.pairBars(hist, { aLabel: 'Money in', bLabel: 'Money out' }) : F.emptyState('if-cash', 'No bank movement yet', 'Once payments are posted to your bank accounts, the monthly flow shows here.', '', true)}</div>
         <div data-cfview="fc" hidden>${fc.some((r) => r.a || r.b) ? F.pairBars(fc, { aLabel: 'Expected in', bLabel: 'Expected out' }) : F.emptyState('if-hourglass', 'Nothing due in or out', 'Open invoices, bills and VAT due in the next weeks show here.', '', true)}
           ${last ? `<div class="fwf-banner fwf-banner--${low && low.balance < 0 ? 'danger' : 'info'}" style="margin-top:12px">${icon('if-trend')}<span>Starting at <b>${esc(money(c.start_balance))}</b>, the bank should hold about <b>${esc(money(last.balance))}</b> by ${esc(fmtDate(last.to))}${low && low.balance < c.start_balance ? `, lowest around ${esc(money(low.balance))} in the week of ${esc(fmtDate(low.from))}` : ''}. Based on due dates; late invoices are counted in the first week.</span></div>` : ''}</div>`, 'fwf-panel--wide');
    }
    if (k === 'attention') {
      const a = p.attention;
      return box(k, `Needs attention${a.items.length ? `<small>${a.items.length}</small>` : ''}`, a.snoozed ? `<button class="fwf-link" type="button" id="unsnooze">${a.snoozed} snoozed · show</button>` : '',
        a.items.length ? `<div class="fwf-attn">${a.items.map((x) => `<div class="fwf-attn__item" data-key="${esc(x.key)}"><span class="fwf-type" data-t="${esc(x.tone)}">${icon(x.icon)}</span>
          <a class="fwf-attn__text" href="${esc(x.href)}"><b>${esc(x.title)}</b><small>${esc(x.sub)}</small></a>
          <span class="fwf-attn__acts"><a class="icon-btn" href="${esc(x.href)}" aria-label="Open">${icon('chev-right')}</a>${x.snooze ? `<button class="icon-btn" type="button" data-snooze="${esc(x.key)}" aria-label="Snooze ${esc(x.title)}" data-tip="Snooze">${icon('if-snooze')}</button>` : ''}</span></div>`).join('')}</div>`
          : `<div class="fwf-ok">${icon('if-approve')}<span><b>All clear.</b> Nothing is late, waiting or out of balance.</span></div>`);
    }
    if (k === 'aging') {
      const a = p.aging;
      const tones = ['var(--success)', 'var(--gold)', 'var(--warning)', '#c0662a', 'var(--danger)'];
      const block = (title, data, route, top, kind) => data ? `<div><h4>${esc(title)}<span>${esc(money(data.reduce((s, b) => s + b.value, 0)))}</span></h4>
        ${F.agingBars(data.map((b, i) => ({ ...b, tone: tones[i] })), (b, i) => `#${route}?status=${i === 0 ? 'open' : 'overdue'}`)}
        ${top.length ? `<div class="fwf-rows" style="margin-top:8px">${top.map((x) => `<a href="${kind === 'ar' ? `#receivables?invoice=${x.id}` : `#payables?bill=${x.id}`}">${F.avatar(x.name, 'fwf-ava--sm')}<span class="fwf-rows__text"><b>${esc(x.name)}</b><small>${esc(x.number || '')} · ${x.days} days late</small></span><span class="fwf-rows__side">${esc(money(x.balance))}</span></a>`).join('')}</div>` : ''}</div>` : '';
      return box(k, 'Who owes whom', `<a href="#reports?r=ar_aging">Aged reports</a>`, `<div class="fwf-aging2">${block('Customers owe you', a.ar, 'receivables', a.top_ar, 'ar')}${block('You owe suppliers', a.ap, 'payables', a.top_ap, 'ap')}</div>`);
    }
    if (k === 'profit') {
      const rows = p.profit.months.map((m, i) => ({ label: m.label, a: m.income, b: m.expenses, current: i === p.profit.months.length - 1, tip: `${m.label} ${m.year}: income ${money(m.income)} · expenses ${money(m.expenses)} · ${m.income - m.expenses >= 0 ? 'profit' : 'loss'} ${money(Math.abs(m.income - m.expenses))}` }));
      return box(k, 'Income vs expenses<small>12 months</small>', `<a href="#reports?r=income_statement">Profit and loss</a>`,
        rows.some((r) => r.a || r.b) ? F.pairBars(rows, { aLabel: 'Income', bLabel: 'Expenses', height: 180 }) : F.emptyState('if-trend', 'No income or expenses yet', 'Invoices, bills and journals show up here once they are posted.', '', true));
    }
    if (k === 'spend') {
      const s = p.spend;
      const colors = ['#b08d57', '#2f5c84', '#8a6fb8', '#2f7457', '#b07818', '#a8281e', '#85766b'];
      return box(k, `Where the money went<small>${esc(s.label)}</small>`, `<a href="#reports?r=income_statement">Details</a>`, s.rows.length ? `${F.shareBar(s.rows.map((r, i) => ({ label: r.name, value: r.value, color: colors[i % colors.length] })))}
        <div class="fwf-rows">${s.rows.map((r, i) => `<a href="${r.code ? '#ledger?account=' + encodeURIComponent(r.code) : '#reports?r=income_statement'}"><span class="fwf-type" style="--c:${colors[i % colors.length]};color:${colors[i % colors.length]}">${r.code ? `<b style="font-size:10px">${esc(r.code)}</b>` : icon('more-h')}</span>
          <span class="fwf-rows__text"><b>${esc(r.name)}</b><small>${Math.round((r.value / s.total) * 100)}% of ${esc(money(s.total))}</small></span><span class="fwf-rows__side">${esc(money(r.value))}</span></a>`).join('')}</div>`
        : F.emptyState('if-budget', 'No expenses posted', 'Bills, payroll and journals to expense accounts show here.', '', true));
    }
    if (k === 'banks') {
      const b = p.banks.accounts;
      return box(k, 'Bank accounts', `<a href="#bank">Open bank</a>`, b.length ? `<div class="fwf-bankcards">${b.map((x) => {
        const diff = x.books !== null && Math.abs(x.books - x.statement) >= 0.01;
        return `<a class="fwf-bankcard" href="#bank?account=${x.id}"><span class="fwf-bankcard__name">${icon('if-bank')}${esc(x.name)}${x.last4 ? ` <small>··${esc(x.last4)}</small>` : ''}</span><b>${esc(money(x.statement))}</b>
          <small>${x.unmatched ? `${x.unmatched} line(s) to match` : 'All lines matched'} · ${x.reconciled ? 'reconciled ' + esc(fmtDate(x.reconciled)) : 'never reconciled'}</small>
          ${diff ? `<small class="fwf-neg">Books say ${esc(money(x.books))}</small>` : ''}</a>`;
      }).join('')}</div>` : F.emptyState('if-bank', 'No bank accounts yet', 'Add your bank account and import statements to match them to the books.', can('write') ? `<a class="btn btn--sm" href="#bank?new=account">${icon('plus')}Add a bank account</a>` : '', true));
    }
    if (k === 'vat') {
      const v = p.vat;
      const c = v.current;
      return box(k, `${esc(L('vat'))} position<small>${v.basis === 'payments' ? 'payments basis' : 'invoice basis'}</small>`, `<a href="#vat">Open ${esc(L('vat'))}</a>`,
        `${c && c.estimate ? `<div class="fwf-vatbox"><div><span>Output (Box 5)</span><b>${esc(money(c.estimate.output))}</b></div><div><span>Input (Box 9)</span><b>${esc(money(c.estimate.input))}</b></div>
          <div class="is-due"><span>${c.estimate.net >= 0 ? 'To pay (Box 10)' : 'Refund (Box 10)'}</span><b>${esc(money(Math.abs(c.estimate.net)))}</b></div></div>
          <p class="fwf-hint">Running estimate for ${esc(fmtDate(c.start))} – ${esc(fmtDate(c.end, 'always'))}.</p>`
        : c ? '' : `<div class="fwf-banner fwf-banner--info">${icon('if-vat')}<span>No VAT period covers today.</span>${can('vat') ? `<a class="fwf-mini" href="#vat?new=period">${icon('plus')}Create it</a>` : ''}</div>`}
        ${v.to_file.length ? `<div class="fwf-rows">${v.to_file.map((x) => `<a href="#vat?period=${x.id}"><span class="fwf-type" data-t="${x.due < BOOT.today ? 'danger' : 'warning'}">${icon('if-vat')}</span><span class="fwf-rows__text"><b>${esc(fmtDate(x.start))} – ${esc(fmtDate(x.end, 'always'))}</b><small>${x.due < BOOT.today ? 'Was due' : 'Due'} ${esc(fmtDate(x.due, 'always'))} · ${esc(F.statusLabel(x.status))}</small></span><span class="fwf-rows__side">${x.net !== null ? esc(money(x.net)) : ''}</span></a>`).join('')}</div>` : ''}`);
    }
    if (k === 'activity') {
      const a = p.activity;
      return box(k, 'Recent postings', `<a href="#journals">All journals</a>`, a.length ? `<div class="fwf-rows">${a.map((j) => `<a href="#journals?id=${j.id}"><span class="fwf-type">${icon(SOURCE_ICON[j.source] || 'if-journal')}</span>
        <span class="fwf-rows__text"><b>${esc(j.reference || '#' + j.id)} · ${esc(j.description || 'Journal')}</b><small>${esc(fmtDate(j.date))} · ${esc(relTime(j.created_at))}</small></span>
        <span class="fwf-rows__side">${esc(money(j.total))}${j.status !== 'posted' ? F.pill(j.status) : ''}</span></a>`).join('')}</div>` : F.emptyState('if-journal', 'Nothing posted yet', 'Journals from invoices, bills, payroll and your own entries show here.', '', true));
    }
    return '';
  }
  const SOURCE_ICON = { manual: 'if-journal', sales: 'iq-invoice', receipts: 'if-in', purchases: 'if-out', payments: 'if-cash', bank: 'if-bank', payroll: 'if-payroll',
    assets: 'if-asset', vat: 'if-vat', inventory: 'i-layers', year_end: 'if-close', reversal: 'if-reverse', other: 'if-journal' };
  function bindOverview(root) {
    $$('[data-cf]', root).forEach((b) => b.addEventListener('click', () => {
      $$('[data-cf]', root).forEach((x) => x.classList.toggle('is-on', x === b));
      $$('[data-cfview]', root).forEach((v) => { v.hidden = v.dataset.cfview !== b.dataset.cf; });
    }));
    $$('[data-snooze]', root).forEach((b) => b.addEventListener('click', () => {
      popover(b, `<div class="fwm-pop__title">Snooze</div>${[[1, 'Until tomorrow'], [3, 'For 3 days'], [7, 'For a week'], [30, 'For a month']].map(([d, l]) => `<button class="fwm-pop__item" type="button" data-v="${d}">${icon('if-snooze')}${l}</button>`).join('')}`, {
        align: 'end', onClick: async (it) => {
          try { const d = await api.post('overview.php', { op: 'snooze', key: b.dataset.snooze, days: +it.dataset.v }); paintOverview(d, $('.fwf-hello h2').textContent.split(',')[0].replace('.', '')); toast('Snoozed'); } catch (e) { toastError(e); }
        },
      });
    }));
    const un = $('#unsnooze', root);
    if (un) un.addEventListener('click', async () => { try { const d = await api.post('overview.php', { op: 'unsnooze_all' }); paintOverview(d, $('.fwf-hello h2').textContent.split(',')[0].replace('.', '')); } catch (e) { toastError(e); } });
  }
  function panelMenu(anchor) {
    const companyOff = BOOT.config.overview_off;
    const mine = BOOT.prefs.overview_off;
    popover(anchor, `<div class="fwm-pop__title">Show on my overview</div>${Object.entries(BOOT.panels).map(([k, l]) => {
      const off = companyOff.includes(k);
      return `<button class="fwm-pop__item" type="button" data-v="${k}" ${off ? 'disabled' : ''}>${icon(off ? 'lock' : mine.includes(k) ? 'eye-off' : 'eye')}${esc(l)}${off ? '<small class="fwf-pop-hint">off for the company</small>' : ''}</button>`;
    }).join('')}`, {
      align: 'end',
      onClick: async (b) => {
        const k = b.dataset.v;
        const next = mine.includes(k) ? mine.filter((x) => x !== k) : [...mine, k];
        try { await F.savePrefs({ overview_off: next }); render(); } catch (e) { toastError(e); }
      },
    });
  }

  screens.overview = { render: renderOverview };

  // ------------------------------------------------------------- start
  function start() {
    F.initChrome();
    paintViews();
    window.addEventListener('beforeunload', () => {});
    readRoute();
  }

  F.app = { register: (k, s) => { screens[k] = s; }, screens, start, go, setParams, param, withParams, render, refresh: () => render({ keep: true }), head, tools, period, periodBtn, bindPeriod,
    chips, bindChips, qbox, bindQbox, sortTh, bindSort, pager, bindPager, bindRows, saveViewBtn, bindSaveView, exportBtn, filesBlock, bindFiles,
    SOURCE_ICON, el, state: S };
})();
