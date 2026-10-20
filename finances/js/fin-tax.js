// /finances/js/fin-tax.js — VAT (periods, the VAT201, adjustments, filing,
// paying SARS), fixed assets, payroll in the books and budgets.
(function () {
  'use strict';
  const F = window.FIN;
  const A = F.app;
  const { $, $$, esc, icon, api, toast, toastError, popover, dialog, BOOT, L, lower, can, money, fmtDate, relDay, relTime } = F;
  const uid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
  const range = (a, b) => `${fmtDate(a, 'always')} – ${fmtDate(b, 'always')}`;
  const after = () => { A.refresh(); F.refreshBoot().catch(() => {}); };
  const viewSeg = (views, current, route) => `<div class="seg" role="tablist" aria-label="View">${views.map(([k, l]) => `<button type="button" role="tab" data-view="${k}" aria-selected="${current === k}" class="${current === k ? 'is-on' : ''}">${esc(l)}</button>`).join('')}</div>`;
  const bindViewSeg = (root, route) => $$('[data-view]', root).forEach((b) => b.addEventListener('click', () => A.go(route, { view: b.dataset.view })));

  // ================================================================ VAT
  async function renderVat(ctx) {
    const p = ctx.param;
    A.head(BOOT.company, L('vat'), `<button class="btn btn--sm btn--ghost" type="button" id="vRange">${icon('if-vat')}<span>VAT201 for any dates</span></button>
      ${can('write') ? `<button class="btn btn--primary" type="button" id="vNew">${icon('plus')}<span>New period</span></button>` : ''}`);
    A.el.view.innerHTML = F.skel(5, 60);
    const d = await api.get('vat.php', { op: 'periods' });
    if (!ctx.isCurrent()) return;
    $('#vRange').addEventListener('click', () => rangeDialog());
    const nb = $('#vNew');
    if (nb) nb.addEventListener('click', () => newPeriod(d.suggest));
    const rows = d.rows;
    const todo = rows.filter((r) => ['open', 'prepared', 'adjusted'].includes(r.status));
    const owing = rows.filter((r) => r.status === 'filed' && r.to_settle > 0);
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-banner fwf-banner--info">${icon('info')}<span>${d.basis === 'payments' ? 'You account for VAT on the <b>payments basis</b>: output tax when customers pay, input tax when you pay.' : 'You account for VAT on the <b>invoice basis</b>: output tax when you invoice, input tax when you are billed.'}
        Returns are due on the last business day of the month after the period.${can('admin') ? ' <a href="/finances/settings.php#books">Change in settings</a>.' : ''}</span></div>
      ${rows.length ? `<div class="fwf-table-wrap can-card"><table class="fwf-table"><thead><tr><th>Period</th><th>Status</th><th>Due</th><th class="r">Output tax</th><th class="r">Input tax</th><th class="r">To SARS</th><th></th></tr></thead>
        <tbody>${rows.map((r) => `<tr class="fwf-row" tabindex="0" data-open="${r.id}">
          <td><b>${esc(range(r.start, r.end))}</b>${r.basis === 'payments' ? ' <small class="fwf-muted">payments basis</small>' : ''}</td>
          <td>${F.pill(r.status)}${r.changed ? ` <span class="fwf-pill" data-tone="warning" title="Documents changed since it was prepared">changed</span>` : ''}</td>
          <td class="fwf-nowrap">${['filed', 'paid'].includes(r.status) ? `<span class="fwf-muted">filed ${esc(fmtDate(String(r.filed_at || '').slice(0, 10), 'always'))}</span>` : r.late ? `<span class="fwf-neg">${esc(fmtDate(r.due, 'always'))} · late</span>` : esc(fmtDate(r.due, 'always')) + ' · ' + esc(relDay(r.due))}</td>
          <td class="r">${esc(F.group(r.figures.output))}</td><td class="r">${esc(F.group(r.figures.input))}</td>
          <td class="r"><b class="${r.figures.net < 0 ? 'fwf-pos' : ''}">${esc(money(Math.abs(r.figures.net)))}</b><div class="fwf-muted" style="font-size:11px">${r.figures.net < 0 ? 'refund' : r.figures.net > 0 ? 'payable' : 'nil'}${r.status === 'filed' && r.to_settle > 0 ? ' · ' + esc(money(r.to_settle)) + ' to settle' : ''}</div></td>
          <td class="r">${r.status === 'open' && can('write') ? `<button class="fwf-mini" type="button" data-prep="${r.id}">${icon('if-approve')}Prepare</button>` : ''}</td></tr>`).join('')}</tbody></table></div>
        <div class="fwf-cards">${rows.map((r) => `<div class="fwf-card" data-open="${r.id}" tabindex="0"><span class="fwf-type">${icon('if-vat')}</span><span class="fwf-card__text"><b>${esc(range(r.start, r.end))}</b><small>${r.late ? 'Late · was due ' : 'Due '}${esc(fmtDate(r.due, 'always'))}</small></span>
          <span class="fwf-card__side">${esc(money(r.figures.net))}${F.pill(r.status)}</span></div>`).join('')}</div>`
        : F.emptyState('if-vat', 'No VAT periods yet', 'Add your VAT periods (monthly or every two months, as SARS registered you). Each one is prepared, checked, filed and paid from here.',
          can('write') ? `<button class="btn btn--primary" type="button" data-new>${icon('plus')}Add the first period</button>` : '')}
      ${todo.length || owing.length ? `<p class="fwf-hint">${todo.length ? `${todo.length} return${todo.length === 1 ? '' : 's'} to prepare or file. ` : ''}${owing.length ? `${owing.length} filed return${owing.length === 1 ? '' : 's'} still to pay or receive.` : ''}</p>` : ''}</div>`;
    A.bindRows(A.el.view, (id) => openPeriod(+id));
    $$('[data-prep]', A.el.view).forEach((b) => b.addEventListener('click', () => prepare(+b.dataset.prep)));
    const fb = $('[data-new]', A.el.view);
    if (fb) fb.addEventListener('click', () => newPeriod(d.suggest));
    if (p('id') && !ctx.keep) openPeriod(+p('id'));
    if (p('new') && can('write')) { A.setParams({ new: '' }); newPeriod(d.suggest); }
  }

  async function prepare(id, s) {
    if (!(await dialog({ title: 'Prepare this return?', body: 'The VAT201 figures are worked out from the books and kept as the prepared return. Documents dated in the period can still change until you file; you will see when they do.', confirm: 'Prepare' }))) return;
    try { const d = await api.post('vat.php', { op: 'prepare', id }); toast('Return prepared'); if (s) paintPeriod(s, d); after(); } catch (e) { toastError(e); }
  }

  function newPeriod(sug) {
    F.formDialog({
      title: 'New VAT period', submit: 'Add period',
      html: `<p class="fwm-dialog__lead">Use the dates SARS gave you (category A/B: two months, C: monthly).</p>
        <div class="fwf-grid2"><div class="field"><label class="label" for="vpS">First day<span class="req">*</span></label><input class="input" id="vpS" name="start" type="date" value="${esc(sug.start)}"></div>
        <div class="field"><label class="label" for="vpE">Last day<span class="req">*</span></label><input class="input" id="vpE" name="end" type="date" value="${esc(sug.end)}"></div></div>`,
      onSubmit: async (f) => {
        const r = await api.post('vat.php', { op: 'create', start: f.start.value, end: f.end.value });
        toast('VAT period added');
        A.go('vat', { id: r.id });
      },
    });
  }

  async function rangeDialog() {
    const [from, to] = F.periodRange('quarter');
    const dlg = F.formDialog({
      title: 'VAT201 for any dates', submit: 'Work it out', wide: true,
      html: `<div class="fwf-grid3"><div class="field"><label class="label" for="vrF">From</label><input class="input" id="vrF" name="from" type="date" value="${esc(from)}"></div>
        <div class="field"><label class="label" for="vrT">To</label><input class="input" id="vrT" name="to" type="date" value="${esc(to)}"></div>
        <div class="field"><label class="label" for="vrB">Basis</label><select class="select" id="vrB" name="basis"><option value="">As in settings</option><option value="invoice">Invoice</option><option value="payments">Payments</option></select></div></div>
        <div id="vrOut"></div>`,
      onSubmit: async (f) => {
        const d = await api.get('vat.php', { op: 'range', from: f.from.value, to: f.to.value, basis: f.basis.value });
        $('#vrOut', dlg.m).innerHTML = `<p class="fwf-hint">${esc(range(d.from, d.to))} · ${esc(d.basis)} basis</p>${boxesTable(d.boxes)}`;
        return false;
      },
    });
  }

  function boxesTable(boxes) {
    const sec = (side, title) => `<tr class="is-group"><td colspan="3">${esc(title)}</td></tr>${boxes.filter((b) => b.side === side).map((b) => `<tr class="${b.total ? 'is-total' : ''}${b.side === 'net' ? ' is-grand' : ''}">
      <td class="fwf-nowrap"><span class="fwf-code">${esc(b.box)}</span></td><td>${esc(b.label)}</td><td class="r">${b.side === 'check' && b.value ? `<span class="fwf-neg">${esc(F.group(b.value))}</span>` : esc(F.group(b.side === 'net' ? Math.abs(b.value) : b.value))}</td></tr>`).join('')}`;
    return `<table class="fwf-table fwf-vat201"><tbody>${sec('output', 'Output tax (sales)')}${sec('input', 'Input tax (purchases)')}${sec('net', 'Result')}${boxes.some((b) => b.side === 'check' && b.value) ? sec('check', 'To check') : ''}</tbody></table>`;
  }

  async function openPeriod(id) {
    const s = F.sheet({ title: 'VAT period', sub: 'Loading…', html: F.skel(6, 36), wide: true, onClose: () => { if (A.param('id')) A.setParams({ id: '' }); } });
    A.setParams({ id });
    try { paintPeriod(s, await api.get('vat.php', { op: 'period', id })); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); }
  }
  function paintPeriod(s, d) {
    const p = d.period;
    const id = p.id;
    const refresh = async (msg) => { if (msg) toast(msg); try { paintPeriod(s, await api.get('vat.php', { op: 'period', id })); } catch (e) { toastError(e); } A.refresh(); F.refreshBoot().catch(() => {}); };
    const post = async (op, extra, msg) => { try { paintPeriod(s, await api.post('vat.php', { op, id, ...extra })); toast(msg); A.refresh(); F.refreshBoot().catch(() => {}); } catch (e) { toastError(e); } };
    $('h2', s).textContent = range(p.start, p.end);
    $('.fwf-eyebrow', s).textContent = `${L('vat')} · ${d.basis} basis${d.company.vat_number ? ' · VAT no. ' + d.company.vat_number : ''}`;
    const st = p.status;
    $('.fwf-sheet__acts', s).innerHTML = `${F.pill(st)}
      ${can('write') && st === 'open' ? `<button class="btn btn--sm btn--primary" type="button" data-a="prepare">${icon('if-approve')}Prepare</button>` : ''}
      ${can('write') && ['prepared', 'adjusted'].includes(st) ? `<button class="btn btn--sm" type="button" data-a="adjust">${icon('if-sliders')}Adjust</button>` : ''}
      ${can('admin') && ['prepared', 'adjusted'].includes(st) ? `<button class="btn btn--sm btn--primary" type="button" data-a="file">${icon('if-seal')}File</button>` : ''}
      ${can('write') && st === 'filed' && p.to_settle > 0 ? `<button class="btn btn--sm btn--primary" type="button" data-a="pay">${icon('if-cash')}${p.figures.net < 0 ? 'Refund received' : 'Pay SARS'}</button>` : ''}
      <button class="icon-btn" type="button" data-a="more" aria-label="More actions">${icon('more-v')}</button>`;
    const net = p.figures.net;
    $('.fwf-sheet__body', s).innerHTML = `
      ${p.changed ? `<div class="fwf-banner fwf-banner--warn">${icon('alert')}<span>Documents in this period changed since it was prepared: Box 10 was <b>${esc(money(p.stored.net))}</b>, it is now <b>${esc(money(p.live.net))}</b>. Filing uses the new figures.</span></div>` : ''}
      ${p.filed_differs ? `<div class="fwf-banner fwf-banner--warn">${icon('alert')}<span>Something dated in this filed period changed after filing (now ${esc(money(p.live.net))}, filed ${esc(money(p.stored.net))}). Correct it in the next return.</span></div>` : ''}
      ${d.earlier_open && ['prepared', 'adjusted'].includes(st) ? `<div class="fwf-banner fwf-banner--info">${icon('info')}<span>The period ending ${esc(fmtDate(d.earlier_open, 'always'))} must be filed first.</span></div>` : ''}
      ${d.legacy_control_cents ? `<div class="fwf-banner fwf-banner--warn">${icon('info')}<span>An older adjustment in this period put <b>${esc(money(d.legacy_control_cents / 100))}</b> on VAT Control as its other side, so VAT Control differs from Box 10 by that much. Ask your accountant for a correcting journal; new adjustments ask for the right account.</span></div>` : ''}
      ${p.late ? `<div class="fwf-banner fwf-banner--danger">${icon('alert')}<span>This return was due on ${esc(fmtDate(p.due, 'always'))}.</span></div>` : ''}
      <div class="fwf-stats">
        <div class="fwf-stat"><span>Output tax · Box 5</span><b>${esc(money(p.figures.output))}</b><small>on sales</small></div>
        <div class="fwf-stat"><span>Input tax · Box 9</span><b>${esc(money(p.figures.input))}</b><small>on purchases</small></div>
        <div class="fwf-stat ${net > 0 ? 'is-warn' : ''}"><span>${net < 0 ? 'Refund · Box 10' : 'To SARS · Box 10'}</span><b>${esc(money(Math.abs(net)))}</b><small>${['filed', 'paid'].includes(st) ? (p.to_settle > 0 ? esc(money(p.to_settle)) + ' still to settle' : 'settled') : 'due ' + esc(fmtDate(p.due, 'always'))}</small></div>
      </div>
      <div class="fwf-sheet__sec"><h4>${icon('if-vat')}VAT201<small>${esc(d.company.name)}</small></h4>${boxesTable(d.boxes)}</div>
      ${d.journals.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-journal')}In the books</h4><div class="fwf-rows">${d.journals.map((j) => `<div class="fwf-rowlink${j.reversed ? ' is-muted' : ''}">
        <span class="fwf-code">${esc({ adjustment: 'ADJ', settlement: 'FILE', payment: 'PAY' }[j.kind] || '#')}</span><span class="fwf-rows__text"><b><a href="#journals?id=${j.id}" data-j="${j.id}">${esc(j.reference || '#' + j.id)}</a> · ${esc(money(j.amount))}</b><small>${esc(fmtDate(j.date, 'always'))} · ${esc(j.description)}${j.reversed ? ' · undone' : ''}${j.reversal ? ' · reversal' : ''}</small></span>
        ${can('write') && !j.reversed && !j.reversal && ((j.kind === 'adjustment' && ['prepared', 'adjusted'].includes(st)) || j.kind === 'payment') ? `<button class="fwf-mini" type="button" data-undo="${j.id}" data-kind="${j.kind}">${icon('undo')}Undo</button>` : '<span></span>'}</div>`).join('')}</div></div>` : ''}
      <div class="fwf-sheet__sec"><h4>${icon('pen')}Notes</h4><textarea class="textarea" id="vNotes" rows="2" maxlength="2000" placeholder="Submission reference, who checked it…" ${can('write') ? '' : 'disabled'}>${esc(p.notes)}</textarea></div>`;
    $$('[data-j]', s).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
    const notes = $('#vNotes', s);
    if (notes && can('write')) notes.addEventListener('change', async () => { try { await api.post('vat.php', { op: 'notes', id, notes: notes.value }); toast('Notes saved'); } catch (e) { toastError(e); } });
    $$('[data-undo]', s).forEach((b) => b.addEventListener('click', async () => {
      const adj = b.dataset.kind === 'adjustment';
      if (!(await dialog({ title: adj ? 'Undo this adjustment?' : 'Undo this payment?', body: adj ? 'A reversal is posted on the last day of the period and the figures are worked out again.' : 'The payment journal is reversed and the period goes back to filed.', confirm: 'Undo', danger: true }))) return;
      post(adj ? 'undo_adjust' : 'undo_pay', { journal_id: +b.dataset.undo }, adj ? 'Adjustment undone' : 'Payment undone');
    }));
    $$('[data-a]', s).forEach((b) => b.addEventListener('click', async () => {
      const a = b.dataset.a;
      if (a === 'prepare') {
        if (!(await dialog({ title: 'Prepare this return?', body: 'The VAT201 figures are worked out from the books and kept as the prepared return.', confirm: 'Prepare' }))) return;
        post('prepare', {}, 'Return prepared');
      }
      if (a === 'adjust') adjustDialog(p, (r) => { paintPeriod(s, r); toast('Adjustment posted'); A.refresh(); });
      if (a === 'pay') payDialog(p, (r) => { paintPeriod(s, r); toast(p.figures.net < 0 ? 'Refund recorded' : 'Payment recorded'); A.refresh(); F.refreshBoot().catch(() => {}); });
      if (a === 'file') {
        if (!(await dialog({ title: 'File this return?', body: `<p>Box 10: <b>${esc(money(Math.abs(net)))}</b> ${net < 0 ? 'refundable by' : 'payable to'} SARS.</p><p>Output and input tax for the period move to VAT Control, and the books are locked through ${esc(fmtDate(p.end, 'always'))}. Submit the same figures on eFiling.</p>`, confirm: 'File return' }))) return;
        post('file', {}, 'Return filed and period locked');
      }
      if (a === 'more') popover(b, `<a class="fwm-pop__item" href="${esc(api.url('vat.php', { op: 'audit', id, format: 'csv' }))}" download>${icon('download')}Audit file (every line, CSV)</a>
        <button class="fwm-pop__item" type="button" data-v="print">${icon('print')}Print the VAT201</button>
        <a class="fwm-pop__item" href="#journals?source=vat">${icon('if-journal')}All VAT journals</a>
        ${can('write') && st === 'prepared' ? `<button class="fwm-pop__item" type="button" data-v="reopen">${icon('undo')}Back to open</button>` : ''}
        ${can('write') && st === 'open' ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" type="button" data-v="delete">${icon('trash')}Remove this period</button>` : ''}`, {
        align: 'end',
        onClick: async (it) => {
          const v = it.dataset.v;
          if (v === 'print') window.print();
          if (v === 'reopen') post('reopen', {}, 'Back to open');
          if (v === 'delete') {
            if (!(await dialog({ title: 'Remove this period?', body: 'Nothing is posted against it. You can add it again with other dates.', confirm: 'Remove', danger: true }))) return;
            try { await api.post('vat.php', { op: 'delete', id }); toast('Period removed'); F.closeSheet(); A.render(); } catch (e) { toastError(e); }
          }
        },
      });
    }));
  }

  function adjustDialog(p, done) {
    const key = uid();
    const line = (i) => `<div class="fwf-grid3" data-l="${i}" style="align-items:end">
      <div class="field"><label class="label">Tax</label><select class="select" name="side"><option value="output">Output tax (Box 4)</option><option value="input">Input tax (Box 6)</option></select></div>
      <div class="field"><label class="label">Amount</label><input class="input fwf-amount" name="amount" inputmode="decimal" placeholder="100.00 or -100.00"></div>
      <div class="field"><label class="label">Why</label><input class="input" name="memo" maxlength="200" placeholder="Change in use: vehicle"></div></div>`;
    const dlg = F.formDialog({
      title: 'Adjust the return', submit: 'Post adjustment', wide: true,
      html: `<p class="fwm-dialog__lead">A positive amount raises that box, a negative amount lowers it. It is posted on ${esc(fmtDate(p.end, 'always'))}.</p>
        <div id="adjLines">${line(0)}</div><button class="btn btn--sm btn--ghost" type="button" id="adjAdd">${icon('plus')}Another line</button>
        <div class="fwf-formsec"><h4>Other side<small>The expense, asset or income the adjustment is about</small></h4>
          <div class="field"><input class="input" id="adjContra" placeholder="Account code or name" aria-label="Account for the other side"><div class="fwf-hint">Not VAT Control: that would make VAT Control differ from what you owe SARS. Not needed when the lines balance each other.</div></div>
          <div class="field"><label class="label" for="adjMemo">Description</label><input class="input" id="adjMemo" name="memo_all" maxlength="200"></div></div>
        <div class="fwf-balance" id="adjBal"></div>`,
      onSubmit: async (f) => {
        const lines = $$('[data-l]', dlg.m).map((r) => ({ side: $('[name=side]', r).value, amount: F.parseMoney($('[name=amount]', r).value) || 0, memo: $('[name=memo]', r).value.trim() })).filter((l) => l.amount);
        if (!lines.length) throw new Error('Enter at least one amount.');
        const c = picker.get();
        const r = await api.post('vat.php', { op: 'adjust', id: p.id, lines, contra_account_id: c ? c.id : 0, memo: f.memo_all.value.trim(), key });
        done(r);
      },
    });
    const picker = F.accountPicker($('#adjContra', dlg.m), { filter: (a) => !a.control && a.subtype !== 'vat_control' });
    const calc = () => {
      let out = 0, inp = 0;
      $$('[data-l]', dlg.m).forEach((r) => { const v = F.parseMoney($('[name=amount]', r).value) || 0; if ($('[name=side]', r).value === 'output') out += v; else inp += v; });
      const diff = Math.round((inp - out) * 100) / 100;
      $('#adjBal', dlg.m).innerHTML = `<span>Box 5 <b>${esc(money(p.figures.output + out))}</b></span><span>Box 9 <b>${esc(money(p.figures.input + inp))}</b></span><span>Box 10 <b>${esc(money(p.figures.net + out - inp))}</b></span><span class="fwf-grow"></span>${diff ? `<span>Other side ${diff > 0 ? 'credit' : 'debit'} <b>${esc(money(Math.abs(diff)))}</b></span>` : '<span>Balances by itself</span>'}`;
    };
    $('#adjAdd', dlg.m).addEventListener('click', () => { $('#adjLines', dlg.m).insertAdjacentHTML('beforeend', line($$('[data-l]', dlg.m).length)); calc(); });
    dlg.m.addEventListener('input', calc);
    calc();
  }

  function payDialog(p, done) {
    const key = uid();
    const refund = p.figures.net < 0;
    F.formDialog({
      title: refund ? 'Refund from SARS' : 'Pay SARS', submit: refund ? 'Record refund' : 'Record payment',
      html: `<p class="fwm-dialog__lead">${refund ? 'Money in' : 'Money out'} for ${esc(range(p.start, p.end))}: Dr ${refund ? 'bank' : 'VAT Control'}, Cr ${refund ? 'VAT Control' : 'bank'}. Then match the bank line to it under “In the books”.</p>
        <div class="fwf-grid2"><div class="field"><label class="label" for="vpBank">${refund ? 'Into' : 'From'}<span class="req">*</span></label><select class="select" id="vpBank" name="bank">${F.bankOptions((BOOT.banks[0] || {}).id)}</select></div>
        <div class="field"><label class="label" for="vpDate">Date<span class="req">*</span></label><input class="input" id="vpDate" name="date" type="date" max="${esc(BOOT.today)}" value="${esc(BOOT.today)}"></div>
        <div class="field"><label class="label" for="vpAmt">Amount</label><input class="input fwf-amount" id="vpAmt" name="amount" inputmode="decimal" value="${esc(p.to_settle.toFixed(2))}"><div class="fwf-hint">Penalties and interest are separate expenses — categorise those from the bank line.</div></div>
        <div class="field"><label class="label" for="vpRef">Reference</label><input class="input" id="vpRef" name="reference" maxlength="100" placeholder="PRN from eFiling"></div></div>`,
      onSubmit: async (f) => {
        if (!f.bank.value) throw new Error('Add a bank account under Bank first.');
        done(await api.post('vat.php', { op: 'pay', id: p.id, bank_account_id: +f.bank.value, date: f.date.value, amount: f.amount.value, reference: f.reference.value.trim(), key }));
      },
    });
  }

  // ======================================================== fixed assets
  const METHOD = { straight_line: 'Straight line', declining_balance: 'Declining balance' };
  async function renderAssets(ctx) {
    const p = ctx.param;
    const view = ['register', 'runs'].includes(p('view')) ? p('view') : 'register';
    const status = p('status', 'active');
    A.head(BOOT.company, L('assets'), `${A.exportBtn(api.url('assets.php', { op: 'list', status, q: p('q'), format: 'csv' }))}
      ${can('write') ? `<button class="btn btn--sm btn--ghost" type="button" id="faRun">${icon('if-hourglass')}<span>Run depreciation</span></button>
      <button class="btn btn--primary" type="button" id="faNew">${icon('plus')}<span>New ${esc(lower('asset'))}</span></button>` : ''}`);
    const tools = A.tools(`${viewSeg([['register', 'Register'], ['runs', 'Depreciation runs']], view)}<span id="faChips"></span><span class="fwf-grow"></span>${view === 'register' ? A.qbox(p('q'), 'Name or category') : ''}`);
    bindViewSeg(tools, 'assets');
    A.bindQbox(tools);
    A.el.view.innerHTML = F.skel(6, 48);
    const d = await api.get('assets.php', { op: 'list', status, q: p('q'), category: p('category') });
    if (!ctx.isCurrent()) return;
    const hb = (sel, fn) => { const b = $(sel); if (b) b.addEventListener('click', fn); };
    hb('#faRun', () => runDialog(d.next_month));
    hb('#faNew', () => assetEditor());
    if (view === 'runs') {
      const r = await api.get('assets.php', { op: 'runs' });
      if (!ctx.isCurrent()) return;
      A.el.view.innerHTML = r.runs.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Month</th><th>Status</th><th class="r">Assets</th><th class="r">Depreciation</th><th>Journal</th><th class="hide-narrow">Run</th></tr></thead>
        <tbody>${r.runs.map((x) => `<tr><td><b>${esc(F.fmtMonth(x.month))}</b></td><td>${F.pill(x.status)}</td><td class="r">${x.assets}</td><td class="r"><b>${esc(money(x.total))}</b></td>
          <td>${x.journal_id ? `<a href="#journals?id=${x.journal_id}" data-j="${x.journal_id}">#${x.journal_id}</a>` : '—'}</td><td class="hide-narrow">${esc(relTime(x.at))}</td></tr>`).join('')}</tbody></table></div>
        ${r.next_month ? `<p class="fwf-hint">Next month to run: <b>${esc(F.fmtMonth(r.next_month))}</b>.</p>` : ''}`
        : F.emptyState('if-hourglass', 'No depreciation runs yet', 'Run depreciation once a month; it posts one journal for every asset.', can('write') && d.rows.length ? `<button class="btn btn--primary" type="button" data-run>${icon('if-hourglass')}Run depreciation</button>` : '');
      $$('[data-j]', A.el.view).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
      hb('[data-run]', () => runDialog(d.next_month));
      return;
    }
    $('#faChips').innerHTML = A.chips([['active', 'In use'], ['disposed', 'Disposed'], ['all', 'All']], status, d.counts);
    A.bindChips($('#faChips'));
    const t = d.totals;
    const nextDue = d.next_month && d.next_month < BOOT.today.slice(0, 7) + '-01';
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-stats">
        <div class="fwf-stat"><span>Cost</span><b>${esc(money(t.cost))}</b><small>${d.counts.active} in use</small></div>
        <div class="fwf-stat"><span>Depreciated so far</span><b>${esc(money(t.accum))}</b><small>accumulated</small></div>
        <div class="fwf-stat"><span>Book value</span><b>${esc(money(t.book))}</b><small>cost less depreciation</small></div>
        <div class="fwf-stat ${nextDue ? 'is-warn' : ''}"><span>Each month</span><b>${esc(money(t.month))}</b><small>${d.next_month ? 'next run ' + esc(F.fmtMonth(d.next_month)) : 'nothing to run'}</small></div>
      </div>
      ${d.rows.length ? `<div class="fwf-table-wrap can-card"><table class="fwf-table"><thead><tr><th>${esc(L('asset'))}</th><th class="hide-narrow">Bought</th><th class="r">Cost</th><th class="r">Depreciated</th><th class="r">Book value</th><th class="hide-narrow">Method</th><th>Status</th></tr></thead>
        <tbody>${d.rows.map((a) => `<tr class="fwf-row${a.status === 'disposed' ? ' is-muted' : ''}" tabindex="0" data-open="${a.id}">
          <td><div class="fwf-cell2"><b>${esc(a.name)}</b><small>${esc(a.category || '')}${a.asset_code ? ' · ' + esc(a.asset_code) : ''}</small></div></td>
          <td class="hide-narrow fwf-nowrap">${esc(fmtDate(a.purchased, 'always'))}</td><td class="r">${esc(F.group(a.cost))}</td><td class="r">${esc(F.group(a.accumulated))}</td>
          <td class="r"><b>${esc(F.group(a.book))}</b></td><td class="hide-narrow">${esc(METHOD[a.method] || a.method)} · ${Math.round(a.life / 12 * 10) / 10} yr</td><td>${F.pill(a.status)}</td></tr>`).join('')}</tbody></table></div>
        <div class="fwf-cards">${d.rows.map((a) => `<div class="fwf-card" data-open="${a.id}" tabindex="0"><span class="fwf-type">${icon('if-asset')}</span><span class="fwf-card__text"><b>${esc(a.name)}</b><small>${esc(fmtDate(a.purchased, 'always'))} · ${esc(money(a.monthly))}/month</small></span>
          <span class="fwf-card__side">${esc(money(a.book))}${F.pill(a.status)}</span></div>`).join('')}</div>`
        : F.emptyState('if-asset', status === 'active' ? 'No assets in use' : 'Nothing here', 'Register vehicles, equipment, computers and furniture you capitalised, then run depreciation each month.',
          can('write') ? `<button class="btn btn--primary" type="button" data-new>${icon('plus')}Register an asset</button>` : '')}</div>`;
    A.bindRows(A.el.view, (id) => openAsset(+id));
    hb('[data-new]', () => assetEditor());
    if (p('id') && !ctx.keep) openAsset(+p('id'));
    if (p('new') && can('write')) { A.setParams({ new: '' }); assetEditor(); }
    if (p('run') && can('write')) { const m = p('run'); A.setParams({ run: '' }); runDialog(m + '-01'); }
  }

  async function openAsset(id) {
    const s = F.sheet({ title: L('asset'), sub: 'Loading…', html: F.skel(5, 40), wide: true, onClose: () => { if (A.param('id')) A.setParams({ id: '' }); } });
    A.setParams({ id });
    try { paintAsset(s, await api.get('assets.php', { op: 'get', id })); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); }
  }
  function paintAsset(s, d) {
    const a = d.asset;
    const ac = (k) => (d.accounts[k] ? `<span class="fwf-code">${esc(d.accounts[k].account_code)}</span> ${esc(d.accounts[k].account_name)}` : '—');
    $('h2', s).textContent = a.name;
    $('.fwf-eyebrow', s).textContent = `${a.category || L('asset')} · bought ${fmtDate(a.purchased, 'always')}`;
    $('.fwf-sheet__acts', s).innerHTML = `${F.pill(a.status)}${can('write') && a.raw_status === 'active' ? `<button class="btn btn--sm" type="button" data-a="edit">${icon('pen')}Edit</button><button class="btn btn--sm" type="button" data-a="dispose">${icon('if-out')}Dispose</button>` : ''}`;
    $('.fwf-sheet__body', s).innerHTML = `
      ${a.raw_status !== 'active' ? `<div class="fwf-banner fwf-banner--info">${icon('info')}<span>Disposed on ${esc(fmtDate(a.disposed, 'always'))} for ${esc(money(a.proceeds || 0))}${a.disposal_journal_id ? ` · <a href="#journals?id=${a.disposal_journal_id}" data-j="${a.disposal_journal_id}">journal #${a.disposal_journal_id}</a>` : ''}.</span></div>` : ''}
      <div class="fwf-stats"><div class="fwf-stat"><span>Cost</span><b>${esc(money(a.cost))}</b><small>residual ${esc(money(a.salvage))}</small></div>
        <div class="fwf-stat"><span>Depreciated</span><b>${esc(money(a.accumulated))}</b><small>${esc(METHOD[a.method] || a.method)}</small></div>
        <div class="fwf-stat"><span>Book value</span><b>${esc(money(a.book))}</b><small>${a.monthly ? esc(money(a.monthly)) + ' a month' : 'no more depreciation'}</small></div></div>
      <dl class="fwf-kv"><dt>Useful life</dt><dd>${a.life} months (${Math.round(a.life / 12 * 10) / 10} years)</dd><dt>Cost account</dt><dd>${ac('asset_account_id')}</dd>
        <dt>Depreciation</dt><dd>${ac('depreciation_expense_account_id')}</dd><dt>Accumulated</dt><dd>${ac('accumulated_depreciation_account_id')}</dd>
        <dt>SARS allowance</dt><dd>${a.tax_method === 'none' ? 'None' : a.tax_method === 's12c' ? 's12C (40/20/20/20)' : 's11(e) over ' + esc(a.tax_years) + ' years'}</dd></dl>
      ${d.schedule.length ? `<div class="fwf-sheet__sec"><h4>${icon('if-trend')}Coming months<small>same sums as the run</small></h4><table class="fwf-table"><thead><tr><th>Month</th><th class="r">Depreciation</th><th class="r">Book value after</th></tr></thead>
        <tbody>${d.schedule.slice(0, 12).map((x) => `<tr><td>${esc(F.fmtMonth(x.month))}</td><td class="r">${esc(F.group(x.amount))}</td><td class="r">${esc(F.group(x.book))}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${d.history.length ? `<div class="fwf-sheet__sec"><h4>${icon('history')}Depreciation posted<small>${d.history.length}</small></h4><table class="fwf-table"><tbody>${d.history.slice(0, 24).map((x) => `<tr><td>${esc(F.fmtMonth(x.month))}</td><td>${x.journal_id ? `<a href="#journals?id=${x.journal_id}" data-j="${x.journal_id}">#${x.journal_id}</a>` : ''}</td><td class="r">${esc(F.group(x.amount))}</td></tr>`).join('')}</tbody></table></div>` : ''}
      ${A.filesBlock('asset', a.id, d.files, can('write'))}`;
    const refresh = async (msg) => { if (msg) toast(msg); try { paintAsset(s, await api.get('assets.php', { op: 'get', id: a.id })); } catch (e) { toastError(e); } after(); };
    A.bindFiles(s, () => refresh());
    $$('[data-j]', s).forEach((x) => x.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+x.dataset.j); }));
    $$('[data-a]', s).forEach((b) => b.addEventListener('click', () => {
      if (b.dataset.a === 'edit') assetEditor(a, d.editable_money, () => refresh('Asset saved'));
      if (b.dataset.a === 'dispose') disposeDialog(a, () => refresh('Asset disposed of'));
    }));
  }

  async function assetEditor(a = null, moneyOk = true, onSaved) {
    a = a || {};
    let defs = {};
    try { defs = (await api.get('assets.php', { op: 'defaults' })).defaults; } catch (e) { /* the form still works */ }
    const fixed = !!a.id && !moneyOk;
    const opts = (sel, type) => F.accountOptions(sel, (x) => x.type === type);
    F.formDialog({
      title: a.id ? 'Edit ' + a.name : 'Register an asset', wide: true, submit: a.id ? 'Save' : 'Register',
      html: `${fixed ? `<div class="fwf-banner fwf-banner--info">${icon('lock')}<span>This asset has been depreciated, so its cost, date, life, method and accounts stay as they are.</span></div>` : ''}
        <div class="fwf-grid3">
          <div class="field fwf-span2"><label class="label" for="faName">Name<span class="req">*</span></label><input class="input" id="faName" name="name" maxlength="255" value="${esc(a.name || '')}" placeholder="Toyota Hilux CA 123-456"></div>
          <div class="field"><label class="label" for="faCat">Category</label><input class="input" id="faCat" name="category" maxlength="100" value="${esc(a.category || '')}" placeholder="Vehicles" list="faCats"></div>
          <div class="field"><label class="label" for="faDate">Bought<span class="req">*</span></label><input class="input" id="faDate" name="purchased" type="date" value="${esc(a.purchased || BOOT.today)}" ${fixed ? 'disabled' : ''}></div>
          <div class="field"><label class="label" for="faCost">Cost (excl. VAT)<span class="req">*</span></label><input class="input fwf-amount" id="faCost" name="cost" inputmode="decimal" value="${a.cost != null ? esc(F.r2(a.cost).toFixed(2)) : ''}" ${fixed ? 'disabled' : ''}></div>
          <div class="field"><label class="label" for="faSal">Residual value</label><input class="input fwf-amount" id="faSal" name="salvage" inputmode="decimal" value="${esc(F.r2(a.salvage || 0).toFixed(2))}" ${fixed ? 'disabled' : ''}></div>
          <div class="field"><label class="label" for="faLife">Useful life (months)<span class="req">*</span></label><input class="input" id="faLife" name="life" type="number" min="1" max="1200" value="${esc(a.life || 60)}" ${fixed ? 'disabled' : ''}></div>
          <div class="field"><label class="label" for="faMeth">Method</label><select class="select" id="faMeth" name="method" ${fixed ? 'disabled' : ''}>${Object.entries(METHOD).map(([k, l]) => `<option value="${k}"${(a.method || 'straight_line') === k ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="faTax">SARS allowance</label><select class="select" id="faTax" name="tax_method"><option value="none">None</option><option value="s11e"${a.tax_method === 's11e' ? ' selected' : ''}>s11(e) wear and tear</option><option value="s12c"${a.tax_method === 's12c' ? ' selected' : ''}>s12C (40/20/20/20)</option></select></div>
          <div class="field"><label class="label" for="faTaxY">Write-off years (s11e)</label><input class="input" id="faTaxY" name="tax_years" type="number" min="1" max="50" value="${esc(a.tax_years || '')}"></div>
          <div class="field"><label class="label" for="faAcc">Cost account<span class="req">*</span></label><select class="select" id="faAcc" name="asset_account_id" ${fixed ? 'disabled' : ''}>${opts(a.asset_account_id || defs.asset, 'asset')}</select></div>
          <div class="field"><label class="label" for="faAcd">Accumulated depreciation<span class="req">*</span></label><select class="select" id="faAcd" name="accum_account_id" ${fixed ? 'disabled' : ''}>${opts(a.accum_account_id || defs.accum, 'asset')}</select></div>
          <div class="field"><label class="label" for="faExp">Depreciation expense<span class="req">*</span></label><select class="select" id="faExp" name="expense_account_id" ${fixed ? 'disabled' : ''}>${opts(a.expense_account_id || defs.expense, 'expense')}</select></div>
        </div><div class="fwf-hint" id="faHint"></div>
        <p class="fwf-hint">Registering posts nothing: the purchase is already in the books through its ${esc(lower('bill'))} or journal.</p>`,
      onOpen: (f, m) => {
        const hint = () => {
          const c = F.parseMoney(f.cost.value) || 0, sv = F.parseMoney(f.salvage.value) || 0, life = +f.life.value || 0;
          $('#faHint', m).textContent = c > 0 && life > 0 ? (f.method.value === 'straight_line' ? `About ${money(Math.floor((c - sv) * 100 / life) / 100)} a month.` : `First month about ${money(Math.floor(c * 100 * 2 / life) / 100)}, less each month after.`) : '';
        };
        ['cost', 'salvage', 'life', 'method'].forEach((n) => f[n].addEventListener('input', hint));
        hint();
      },
      onSubmit: async (f) => {
        const r = await api.post('assets.php', { op: 'save', id: a.id || 0, name: f.name.value.trim(), category: f.category.value.trim(), purchased: f.purchased.value, cost: f.cost.value, salvage: f.salvage.value,
          life: +f.life.value, method: f.method.value, tax_method: f.tax_method.value, tax_years: +f.tax_years.value || null,
          asset_account_id: +f.asset_account_id.value, accum_account_id: +f.accum_account_id.value, expense_account_id: +f.expense_account_id.value });
        if (onSaved) onSaved(r); else { toast('Asset registered'); A.go('assets', { id: r.id }); }
      },
    });
  }

  async function runDialog(next) {
    let month = (next || BOOT.today).slice(0, 7);
    // a locked month cannot take a posting: start after the lock
    if (BOOT.locked_through && BOOT.locked_through >= month + '-01') {
      const d = F.parseYmd(BOOT.locked_through);
      month = F.ymd(new Date(d.getFullYear(), d.getMonth() + 1, 1)).slice(0, 7);
      if (month > BOOT.today.slice(0, 7)) month = BOOT.today.slice(0, 7);
    }
    const dlg = F.formDialog({
      title: 'Run depreciation', submit: 'Post depreciation',
      html: `<div class="field"><label class="label" for="drM">Month</label><input class="input" id="drM" name="month" type="month" max="${esc(BOOT.today.slice(0, 7))}" value="${esc(month)}"></div><div id="drOut">${F.skel(2, 30)}</div>`,
      onSubmit: async (f) => {
        const r = await api.post('assets.php', { op: 'run', month: f.month.value });
        toast(`${money(r.total)} depreciation posted for ${r.assets} asset(s)`);
        after();
      },
    });
    const show = async () => {
      const out = $('#drOut', dlg.m);
      try {
        const d = await api.get('assets.php', { op: 'preview', month: dlg.form.month.value });
        out.innerHTML = d.done ? `<div class="fwf-banner fwf-banner--ok">${icon('check')}<span>${esc(F.fmtMonth(d.month))} is already posted.</span></div>`
          : d.future ? `<div class="fwf-banner fwf-banner--info">${icon('info')}<span>That month has not come yet.</span></div>`
          : d.locked ? `<div class="fwf-banner fwf-banner--warn">${icon('lock')}<span>The books are locked for that month.</span></div>`
          : d.lines.length ? `<table class="fwf-table"><tbody>${d.lines.map((l) => `<tr><td>${esc(l.name)}</td><td class="r">${esc(F.group(l.amount))}</td></tr>`).join('')}</tbody><tfoot><tr><td>Total</td><td class="r">${esc(F.group(d.total))}</td></tr></tfoot></table>`
          : '<p class="fwf-muted">Nothing to depreciate that month.</p>';
        $('[data-submit]', dlg.m).disabled = d.done || d.future || d.locked || !d.lines.length;
      } catch (e) { out.innerHTML = F.errorBox(e); }
    };
    dlg.form.month.addEventListener('change', show);
    show();
  }

  function disposeDialog(a, done) {
    F.formDialog({
      title: 'Dispose of ' + a.name, submit: 'Dispose', danger: true,
      html: `<p class="fwm-dialog__lead">Book value now: <b>${esc(money(a.book))}</b>. The difference between what you got (excl. VAT) and the book value goes to gain or loss on disposal.</p>
        <div class="fwf-grid2"><div class="field"><label class="label" for="dpD">Date<span class="req">*</span></label><input class="input" id="dpD" name="date" type="date" value="${esc(BOOT.today)}"></div>
        <div class="field"><label class="label" for="dpP">Proceeds</label><input class="input fwf-amount" id="dpP" name="proceeds" inputmode="decimal" placeholder="0.00 when scrapped"></div>
        <div class="field"><label class="label" for="dpB">Into</label><select class="select" id="dpB" name="bank">${F.bankOptions((BOOT.banks[0] || {}).id) || '<option value="">The default bank account</option>'}</select></div>
        <label class="switch" style="align-self:end"><input type="checkbox" name="vat"><span class="switch__track"></span><span>The proceeds include ${esc(L('vat'))}</span></label></div>
        <div class="field"><label class="label" for="dpN">Notes</label><textarea class="textarea" id="dpN" name="notes" rows="2" maxlength="2000"></textarea></div><div class="fwf-hint" id="dpH"></div>`,
      onOpen: (f, m) => {
        const h = () => { const pr = F.parseMoney(f.proceeds.value) || 0; const ex = f.vat.checked ? pr - Math.round(pr * 15 / 115 * 100) / 100 : pr; const diff = Math.round((ex - a.book) * 100) / 100; $('#dpH', m).textContent = diff ? `${diff > 0 ? 'Gain' : 'Loss'} of about ${money(Math.abs(diff))}.` : ''; };
        f.proceeds.addEventListener('input', h); f.vat.addEventListener('change', h);
      },
      onSubmit: async (f) => { await api.post('assets.php', { op: 'dispose', id: a.id, date: f.date.value, proceeds: f.proceeds.value || '0', vat: f.vat.checked, bank_account_id: +f.bank.value || null, notes: f.notes.value }); done(); },
    });
  }

  // ============================================================ payroll
  async function renderPayroll(ctx) {
    const p = ctx.param;
    const status = p('status', 'all');
    A.head(BOOT.company, L('payroll'), `<a class="btn btn--sm btn--ghost" href="/payroll/">${icon('external')}<span>Open Payroll</span></a>`);
    const tools = A.tools(`<span id="prChips"></span>`);
    A.el.view.innerHTML = F.skel(5, 48);
    const d = await api.get('payroll.php', { op: 'runs', status });
    if (!ctx.isCurrent()) return;
    $('#prChips', tools).innerHTML = A.chips([['all', 'All'], ['to_post', 'To post'], ['posted', 'In the books'], ['open', 'Being prepared']], status, d.counts, ['to_post']);
    A.bindChips(tools);
    const li = d.liabilities;
    A.el.view.innerHTML = `<div class="fwf-page">
      ${li && li.rows && li.rows.length ? `<div class="fwf-stats">${li.rows.map((r) => `<div class="fwf-stat"><span>${esc(r.label)} owed</span><b>${esc(money(r.owed))}</b><small><a href="#ledger?account=${esc(r.code)}">${esc(r.code)}</a></small></div>`).join('')}
        <div class="fwf-stat ${li.total > 0 ? 'is-warn' : ''}"><span>EMP201 to SARS</span><b>${esc(money(li.total))}</b><small>due ${esc(fmtDate(li.due, 'always'))}</small></div></div>
        <p class="fwf-hint">Pay the EMP201 from the bank and categorise the bank line to the PAYE, UIF and SDL accounts (split it), so these return to nil.</p>` : ''}
      ${d.rows.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Run</th><th>Pay date</th><th>Status</th><th class="r">People</th><th class="r">Gross</th><th class="r hide-narrow">PAYE</th><th class="r hide-narrow">UIF</th><th class="r hide-narrow">SDL</th><th class="r">Net pay</th><th></th></tr></thead>
        <tbody>${d.rows.map((r) => `<tr><td><div class="fwf-cell2"><b><a href="${esc(r.url)}">${esc(r.number)}</a></b><small>${esc(r.name || range(r.start, r.end))}</small></div></td><td class="fwf-nowrap">${esc(fmtDate(r.pay_date, 'always'))}</td>
          <td>${r.status === 'posted' ? F.pill('posted', 'In the books') : r.status === 'locked' ? F.tonePill('warning', 'Finalised · to post') : F.pill('draft', F.statusLabel(r.status))}</td>
          <td class="r">${r.people}</td><td class="r">${esc(F.group(r.gross))}</td><td class="r hide-narrow">${esc(F.group(r.paye))}</td><td class="r hide-narrow">${esc(F.group(r.uif))}</td><td class="r hide-narrow">${esc(F.group(r.sdl))}</td><td class="r"><b>${esc(F.group(r.net))}</b></td>
          <td class="r fwf-nowrap">${r.journal_id ? `<a class="fwf-mini" href="#journals?id=${r.journal_id}" data-j="${r.journal_id}">#${r.journal_id}</a>` : r.status === 'locked' && can('write') ? `<button class="fwf-mini" type="button" data-post="${r.id}">${icon('if-post')}Post</button>` : ''}</td></tr>`).join('')}</tbody></table></div>`
        : d.available ? F.emptyState('if-payroll', 'No pay runs yet', 'Pay runs are prepared in Payroll. Once a run is finalised it can be posted to the books here or there.', `<a class="btn" href="/payroll/">${icon('external')}Open Payroll</a>`)
        : F.emptyState('if-payroll', 'Payroll is not set up', 'Set up Payroll to see your pay runs here.', `<a class="btn" href="/payroll/">${icon('external')}Open Payroll</a>`)}</div>`;
    $$('[data-j]', A.el.view).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
    $$('[data-post]', A.el.view).forEach((b) => b.addEventListener('click', async () => {
      if (!(await dialog({ title: 'Post this pay run?', body: 'Wages, PAYE, UIF, SDL and net pay go into the books on the pay date, exactly as Payroll posts them.', confirm: 'Post' }))) return;
      try { await api.post('payroll.php', { op: 'post', id: +b.dataset.post }); toast('Pay run posted'); after(); } catch (e) { toastError(e); }
    }));
  }

  // ============================================================ budgets
  async function renderBudgets(ctx) {
    const p = ctx.param;
    const fyNow = +BOOT.fy.start.slice(0, 4);
    const fy = +p('fy') || fyNow;
    const show = p('show', 'expense');
    A.head(BOOT.company, L('budgets'), `${A.exportBtn(api.url('budgets.php', { op: 'grid', fy, format: 'csv' }))}
      ${can('write') ? `<button class="btn btn--sm btn--ghost" type="button" id="bgSeed">${icon('copy')}<span>Start from last year</span></button><button class="btn btn--primary" type="button" id="bgSave" disabled>${icon('check')}<span>Save</span></button>` : ''}`);
    const tools = A.tools(`<div class="seg" role="group" aria-label="Year"><button type="button" data-fy="${fy - 1}">${icon('chev-left')}</button><button type="button" class="is-on" aria-current="true">FY ${fy}/${String(fy + 1).slice(2)}</button><button type="button" data-fy="${fy + 1}">${icon('chev-right')}</button></div>
      ${viewSeg([['expense', 'Expenses'], ['revenue', 'Income'], ['all', 'All']], show)}<span class="fwf-grow"></span><span class="fwf-muted" id="bgDirty"></span>`);
    $$('[data-fy]', tools).forEach((b) => b.addEventListener('click', () => A.go('budgets', A.withParams({ fy: b.dataset.fy }))));
    $$('[data-view]', tools).forEach((b) => b.addEventListener('click', () => A.go('budgets', A.withParams({ show: b.dataset.view }))));
    A.el.view.innerHTML = F.skel(8, 34);
    const d = await api.get('budgets.php', { op: 'grid', fy });
    if (!ctx.isCurrent()) return;
    const rows = d.rows.filter((r) => show === 'all' || r.type === show);
    const dirty = new Map();
    const sum = (a) => a.reduce((s, v) => s + (Number(v) || 0), 0);
    A.el.view.innerHTML = rows.length ? `<div class="fwf-table-wrap"><table class="fwf-table fwf-budget"><thead><tr><th>Account</th>${d.months.map((m, i) => `<th class="r${d.locked[i] ? ' is-locked' : ''}">${esc(m.label.split(' ')[0])}${d.locked[i] ? ' ' + icon('lock', 'i--xs') : ''}</th>`).join('')}<th class="r">Year</th><th class="r hide-narrow">Actual</th></tr></thead>
      <tbody>${rows.map((r) => `<tr data-acc="${r.id}"><td><div class="fwf-cell2"><b>${esc(r.code)} · ${esc(r.name)}</b><small>${esc(r.type === 'revenue' ? 'income' : 'expense')}</small></div></td>
        ${r.budget.map((b, i) => `<td class="r"><input class="input fwf-amount fwf-bcell" data-i="${i}" inputmode="decimal" value="${b !== null ? esc(Number.isInteger(b) ? b : F.r2(b).toFixed(2)) : ''}" placeholder="${r.actual[i] ? esc(Math.round(r.actual[i])) : ''}" aria-label="${esc(r.name)} ${esc(d.months[i].label)}" ${d.locked[i] || !can('write') ? 'disabled' : ''}
          title="Actual ${esc(F.group(r.actual[i]))}"></td>`).join('')}
        <td class="r"><b data-yt>${esc(F.group(sum(r.budget)))}</b></td><td class="r hide-narrow"><span class="${sum(r.actual) > sum(r.budget) && r.type === 'expense' && sum(r.budget) ? 'fwf-neg' : ''}">${esc(F.group(sum(r.actual)))}</span></td></tr>`).join('')}</tbody></table></div>
      <p class="fwf-hint">Grey numbers in empty cells are the actual figures for that month; Tab moves along a row, Enter down a column. Only the cells you change are saved.</p>`
      : F.emptyState('if-budget', 'No accounts', 'Add income and expense accounts to the chart first.');
    const saveBtn = $('#bgSave');
    const mark = () => { $('#bgDirty').textContent = dirty.size ? `${dirty.size} change${dirty.size === 1 ? '' : 's'} not saved` : ''; if (saveBtn) saveBtn.disabled = !dirty.size; };
    $$('.fwf-bcell', A.el.view).forEach((inp) => {
      inp.addEventListener('input', () => {
        const tr = inp.closest('tr');
        const i = +inp.dataset.i;
        const m = d.months[i];
        dirty.set(tr.dataset.acc + ':' + i, { account_id: +tr.dataset.acc, y: m.y, m: m.m, amount: inp.value.trim() === '' ? '' : String(F.parseMoney(inp.value) ?? inp.value) });
        $('[data-yt]', tr).textContent = F.group(sum($$('.fwf-bcell', tr).map((x) => F.parseMoney(x.value) || 0)));
        mark();
      });
      inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); const n = inp.closest('tr').nextElementSibling; if (n) $(`.fwf-bcell[data-i="${inp.dataset.i}"]`, n).focus(); } });
    });
    if (saveBtn) saveBtn.addEventListener('click', async () => {
      try { const r = await api.post('budgets.php', { op: 'save', cells: [...dirty.values()] }); dirty.clear(); mark(); toast(`${r.saved} budget figure(s) saved`); } catch (e) { toastError(e); }
    });
    const seed = $('#bgSeed');
    if (seed) seed.addEventListener('click', () => F.formDialog({
      title: `Budget FY ${fy}/${String(fy + 1).slice(2)} from last year`, submit: 'Fill in',
      html: `<div class="field"><label class="label">Start from</label><select class="select" name="from"><option value="actual">Last year's actual figures</option><option value="budget">Last year's budget</option></select></div>
        <div class="field"><label class="label">Change</label><input class="input" name="grow" inputmode="decimal" value="6" style="max-width:120px"> %</div>
        <label class="switch"><input type="checkbox" name="overwrite"><span class="switch__track"></span><span>Replace figures already entered</span></label>`,
      onSubmit: async (f) => { const r = await api.post('budgets.php', { op: 'seed', fy, from: f.from.value, grow: parseFloat(f.grow.value) || 0, overwrite: f.overwrite.checked }); toast(`${r.saved} figure(s) filled in`); A.render(); },
    }));
    window.onbeforeunload = () => (dirty.size ? true : undefined);
  }

  F.creators.vat_period = () => { if (A.state.route === 'vat') { api.get('vat.php', { op: 'periods' }).then((d) => newPeriod(d.suggest)).catch(toastError); } else location.hash = '#vat?new=1'; };
  F.creators.asset = () => assetEditor();
  F.openAsset = openAsset;
  A.register('vat', { render: renderVat, newKind: () => (can('write') ? 'vat_period' : null) });
  A.register('assets', { render: renderAssets, newKind: () => 'asset' });
  A.register('payroll', { render: renderPayroll, newKind: () => 'journal' });
  A.register('budgets', { render: renderBudgets, newKind: () => 'journal' });
  A.viewSeg = viewSeg;
  A.bindViewSeg = bindViewSeg;
})();
