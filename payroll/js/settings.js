// /payroll/js/settings.js — the tax tables on the Settings page: each year's
// brackets, rebates, thresholds, medical credits and UIF ceiling, and (for
// admins) a company table for a year Flowwork has not added yet.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, busy, money, moneyInput, fieldErrors, fmtDay } = FWP;
  const B = FWP.boot;
  const box = $('#taxBox');
  let tax = B.tax;

  function render() {
    const nb = $('[data-tax-new]');
    if (nb) nb.hidden = tax.has_next;
    box.innerHTML = tax.years.map((y, i) => {
      const t = y[y.in_use];
      const other = y.in_use === 'company' ? y.flowwork : null;
      return `<details class="fwp-year"${y.current ? ' open' : ''}>
        <summary><b>${esc(y.label)}</b><span class="fwp-muted">${esc(fmtDay(y.from))} – ${esc(fmtDay(y.to))}</span>
          ${y.current ? '<span class="fwp-pill" data-tone="success">This tax year</span>' : ''}
          <span class="fwp-pill fwp-pill--plain" data-tone="${y.in_use === 'company' ? 'gold' : 'muted'}">${y.in_use === 'company' ? 'Your own table' : 'From SARS, kept by Flowwork'}</span>
          <span class="grow"></span>${icon('chev-down')}</summary>
        <div class="fwp-year__body">
          <div><table><thead><tr><th>Taxable income a year</th><th>Tax</th></tr></thead><tbody>
            ${t.brackets.map((b, j) => {
              const next = t.brackets[j + 1];
              const range = next ? `${money(b.from + (j ? 100 : 0), { symbol: true })} – ${money(next.from)}` : `${money(b.from + 100)} and above`;
              return `<tr><td>${range}</td><td>${b.base ? money(b.base) + ' + ' : ''}${b.rate}%${j ? ' of the amount above ' + money(b.from) : ''}</td></tr>`;
            }).join('')}</tbody></table></div>
          <div><dl class="fwp-workings">
            <dt>Primary rebate</dt><dd>${money(t.rebates[0])}</dd><dt>Secondary (65 and older)</dt><dd>${money(t.rebates[1])}</dd><dt>Tertiary (75 and older)</dt><dd>${money(t.rebates[2])}</dd>
            <dt class="is-sum">No tax below — under 65</dt><dd class="is-sum">${money(t.thresholds[0])}</dd><dt>65 to 74</dt><dd>${money(t.thresholds[1])}</dd><dt>75 and older</dt><dd>${money(t.thresholds[2])}</dd>
            <dt class="is-sum">Medical credit, first two people</dt><dd class="is-sum">${money(t.medical[0])} a month each</dd><dt>Each additional dependant</dt><dd>${money(t.medical[1])} a month</dd>
            <dt class="is-sum">UIF ceiling</dt><dd class="is-sum">${money(t.uif_month)} a month</dd></dl>
            ${B.canEdit ? `<div style="display:flex;gap:6px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap">
              ${y.company ? `<button class="fwp-mini" type="button" data-edit="${i}">${icon('pen')}Edit your table</button><button class="fwp-mini fwp-mini--danger" type="button" data-del="${i}">${icon('undo')}Use Flowwork’s</button>`
                : `<button class="fwp-mini" type="button" data-edit="${i}">${icon('pen')}Use your own figures</button>`}</div>` : ''}
            ${other ? '<p class="hint" style="text-align:right">Flowwork also has the SARS table for this year — remove yours to use it.</p>' : ''}
          </div>
        </div></details>`;
    }).join('');
    $$('[data-edit]', box).forEach((b) => b.addEventListener('click', () => { const y = tax.years[+b.dataset.edit]; editor(y.from, y.label, y[y.in_use]); }));
    $$('[data-del]', box).forEach((b) => b.addEventListener('click', async () => {
      const y = tax.years[+b.dataset.del];
      const ok = await dialog({ title: 'Use Flowwork’s table for ' + y.label + '?', body: y.flowwork ? 'Your own figures are removed and Flowwork’s SARS table is used. Runs being prepared use it when worked out again.' : 'Flowwork has no table for this year yet, so runs in it cannot be worked out until one is added.', confirm: 'Remove mine', danger: !y.flowwork });
      if (!ok) return;
      try { tax = (await api.post('settings.php', { op: 'tax_delete', from: y.from })).tax; render(); toast('Back to Flowwork’s table.'); } catch (e) { toastError(e); }
    }));
  }

  function editor(from, label, t) {
    const row = (b) => `<div class="fwp-bracket-row"><div class="fwp-affix"><span>above R</span><input class="input" data-b="from" inputmode="decimal" value="${esc(moneyInput(b.from))}"></div>
      <div class="fwp-affix"><input class="input" data-b="rate" inputmode="decimal" value="${esc(b.rate)}"><span>%</span></div>
      <button class="icon-btn icon-btn--sm" type="button" data-rmb aria-label="Remove">${icon('x')}</button></div>`;
    const m = modal(`<div class="fwm-dialog__head"><h2>Tax table · ${esc(label)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate style="max-height:min(66vh,640px)">
        <p class="fwm-dialog__lead">Copy the figures from SARS’s “Rates of tax for individuals” for ${esc(label)}. Only the income each bracket starts above and its rate are needed — the base amounts are worked out.</p>
        <div class="field" id="bWrap"><span class="label">Brackets</span><div id="bRows">${t.brackets.map(row).join('')}</div>
          <input type="hidden" name="brackets"><button class="fwp-mini" type="button" id="addB" style="margin-top:4px">${icon('plus')}Add a bracket</button></div>
        <div class="fwp-form fwp-form--3" style="margin-top:16px">
          <div class="field"><label class="label" for="tr1">Primary rebate</label><div class="fwp-affix"><span>R</span><input class="input" id="tr1" name="rebate_primary" value="${esc(moneyInput(t.rebates[0]))}" inputmode="decimal"></div></div>
          <div class="field"><label class="label" for="tr2">Secondary (65+)</label><div class="fwp-affix"><span>R</span><input class="input" id="tr2" name="rebate_secondary" value="${esc(moneyInput(t.rebates[1]))}" inputmode="decimal"></div></div>
          <div class="field"><label class="label" for="tr3">Tertiary (75+)</label><div class="fwp-affix"><span>R</span><input class="input" id="tr3" name="rebate_tertiary" value="${esc(moneyInput(t.rebates[2]))}" inputmode="decimal"></div></div>
          <div class="field"><label class="label" for="tm1">Medical credit, first two</label><div class="fwp-affix"><span>R</span><input class="input" id="tm1" name="medical_main" value="${esc(moneyInput(t.medical[0]))}" inputmode="decimal"><span>a month</span></div></div>
          <div class="field"><label class="label" for="tm2">Each additional</label><div class="fwp-affix"><span>R</span><input class="input" id="tm2" name="medical_extra" value="${esc(moneyInput(t.medical[1]))}" inputmode="decimal"><span>a month</span></div></div>
          <div class="field"><label class="label" for="tu">UIF ceiling</label><div class="fwp-affix"><span>R</span><input class="input" id="tu" name="uif_month" value="${esc(moneyInput(t.uif_month))}" inputmode="decimal"><span>a month</span></div></div>
        </div></form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-go>${icon('check')}Save table</button></div>`, { wide: true });
    const rows = $('#bRows', m);
    const bindRm = () => $$('[data-rmb]', rows).forEach((b) => { b.onclick = () => { b.closest('.fwp-bracket-row').remove(); }; });
    bindRm();
    $('#addB', m).addEventListener('click', () => { rows.insertAdjacentHTML('beforeend', row({ from: 0, rate: '' })); bindRm(); });
    $('[data-go]', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      const data = { op: 'tax_save', from, brackets: $$('.fwp-bracket-row', rows).map((r) => ({ from: $('[data-b=from]', r).value, rate: $('[data-b=rate]', r).value })) };
      $$('form [name]', m).forEach((f) => { if (f.name !== 'brackets') data[f.name] = f.value; });
      busy(b, true);
      try { tax = (await api.post('settings.php', data)).tax; closeModal(m); render(); toast('Tax table for ' + label + ' saved.'); }
      catch (e) { if (e.data && e.data.errors) fieldErrors($('form', m), e.data.errors); else toastError(e); busy(b, false); }
    });
    openModal(m);
  }

  const nb = $('[data-tax-new]');
  if (nb) nb.addEventListener('click', () => {
    const latest = tax.years[0];
    editor(tax.next, tax.next_label, latest[latest.in_use]);
  });
  render();
})();
