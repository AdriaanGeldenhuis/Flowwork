// /payroll/js/runs.js — the list of pay runs and the "New pay run" dialog.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, busy, money, fieldErrors, formData, fmtDay } = FWP;
  const B = FWP.boot;
  const params = new URLSearchParams(location.search);
  const state = { stage: params.get('stage') || 'all', frequency: '' };
  const tone = { prepare: 'info', approved: 'gold', final: 'accent', booked: 'success' };

  async function load() {
    $$('[data-stage]').forEach((b) => b.classList.toggle('is-on', b.dataset.stage === state.stage));
    try {
      const res = await api.get('runs.php', { op: 'list', stage: state.stage, frequency: state.frequency });
      $('#cOpen').textContent = res.counts.open;
      $('#cFinal').textContent = res.counts.final;
      $('#cBooked').textContent = res.counts.booked;
      $('#cAll').textContent = res.counts.all;
      const tb = $('#runRows');
      if (!res.rows.length) {
        tb.innerHTML = `<tr><td colspan="7"><div class="fwp-table__empty"><b>${res.counts.all ? 'None here' : 'No pay runs yet'}</b>${res.counts.all ? 'Try another filter.' : 'Start the first one — everyone is worked out for you.'}
          ${res.counts.all ? '' : `<div style="margin-top:14px"><button class="btn btn--primary" type="button" data-new-run>${icon('plus')}New pay run</button></div>`}</div></td></tr>`;
        return;
      }
      tb.innerHTML = res.rows.map((r) => `<tr class="is-click" data-href="/payroll/run_view.php?id=${r.id}" tabindex="0">
        <td><div class="fwp-who__text"><b>${esc(r.name)}</b><small>${esc(r.number)} · ${esc(B.freq[r.frequency] || r.frequency)}</small></div></td>
        <td class="hide-narrow fwp-nowrap">${esc(r.period)}</td>
        <td class="fwp-nowrap">${esc(fmtDay(r.pay_date))}</td>
        <td class="t-num hide-narrow">${r.people}</td>
        <td class="t-num fwp-money fwp-strong">${money(r.totals.net)}</td>
        <td class="t-num fwp-money hide-narrow">${money(r.totals.cost)}</td>
        <td><span class="fwp-pill" data-tone="${tone[r.stage]}">${esc(r.stage_label)}</span></td></tr>`).join('');
    } catch (e) { toastError(e); }
  }
  $('#runRows').addEventListener('click', (e) => { const tr = e.target.closest('tr[data-href]'); if (tr) location.href = tr.dataset.href; });
  $('#runRows').addEventListener('keydown', (e) => { const tr = e.target.closest('tr[data-href]'); if (tr && e.key === 'Enter') location.href = tr.dataset.href; });
  $$('[data-stage]').forEach((b) => b.addEventListener('click', () => { state.stage = b.dataset.stage; load(); }));
  $('#runFreq').addEventListener('change', (e) => { state.frequency = e.target.value; load(); });
  document.addEventListener('click', (e) => { if (e.target.closest('[data-new-run]')) newRun(); });

  function newRun() {
    let freq = B.byFreq[B.defaultFrequency] || !Object.keys(B.byFreq).length ? B.defaultFrequency : Object.keys(B.byFreq)[0];
    const m = modal(`<div class="fwm-dialog__head"><h2>New pay run</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate>
        <p class="fwm-dialog__lead">Everyone paid on this frequency who works in the period is added and worked out at once — pro-rated when they start or leave part-way. You can add hours, overtime and bonuses next.</p>
        <div class="field"><span class="label">Who is paid</span>
          <div class="seg fwp-seg-wide" role="radiogroup" aria-label="Pay frequency">${Object.entries(B.freq).map(([k, v]) =>
            `<button type="button" data-f="${k}">${esc(v)} <small class="fwp-muted">${B.byFreq[k] || 0}</small></button>`).join('')}</div>
          <input type="hidden" name="frequency"></div>
        <div class="fwp-form" style="margin-top:16px">
          <div class="field is-full"><label class="label" for="rn-name">Name</label><input class="input" id="rn-name" name="name" maxlength="120"></div>
          <div class="field"><label class="label" for="rn-ps">Period from</label><input class="input" type="date" id="rn-ps" name="period_start"></div>
          <div class="field"><label class="label" for="rn-pe">Period to</label><input class="input" type="date" id="rn-pe" name="period_end"></div>
          <div class="field"><label class="label" for="rn-pd">Pay day</label><input class="input" type="date" id="rn-pd" name="pay_date"><div class="hint" id="rn-pdh"></div></div>
          <div class="field"><label class="label" for="rn-notes">Note (optional)</label><input class="input" id="rn-notes" name="notes" maxlength="1000" placeholder="e.g. includes year-end bonuses"></div>
        </div>
        <div class="fwp-banner" id="rn-who" style="margin:16px 0 0"></div>
      </form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button>
        <button class="btn btn--primary" type="button" data-go>${icon('ip-run')}Start the run</button></div>`, { width: 600 });
    const set = (f) => {
      freq = f;
      $$('[data-f]', m).forEach((b) => b.classList.toggle('is-on', b.dataset.f === f));
      $('[name=frequency]', m).value = f;
      const s = B.suggest[f];
      $('[name=name]', m).value = s.name;
      $('[name=period_start]', m).value = s.period_start;
      $('[name=period_end]', m).value = s.period_end;
      $('[name=pay_date]', m).value = s.pay_date;
      who();
    };
    const who = () => {
      const n = B.byFreq[freq] || 0;
      const pd = $('[name=pay_date]', m).value;
      const d = pd ? new Date(pd + 'T00:00:00') : null;
      $('#rn-pdh', m).textContent = d && !isNaN(d) ? ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][d.getDay()] + (d.getDay() === 0 || d.getDay() === 6 ? ' — a weekend' : '') : '';
      $('#rn-who', m).innerHTML = `${icon('i-users')}<div class="fwp-banner__body">${n ? `<b>${n} ${n === 1 ? 'person is' : 'people are'}</b> paid ${esc(B.freq[freq].toLowerCase())}.` : `<b>Nobody is paid ${esc(B.freq[freq].toLowerCase())} yet.</b> <a href="/payroll/employees.php?new=1">Add an employee</a> first, or choose another frequency.`}</div>`;
    };
    $$('[data-f]', m).forEach((b) => b.addEventListener('click', () => set(b.dataset.f)));
    $('[name=pay_date]', m).addEventListener('input', who);
    set(freq);
    $('[data-go]', m).addEventListener('click', async (ev) => {
      const btn = ev.currentTarget;
      busy(btn, true);
      try {
        const res = await api.post('runs.php', Object.assign({ op: 'create' }, formData($('form', m))));
        closeModal(m);
        toast('Run started with ' + res.people + (res.people === 1 ? ' person.' : ' people.'));
        location.href = '/payroll/run_view.php?id=' + res.id;
      } catch (err) {
        if (err.data && err.data.errors) fieldErrors($('form', m), err.data.errors); else toastError(err);
        busy(btn, false);
      }
    });
    openModal(m);
  }

  load().then(() => { if (params.get('new')) { newRun(); history.replaceState(null, '', location.pathname); } });
})();
