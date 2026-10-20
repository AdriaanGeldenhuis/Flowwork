// /payroll/js/reports.js — EMP201, EMP501, pay summary and cost by project,
// each with its own filters and a spreadsheet download.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toastError, money, fmtDay, longDay } = FWP;
  const B = FWP.boot;
  const app = $('#repApp');
  const params = new URLSearchParams(location.search);
  const today = new Date().toISOString().slice(0, 10);
  const st = {
    rep: params.get('report') || 'emp201', month: B.month, year: B.currentYear, kind: (new Date().getMonth() + 1) >= 9 || (new Date().getMonth() + 1) <= 2 ? 'interim' : 'annual',
    from: B.currentYear, to: today,
  };
  const csv = (q) => '/payroll/api/reports.php?' + new URLSearchParams(Object.assign({ format: 'csv' }, q)).toString();
  const card = (iconName, title, sub, tools, body) => `<section class="fwp-card fwp-card--flush">
    <div class="fwp-card__head"><span class="fwp-card__icon">${icon(iconName)}</span><div class="fwp-card__title"><h3>${title}</h3><p>${sub}</p></div></div>
    <div class="fwp-filters">${tools}</div>${body}</section>`;
  const tf = (label, ctl) => `<div class="field"><span class="label">${label}</span>${ctl}</div>`;
  const tone = { prepare: 'info', approved: 'gold', final: 'accent', booked: 'success' };

  async function show() {
    $$('[data-rep]').forEach((b) => { b.classList.toggle('is-on', b.dataset.rep === st.rep); b.setAttribute('aria-selected', String(b.dataset.rep === st.rep)); });
    app.innerHTML = '<div class="fwp-loading"><span class="fwp-spin"></span>Working it out…</div>';
    try {
      if (st.rep === 'emp201') return emp201((await api.get('reports.php', { op: 'emp201', month: st.month })).report);
      if (st.rep === 'recon') return recon((await api.get('reports.php', { op: 'recon', year: st.year, kind: st.kind })).report);
      if (st.rep === 'summary') return summary((await api.get('reports.php', { op: 'summary', from: st.from, to: st.to })).report);
      if (st.rep === 'costs') return costs((await api.get('reports.php', { op: 'costs', from: st.from, to: st.to })).report);
    } catch (e) { app.innerHTML = ''; toastError(e); }
  }

  function emp201(r) {
    const t = r.totals;
    const tools = tf('Month', `<input class="input" type="month" id="fMonth" value="${esc(st.month)}" max="${today.slice(0, 7)}">`) +
      `<div class="field"><a class="btn" href="${csv({ op: 'emp201', month: st.month })}">${icon('download')}Download</a></div>`;
    const body = `<div class="fwp-card__body is-padded">
      <div class="fwp-stats">
        <div class="fwp-stat"><span class="fwp-stat__label">PAYE</span><span class="fwp-stat__value fwp-num">${money(t.paye)}</span><span class="fwp-stat__sub">employees’ tax</span></div>
        <div class="fwp-stat"><span class="fwp-stat__label">UIF</span><span class="fwp-stat__value fwp-num">${money(t.uif)}</span><span class="fwp-stat__sub">1% + 1%</span></div>
        <div class="fwp-stat"><span class="fwp-stat__label">SDL</span><span class="fwp-stat__value fwp-num">${money(t.sdl)}</span><span class="fwp-stat__sub">skills levy</span></div>
        <div class="fwp-stat${r.overdue ? ' is-alert' : ''}"><span class="fwp-stat__label">Pay SARS</span><span class="fwp-stat__value fwp-num">${money(t.total)}</span><span class="fwp-stat__sub">by ${esc(longDay(r.due))}</span></div>
      </div>
      ${r.pending.length ? `<div class="fwp-banner fwp-banner--warn" style="margin-top:14px">${icon('alert')}<div class="fwp-banner__body"><b>${r.pending.length === 1 ? 'A run paid this month is' : r.pending.length + ' runs paid this month are'} not finalised yet</b> and not counted: ${r.pending.map((p) => `<a href="/payroll/run_view.php?id=${p.id}">${esc(p.name)}</a>`).join(', ')}.</div></div>` : ''}
      <p class="hint" style="margin:14px 0 0">Declare these on the EMP201 for ${esc(r.label)} on SARS eFiling and pay by the 7th (the last business day before it when it falls on a weekend). UIF is the employee’s and the employer’s 1% together.</p></div>
      ${r.runs.length ? `<div class="fwp-table-wrap"><table class="fwp-table"><thead><tr><th>Run</th><th>Paid</th><th class="t-num">People</th><th class="t-num">Gross pay</th><th class="t-num">PAYE</th><th class="t-num">UIF</th><th class="t-num">SDL</th></tr></thead><tbody>
        ${r.runs.map((x) => `<tr><td><a href="/payroll/run_view.php?id=${x.id}">${esc(x.name)}</a> <small class="fwp-muted">${esc(x.number)}</small></td><td>${esc(fmtDay(x.pay_date))}</td><td class="t-num">${x.people}</td><td class="t-num fwp-money">${money(x.gross)}</td><td class="t-num fwp-money">${money(x.paye)}</td><td class="t-num fwp-money">${money(x.uif)}</td><td class="t-num fwp-money">${money(x.sdl)}</td></tr>`).join('')}
        </tbody><tfoot><tr><td>Total</td><td></td><td class="t-num">${t.people}</td><td class="t-num">${money(t.gross)}</td><td class="t-num">${money(t.paye)}</td><td class="t-num">${money(t.uif)}</td><td class="t-num">${money(t.sdl)}</td></tr></tfoot></table></div>`
        : '<div class="fwp-table__empty"><b>Nothing paid this month</b>No finalised pay runs had their pay day in ' + esc(r.label) + '. You still file a nil EMP201 if you are registered.</div>'}`;
    app.innerHTML = card('ip-sars', 'EMP201 · ' + esc(r.label), 'The monthly declaration of PAYE, UIF and SDL.', tools, body);
    $('#fMonth').addEventListener('change', (e) => { if (e.target.value) { st.month = e.target.value; show(); } });
  }

  function recon(r) {
    const yearOpts = Object.entries(B.years).map(([k, v]) => `<option value="${k}"${k === st.year ? ' selected' : ''}>${esc(v)}</option>`).join('');
    const tools = tf('Tax year', `<select class="select" id="fYear">${yearOpts}</select>`) +
      tf('Period', `<div class="seg" role="radiogroup"><button type="button" data-k="interim" class="${st.kind === 'interim' ? 'is-on' : ''}">Interim · Mar–Aug</button><button type="button" data-k="annual" class="${st.kind === 'annual' ? 'is-on' : ''}">Annual · Mar–Feb</button></div>`) +
      `<div class="field"><a class="btn" href="${csv({ op: 'recon', year: st.year, kind: st.kind })}">${icon('download')}Certificate figures</a></div>`;
    const t = r.totals;
    const noTax = r.people.filter((p) => p.missing_tax_number);
    const body = `<div class="fwp-card__body is-padded">
      <p class="hint" style="margin-top:0">The ${r.kind === 'interim' ? 'interim reconciliation (filed by the end of October)' : 'annual reconciliation (filed by the end of May)'} checks that the EMP201s you filed add up to what each employee’s IRP5 certificate shows. Compare these monthly totals with your EMP201s on eFiling, then use the certificate figures in e@syFile.</p>
      ${noTax.length ? `<div class="fwp-banner fwp-banner--warn">${icon('alert')}<div class="fwp-banner__body"><b>${noTax.length === 1 ? '1 person has' : noTax.length + ' people have'} no tax number:</b> ${noTax.map((p) => `<a href="/payroll/employees.php?open=${p.id}">${esc(p.name)}</a>`).join(', ')}. SARS needs it on their certificate.</div></div>` : ''}
    </div>
    <div class="fwp-table-wrap"><table class="fwp-table"><thead><tr><th>Month</th><th class="t-num">PAYE</th><th class="t-num">UIF</th><th class="t-num">SDL</th><th class="t-num">Total</th></tr></thead><tbody>
      ${r.months.map((m) => `<tr${m.total ? '' : ' class="is-muted"'}><td>${esc(m.label)}</td><td class="t-num fwp-money">${money(m.paye)}</td><td class="t-num fwp-money">${money(m.uif)}</td><td class="t-num fwp-money">${money(m.sdl)}</td><td class="t-num fwp-money fwp-strong">${money(m.total)}</td></tr>`).join('')}
      </tbody><tfoot><tr><td>${esc(r.label)}</td><td class="t-num">${money(t.paye)}</td><td class="t-num">${money(t.uif)}</td><td class="t-num">${money(t.sdl)}</td><td class="t-num">${money(t.total)}</td></tr></tfoot></table></div>
    <div class="fwp-section" style="margin:22px 22px 10px"><h2 style="font-size:20px">Per employee</h2><span class="fwp-section__rule"></span><small>IRP5 source codes</small></div>
    ${r.people.length ? `<div class="fwp-table-wrap"><table class="fwp-table"><thead><tr><th>Employee</th><th class="hide-narrow">Tax number</th>${r.codes.map((c) => `<th class="t-num hide-narrow">${esc(c)}</th>`).join('')}<th class="t-num">4102 PAYE</th><th class="t-num hide-narrow">4141 UIF</th><th class="t-num hide-narrow">4142 SDL</th></tr></thead><tbody>
      ${r.people.map((p) => `<tr><td><div class="fwp-who__text"><b>${esc(p.name)}</b><small>${esc(p.no)} · ${esc(p.period)}</small></div></td><td class="hide-narrow fwp-mono">${p.tax_number ? esc(p.tax_number) : '<span class="fwp-flag">missing</span>'}</td>
        ${r.codes.map((c) => `<td class="t-num fwp-money hide-narrow">${p.codes[c] ? money(p.codes[c]) : '—'}</td>`).join('')}<td class="t-num fwp-money fwp-strong">${money(p.paye)}</td><td class="t-num fwp-money hide-narrow">${money(p.uif)}</td><td class="t-num fwp-money hide-narrow">${money(p.sdl)}</td></tr>`).join('')}
      </tbody></table></div>` : '<div class="fwp-table__empty"><b>No one paid in this period</b>Finalised runs will show here.</div>'}`;
    app.innerHTML = card('if-scale', 'EMP501 · ' + esc(r.label), 'Six-monthly reconciliation and the figures for each IRP5 certificate.', tools, body);
    $('#fYear').addEventListener('change', (e) => { st.year = e.target.value; show(); });
    $$('[data-k]', app).forEach((b) => b.addEventListener('click', () => { st.kind = b.dataset.k; show(); }));
  }

  const rangeTools = (op) => tf('From', `<input class="input" type="date" id="fFrom" value="${esc(st.from)}">`) + tf('To', `<input class="input" type="date" id="fTo" value="${esc(st.to)}">`) +
    tf('Quick', `<select class="select" id="fQuick"><option value="">Choose…</option><option value="ty">This tax year</option><option value="lty">Last tax year</option><option value="m">This month</option><option value="lm">Last month</option></select>`) +
    `<div class="field"><a class="btn" href="${csv({ op, from: st.from, to: st.to })}">${icon('download')}Download</a></div>`;
  function bindRange() {
    $('#fFrom').addEventListener('change', (e) => { st.from = e.target.value; show(); });
    $('#fTo').addEventListener('change', (e) => { st.to = e.target.value; show(); });
    $('#fQuick').addEventListener('change', (e) => {
      const d = new Date();
      const ymd = (x) => x.toISOString().slice(0, 10);
      const y0 = +B.currentYear.slice(0, 4);
      const q = e.target.value;
      if (q === 'ty') { st.from = B.currentYear; st.to = ymd(d); }
      if (q === 'lty') { st.from = (y0 - 1) + '-03-01'; st.to = ymd(new Date(y0, 2, 0)); }
      if (q === 'm') { st.from = ymd(new Date(d.getFullYear(), d.getMonth(), 1, 12)); st.to = ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0, 12)); }
      if (q === 'lm') { st.from = ymd(new Date(d.getFullYear(), d.getMonth() - 1, 1, 12)); st.to = ymd(new Date(d.getFullYear(), d.getMonth(), 0, 12)); }
      show();
    });
  }
  function summary(r) {
    const t = r.totals;
    const body = r.rows.length ? `<div class="fwp-table-wrap"><table class="fwp-table"><thead><tr><th>Employee</th><th class="t-num hide-narrow">Runs</th><th class="t-num">Gross</th><th class="t-num">PAYE</th><th class="t-num hide-narrow">UIF</th><th class="t-num hide-narrow">Deductions</th><th class="t-num">Net pay</th><th class="t-num hide-narrow">Cost to company</th></tr></thead><tbody>
      ${r.rows.map((x) => `<tr><td><a href="/payroll/employees.php?open=${x.id}">${esc(x.name)}</a> <small class="fwp-muted">${esc(x.no)}</small></td><td class="t-num hide-narrow">${x.runs}</td><td class="t-num fwp-money">${money(x.gross)}</td><td class="t-num fwp-money">${money(x.paye)}</td><td class="t-num fwp-money hide-narrow">${money(x.uif)}</td><td class="t-num fwp-money hide-narrow">${money(x.deductions)}</td><td class="t-num fwp-money fwp-strong">${money(x.net)}</td><td class="t-num fwp-money hide-narrow">${money(x.cost)}</td></tr>`).join('')}
      </tbody><tfoot><tr><td>Total</td><td class="hide-narrow"></td><td class="t-num">${money(t.gross)}</td><td class="t-num">${money(t.paye)}</td><td class="t-num hide-narrow">${money(t.uif)}</td><td class="t-num hide-narrow">${money(t.deductions)}</td><td class="t-num">${money(t.net)}</td><td class="t-num hide-narrow">${money(t.cost)}</td></tr></tfoot></table></div>`
      : '<div class="fwp-table__empty"><b>Nothing paid in these dates</b>Choose other dates, or finalise a run.</div>';
    app.innerHTML = card('i-users', 'Pay summary', 'Everyone’s pay between two dates, by pay day of finalised runs.', rangeTools('summary'), body);
    bindRange();
  }
  function costs(r) {
    const t = r.totals;
    const body = r.rows.length ? `<div class="fwp-table-wrap"><table class="fwp-table"><thead><tr><th>Project</th><th class="t-num">Wages</th><th class="t-num">Employer UIF + SDL</th><th class="t-num">Total</th></tr></thead><tbody>
      ${r.rows.map((x) => `<tr${x.project_id ? '' : ' class="is-muted"'}><td>${esc(x.name)}</td><td class="t-num fwp-money">${money(x.wages)}</td><td class="t-num fwp-money">${money(x.levies)}</td><td class="t-num fwp-money fwp-strong">${money(x.total)}</td></tr>`).join('')}
      </tbody><tfoot><tr><td>Total</td><td class="t-num">${money(t.wages)}</td><td class="t-num">${money(t.levies)}</td><td class="t-num">${money(t.total)}</td></tr></tfoot></table></div>`
      : '<div class="fwp-table__empty"><b>Nothing paid in these dates</b>Give people a usual project on their profile to see labour cost per project.</div>';
    app.innerHTML = card('i-briefcase', 'Wage cost by project', 'Earnings by each person’s usual project; the company’s UIF and SDL follow in proportion.', rangeTools('costs'), body);
    bindRange();
  }

  $$('[data-rep]').forEach((b) => b.addEventListener('click', () => { st.rep = b.dataset.rep; history.replaceState(null, '', '?report=' + st.rep); show(); }));
  show();
})();
