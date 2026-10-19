// /finances/js/fin-reports.js — reports (one renderer for every report, with
// print and CSV from the same rows) and closing the books (period locks, the
// year-end close, the health check).
(function () {
  'use strict';
  const F = window.FIN;
  const A = F.app;
  const { $, $$, esc, icon, api, toast, toastError, dialog, BOOT, L, can, money, fmtDate, relTime } = F;
  const after = () => { A.refresh(); F.refreshBoot().catch(() => {}); };
  const PDF = { ar_aging: (p) => `/export/pdf/ar_aging.php?date=${p.as_of}`, ap_aging: (p) => `/export/pdf/ap_aging.php?date=${p.as_of}`, vat_summary: (p) => `/export/pdf/vat_summary.php?start_date=${p.from}&end_date=${p.to}` };
  const ICON = { statements: 'if-report', parties: 'if-statement', tax: 'if-vat', assets: 'if-asset', compliance: 'shield' };

  // ============================================================= reports
  let catalog = null;
  async function renderReports(ctx) {
    const p = ctx.param;
    if (!catalog) catalog = (await api.get('reports.php', { op: 'catalog' })).groups;
    if (!ctx.isCurrent()) return;
    const key = p('r');
    const item = catalog.flatMap((g) => g.items).find((i) => i.key === key);
    if (!item) return renderCatalog();
    return renderReport(ctx, item);
  }

  function renderCatalog() {
    A.head(BOOT.company, L('reports'), `<a class="btn btn--sm btn--ghost" href="#ledger">${icon('if-ledger')}<span>Account detail</span></a>`);
    const recent = (BOOT.prefs.recent_reports || []).map((k) => catalog.flatMap((g) => g.items).find((i) => i.key === k)).filter(Boolean);
    A.el.view.innerHTML = `<div class="fwf-page">
      ${recent.length ? `<div class="fwf-chips">${recent.map((i) => `<a class="fwf-chip" href="#reports?r=${esc(i.key)}">${icon('history')}${esc(i.label)}</a>`).join('')}</div>` : ''}
      ${catalog.map((g) => `<section class="fwf-repgroup"><h3>${icon(ICON[g.key] || 'if-report')}${esc(g.label)}</h3><div class="fwf-reports">${g.items.map((i) => `<a class="fwf-reportcard" href="#reports?r=${esc(i.key)}"><b>${esc(i.label)}</b><span>${esc(i.about)}</span></a>`).join('')}</div></section>`).join('')}
      <section class="fwf-repgroup"><h3>${icon('download')}PDF exports</h3><div class="fwf-reports">
        <a class="fwf-reportcard" href="/export/pdf/payroll_summary.php"><b>Payroll summary</b><span>PDF for the payroll year.</span></a>
        <a class="fwf-reportcard" href="/export/pdf/project_pl.php"><b>Project profit and loss</b><span>Income and costs tagged to projects.</span></a>
      </div></section></div>`;
  }

  async function renderReport(ctx, item) {
    const p = ctx.param;
    const today = BOOT.today;
    const [dFrom, dTo] = A.period(item.params === 'range' ? 'fy' : 'month');
    const params = { key: item.key, as_of: p('as_of') || today, from: p('from') || dFrom, to: p('to') || dTo, compare: p('compare'), fy: p('fy'), year: p('year') || today.slice(0, 4) };
    A.head(L('reports'), item.label, `<a class="btn btn--sm btn--ghost" href="#reports">${icon('arrow-left')}<span>All reports</span></a>
      ${A.exportBtn(api.url('reports.php', { op: 'run', ...params, format: 'csv' }), 'CSV')}
      ${PDF[item.key] ? `<a class="btn btn--sm btn--ghost" href="${esc(PDF[item.key](params))}" target="_blank" rel="noopener">${icon('file-text')}<span>PDF</span></a>` : ''}
      <button class="btn btn--sm" type="button" id="rpPrint">${icon('print')}<span>Print</span></button>`);
    const tools = A.tools(`${item.params === 'date' ? `<label class="fwf-qbox" style="max-width:200px">${icon('calendar')}<input type="date" id="rpAsOf" value="${esc(params.as_of)}" aria-label="As at"></label>` : ''}
      ${item.params === 'range' ? A.periodBtn(params.from, params.to) : ''}
      ${item.key === 'income_statement' ? `<select class="select" id="rpCmp" style="max-width:220px" aria-label="Compare with"><option value="">No comparison</option><option value="prior_period"${params.compare === 'prior_period' ? ' selected' : ''}>Against the period before</option><option value="prior_year"${params.compare === 'prior_year' ? ' selected' : ''}>Against the same period last year</option></select>` : ''}
      ${item.params === 'year' ? `<input class="input" id="rpYear" type="number" min="2000" max="2100" value="${esc(params.year)}" style="max-width:110px" aria-label="Year">` : ''}
      <span class="fwf-grow"></span>${A.saveViewBtn()}`);
    A.bindPeriod(tools, params.from, params.to);
    A.bindSaveView(tools);
    const ch = (id, k) => { const x = $('#' + id, tools); if (x) x.addEventListener('change', () => A.go('reports', A.withParams({ [k]: x.value }))); };
    ch('rpAsOf', 'as_of'); ch('rpCmp', 'compare'); ch('rpYear', 'year');
    $('#rpPrint').addEventListener('click', () => window.print());
    A.el.view.innerHTML = F.skel(10, 30);
    const d = (await api.get('reports.php', { op: 'run', ...params })).report;
    if (!ctx.isCurrent()) return;
    if ((BOOT.prefs.recent_reports || [])[0] !== item.key) F.savePrefs({ op: 'recent_report', key: item.key }).catch(() => {});
    A.el.view.innerHTML = `<div class="fwf-page">${reportHtml(d)}</div>`;
    $$('a[data-j]', A.el.view).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
  }

  function cell(c, v) {
    if (v === null || v === undefined || v === '') return '';
    if (c.pct) return esc(F.group(v, 1)) + '%';
    if (c.money) return Math.abs(v) < 0.005 ? '–' : esc(F.group(v));
    return esc(v);
  }
  function reportHtml(d) {
    const cols = d.columns;
    const sum = d.summary && d.summary.length ? `<div class="fwf-stats fwf-noprint-break">${d.summary.map((s) => `<div class="fwf-stat"><span>${esc(s.label)}</span><b>${s.value === null ? '—' : s.pct ? esc(F.group(s.value, 1)) + '%' : s.plain ? esc(s.value) : esc(money(s.value))}</b></div>`).join('')}</div>` : '';
    const checks = d.checks && d.checks.length ? `<div class="fwf-rchecks">${d.checks.map((c) => `<span class="fwf-check ${c.ok ? 'is-ok' : 'is-bad'}">${icon(c.ok ? 'check' : 'alert')}<b>${esc(c.label)}</b> ${esc(c.detail)}</span>`).join('')}</div>` : '';
    return `<div class="fwf-report">
      <div class="fwf-report__head"><div><div class="fwf-eyebrow">${esc(d.company)}</div><h2>${esc(d.title)}</h2><p class="fwf-muted">${esc(d.subtitle)} · prepared ${esc(d.generated)}</p></div></div>
      ${sum}${checks}
      <div class="fwf-table-wrap"><table class="fwf-table fwf-rep"><thead><tr><th></th>${cols.map((c) => `<th class="${c.align === 'r' ? 'r' : ''}">${esc(c.label)}</th>`).join('')}</tr></thead>
      <tbody>${d.rows.map((r) => {
        const cls = [r.kind === 'group' ? 'is-group' : '', r.kind === 'total' ? 'is-total' : '', r.kind === 'grand' ? 'is-grand' : '', r.muted ? 'is-muted' : '', r.flag ? 'is-flag' : ''].join(' ');
        const label = r.link ? `<a href="${esc(r.link)}">${esc(r.label)}</a>` : esc(r.label);
        return r.kind === 'group' ? `<tr class="${cls}"><td colspan="${cols.length + 1}">${esc(r.label)}</td></tr>`
          : `<tr class="${cls}"><td class="${r.level ? 'indent-' + r.level : ''}">${label}</td>${cols.map((c) => `<td class="${c.align === 'r' ? 'r' : ''}${c.money && (r.cells[c.key] || 0) < 0 ? ' fwf-neg' : ''}">${cell(c, r.cells[c.key])}</td>`).join('')}</tr>`;
      }).join('')}</tbody></table></div>
      ${d.rows.length ? '' : '<p class="fwf-muted">Nothing to show for these dates.</p>'}
      ${(d.notes || []).map((n) => `<p class="fwf-hint">${icon('info', 'i--xs')} ${esc(n)}</p>`).join('')}</div>`;
  }

  // ============================================================== close
  async function renderClose(ctx) {
    const p = ctx.param;
    const view = ['locks', 'year', 'health'].includes(p('view')) ? p('view') : 'locks';
    A.head(BOOT.company, L('close') === 'close' ? 'Close & year end' : L('close'), can('write') && view === 'locks' ? `<button class="btn btn--primary" type="button" id="lkNew">${icon('lock')}<span>Lock a period</span></button>` : '');
    const tools = A.tools(`${A.viewSeg([['locks', 'Period locks'], ['year', 'Year end'], ['health', 'Health check']], view)}`);
    A.bindViewSeg(tools, 'close');
    A.el.view.innerHTML = F.skel(5, 50);
    if (view === 'year') return yearEnd(ctx);
    if (view === 'health') return health(ctx);
    const d = await api.get('close.php', { op: 'locks' });
    if (!ctx.isCurrent()) return;
    const nb = $('#lkNew');
    if (nb) nb.addEventListener('click', () => lockDialog(d));
    const active = d.rows.filter((r) => r.active), old = d.rows.filter((r) => !r.active);
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-banner fwf-banner--info">${icon('lock')}<span>${d.locked_through ? `The books are locked through <b>${esc(fmtDate(d.locked_through, 'always'))}</b>: nothing can be posted, changed or reversed on or before that day.` : 'No period is locked yet. Lock each month once it is reconciled and reviewed.'}</span></div>
      ${active.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Locked through</th><th>Why</th><th class="hide-narrow">By</th><th></th></tr></thead><tbody>${active.map((r) => `<tr>
        <td><b>${esc(fmtDate(r.date, 'always'))}</b></td><td>${esc(r.reason)} ${r.system ? F.tonePill('gold', 'system') : ''}</td><td class="hide-narrow">${esc(r.by || '—')} · ${esc(relTime(r.at))}</td>
        <td class="r">${can('admin') && !r.system ? `<button class="fwf-mini" type="button" data-unlock="${r.id}">${icon('if-unlock')}Remove</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${old.length ? `<details class="fwf-box"><summary class="fwf-box__body" style="padding:12px 18px;cursor:pointer">Removed locks (${old.length})</summary><div class="fwf-trail" style="padding:0 18px 14px">${old.map((r) => `<div><b>${esc(fmtDate(r.date, 'always'))}</b> · ${esc(r.reason)}<small>removed by ${esc(r.removed_by || '—')} ${esc(relTime(r.removed_at))}</small></div>`).join('')}</div></details>` : ''}</div>`;
    $$('[data-unlock]', A.el.view).forEach((b) => b.addEventListener('click', () => F.formDialog({
      title: 'Remove this lock?', submit: 'Remove lock', danger: true,
      html: `<p class="fwm-dialog__lead">Postings into the period become possible again (unless a later lock still covers it). The removal is kept on the audit trail.</p>
        <div class="field"><label class="label" for="ulWhy">Why<span class="req">*</span></label><input class="input" id="ulWhy" name="why" maxlength="255" placeholder="Late supplier invoice for September"></div>`,
      onSubmit: async (f) => { await api.post('close.php', { op: 'unlock', id: +b.dataset.unlock, why: f.why.value.trim() }); toast('Lock removed'); after(); },
    })));
    if (p('new') && can('write')) { A.setParams({ new: '' }); lockDialog(d); }
  }

  function lockDialog(d) {
    F.formDialog({
      title: 'Lock a period', submit: 'Lock',
      html: `<p class="fwm-dialog__lead">Everything dated on or before this day is closed for posting. Do it once the month is reconciled.</p>
        <div class="fwf-grid2"><div class="field"><label class="label" for="lkD">Lock through<span class="req">*</span></label><input class="input" id="lkD" name="date" type="date" value="${esc(d.suggest)}"></div>
        <div class="field"><label class="label" for="lkR">Why</label><input class="input" id="lkR" name="reason" maxlength="200" placeholder="September reviewed"></div></div>`,
      onSubmit: async (f) => { await api.post('close.php', { op: 'lock', date: f.date.value, reason: f.reason.value.trim() }); toast('Period locked'); after(); },
    });
  }

  async function yearEnd(ctx) {
    const d = await api.get('close.php', { op: 'years' });
    if (!ctx.isCurrent()) return;
    const years = d.years.slice().reverse();
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-banner fwf-banner--info">${icon('info')}<span>Closing a year moves its income and expenses to retained earnings in one journal on the last day of the year and locks the year. Reports for the year stay as they were.</span></div>
      <div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Financial year</th><th class="r">Result</th><th>Status</th><th></th></tr></thead><tbody>${years.map((y) => `<tr>
        <td><b>${esc(fmtDate(y.start, 'always'))} – ${esc(fmtDate(y.end, 'always'))}</b></td><td class="r ${y.result < 0 ? 'fwf-neg' : ''}">${esc(money(y.result))}</td>
        <td>${y.closed ? F.pill('closed', 'Closed') + ` <a href="#journals?id=${y.journal_id}" data-j="${y.journal_id}">#${y.journal_id}</a>` : y.ended ? F.tonePill('warning', 'Ended · not closed') : F.pill('open', 'In progress')}</td>
        <td class="r">${can('admin') && y.ended && !y.closed ? `<button class="fwf-mini" type="button" data-close="${y.start}">${icon('if-close')}Close…</button>` : ''}${can('admin') && y.closed ? `<button class="fwf-mini" type="button" data-reopen="${y.start}">${icon('undo')}Reopen…</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
      ${!can('admin') ? '<p class="fwf-hint">Only an administrator closes or reopens a year.</p>' : ''}</div>`;
    $$('a[data-j]', A.el.view).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
    $$('[data-close]', A.el.view).forEach((b) => b.addEventListener('click', () => closeDialog(b.dataset.close)));
    $$('[data-reopen]', A.el.view).forEach((b) => b.addEventListener('click', () => F.formDialog({
      title: 'Reopen this year?', submit: 'Reopen', danger: true,
      html: `<p class="fwm-dialog__lead">The closing journal is reversed on the same day and the year's lock is lifted, so corrections can be posted. Close it again afterwards.</p>
        <div class="field"><label class="label" for="roWhy">Why<span class="req">*</span></label><input class="input" id="roWhy" name="why" maxlength="255" placeholder="Auditor's adjustments"></div>`,
      onSubmit: async (f) => { await api.post('close.php', { op: 'reopen_year', start: b.dataset.reopen, why: f.why.value.trim() }); toast('Year reopened'); after(); },
    })));
  }

  async function closeDialog(start) {
    let pv;
    try { pv = await api.get('close.php', { op: 'preview', start }); } catch (e) { toastError(e); return; }
    const dlg = F.formDialog({
      title: `Close ${fmtDate(pv.start, 'always')} – ${fmtDate(pv.end, 'always')}`, submit: 'Close the year', wide: true,
      html: `${pv.blockers.map((b) => `<div class="fwf-banner fwf-banner--danger">${icon('alert')}<span>${esc(b)}</span></div>`).join('')}
        ${pv.drafts ? `<div class="fwf-banner fwf-banner--warn">${icon('info')}<span>${pv.drafts} journal(s) dated in this year are not posted yet. Post or delete them first if they belong in the year.</span></div>` : ''}
        <p class="fwm-dialog__lead">${pv.net >= 0 ? 'Profit' : 'Loss'} of <b>${esc(money(Math.abs(pv.net)))}</b> goes to ${esc(pv.retained.code)} · ${esc(pv.retained.name)}.</p>
        <table class="fwf-table"><thead><tr><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead><tbody>${pv.lines.map((l) => `<tr><td><span class="fwf-code">${esc(l.code)}</span> ${esc(l.name)}</td><td class="r">${esc(F.dc(l.debit))}</td><td class="r">${esc(F.dc(l.credit))}</td></tr>`).join('')}</tbody></table>`,
      onSubmit: async () => {
        if (!(await dialog({ title: 'Close the year now?', body: 'The year is locked once it is closed. An administrator can reopen it later.', confirm: 'Close the year' }))) return false;
        await api.post('close.php', { op: 'close_year', start: pv.start });
        toast('Year closed');
        after();
      },
    });
    if (pv.blockers.length) $('[data-submit]', dlg.m).disabled = true;
  }

  async function health(ctx) {
    const d = await api.get('close.php', { op: 'health' });
    if (!ctx.isCurrent()) return;
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-banner ${d.ok ? 'fwf-banner--ok' : 'fwf-banner--warn'}">${icon(d.ok ? 'check' : 'if-health')}<span>${d.ok ? 'Everything checks out.' : `${d.checks.filter((c) => !c.ok).length} thing(s) to look at.`}</span></div>
      <div class="fwf-attn">${d.checks.map((c) => `<div class="fwf-attn__item" data-tone="${esc(c.ok ? 'ok' : c.tone === 'danger' ? 'danger' : 'warn')}"><span class="fwf-type">${icon(c.ok ? 'check' : 'alert')}</span>
        <span class="fwf-attn__text"><b>${esc(c.label)}</b><small>${c.ok ? 'OK' : esc((c.count > 1 ? c.count + ' · ' : '') + c.fix)}</small></span>
        <span class="fwf-attn__acts">${!c.ok && c.href ? `<a class="fwf-mini" href="${esc(c.href)}">Look</a>` : ''}</span></div>`).join('')}</div></div>`;
  }

  F.creators.period_lock = () => { location.hash = '#close?new=1'; };
  A.register('reports', { render: renderReports, newKind: () => 'journal' });
  A.register('close', { render: renderClose, newKind: () => (can('write') ? 'journal' : null) });
})();
