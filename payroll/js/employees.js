// /payroll/js/employees.js — the Employees page: the table (search, filters)
// and the employee dialog with its tabs: Details, Pay, Tax & bank, Regular
// items and History. Everything is saved through /payroll/api/employees.php,
// which checks every value again.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, busy, money, moneyInput, fieldErrors, formData, runsNote, fmtDay, initials, hue } = FWP;
  const B = FWP.boot;
  const state = { status: 'active', frequency: '', q: '', missing: false, rows: [] };
  const params = new URLSearchParams(location.search);
  if (params.get('filter') === 'missing') { state.missing = true; state.status = 'active'; }

  // ------------------------------------------------------------------ table
  const tbody = $('#empRows');
  async function load() {
    try {
      const res = await api.get('employees.php', { op: 'list', status: state.status, frequency: state.frequency, q: state.q });
      state.rows = res.rows;
      $('#cActive').textContent = res.counts.active;
      $('#cLeft').textContent = res.counts.left;
      $('#cAll').textContent = res.counts.all;
      render();
    } catch (e) { toastError(e); }
  }
  const payText = (r) => (r.basis === 'hourly' ? money(r.hourly) + ' <small class="fwp-muted">/ hour</small>' : money(r.monthly) + ' <small class="fwp-muted">/ month</small>');
  function statusFlags(r) {
    const out = [];
    if (r.status === 'left') out.push(`<span class="fwp-flag fwp-flag--info">Left ${esc(fmtDay(r.termination_date))}</span>`);
    else if (r.status === 'leaving') out.push(`<span class="fwp-flag fwp-flag--info">Leaves ${esc(fmtDay(r.termination_date))}</span>`);
    else if (r.status === 'starting') out.push(`<span class="fwp-flag fwp-flag--ok">Starts ${esc(fmtDay(r.hire_date))}</span>`);
    r.missing.forEach((m) => out.push(`<span class="fwp-flag">${esc(m)}</span>`));
    return out.length ? `<span class="fwp-flags">${out.join('')}</span>` : '<span class="fwp-muted">—</span>';
  }
  function render() {
    $$('[data-status]').forEach((b) => b.classList.toggle('is-on', b.dataset.status === state.status));
    $('[data-missing]').classList.toggle('is-on', state.missing);
    const rows = state.missing ? state.rows.filter((r) => r.missing.length) : state.rows;
    if (!rows.length) {
      const none = state.q ? `Nobody matches “${esc(state.q)}”.` : state.missing ? 'Everyone’s details are complete.' : state.status === 'left' ? 'Nobody has left.' : 'Add the people you pay.';
      tbody.innerHTML = `<tr><td colspan="7"><div class="fwp-table__empty"><b>${state.missing && !state.q ? 'All complete' : 'No one here'}</b>${none}${!state.q && !state.missing && state.status !== 'left' ? `<div style="margin-top:14px"><button class="btn btn--primary" type="button" data-add-employee>${icon('plus')}Add employee</button></div>` : ''}</div></td></tr>`;
      $('#empFoot').textContent = '';
      return;
    }
    tbody.innerHTML = rows.map((r) => `<tr class="is-click${r.status === 'left' ? ' is-muted' : ''}" data-id="${r.id}" tabindex="0">
      <td><div class="fwp-who"><span class="fwp-ava fwp-ava--sm" style="--h:${hue(r.name)}">${esc(initials(r.name))}</span><span class="fwp-who__text"><b>${esc(r.name)}</b><small>${esc(r.job_title || FWP.boot.types[r.type] || '')}</small></span></div></td>
      <td class="hide-narrow fwp-mono">${esc(r.no)}</td>
      <td class="t-num fwp-money">${payText(r)}</td>
      <td class="hide-narrow">${esc(B.freq[r.frequency] || r.frequency)}</td>
      <td class="hide-narrow fwp-nowrap">${esc(fmtDay(r.hire_date))}</td>
      <td>${statusFlags(r)}</td>
      <td class="t-actions">${icon('chev-right', 'i--sm')}</td></tr>`).join('');
    const hourly = rows.filter((r) => r.basis === 'hourly').length;
    $('#empFoot').textContent = `${rows.length} ${rows.length === 1 ? 'person' : 'people'}${hourly ? ` · ${hourly} paid by the hour` : ''}`;
  }
  tbody.addEventListener('click', (e) => { const tr = e.target.closest('tr[data-id]'); if (tr) open(+tr.dataset.id); });
  tbody.addEventListener('keydown', (e) => { if (e.key === 'Enter') { const tr = e.target.closest('tr[data-id]'); if (tr) open(+tr.dataset.id); } });
  $$('[data-status]').forEach((b) => b.addEventListener('click', () => { state.status = b.dataset.status; state.missing = false; load(); }));
  $('[data-missing]').addEventListener('click', () => { state.missing = !state.missing; if (state.missing && state.status === 'left') state.status = 'active'; load(); });
  $('#empFreq').addEventListener('change', (e) => { state.frequency = e.target.value; load(); });
  let t = null;
  $('#empSearch').addEventListener('input', (e) => { clearTimeout(t); t = setTimeout(() => { state.q = e.target.value.trim(); load(); }, 220); });
  document.addEventListener('click', (e) => { if (e.target.closest('[data-add-employee]')) open(null); });

  // ----------------------------------------------------------- the dialog
  const field = (name, label, control, hint = '', cls = '') =>
    `<div class="field ${cls}"><label class="label" for="ef-${name}">${label}</label>${control}${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
  const input = (name, value, attrs = '') => `<input class="input" id="ef-${name}" name="${name}" value="${esc(value == null ? '' : value)}" ${attrs}>`;
  const select = (name, options, value, attrs = '') =>
    `<select class="select" id="ef-${name}" name="${name}" ${attrs}>${Object.entries(options).map(([k, v]) => `<option value="${esc(k)}"${String(k) === String(value) ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select>`;
  const affix = (name, value, pre, post, attrs = '') =>
    `<div class="fwp-affix">${pre ? `<span>${pre}</span>` : ''}<input class="input" id="ef-${name}" name="${name}" value="${esc(value)}" ${attrs}>${post ? `<span>${post}</span>` : ''}</div>`;
  const toggle = (name, on, title, sub) =>
    `<label class="switch fwp-switch-row"><input type="checkbox" name="${name}"${on ? ' checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${title}</span><span class="hint">${sub}</span></span></label>`;

  /** SA ID → "1984-07-23" (Luhn-checked), else null. */
  function idBirth(id) {
    const d = String(id || '').replace(/\D/g, '');
    if (d.length !== 13) return null;
    let sum = 0;
    for (let i = 0; i < 13; i++) { let n = +d[12 - i]; if (i % 2) { n *= 2; if (n > 9) n -= 9; } sum += n; }
    if (sum % 10) return null;
    let y = 2000 + +d.slice(0, 2);
    if (y > new Date().getFullYear()) y -= 100;
    const m = +d.slice(2, 4), day = +d.slice(4, 6);
    const dt = new Date(y, m - 1, day);
    if (dt.getMonth() !== m - 1 || dt.getDate() !== day) return null;
    return `${y}-${String(m).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  }
  const ageOf = (iso) => { const b = new Date(iso + 'T00:00:00'), n = new Date(); let a = n.getFullYear() - b.getFullYear(); if (n < new Date(n.getFullYear(), b.getMonth(), b.getDate())) a--; return a; };

  async function open(id) {
    let emp = null;
    let nextNo = '';
    try {
      if (id) emp = (await api.get('employees.php', { op: 'get', id })).employee;
      else nextNo = (await api.get('employees.php', { op: 'new' })).next_number;
    } catch (e) { return toastError(e); }
    editor(emp, nextNo, id ? 'details' : 'details');
  }

  function editor(emp, nextNo, tab) {
    const isNew = !emp;
    const E = emp || { basis: 'salary', frequency: B.defaultFrequency, type: 'permanent', uif_included: true, sdl_included: true, medical_members: 0, items: [], history: [] };
    const left = !isNew && E.termination_date;
    const bankOpts = Object.assign({ '': 'Choose the bank' }, Object.fromEntries(Object.keys(B.banks).map((b) => [b, b])), { __other: 'Another bank…' });
    const knownBank = !E.bank_name || B.banks[E.bank_name] !== undefined;
    const m = modal(`
      <div class="fwm-dialog__head"><h2>${isNew ? 'New employee' : esc(E.name)}</h2>
        ${!isNew ? `<span class="fwp-mono fwp-muted">${esc(E.no)}</span>` : ''}
        <button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwp-tabs" role="tablist">
        ${[['details', 'Details'], ['pay', 'Pay'], ['tax', 'Tax & bank'], ['items', 'Regular items'], ['history', 'History']]
          .filter(([k]) => !isNew || ['details', 'pay', 'tax'].includes(k))
          .map(([k, l]) => `<button class="fwp-tab" type="button" role="tab" data-tab-btn="${k}">${l}${k === 'tax' && E.missing && E.missing.some((x) => x !== 'ID number' && x !== 'date of birth') ? '<span class="fwp-dotwarn" aria-label="incomplete"></span>' : ''}</button>`).join('')}
      </div>
      <form class="fwm-dialog__body" novalidate style="max-height:min(62vh,620px)">
        ${left ? `<div class="fwp-banner fwp-banner--warn">${icon('ip-leave')}<div class="fwp-banner__body"><b>${new Date(E.termination_date) < new Date() ? 'Left' : 'Leaves'} on ${esc(fmtDay(E.termination_date))}</b> — ${esc(E.termination_reason || '')}. Their last run pays up to that day.</div></div>` : ''}
        <div class="fwp-panel" data-tab="details">
          <div class="fwp-form">
            ${field('first_name', 'First name <span class="req">*</span>', input('first_name', E.first_name, 'maxlength="120" autocomplete="off" autofocus'))}
            ${field('last_name', 'Surname <span class="req">*</span>', input('last_name', E.last_name, 'maxlength="120" autocomplete="off"'))}
            ${field('job_title', 'Job title', input('job_title', E.job_title, 'maxlength="120" placeholder="e.g. Site foreman"'), 'Printed on their payslip.')}
            ${field('employee_no', 'Employee number', input('employee_no', E.no || '', `maxlength="32" placeholder="${esc(nextNo || '')}"`), isNew ? 'Leave empty to use ' + esc(nextNo) + '.' : '')}
            ${field('id_number', 'SA ID or passport number', input('id_number', E.id_number, 'maxlength="20" inputmode="numeric" autocomplete="off"'), '<span id="idHint">An SA ID number fills in the date of birth.</span>')}
            ${field('birth_date', 'Date of birth', input('birth_date', E.birth_date, 'type="date"'), 'Decides the extra tax rebates from 65 and 75.')}
            ${field('email', 'Email', input('email', E.email, 'type="email" maxlength="190" autocomplete="off"'), 'Their payslips are emailed here.')}
            ${field('phone', 'Phone', input('phone', E.phone, 'type="tel" maxlength="64"'))}
            ${B.projects.length ? field('default_project_id', 'Usual project', select('default_project_id', Object.assign({ '': 'None' }, Object.fromEntries(B.projects.map((p) => [p.id, p.name]))), E.default_project_id || ''), 'Their pay is counted as this project’s labour cost.', 'is-full') : ''}
          </div>
        </div>
        <div class="fwp-panel" data-tab="pay" hidden>
          <div class="fwp-form">
            <div class="field is-full"><span class="label">Paid</span>
              <div class="seg fwp-seg-wide" role="radiogroup" aria-label="How they are paid">
                <button type="button" data-basis="salary" class="${E.basis !== 'hourly' ? 'is-on' : ''}">A monthly salary</button>
                <button type="button" data-basis="hourly" class="${E.basis === 'hourly' ? 'is-on' : ''}">By the hour</button>
              </div><input type="hidden" name="basis" value="${E.basis === 'hourly' ? 'hourly' : 'salary'}"></div>
            ${field('monthly', 'Salary', affix('monthly', moneyInput(E.monthly), 'R', 'a month', 'inputmode="decimal" autocomplete="off"'), '<span id="perPeriod"></span>', 'is-salary')}
            ${field('hourly', 'Rate per hour', affix('hourly', moneyInput(E.hourly), 'R', 'an hour', 'inputmode="decimal" autocomplete="off"'), '<span id="hourlyHint"></span>')}
            ${field('pay_frequency', 'Pay frequency', select('pay_frequency', B.freq, E.frequency))}
            ${field('employment_type', 'Employment', select('employment_type', B.types, E.type))}
            ${field('hire_date', 'Started on <span class="req">*</span>', input('hire_date', E.hire_date, 'type="date"'), 'Someone who starts mid-period is paid for the days they worked.')}
          </div>
        </div>
        <div class="fwp-panel" data-tab="tax" hidden>
          <div class="fwp-form">
            ${field('tax_number', 'SARS income tax number', input('tax_number', E.tax_number, 'maxlength="12" inputmode="numeric" placeholder="10 digits"'), 'Needed on their IRP5 tax certificate.')}
            ${field('medical_members', 'People on their medical aid', input('medical_members', E.medical_members || 0, 'type="number" min="0" max="20" step="1"'), 'Including them. Gives the medical tax credit (R376 a month each for the first two, R254 for more). 0 = not on medical aid through you.')}
            <div class="fwp-checks is-full">
              ${toggle('uif_included', E.uif_included, 'Pays UIF', '1% from them, 1% from you. Off only for those exempt, e.g. working under 24 hours a month.')}
              ${toggle('sdl_included', E.sdl_included, 'Counts for SDL', 'The 1% Skills Development Levy you pay on their pay.')}
            </div>
            <div class="is-full fwp-section" style="margin:8px 0 0"><h2 style="font-size:19px">Bank account</h2><span class="fwp-section__rule"></span></div>
            ${field('bank_pick', 'Bank', select('bank_pick', bankOpts, knownBank ? (E.bank_name || '') : '__other'))}
            ${field('bank_name', 'Bank name', input('bank_name', E.bank_name, 'maxlength="120"'), '', knownBank ? 'is-hidden-bank' : '')}
            ${field('branch_code', 'Branch code', input('branch_code', E.branch_code, 'maxlength="6" inputmode="numeric"'), 'The universal code is filled in for you — check it against a statement.')}
            ${field('bank_account_no', 'Account number', input('bank_account_no', E.bank_account_no, 'maxlength="16" inputmode="numeric" autocomplete="off"'))}
            ${field('bank_account_type', 'Account type', select('bank_account_type', Object.assign({ '': 'Not sure' }, B.accountTypes), E.bank_account_type || ''))}
          </div>
        </div>
        ${!isNew ? `<div class="fwp-panel" data-tab="items" hidden><div id="itemsBox"></div></div>
        <div class="fwp-panel" data-tab="history" hidden>${historyHtml(E)}</div>` : ''}
      </form>
      <div class="fwm-dialog__foot">
        ${!isNew && !E.termination_date ? `<button class="btn btn--ghost" type="button" data-act="leave">${icon('ip-leave')}End employment</button>` : ''}
        ${!isNew && E.termination_date ? `<button class="btn btn--ghost" type="button" data-act="restore">${icon('undo')}Restore</button>` : ''}
        ${!isNew && E.can_delete ? `<button class="btn btn--ghost btn--danger" type="button" data-act="delete">${icon('trash')}Delete</button>` : ''}
        <span class="grow"></span>
        <button class="btn" type="button" data-close>Close</button>
        <button class="btn btn--primary" type="button" data-act="save">${icon('check')}${isNew ? 'Add employee' : 'Save'}</button>
      </div>`, { wide: true });

    const form = $('form', m);
    const saved = () => JSON.stringify(formData(form));
    let clean = '';
    m._beforeClose = () => {
      if (saved() === clean || m._discard) return true;
      dialog({ title: 'Discard your changes?', body: 'What you changed for ' + esc(E.name || 'this person') + ' has not been saved.', confirm: 'Discard', danger: true })
        .then((ok) => { if (ok) { m._discard = true; closeModal(m); } });
      return false;
    };
    // tabs
    const tabs = $$('[data-tab-btn]', m);
    function show(k) {
      tabs.forEach((b) => { b.classList.toggle('is-on', b.dataset.tabBtn === k); b.setAttribute('aria-selected', String(b.dataset.tabBtn === k)); });
      $$('.fwp-panel', m).forEach((p) => { p.hidden = p.dataset.tab !== k; });
    }
    tabs.forEach((b) => b.addEventListener('click', () => show(b.dataset.tabBtn)));
    show(tab || 'details');
    // ID → birth date
    const idIn = $('[name=id_number]', m), bdIn = $('[name=birth_date]', m), idHint = $('#idHint', m);
    function syncId() {
      const b = idBirth(idIn.value);
      const digits = idIn.value.replace(/\D/g, '');
      if (b) { bdIn.value = b; bdIn.readOnly = true; idHint.innerHTML = `Valid SA ID · born ${esc(fmtDay(b))} · ${ageOf(b)} years old`; }
      else { bdIn.readOnly = false; idHint.innerHTML = digits.length === 13 ? '<span style="color:var(--danger)">That SA ID number does not add up — check the digits.</span>' : 'An SA ID number fills in the date of birth.'; }
    }
    idIn.addEventListener('input', syncId);
    syncId();
    // pay basis and per-period amount
    const basisIn = $('[name=basis]', m);
    function syncPay() {
      const hourly = basisIn.value === 'hourly';
      $('.is-salary', m).hidden = hourly;
      $$('[data-basis]', m).forEach((b) => b.classList.toggle('is-on', b.dataset.basis === basisIn.value));
      const f = $('[name=pay_frequency]', m).value;
      const cents = Math.round(parseFloat(String($('[name=monthly]', m).value).replace(/[\s,]/g, '')) * 100) || 0;
      const per = { monthly: 12, fortnight: 26, weekly: 52 }[f] || 12;
      $('#perPeriod', m).innerHTML = cents && f !== 'monthly' ? `That is <b>${money(Math.round(cents * 12 / per))}</b> a ${esc(B.freqShort[f])} (× 12 ÷ ${per}).` : cents ? `${money(cents * 12)} a year.` : '';
      $('#hourlyHint', m).textContent = hourly ? 'Enter their hours on each pay run.' : 'Optional — used for overtime. Empty: worked out from the salary and the normal hours in Settings.';
    }
    $$('[data-basis]', m).forEach((b) => b.addEventListener('click', () => { basisIn.value = b.dataset.basis; syncPay(); form.dispatchEvent(new Event('input')); }));
    ['monthly', 'pay_frequency'].forEach((n) => $(`[name=${n}]`, m).addEventListener('input', syncPay));
    $('[name=pay_frequency]', m).addEventListener('change', syncPay);
    syncPay();
    // bank pick → name + universal branch code
    const pick = $('[name=bank_pick]', m), bankName = $('[name=bank_name]', m), branch = $('[name=branch_code]', m);
    const nameField = bankName.closest('.field');
    function syncBank(fromUser) {
      const v = pick.value;
      nameField.hidden = v !== '__other';
      if (v !== '__other') bankName.value = v;
      if (fromUser && B.banks[v] && (!branch.value || Object.values(B.banks).includes(branch.value))) branch.value = B.banks[v];
    }
    pick.addEventListener('change', () => syncBank(true));
    syncBank(false);
    pick.removeAttribute('name'); // the bank name travels in bank_name

    if (!isNew) renderItems(m, E);
    clean = saved();

    m.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-act]');
      if (!b) return;
      if (b.dataset.act === 'save') save(b);
      if (b.dataset.act === 'leave') leave();
      if (b.dataset.act === 'restore') restore(b);
      if (b.dataset.act === 'delete') del();
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); save($('[data-act=save]', m)); });
    form.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save($('[data-act=save]', m)); } });

    async function save(btn) {
      const data = formData(form);
      if (isNew === false) data.id = E.id;
      busy(btn, true);
      try {
        const res = await api.post('employees.php', Object.assign({ op: 'save' }, data));
        clean = saved();
        m._discard = true;
        closeModal(m);
        const note = runsNote(res.runs);
        toast((res.created ? res.employee.name + ' added.' : 'Saved.') + (note ? ' ' + note : ''), { timeout: note ? 9000 : 4000 });
        load();
        if (res.created) editor(res.employee, '', 'items');
      } catch (err) {
        if (err.data && err.data.errors) { fieldErrors(form, err.data.errors); toast('Some details need a second look.', { type: 'error' }); }
        else toastError(err);
      } finally { busy(btn, false); }
    }
    async function leave() {
      const d = modal(`<div class="fwm-dialog__head"><h2>End ${esc(E.first_name)}’s employment</h2></div>
        <form class="fwm-dialog__body" novalidate>
          <p class="fwm-dialog__lead">Their last run pays them up to their last day, pro-rated. Their records and payslips stay.</p>
          <div class="fwp-form">
            ${field('termination_date', 'Last working day', `<input class="input" type="date" name="termination_date" id="ef-termination_date" value="${new Date().toISOString().slice(0, 10)}" min="${esc(E.hire_date)}">`)}
            ${field('termination_reason', 'Why they left', select('termination_reason', Object.assign({ '': 'Choose' }, B.leaveReasons), ''))}
            ${field('note', 'Note (optional)', '<input class="input" name="note" id="ef-note" maxlength="150">', '', 'is-full')}
          </div></form>
        <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-go>${icon('ip-leave')}End employment</button></div>`, { width: 520 });
      openModal(d);
      $('[data-go]', d).addEventListener('click', async (ev) => {
        busy(ev.currentTarget, true);
        try {
          const res = await api.post('employees.php', Object.assign({ op: 'terminate', id: E.id }, formData($('form', d))));
          closeModal(d);
          m._discard = true;
          closeModal(m);
          toast(`${E.name}’s employment ends on ${fmtDay(res.employee.termination_date)}.` + (runsNote(res.runs) ? ' ' + runsNote(res.runs) : ''), { timeout: 8000 });
          load();
        } catch (err) {
          if (err.data && err.data.errors) fieldErrors($('form', d), err.data.errors); else toastError(err);
        } finally { busy(ev.currentTarget, false); }
      });
    }
    async function restore(btn) {
      busy(btn, true);
      try {
        const res = await api.post('employees.php', { op: 'reinstate', id: E.id });
        m._discard = true;
        closeModal(m);
        toast(`${E.name} is back on the payroll.` + (runsNote(res.runs) ? ' ' + runsNote(res.runs) : ''));
        load();
      } catch (err) { toastError(err); } finally { busy(btn, false); }
    }
    async function del() {
      const ok = await dialog({ title: `Delete ${E.name}?`, body: 'They have never been paid, so nothing else refers to them. This cannot be undone.', confirm: 'Delete', danger: true });
      if (!ok) return;
      try {
        await api.post('employees.php', { op: 'delete', id: E.id });
        m._discard = true;
        closeModal(m);
        toast(E.name + ' deleted.');
        load();
      } catch (err) { toastError(err); }
    }
    openModal(m);
    if (tab === 'items') setTimeout(() => show('items'), 40);
  }

  function historyHtml(E) {
    if (!E.history.length) return '<p class="fwp-muted">Not paid yet. Their pay runs and payslips will show here.</p>';
    const tone = { prepare: 'info', approved: 'gold', final: 'accent', booked: 'success' };
    const label = { prepare: 'Being prepared', approved: 'Approved', final: 'Finalised', booked: 'In the books' };
    return `<ul class="fwp-list">${E.history.map((h) => `<li>
      <a class="fwp-list__text" href="/payroll/run_view.php?id=${h.run_id}#p${E.id}"><b>${esc(h.name)}</b><small>${esc(h.number)} · paid ${esc(fmtDay(h.pay_date))} · gross ${money(h.gross)} · PAYE ${money(h.paye)}</small></a>
      <span class="fwp-money fwp-strong">${money(h.net)}</span>
      <span class="fwp-pill" data-tone="${tone[h.stage]}">${label[h.stage]}</span>
      <a class="icon-btn icon-btn--sm" href="/payroll/api/payslip.php?run=${h.run_id}&employee=${E.id}" target="_blank" rel="noopener" aria-label="Payslip" data-tip="Payslip">${icon('ip-payslip')}</a></li>`).join('')}</ul>`;
  }

  // ------------------------------------------------- regular items (per person)
  function renderItems(m, E) {
    const box = $('#itemsBox', m);
    const typeLabel = { earning: 'Earning', deduction: 'Deduction', reimbursement: 'Reimbursement', benefit: 'Fringe benefit', contribution: 'Company contribution' };
    const opts = B.items.filter((i) => i.type !== 'earning' || i.name).reduce((acc, i) => { (acc[i.type] = acc[i.type] || []).push(i); return acc; }, {});
    const itemSelect = `<select class="select" data-k="payitem_id" aria-label="Item"><option value="">Choose an item</option>${Object.entries(opts).map(([t, list]) =>
      `<optgroup label="${esc(typeLabel[t] || t)}">${list.map((i) => `<option value="${i.id}">${esc(i.name)}</option>`).join('')}</optgroup>`).join('')}</select>`;
    const list = E.items.length ? `<ul class="fwp-list">${E.items.map((it) => `<li>
        <span class="fwp-list__text"><b>${esc(it.name)}</b><small>${esc(typeLabel[it.type] || it.type)}${it.from ? ' · from ' + esc(fmtDay(it.from)) : ''}${it.to ? ' · until ' + esc(fmtDay(it.to)) : ''}${!it.active ? ' · switched off' : ''}</small></span>
        <span class="fwp-money ${it.type === 'deduction' ? 'is-neg' : ''}">${it.unit === 'percent' ? esc(String(it.percent)) + '% of basic' : (it.type === 'deduction' ? '−' : '') + money(it.amount)}</span>
        <button class="icon-btn icon-btn--sm" type="button" data-rm="${it.id}" aria-label="Remove ${esc(it.name)}">${icon('x')}</button></li>`).join('')}</ul>`
      : '<p class="fwp-muted" style="margin-top:0">Nothing repeats yet. Add allowances or deductions that come back every pay day — medical aid, pension, a travel allowance, a loan repayment.</p>';
    box.innerHTML = `${list}
      <div class="fwp-add-item" style="display:grid;grid-template-columns:minmax(0,1.4fr) auto minmax(0,1fr) auto;gap:8px;align-items:end;margin-top:14px">
        <div class="field">${itemSelect}</div>
        <div class="seg" role="radiogroup" aria-label="Amount or percentage"><button type="button" class="is-on" data-unit="fixed">R</button><button type="button" data-unit="percent">%</button></div>
        <div class="field"><input class="input" data-k="value" inputmode="decimal" placeholder="Amount each pay day" aria-label="Amount"></div>
        <button class="btn btn--primary" type="button" data-add-item>${icon('plus')}Add</button>
      </div>
      <p class="hint" style="margin-top:8px">A percentage is of the basic salary for the period — handy for pension.</p>
      ${B.hasOwnItems || !FWP.boot.admin ? '' : `<div class="fwp-banner" style="margin:12px 0 0">${icon('i-sparkle')}<div class="fwp-banner__body"><b>No pay items yet.</b> Add the usual South African ones — bonus, commission, allowance, pension, provident fund, retirement annuity, medical aid, staff loan — with their SARS codes.</div><button class="fwp-mini" type="button" data-defaults>${icon('plus')}Add them</button></div>`}`;
    const def = $('[data-defaults]', box);
    if (def) def.addEventListener('click', async () => {
      busy(def, true);
      try {
        const res = await api.post('items.php', { op: 'defaults' });
        B.items = res.rows.filter((i) => i.active && i.code !== 'BASIC_SALARY').map((i) => ({ id: i.id, name: i.name, type: i.type }));
        B.hasOwnItems = true;
        renderItems(m, E);
        toast(res.added + ' pay items added.');
      } catch (err) { toastError(err); busy(def, false); }
    });
    let unit = 'fixed';
    $$('[data-unit]', box).forEach((b) => b.addEventListener('click', () => {
      unit = b.dataset.unit;
      $$('[data-unit]', box).forEach((x) => x.classList.toggle('is-on', x === b));
      $('[data-k=value]', box).placeholder = unit === 'fixed' ? 'Amount each pay day' : 'e.g. 7.5';
    }));
    $('[data-add-item]', box).addEventListener('click', async (ev) => {
      const payitem = $('[data-k=payitem_id]', box).value;
      const value = $('[data-k=value]', box).value;
      busy(ev.currentTarget, true);
      try {
        const res = await api.post('employees.php', { op: 'item_save', employee_id: E.id, payitem_id: payitem, unit, amount: value, percent: value });
        E.items = res.items;
        renderItems(m, E);
        const note = runsNote(res.runs);
        toast('Added.' + (note ? ' ' + note : ''));
      } catch (err) {
        toast(err.data && err.data.errors ? Object.values(err.data.errors)[0] : err.message, { type: 'error' });
      } finally { busy(ev.currentTarget, false); }
    });
    $$('[data-rm]', box).forEach((b) => b.addEventListener('click', async () => {
      try {
        const res = await api.post('employees.php', { op: 'item_remove', employee_id: E.id, id: +b.dataset.rm });
        E.items = res.items;
        renderItems(m, E);
        const note = runsNote(res.runs);
        toast('Removed.' + (note ? ' ' + note : ''));
      } catch (err) { toastError(err); }
    }));
  }

  load().then(() => {
    if (params.get('new')) open(null);
    if (params.get('open')) open(+params.get('open'));
    if (params.get('new') || params.get('open')) history.replaceState(null, '', location.pathname);
  });
})();
