// /finances/js/fin-parties.js — receivables (what customers owe) and payables
// (what the business owes suppliers): lists, the document sheets, receiving
// and paying money against several documents at once, supplier bills and
// credit notes, statements, aging and three-way matching.
// Invoices and customer credit notes are written in Quotes & Invoices; here
// they are read and paid. Bills and supplier credits are written here.
(function () {
  'use strict';
  const F = window.FIN;
  const A = F.app;
  const { $, $$, esc, icon, api, toast, toastError, popover, dialog, BOOT, L, lower, can, money, fmtDate, relDay, relTime } = F;
  const uid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
  const cur = (v, c) => (c && c !== 'ZAR' ? c + ' ' + F.group(F.r2(v)) : money(v));
  const dueCell = (due, late) => (late ? `<span class="fwf-neg">${late} day${late === 1 ? '' : 's'} late</span>` : due ? esc(relDay(due)) : '<span class="fwf-muted">no due date</span>');
  const METHODS = [['eft', 'EFT'], ['card', 'Card'], ['cash', 'Cash'], ['cheque', 'Cheque'], ['other', 'Other']];
  const methodOptions = (sel) => METHODS.map(([v, l]) => `<option value="${v}"${sel === v ? ' selected' : ''}>${l}</option>`).join('');
  const LBL = { review: 'To review', applied: 'Applied', open: 'Open', approved: 'Approved', blocked: 'Blocked' };
  const pill = (s) => F.pill(s === 'review' ? 'draft' : s, LBL[s] || undefined);
  const after = () => { A.refresh(); F.refreshBoot().catch(() => {}); };
  const viewSeg = (views, current) => `<div class="seg" role="tablist" aria-label="View">${views.map(([k, l]) => `<button type="button" role="tab" data-view="${k}" aria-selected="${current === k}" class="${current === k ? 'is-on' : ''}">${esc(l)}</button>`).join('')}</div>`;
  const bindViewSeg = (root, route) => $$('[data-view]', root).forEach((b) => b.addEventListener('click', () => A.go(route, { view: b.dataset.view })));

  /** Auto-allocate an amount over rows (oldest first) into their inputs. */
  function spread(rows, inputs, total) {
    let left = Math.round((total || 0) * 100);
    rows.forEach((r, i) => {
      const owe = Math.round(r.balance * 100);
      const take = Math.max(0, Math.min(owe, left));
      inputs[i].value = take ? (take / 100).toFixed(2) : '';
      left -= take;
    });
    return left / 100;
  }

  // ======================================================== receivables
  async function renderReceivables(ctx) {
    const p = ctx.param;
    const view = ['invoices', 'customers', 'aging', 'statement'].includes(p('view')) ? p('view') : 'invoices';
    if (view === 'statement') return renderStatement(ctx, 'ar');
    const status = p('status', 'open');
    const asOf = F.parseYmd(p('as_of')) ? p('as_of') : BOOT.today;
    const exportHref = view === 'aging' ? api.url('receivables.php', { op: 'aging', as_of: asOf, format: 'csv' })
      : api.url('receivables.php', { op: 'invoices', status, customer: p('customer'), q: p('q'), format: 'csv' });
    A.head(BOOT.company, L('receivables'), `${A.exportBtn(exportHref)}${can('write') ? `<button class="btn btn--primary" type="button" id="arRecv">${icon('if-in')}<span>Receive a payment</span></button>` : ''}`);
    const tools = A.tools(`${viewSeg([['invoices', 'Invoices'], ['customers', L('customers')], ['aging', 'Aging']], view)}<span id="arChips"></span><span class="fwf-grow"></span>
      ${view === 'aging' ? `<label class="fwf-qbox" style="max-width:190px">${icon('calendar')}<input type="date" id="arAsOf" value="${esc(asOf)}" aria-label="Aging as at"></label>` : A.qbox(p('q'), view === 'customers' ? 'Find a ' + lower('customer') : 'Number, ' + lower('customer') + ' or amount')}${A.saveViewBtn()}`);
    bindViewSeg(tools, 'receivables');
    A.bindQbox(tools);
    A.bindSaveView(tools);
    const asIn = $('#arAsOf', tools);
    if (asIn) asIn.addEventListener('change', () => { if (asIn.value) A.go('receivables', A.withParams({ as_of: asIn.value })); });
    const rb = $('#arRecv');
    if (rb) rb.addEventListener('click', () => receiveDialog(+p('customer') || 0));
    A.el.view.innerHTML = F.skel(7, 48);
    if (view === 'customers') await arCustomers(ctx);
    else if (view === 'aging') await partyAging(ctx, 'ar', asOf);
    else await arInvoices(ctx, status);
    if (p('id') && !ctx.keep) openInvoice(+p('id'));
    if (p('new') === 'payment' && can('write')) { A.setParams({ new: '' }); receiveDialog(+p('customer') || 0); }
  }

  async function arInvoices(ctx, status) {
    const p = ctx.param;
    const d = await api.get('receivables.php', { op: 'invoices', status, customer: p('customer'), q: p('q'), sort: p('sort', 'due'), dir: p('dir', 'asc'), page: p('page', 1) });
    if (!ctx.isCurrent()) return;
    $('#arChips').innerHTML = A.chips([['open', 'Open'], ['overdue', 'Late'], ['due', 'Due soon'], ['paid', 'Paid'], ['all', 'All']], status, d.counts, ['overdue']);
    A.bindChips($('#arChips'));
    const rows = d.rows;
    const custName = p('customer') && rows[0] ? rows[0].customer : '';
    A.el.view.innerHTML = rows.length ? `
      <div class="fwf-sum"><span><b>${d.total}</b> invoice${d.total === 1 ? '' : 's'}</span><span>${status === 'paid' ? 'Still owing' : 'Owing'} <b>${esc(money(d.sum))}</b></span>
        ${p('customer') ? `<span>${esc(L('customer'))} <b>${esc(custName || '#' + p('customer'))}</b> <button class="fwf-link" type="button" data-clear="customer">clear</button> · <a class="fwf-link" href="#receivables?view=statement&customer=${esc(p('customer'))}">statement</a></span>` : ''}</div>
      <div class="fwf-table-wrap can-card"><table class="fwf-table"><thead><tr>${A.sortTh('number', 'Invoice')}<th>${esc(L('customer'))}</th>${A.sortTh('issued', 'Issued', 'hide-narrow')}${A.sortTh('due', 'Due')}<th>Status</th>${A.sortTh('balance', 'Balance', 'r')}<th></th></tr></thead>
      <tbody>${rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}">
        <td class="fwf-nowrap"><b>${esc(r.number)}</b></td>
        <td><div class="fwf-cell2"><b>${esc(r.customer)}</b><small>${r.last_paid ? 'last paid ' + esc(fmtDate(r.last_paid)) : r.viewed ? 'viewed ' + esc(relTime(r.viewed)) : r.sent ? 'sent ' + esc(relTime(r.sent)) : ''}</small></div></td>
        <td class="fwf-nowrap hide-narrow">${esc(fmtDate(r.issued, 'always'))}</td><td class="fwf-nowrap">${dueCell(r.due, r.days_late)}</td><td>${pill(r.status)}</td>
        <td class="r"><b>${esc(cur(r.balance, r.currency))}</b>${r.currency !== 'ZAR' ? `<div class="fwf-muted" style="font-size:11px">${esc(money(r.balance_zar))}</div>` : ''}</td>
        <td class="r">${can('write') && r.balance > 0 && !['paid', 'cancelled', 'written_off'].includes(r.status) ? `<button class="fwf-mini" type="button" data-recv="${r.id}" data-cust="${r.customer_id}">${icon('if-in')}Receive</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
      <div class="fwf-cards">${rows.map((r) => `<div class="fwf-card" data-open="${r.id}" tabindex="0"><span class="fwf-type">${icon('iq-invoice')}</span>
        <span class="fwf-card__text"><b>${esc(r.number)} · ${esc(r.customer)}</b><small>${r.days_late ? esc(r.days_late + ' days late') : 'Due ' + esc(fmtDate(r.due))}</small></span>
        <span class="fwf-card__side">${esc(cur(r.balance, r.currency))}${pill(r.status)}</span></div>`).join('')}</div>
      ${A.pager(d.page, d.per, d.total)}`
      : F.emptyState(status === 'overdue' ? 'if-approve' : 'iq-invoice', status === 'overdue' ? 'Nobody is late' : status === 'open' ? 'Nothing is owed to you' : 'No invoices here',
        status === 'open' || status === 'overdue' ? 'Invoices you issue in Quotes &amp; Invoices show here until they are paid.' : 'Try another filter.',
        `<a class="btn" href="/qi/#invoices">${icon('external')}Quotes &amp; Invoices</a>`);
    A.bindSort(A.el.view);
    A.bindPager(A.el.view);
    A.bindRows(A.el.view, (id) => openInvoice(+id));
    $$('[data-clear]', A.el.view).forEach((b) => b.addEventListener('click', () => A.go('receivables', A.withParams({ [b.dataset.clear]: '', page: 1 }))));
    $$('[data-recv]', A.el.view).forEach((b) => b.addEventListener('click', () => receiveDialog(+b.dataset.cust, +b.dataset.recv)));
  }

  async function arCustomers(ctx) {
    const d = await api.get('receivables.php', { op: 'customers', q: ctx.param('q') });
    if (!ctx.isCurrent()) return;
    const rows = d.rows;
    const tot = rows.reduce((s, r) => s + r.balance, 0), late = rows.reduce((s, r) => s + r.late, 0);
    A.el.view.innerHTML = rows.length ? `<div class="fwf-sum"><span><b>${rows.length}</b> ${esc(lower(rows.length === 1 ? 'customer' : 'customers'))} owe you</span><span>Total <b>${esc(money(tot))}</b></span><span>Late <b class="${late > 0 ? 'fwf-neg' : ''}">${esc(money(late))}</b></span></div>
      <div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>${esc(L('customer'))}</th><th class="r">Invoices</th><th class="r">Owing</th><th class="r">Late</th><th class="hide-narrow">Oldest</th><th class="hide-narrow">Last paid</th><th></th></tr></thead>
      <tbody>${rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}">
        <td><div class="fwf-cell2"><b>${esc(r.name)}</b><small>${esc(r.email || r.phone || '')}</small></div></td><td class="r">${r.invoices}</td><td class="r"><b>${esc(money(r.balance))}</b></td>
        <td class="r">${r.late > 0 ? `<span class="fwf-neg">${esc(money(r.late))}</span>` : '—'}</td><td class="hide-narrow">${r.worst_days > 0 ? esc(r.worst_days + ' days late') : 'not late'}</td>
        <td class="hide-narrow">${r.last_paid ? esc(fmtDate(r.last_paid, 'always')) : '<span class="fwf-muted">never</span>'}</td>
        <td class="r fwf-nowrap"><a class="fwf-mini" href="#receivables?customer=${r.id}" data-stop>Invoices</a> ${can('write') ? `<button class="fwf-mini" type="button" data-recv="${r.id}">${icon('if-in')}Receive</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
      : F.emptyState('if-approve', 'Nobody owes you money', 'Customers with open invoices show here, worst first.');
    A.bindRows(A.el.view, (id) => A.go('receivables', { view: 'statement', customer: id }));
    $$('[data-recv]', A.el.view).forEach((b) => b.addEventListener('click', () => receiveDialog(+b.dataset.recv)));
  }

  /** Aging per party with the bucket bars on top. kind: ar | ap */
  async function partyAging(ctx, kind, asOf) {
    const d = await api.get(kind === 'ar' ? 'receivables.php' : 'payables.php', { op: 'aging', as_of: asOf });
    if (!ctx.isCurrent()) return;
    const t = d.totals;
    const route = kind === 'ar' ? 'receivables' : 'payables';
    const party = kind === 'ar' ? 'customer' : 'supplier';
    const B = [['current', 'Current', 'var(--ok, #2f7457)'], ['d30', '1–30 days', 'var(--gold)'], ['d60', '31–60 days', '#c58a2a'], ['d90', '61–90 days', '#b85c2a'], ['d90p', '90+ days', 'var(--danger, #a8281e)']];
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-box"><header><h3>${kind === 'ar' ? 'Owed to you' : 'You owe'} as at ${esc(fmtDate(asOf, 'always'))}<small>${esc(money(t.total))}</small></h3></header>
        <div class="fwf-box__body">${F.agingBars(B.map(([k, l, c]) => ({ label: l, value: t[k], tone: c })))}</div></div>
      <div class="fwf-box">${d.rows.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>${esc(L(party))}</th>${B.map(([, l]) => `<th class="r">${esc(l)}</th>`).join('')}<th class="r">Total</th></tr></thead>
        <tbody>${d.rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}"><td><b>${esc(r.name)}</b> <small class="fwf-muted">${r.docs}</small></td>${B.map(([k]) => `<td class="r${k !== 'current' && r[k] > 0 ? ' fwf-neg' : ''}">${esc(F.dc(r[k]))}</td>`).join('')}<td class="r"><b>${esc(F.group(r.total))}</b></td></tr>`).join('')}</tbody>
        <tfoot><tr><td>Total</td>${B.map(([k]) => `<td class="r">${esc(F.group(t[k]))}</td>`).join('')}<td class="r">${esc(F.group(t.total))}</td></tr></tfoot></table></div>`
        : F.emptyState('if-approve', 'Nothing outstanding', `No posted ${kind === 'ar' ? 'invoices' : lower('bills')} were open on ${esc(fmtDate(asOf, 'always'))}.`, '', true)}</div></div>`;
    A.bindRows(A.el.view, (id) => A.go(route, { view: 'statement', [party]: id, to: asOf }));
  }

  /** A customer or supplier statement as a printable report. */
  async function renderStatement(ctx, kind) {
    const p = ctx.param;
    const ar = kind === 'ar';
    const route = ar ? 'receivables' : 'payables';
    const party = ar ? 'customer' : 'supplier';
    const id = +p(party) || 0;
    const to = F.parseYmd(p('to')) ? p('to') : BOOT.today;
    const from = F.parseYmd(p('from')) ? p('from') : F.ymd(new Date(F.parseYmd(to).getFullYear(), F.parseYmd(to).getMonth() - 2, 1));
    A.head(L(ar ? 'receivables' : 'payables'), 'Statement', `<button class="btn btn--sm btn--ghost" type="button" id="stBack">${icon('arrow-left')}<span>Back</span></button>
      ${id ? A.exportBtn(api.url(ar ? 'receivables.php' : 'payables.php', { op: 'statement', [party]: id, from, to, format: 'csv' })) : ''}
      <button class="btn btn--sm" type="button" id="stPrint">${icon('print')}<span>Print</span></button>`);
    const tools = A.tools(`<select class="select" id="stParty" aria-label="${esc(L(party))}" style="max-width:280px"><option value="">Choose a ${esc(lower(party))}…</option></select><span class="fwf-grow"></span>${A.periodBtn(from, to)}`);
    $('#stBack').addEventListener('click', () => A.go(route, { view: ar ? 'customers' : 'suppliers' }));
    $('#stPrint').addEventListener('click', () => window.print());
    A.bindPeriod(tools, from, to);
    const sel = $('#stParty', tools);
    sel.addEventListener('change', () => A.go(route, A.withParams({ [party]: sel.value })));
    api.get(ar ? 'receivables.php' : 'payables.php', { op: ar ? 'customers' : 'supplier_list' }).then((r) => {
      const list = r.rows;
      if (id && !list.some((x) => x.id === id)) list.unshift({ id, name: '#' + id });
      sel.insertAdjacentHTML('beforeend', list.map((x) => `<option value="${x.id}"${x.id === id ? ' selected' : ''}>${esc(x.name)}</option>`).join(''));
    }).catch(() => {});
    if (!id) { A.el.view.innerHTML = F.emptyState('if-statement', 'Choose a ' + lower(party), 'The statement lists every document and payment with a running balance.'); return; }
    A.el.view.innerHTML = F.skel(6, 36);
    const d = await api.get(ar ? 'receivables.php' : 'payables.php', { op: 'statement', [party]: id, from, to });
    if (!ctx.isCurrent()) return;
    const who = ar ? d.customer : d.supplier;
    const c = ar ? d.currency : 'ZAR';
    const amt = (v) => (c === 'ZAR' ? money(v) : c + ' ' + F.group(v));
    const ag = d.aging;
    A.el.view.innerHTML = `<div class="fwf-page fwf-page--narrow"><div class="fwf-report">
      <div class="fwf-report__head"><div><div class="fwf-eyebrow">${esc(BOOT.company)}</div><h2>${ar ? 'Statement for' : 'Our account with'} ${esc(who.name)}</h2>
        <p class="fwf-muted">${esc(fmtDate(from, 'always'))} to ${esc(fmtDate(to, 'always'))}${c !== 'ZAR' ? ' · in ' + esc(c) : ''}</p></div>
        <div class="fwf-stat"><span>${ar ? 'Owing to us' : 'We owe'}</span><b>${esc(amt(d.closing))}</b></div></div>
      <table class="fwf-table"><thead><tr><th>Date</th><th>Description</th><th class="hide-narrow">Reference</th><th class="r">${ar ? 'Charged' : 'Paid / credited'}</th><th class="r">${ar ? 'Paid / credited' : 'Billed'}</th><th class="r">Balance</th></tr></thead>
      <tbody><tr class="is-group"><td>${esc(fmtDate(from, 'always'))}</td><td colspan="4">Opening balance</td><td class="r">${esc(F.group(d.opening))}</td></tr>
        ${d.lines.map((l) => `<tr><td class="fwf-nowrap">${esc(fmtDate(l.date, 'always'))}</td><td>${esc(l.description)}</td><td class="hide-narrow">${esc(l.reference)}</td>
          <td class="r">${esc(F.dc(l.debit))}</td><td class="r">${esc(F.dc(l.credit))}</td><td class="r">${esc(F.group(l.balance))}</td></tr>`).join('')}</tbody>
      <tfoot><tr><td colspan="5">Closing balance</td><td class="r">${esc(F.group(d.closing))}</td></tr></tfoot></table>
      ${ag ? `<div class="fwf-sheet__sec"><h4>${icon('if-hourglass')}Aging at ${esc(fmtDate(to, 'always'))}</h4><dl class="fwf-kv">${[['current', 'Current'], ['d30', '1–30 days'], ['d60', '31–60 days'], ['d90', '61–90 days'], ['d90p', '90+ days']].map(([k, l]) => `<dt>${l}</dt><dd>${esc(money(ag[k]))}</dd>`).join('')}</dl></div>` : ''}
      ${!d.lines.length ? '<p class="fwf-muted">Nothing happened in this period.</p>' : ''}</div></div>`;
  }

  /** The money side of one invoice. */
  async function openInvoice(id) {
    const s = F.sheet({ title: 'Invoice', sub: 'Loading…', html: F.skel(4, 46), onClose: () => { if (A.param('id')) A.setParams({ id: '' }); } });
    A.setParams({ id });
    try { paintInvoice(s, await api.get('receivables.php', { op: 'invoice', id })); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); }
  }
  function paintInvoice(s, d) {
    const i = d.invoice;
    const c = i.currency;
    $('h2', s).textContent = 'Invoice ' + i.number;
    $('.fwf-eyebrow', s).textContent = `${i.customer} · ${fmtDate(i.issued, 'always')}`;
    const open = i.balance > 0 && !['paid', 'cancelled', 'written_off', 'draft', 'refunded', 'uncollectible'].includes(i.status);
    $('.fwf-sheet__acts', s).innerHTML = `${pill(i.status)}${can('write') && i.payable ? `<button class="btn btn--sm btn--primary" type="button" data-a="recv">${icon('if-in')}Receive</button>` : ''}
      <button class="icon-btn" type="button" data-a="more" aria-label="More actions">${icon('more-v')}</button>`;
    $('.fwf-sheet__body', s).innerHTML = `
      ${i.days_late ? `<div class="fwf-banner fwf-banner--danger">${icon('alert')}<span><b>${i.days_late} days late.</b> ${d.emails.some((m) => m.kind === 'reminder') ? 'Last reminder ' + esc(relTime(d.emails.find((m) => m.kind === 'reminder').at)) + '.' : 'No reminder sent yet.'}</span>
        ${can('write') ? `<button class="fwf-mini" type="button" data-a="remind">${icon('send')}Send a reminder</button>` : ''}</div>` : ''}
      <dl class="fwf-kv">
        <dt>${esc(L('customer'))}</dt><dd><a href="#receivables?customer=${i.customer_id}">${esc(i.customer)}</a> · <a href="#receivables?view=statement&customer=${i.customer_id}">statement</a></dd>
        <dt>Issued</dt><dd>${esc(fmtDate(i.issued, 'always'))}</dd>
        <dt>Due</dt><dd>${i.due ? esc(fmtDate(i.due, 'always')) + ' · ' + dueCell(i.due, i.days_late) : '—'}</dd>
        <dt>Total</dt><dd><b>${esc(cur(i.total, c))}</b> <small class="fwf-muted">incl. ${esc(cur(i.tax, c))} ${esc(L('vat'))}</small></dd>
        <dt>Still owing</dt><dd><b class="${open ? '' : 'fwf-pos'}">${esc(cur(i.balance, c))}</b>${c !== 'ZAR' ? ` <small class="fwf-muted">≈ ${esc(money(i.balance * i.rate))} at ${esc(i.rate)}</small>` : ''}</dd>
        ${i.written_off ? `<dt>Written off</dt><dd>${esc(cur(i.written_off, c))}</dd>` : ''}
      </dl>
      <div class="fwf-sheet__sec"><h4>${icon('if-cash')}Payments<small>${d.payments.length || 'none yet'}</small></h4>
        ${d.payments.length ? `<table class="fwf-table"><tbody>${d.payments.map((x) => `<tr class="${x.refunded ? 'is-muted' : ''}"><td class="fwf-nowrap">${esc(fmtDate(x.date, 'always'))}</td><td>${esc(x.method.toUpperCase())}${x.reference ? ' · ' + esc(x.reference) : ''}${x.refunded ? ' · refunded' : ''}</td>
          <td>${x.journal_id ? `<a href="#journals?id=${x.journal_id}" data-j="${x.journal_id}">#${x.journal_id}</a>` : ''}</td><td class="r"><b>${esc(cur(x.amount, c))}</b></td></tr>`).join('')}</tbody></table>` : ''}</div>
      ${d.credits.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-credit')}Credit notes</h4><table class="fwf-table"><tbody>${d.credits.map((x) => `<tr><td>${esc(fmtDate(x.date, 'always'))}</td><td><a href="/qi/view.php?type=credit_note&id=${x.id}">${esc(x.number)}</a></td><td class="r">${esc(cur(x.amount, c))}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${d.journals.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-journal')}In the books</h4><div class="fwf-rows">${d.journals.map((j) => `<a class="fwf-rowlink" href="#journals?id=${j.id}" data-j="${j.id}"><span class="fwf-code">#${j.id}</span><span class="fwf-rows__text"><b>${esc(j.reference)}</b><small>${esc(fmtDate(j.date, 'always'))}</small></span>${F.pill(j.status)}</a>`).join('')}</div></div>` : ''}
      ${d.emails.length ? `<div class="fwf-sheet__sec"><h4>${icon('mail')}Emails</h4><div class="fwf-trail">${d.emails.map((m) => `<div><b>${m.kind === 'reminder' ? 'Reminder' : 'Invoice'}</b> to ${esc(m.to)} · ${esc(m.status)}<small>${esc(relTime(m.at))}</small></div>`).join('')}</div></div>` : ''}`;
    $$('[data-j]', s).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
    const refresh = async () => { try { paintInvoice(s, await api.get('receivables.php', { op: 'invoice', id: i.id })); } catch (e) { toastError(e); } };
    $$('[data-a]', s).forEach((b) => b.addEventListener('click', () => {
      const a = b.dataset.a;
      if (a === 'recv') receiveDialog(i.customer_id, i.id, () => { refresh(); after(); });
      if (a === 'remind') reminderDialog(i, refresh);
      if (a === 'more') popover(b, `<a class="fwm-pop__item" href="${esc(d.qi_url)}">${icon('external')}Open in Quotes &amp; Invoices</a>
        ${can('write') && open ? `<button class="fwm-pop__item" type="button" data-v="remind">${icon('send')}Send a payment reminder</button>` : ''}
        <a class="fwm-pop__item" href="#receivables?view=statement&customer=${i.customer_id}">${icon('if-statement')}${esc(L('customer'))} statement</a>
        <a class="fwm-pop__item" href="#receivables?customer=${i.customer_id}&status=all">${icon('list')}All their invoices</a>`, {
        align: 'end', onClick: (it) => { if (it.dataset.v === 'remind') reminderDialog(i, refresh); },
      });
    }));
  }

  async function reminderDialog(inv, done) {
    let draft;
    try { draft = (await api.get('receivables.php', { op: 'reminder', id: inv.id })).draft; } catch (e) { toastError(e); return; }
    F.formDialog({
      title: 'Payment reminder · ' + inv.number, submit: 'Send reminder', wide: true,
      html: `<p class="fwm-dialog__lead">Sent from your mailbox in Mail, with the invoice attached. It is logged on the invoice.</p>
        <div class="field"><label class="label" for="rmTo">To<span class="req">*</span></label><input class="input" id="rmTo" name="to" type="email" multiple value="${esc((draft.to || []).join(', '))}" placeholder="accounts@customer.co.za"></div>
        <div class="field"><label class="label" for="rmSub">Subject</label><input class="input" id="rmSub" name="subject" maxlength="200" value="${esc(draft.subject || '')}"></div>
        <div class="field"><label class="label" for="rmBody">Message</label><textarea class="textarea" id="rmBody" name="body" rows="9">${esc(draft.body || '')}</textarea></div>`,
      onSubmit: async (f) => {
        const to = f.to.value.split(/[,;\s]+/).filter(Boolean);
        if (!to.length) throw new Error("Add the customer's email address.");
        await api.post('receivables.php', { op: 'remind', id: inv.id, to, subject: f.subject.value, body: f.body.value });
        toast('Reminder sent');
        if (done) done();
      },
    });
  }

  /** Receive money from a customer against one or more of their open invoices. */
  async function receiveDialog(customerId = 0, invoiceId = 0, onDone) {
    let customers = [];
    try { customers = (await api.get('receivables.php', { op: 'customers' })).rows; } catch (e) { toastError(e); return; }
    if (!customers.length) { toast('Nobody owes you money right now.'); return; }
    if (!customers.some((c) => c.id === customerId)) customerId = customers[0].id;
    const key = uid();
    const dlg = F.formDialog({
      title: 'Receive a payment', wide: true, submit: 'Record payment',
      html: `<div class="fwf-grid3">
          <div class="field fwf-span2"><label class="label" for="rcCust">${esc(L('customer'))}<span class="req">*</span></label><select class="select" id="rcCust" name="customer">${customers.map((c) => `<option value="${c.id}"${c.id === customerId ? ' selected' : ''}>${esc(c.name)} · ${esc(money(c.balance))}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="rcDate">Date received<span class="req">*</span></label><input class="input" id="rcDate" name="date" type="date" max="${esc(BOOT.today)}" value="${esc(BOOT.today)}"></div>
          <div class="field"><label class="label" for="rcAmt">Amount received</label><input class="input fwf-amount" id="rcAmt" name="amount" inputmode="decimal" placeholder="0.00"><div class="fwf-hint">Spread over the oldest invoices first.</div></div>
          <div class="field"><label class="label" for="rcBank">Into</label><select class="select" id="rcBank" name="bank">${BOOT.banks.length ? F.bankOptions(BOOT.banks[0].id) : '<option value="">The default bank account</option>'}</select></div>
          <div class="field"><label class="label" for="rcMeth">Method</label><select class="select" id="rcMeth" name="method">${methodOptions('eft')}</select></div>
          <div class="field fwf-span-all"><label class="label" for="rcRef">Reference</label><input class="input" id="rcRef" name="reference" maxlength="190" placeholder="As on the bank statement"></div>
        </div>
        <div class="fwf-formsec"><h4>Allocate to invoices<small id="rcSum"></small></h4><div id="rcRows">${F.skel(2, 36)}</div></div>`,
      onSubmit: async (f) => {
        const allocations = rows.map((r, n) => ({ invoice_id: r.id, amount: F.parseMoney(inputs[n].value) || 0 })).filter((a) => a.amount > 0);
        if (!allocations.length) throw new Error('Enter the amount received against at least one invoice.');
        const bad = rows.find((r, n) => (F.parseMoney(inputs[n].value) || 0) > r.balance + 0.004);
        if (bad) throw new Error(`That is more than the ${cur(bad.balance, bad.currency)} still owing on ${bad.number}.`);
        const r = await api.post('receivables.php', { op: 'receive', customer_id: +f.customer.value, date: f.date.value, method: f.method.value, reference: f.reference.value.trim(), bank_account_id: +f.bank.value || null, allocations, key });
        const n = r.done.filter((x) => !x.duplicate).length;
        toast(n ? `Payment recorded on ${n} invoice${n === 1 ? '' : 's'}` : 'This payment was already recorded');
        if (onDone) onDone(); else after();
      },
    });
    const f = dlg.form;
    let rows = [], inputs = [];
    const sum = () => {
      const t = inputs.reduce((s, x) => s + (F.parseMoney(x.value) || 0), 0);
      const owe = rows.reduce((s, r) => s + r.balance, 0);
      $('#rcSum', dlg.m).textContent = `${money(t)} allocated of ${money(owe)} owing`;
    };
    const load = async () => {
      const host = $('#rcRows', dlg.m);
      host.innerHTML = F.skel(2, 36);
      try { rows = (await api.get('receivables.php', { op: 'open', customer: f.customer.value })).rows; } catch (e) { host.innerHTML = F.errorBox(e); return; }
      const mixed = new Set(rows.map((r) => r.currency)).size > 1 || rows.some((r) => r.currency !== 'ZAR');
      f.amount.disabled = mixed;
      if (mixed) f.amount.placeholder = 'per invoice';
      host.innerHTML = rows.length ? `<table class="fwf-table"><thead><tr><th>Invoice</th><th class="hide-narrow">Issued</th><th>Due</th><th class="r">Owing</th><th class="r" style="width:150px">Receive</th></tr></thead><tbody>${rows.map((r) => `<tr>
        <td><b>${esc(r.number)}</b></td><td class="hide-narrow">${esc(fmtDate(r.issued, 'always'))}</td><td>${dueCell(r.due, r.days_late)}</td><td class="r">${esc(cur(r.balance, r.currency))}</td>
        <td class="r"><input class="input fwf-amount" inputmode="decimal" placeholder="0.00" aria-label="Receive against ${esc(r.number)}" data-max="${r.balance}"></td></tr>`).join('')}</tbody></table>
        <button class="fwf-mini" type="button" id="rcAll" style="margin-top:6px">${icon('check')}Pay all in full</button>`
        : '<p class="fwf-muted">This customer has nothing open.</p>';
      inputs = $$('input[data-max]', host);
      inputs.forEach((x) => { x.addEventListener('input', sum); x.addEventListener('blur', () => { const v = F.parseMoney(x.value); x.value = v ? Math.abs(v).toFixed(2) : ''; sum(); }); });
      const all = $('#rcAll', host);
      if (all) all.addEventListener('click', () => { inputs.forEach((x, n) => { x.value = rows[n].balance.toFixed(2); }); if (!f.amount.disabled) f.amount.value = rows.reduce((s, r) => s + r.balance, 0).toFixed(2); sum(); });
      if (invoiceId) { const n = rows.findIndex((r) => r.id === invoiceId); if (n >= 0) { inputs[n].value = rows[n].balance.toFixed(2); if (!mixed) f.amount.value = rows[n].balance.toFixed(2); inputs[n].focus(); } invoiceId = 0; }
      sum();
    };
    f.customer.addEventListener('change', () => { f.amount.value = ''; load(); });
    f.amount.addEventListener('input', () => { const v = F.parseMoney(f.amount.value); spread(rows, inputs, v || 0); sum(); });
    load();
  }

  // =========================================================== payables
  async function renderPayables(ctx) {
    const p = ctx.param;
    const view = ['bills', 'payments', 'credits', 'suppliers', 'aging', 'statement', 'match'].includes(p('view')) ? p('view') : 'bills';
    if (view === 'statement') return renderStatement(ctx, 'ap');
    const status = p('status', 'open');
    const asOf = F.parseYmd(p('as_of')) ? p('as_of') : BOOT.today;
    const exportHref = view === 'aging' ? api.url('payables.php', { op: 'aging', as_of: asOf, format: 'csv' }) : api.url('payables.php', { op: 'bills', status, supplier: p('supplier'), q: p('q'), format: 'csv' });
    A.head(BOOT.company, L('payables'), `${['bills', 'aging'].includes(view) ? A.exportBtn(exportHref) : ''}
      ${can('write') ? `<button class="btn btn--sm btn--ghost" type="button" id="apPay">${icon('if-cash')}<span>Pay a ${esc(lower('supplier'))}</span></button>
      <button class="btn btn--primary" type="button" id="apNew">${icon('plus')}<span>${view === 'credits' ? 'New credit note' : 'New ' + esc(lower('bill'))}</span></button>` : ''}`);
    const tools = A.tools(`${viewSeg([['bills', L('bills')], ['payments', 'Payments'], ['credits', 'Credit notes'], ['suppliers', L('suppliers')], ['aging', 'Aging'], ['match', 'Match']], view)}<span id="apChips"></span><span class="fwf-grow"></span>
      ${view === 'aging' ? `<label class="fwf-qbox" style="max-width:190px">${icon('calendar')}<input type="date" id="apAsOf" value="${esc(asOf)}" aria-label="Aging as at"></label>` : view === 'match' ? '' : A.qbox(p('q'), view === 'suppliers' ? 'Find a ' + lower('supplier') : 'Number, ' + lower('supplier') + ' or amount')}${view !== 'match' ? A.saveViewBtn() : ''}`);
    bindViewSeg(tools, 'payables');
    A.bindQbox(tools);
    A.bindSaveView(tools);
    const asIn = $('#apAsOf', tools);
    if (asIn) asIn.addEventListener('change', () => { if (asIn.value) A.go('payables', A.withParams({ as_of: asIn.value })); });
    const hb = (sel, fn) => { const b = $(sel); if (b) b.addEventListener('click', fn); };
    hb('#apPay', () => payDialog(+p('supplier') || 0));
    hb('#apNew', () => (view === 'credits' ? creditEditor() : billEditor()));
    A.el.view.innerHTML = F.skel(7, 48);
    if (view === 'payments') await apPayments(ctx);
    else if (view === 'credits') await apCredits(ctx);
    else if (view === 'suppliers') await apSuppliers(ctx);
    else if (view === 'aging') await partyAging(ctx, 'ap', asOf);
    else if (view === 'match') await apMatch(ctx);
    else await apBills(ctx, status);
    if (p('id') && !ctx.keep) openBill(+p('id'));
    if (p('credit') && !ctx.keep) openCredit(+p('credit'));
    const nw = p('new');
    if (nw && can('write')) {
      A.setParams({ new: '' });
      if (nw === 'bill') billEditor();
      if (nw === 'payment') payDialog(+p('supplier') || 0, +p('bill') || 0);
      if (nw === 'credit') creditEditor();
    }
  }

  async function apBills(ctx, status) {
    const p = ctx.param;
    const d = await api.get('payables.php', { op: 'bills', status, supplier: p('supplier'), q: p('q'), sort: p('sort', 'due'), dir: p('dir', 'asc'), page: p('page', 1) });
    if (!ctx.isCurrent()) return;
    $('#apChips').innerHTML = A.chips([['open', 'To pay'], ['overdue', 'Late'], ['due', 'Due soon'], ['draft', 'Drafts'], ['paid', 'Paid'], ['all', 'All']], status, d.counts, ['overdue']);
    A.bindChips($('#apChips'));
    const rows = d.rows;
    A.el.view.innerHTML = rows.length ? `
      <div class="fwf-sum"><span><b>${d.total}</b> ${esc(lower(d.total === 1 ? 'bill' : 'bills'))}</span><span>${status === 'draft' ? 'Value' : 'Owing'} <b>${esc(money(d.sum))}</b></span>
        ${p('supplier') ? `<span>${esc(L('supplier'))} <b>${esc(rows[0].supplier)}</b> <button class="fwf-link" type="button" data-clear="supplier">clear</button> · <a class="fwf-link" href="#payables?view=statement&supplier=${esc(p('supplier'))}">statement</a></span>` : ''}</div>
      <div class="fwf-table-wrap can-card"><table class="fwf-table"><thead><tr>${A.sortTh('number', L('bill'))}<th>${esc(L('supplier'))}</th>${A.sortTh('issued', 'Dated', 'hide-narrow')}${A.sortTh('due', 'Due')}<th>Status</th>${A.sortTh('balance', 'Balance', 'r')}<th></th></tr></thead>
      <tbody>${rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}">
        <td class="fwf-nowrap"><b>${esc(r.number)}</b>${r.files ? ` <small class="fwf-muted">${icon('clip', 'i--xs')}${r.files}</small>` : ''}</td>
        <td><div class="fwf-cell2"><b>${esc(r.supplier)}</b><small>${r.from_receipts ? 'from Receipts' : ''}</small></div></td>
        <td class="fwf-nowrap hide-narrow">${esc(fmtDate(r.issued, 'always'))}</td><td class="fwf-nowrap">${['draft', 'paid'].includes(r.status) ? (r.due ? esc(fmtDate(r.due, 'always')) : '—') : dueCell(r.due, r.days_late)}</td>
        <td>${pill(r.status)}</td><td class="r"><b>${esc(money(r.balance))}</b></td>
        <td class="r">${can('write') && ['posted', 'overdue'].includes(r.status) && r.balance > 0 ? `<button class="fwf-mini" type="button" data-pay="${r.id}" data-sup="${r.supplier_id}">${icon('if-cash')}Pay</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
      <div class="fwf-cards">${rows.map((r) => `<div class="fwf-card" data-open="${r.id}" tabindex="0"><span class="fwf-type">${icon('if-out')}</span>
        <span class="fwf-card__text"><b>${esc(r.number)} · ${esc(r.supplier)}</b><small>${r.days_late ? esc(r.days_late + ' days late') : r.due ? 'Due ' + esc(fmtDate(r.due)) : esc(fmtDate(r.issued))}</small></span>
        <span class="fwf-card__side">${esc(money(r.balance))}${pill(r.status)}</span></div>`).join('')}</div>
      ${A.pager(d.page, d.per, d.total)}`
      : F.emptyState(status === 'open' || status === 'overdue' ? 'if-approve' : 'if-out', status === 'open' ? 'Nothing to pay' : status === 'overdue' ? 'Nothing is late' : 'No ' + lower('bills') + ' here',
        status === 'draft' ? 'Bills captured in Receipts or saved as drafts wait here until they are posted.' : 'Capture supplier invoices here, post them, then pay them in one go.',
        can('write') ? `<button class="btn btn--primary" type="button" data-newbill>${icon('plus')}New ${esc(lower('bill'))}</button>` : '');
    A.bindSort(A.el.view);
    A.bindPager(A.el.view);
    A.bindRows(A.el.view, (id) => openBill(+id));
    $$('[data-clear]', A.el.view).forEach((b) => b.addEventListener('click', () => A.go('payables', A.withParams({ [b.dataset.clear]: '', page: 1 }))));
    $$('[data-pay]', A.el.view).forEach((b) => b.addEventListener('click', () => payDialog(+b.dataset.sup, +b.dataset.pay)));
    const nb = $('[data-newbill]', A.el.view);
    if (nb) nb.addEventListener('click', () => billEditor());
  }

  async function apPayments(ctx) {
    const p = ctx.param;
    const d = await api.get('payables.php', { op: 'payments', supplier: p('supplier'), q: p('q'), page: p('page', 1) });
    if (!ctx.isCurrent()) return;
    A.el.view.innerHTML = d.rows.length ? `<div class="fwf-sum"><span><b>${d.total}</b> payment(s)</span><span>Total <b>${esc(money(d.sum))}</b></span></div>
      <div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Date</th><th>${esc(L('supplier'))}</th><th class="hide-narrow">${esc(L('bills'))}</th><th class="hide-narrow">From</th><th>Reference</th><th class="r">Amount</th><th></th></tr></thead>
      <tbody>${d.rows.map((r) => `<tr><td class="fwf-nowrap">${esc(fmtDate(r.date, 'always'))}</td><td><b>${esc(r.supplier)}</b></td><td class="hide-narrow">${esc(r.bills)}</td>
        <td class="hide-narrow">${esc(r.bank || 'default bank')} · ${esc(r.method.toUpperCase())}</td><td>${esc(r.reference)}</td><td class="r"><b>${esc(money(r.amount))}</b></td>
        <td class="r">${r.journal_id ? `<a class="fwf-mini" href="#journals?id=${r.journal_id}" data-j="${r.journal_id}">#${r.journal_id}</a>` : ''}</td></tr>`).join('')}</tbody></table></div>${A.pager(d.page, d.per, d.total)}`
      : F.emptyState('if-cash', 'No payments yet', 'Payments to suppliers show here with the bills they settled.');
    A.bindPager(A.el.view);
    $$('[data-j]', A.el.view).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
  }

  async function apCredits(ctx) {
    const p = ctx.param;
    const d = await api.get('payables.php', { op: 'credits', supplier: p('supplier'), q: p('q') });
    if (!ctx.isCurrent()) return;
    A.el.view.innerHTML = d.rows.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Credit note</th><th>${esc(L('supplier'))}</th><th>Date</th><th>Status</th><th class="r">Total</th><th class="r">Left to apply</th></tr></thead>
      <tbody>${d.rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}"><td><b>${esc(r.number)}</b></td><td>${esc(r.supplier)}</td><td class="fwf-nowrap">${esc(fmtDate(r.date, 'always'))}</td>
        <td>${pill(r.status)}</td><td class="r">${esc(money(r.total))}</td><td class="r">${r.posted && r.remaining > 0 ? `<b>${esc(money(r.remaining))}</b>` : '—'}</td></tr>`).join('')}</tbody></table></div>`
      : F.emptyState('if-credit', 'No supplier credit notes', 'When a supplier credits you (a return, a discount or a mistake), capture the credit note here and apply it to their bills.',
        can('write') ? `<button class="btn btn--primary" type="button" data-newcr>${icon('plus')}New credit note</button>` : '');
    A.bindRows(A.el.view, (id) => openCredit(+id));
    const nb = $('[data-newcr]', A.el.view);
    if (nb) nb.addEventListener('click', () => creditEditor());
  }

  async function apSuppliers(ctx) {
    const d = await api.get('payables.php', { op: 'suppliers', q: ctx.param('q') });
    if (!ctx.isCurrent()) return;
    const rows = d.rows;
    const tot = rows.reduce((s, r) => s + r.balance, 0), late = rows.reduce((s, r) => s + r.late, 0);
    A.el.view.innerHTML = rows.length ? `<div class="fwf-sum"><span>You owe <b>${rows.length}</b> ${esc(lower(rows.length === 1 ? 'supplier' : 'suppliers'))}</span><span>Total <b>${esc(money(tot))}</b></span><span>Late <b class="${late > 0 ? 'fwf-neg' : ''}">${esc(money(late))}</b></span></div>
      <div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>${esc(L('supplier'))}</th><th class="r">${esc(L('bills'))}</th><th class="r">Owing</th><th class="r">Late</th><th class="hide-narrow">Next due</th><th></th></tr></thead>
      <tbody>${rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}"><td><b>${esc(r.name)}</b></td><td class="r">${r.bills}</td><td class="r"><b>${esc(money(r.balance))}</b></td>
        <td class="r">${r.late > 0 ? `<span class="fwf-neg">${esc(money(r.late))}</span>` : '—'}</td><td class="hide-narrow">${r.next_due ? esc(fmtDate(r.next_due, 'always')) : '—'}</td>
        <td class="r fwf-nowrap"><a class="fwf-mini" href="#payables?supplier=${r.id}" data-stop>${esc(L('bills'))}</a> ${can('write') ? `<button class="fwf-mini" type="button" data-pay="${r.id}">${icon('if-cash')}Pay</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
      : F.emptyState('if-approve', 'You owe nobody', 'Suppliers with posted, unpaid bills show here, the most urgent first.');
    A.bindRows(A.el.view, (id) => A.go('payables', { view: 'statement', supplier: id }));
    $$('[data-pay]', A.el.view).forEach((b) => b.addEventListener('click', () => payDialog(+b.dataset.pay)));
  }

  // ------------------------------------------------------------- the bill
  async function openBill(id) {
    const s = F.sheet({ title: L('bill'), sub: 'Loading…', html: F.skel(4, 46), wide: true, onClose: () => { if (A.param('id')) A.setParams({ id: '' }); } });
    A.setParams({ id, credit: '' });
    try { paintBill(s, await api.get('payables.php', { op: 'bill', id })); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); }
  }
  function paintBill(s, d) {
    const b = d.bill;
    const refresh = async (msg) => { if (msg) toast(msg); try { paintBill(s, await api.get('payables.php', { op: 'bill', id: b.id })); } catch (e) { toastError(e); } after(); };
    $('h2', s).textContent = `${b.number} · ${b.supplier}`;
    $('.fwf-eyebrow', s).textContent = `${L('bill')} · ${fmtDate(b.issued, 'always')}`;
    const payable = ['posted', 'overdue'].includes(b.status) && b.balance > 0;
    $('.fwf-sheet__acts', s).innerHTML = `${pill(b.raw_status === 'review' ? 'review' : b.status)}
      ${can('write') && b.editable ? `<button class="btn btn--sm" type="button" data-a="edit">${icon('pen')}Edit</button>` : ''}
      ${can('write') && b.postable ? `<button class="btn btn--sm btn--primary" type="button" data-a="post">${icon('if-post')}Post</button>` : ''}
      ${can('write') && payable ? `<button class="btn btn--sm btn--primary" type="button" data-a="pay">${icon('if-cash')}Pay</button>` : ''}
      <button class="icon-btn" type="button" data-a="more" aria-label="More actions">${icon('more-v')}</button>`;
    $('.fwf-sheet__body', s).innerHTML = `
      ${b.raw_status === 'review' ? `<div class="fwf-banner fwf-banner--info">${icon('i-receipt')}<span>Captured from a receipt or invoice in Receipts. Check the lines and accounts, then post it.</span></div>` : ''}
      ${b.editable ? `<div class="fwf-banner fwf-banner--warn">${icon('info')}<span>Not in the books yet. Posting puts ${esc(money(b.total))} on the ${esc(lower('supplier'))}'s account and the expense and ${esc(L('vat'))} accounts.</span></div>` : ''}
      <dl class="fwf-kv">
        <dt>${esc(L('supplier'))}</dt><dd><a href="#payables?supplier=${b.supplier_id}">${esc(b.supplier)}</a> · <a href="#payables?view=statement&supplier=${b.supplier_id}">statement</a></dd>
        <dt>Their invoice</dt><dd>${esc(b.number)}${b.vendor_vat ? ' · VAT no. ' + esc(b.vendor_vat) : ''}</dd>
        <dt>Dated</dt><dd>${esc(fmtDate(b.issued, 'always'))}</dd>
        <dt>Due</dt><dd>${b.due ? esc(fmtDate(b.due, 'always')) + (payable ? ' · ' + dueCell(b.due, b.status === 'overdue' ? F.daysFrom(b.due) * -1 : 0) : '') : '—'}</dd>
        <dt>Total</dt><dd><b>${esc(money(b.total))}</b> <small class="fwf-muted">${esc(money(b.subtotal))} + ${esc(money(b.tax))} ${esc(L('vat'))}</small></dd>
        ${b.paid || b.credited ? `<dt>Paid / credited</dt><dd>${esc(money(b.paid))}${b.credited ? ' + ' + esc(money(b.credited)) + ' credit' : ''}</dd>` : ''}
        ${['posted', 'paid', 'overdue'].includes(b.status) ? `<dt>Still owing</dt><dd><b class="${b.balance > 0 ? '' : 'fwf-pos'}">${esc(money(b.balance))}</b></dd>` : ''}
        ${b.journal_id ? `<dt>In the books</dt><dd><a href="#journals?id=${b.journal_id}" data-j="${b.journal_id}">Journal #${b.journal_id}</a></dd>` : ''}
        ${b.notes ? `<dt>Notes</dt><dd>${esc(b.notes)}</dd>` : ''}
      </dl>
      <div class="fwf-sheet__sec"><h4>${icon('list')}Lines<small>${d.lines.length}</small></h4>
        <div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Description</th><th>Account</th><th class="r">Qty</th><th class="r">Price</th><th class="r">${esc(L('vat'))}</th><th class="r">Net</th></tr></thead>
        <tbody>${d.lines.map((l) => `<tr><td>${esc(l.description)}</td><td>${l.account_code ? `<a href="#ledger?account=${encodeURIComponent(l.account_code)}"><span class="fwf-code">${esc(l.account_code)}</span></a> ${esc(l.account_name)}` : '<span class="fwf-muted">default expense</span>'}</td>
          <td class="r">${esc(l.qty)}</td><td class="r">${esc(F.group(l.unit_price))}${l.discount ? `<div class="fwf-muted" style="font-size:11px">−${esc(F.group(l.discount))}</div>` : ''}</td><td class="r">${esc(l.tax_rate)}%</td><td class="r">${esc(F.group(l.net))}</td></tr>`).join('')}</tbody>
        <tfoot><tr><td colspan="4">Total</td><td class="r">${esc(F.group(b.tax))}</td><td class="r">${esc(F.group(b.subtotal))}</td></tr></tfoot></table></div></div>
      ${d.payments.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-cash')}Payments</h4><table class="fwf-table"><tbody>${d.payments.map((x) => `<tr><td class="fwf-nowrap">${esc(fmtDate(x.date, 'always'))}</td><td>${esc(x.method.toUpperCase())}${x.reference ? ' · ' + esc(x.reference) : ''}</td><td>${x.journal_id ? `<a href="#journals?id=${x.journal_id}" data-j="${x.journal_id}">#${x.journal_id}</a>` : ''}</td><td class="r"><b>${esc(money(x.amount))}</b></td></tr>`).join('')}</tbody></table></div>` : ''}
      ${d.credits.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-credit')}Credit notes applied</h4><table class="fwf-table"><tbody>${d.credits.map((x) => `<tr class="fwf-row" data-cr="${x.id}"><td>${esc(fmtDate(x.date, 'always'))}</td><td><b>${esc(x.number)}</b></td><td class="r">${esc(money(x.amount))}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${A.filesBlock('bill', b.id, d.files, can('write'))}`;
    A.bindFiles(s, () => refresh());
    $$('[data-j]', s).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
    $$('[data-cr]', s).forEach((a) => a.addEventListener('click', () => openCredit(+a.dataset.cr)));
    $$('[data-a]', s).forEach((btn) => btn.addEventListener('click', async () => {
      const a = btn.dataset.a;
      if (a === 'edit') billEditor({ ...b, lines: d.lines }, () => refresh());
      if (a === 'pay') payDialog(b.supplier_id, b.id, () => refresh('Payment recorded'));
      if (a === 'post') {
        if (!(await dialog({ title: `Post ${b.number}?`, body: `<b>${esc(money(b.total))}</b> goes into the books on ${esc(fmtDate(b.issued, 'always'))}. A posted ${esc(lower('bill'))} cannot change; a mistake is corrected with a supplier credit note.`, confirm: 'Post' }))) return;
        try { await api.post('payables.php', { op: 'bill_post', id: b.id }); refresh(L('bill') + ' posted'); } catch (e) { toastError(e); }
      }
      if (a === 'more') popover(btn, `${can('write') && ['posted', 'overdue', 'paid'].includes(b.status) ? `<button class="fwm-pop__item" type="button" data-v="credit">${icon('if-credit')}Record a credit note for this</button>` : ''}
        <a class="fwm-pop__item" href="#payables?view=statement&supplier=${b.supplier_id}">${icon('if-statement')}${esc(L('supplier'))} statement</a>
        <a class="fwm-pop__item" href="#payables?supplier=${b.supplier_id}&status=all">${icon('list')}All their ${esc(lower('bills'))}</a>
        ${b.receipt_url ? `<a class="fwm-pop__item" href="${esc(b.receipt_url)}">${icon('external')}Open Receipts</a>` : ''}
        <button class="fwm-pop__item" type="button" data-v="print">${icon('print')}Print</button>
        ${can('write') && b.editable ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" type="button" data-v="delete">${icon('trash')}Delete draft</button>` : ''}`, {
        align: 'end',
        onClick: async (it) => {
          const v = it.dataset.v;
          if (v === 'print') window.print();
          if (v === 'credit') creditEditor({ supplier_id: b.supplier_id, lines: d.lines.map((l) => ({ ...l })), notes: 'Credit against ' + b.number, reason: 'correction' });
          if (v === 'delete') {
            if (!(await dialog({ title: 'Delete this draft?', body: 'It was never posted, so nothing changes in the books.', confirm: 'Delete', danger: true }))) return;
            try { await api.post('payables.php', { op: 'bill_delete', id: b.id }); toast('Draft deleted'); F.closeSheet(); after(); } catch (e) { toastError(e); }
          }
        },
      });
    }));
  }

  /** VAT rates offered on a document line (the company's tax codes). */
  function rateOptions(sel) {
    const rates = [...new Set((BOOT.tax_codes || []).map((t) => t.rate))].sort((a, b) => b - a);
    if (!rates.includes(0)) rates.push(0);
    const std = rates[0];
    if (sel === undefined || sel === null || sel === '') sel = std;
    return rates.map((r) => `<option value="${r}"${Number(sel) === r ? ' selected' : ''}>${r}%${r === std && r > 0 ? '' : r === 0 ? ' (zero / exempt)' : ''}</option>`).join('');
  }

  /**
   * Document lines editor (bill or supplier credit). Mirrors the engine: each line's
   * net is rounded to the cent and its VAT is rounded per line.
   */
  function linesEditor(m, host, lines, onChange) {
    // what the editor does not show (discount, unit, stock item, project tag) travels with the line
    const carry = (l) => esc(JSON.stringify({ discount: l.discount || 0, unit: l.unit || '', inventory_item_id: l.inventory_item_id || null, board_id: l.board_id || null, project_item_id: l.project_item_id || null }));
    const row = (l, i) => `<tr data-i="${i}" data-x="${carry(l)}">
      <td class="c-desc"><input class="input" name="desc" maxlength="255" placeholder="What was bought" value="${esc(l.description || '')}" aria-label="Description, line ${i + 1}"></td>
      <td class="c-acc"><input class="input" name="acc" placeholder="Account" data-code="${esc(l.account_code || (l.account_id && F.acctById(l.account_id) ? F.acctById(l.account_id).code : ''))}" aria-label="Account, line ${i + 1}"></td>
      <td class="c-qty"><input class="input fwf-amount" name="qty" inputmode="decimal" value="${esc(l.qty != null ? l.qty : 1)}" aria-label="Quantity, line ${i + 1}"></td>
      <td class="c-amt"><input class="input fwf-amount" name="price" inputmode="decimal" placeholder="0.00" value="${l.unit_price != null && l.unit_price !== '' ? esc(F.r2(l.unit_price).toFixed(2)) : ''}" aria-label="Unit price excl. VAT, line ${i + 1}"></td>
      <td class="c-tax"><select class="select" name="rate" aria-label="VAT rate, line ${i + 1}">${rateOptions(l.tax_rate)}</select></td>
      <td data-net>—</td>
      <td class="c-x"><button class="icon-btn" type="button" data-rm aria-label="Remove line ${i + 1}">${icon('x')}</button></td></tr>`;
    host.innerHTML = `<table class="fwf-lines fwf-lines--doc"><thead><tr><th>Description</th><th>Account</th><th class="r">Qty</th><th class="r">Price excl.</th><th>${esc(L('vat'))}</th><th class="r">Net</th><th></th></tr></thead><tbody>${(lines.length ? lines : [{}]).map(row).join('')}</tbody></table>
      <button class="btn btn--sm btn--ghost" type="button" data-add>${icon('plus')}Add a line</button><div class="fwf-balance is-ok" data-tot aria-live="polite"></div>`;
    const tbody = $('tbody', host);
    const bind = (tr) => {
      F.accountPicker($('[name=acc]', tr), { filter: (a) => !a.control });
      $$('input, select', tr).forEach((x) => x.addEventListener('input', calc));
      $$('[name=price], [name=qty]', tr).forEach((x) => x.addEventListener('blur', () => { const v = F.parseMoney(x.value); if (v !== null && x.name === 'price') x.value = Math.abs(v).toFixed(2); calc(); }));
      $('[data-rm]', tr).addEventListener('click', () => { if (tbody.children.length > 1) tr.remove(); else $$('input', tr).forEach((x) => { x.value = x.name === 'qty' ? '1' : ''; x.dataset.code = ''; }); calc(); });
    };
    $$('tr', tbody).forEach(bind);
    $('[data-add]', host).addEventListener('click', () => {
      const t = document.createElement('tbody');
      const prev = tbody.lastElementChild;
      t.innerHTML = row({ account_code: prev ? $('[name=acc]', prev).dataset.code : '', tax_rate: prev ? $('[name=rate]', prev).value : undefined }, tbody.children.length);
      const tr = t.firstElementChild;
      tbody.appendChild(tr);
      bind(tr);
      $('[name=desc]', tr).focus();
    });
    function read() {
      return $$('tr', tbody).map((tr) => {
        const qty = parseFloat($('[name=qty]', tr).value) || 0;
        const price = F.parseMoney($('[name=price]', tr).value);
        const rate = parseFloat($('[name=rate]', tr).value) || 0;
        let x = {};
        try { x = JSON.parse(tr.dataset.x || '{}'); } catch (e) { x = {}; }
        const netC = price === null ? 0 : Math.round((qty * price - (Number(x.discount) || 0)) * 100);
        return { tr, x, description: $('[name=desc]', tr).value.trim(), account_id: +($('[name=acc]', tr).dataset.id || 0) || (F.acct($('[name=acc]', tr).dataset.code) || {}).id || null,
                 qty, unit_price: price, tax_rate: rate, netC, vatC: Math.round(netC * rate / 100) };
      });
    }
    function calc() {
      const ls = read();
      let n = 0, v = 0;
      ls.forEach((l) => { $('[data-net]', l.tr).innerHTML = l.unit_price === null ? '—' : esc(F.group(l.netC / 100)) + (Number(l.x.discount) ? `<div class="fwf-muted" style="font-size:11px">after ${esc(F.group(l.x.discount))} discount</div>` : ''); n += l.netC; v += l.vatC; });
      $('[data-tot]', host).innerHTML = `<span>Net <b>${esc(money(n / 100))}</b></span><span>${esc(L('vat'))} <b>${esc(money(v / 100))}</b></span><span class="fwf-grow"></span><span>Total <b>${esc(money((n + v) / 100))}</b></span>`;
      if (onChange) onChange((n + v) / 100);
    }
    calc();
    return { read: () => read().filter((l) => l.description || l.unit_price !== null).map(({ x, description, account_id, qty, unit_price, tax_rate }) => ({ ...x, description, account_id, qty, unit_price, tax_rate })) };
  }

  async function supplierSelect(sel) {
    const r = await api.get('payables.php', { op: 'supplier_list' });
    return `<option value="">Choose a ${esc(lower('supplier'))}…</option>${r.rows.map((x) => `<option value="${x.id}"${x.id === sel ? ' selected' : ''}>${esc(x.name)}</option>`).join('')}`;
  }

  /** Bill editor: a new bill, or a draft / one from Receipts. */
  async function billEditor(b = null, onSaved) {
    b = b || {};
    let opts;
    try { opts = await supplierSelect(b.supplier_id || +A.param('supplier') || 0); } catch (e) { toastError(e); return; }
    const isEdit = !!b.id;
    let savedId = b.id || 0;
    let lines;
    const dlg = F.formDialog({
      title: isEdit ? 'Edit ' + (b.number || lower('bill')) : 'New ' + lower('bill'), wide: true, submit: 'Save draft',
      extraFoot: `<button class="btn" type="button" data-then="post">${icon('if-post')}Save &amp; post</button>`,
      html: `<div class="fwf-grid3">
          <div class="field fwf-span2"><label class="label" for="bSup">${esc(L('supplier'))}<span class="req">*</span></label><select class="select" id="bSup" name="supplier">${opts}</select>
            <div class="fwf-hint">Not listed? Add the ${esc(lower('supplier'))} in <a href="/crm/#suppliers" target="_blank" rel="noopener">CRM</a>, then reopen this.</div></div>
          <div class="field"><label class="label" for="bNum">Their invoice number<span class="req">*</span></label><input class="input" id="bNum" name="number" maxlength="190" value="${esc(b.number || '')}"></div>
          <div class="field"><label class="label" for="bIss">Invoice date<span class="req">*</span></label><input class="input" id="bIss" name="issued" type="date" value="${esc(b.issued || BOOT.today)}"></div>
          <div class="field"><label class="label" for="bDue">Due</label><input class="input" id="bDue" name="due" type="date" value="${esc(b.due || '')}"></div>
          <div class="field"><label class="label" for="bVat">Their ${esc(L('vat'))} number</label><input class="input" id="bVat" name="vendor_vat" maxlength="12" inputmode="numeric" placeholder="4xxxxxxxxx" value="${esc(b.vendor_vat || '')}"></div>
        </div>
        ${BOOT.locked_through ? `<div class="fwf-hint">${icon('lock', 'i--xs')} The books are locked through ${esc(fmtDate(BOOT.locked_through, 'always'))}.</div>` : ''}
        <div class="fwf-formsec"><h4>Lines<small>Prices excluding ${esc(L('vat'))}</small></h4><div id="bLines"></div></div>
        <div class="field"><label class="label" for="bNotes">Notes</label><textarea class="textarea" id="bNotes" name="notes" rows="2" maxlength="2000">${esc(b.notes || '')}</textarea></div>`,
      onSubmit: (f) => submit(f, ''),
    });
    const f = dlg.form;
    lines = linesEditor(dlg.m, $('#bLines', dlg.m), b.lines || []);
    if (!b.due) f.issued.addEventListener('change', () => { if (!f.due.value && f.issued.value) { const d = F.parseYmd(f.issued.value); d.setDate(d.getDate() + 30); f.due.placeholder = F.ymd(d); } });
    async function submit(form, then) {
      const body = { op: 'bill_save', id: savedId, supplier_id: +form.supplier.value, number: form.number.value.trim(), issued: form.issued.value, due: form.due.value, vendor_vat: form.vendor_vat.value.trim(), notes: form.notes.value.trim(), lines: lines.read(), then };
      if (!body.supplier_id) throw new Error('Choose the ' + lower('supplier') + '.');
      if (!body.lines.length) throw new Error('Add at least one line.');
      let r;
      try { r = await api.post('payables.php', body); }
      catch (e) { if (e.data && e.data.saved_id) { savedId = e.data.saved_id; after(); } throw e; }
      savedId = r.id;
      toast(then === 'post' ? L('bill') + ' posted' : 'Draft saved', { action: { label: 'Open', run: () => openBill(r.id) } });
      if (onSaved) onSaved(r); else after();
    }
    const tb = $('[data-then]', dlg.m);
    tb.addEventListener('click', async () => {
      const err = $('.fwf-formerr', dlg.m);
      err.hidden = true;
      tb.disabled = true;
      try { await submit(f, 'post'); F.closeModal(dlg.m); } catch (e) { err.textContent = e.message; err.hidden = false; } finally { tb.disabled = false; }
    });
  }

  /** Pay a supplier: one payment over one or more of their posted bills. */
  async function payDialog(supplierId = 0, billId = 0, onDone) {
    let sups = [];
    try { sups = (await api.get('payables.php', { op: 'suppliers' })).rows; } catch (e) { toastError(e); return; }
    if (!sups.length) { toast('There are no posted ' + lower('bills') + ' to pay.'); return; }
    if (!sups.some((x) => x.id === supplierId)) supplierId = sups[0].id;
    const key = uid();
    let rows = [], inputs = [];
    const dlg = F.formDialog({
      title: 'Pay a ' + lower('supplier'), wide: true, submit: 'Record payment',
      html: `<div class="fwf-grid3">
          <div class="field fwf-span2"><label class="label" for="pySup">${esc(L('supplier'))}<span class="req">*</span></label><select class="select" id="pySup" name="supplier">${sups.map((x) => `<option value="${x.id}"${x.id === supplierId ? ' selected' : ''}>${esc(x.name)} · ${esc(money(x.balance))}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="pyDate">Date paid<span class="req">*</span></label><input class="input" id="pyDate" name="date" type="date" max="${esc(BOOT.today)}" value="${esc(BOOT.today)}"></div>
          <div class="field"><label class="label" for="pyAmt">Amount paid</label><input class="input fwf-amount" id="pyAmt" name="amount" inputmode="decimal" placeholder="0.00"><div class="fwf-hint">Spread over the oldest ${esc(lower('bills'))} first.</div></div>
          <div class="field"><label class="label" for="pyBank">From</label><select class="select" id="pyBank" name="bank">${BOOT.banks.length ? F.bankOptions(BOOT.banks[0].id) : '<option value="">The default bank account</option>'}</select></div>
          <div class="field"><label class="label" for="pyMeth">Method</label><select class="select" id="pyMeth" name="method">${methodOptions('eft')}</select></div>
          <div class="field fwf-span-all"><label class="label" for="pyRef">Reference</label><input class="input" id="pyRef" name="reference" maxlength="190" placeholder="As on the bank statement"></div>
        </div>
        <div class="fwf-formsec"><h4>Pay these ${esc(lower('bills'))}<small id="pySum"></small></h4><div id="pyRows"></div></div>`,
      onSubmit: async (f) => {
        const allocations = rows.map((r, n) => ({ bill_id: r.id, amount: F.parseMoney(inputs[n].value) || 0 })).filter((a) => a.amount > 0);
        if (!allocations.length) throw new Error('Enter how much is paid against at least one ' + lower('bill') + '.');
        const bad = rows.find((r, n) => (F.parseMoney(inputs[n].value) || 0) > r.balance + 0.004);
        if (bad) throw new Error(`That is more than the ${money(bad.balance)} still owing on ${bad.number}.`);
        const r = await api.post('payables.php', { op: 'pay', supplier_id: +f.supplier.value, date: f.date.value, method: f.method.value, reference: f.reference.value.trim(), bank_account_id: +f.bank.value || null, allocations, key });
        toast(r.duplicate ? 'This payment was already recorded' : 'Payment of ' + money(allocations.reduce((s, a) => s + a.amount, 0)) + ' recorded');
        if (onDone) onDone(); else after();
      },
    });
    const f = dlg.form;
    const sum = () => { const t = inputs.reduce((s, x) => s + (F.parseMoney(x.value) || 0), 0); $('#pySum', dlg.m).textContent = `Paying ${money(t)} of ${money(rows.reduce((s, r) => s + r.balance, 0))}`; };
    const load = async () => {
      const host = $('#pyRows', dlg.m);
      host.innerHTML = F.skel(2, 36);
      try { rows = (await api.get('payables.php', { op: 'open', supplier: f.supplier.value })).rows; } catch (e) { host.innerHTML = F.errorBox(e); return; }
      host.innerHTML = rows.length ? `<table class="fwf-table"><thead><tr><th>${esc(L('bill'))}</th><th class="hide-narrow">Dated</th><th>Due</th><th class="r">Owing</th><th class="r" style="width:150px">Pay</th></tr></thead><tbody>${rows.map((r) => `<tr>
        <td><b>${esc(r.number)}</b></td><td class="hide-narrow">${esc(fmtDate(r.issued, 'always'))}</td><td>${r.due ? esc(fmtDate(r.due, 'always')) : '—'}</td><td class="r">${esc(money(r.balance))}</td>
        <td class="r"><input class="input fwf-amount" inputmode="decimal" placeholder="0.00" aria-label="Pay against ${esc(r.number)}" data-max="${r.balance}"></td></tr>`).join('')}</tbody></table>
        <button class="fwf-mini" type="button" id="pyAll" style="margin-top:6px">${icon('check')}Pay all in full</button>` : '<p class="fwf-muted">Nothing open for this supplier.</p>';
      inputs = $$('input[data-max]', host);
      inputs.forEach((x) => { x.addEventListener('input', sum); x.addEventListener('blur', () => { const v = F.parseMoney(x.value); x.value = v ? Math.abs(v).toFixed(2) : ''; sum(); }); });
      const all = $('#pyAll', host);
      if (all) all.addEventListener('click', () => { inputs.forEach((x, n) => { x.value = rows[n].balance.toFixed(2); }); f.amount.value = rows.reduce((s, r) => s + r.balance, 0).toFixed(2); sum(); });
      if (billId) { const n = rows.findIndex((r) => r.id === billId); if (n >= 0) { inputs[n].value = rows[n].balance.toFixed(2); f.amount.value = rows[n].balance.toFixed(2); } billId = 0; }
      sum();
    };
    f.supplier.addEventListener('change', () => { f.amount.value = ''; load(); });
    f.amount.addEventListener('input', () => { spread(rows, inputs, F.parseMoney(f.amount.value) || 0); sum(); });
    load();
  }

  // -------------------------------------------------------- supplier credits
  const REASONS = [['return', 'Goods returned'], ['discount', 'Discount'], ['correction', 'Correcting a bill'], ['damaged', 'Damaged goods'], ['cancellation', 'Order cancelled'], ['other', 'Other']];
  async function openCredit(id) {
    const s = F.sheet({ title: 'Supplier credit', sub: 'Loading…', html: F.skel(4, 46), wide: true, onClose: () => { if (A.param('credit')) A.setParams({ credit: '' }); } });
    A.setParams({ credit: id, id: '' });
    try { paintCredit(s, await api.get('payables.php', { op: 'credit', id })); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); }
  }
  function paintCredit(s, d) {
    const c = d.credit;
    const refresh = async (msg) => { if (msg) toast(msg); try { paintCredit(s, await api.get('payables.php', { op: 'credit', id: c.id })); } catch (e) { toastError(e); } after(); };
    $('h2', s).textContent = `Credit note ${c.number}`;
    $('.fwf-eyebrow', s).textContent = `${c.supplier} · ${fmtDate(c.date, 'always')}`;
    $('.fwf-sheet__acts', s).innerHTML = `${pill(c.status)}
      ${can('write') && !c.posted ? `<button class="btn btn--sm" type="button" data-a="edit">${icon('pen')}Edit</button><button class="btn btn--sm btn--primary" type="button" data-a="post">${icon('if-post')}Post</button>` : ''}
      ${can('write') && c.posted && c.remaining > 0 && d.open_bills.length ? `<button class="btn btn--sm btn--primary" type="button" data-a="apply">${icon('if-match')}Apply to ${esc(lower('bills'))}</button>` : ''}
      ${can('write') && !c.posted ? `<button class="icon-btn" type="button" data-a="delete" aria-label="Delete draft">${icon('trash')}</button>` : ''}`;
    $('.fwf-sheet__body', s).innerHTML = `
      ${!c.posted ? `<div class="fwf-banner fwf-banner--warn">${icon('info')}<span>Not in the books yet. Posting takes ${esc(money(c.total))} off what you owe ${esc(c.supplier)} and off the expense and input ${esc(L('vat'))}.</span></div>`
        : c.remaining > 0 ? `<div class="fwf-banner fwf-banner--info">${icon('if-credit')}<span><b>${esc(money(c.remaining))}</b> of this credit is still to apply to ${esc(lower('bills'))}.</span></div>` : ''}
      <dl class="fwf-kv"><dt>${esc(L('supplier'))}</dt><dd><a href="#payables?supplier=${c.supplier_id}">${esc(c.supplier)}</a></dd><dt>Date</dt><dd>${esc(fmtDate(c.date, 'always'))}</dd>
        <dt>Reason</dt><dd>${esc((REASONS.find((r) => r[0] === c.reason) || [, c.reason || '—'])[1])}</dd><dt>Total</dt><dd><b>${esc(money(c.total))}</b></dd>
        ${c.journal_id ? `<dt>In the books</dt><dd><a href="#journals?id=${c.journal_id}" data-j="${c.journal_id}">Journal #${c.journal_id}</a></dd>` : ''}${c.notes ? `<dt>Notes</dt><dd>${esc(c.notes)}</dd>` : ''}</dl>
      <div class="fwf-sheet__sec"><h4>${icon('list')}Lines</h4><table class="fwf-table"><thead><tr><th>Description</th><th>Account</th><th class="r">${esc(L('vat'))}</th><th class="r">Net</th></tr></thead>
        <tbody>${d.lines.map((l) => `<tr><td>${esc(l.description)}</td><td>${l.account_code ? `<span class="fwf-code">${esc(l.account_code)}</span> ${esc(l.account_name)}` : '<span class="fwf-muted">default expense</span>'}</td><td class="r">${esc(F.group(l.vat))}</td><td class="r">${esc(F.group(l.net))}</td></tr>`).join('')}</tbody></table></div>
      ${d.allocations.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-match')}Applied to</h4><table class="fwf-table"><tbody>${d.allocations.map((a) => `<tr class="fwf-row" data-bill="${a.bill_id}"><td><b>${esc(a.number)}</b></td><td class="r">${esc(money(a.amount))}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${A.filesBlock('vendor_credit', c.id, d.files, can('write'))}`;
    A.bindFiles(s, () => refresh());
    $$('[data-j]', s).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
    $$('[data-bill]', s).forEach((a) => a.addEventListener('click', () => openBill(+a.dataset.bill)));
    $$('[data-a]', s).forEach((b) => b.addEventListener('click', async () => {
      const a = b.dataset.a;
      if (a === 'edit') creditEditor({ ...c, supplier_id: c.supplier_id, lines: d.lines }, () => refresh());
      if (a === 'apply') applyCreditDialog(c, d.open_bills, () => refresh('Credit applied'));
      if (a === 'post') {
        if (!(await dialog({ title: `Post credit note ${c.number}?`, body: `It is posted once, in full (${esc(money(c.total))}). You can then apply it to one or more ${esc(lower('bills'))}.`, confirm: 'Post' }))) return;
        try { await api.post('payables.php', { op: 'credit_post', id: c.id }); refresh('Credit note posted'); } catch (e) { toastError(e); }
      }
      if (a === 'delete') {
        if (!(await dialog({ title: 'Delete this draft credit note?', body: 'It was never posted.', confirm: 'Delete', danger: true }))) return;
        try { await api.post('payables.php', { op: 'credit_delete', id: c.id }); toast('Draft deleted'); F.closeSheet(); after(); } catch (e) { toastError(e); }
      }
    }));
  }
  function applyCreditDialog(c, bills, done) {
    F.formDialog({
      title: `Apply ${c.number}`, submit: 'Apply credit',
      html: `<p class="fwm-dialog__lead">${esc(money(c.remaining))} is left on this credit. Nothing new is posted: the credit was booked in full when it was posted.</p>
        <table class="fwf-table"><thead><tr><th>${esc(L('bill'))}</th><th class="r">Owing</th><th class="r" style="width:150px">Apply</th></tr></thead><tbody>${bills.map((b) => `<tr><td><b>${esc(b.number)}</b> <small class="fwf-muted">${esc(fmtDate(b.issued, 'always'))}</small></td><td class="r">${esc(money(b.balance))}</td>
          <td class="r"><input class="input fwf-amount" name="b${b.id}" inputmode="decimal" placeholder="0.00"></td></tr>`).join('')}</tbody></table>`,
      onOpen: (f) => { spread(bills, bills.map((b) => f['b' + b.id]), c.remaining); },
      onSubmit: async (f) => {
        const allocations = bills.map((b) => ({ bill_id: b.id, amount: F.parseMoney(f['b' + b.id].value) || 0 })).filter((x) => x.amount > 0);
        if (!allocations.length) throw new Error('Enter how much goes against each ' + lower('bill') + '.');
        await api.post('payables.php', { op: 'credit_allocate', id: c.id, allocations });
        done();
      },
    });
  }
  async function creditEditor(c = null, onSaved) {
    c = c || {};
    let opts;
    try { opts = await supplierSelect(c.supplier_id || +A.param('supplier') || 0); } catch (e) { toastError(e); return; }
    let savedId = c.id || 0;
    const dlg = F.formDialog({
      title: c.id ? 'Edit credit note ' + c.number : 'Supplier credit note', wide: true, submit: 'Save draft',
      extraFoot: `<button class="btn" type="button" data-then="post">${icon('if-post')}Save &amp; post</button>`,
      html: `<div class="fwf-grid3">
          <div class="field fwf-span2"><label class="label" for="cSup">${esc(L('supplier'))}<span class="req">*</span></label><select class="select" id="cSup" name="supplier">${opts}</select></div>
          <div class="field"><label class="label" for="cNum">Their credit note number<span class="req">*</span></label><input class="input" id="cNum" name="number" maxlength="50" value="${esc(c.number || '')}"></div>
          <div class="field"><label class="label" for="cDate">Date<span class="req">*</span></label><input class="input" id="cDate" name="date" type="date" value="${esc(c.date || BOOT.today)}"></div>
          <div class="field fwf-span2"><label class="label" for="cWhy">Reason</label><select class="select" id="cWhy" name="reason">${REASONS.map(([v, l]) => `<option value="${v}"${(c.reason || 'return') === v ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
        </div>
        <div class="fwf-formsec"><h4>Lines<small>What is credited, excluding ${esc(L('vat'))}</small></h4><div id="cLines"></div></div>
        <div class="field"><label class="label" for="cNotes">Notes</label><textarea class="textarea" id="cNotes" name="notes" rows="2" maxlength="2000">${esc(c.notes || '')}</textarea></div>`,
      onSubmit: (f) => submit(f, ''),
    });
    const lines = linesEditor(dlg.m, $('#cLines', dlg.m), c.lines || []);
    async function submit(f, then) {
      const body = { op: 'credit_save', id: savedId, supplier_id: +f.supplier.value, number: f.number.value.trim(), date: f.date.value, reason: f.reason.value, notes: f.notes.value.trim(), lines: lines.read(), then };
      if (!body.supplier_id) throw new Error('Choose the ' + lower('supplier') + '.');
      let r;
      try { r = await api.post('payables.php', body); }
      catch (e) { if (e.data && e.data.saved_id) { savedId = e.data.saved_id; after(); } throw e; }
      savedId = r.id;
      toast(then === 'post' ? 'Credit note posted' : 'Draft saved', { action: { label: 'Open', run: () => openCredit(r.id) } });
      if (onSaved) onSaved(r); else if (A.state.route === 'payables') A.go('payables', { view: 'credits', credit: r.id }); else after();
    }
    const tb = $('[data-then]', dlg.m);
    tb.addEventListener('click', async () => {
      const err = $('.fwf-formerr', dlg.m);
      err.hidden = true;
      tb.disabled = true;
      try { await submit(dlg.form, 'post'); F.closeModal(dlg.m); } catch (e) { err.textContent = e.message; err.hidden = false; } finally { tb.disabled = false; }
    });
  }

  // ------------------------------------------------------- three-way match
  async function apMatch(ctx) {
    const sup = +ctx.param('supplier') || 0;
    let opts;
    try { opts = await supplierSelect(sup); } catch (e) { A.el.view.innerHTML = F.errorBox(e); return; }
    if (!ctx.isCurrent()) return;
    const top = `<div class="fwf-box"><div class="fwf-box__body" style="padding-top:14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center">
      <select class="select" id="mSup" style="max-width:320px" aria-label="${esc(L('supplier'))}">${opts}</select>
      <span class="fwf-hint" style="margin:0">Link each ${esc(lower('bill'))} line to the purchase order and the delivery it is for. Only what is left on a line can be matched.</span></div></div>`;
    if (!sup) {
      A.el.view.innerHTML = `<div class="fwf-page">${top}${F.emptyState('if-match', 'Choose a ' + lower('supplier'), 'Three-way matching checks that you pay only for what was ordered and delivered.')}</div>`;
    } else {
      A.el.view.innerHTML = `<div class="fwf-page">${top}${F.skel(4, 40)}</div>`;
      const d = await api.get('payables.php', { op: 'match', supplier: sup });
      if (!ctx.isCurrent()) return;
      const open = d.bill.filter((l) => l.left > 0.0005);
      const poOpts = d.po.filter((l) => l.left > 0.0005).map((l) => `<option value="${l.id}">${esc(l.doc)} · ${esc(l.description.slice(0, 40))} (${esc(l.left)} left)</option>`).join('');
      const grnOpts = d.grn.filter((l) => l.left > 0.0005).map((l) => `<option value="${l.id}">${esc(l.doc)} · ${esc((l.description || '').slice(0, 40))} (${esc(l.left)} left)</option>`).join('');
      A.el.view.innerHTML = `<div class="fwf-page">${top}<div class="fwf-box">${open.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>${esc(L('bill'))} line</th><th>Purchase order line</th><th>Delivery line</th><th class="r" style="width:110px">Qty</th><th></th></tr></thead>
        <tbody>${open.map((l) => `<tr data-line="${l.id}"><td><div class="fwf-cell2"><b>${esc(l.doc)} · ${esc(l.description)}</b><small>${esc(l.left)} of ${esc(l.qty)} left · ${esc(money(l.price))} each</small></div></td>
          <td><select class="select" name="po" aria-label="Purchase order line"><option value="">—</option>${poOpts}</select></td><td><select class="select" name="grn" aria-label="Delivery line"><option value="">—</option>${grnOpts}</select></td>
          <td class="r"><input class="input fwf-amount" name="qty" inputmode="decimal" value="${esc(l.left)}" aria-label="Quantity to match"></td>
          <td class="r">${can('write') ? `<button class="fwf-mini" type="button" data-match>${icon('if-match')}Match</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
        : F.emptyState('if-approve', 'Everything is matched', `No ${esc(lower('bill'))} lines of this ${esc(lower('supplier'))} are left to match.`, '', true)}
        ${!d.po.length && !d.grn.length ? `<div class="fwf-banner fwf-banner--info">${icon('info')}<span>This ${esc(lower('supplier'))} has no purchase orders or deliveries in Procurement.</span></div>` : ''}</div></div>`;
      $$('[data-match]', A.el.view).forEach((b) => b.addEventListener('click', async () => {
        const tr = b.closest('tr');
        const m = { bill_line_id: +tr.dataset.line, po_line_id: +$('[name=po]', tr).value || null, grn_line_id: +$('[name=grn]', tr).value || null, qty: parseFloat($('[name=qty]', tr).value) || 0 };
        if (!m.po_line_id && !m.grn_line_id) { toast('Choose the order line, the delivery line or both.', { type: 'error' }); return; }
        try { await api.post('payables.php', { op: 'match_apply', supplier_id: sup, matches: [m] }); toast('Matched'); A.render(); } catch (e) { toastError(e); }
      }));
    }
    const s = $('#mSup', A.el.view);
    if (s) s.addEventListener('change', () => A.go('payables', { view: 'match', supplier: s.value }));
  }

  // ------------------------------------------------------------ register
  F.creators.bill = () => billEditor();
  F.creators.supplier_payment = () => payDialog(A.state.route === 'payables' ? +A.param('supplier') || 0 : 0);
  F.creators.vendor_credit = () => creditEditor();
  F.creators.customer_payment = () => receiveDialog(A.state.route === 'receivables' ? +A.param('customer') || 0 : 0);
  F.openBill = openBill;
  F.openInvoice = openInvoice;
  A.register('receivables', { render: renderReceivables, newKind: () => 'customer_payment' });
  A.register('payables', { render: renderPayables, newKind: () => (A.param('view') === 'credits' ? 'vendor_credit' : 'bill') });
})();
