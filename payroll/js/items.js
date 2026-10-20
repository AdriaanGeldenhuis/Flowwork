// /payroll/js/items.js — pay items: grouped lists, the usual South African
// set in one click, and the add / edit dialog with plain-language tax settings.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, busy, fieldErrors } = FWP;
  const T = FWP.boot.types;
  const app = $('#itemsApp');
  let data = { rows: [], suggested: [], accounts: {} };
  const ORDER = ['earning', 'deduction', 'reimbursement', 'benefit', 'contribution'];
  const KIND_ICON = { earning: 'plus', deduction: 'ip-minus-doc', reimbursement: 'receipt', benefit: 'sparkle', contribution: 'building' };

  async function load() {
    try { data = await api.get('items.php', { op: 'list' }); render(); } catch (e) { toastError(e); }
  }
  function chips(r) {
    const c = [];
    if (r.type === 'earning' || r.type === 'benefit' || r.type === 'contribution') c.push(r.taxable ? '<span class="fwp-flag fwp-flag--info">Taxed</span>' : '<span class="fwp-flag fwp-flag--ok">Not taxed</span>');
    if (r.uif) c.push('<span class="fwp-flag fwp-flag--info">UIF</span>');
    if (r.sdl) c.push('<span class="fwp-flag fwp-flag--info">SDL</span>');
    if (r.type === 'deduction') c.push(r.pre_tax ? '<span class="fwp-flag fwp-flag--ok">Before tax — lowers taxable pay</span>' : '<span class="fwp-flag fwp-flag--info">After tax</span>');
    if (r.type === 'reimbursement') c.push('<span class="fwp-flag fwp-flag--ok">Not taxed — paid back</span>');
    if (r.irregular) c.push('<span class="fwp-flag">Once-off (annual payment)</span>');
    if (r.system) c.push('<span class="fwp-flag">Built in</span>');
    return `<span class="fwp-flags">${c.join('')}</span>`;
  }
  function render() {
    const sug = data.suggested;
    let html = '';
    if (sug.length) {
      html += `<div class="fwp-banner">${icon('sparkle')}<div class="fwp-banner__body"><b>Add the usual South African items in one go:</b> ${sug.map((s) => esc(s.name)).join(', ')}. They come with their SARS source codes and sensible tax settings — change anything afterwards.</div>
        <button class="btn btn--primary btn--sm" type="button" data-defaults>${icon('plus')}Add ${sug.length === 1 ? 'it' : 'all ' + sug.length}</button></div>`;
    }
    ORDER.forEach((type) => {
      const rows = data.rows.filter((r) => r.type === type);
      if (!rows.length) return;
      html += `<section class="fwp-card fwp-card--flush" style="margin-top:16px">
        <div class="fwp-card__head"><span class="fwp-card__icon">${icon(KIND_ICON[type])}</span><div class="fwp-card__title"><h3>${esc(T[type][0])}s</h3><p>${esc(T[type][1])}</p></div></div>
        <div class="fwp-table-wrap"><table class="fwp-table"><thead><tr><th>Item</th><th>How it is treated</th><th class="hide-narrow">SARS code</th><th class="hide-narrow">Account</th><th class="t-num hide-narrow">People</th><th></th></tr></thead><tbody>
        ${rows.map((r) => `<tr class="is-click${r.active ? '' : ' is-muted'}" data-id="${r.id}" tabindex="0">
          <td><div class="fwp-who__text"><b>${esc(r.name)}</b><small class="fwp-mono">${esc(r.code)}${r.active ? '' : ' · switched off'}</small></div></td>
          <td>${chips(r)}</td>
          <td class="hide-narrow fwp-mono">${esc(r.source_code || '—')}</td>
          <td class="hide-narrow">${r.gl ? `<span class="fwp-mono">${esc(r.gl)}</span> <small class="fwp-muted">${esc(data.accounts[r.gl] || '')}</small>` : '<span class="fwp-muted">Salaries &amp; wages</span>'}</td>
          <td class="t-num hide-narrow">${r.people || '—'}</td>
          <td class="t-actions">${icon('chev-right', 'i--sm')}</td></tr>`).join('')}
        </tbody></table></div></section>`;
    });
    app.innerHTML = html;
    $$('tr[data-id]', app).forEach((tr) => {
      tr.addEventListener('click', () => edit(data.rows.find((r) => r.id === +tr.dataset.id)));
      tr.addEventListener('keydown', (e) => { if (e.key === 'Enter') tr.click(); });
    });
    const d = $('[data-defaults]', app);
    if (d) d.addEventListener('click', async () => {
      busy(d, true);
      try { data = Object.assign(data, await api.post('items.php', { op: 'defaults' })); toast(data.added + ' pay items added.'); render(); } catch (e) { toastError(e); busy(d, false); }
    });
  }

  const sw = (name, on, title, sub, show = true) => `<label class="switch fwp-switch-row" data-flag="${name}"${show ? '' : ' hidden'}><input type="checkbox" name="${name}"${on ? ' checked' : ''}><span class="switch__track"></span>
    <span class="switch__text"><span class="switch__title">${title}</span><span class="hint">${sub}</span></span></label>`;

  function edit(r) {
    const isNew = !r;
    const R = r || { type: 'earning', taxable: true, uif: true, sdl: true, active: true, name: '', code: '', source_code: '', gl: '' };
    const accounts = Object.entries(data.accounts || {}).sort(([a], [b]) => a.localeCompare(b));
    const m = modal(`<div class="fwm-dialog__head"><h2>${isNew ? 'New pay item' : esc(R.name)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate style="max-height:min(66vh,640px)">
        ${R.system ? `<div class="fwp-banner">${icon('info')}<div class="fwp-banner__body">${esc(R.name)} is built in: it is always taxed and counts for UIF and SDL. You can rename it and choose its account.</div></div>` : ''}
        <div class="fwp-form">
          <div class="field"><label class="label" for="pi-name">Name on the payslip <span class="req">*</span></label><input class="input" id="pi-name" name="name" maxlength="120" value="${esc(R.name)}" autofocus></div>
          <div class="field"><label class="label" for="pi-code">Code</label><input class="input fwp-mono" id="pi-code" name="code" maxlength="32" value="${esc(R.code)}" ${R.system ? 'disabled' : ''} placeholder="Made from the name"></div>
        </div>
        <div class="field" style="margin-top:14px"><span class="label">Kind</span>
          <div class="fwp-kinds" style="display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))">${ORDER.map((k) => `<label class="fwp-kind" style="display:flex;flex-direction:column;gap:3px;padding:11px 13px;border-radius:13px;border:1px solid var(--line-strong);background:var(--surface);cursor:pointer">
            <span style="display:flex;gap:8px;align-items:center"><input type="radio" name="type" value="${k}"${R.type === k ? ' checked' : ''}${R.system || (!isNew && R.used) ? ' disabled' : ''}><b style="font-weight:600">${esc(T[k][0])}</b></span><small class="fwp-muted" style="font-size:11.5px;line-height:1.45">${esc(T[k][1])}</small></label>`).join('')}</div>
          ${!isNew && R.used && !R.system ? '<div class="hint">It is already on payslips, so its kind stays the same.</div>' : ''}</div>
        <div class="fwp-checks" style="margin-top:14px">
          ${sw('taxable', R.taxable, 'Taxed through PAYE', 'Most earnings are. Off only for amounts SARS does not tax.')}
          ${sw('uif', R.uif, 'Counts for UIF', 'Part of the pay UIF is worked out on.')}
          ${sw('sdl', R.sdl, 'Counts for SDL', 'Part of the pay the Skills Development Levy is worked out on.')}
          ${sw('irregular', R.irregular, 'Once-off — a bonus or annual payment', 'Taxed on top of the year’s pay instead of as if it came every month, so the tax is right.')}
          ${sw('pre_tax', R.pre_tax, 'Lowers taxable pay', 'For pension, provident fund and retirement annuity contributions (up to 27.5% of pay).')}
          ${R.system ? '' : sw('active', R.active, 'Can be used', 'Switched off items stay on old payslips but can no longer be added.')}
        </div>
        <div class="fwp-form" style="margin-top:14px">
          <div class="field"><label class="label" for="pi-src">SARS source code</label><input class="input fwp-mono" id="pi-src" name="source_code" maxlength="4" inputmode="numeric" value="${esc(R.source_code)}" list="srcCodes" placeholder="e.g. 3601">
            <datalist id="srcCodes"><option value="3601">Income / salary</option><option value="3605">Annual payment / bonus</option><option value="3606">Commission</option><option value="3701">Travel allowance</option>
            <option value="3713">Other allowances</option><option value="3801">General fringe benefits</option><option value="3810">Medical aid (company share)</option><option value="4001">Pension fund</option>
            <option value="4003">Provident fund</option><option value="4005">Medical aid</option><option value="4006">Retirement annuity</option></datalist>
            <div class="hint">Where it shows on the IRP5 tax certificate.</div></div>
          <div class="field"><label class="label" for="pi-gl">Finance account</label><select class="select" id="pi-gl" name="gl"><option value="">${R.type === 'deduction' || R.type === 'contribution' ? 'Salaries & wages payable' : 'Salaries & wages'}</option>
            ${accounts.map(([c, n]) => `<option value="${esc(c)}"${c === R.gl ? ' selected' : ''}>${esc(c)} · ${esc(n)}</option>`).join('')}</select>
            <div class="hint" id="glHint"></div></div>
        </div>
      </form>
      <div class="fwm-dialog__foot">${!isNew && !R.system ? `<button class="btn btn--ghost btn--danger" type="button" data-rm>${icon('trash')}${R.used || R.people ? 'Switch off' : 'Delete'}</button>` : ''}<span class="grow"></span>
        <button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-go>${icon('check')}${isNew ? 'Add item' : 'Save'}</button></div>`, { wide: true });
    const form = $('form', m);
    const sync = () => {
      const k = ($('[name=type]:checked', form) || {}).value || R.type;
      const show = { taxable: ['earning', 'benefit', 'contribution'], uif: ['earning', 'benefit'], sdl: ['earning', 'benefit'], irregular: ['earning'], pre_tax: ['deduction'] };
      Object.entries(show).forEach(([f, kinds]) => { const el = $(`[data-flag="${f}"]`, form); if (el) el.hidden = R.system ? true : !kinds.includes(k); });
      $$('.fwp-kind', form).forEach((l) => { const on = $('input', l).checked; l.style.borderColor = on ? 'var(--gold)' : ''; l.style.background = on ? 'var(--gold-soft)' : ''; });
      $('#glHint', form).textContent = { earning: 'The expense account it is paid from.', reimbursement: 'The expense account the cost belongs to.', deduction: 'Who it is owed to, e.g. Medical Aid Payable.', contribution: 'Who it is owed to; the cost goes to salaries & wages.', benefit: 'Benefits are not paid out, so nothing is posted.' }[k];
    };
    form.addEventListener('change', sync);
    sync();
    $('[data-go]', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      const d = { op: 'save', id: R.id || 0 };
      $$('[name]', form).forEach((f) => { if (f.type === 'radio') { if (f.checked) d[f.name] = f.value; } else d[f.name] = f.type === 'checkbox' ? (f.checked ? 1 : 0) : f.value; });
      if (R.system) d.type = R.type;
      if (!d.type) d.type = R.type;
      busy(b, true);
      try {
        data = Object.assign(data, await api.post('items.php', d));
        closeModal(m);
        toast(isNew ? 'Pay item added.' : 'Saved.');
        render();
      } catch (e) { if (e.data && e.data.errors) fieldErrors(form, e.data.errors); else toastError(e); busy(b, false); }
    });
    const rm = $('[data-rm]', m);
    if (rm) rm.addEventListener('click', async () => {
      const used = R.used || R.people;
      const ok = await dialog({ title: used ? `Switch off ${R.name}?` : `Delete ${R.name}?`, body: used ? 'It stays on old payslips, and is taken off the people who get it every pay day.' : 'It has never been used, so it is removed completely.', confirm: used ? 'Switch off' : 'Delete', danger: true });
      if (!ok) return;
      try { data = Object.assign(data, await api.post('items.php', { op: 'remove', id: R.id })); closeModal(m); toast(data.result === 'deleted' ? 'Deleted.' : 'Switched off.'); render(); } catch (e) { toastError(e); }
    });
    openModal(m);
  }
  document.addEventListener('click', (e) => { if (e.target.closest('[data-new-item]')) edit(null); });
  load();
})();
