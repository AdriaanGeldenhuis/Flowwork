// /finances/js/fin-money.js — the bank: accounts, statement lines, matching,
// rules, import and reconciliation. Receivables and payables: fin-parties.js.
(function () {
  'use strict';
  const F = window.FIN;
  const A = F.app;
  const { $, $$, esc, icon, api, toast, toastError, popover, dialog, BOOT, L, lower, can, money, fmtDate, relTime } = F;
  const signed = (v) => `<span class="fwf-txn__amt${v > 0 ? ' in' : ''}">${v > 0 ? '+' : '−'}${esc(F.group(Math.abs(v)))}</span>`;
  const bankAccOptions = (sel, filter) => (BOOT.accounts || []).filter((a) => a.active && a.type === 'asset' && ['bank', 'cash'].includes(a.subtype) && (!filter || filter(a)))
    .map((a) => `<option value="${a.id}"${String(sel) === String(a.id) ? ' selected' : ''}>${esc(a.code + ' · ' + a.name)}</option>`).join('');

  // ================================================================ bank
  async function renderBank(ctx) {
    const p = ctx.param;
    const status = p('status', 'unmatched');
    const acc = +p('account') || 0;
    const [from, to] = A.period('all');
    A.head(BOOT.company, L('bank'), `${A.exportBtn(api.url('bank.php', { op: 'lines', account: acc, status, from, to, q: p('q'), format: 'csv' }), 'Export')}
      ${can('write') ? `<button class="btn btn--sm btn--ghost" type="button" id="bkRules">${icon('if-rules')}<span>Rules</span></button>
      <button class="btn btn--sm btn--ghost" type="button" id="bkRecon">${icon('if-scale')}<span>Reconcile</span></button>
      <button class="btn btn--primary" type="button" id="bkImport">${icon('upload')}<span>Import statement</span></button>` : `<button class="btn btn--sm btn--ghost" type="button" id="bkRecon">${icon('if-scale')}<span>Reconciliation</span></button>`}`);
    const tools = A.tools(`<span id="bkChips"></span>
      <div class="seg" role="group" aria-label="Direction"><button type="button" data-dir="" class="${!p('dir') ? 'is-on' : ''}">All</button><button type="button" data-dir="in" class="${p('dir') === 'in' ? 'is-on' : ''}">Money in</button><button type="button" data-dir="out" class="${p('dir') === 'out' ? 'is-on' : ''}">Money out</button></div>
      <span class="fwf-grow"></span>${A.periodBtn(from, to)}${A.qbox(p('q'), 'Words or an amount')}${A.saveViewBtn()}`);
    A.bindPeriod(tools, from, to);
    A.bindQbox(tools);
    A.bindSaveView(tools);
    $$('[data-dir]', tools).forEach((b) => b.addEventListener('click', () => A.go('bank', A.withParams({ dir: b.dataset.dir, page: 1 }))));
    const hb = (id, fn) => { const b = $(id); if (b) b.addEventListener('click', fn); };
    hb('#bkImport', () => importDialog(acc));
    hb('#bkRules', () => rulesSheet());
    hb('#bkRecon', () => reconcileSheet(acc));
    A.el.view.innerHTML = F.skel(6, 54);
    const [accs, d] = await Promise.all([api.get('bank.php', { op: 'accounts' }), api.get('bank.php', { op: 'lines', account: acc, status, from, to, q: p('q'), dir: p('dir'), page: p('page', 1) })]);
    if (!ctx.isCurrent()) return;
    const bankCode = Object.fromEntries(accs.accounts.map((b) => [b.id, b.account_code]));
    $('#bkChips').innerHTML = A.chips([['unmatched', 'To match'], ['matched', 'Matched'], ['all', 'All']], status, d.counts, ['unmatched']);
    A.bindChips($('#bkChips'));
    const cards = accs.accounts.map((b) => `<a class="fwf-bankcard${b.id === acc ? ' is-on' : ''}" href="#bank?account=${b.id === acc ? '' : b.id}${status !== 'unmatched' ? '&status=' + status : ''}" aria-pressed="${b.id === acc}">
      <span class="fwf-bankcard__name">${icon('if-bank')}${esc(b.name)}${b.last4 ? ` <small>··${esc(b.last4)}</small>` : ''}</span><b>${esc(money(b.statement))}</b>
      <small>${b.unmatched ? `<b>${b.unmatched}</b> to match (${esc(money(b.unmatched_total))})` : 'Everything matched'}</small>
      <small>${b.books !== null ? 'Books ' + esc(money(b.books)) + ' · ' : ''}${b.reconciled ? 'reconciled to ' + esc(fmtDate(b.reconciled, 'always')) : 'not reconciled yet'}</small></a>`).join('');
    const addCard = can('write') ? `<button class="fwf-bankcard" type="button" id="bkAdd" style="align-items:center;justify-content:center;text-align:center;border:1px dashed var(--line-strong);background:transparent;box-shadow:none">${icon('plus')}<span>Add a bank account</span></button>` : '';
    if (!accs.accounts.length) {
      A.el.view.innerHTML = `<div class="fwf-page">${F.emptyState('if-bank', 'No bank accounts yet', 'Add the bank accounts the business uses, then import statements (CSV, OFX or PDF) and match each line to the books.',
        can('write') ? `<button class="btn btn--primary" type="button" id="bkAdd">${icon('plus')}Add a bank account</button>` : '')}</div>`;
      hb('#bkAdd', () => accountEditor());
      return;
    }
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-bankcards">${cards}${addCard}</div>
      <div class="fwf-box">${d.rows.length ? `<div class="fwf-sum" style="padding:12px 18px 6px"><span><b>${d.total}</b> line(s)</span><span>In <b class="fwf-pos">${esc(money(d.in))}</b></span><span>Out <b>${esc(money(Math.abs(d.out)))}</b></span>
          ${status === 'unmatched' && can('write') ? `<span class="fwf-grow"></span><button class="fwf-mini" type="button" id="bkRun">${icon('if-rules')}Run rules on these</button>` : ''}</div>
        <div id="bkList">${d.rows.map((r) => `<div class="fwf-txn" tabindex="0" data-open="${r.id}" role="button" aria-label="${esc(r.description)} ${esc(money(r.amount))}">
          <span class="fwf-txn__date">${esc(fmtDate(r.date))}</span>
          <span class="fwf-txn__desc"><b>${esc(r.description)}</b><small>${esc(r.bank)}${r.reference ? ' · ' + esc(r.reference) : ''}${r.match ? ' · ' + esc(r.match.kind === 'existing' ? 'matched to ' : r.match.kind === 'transfer' ? 'transfer ' : '') + esc(r.match.reference || '') + (r.codes ? ' ' + r.codes.filter((c) => c !== bankCode[r.account]).slice(0, 2).join(', ') : '') : ''}${r.reconciled ? ' · reconciled' : ''}</small></span>
          ${signed(r.amount)}<span>${r.matched ? F.pill('matched') : F.pill('unmatched')}</span></div>`).join('')}</div>${A.pager(d.page, d.per, d.total)}`
        : F.emptyState(status === 'unmatched' ? 'if-approve' : 'if-bank', status === 'unmatched' ? 'Every line is matched' : 'No lines here', status === 'unmatched' ? 'Import the latest statement to keep the books up to date.' : 'Try another filter or period.',
          can('write') ? `<button class="btn" type="button" id="bkImport2">${icon('upload')}Import a statement</button>` : '', true)}</div></div>`;
    hb('#bkAdd', () => accountEditor());
    hb('#bkImport2', () => importDialog(acc));
    hb('#bkRun', async () => {
      try {
        const plan = await api.post('bank.php', { op: 'apply_rules', account: acc, dry: true });
        if (!plan.plan.length) { toast('No rule matches these lines. Categorise one and tick “always do this” to make a rule.'); return; }
        if (!(await dialog({ title: `Post ${plan.plan.length} line(s) with your rules?`, body: `<div class="fwf-rows">${plan.plan.slice(0, 8).map((x) => `<div class="fwf-rowlink"><span class="fwf-code">${esc(x.account_code)}</span><span class="fwf-rows__text"><b>${esc(x.rule)}</b></span><span></span></div>`).join('')}</div>${plan.plan.length > 8 ? `<p class="fwf-hint">…and ${plan.plan.length - 8} more.</p>` : ''}`, confirm: 'Post them' }))) return;
        const r = await api.post('bank.php', { op: 'apply_rules', account: acc });
        toast(`${r.matched} line(s) matched${r.skipped ? ', ' + r.skipped + ' skipped' : ''}`);
        if (r.errors.length) toast(r.errors.join(' · '), { type: 'error' });
        A.render();
      } catch (e) { toastError(e); }
    });
    A.bindPager(A.el.view);
    const list = $('#bkList');
    if (list) A.bindRows(list, (id, row) => { $$('.fwf-txn', list).forEach((x) => x.classList.toggle('is-on', x === row)); openLine(+id); });
    if (p('txn') && !ctx.keep) openLine(+p('txn'));
    if (p('new') === 'import' && can('write')) { A.setParams({ new: '' }); importDialog(acc); }
    if (p('new') === 'account' && can('write')) { A.setParams({ new: '' }); accountEditor(); }
  }

  /** The matching sheet for one statement line. */
  async function openLine(id) {
    const s = F.sheet({ title: 'Bank line', sub: 'Loading…', html: F.skel(4, 50), onClose: () => { if (A.param('txn')) A.setParams({ txn: '' }); $$('.fwf-txn.is-on').forEach((x) => x.classList.remove('is-on')); } });
    A.setParams({ txn: id });
    let d;
    try { d = await api.get('bank.php', { op: 'line', id }); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); return; }
    paintLine(s, d);
  }
  function paintLine(s, d) {
    const l = d.line;
    const after = (msg) => { toast(msg); F.closeSheet(); A.render(); F.refreshBoot().catch(() => {}); };
    $('h2', s).innerHTML = `${l.amount > 0 ? '+' : '−'}${esc(money(Math.abs(l.amount)))}`;
    $('.fwf-eyebrow', s).textContent = `${l.bank} · ${fmtDate(l.date, 'always')}`;
    $('.fwf-sheet__acts', s).innerHTML = l.matched ? F.pill('matched') : F.pill('unmatched');
    const blocked = l.reconciled ? 'This line is in a closed reconciliation. An administrator can reopen it.' : l.locked ? 'This line is in a locked period.' : '';
    const body = $('.fwf-sheet__body', s);
    const head = `<div class="fwf-box" style="box-shadow:none;background:var(--surface-2)"><div class="fwf-box__body" style="padding-top:14px"><b style="font-size:14px">${esc(l.description)}</b>${l.reference ? `<div class="fwf-muted">Reference ${esc(l.reference)}</div>` : ''}</div></div>
      ${blocked ? `<div class="fwf-banner fwf-banner--info">${icon('lock')}<span>${esc(blocked)}</span></div>` : ''}`;
    if (l.matched) {
      body.innerHTML = `${head}<div class="fwf-banner fwf-banner--ok">${icon('check')}<span>Matched to journal <a href="#journals?id=${l.journal_id}" data-j="${l.journal_id}">#${l.journal_id}</a>.</span>
        ${can('write') && !blocked ? `<button class="fwf-mini" type="button" id="unm">${icon('undo')}Undo match</button>` : ''}</div>${A.filesBlock('bank_txn', l.id, d.files, can('write'))}`;
      const u = $('#unm', body);
      if (u) u.addEventListener('click', async () => {
        if (!(await dialog({ title: 'Undo this match?', body: 'If the bank posted the journal, it is reversed (both stay on the audit trail). If the line was matched to a payment in the books, that payment is simply released.', confirm: 'Undo match', danger: true }))) return;
        try { const r = await api.post('bank.php', { op: 'unmatch', id: l.id }); after(r.released ? 'Match released' : 'Match undone and journal reversed'); } catch (e) { toastError(e); }
      });
      $$('[data-j]', body).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); F.openJournal(+a.dataset.j); }));
      A.bindFiles(body, async () => paintLine(s, await api.get('bank.php', { op: 'line', id: l.id })));
      return;
    }
    const ex = d.existing || [];
    const guess = d.rule || d.history;
    const tab0 = ex.length ? 'books' : 'cat';
    body.innerHTML = `${head}
      <div class="fwf-tabs" role="tablist"><button type="button" role="tab" data-tab="books" class="${tab0 === 'books' ? 'is-on' : ''}">In the books <small>${ex.length}</small></button>
        <button type="button" role="tab" data-tab="cat" class="${tab0 === 'cat' ? 'is-on' : ''}">Categorise</button><button type="button" role="tab" data-tab="xfer">Transfer</button></div>
      <div data-pane="books" ${tab0 === 'books' ? '' : 'hidden'}>${ex.length ? `<p class="fwf-hint">These postings are already in the books on this bank account with the same amount. Matching one posts nothing new, so nothing is counted twice.</p>
        <div class="fwf-suggest">${ex.map((x) => `<button type="button" data-ex="${x.journal_id}" ${blocked || !can('write') ? 'disabled' : ''}><span class="fwf-type">${icon(A.SOURCE_ICON[x.source] || 'if-journal')}</span>
          <span class="fwf-rows__text"><b>${esc(x.reference || '#' + x.journal_id)} · ${esc(x.description || '')}</b><small>${esc(fmtDate(x.date, 'always'))} · ${x.days ? x.days + ' day(s) apart' : 'same day'}</small></span><span class="fwf-score">${x.score}%</span></button>`).join('')}</div>`
        : `<p class="fwf-hint">Nothing in the books on this bank account has this amount. If the money was for an invoice or a bill, record the payment there first; otherwise categorise it.</p>`}</div>
      <form data-pane="cat" class="fwf-form" ${tab0 === 'cat' ? '' : 'hidden'}>
        ${guess ? `<div class="fwf-banner fwf-banner--info">${icon('sparkle')}<span>${d.rule ? 'Your rule “' + esc(d.rule.name) + '” suggests' : 'Similar lines went to'} <b>${esc(F.acctLabel(guess.account_code))}</b>.</span></div>` : ''}
        <div id="parts"></div>
        <button class="btn btn--sm btn--ghost" type="button" id="addPart">${icon('plus')}Split across another account</button>
        <div class="field"><label class="label" for="catMemo">Description</label><input class="input" id="catMemo" name="memo" maxlength="255" value="${esc(l.description)}"></div>
        <label class="switch"><input type="checkbox" name="rule"><span class="switch__track"></span><span>Always do this for lines containing</span></label>
        <input class="input" name="rule_value" maxlength="120" value="${esc(ruleWord(l.description))}" style="margin-top:6px">
        <div class="fwf-formerr" hidden></div>
        <div style="display:flex;gap:8px;margin-top:12px"><span class="fwf-grow"></span><button class="btn btn--primary" type="submit" ${blocked || !can('write') ? 'disabled' : ''}>${icon('if-post')}Post</button></div></form>
      <form data-pane="xfer" class="fwf-form" hidden>
        <p class="fwf-hint">Money moved ${l.amount > 0 ? 'in from' : 'out to'} another of your bank accounts. One journal moves it between the two ledger accounts.</p>
        <div class="field"><label class="label" for="xTo">${l.amount > 0 ? 'From' : 'To'}</label><select class="select" id="xTo" name="to">${(BOOT.banks || []).filter((b) => b.id !== l.account).map((b) => `<option value="${b.id}">${esc(b.name)}</option>`).join('')}</select></div>
        ${(d.transfers || []).length ? `<div class="field"><label class="label">Match the line there too</label>${d.transfers.map((t, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="other" value="${t.id}" ${i === 0 ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(t.bank)} · ${esc(fmtDate(t.date))}</b><small>${esc(t.description)}</small></span></label>`).join('')}<label class="fwm-result" style="cursor:pointer"><input type="radio" name="other" value="0" style="accent-color:var(--accent)"><span class="fwm-result__text"><b>Not yet</b></span></label></div>` : ''}
        <div class="fwf-formerr" hidden></div>
        <div style="display:flex;gap:8px;margin-top:12px"><span class="fwf-grow"></span><button class="btn btn--primary" type="submit" ${blocked || !can('write') || (BOOT.banks || []).length < 2 ? 'disabled' : ''}>${icon('if-match')}Record transfer</button></div></form>
      ${A.filesBlock('bank_txn', l.id, d.files, can('write'))}`;
    $$('[data-tab]', body).forEach((b) => b.addEventListener('click', () => {
      $$('[data-tab]', body).forEach((x) => x.classList.toggle('is-on', x === b));
      $$('[data-pane]', body).forEach((p) => { p.hidden = p.dataset.pane !== b.dataset.tab; });
    }));
    $$('[data-ex]', body).forEach((b) => b.addEventListener('click', async () => {
      try { await api.post('bank.php', { op: 'match_existing', id: l.id, journal_id: +b.dataset.ex }); after('Matched to the posting in the books'); } catch (e) { toastError(e); }
    }));
    // categorise parts
    const parts = $('#parts', body);
    const total = Math.abs(l.amount);
    const partRow = (code, tax, amt) => {
      const div = document.createElement('div');
      div.className = 'fwf-grid3 fwf-part';
      div.style.marginBottom = '10px';
      div.innerHTML = `<div class="field fwf-span2" style="margin:0"><label class="label">Account</label><input class="input" name="acc" data-code="${esc(code || '')}" placeholder="Income or expense account"></div>
        <div class="field" style="margin:0"><label class="label">${esc(L('vat'))}</label><select class="select" name="tax">${F.taxOptions(tax)}</select></div>
        <div class="field" style="margin:0"><label class="label">Amount (incl. VAT)</label><input class="input fwf-amount" name="amt" inputmode="decimal" value="${amt != null ? esc(amt.toFixed(2)) : ''}"></div>
        ${l.amount < 0 ? `<label class="switch" style="align-self:end;margin-bottom:8px"><input type="checkbox" name="cap"><span class="switch__track"></span><span>Capital asset</span></label>` : '<span></span>'}
        <div class="fwf-hint" data-net style="align-self:end;margin-bottom:10px"></div>`;
      parts.appendChild(div);
      F.accountPicker($('[name=acc]', div), { filter: (a) => !a.control, onPick: () => net() });
      $('[name=tax]', div).addEventListener('change', net);
      $('[name=amt]', div).addEventListener('input', net);
    };
    const net = () => {
      const rows = $$('.fwf-part', parts);
      let used = 0;
      rows.forEach((r, i) => {
        const amtIn = $('[name=amt]', r);
        let a = F.parseMoney(amtIn.value);
        if (rows.length === 1) { a = total; amtIn.value = total.toFixed(2); amtIn.readOnly = true; } else amtIn.readOnly = false;
        if (i === rows.length - 1 && rows.length > 1 && (a === null || document.activeElement !== amtIn)) { a = Math.max(0, F.r2(total - used)); amtIn.value = a.toFixed(2); }
        used += a || 0;
        const tc = F.taxCode($('[name=tax]', r).value);
        const vat = tc && ['output', 'input'].includes(tc.type) && tc.rate > 0 ? F.r2((a || 0) * tc.rate / (100 + tc.rate)) : 0;
        $('[data-net]', r).textContent = vat ? `${money((a || 0) - vat)} + VAT ${money(vat)}` : '';
      });
    };
    partRow(guess ? guess.account_code : '', guess ? guess.tax_code_id : null, total);
    net();
    $('#addPart', body).addEventListener('click', () => { partRow('', null, null); net(); });
    const catForm = $('[data-pane=cat]', body);
    catForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const err = $('.fwf-formerr', catForm);
      err.hidden = true;
      const lines = $$('.fwf-part', parts).map((r) => ({ account_code: $('[name=acc]', r).dataset.code || '', tax_code_id: +$('[name=tax]', r).value || null, amount: F.parseMoney($('[name=amt]', r).value) || 0, capital: !!($('[name=cap]', r) || {}).checked }));
      const body2 = { op: 'categorise', id: l.id, lines, memo: catForm.memo.value.trim() };
      if (catForm.rule.checked && lines.length === 1 && lines[0].account_code) {
        const a = F.acct(lines[0].account_code);
        body2.make_rule = { value: catForm.rule_value.value.trim(), account_id: a ? a.id : 0, tax_code_id: lines[0].tax_code_id, field: 'description', operator: 'contains' };
      }
      try { await api.post('bank.php', body2); after(catForm.rule.checked ? 'Posted, and the rule is saved' : 'Posted'); } catch (er) { err.textContent = er.message; err.hidden = false; }
    });
    const xf = $('[data-pane=xfer]', body);
    xf.addEventListener('submit', async (e) => {
      e.preventDefault();
      const err = $('.fwf-formerr', xf);
      err.hidden = true;
      const other = ($('input[name=other]:checked', xf) || {}).value || 0;
      try { await api.post('bank.php', { op: 'transfer', id: l.id, to_account: +xf.to.value, other_line: +other }); after('Transfer recorded'); } catch (er) { err.textContent = er.message; err.hidden = false; }
    });
    A.bindFiles(body, async () => paintLine(s, await api.get('bank.php', { op: 'line', id: l.id })));
  }
  const ruleWord = (desc) => (String(desc || '').toUpperCase().replace(/[^A-Z0-9 ]+/g, ' ').split(/\s+/).filter((w) => w.length > 2 && !/^\d+$/.test(w))[0] || '');

  function accountEditor(b = null) {
    b = b || {};
    F.formDialog({
      title: b.id ? 'Edit ' + b.name : 'Add a bank account', submit: b.id ? 'Save' : 'Add account',
      html: `<div class="fwf-grid2">
        <div class="field fwf-span2"><label class="label" for="baName">Name<span class="req">*</span></label><input class="input" id="baName" name="name" maxlength="120" required value="${esc(b.name || '')}" placeholder="FNB Business Cheque"></div>
        <div class="field"><label class="label" for="baBank">Bank</label><input class="input" id="baBank" name="bank_name" maxlength="120" value="${esc(b.bank || '')}" placeholder="FNB"></div>
        <div class="field"><label class="label" for="baNo">Account number</label><input class="input" id="baNo" name="account_no" maxlength="64" value="${esc(b.account_no || '')}" inputmode="numeric"></div>
        <div class="field fwf-span2"><label class="label" for="baGl">Ledger account<span class="req">*</span></label><select class="select" id="baGl" name="gl_account_id"><option value="">— choose —</option>${bankAccOptions(b.gl_account_id)}</select><div class="fwf-hint">The bank or cash account in your chart that this bank account posts to.</div></div>
        <div class="field"><label class="label" for="baOpen">Statement opening balance</label><input class="input fwf-amount" id="baOpen" name="opening_balance" inputmode="decimal" value="${esc(F.r2(b.opening || 0).toFixed(2))}" ${b.lines ? 'readonly' : ''}><div class="fwf-hint">${b.lines ? 'Fixed once lines are imported.' : 'The balance on the first statement you will import.'}</div></div>
        ${b.id ? `<label class="switch" style="align-self:end"><input type="checkbox" name="active" ${b.active !== false ? 'checked' : ''}><span class="switch__track"></span><span>In use</span></label>` : ''}</div>`,
      onSubmit: async (f) => {
        const r = await api.post('bank.php', { op: 'account_save', id: b.id || 0, name: f.name.value, bank_name: f.bank_name.value, account_no: f.account_no.value, gl_account_id: +f.gl_account_id.value,
          opening_balance: f.opening_balance.value, active: f.active ? f.active.checked : true });
        BOOT.banks = r.banks;
        toast(b.id ? 'Bank account saved' : 'Bank account added');
        A.render();
      },
    });
  }

  /** Import: choose a file → see what will come in → import. */
  function importDialog(acc) {
    if (!(BOOT.banks || []).length) { toast('Add a bank account first.', { type: 'error' }); return; }
    let parsed = null, file = null;
    const dlg = F.formDialog({
      title: 'Import a bank statement', wide: true, submit: 'Read the file',
      html: `<div class="fwf-grid2"><div class="field"><label class="label" for="imAcc">Bank account</label><select class="select" id="imAcc" name="account">${F.bankOptions(acc || (BOOT.banks[0] || {}).id)}</select></div>
        <div class="field"><label class="label">Statement file</label><label class="fwf-drop" style="padding:12px">${icon('upload')}<b id="imName">Choose CSV, OFX or PDF</b><input type="file" id="imFile" accept=".csv,.txt,.ofx,.qfx,.pdf,text/csv,application/pdf" hidden></label></div></div>
        <div id="imOut"></div>`,
      onSubmit: async (f, m) => {
        const btn = $('[data-submit]', m);
        if (parsed && btn.dataset.ready === '1') {
          const keep = parsed.rows.filter((r, i) => !r.duplicate && !r.reconciled && ($(`[data-keep="${i}"]`, m) || {}).checked !== false);
          const r = await api.post('bank.php', { op: 'import', account: +f.account.value, rows: keep, file_name: file.name });
          toast(`${r.imported} line(s) imported${r.duplicates ? ', ' + r.duplicates + ' already there' : ''}${r.reconciled ? ', ' + r.reconciled + ' in a closed reconciliation' : ''}`);
          A.go('bank', { account: f.account.value, status: 'unmatched' });
          F.refreshBoot().catch(() => {});
          return;
        }
        if (!file) throw new Error('Choose the statement file.');
        await read(f, m, null);
        return false;
      },
    });
    const m = dlg.m;
    $('#imFile', m).addEventListener('change', (e) => { file = e.target.files[0] || null; $('#imName', m).textContent = file ? file.name : 'Choose CSV, OFX or PDF'; parsed = null; $('[data-submit]', m).dataset.ready = ''; $('[data-submit]', m).textContent = 'Read the file'; });
    async function read(f, mm, map) {
      const fd = new FormData();
      fd.append('op', 'parse');
      fd.append('account', f.account.value);
      fd.append('file', file);
      if (map) fd.append('map', JSON.stringify(map));
      parsed = await api.upload('bank.php', fd);
      paint(f, mm);
    }
    function paint(f, mm) {
      const p = parsed;
      const out = $('#imOut', mm);
      const colSel = (key, label) => p.head ? `<div class="field" style="margin:0"><label class="label">${esc(label)}</label><select class="select" data-map="${key}"><option value="">—</option>${p.head.map((h, i) => `<option value="${i}"${p.columns && String(p.columns[key]) === String(i) ? ' selected' : ''}>${esc(h || 'Column ' + (i + 1))}</option>`).join('')}</select></div>` : '';
      out.innerHTML = `${p.kind === 'csv' && p.head ? `<div class="fwf-formsec"><h4>Columns<small>change if a column is read wrongly</small></h4><div class="fwf-grid3">${colSel('date', 'Date')}${colSel('description', 'Description')}${colSel('amount', 'Amount (+ in, − out)')}${colSel('debit', 'Or: money out')}${colSel('credit', 'Or: money in')}${colSel('reference', 'Reference')}</div>
          <button class="btn btn--sm" type="button" id="imRemap" style="margin-top:8px">${icon('refresh')}Read again with these columns</button></div>` : ''}
        ${p.warnings.length ? `<div class="fwf-banner fwf-banner--warn">${icon('alert')}<span>${p.warnings.map(esc).join('<br>')}</span></div>` : ''}
        <div class="fwf-banner fwf-banner--${p.new ? 'ok' : 'info'}">${icon(p.new ? 'check' : 'info')}<span><b>${p.new}</b> new line(s) of ${p.rows.length}.${p.reconciled_to ? ' Lines up to ' + esc(fmtDate(p.reconciled_to, 'always')) + ' are in a closed reconciliation and stay out.' : ''}</span></div>
        <div style="max-height:46vh;overflow:auto"><table class="fwf-table"><thead><tr><th class="c-check"></th><th>Date</th><th>Description</th><th class="r">Amount</th><th></th></tr></thead><tbody>
        ${p.rows.slice(0, 600).map((r, i) => `<tr class="${r.duplicate || r.reconciled ? 'is-muted' : ''}"><td class="c-check"><input type="checkbox" data-keep="${i}" ${r.duplicate || r.reconciled ? 'disabled' : 'checked'} aria-label="Import this line"></td><td class="fwf-nowrap">${esc(fmtDate(r.date, 'always'))}</td><td>${esc(r.description)}${r.reference ? ` <small class="fwf-muted">${esc(r.reference)}</small>` : ''}</td>
          <td class="r">${signed(r.amount_cents / 100)}</td><td>${r.duplicate ? F.tonePill('muted', 'Already in') : r.reconciled ? F.tonePill('gold', 'Reconciled') : F.tonePill('success', 'New')}</td></tr>`).join('')}</tbody></table></div>`;
      const btn = $('[data-submit]', mm);
      btn.dataset.ready = p.new ? '1' : '';
      btn.textContent = p.new ? `Import ${p.new} line(s)` : 'Read the file';
      const rm = $('#imRemap', out);
      if (rm) rm.addEventListener('click', async () => {
        const map = {};
        $$('[data-map]', out).forEach((s) => { if (s.value !== '') map[s.dataset.map] = +s.value; });
        try { await read(f, mm, map); } catch (e) { toastError(e); }
      });
    }
  }

  /** Bank rules: what to post lines to, automatically. */
  async function rulesSheet() {
    const s = F.sheet({ title: 'Bank rules', sub: 'Matching on autopilot', html: F.skel(4, 50), wide: true });
    const paint = (rules) => {
      $('.fwf-sheet__acts', s).innerHTML = `<button class="btn btn--sm btn--primary" type="button" id="ruleNew">${icon('plus')}New rule</button>`;
      $('.fwf-sheet__body', s).innerHTML = `<p class="fwf-hint">Rules run top to bottom; the first one that fits a line posts it. Drag to change the order. Rules never post to receivables, payables or VAT control accounts — match those lines to the payment in the books.</p>
        ${rules.length ? `<div class="fwf-sortable" id="ruleList">${rules.map((r) => `<div class="fwf-srow${r.active ? '' : ' is-off'}" draggable="true" data-rule="${r.id}" tabindex="0">
          <span class="fwf-srow__grip">${icon('grip')}</span><span class="fwf-srow__main"><b>${esc(r.name)}</b><small class="fwf-muted">${esc(r.field)} ${esc(r.operator.replace('_', ' '))} “${esc(r.value)}” → <span class="fwf-code">${esc(r.account_code)}</span> ${esc(r.account_name)}${r.tax_code_id && F.taxCode(r.tax_code_id) ? ' · ' + esc(F.taxCode(r.tax_code_id).code) : ''}</small></span>
          <span class="fwf-rowacts" style="opacity:1"><button class="icon-btn" type="button" data-redit="${r.id}" aria-label="Edit">${icon('pen')}</button><button class="icon-btn" type="button" data-rtog="${r.id}" aria-label="${r.active ? 'Pause' : 'Resume'}">${icon(r.active ? 'eye' : 'eye-off')}</button><button class="icon-btn" type="button" data-rdel="${r.id}" aria-label="Delete">${icon('trash')}</button></span></div>`).join('')}</div>`
          : F.emptyState('if-rules', 'No rules yet', 'Categorise a bank line and tick “always do this”, or add a rule here.', '', true)}`;
      $('#ruleNew', s).addEventListener('click', () => ruleEditor(null, paint));
      const list = $('#ruleList', s);
      if (list) {
        F.sortable(list, '[data-rule]', async (els) => { try { const r = await api.post('bank.php', { op: 'rule_order', ids: els.map((x) => +x.dataset.rule) }); paint(r.rules); } catch (e) { toastError(e); } });
        $$('[data-redit]', list).forEach((b) => b.addEventListener('click', () => ruleEditor(rules.find((r) => r.id === +b.dataset.redit), paint)));
        $$('[data-rtog]', list).forEach((b) => b.addEventListener('click', async () => { try { paint((await api.post('bank.php', { op: 'rule_toggle', id: +b.dataset.rtog })).rules); } catch (e) { toastError(e); } }));
        $$('[data-rdel]', list).forEach((b) => b.addEventListener('click', async () => {
          if (!(await dialog({ title: 'Delete this rule?', body: 'Lines it already posted stay as they are.', confirm: 'Delete', danger: true }))) return;
          try { paint((await api.post('bank.php', { op: 'rule_delete', id: +b.dataset.rdel })).rules); } catch (e) { toastError(e); }
        }));
      }
    };
    try { paint((await api.get('bank.php', { op: 'rules' })).rules); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); }
  }
  function ruleEditor(r, done) {
    r = r || { field: 'description', operator: 'contains' };
    const dlg = F.formDialog({
      title: r.id ? 'Edit rule' : 'New rule', submit: 'Save rule',
      html: `<div class="fwf-grid3"><div class="field"><label class="label">When the</label><select class="select" name="field"><option value="description"${r.field === 'description' ? ' selected' : ''}>description</option><option value="reference"${r.field === 'reference' ? ' selected' : ''}>reference</option></select></div>
        <div class="field"><label class="label">&nbsp;</label><select class="select" name="operator">${[['contains', 'contains'], ['starts_with', 'starts with'], ['ends_with', 'ends with'], ['equals', 'is exactly']].map(([k, lb]) => `<option value="${k}"${r.operator === k ? ' selected' : ''}>${lb}</option>`).join('')}</select></div>
        <div class="field"><label class="label">&nbsp;</label><input class="input" name="value" maxlength="255" required value="${esc(r.value || '')}" placeholder="ENGEN"></div></div>
        <div class="fwf-grid2"><div class="field"><label class="label">Post to</label><input class="input" name="acc" data-code="${esc(r.account_code || '')}" placeholder="Account"></div>
        <div class="field"><label class="label">${esc(L('vat'))}</label><select class="select" name="tax">${F.taxOptions(r.tax_code_id)}</select></div></div>
        <div class="fwf-grid2"><div class="field"><label class="label">Rule name</label><input class="input" name="name" maxlength="120" value="${esc(r.name || '')}" placeholder="Fuel"></div>
        <div class="field"><label class="label">Journal description</label><input class="input" name="template" maxlength="255" value="${esc(r.template || '')}" placeholder="Keeps the bank wording when empty"></div></div>`,
      onSubmit: async (f) => {
        const a = F.acct(f.acc.dataset.code);
        if (!a) throw new Error('Choose the account to post to.');
        const res = await api.post('bank.php', { op: 'rule_save', id: r.id || 0, field: f.field.value, operator: f.operator.value, value: f.value.value, account_id: a.id, tax_code_id: +f.tax.value || null, name: f.name.value, template: f.template.value });
        done(res.rules);
        toast('Rule saved');
      },
    });
    F.accountPicker($('[name=acc]', dlg.m), { filter: (a) => !a.control });
  }

  /** Reconcile a bank account to a statement. */
  async function reconcileSheet(acc) {
    const banks = BOOT.banks || [];
    if (!banks.length) { toast('Add a bank account first.', { type: 'error' }); return; }
    let account = acc || banks[0].id;
    const s = F.sheet({ title: 'Reconcile', sub: 'Statement against the books', html: '', wide: true });
    const body = $('.fwf-sheet__body', s);
    body.innerHTML = `<form class="fwf-grid3" id="rcForm"><div class="field"><label class="label" for="rcAcc">Bank account</label><select class="select" id="rcAcc" name="account">${F.bankOptions(account)}</select></div>
      <div class="field"><label class="label" for="rcDate">Statement date</label><input class="input" type="date" id="rcDate" name="date" value="${esc(BOOT.today)}" max="${esc(BOOT.today)}"></div>
      <div class="field"><label class="label" for="rcBal">Closing balance on the statement</label><input class="input fwf-amount" id="rcBal" name="statement" inputmode="decimal" placeholder="0.00"></div></form><div id="rcOut">${F.skel(3, 50)}</div>`;
    const f = $('#rcForm', body);
    const load = F.debounce(async () => {
      try {
        const d = await api.get('bank.php', { op: 'reconcile', account: f.account.value, date: f.date.value, statement: f.statement.value });
        paint(d);
      } catch (e) { $('#rcOut', body).innerHTML = F.errorBox(e); }
    }, 300);
    ['change', 'input'].forEach((ev) => f.addEventListener(ev, load));
    load();
    function paint(d) {
      const row = (label, v, hint = '', cls = '') => `<tr class="${cls}"><td>${label}${hint ? `<div class="fwf-hint" style="margin:0">${hint}</div>` : ''}</td><td class="r"><b>${esc(money(v))}</b></td></tr>`;
      const entered = f.statement.value.trim() !== '';
      $('#rcOut', body).innerHTML = `
        ${d.account.reconciled ? `<p class="fwf-hint">Last reconciled to ${esc(fmtDate(d.account.reconciled, 'always'))}.</p>` : ''}
        <table class="fwf-table" style="margin-top:4px"><tbody>
          ${row('Statement closing balance' + (entered ? '' : ' (from the imported lines)'), d.statement)}
          ${entered && !d.statement_ok ? row('Imported lines add up to', d.imported, 'Some statement lines are missing or extra. Import the full statement first.', 'is-group') : ''}
          ${row('Balance in the books', d.books, 'The ledger account ' + esc(d.account.code))}
          ${row('+ Statement lines not matched yet', d.unmatched, d.unmatched_count ? d.unmatched_count + ' line(s) — match them first' : '')}
          ${row('− In the books, not on the statement yet', d.uncleared, d.uncleared_rows.length ? 'Payments recorded but not through the bank yet' : '')}
          <tr class="is-grand"><td>Difference</td><td class="r ${Math.abs(d.difference) >= 0.005 ? 'fwf-neg' : ''}">${esc(money(d.difference))}</td></tr></tbody></table>
        ${d.uncleared_rows.length ? `<div class="fwf-sheet__sec" style="margin-top:12px"><h4>In the books, not on the statement<small>${d.uncleared_rows.length}</small></h4><div class="fwf-rows">${d.uncleared_rows.map((x) => `<div class="fwf-rowlink" data-j="${x.journal_id}"><span class="fwf-type">${icon('if-journal')}</span><span class="fwf-rows__text"><b>${esc(x.reference || '#' + x.journal_id)} · ${esc(x.description || '')}</b><small>${esc(fmtDate(x.date, 'always'))}</small></span><span class="fwf-rows__side">${esc(money(x.amount))}</span></div>`).join('')}</div></div>` : ''}
        <div class="fwf-banner fwf-banner--${d.can_close ? 'ok' : 'info'}" style="margin-top:12px">${icon(d.can_close ? 'check' : 'info')}<span>${d.can_close ? 'Everything agrees. Closing locks these lines against changes.' : !entered ? 'Enter the closing balance from your statement.' : d.unmatched_count ? 'Match the open lines, then close.' : 'Find the difference before closing.'}</span>
          ${can('reconcile') ? `<button class="btn btn--primary" type="button" id="rcClose" ${d.can_close && entered ? '' : 'disabled'}>${icon('if-close')}Close to ${esc(fmtDate(d.date, 'always'))}</button>` : ''}</div>
        ${d.history.length ? `<div class="fwf-sheet__sec" style="margin-top:12px"><h4>History</h4><div class="fwf-rows">${d.history.map((h, i) => `<div class="fwf-rowlink"><span class="fwf-type" data-t="${h.status === 'closed' ? 'success' : 'warning'}">${icon(h.status === 'closed' ? 'if-close' : 'if-unlock')}</span><span class="fwf-rows__text"><b>To ${esc(fmtDate(h.to, 'always'))}</b><small>${h.status === 'closed' ? 'Closed by ' + esc(h.by || '') + ' · ' + esc(relTime(h.at)) : 'Reopened'}</small></span><span class="fwf-rows__side">${esc(money(h.closing))}${i === 0 && h.status === 'closed' && BOOT.is_admin ? `<button class="fwf-mini" type="button" id="rcReopen">${icon('if-unlock')}Reopen</button>` : ''}</span></div>`).join('')}</div></div>` : ''}`;
      $$('[data-j]', body).forEach((x) => x.addEventListener('click', () => F.openJournal(+x.dataset.j)));
      const c = $('#rcClose', body);
      if (c) c.addEventListener('click', async () => {
        try { await api.post('bank.php', { op: 'reconcile_close', account: +f.account.value, date: f.date.value, statement: f.statement.value }); toast('Reconciled to ' + fmtDate(f.date.value, 'always')); F.closeSheet(); A.render(); }
        catch (e) { toastError(e); }
      });
      const ro = $('#rcReopen', body);
      if (ro) ro.addEventListener('click', async () => {
        const reason = await dialog({ title: 'Reopen this reconciliation?', body: 'Lines in it can change again until it is closed once more. This is noted in the audit trail.', input: { label: 'Why', placeholder: 'A line was matched to the wrong payment' }, confirm: 'Reopen', danger: true });
        if (!reason) return;
        try { await api.post('bank.php', { op: 'reconcile_reopen', account: +f.account.value, reason }); toast('Reconciliation reopened'); load(); } catch (e) { toastError(e); }
      });
    }
  }
  F.creators.bank_import = () => { if (A.state.route === 'bank') importDialog(+A.param('account') || 0); else location.href = '/finances/#bank?new=import'; };

  A.register('bank', { render: renderBank, newKind: () => 'bank_import' });
  A.bankAccOptions = bankAccOptions;
  A.signed = signed;
})();
