// /payroll/js/run.js — one pay run: stages, the money, everyone in it and
// the actions for its stage; each person's payslip opens in a dialog where
// hours, overtime, bonuses and once-off deductions are added or removed.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, popover, closePop, busy, money, fieldErrors, fmtDay, longDay, initials, hue } = FWP;
  const B = FWP.boot;
  const app = $('#runApp');
  let run = null;
  const TONE = { prepare: 'info', approved: 'gold', final: 'accent', booked: 'success' };
  const WARN = {
    no_hours: ['Needs hours', 'danger'], negative_net: ['Deductions too high', 'danger'], not_employed: ['Not employed in period', 'danger'], no_pay: ['No salary or rate', 'danger'],
    no_tax_number: ['No tax number', ''], no_bank: ['No bank details', ''], no_birth_date: ['No date of birth', ''],
  };

  async function load() {
    try {
      run = (await api.get('runs.php', { op: 'get', id: B.runId })).run;
      render();
    } catch (e) {
      app.innerHTML = `<div class="fwm-empty" style="min-height:50vh"><h3>This pay run could not be opened</h3><p>${esc(e.message)}</p><a class="btn" href="/payroll/runs.php">All pay runs</a></div>`;
    }
  }

  // ---------------------------------------------------------------- render
  function stages() {
    const order = ['prepare', 'approved', 'final', 'booked'];
    const idx = order.indexOf(run.stage);
    const steps = [['Prepare', 'Hours, overtime and bonuses'], ['Approve', 'Check and freeze the figures'], ['Finalise', 'Payslips and the bank file'], ['In the books', 'Wages, PAYE, UIF, SDL']];
    return `<div class="fwp-stages" role="list">${steps.map(([t, s], i) => {
      const cls = i < idx || (i === idx && run.stage === 'booked') ? 'is-done' : i === idx ? 'is-current' : '';
      return `<div class="fwp-stage ${cls}" role="listitem"${i === idx ? ' aria-current="step"' : ''}><span class="fwp-stage__dot">${cls === 'is-done' ? icon('check') : i + 1}</span><b>${t}</b><small>${s}</small></div>`;
    }).join('')}</div>`;
  }
  const stageHint = () => ({
    prepare: 'Open anyone to add hours, overtime, a bonus or a once-off deduction. Approve when the figures are right.',
    approved: 'The figures are frozen. Finalise to create the payslips and the bank file — or send it back to change something.',
    final: run.journal_id ? '' : 'Payslips are ready. Pay the net amounts with the bank file, then put the run in the books.',
    booked: 'Done — the wages, PAYE, UIF and SDL are in Finance.',
  }[run.stage]);

  function actions() {
    const s = run.stage;
    const kebab = `<button class="icon-btn" type="button" id="runMore" aria-label="More" aria-haspopup="menu">${icon('more-v')}</button>`;
    if (s === 'prepare') return `<button class="btn" type="button" data-do="recalc">${icon('refresh')}Recalculate</button>
      <button class="btn btn--primary" type="button" data-do="approve">${icon('check')}Approve</button>${kebab}`;
    if (s === 'approved') return `<button class="btn" type="button" data-do="reopen">${icon('undo')}Send back</button>
      <button class="btn btn--primary" type="button" data-do="finalise">${icon('ip-payslip')}Finalise</button>${kebab}`;
    const common = `<button class="btn" type="button" data-do="bank">${icon('download')}Bank file</button>
      <button class="btn" type="button" data-do="email">${icon('mail')}Email payslips</button>`;
    if (s === 'final') return common + `<button class="btn btn--primary" type="button" data-do="post">${icon('if-post')}Put in the books</button>${kebab}`;
    return common + (run.journal_id ? `<a class="btn" href="/finances/#journals?id=${run.journal_id}">${icon('if-ledger')}Open the journal</a>` : '') + kebab;
  }

  function hero() {
    const t = run.totals;
    const n = run.people.length;
    const fig = (label, v, sub = '') => `<div class="fwp-fig"><span>${label}</span><b>${money(v)}</b>${sub ? `<small>${sub}</small>` : ''}</div>`;
    return `<section class="fwp-card fwp-hero">
      <div class="fwp-hero__net"><span class="fwp-stat__label">Net pay</span><span class="fwp-big">${money(t.net)}</span>
        <small>${n} ${n === 1 ? 'person' : 'people'} · into the bank ${money(t.bank)}</small></div>
      <div class="fwp-hero__grid">
        ${fig('Gross pay', t.gross, t.reimb ? 'plus ' + money(t.reimb) + ' reimbursed' : '')}
        ${fig('PAYE', t.paye, 'income tax to SARS')}
        ${fig('UIF', t.uif_ee + t.uif_er, money(t.uif_ee) + ' from people')}
        ${fig('SDL', t.sdl, 'skills levy')}
        ${fig('Other deductions', t.deductions)}
        ${fig('Cost to company', t.cost, 'pay + employer UIF, SDL')}
      </div></section>`;
  }

  function blockers() {
    if (run.stage !== 'prepare') return '';
    const bad = run.people.filter((p) => p.warnings.some((w) => WARN[w] && WARN[w][1] === 'danger'));
    if (!bad.length) return '';
    return `<div class="fwp-banner fwp-banner--danger">${icon('alert')}<div class="fwp-banner__body"><b>${bad.length === 1 ? '1 person needs' : bad.length + ' people need'} sorting out before you approve:</b>
      ${bad.map((p) => `<a href="#p${p.employee_id}" data-open="${p.employee_id}">${esc(p.name)}</a> (${p.warnings.filter((w) => WARN[w] && WARN[w][1] === 'danger').map((w) => WARN[w][0].toLowerCase()).join(', ')})`).join(' · ')}</div></div>`;
  }

  function missing() {
    if (run.stage !== 'prepare' || !run.missing.length) return '';
    const names = run.missing.slice(0, 4).map((m) => esc(m.name)).join(', ') + (run.missing.length > 4 ? ' and ' + (run.missing.length - 4) + ' more' : '');
    return `<div class="fwp-banner">${icon('i-users')}<div class="fwp-banner__body"><b>${run.missing.length === 1 ? '1 person' : run.missing.length + ' people'} paid ${esc((B.freq[run.frequency] || '').toLowerCase())} ${run.missing.length === 1 ? 'is' : 'are'} not in this run:</b> ${names}.</div>
      <button class="fwp-mini" type="button" data-do="add_people">${icon('plus')}Add ${run.missing.length === 1 ? 'them' : 'them all'}</button></div>`;
  }

  function flags(p) {
    const out = [];
    p.warnings.forEach((w) => { if (WARN[w]) out.push(`<span class="fwp-flag ${WARN[w][1] === 'danger' ? 'fwp-flag--danger' : ''}">${WARN[w][0]}</span>`); });
    if (p.leaving) out.push('<span class="fwp-flag fwp-flag--info">Last pay</span>');
    if (p.adjustments) out.push(`<span class="fwp-flag fwp-flag--ok">${p.adjustments} added</span>`);
    if (run.stage === 'final' || run.stage === 'booked') {
      if (p.emailed_at) out.push(`<span class="fwp-flag fwp-flag--ok">${icon('check')}Emailed</span>`);
      else if (p.email_error) out.push(`<span class="fwp-flag fwp-flag--danger" title="${esc(p.email_error)}">Email failed</span>`);
      else if (!p.email) out.push('<span class="fwp-flag">No email</span>');
    }
    return out.length ? `<span class="fwp-flags">${out.join('')}</span>` : '';
  }
  function variance(p) {
    if (p.variance === null || p.variance === 0) return '';
    const up = p.variance > 0;
    return `<span class="fwp-var ${up ? 'is-up' : 'is-down'}" title="${up ? 'More' : 'Less'} than their last finalised pay">${up ? '▲' : '▼'} ${money(Math.abs(p.variance))}</span>`;
  }
  function people() {
    if (!run.people.length) {
      return `<section class="fwp-card"><div class="fwp-table__empty"><b>Nobody in this run</b>${run.stage === 'prepare' ? 'Add the people it should pay.' : ''}</div></section>`;
    }
    const t = run.totals;
    return `<section class="fwp-card fwp-card--flush" style="margin-top:16px">
      <div class="fwp-card__head"><div class="fwp-card__title"><h3>People <small class="fwp-muted" style="font-family:var(--font-ui);font-size:13px">${run.people.length}</small></h3>
        <p>${run.stage === 'prepare' ? 'Open someone to see their payslip and add to it.' : 'Open someone to see their payslip.'}</p></div></div>
      <div class="fwp-table-wrap"><table class="fwp-table">
        <thead><tr><th>Employee</th><th class="t-num">Gross</th><th class="t-num hide-narrow">PAYE</th><th class="t-num hide-narrow">UIF</th><th class="t-num hide-narrow">Deductions</th><th class="t-num">Net pay</th><th></th></tr></thead>
        <tbody>${run.people.map((p) => `<tr class="is-click" data-open="${p.employee_id}" tabindex="0" id="p${p.employee_id}">
          <td><div class="fwp-who"><span class="fwp-ava fwp-ava--sm" style="--h:${hue(p.name)}">${esc(initials(p.name))}</span><span class="fwp-who__text"><b>${esc(p.name)}</b><small>${esc(p.job_title || p.no)}${p.hourly ? ' · hourly' : ''}</small></span></div>
            ${flags(p) ? `<div style="margin:6px 0 0 39px">${flags(p)}</div>` : ''}</td>
          <td class="t-num fwp-money">${money(p.gross)}</td>
          <td class="t-num fwp-money hide-narrow">${money(p.paye)}</td>
          <td class="t-num fwp-money hide-narrow">${money(p.uif)}</td>
          <td class="t-num fwp-money hide-narrow">${money(p.deductions)}</td>
          <td class="t-num"><span class="fwp-money fwp-strong ${p.net < 0 ? 'is-neg' : ''}">${money(p.net)}</span>${variance(p)}</td>
          <td class="t-actions">${icon('chev-right', 'i--sm')}</td></tr>`).join('')}</tbody>
        <tfoot><tr><td>Total</td><td class="t-num">${money(t.gross)}</td><td class="t-num hide-narrow">${money(t.paye)}</td><td class="t-num hide-narrow">${money(t.uif_ee)}</td><td class="t-num hide-narrow">${money(t.deductions)}</td><td class="t-num">${money(t.net)}</td><td></td></tr></tfoot>
      </table></div></section>`;
  }

  function extras() {
    const out = [];
    if (run.bank_files.length) {
      out.push(`<li><span class="fwp-list__text"><b>Bank file downloaded ${run.bank_files.length === 1 ? 'once' : run.bank_files.length + ' times'}</b><small>Last: ${esc(fmtDay(run.bank_files[0].at))}${run.bank_files[0].by ? ' by ' + esc(run.bank_files[0].by) : ''} · ${run.bank_files[0].people} people · ${money(run.bank_files[0].total)}</small></span></li>`);
    }
    if (run.notes) out.push(`<li><span class="fwp-list__text"><b>Note</b><small>${esc(run.notes)}</small></span></li>`);
    return out.length ? `<section class="fwp-card" style="margin-top:16px"><div class="fwp-card__body" style="padding-top:12px"><ul class="fwp-list">${out.join('')}</ul></div></section>` : '';
  }

  function render() {
    document.title = run.name + ' · Payroll';
    app.innerHTML = `
      <header class="fwp-head">
        <div class="fwp-head__text">
          <p class="fwp-eyebrow">Pay run <i>◆</i> ${esc(run.number)} <i>◆</i> ${esc(B.freq[run.frequency] || run.frequency)}</p>
          <h1>${esc(run.name)}</h1>
          <p>${esc(run.period)} · paid <b>${esc(longDay(run.pay_date))}</b> · ${esc(run.tax_year)} tax year · <span class="fwp-pill" data-tone="${TONE[run.stage]}">${esc(run.stage_label)}</span></p>
        </div>
        <div class="fwp-head__actions">${actions()}</div>
      </header>
      <section class="fwp-card"><div class="fwp-card__body" style="padding:20px 22px 18px">${stages()}
        ${stageHint() ? `<p class="fwp-muted" style="text-align:center;margin:14px 0 0">${esc(stageHint())}</p>` : ''}
        ${run.stage === 'final' && !B.bankReady ? `<p style="text-align:center;margin:8px 0 0"><a href="/payroll/settings.php#set-bank_account_id">Choose the bank account salaries are paid from</a> before putting the run in the books.</p>` : ''}
      </div></section>
      <div style="margin-top:16px">${blockers()}${missing()}</div>
      ${hero()}
      ${people()}
      ${extras()}`;
    bind();
  }

  // ---------------------------------------------------------------- actions
  function bind() {
    $$('[data-open]', app).forEach((el) => el.addEventListener('click', (e) => { e.preventDefault(); person(+el.dataset.open); }));
    $$('tr[data-open]', app).forEach((el) => el.addEventListener('keydown', (e) => { if (e.key === 'Enter') person(+el.dataset.open); }));
    $$('[data-do]', app).forEach((b) => b.addEventListener('click', () => act(b.dataset.do, b)));
    const more = $('#runMore', app);
    if (more) more.addEventListener('click', () => {
      const items = [];
      if (run.stage === 'prepare') items.push(['rename', 'pen', 'Rename or add a note'], ['delete', 'trash', 'Delete this run', true]);
      if (run.stage === 'approved') items.push(['delete', 'trash', 'Delete this run', true]);
      if (run.stage === 'final' && B.admin) items.push(['reopen_final', 'undo', 'Reopen (admins)', true]);
      items.push(['print', 'print', 'Print this page']);
      const el = popover(more, items.map(([k, ic, l, d]) => `<button class="fwm-pop__item${d ? ' is-danger' : ''}" type="button" role="menuitem" data-m="${k}">${icon(ic)}${l}</button>`).join(''), { align: 'end' });
      $$('[data-m]', el).forEach((b) => b.addEventListener('click', () => { closePop(); act(b.dataset.m, more); }));
    });
  }

  async function act(what, btn) {
    const call = async (op, extra = {}, msg = '') => {
      busy(btn, true);
      try {
        const res = await api.post('runs.php', Object.assign({ op, id: run.id }, extra));
        if (msg) toast(typeof msg === 'function' ? msg(res) : msg);
        await load();
        return res;
      } catch (e) { toastError(e); await load(); return null; } finally { busy(btn, false); }
    };
    switch (what) {
      case 'recalc': return call('recalc', {}, (r) => 'Worked out again for ' + r.people + (r.people === 1 ? ' person.' : ' people.'));
      case 'add_people': return call('add_people', {}, (r) => r.added + (r.added === 1 ? ' person added.' : ' people added.'));
      case 'approve': return call('approve', {}, 'Approved — the figures are frozen.');
      case 'reopen': return call('reopen', {}, 'Back to preparation.');
      case 'reopen_final': {
        const ok = await dialog({ title: 'Reopen this finalised run?', body: 'Payslips that were already emailed will be out of date, and the bank file may already have been paid. Only do this to correct a mistake before anyone is paid.', confirm: 'Reopen', danger: true, typeToConfirm: 'REOPEN' });
        if (ok) return call('reopen', {}, 'Reopened for changes. Approve and finalise it again when done.');
        return;
      }
      case 'finalise': return finalise(btn);
      case 'post': {
        const ok = await dialog({ title: 'Put this run in the books?', body: `Finance gets one journal dated ${esc(fmtDay(run.pay_date))}: wages, PAYE, UIF, SDL, deductions and the net pay from the salaries bank account. A run in the books cannot change.`, confirm: 'Put in the books' });
        if (ok) return call('post', {}, 'In the books.');
        return;
      }
      case 'bank': return bankFile(btn);
      case 'email': return emailAll(btn);
      case 'rename': return rename();
      case 'delete': {
        const ok = await dialog({ title: 'Delete this pay run?', body: 'Everyone’s figures and the hours, overtime and bonuses added to it are thrown away. Their records stay.', confirm: 'Delete run', danger: true });
        if (!ok) return;
        busy(btn, true);
        try { await api.post('runs.php', { op: 'delete', id: run.id }); location.href = '/payroll/runs.php'; } catch (e) { toastError(e); busy(btn, false); }
        return;
      }
      case 'print': return window.print();
    }
  }

  function finalise(btn) {
    const withEmail = run.people.filter((p) => p.email).length;
    const without = run.people.length - withEmail;
    const m = modal(`<div class="fwm-dialog__head"><h2>Finalise ${esc(run.name)}</h2></div>
      <div class="fwm-dialog__body"><p class="fwm-dialog__lead">This locks the run and creates a payslip for each of the ${run.people.length} ${run.people.length === 1 ? 'person' : 'people'}. The bank file becomes available.${B.autoPost ? ' It then goes into the books by itself (Settings).' : ''}</p>
        <label class="switch fwp-switch-row"><input type="checkbox" id="finEmail"${withEmail ? ' checked' : ' disabled'}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Email the payslips now</span>
          <span class="hint">${withEmail ? `To ${withEmail} ${withEmail === 1 ? 'person' : 'people'} with an email address.` : 'Nobody in this run has an email address.'}${without && withEmail ? ` ${without} without one — download theirs from the run.` : ''}</span></span></label></div>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-go>${icon('ip-payslip')}Finalise</button></div>`, { width: 520 });
    openModal(m);
    $('[data-go]', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      busy(b, true);
      try {
        const res = (await api.post('runs.php', { op: 'finalise', id: run.id, email: $('#finEmail', m).checked })).result;
        closeModal(m);
        let msg = `Finalised — ${res.payslips} payslip${res.payslips === 1 ? '' : 's'} created.`;
        if (res.emailed) msg += ` Emailed ${res.emailed.sent}${res.emailed.failed ? `, ${res.emailed.failed} failed` : ''}.`;
        if (res.posted) msg += ' In the books.';
        toast(msg, { timeout: 8000 });
        if (res.post_error) toast('Not put in the books: ' + res.post_error, { type: 'error' });
        load();
      } catch (e) { toastError(e); busy(b, false); }
    });
  }

  async function bankFile(btn) {
    busy(btn, true);
    try {
      const chk = await api.get('bank.php', { run: run.id, check: 1 });
      busy(btn, false);
      const go = () => { location.href = '/payroll/api/bank.php?run=' + run.id; setTimeout(load, 1500); };
      if (!chk.missing.length) return go();
      const ok = await dialog({ title: 'Some people have no bank details', body: `${esc(chk.missing.join(', '))} ${chk.missing.length === 1 ? 'is' : 'are'} left out of the file — pay ${chk.missing.length === 1 ? 'them' : 'them'} another way. The file pays ${chk.people} ${chk.people === 1 ? 'person' : 'people'} ${money(chk.total)}.`, confirm: chk.people ? 'Download anyway' : 'OK', cancel: chk.people ? 'Cancel' : '' });
      if (ok && chk.people) go();
    } catch (e) { busy(btn, false); toastError(e); }
  }

  async function emailAll(btn, employeeId = null, resend = false) {
    busy(btn, true);
    try {
      const r = (await api.post('runs.php', { op: 'email', id: run.id, employee_id: employeeId, resend })).result;
      const parts = [];
      if (r.sent) parts.push(`${r.sent} sent from ${r.via === 'server' ? 'the server’s own mail' : r.via}`);
      if (r.failed) parts.push(`${r.failed} failed`);
      if (r.no_email.length) parts.push(`${r.no_email.length} without an email address`);
      if (!r.sent && !r.failed && !r.no_email.length) parts.push('Everyone already has theirs');
      toast(parts.join(' · ') + '.', { type: r.failed ? 'error' : undefined, timeout: 8000 });
      if (r.errors.length) toast(r.errors.slice(0, 3).join(' · '), { type: 'error' });
      await load();
    } catch (e) { toastError(e); } finally { busy(btn, false); }
  }

  function rename() {
    const m = modal(`<div class="fwm-dialog__head"><h2>Rename the run</h2></div>
      <form class="fwm-dialog__body" novalidate><div class="field"><label class="label" for="rnName">Name</label><input class="input" id="rnName" name="name" maxlength="120" value="${esc(run.name)}"></div>
        <div class="field"><label class="label" for="rnNotes">Note</label><textarea class="textarea" id="rnNotes" name="notes" maxlength="1000" rows="3">${esc(run.notes)}</textarea></div></form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-go>Save</button></div>`, { width: 480 });
    openModal(m);
    $('[data-go]', m).addEventListener('click', async () => {
      try {
        await api.post('runs.php', { op: 'rename', id: run.id, name: $('#rnName', m).value, notes: $('#rnNotes', m).value });
        closeModal(m);
        load();
      } catch (e) { if (e.data && e.data.errors) fieldErrors($('form', m), e.data.errors); else toastError(e); }
    });
  }

  // ---------------------------------------------------------- the payslip
  async function person(employeeId) {
    let P;
    try { P = (await api.get('runs.php', { op: 'person', id: run.id, employee: employeeId })).person; } catch (e) { return toastError(e); }
    const open = run.stage === 'prepare';
    const m = modal(`<div class="fwm-dialog__head"><span class="fwp-ava" style="--h:${hue(P.employee.name)}">${esc(initials(P.employee.name))}</span>
        <h2 style="font-size:24px">${esc(P.employee.name)}<small class="fwp-muted" style="display:block;font-family:var(--font-ui);font-size:12.5px;margin-top:2px">${esc([P.employee.job_title, P.employee.no].filter(Boolean).join(' · '))}</small></h2>
        <button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body" id="slipBody" style="max-height:min(66vh,680px)"></div>
      <div class="fwm-dialog__foot">
        ${open ? `<button class="btn btn--ghost btn--danger" type="button" data-p="remove">${icon('x')}Take out of run</button>` : ''}
        <a class="btn btn--ghost" href="/payroll/employees.php?open=${employeeId}">${icon('user')}Their details</a>
        <span class="grow"></span>
        ${run.stage === 'final' || run.stage === 'booked' ? `<button class="btn" type="button" data-p="email">${icon('mail')}${P.payslip.emailed_at ? 'Email again' : 'Email payslip'}</button>` : ''}
        <a class="btn ${open ? '' : 'btn--primary'}" href="/payroll/api/payslip.php?run=${run.id}&employee=${employeeId}" target="_blank" rel="noopener">${icon('ip-payslip')}${run.stage === 'final' || run.stage === 'booked' ? 'Payslip' : 'Draft payslip'}</a>
      </div>`, { wide: true });
    const body = $('#slipBody', m);
    const draw = () => { body.innerHTML = slip(P, open); bindSlip(); };
    function bindSlip() {
      $$('[data-rm-line]', body).forEach((b) => b.addEventListener('click', async () => {
        busy(b, true);
        try {
          P = (await api.post('runs.php', { op: 'adjust_remove', id: run.id, line_id: +b.dataset.rmLine, employee_id: employeeId })).person;
          draw();
          toast('Removed.');
          load();
        } catch (e) { toastError(e); busy(b, false); }
      }));
      $$('[data-add]', body).forEach((b) => b.addEventListener('click', () => addForm(b.dataset.add)));
    }
    function addForm(kind) {
      const box = $('#adjBox', body);
      const rate = P.employee.hourly_rate;
      const ot = P.overtime;
      const itemsOf = (types) => B.items.filter((i) => types.includes(i.type));
      let html = '';
      if (kind === 'hours') {
        html = `<div class="fwp-form fwp-form--3"><div class="field"><label class="label" for="aq">Hours worked</label><input class="input" id="aq" name="qty" inputmode="decimal" autofocus></div>
          <div class="field"><span class="label">Rate</span><div class="input" style="display:flex;align-items:center">${money(rate)} an hour</div></div>
          <div class="field"><span class="label">Pay</span><div class="input fwp-money" id="aPrev" style="display:flex;align-items:center">—</div></div></div>`;
      } else if (kind === 'overtime') {
        html = `<div class="fwp-form fwp-form--3"><div class="field"><label class="label" for="aq">Hours</label><input class="input" id="aq" name="qty" inputmode="decimal" autofocus></div>
          <div class="field"><label class="label" for="aw">When</label><select class="select" id="aw" name="when"><option value="weekday">Normal overtime (${ot.weekday}×)</option><option value="sunday">Sunday (${ot.sunday}×)</option><option value="holiday">Public holiday (${ot.holiday}×)</option></select></div>
          <div class="field"><span class="label">Pay</span><div class="input fwp-money" id="aPrev" style="display:flex;align-items:center">—</div></div></div>
          <p class="hint" style="margin:6px 0 0">Their hourly rate is ${money(rate)}${P.employee.hourly ? '' : ' (salary ÷ normal hours a month, or the rate on their profile)'}.</p>`;
      } else {
        const types = kind === 'deduction' ? ['deduction'] : kind === 'bonus' ? ['earning'] : ['earning', 'reimbursement', 'benefit', 'contribution'];
        let list = itemsOf(types);
        if (kind === 'bonus') list = list.filter((i) => i.irregular).concat(list.filter((i) => !i.irregular));
        if (!list.length) {
          box.innerHTML = `<div class="fwp-banner" style="margin:12px 0 0">${icon('i-info')}<div class="fwp-banner__body">There is no ${kind === 'deduction' ? 'deduction' : 'earning'} item yet. <a href="/payroll/payitems.php">Add pay items</a> — the usual South African ones are one click.</div></div>`;
          return;
        }
        html = `<div class="fwp-form"><div class="field"><label class="label" for="ai">${kind === 'deduction' ? 'Deduction' : 'Item'}</label><select class="select" id="ai" name="payitem_id">${list.map((i) => `<option value="${i.id}">${esc(i.name)}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="aa">Amount</label><div class="fwp-affix"><span>R</span><input class="input" id="aa" name="amount" inputmode="decimal" autofocus></div></div>
          <div class="field"><label class="label" for="ad">Description on the payslip</label><input class="input" id="ad" name="description" maxlength="120" placeholder="Same as the item"></div>
          <div class="field"><label class="label" for="an">Note (not on the payslip)</label><input class="input" id="an" name="note" maxlength="255"></div></div>`;
      }
      box.innerHTML = `<form class="fwp-card" style="margin-top:14px;padding:16px 18px" novalidate>${html}
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px"><button class="btn btn--ghost" type="button" data-cancel>Cancel</button><button class="btn btn--primary" type="submit">${icon('plus')}Add to this pay</button></div></form>`;
      const form = $('form', box);
      const q = $('[name=qty]', form);
      const prev = $('#aPrev', form);
      const upd = () => {
        if (!prev) return;
        const h = parseFloat(String(q.value).replace(',', '.')) || 0;
        const mult = kind === 'overtime' ? ot[$('[name=when]', form).value] : 1;
        prev.textContent = h ? money(Math.round(h * Math.round(rate * mult))) : '—';
      };
      if (q) q.addEventListener('input', upd);
      const w = $('[name=when]', form);
      if (w) w.addEventListener('change', upd);
      $('[data-cancel]', form).addEventListener('click', () => { box.innerHTML = ''; });
      setTimeout(() => { const f = $('[autofocus]', form); if (f) f.focus(); }, 30);
      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = $('[type=submit]', form);
        const data = { kind: ['hours', 'overtime'].includes(kind) ? kind : 'item' };
        $$('[name]', form).forEach((f) => { data[f.name] = f.value; });
        busy(btn, true);
        try {
          P = (await api.post('runs.php', Object.assign({ op: 'adjust', id: run.id, employee_id: employeeId }, data))).person;
          draw();
          toast('Added — net pay is now ' + money(P.totals.net) + '.');
          load();
        } catch (err) {
          if (err.data && err.data.errors) fieldErrors(form, err.data.errors); else toastError(err);
          busy(btn, false);
        }
      });
    }
    m.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-p]');
      if (!b) return;
      if (b.dataset.p === 'remove') {
        const ok = await dialog({ title: `Take ${P.employee.name} out of this run?`, body: 'They are not paid in it. What was added for them on this run is thrown away. You can add them back from the run.', confirm: 'Take out', danger: true });
        if (!ok) return;
        try { await api.post('runs.php', { op: 'remove_person', id: run.id, employee_id: employeeId }); closeModal(m); toast(P.employee.name + ' taken out.'); load(); } catch (err) { toastError(err); }
      }
      if (b.dataset.p === 'email') { await emailAll(b, employeeId, true); }
    });
    draw();
    openModal(m);
  }

  function slip(P, open) {
    const t = P.totals;
    const L = (types) => P.lines.filter((l) => types.includes(l.type));
    const line = (l, neg) => `<li class="fwp-line${l.adjustment ? ' is-adj' : ''}">
      <span class="fwp-line__text"><span>${esc(l.description)}</span>${Math.abs(l.qty - 1) > 0.0001 ? `<small>${+l.qty.toFixed(2)} × ${money(Math.abs(l.rate))}</small>` : ''}${l.note ? `<small>${esc(l.note)}</small>` : ''}</span>
      <span class="fwp-line__amt">${neg ? '−' : ''}${money(Math.abs(l.amount))}</span>
      ${open && l.adjustment ? `<button class="icon-btn" type="button" data-rm-line="${l.id}" aria-label="Remove ${esc(l.description)}">${icon('x', 'i--sm')}</button>` : ''}</li>`;
    const earnings = L(['earning', 'reimbursement']);
    const nonCash = L(['benefit', 'contribution']);
    const deds = L(['deduction']);
    const x = P.explain || {};
    const pa = x.paye || {};
    const warn = (x.warnings || []).filter((w) => WARN[w]);
    const yr = x.tax_table ? x.tax_table.label : '';
    const ageTxt = pa.age === null || pa.age === undefined ? 'age unknown — primary rebate only' : `aged ${pa.age} at the end of February`;
    return `${warn.length ? `<div class="fwp-banner ${warn.some((w) => WARN[w][1] === 'danger') ? 'fwp-banner--danger' : 'fwp-banner--warn'}">${icon('alert')}<div class="fwp-banner__body">${warn.map((w) => WARN[w][0]).join(' · ')}${warn.includes('no_hours') ? ' — add the hours they worked below.' : ''}</div></div>` : ''}
      <div class="fwp-slip">
        <div><h4>Earnings</h4><ul class="fwp-lines">${earnings.length ? earnings.map((l) => line(l, false)).join('') : '<li class="fwp-line"><span class="fwp-line__text"><span class="fwp-muted">Nothing yet</span></span></li>'}
          <li class="fwp-line is-total"><span class="fwp-line__text"><span>Total earnings</span></span><span class="fwp-line__amt">${money(t.gross + t.reimb)}</span></li></ul></div>
        <div><h4>Deductions</h4><ul class="fwp-lines">
          <li class="fwp-line"><span class="fwp-line__text"><span>Income tax (PAYE)</span></span><span class="fwp-line__amt">−${money(t.paye)}</span></li>
          <li class="fwp-line"><span class="fwp-line__text"><span>UIF (1%)</span></span><span class="fwp-line__amt">−${money(t.uif_ee)}</span></li>
          ${deds.map((l) => line(l, true)).join('')}
          <li class="fwp-line is-total"><span class="fwp-line__text"><span>Total deductions</span></span><span class="fwp-line__amt">−${money(t.paye + t.uif_ee + t.deductions)}</span></li></ul></div>
      </div>
      <div class="fwp-netband"><span>Net pay</span>${t.variance !== null && t.variance !== 0 ? `<small style="color:var(--gold-bright)">${t.variance > 0 ? '▲' : '▼'} ${money(Math.abs(t.variance))} on last time</small>` : ''}<b>${money(t.net)}</b></div>
      ${open ? `<div class="fwp-quick">${P.employee.hourly ? `<button class="fwp-mini" type="button" data-add="hours">${icon('ip-hours')}Hours worked</button>` : ''}
        <button class="fwp-mini" type="button" data-add="overtime">${icon('clock')}Overtime</button>
        <button class="fwp-mini" type="button" data-add="bonus">${icon('ip-bonus')}Bonus</button>
        <button class="fwp-mini" type="button" data-add="earning">${icon('plus')}Other earning</button>
        <button class="fwp-mini" type="button" data-add="deduction">${icon('ip-minus-doc')}Once-off deduction</button></div><div id="adjBox"></div>` : ''}
      ${nonCash.length || t.uif_er || t.sdl ? `<details class="fwp-explain"><summary>${icon('i-building')}<span style="flex:1">Paid by the company on top · ${money(t.cost)} cost to company</span>${icon('chev-down')}</summary>
        <div class="fwp-explain__body"><dl class="fwp-workings">
          ${nonCash.map((l) => `<dt>${esc(l.description)} <small>(${l.type === 'benefit' ? 'taxable benefit, not paid out' : 'company contribution'})</small></dt><dd>${money(l.amount)}</dd>`).join('')}
          <dt>UIF (company 1%)</dt><dd>${money(t.uif_er)}</dd><dt>SDL (1%)${x.sdl_liable === false ? ' — the company does not pay SDL' : ''}</dt><dd>${money(t.sdl)}</dd>
          <dt class="is-sum">Cost to company</dt><dd class="is-sum">${money(t.cost)}</dd></dl></div></details>` : ''}
      <details class="fwp-explain"><summary>${icon('ip-calc')}<span style="flex:1">How the tax was worked out${yr ? ' · ' + esc(yr) + ' SARS tables' : ''}</span>${icon('chev-down')}</summary>
        <div class="fwp-explain__body"><dl class="fwp-workings">
          ${x.retirement && x.retirement.deductible ? `<dt>Retirement contributions that lower taxable pay</dt><dd>−${money(x.retirement.deductible)}</dd>` : ''}
          <dt>Taxable pay this period</dt><dd>${money(t.taxable - (pa.once_off_income || 0))}</dd>
          <dt>× ${pa.periods || ''} pay periods = a year’s taxable income</dt><dd>${money(pa.annual_income || 0)}</dd>
          <dt>Tax on that from the SARS brackets</dt><dd>${money(pa.tax_before_rebates || 0)}</dd>
          <dt>Less rebates (${esc(ageTxt)})</dt><dd>−${money(pa.rebates || 0)}</dd>
          <dt>= Tax for the year</dt><dd>${money(pa.tax_on_regular_year || 0)}</dd>
          <dt>÷ ${pa.periods || ''} = tax this period</dt><dd>${money(pa.tax_on_regular_period || 0)}</dd>
          ${pa.once_off_income ? `<dt>Tax on once-off pay (${money(pa.once_off_income)}), taxed at the margin</dt><dd>${money(pa.tax_on_once_off)}</dd>` : ''}
          ${pa.medical_members ? `<dt>Less medical aid credit (${pa.medical_members} ${pa.medical_members === 1 ? 'person' : 'people'})</dt><dd>−${money(pa.medical_credit)}</dd>` : ''}
          <dt class="is-sum">PAYE</dt><dd class="is-sum">${money(t.paye)}</dd>
          ${x.uif ? `<dt style="margin-top:6px">UIF: 1% of ${money(x.uif.base)}${x.uif.pay > x.uif.ceiling ? ` (capped at ${money(x.uif.ceiling)})` : ''}</dt><dd style="margin-top:6px">${money(t.uif_ee)}</dd>` : '<dt style="margin-top:6px">UIF</dt><dd style="margin-top:6px">Not paid (exempt)</dd>'}
        </dl></div></details>`;
  }

  load().then(() => {
    const m = /^#p(\d+)$/.exec(location.hash);
    if (m && run) person(+m[1]);
  });
})();
