// /finances/js/fin-books.js — the books: the journal register (with the
// journal sheet and the journal editor), the ledger for one account and the
// chart of accounts. The close screen (period locks, year end, health
// checks) lives at the end of this file.
(function () {
  'use strict';
  const F = window.FIN;
  const A = F.app;
  const { $, $$, esc, icon, api, toast, toastError, popover, dialog, BOOT, L, lower, can, money, fmtDate, relTime } = F;
  const SOURCES = { manual: 'Manual', sales: 'Sales', receipts: 'Customer payments', purchases: 'Purchases', payments: 'Supplier payments', bank: 'Bank',
    payroll: 'Payroll', assets: 'Fixed assets', vat: L('vat'), inventory: 'Inventory', year_end: 'Year end', reversal: 'Reversals', other: 'Other' };
  const dcell = (v) => (Math.abs(v) < 0.005 ? '<span class="fwf-muted">—</span>' : esc(F.group(F.r2(v))));

  // ============================================================ journals
  async function renderJournals(ctx) {
    const p = ctx.param;
    const [from, to] = A.period('fy');
    const status = p('status', 'all');
    const source = p('source');
    A.head(BOOT.company, L('journals'), `${A.exportBtn(api.url('journals.php', { ...Object.fromEntries(ctx.params.entries()), from, to, status, format: 'csv' }), 'Export')}
      ${can('write') ? `<button class="btn btn--primary" type="button" id="newJ">${icon('plus')}<span>New ${esc(lower('journal'))}</span></button>` : ''}`);
    const nb = $('#newJ');
    if (nb) nb.addEventListener('click', () => editor());
    const tools = A.tools(`<span id="jChips"></span><span class="fwf-grow"></span>
      <button class="btn btn--sm btn--ghost" type="button" id="srcBtn">${icon('filter')}<span>${esc(source ? SOURCES[source] || source : 'All sources')}</span></button>
      ${A.periodBtn(from, to)}${A.qbox(p('q'), 'Reference, words or an amount')}${A.saveViewBtn()}`);
    A.bindPeriod(tools, from, to);
    A.bindQbox(tools);
    A.bindSaveView(tools);
    $('#srcBtn').addEventListener('click', (e) => popover(e.currentTarget, `<div class="fwm-pop__title">Where it came from</div>
      <button class="fwm-pop__item${!source ? ' is-on' : ''}" type="button" data-v="">${icon('if-journal')}All sources</button>
      ${Object.entries(SOURCES).map(([k, l]) => `<button class="fwm-pop__item${source === k ? ' is-on' : ''}" type="button" data-v="${k}">${icon(A.SOURCE_ICON[k] || 'if-journal')}${esc(l)}</button>`).join('')}`,
      { onClick: (b) => A.go('journals', A.withParams({ source: b.dataset.v, page: 1 })) }));
    A.el.view.innerHTML = F.skel(8, 44);
    const d = await api.get('journals.php', { status, source, from, to, q: p('q'), account: p('account'), min: p('min'), max: p('max'), page: p('page', 1), sort: p('sort', 'date'), dir: p('dir', 'desc') });
    if (!ctx.isCurrent()) return;
    $('#jChips').innerHTML = A.chips([['all', 'All'], ['draft', 'Drafts'], ['approved', 'Approved'], ['posted', 'Posted'], ['reversed', 'Reversed']], status, d.counts);
    A.bindChips($('#jChips'));
    const rows = d.rows;
    A.el.view.innerHTML = rows.length ? `
      <div class="fwf-sum"><span><b>${d.total}</b> journal${d.total === 1 ? '' : 's'}</span><span>Total <b>${esc(money(d.sum))}</b></span><span>${esc(F.periodLabel(from, to))}</span>
        ${p('account') ? `<span>Account <b>${esc(F.acctLabel(p('account')))}</b> <button class="fwf-link" type="button" data-clear="account">clear</button></span>` : ''}</div>
      <div class="fwf-table-wrap can-card"><table class="fwf-table"><thead><tr>${A.sortTh('date', 'Date')}${A.sortTh('ref', 'Reference')}<th>Description</th><th class="hide-narrow">Accounts</th><th>Source</th><th>Status</th>${A.sortTh('amount', 'Amount', 'r')}</tr></thead>
      <tbody>${rows.map((j) => `<tr class="fwf-row${j.status === 'reversed' ? ' is-muted' : ''}" tabindex="0" data-open="${j.id}">
        <td class="fwf-nowrap">${esc(fmtDate(j.date, 'always'))}</td><td class="fwf-nowrap"><b>${esc(j.reference || '#' + j.id)}</b></td>
        <td><div class="fwf-cell2"><b>${esc(j.description || '—')}</b><small>${esc(j.created_by || 'System')} · ${esc(relTime(j.created_at))}</small></div></td>
        <td class="hide-narrow">${j.codes.map((c) => `<span class="fwf-code" title="${esc(F.acctLabel(c))}">${esc(c)}</span>`).join(' ')}${j.lines > j.codes.length ? ` <small class="fwf-muted">+${j.lines - j.codes.length}</small>` : ''}</td>
        <td class="fwf-nowrap">${icon(A.SOURCE_ICON[j.source] || 'if-journal', 'i--sm')} ${esc(SOURCES[j.source] || j.source)}</td><td>${F.pill(j.status)}</td><td class="r"><b>${esc(money(j.total))}</b></td></tr>`).join('')}</tbody></table></div>
      <div class="fwf-cards">${rows.map((j) => `<div class="fwf-card" data-open="${j.id}" tabindex="0"><span class="fwf-type">${icon(A.SOURCE_ICON[j.source] || 'if-journal')}</span>
        <span class="fwf-card__text"><b>${esc(j.reference || '#' + j.id)} · ${esc(j.description || 'Journal')}</b><small>${esc(fmtDate(j.date, 'always'))} · ${esc(SOURCES[j.source] || j.source)}</small></span>
        <span class="fwf-card__side">${esc(money(j.total))}${F.pill(j.status)}</span></div>`).join('')}</div>
      ${A.pager(d.page, d.per, d.total)}`
      : F.emptyState('if-journal', status === 'all' && !p('q') ? 'No journals in this period' : 'Nothing matches', status === 'all' && !p('q') ? 'Journals from invoices, bills, payroll and your own entries show here.' : 'Try another filter or period.',
        can('write') ? `<button class="btn btn--primary" type="button" data-newj>${icon('plus')}New ${esc(lower('journal'))}</button>` : '');
    A.bindSort(A.el.view);
    A.bindPager(A.el.view);
    A.bindRows(A.el.view, (id) => openJournal(+id));
    $$('[data-clear]', A.el.view).forEach((b) => b.addEventListener('click', () => A.go('journals', A.withParams({ [b.dataset.clear]: '', page: 1 }))));
    const nj = $('[data-newj]', A.el.view);
    if (nj) nj.addEventListener('click', () => editor());
    if (p('id') && !ctx.keep) openJournal(+p('id'));
    if (p('new') && can('write')) { A.setParams({ new: '' }); editor(); }
  }

  /** The journal sheet (used everywhere a journal opens). */
  async function openJournal(id, onChange) {
    const s = F.sheet({ title: 'Journal #' + id, sub: 'Loading…', html: F.skel(5, 40), wide: true, onClose: () => { if (A.state.route === 'journals' && A.param('id')) A.setParams({ id: '' }); } });
    if (A.state.route === 'journals') A.setParams({ id });
    let d;
    try { d = await api.get('journals.php', { id }); } catch (e) { $('.fwf-sheet__body', s).innerHTML = F.errorBox(e); return; }
    paintJournal(s, d, onChange);
  }
  function paintJournal(s, d, onChange) {
    const j = d.journal, c = d.can;
    const refresh = async (msg) => {
      if (msg) toast(msg);
      try { const nd = await api.get('journals.php', { id: j.id }); paintJournal(s, nd, onChange); } catch (e) { toastError(e); }
      if (onChange) onChange();
      else if (A.state.route === 'journals' || A.state.route === 'overview') A.refresh();
      F.refreshBoot().catch(() => {});
    };
    $('h2', s).textContent = (j.reference || 'Journal #' + j.id) + (j.description ? ' · ' + j.description : '');
    $('.fwf-eyebrow', s).innerHTML = `${esc(SOURCES[j.source] || j.source)} · ${esc(fmtDate(j.date, 'always'))}`;
    $('.fwf-sheet__acts', s).innerHTML = `${F.pill(j.status)}
      ${c.edit ? `<button class="btn btn--sm" type="button" data-a="edit">${icon('pen')}Edit</button>` : ''}
      ${c.approve ? `<button class="btn btn--sm btn--primary" type="button" data-a="approve">${icon('if-approve')}Approve</button>` : ''}
      ${c.post ? `<button class="btn btn--sm btn--primary" type="button" data-a="post">${icon('if-post')}Post</button>` : ''}
      <button class="icon-btn" type="button" data-a="more" aria-label="More actions">${icon('more-v')}</button>`;
    const total = j.debit;
    $('.fwf-sheet__body', s).innerHTML = `
      ${d.why ? `<div class="fwf-banner fwf-banner--info">${icon('info')}<span>${esc(d.why)}</span></div>` : ''}
      ${j.reversed_by ? `<div class="fwf-banner fwf-banner--warn">${icon('if-reverse')}<span>Reversed by journal <a href="#journals?id=${j.reversed_by}" data-j="${j.reversed_by}">#${j.reversed_by}</a>. Both stay in the books; together they net to nil.</span></div>` : ''}
      ${j.reverses ? `<div class="fwf-banner fwf-banner--info">${icon('if-reverse')}<span>This journal reverses <a href="#journals?id=${j.reverses}" data-j="${j.reverses}">#${j.reverses}</a>.</span></div>` : ''}
      <dl class="fwf-kv">
        <dt>Date</dt><dd>${esc(fmtDate(j.date, 'always'))}${j.locked ? ` <span class="fwf-locked">${icon('lock', 'i--xs')}locked period</span>` : ''}</dd>
        <dt>Amount</dt><dd><b>${esc(money(total))}</b>${Math.abs(j.debit - j.credit) >= 0.005 ? ` <span class="fwf-neg">out of balance by ${esc(money(Math.abs(j.debit - j.credit)))}</span>` : ''}</dd>
        ${d.link ? `<dt>Document</dt><dd><a href="${esc(d.link.href)}">${esc(d.link.label)}${d.link.href.startsWith('/') ? ' ' + icon('external', 'i--xs') : ''}</a></dd>` : ''}
        <dt>Prepared</dt><dd>${esc(j.created_by || 'System')} · ${esc(relTime(j.created_at))}</dd>
        ${j.approved_by ? `<dt>Approved</dt><dd>${esc(j.approved_by)} · ${esc(relTime(j.approved_at))}</dd>` : ''}
        ${j.posted_by ? `<dt>Posted</dt><dd>${esc(j.posted_by)} · ${esc(relTime(j.posted_at))}</dd>` : ''}
      </dl>
      <div class="fwf-sheet__sec"><h4>${icon('if-ledger')}Lines<small>${d.lines.length}</small></h4>
        <table class="fwf-table"><thead><tr><th>Account</th><th>Description</th><th>${esc(L('vat'))}</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
        <tbody>${d.lines.map((l) => `<tr><td><a href="#ledger?account=${encodeURIComponent(l.account_code)}"><span class="fwf-code">${esc(l.account_code)}</span></a> ${esc(l.account_name || '(not on the chart)')}</td>
          <td>${esc(l.description || '')}</td><td>${esc(l.tax_code || '')}</td><td class="r">${dcell(l.debit)}</td><td class="r">${dcell(l.credit)}</td></tr>`).join('')}</tbody>
        <tfoot><tr><td colspan="3">Total</td><td class="r">${esc(F.group(j.debit))}</td><td class="r">${esc(F.group(j.credit))}</td></tr></tfoot></table></div>
      ${A.filesBlock('journal', j.id, d.files, c.attach)}
      ${d.trail.length ? `<div class="fwf-sheet__sec"><h4>${icon('history')}Audit trail</h4><div class="fwf-trail">${d.trail.map((t) => `<div><b>${esc(t.what)}</b> by ${esc(t.who)}${t.note ? ` — “${esc(t.note)}”` : ''}<small>${esc(t.at)}</small></div>`).join('')}</div></div>` : ''}`;
    A.bindFiles(s, () => refresh());
    $$('[data-j]', s).forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); openJournal(+a.dataset.j, onChange); }));
    const act = async (op, extra = {}, msg) => {
      try { await api.post('journals.php', { op, id: j.id, ...extra }); refresh(msg); } catch (e) { toastError(e); }
    };
    $$('[data-a]', s).forEach((b) => b.addEventListener('click', async () => {
      const a = b.dataset.a;
      if (a === 'edit') { const full = await api.get('journals.php', { id: j.id }); editor({ ...full.journal, lines: full.lines }, () => refresh()); return; }
      if (a === 'approve') return act('approve', {}, 'Journal approved');
      if (a === 'post') {
        if (!(await dialog({ title: 'Post this journal?', body: `Once posted, <b>${esc(money(total))}</b> is in the books on ${esc(fmtDate(j.date, 'always'))} and the journal can no longer be edited — only reversed.`, confirm: 'Post' }))) return;
        return act('post', {}, 'Journal posted');
      }
      if (a === 'more') {
        popover(b, `${c.unapprove ? `<button class="fwm-pop__item" type="button" data-v="unapprove">${icon('undo')}Back to draft</button>` : ''}
          ${c.reverse ? `<button class="fwm-pop__item" type="button" data-v="reverse">${icon('if-reverse')}Reverse…</button>` : ''}
          ${c.duplicate ? `<button class="fwm-pop__item" type="button" data-v="dup">${icon('copy')}Duplicate as a new draft</button>` : ''}
          ${c.duplicate && j.status !== 'draft' ? `<button class="fwm-pop__item" type="button" data-v="dupswap">${icon('if-reverse')}Copy with sides swapped</button>` : ''}
          <button class="fwm-pop__item" type="button" data-v="print">${icon('print')}Print</button>
          <a class="fwm-pop__item" data-v="led" href="#journals?account=${encodeURIComponent((d.lines[0] || {}).account_code || '')}">${icon('if-ledger')}Journals on the first account</a>
          ${c.delete ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" type="button" data-v="delete">${icon('trash')}Delete draft</button>` : ''}`, {
          align: 'end',
          onClick: async (it) => {
            const v = it.dataset.v;
            if (v === 'unapprove') act('unapprove', {}, 'Back to draft');
            if (v === 'print') window.print();
            if (v === 'dup' || v === 'dupswap') {
              try { const dl = await api.get('journals.php', { op: 'duplicate', id: j.id, swap: v === 'dupswap' ? 1 : '' }); editor({ description: (v === 'dupswap' ? 'Correction of ' : '') + dl.description, lines: dl.lines, date: BOOT.today }); } catch (e) { toastError(e); }
            }
            if (v === 'delete') {
              if (!(await dialog({ title: 'Delete this draft?', body: 'The draft and its lines are removed. This is noted in the audit trail.', confirm: 'Delete', danger: true }))) return;
              try { await api.post('journals.php', { op: 'delete', id: j.id }); toast('Draft deleted'); F.closeSheet(); if (onChange) onChange(); else A.render(); } catch (e) { toastError(e); }
            }
            if (v === 'reverse') reverseDialog(j, () => refresh('Journal reversed'));
          },
        });
      }
    }));
  }
  function reverseDialog(j, done) {
    const lockedHint = BOOT.locked_through ? `The books are locked through ${fmtDate(BOOT.locked_through, 'always')}.` : '';
    F.formDialog({
      title: 'Reverse ' + (j.reference || 'journal #' + j.id),
      html: `<p class="fwm-dialog__lead">A reversing journal with the sides swapped is posted. The original stays, linked to the reversal, so the audit trail shows both.</p>
        <div class="field"><label class="label" for="rvReason">Why<span class="req">*</span></label><input class="input" id="rvReason" name="reason" maxlength="255" required placeholder="Posted to the wrong account"></div>
        <div class="field"><label class="label" for="rvDate">Reversal date</label><input class="input" id="rvDate" name="date" type="date" value="${esc(j.locked ? BOOT.today : j.date)}"><div class="fwf-hint">${esc(lockedHint)} Leave the journal's own date to cancel it in the same period.</div></div>`,
      submit: 'Reverse', danger: true,
      onSubmit: async (f) => {
        if (!f.reason.value.trim()) throw new Error('Give a reason for the reversal.');
        await api.post('journals.php', { op: 'reverse', id: j.id, reason: f.reason.value.trim(), date: f.date.value });
        done();
      },
    });
  }

  /** The journal editor. j: existing draft (with lines) or a starting point. */
  function editor(j = null, onSaved) {
    j = j || {};
    const isEdit = !!j.id;
    const rules = BOOT.config.rules;
    const sod = BOOT.engine.require_sod === '1';
    const lines = (j.lines && j.lines.length ? j.lines : [{}, {}]).map((l) => ({ ...l }));
    const lineRow = (l, i) => `<tr data-i="${i}">
      <td class="c-acc"><input class="input" name="acc" placeholder="Account code or name" value="" data-code="${esc(l.account_code || '')}" aria-label="Account, line ${i + 1}"></td>
      <td class="c-desc"><input class="input" name="desc" maxlength="255" placeholder="Line description" value="${esc(l.description || '')}" aria-label="Description, line ${i + 1}"></td>
      <td class="c-tax"><select class="select" name="tax" aria-label="VAT code, line ${i + 1}">${F.taxOptions(l.tax_code_id)}</select></td>
      <td class="c-amt"><input class="input fwf-amount" name="debit" inputmode="decimal" placeholder="0.00" value="${l.debit ? esc(F.r2(l.debit).toFixed(2)) : ''}" aria-label="Debit, line ${i + 1}"></td>
      <td class="c-amt"><input class="input fwf-amount" name="credit" inputmode="decimal" placeholder="0.00" value="${l.credit ? esc(F.r2(l.credit).toFixed(2)) : ''}" aria-label="Credit, line ${i + 1}"></td>
      <td class="c-x"><button class="icon-btn" type="button" data-rm aria-label="Remove line ${i + 1}">${icon('x')}</button></td></tr>`;
    const secondary = !rules.approval ? 'post' : (sod ? '' : 'approve');
    const dlg = F.formDialog({
      title: isEdit ? 'Edit ' + (j.reference || 'journal') : 'New ' + lower('journal'), wide: true, submit: 'Save draft',
      extraFoot: secondary ? `<button class="btn" type="button" data-then="${secondary}">${icon(secondary === 'post' ? 'if-post' : 'if-approve')}Save &amp; ${secondary}</button>` : '',
      html: `<div class="fwf-grid3">
          <div class="field"><label class="label" for="jDate">Date<span class="req">*</span></label><input class="input" id="jDate" name="date" type="date" required value="${esc(j.date || BOOT.today)}"></div>
          <div class="field"><label class="label" for="jRef">Reference</label><input class="input" id="jRef" name="reference" maxlength="100" placeholder="Next JNL number" value="${esc(j.reference || '')}"></div>
          <div class="field"><label class="label" for="jMemo">Description${rules.memo_required ? '<span class="req">*</span>' : ''}</label><input class="input" id="jMemo" name="description" maxlength="255" placeholder="Accrue October rent" value="${esc(j.description || '')}"></div>
        </div>
        ${BOOT.locked_through ? `<div class="fwf-hint">${icon('lock', 'i--xs')} The books are locked through ${esc(fmtDate(BOOT.locked_through, 'always'))}; date the journal after that.</div>` : ''}
        <div class="fwf-formsec"><h4>Lines<small>Debits must equal credits</small></h4>
        <table class="fwf-lines"><thead><tr><th>Account</th><th>Description</th><th>${esc(L('vat'))}</th><th class="r">Debit</th><th class="r">Credit</th><th></th></tr></thead><tbody id="jLines">${lines.map(lineRow).join('')}</tbody></table>
        <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap"><button class="btn btn--sm btn--ghost" type="button" id="jAdd">${icon('plus')}Add a line</button><span class="fwf-grow"></span></div>
        <div class="fwf-balance" id="jBal" aria-live="polite"></div></div>`,
      onSubmit: (f, m) => submit(f, m, ''),
    });
    const m = dlg.m, form = dlg.form, tbody = $('#jLines', m);
    const bindRow = (tr) => {
      F.accountPicker($('[name=acc]', tr), { manualOnly: true, onPick: () => balance() });
      ['debit', 'credit'].forEach((n) => {
        const inp = $(`[name=${n}]`, tr);
        inp.addEventListener('input', () => { if (inp.value.trim()) $(`[name=${n === 'debit' ? 'credit' : 'debit'}]`, tr).value = ''; balance(); });
        inp.addEventListener('blur', () => { const v = F.parseMoney(inp.value); if (v !== null) inp.value = Math.abs(v).toFixed(2); balance(); });
        inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); if (tr === tbody.lastElementChild) addLine(true); else $('[name=acc]', tr.nextElementSibling).focus(); } });
      });
      $('[data-rm]', tr).addEventListener('click', () => { if (tbody.children.length > 2) { tr.remove(); balance(); } else { $$('input', tr).forEach((x) => { x.value = ''; x.dataset.code = ''; }); balance(); } });
    };
    const addLine = (focus) => {
      const t = document.createElement('tbody');
      t.innerHTML = lineRow({}, tbody.children.length);
      const tr = t.firstElementChild;
      tbody.appendChild(tr);
      bindRow(tr);
      // carry the description down
      const prev = tr.previousElementSibling;
      if (prev && $('[name=desc]', prev).value && !$('[name=desc]', tr).value) $('[name=desc]', tr).placeholder = $('[name=desc]', prev).value;
      if (focus) $('[name=acc]', tr).focus();
    };
    $$('tr', tbody).forEach(bindRow);
    $('#jAdd', m).addEventListener('click', () => addLine(true));
    const read = () => $$('tr', tbody).map((tr) => ({
      account_code: $('[name=acc]', tr).dataset.code || '', description: $('[name=desc]', tr).value.trim() || $('[name=desc]', tr).placeholder.replace('Line description', '').trim(),
      tax_code_id: +$('[name=tax]', tr).value || null, debit: F.parseMoney($('[name=debit]', tr).value) || 0, credit: F.parseMoney($('[name=credit]', tr).value) || 0,
    }));
    function balance() {
      const ls = read();
      const d = Math.round(ls.reduce((s, l) => s + l.debit, 0) * 100), c = Math.round(ls.reduce((s, l) => s + l.credit, 0) * 100);
      const diff = d - c;
      const b = $('#jBal', m);
      b.className = 'fwf-balance ' + (d > 0 && diff === 0 ? 'is-ok' : (d || c ? 'is-off' : ''));
      b.innerHTML = `<span>Debits <b>${esc(money(d / 100))}</b></span><span>Credits <b>${esc(money(c / 100))}</b></span><span class="fwf-grow"></span>
        ${diff === 0 ? (d ? `${icon('check')}<b>Balanced</b>` : '<span>Enter the amounts</span>') : `<span>Out by <b>${esc(money(Math.abs(diff) / 100))}</b></span><button class="fwf-mini" type="button" id="jFix">${icon('if-scale')}Balance on the last line</button>`}`;
      const fix = $('#jFix', m);
      if (fix) fix.addEventListener('click', () => {
        let tr = tbody.lastElementChild;
        if ($('[name=debit]', tr).value || $('[name=credit]', tr).value) { addLine(false); tr = tbody.lastElementChild; }
        $(`[name=${diff > 0 ? 'credit' : 'debit'}]`, tr).value = (Math.abs(diff) / 100).toFixed(2);
        balance();
        $('[name=acc]', tr).focus();
      });
    }
    balance();
    async function submit(f, mm, then) {
      const ls = read().filter((l) => l.account_code || l.debit || l.credit);
      const payload = { op: 'save', id: j.id || 0, date: f.date.value, reference: f.reference.value.trim(), description: f.description.value.trim(), lines: ls, then };
      const missing = read().findIndex((l) => !l.account_code && (l.debit || l.credit));
      if (missing >= 0) throw new Error(`Line ${missing + 1}: choose an account from the list.`);
      let r;
      try { r = await api.post('journals.php', payload); }
      catch (e) {
        // the draft was kept although approve / post was refused: keep editing that draft
        if (e.data && e.data.saved_id) { j.id = e.data.saved_id; if (['journals', 'overview'].includes(A.state.route)) A.render(); }
        throw e;
      }
      toast(then === 'post' ? 'Journal posted' : then === 'approve' ? 'Journal saved and approved' : 'Draft saved', { action: { label: 'Open', run: () => openJournal(r.journal.id) } });
      if (onSaved) onSaved(r);
      else if (['journals', 'overview', 'ledger'].includes(A.state.route)) A.render();
      F.refreshBoot().catch(() => {});
    }
    const then = $('[data-then]', m);
    if (then) then.addEventListener('click', async () => {
      const err = $('.fwf-formerr', m);
      err.hidden = true;
      then.disabled = true;
      try { await submit(form, m, then.dataset.then); F.closeModal(m); } catch (e) { err.textContent = e.message; err.hidden = false; } finally { then.disabled = false; }
    });
  }
  F.creators.journal = () => editor();
  F.openJournal = openJournal;
  F.journalEditor = editor;

  // ============================================================ ledger
  async function renderLedger(ctx) {
    const p = ctx.param;
    const code = p('account');
    const [from, to] = A.period('ytd');
    const a = code ? F.acct(code) : null;
    A.head(BOOT.company, a ? `${a.code} · ${a.name}` : L('ledger'), a ? `${A.exportBtn(api.url('ledger.php', { account: code, from, to, format: 'csv' }), 'Export')}
      <a class="btn btn--sm btn--ghost" href="#journals?account=${encodeURIComponent(code)}&from=${from}&to=${to}">${icon('if-journal')}<span>Journals</span></a>
      ${can('write') ? `<button class="btn btn--primary" type="button" id="ledNew">${icon('plus')}<span>Journal to this account</span></button>` : ''}` : '');
    const tools = A.tools(`<label class="fwf-qbox" style="max-width:340px;flex-basis:340px">${icon('if-tree')}<input id="ledAcc" placeholder="Choose an account…" aria-label="Account" data-code="${esc(code)}"></label>
      <span class="fwf-grow"></span>${A.periodBtn(from, to)}${a ? A.saveViewBtn() : ''}`);
    A.bindPeriod(tools, from, to);
    A.bindSaveView(tools);
    F.accountPicker($('#ledAcc'), { onPick: (x) => { if (x && x.code !== code) A.go('ledger', A.withParams({ account: x.code })); } });
    const nb = $('#ledNew');
    if (nb) nb.addEventListener('click', () => editor({ lines: [{ account_code: code }, {}] }));
    if (!code) { paintLedgerPicker(); return; }
    A.el.view.innerHTML = F.skel(8, 40);
    const d = await api.get('ledger.php', { account: code, from, to, project: p('project') });
    if (!ctx.isCurrent()) return;
    api.post('prefs.php', { op: 'recent', code }).then((r) => { BOOT.prefs = r.prefs; }).catch(() => {});
    const sign = d.account.normal === 'credit' ? 'Cr' : 'Dr';
    A.el.view.innerHTML = `<div class="fwf-page">
      <div class="fwf-stats">
        <div class="fwf-stat"><span>Opening${d.opening_from ? ' (from ' + esc(fmtDate(d.opening_from, 'always')) + ')' : ''}</span><b>${esc(money(d.opening))}</b><small>${esc(sign)} side positive</small></div>
        <div class="fwf-stat"><span>Debits</span><b>${esc(money(d.debit))}</b><small>${d.rows.length} line(s)</small></div>
        <div class="fwf-stat"><span>Credits</span><b>${esc(money(d.credit))}</b><small>${esc(F.periodLabel(from, to))}</small></div>
        <div class="fwf-stat ${d.closing !== null && d.closing < 0 ? 'is-warn' : ''}"><span>Closing</span><b>${d.closing !== null ? esc(money(d.closing)) : '—'}</b><small>${esc(F.TYPE_LABEL[d.account.type] || '')}</small></div>
      </div>
      <div class="fwf-box"><header><h3>${['revenue', 'expense'].includes(d.account.type) ? 'Each month' : 'Balance at month end'}<small>12 months</small></h3></header><div class="fwf-box__body">${F.sparkline(d.months.map((m) => m.value), 'var(--gold)', 60) || '<p class="fwf-muted">No movement in the last 12 months.</p>'}
        <div class="fwf-legend">${d.months.filter((m, i) => i % 2 === 1).map((m) => `<span>${esc(m.label)} ${esc(F.compact(m.value))}</span>`).join('')}</div></div></div>
      ${d.more ? `<div class="fwf-banner fwf-banner--info">${icon('info')}<span>Showing the first ${d.rows.length} lines. Export for the full ledger or choose a shorter period.</span></div>` : ''}
      <div class="fwf-box">${d.rows.length ? `<table class="fwf-table"><thead><tr><th>Date</th><th>Reference</th><th>Description</th><th class="hide-narrow">Against</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th></tr></thead>
        <tbody><tr class="is-group"><td>${esc(fmtDate(from, 'always'))}</td><td></td><td>Opening balance</td><td class="hide-narrow"></td><td></td><td></td><td class="r">${esc(F.group(d.opening))}</td></tr>
        ${d.rows.map((r) => `<tr class="fwf-row${r.reversed || r.reversal ? ' is-muted' : ''}" tabindex="0" data-open="${r.journal_id}"><td class="fwf-nowrap">${esc(fmtDate(r.date, 'always'))}</td><td class="fwf-nowrap"><b>${esc(r.reference || '#' + r.journal_id)}</b></td>
          <td><div class="fwf-cell2"><b>${esc(r.memo || r.description || '—')}</b>${r.description && r.description !== r.memo ? `<small>${esc(r.description)}</small>` : ''}</div></td>
          <td class="hide-narrow">${r.others.map((c) => `<span class="fwf-code" title="${esc(F.acctLabel(c))}">${esc(c)}</span>`).join(' ')}</td>
          <td class="r">${dcell(r.debit)}</td><td class="r">${dcell(r.credit)}</td><td class="r"><b>${esc(F.group(r.balance))}</b></td></tr>`).join('')}</tbody>
        <tfoot><tr><td colspan="4">Closing balance</td><td class="r">${esc(F.group(d.debit))}</td><td class="r">${esc(F.group(d.credit))}</td><td class="r">${d.closing !== null ? esc(F.group(d.closing)) : ''}</td></tr></tfoot></table>`
        : F.emptyState('if-ledger', 'No postings in this period', 'Choose a longer period, or post a journal to this account.', '', true)}</div></div>`;
    A.bindRows(A.el.view, (id) => openJournal(+id, () => A.render()));
  }
  function paintLedgerPicker() {
    const recent = (BOOT.prefs.recent || []).map(F.acct).filter(Boolean);
    const by = {};
    (BOOT.accounts || []).filter((a) => a.active).forEach((a) => { (by[a.type] = by[a.type] || []).push(a); });
    A.el.view.innerHTML = `<div class="fwf-page fwf-page--narrow">
      ${recent.length ? `<div class="fwf-box"><header><h3>Recently opened</h3></header><div class="fwf-box__body"><div class="fwf-rows">${recent.map((a) => `<a href="#ledger?account=${encodeURIComponent(a.code)}"><span class="fwf-code">${esc(a.code)}</span><span class="fwf-rows__text"><b>${esc(a.name)}</b><small>${esc(F.TYPE_LABEL[a.type])}</small></span><span class="fwf-rows__side">${icon('chev-right')}</span></a>`).join('')}</div></div></div>` : ''}
      ${Object.entries(F.TYPE_LABEL).map(([t, l]) => by[t] ? `<div class="fwf-box"><header><h3>${esc(l)}<small>${by[t].length}</small></h3></header><div class="fwf-box__body"><div class="fwf-rows">${by[t].slice(0, 400).map((a) => `<a href="#ledger?account=${encodeURIComponent(a.code)}"><span class="fwf-code">${esc(a.code)}</span><span class="fwf-rows__text"><b>${esc(a.name)}</b><small>${esc(a.subtype.replace(/_/g, ' '))}</small></span><span class="fwf-rows__side">${icon('chev-right')}</span></a>`).join('')}</div></div></div>` : '').join('')}</div>`;
    setTimeout(() => { const i = $('#ledAcc'); if (i && !F.narrow()) i.focus(); }, 50);
  }

  // ============================================================ accounts
  async function renderAccounts(ctx) {
    const p = ctx.param;
    const type = p('type', 'all');
    const showOff = p('inactive') === '1';
    const [from, to] = A.period('ytd');
    A.head(BOOT.company, L('accounts'), `${A.exportBtn(api.url('accounts.php', { from, to, format: 'csv' }), 'Export')}
      ${BOOT.is_admin ? `<button class="btn btn--sm btn--ghost" type="button" id="accImport">${icon('upload')}<span>Import</span></button>` : ''}
      ${can('write') ? `<button class="btn btn--primary" type="button" id="accNew">${icon('plus')}<span>New ${esc(lower('account'))}</span></button>` : ''}`);
    const tools = A.tools(`${A.chips([['all', 'All'], ...Object.entries(F.TYPE_LABEL)], type, {}, [])}<span class="fwf-grow"></span>
      <label class="switch"><input type="checkbox" id="accOff" ${showOff ? 'checked' : ''}><span class="switch__track"></span><span>Switched off</span></label>
      ${A.periodBtn(from, to)}${A.qbox(p('q'), 'Code or name')}`);
    A.bindChips(tools, 'type');
    A.bindPeriod(tools, from, to);
    A.bindQbox(tools);
    $('#accOff').addEventListener('change', (e) => A.go('accounts', A.withParams({ inactive: e.target.checked ? '1' : '' })));
    const nb = $('#accNew');
    if (nb) nb.addEventListener('click', () => accountEditor());
    const ib = $('#accImport');
    if (ib) ib.addEventListener('click', importDialog);
    A.el.view.innerHTML = F.skel(10, 40);
    const d = await api.get('accounts.php', { from, to });
    if (!ctx.isCurrent()) return;
    const q = p('q').toLowerCase();
    let rows = d.rows.filter((a) => (type === 'all' || a.type === type) && (showOff || a.active) && (!q || (a.code + ' ' + a.name).toLowerCase().includes(q)));
    // tree order: parents before children
    const kids = {};
    rows.forEach((a) => { if (a.parent && rows.some((x) => x.id === a.parent)) (kids[a.parent] = kids[a.parent] || []).push(a); });
    const ordered = [];
    const walk = (a, depth) => { ordered.push([a, depth]); (kids[a.id] || []).forEach((k) => walk(k, depth + 1)); };
    rows.filter((a) => !a.parent || !rows.some((x) => x.id === a.parent)).forEach((a) => walk(a, 0));
    const groups = Object.keys(F.TYPE_LABEL).filter((t) => ordered.some(([a]) => a.type === t));
    A.el.view.innerHTML = ordered.length ? `<div class="fwf-table-wrap"><table class="fwf-table"><thead><tr><th>Code</th><th>Name</th><th class="hide-narrow">Kind</th><th class="r">Balance</th><th class="r hide-narrow">Movement</th><th></th></tr></thead><tbody>
      ${groups.map((t) => `<tr class="is-group"><td colspan="3">${esc(F.TYPE_LABEL[t])}</td><td class="r">${esc(money(ordered.filter(([a]) => a.type === t).reduce((s, [a]) => s + a.balance, 0)))}</td><td class="hide-narrow"></td><td></td></tr>
        ${ordered.filter(([a]) => a.type === t).map(([a, depth]) => `<tr class="fwf-row${a.active ? '' : ' is-muted'}" tabindex="0" data-open="${esc(a.code)}">
          <td class="fwf-nowrap${depth ? ' indent-' + Math.min(2, depth) : ''}"><span class="fwf-code">${esc(a.code)}</span></td>
          <td><div class="fwf-cell2"><b>${esc(a.name)}</b><small>${[a.control ? 'Control account' : '', a.system ? 'System' : '', a.mapped ? 'Used as ' + a.mapped : '', !a.manual ? 'No manual journals' : '', a.active ? '' : 'Switched off'].filter(Boolean).map(esc).join(' · ')}</small></div></td>
          <td class="hide-narrow">${esc(a.subtype.replace(/_/g, ' '))}</td>
          <td class="r"><b class="${a.balance < 0 ? 'fwf-neg' : ''}">${a.lines ? esc(money(a.balance)) : '<span class="fwf-muted">—</span>'}</b></td>
          <td class="r hide-narrow">${Math.abs(a.movement) >= 0.005 ? esc(money(a.movement)) : '<span class="fwf-muted">—</span>'}</td>
          <td class="r"><span class="fwf-rowacts">${can('write') ? `<button class="icon-btn" type="button" data-edit="${a.id}" aria-label="Edit ${esc(a.name)}">${icon('pen')}</button>` : ''}<a class="icon-btn" href="#ledger?account=${encodeURIComponent(a.code)}" aria-label="Ledger for ${esc(a.name)}">${icon('if-ledger')}</a></span></td></tr>`).join('')}`).join('')}
      </tbody></table></div><p class="fwf-hint" style="padding:10px 22px 30px">Balances on the normal side of each account: balance-sheet accounts to ${esc(fmtDate(to, 'always'))}, income and expenses for the financial year to date. Movement is for ${esc(F.periodLabel(from, to))}.</p>`
      : F.emptyState('if-tree', 'No accounts match', 'Try another filter.', '', true);
    A.bindRows(A.el.view, (code) => A.go('ledger', { account: code, from, to }));
    $$('[data-edit]', A.el.view).forEach((b) => b.addEventListener('click', () => accountEditor(d.rows.find((a) => a.id === +b.dataset.edit), d)));
    if (p('new') && can('write')) { A.setParams({ new: '' }); accountEditor(null, d); }
  }
  function accountEditor(a = null, meta = null) {
    const isEdit = !!a;
    a = a || { type: 'expense', active: true, manual: true };
    const subtypes = BOOT.coa.subtypes || {};
    const structural = isEdit && (a.lines > 0 || a.system);
    const subtypeOpts = (t, sel) => `<option value="">— none —</option>${(subtypes[t] || []).map((s) => `<option value="${esc(s)}"${s === sel ? ' selected' : ''}>${esc(s.replace(/_/g, ' '))}</option>`).join('')}`;
    const parentOpts = (t, sel) => `<option value="">— top level —</option>${(BOOT.accounts || []).filter((x) => x.type === t && x.id !== a.id).map((x) => `<option value="${x.id}"${String(sel) === String(x.id) ? ' selected' : ''}>${esc(x.code + ' · ' + x.name)}${x.active ? '' : ' (off)'}</option>`).join('')}`;
    const dlg = F.formDialog({
      title: isEdit ? 'Edit ' + a.code + ' · ' + a.name : 'New ' + lower('account'), submit: isEdit ? 'Save' : 'Add account',
      extraFoot: isEdit ? `${a.active ? `<button class="btn btn--ghost" type="button" data-x="toggle">${icon('if-power')}Switch off</button>` : `<button class="btn btn--ghost" type="button" data-x="toggle">${icon('if-power')}Switch on</button>`}
        ${BOOT.is_admin && !a.lines && !a.system ? `<button class="btn btn--ghost" type="button" data-x="delete">${icon('trash')}Delete</button>` : ''}` : '',
      html: `${structural ? `<div class="fwf-banner fwf-banner--info">${icon('lock')}<span>${a.system ? 'System account: the' : 'Journals use this account, so the'} code, kind, subtype and normal side stay as they are.</span></div>` : ''}
        ${a.locked ? `<div class="fwf-banner fwf-banner--info">${icon('lock')}<span>SARS-statutory account: only the description and VAT code can change.</span></div>` : ''}
        <div class="fwf-grid2">
          <div class="field"><label class="label" for="acType">Kind<span class="req">*</span></label><select class="select" id="acType" name="type" ${structural || a.locked ? 'disabled' : ''}>${Object.entries(F.TYPE_LABEL).map(([k, l]) => `<option value="${k}"${a.type === k ? ' selected' : ''}>${esc(l)}</option>`).join('')}</select></div>
          <div class="field"><label class="label" for="acCode">Code<span class="req">*</span></label><input class="input" id="acCode" name="code" maxlength="12" required value="${esc(a.code || '')}" ${structural || a.locked ? 'readonly' : ''} placeholder="6150"><div class="fwf-hint" id="acBand"></div></div>
          <div class="field fwf-span2"><label class="label" for="acName">Name<span class="req">*</span></label><input class="input" id="acName" name="name" maxlength="120" required value="${esc(a.name || '')}" ${a.locked ? 'readonly' : ''} placeholder="Software subscriptions"></div>
          <div class="field"><label class="label" for="acSub">Subtype</label><select class="select" id="acSub" name="subtype" ${structural || a.locked ? 'disabled' : ''}>${subtypeOpts(a.type, a.subtype)}</select></div>
          <div class="field"><label class="label" for="acNormal">Normal side</label><select class="select" id="acNormal" name="normal" ${structural || a.locked ? 'disabled' : ''}><option value="debit"${a.normal === 'debit' ? ' selected' : ''}>Debit</option><option value="credit"${a.normal === 'credit' ? ' selected' : ''}>Credit</option></select><div class="fwf-hint">Assets and expenses are debit; the rest credit. Contra accounts swap.</div></div>
          <div class="field"><label class="label" for="acParent">Sits under</label><select class="select" id="acParent" name="parent_id" ${a.locked ? 'disabled' : ''}>${parentOpts(a.type, a.parent)}</select></div>
          <div class="field"><label class="label" for="acTax">Default ${esc(L('vat'))} code</label><select class="select" id="acTax" name="tax_code_id">${F.taxOptions(a.tax_code_id)}</select></div>
          <div class="field fwf-span2"><label class="label" for="acDesc">Description</label><textarea class="textarea" id="acDesc" name="description" maxlength="500" rows="2">${esc(a.description || '')}</textarea></div>
          <label class="switch fwf-span2"><input type="checkbox" name="manual" ${a.manual ? 'checked' : ''} ${a.control || a.locked ? 'disabled' : ''}><span class="switch__track"></span><span>Allow manual journals${a.control ? ' (control accounts are kept by their documents)' : ''}</span></label>
        </div>`,
      onSubmit: async (f) => {
        const body = { op: 'save', id: a.id || 0, code: f.code.value.trim(), name: f.name.value.trim(), type: f.type.value, subtype: f.subtype.value, normal: f.normal.value,
          parent_id: +f.parent_id.value || null, tax_code_id: +f.tax_code_id.value || null, description: f.description.value.trim(), active: a.active !== false, manual: f.manual.checked };
        const r = await api.post('accounts.php', body);
        BOOT.accounts = r.accounts;
        toast(isEdit ? 'Account saved' : 'Account added');
        if (A.state.route === 'accounts') A.render();
      },
    });
    const f = dlg.form;
    const band = () => {
      const bands = BOOT.coa.bands[f.type.value] || [];
      $('#acBand', dlg.m).textContent = bands.length ? 'Use a code in ' + bands.map((b) => b[0] + '–' + b[1]).join(' or ') : '';
    };
    band();
    f.type.addEventListener('change', () => {
      f.subtype.innerHTML = subtypeOpts(f.type.value, '');
      f.parent_id.innerHTML = parentOpts(f.type.value, '');
      f.normal.value = ['asset', 'expense'].includes(f.type.value) ? 'debit' : 'credit';
      band();
      if (!isEdit) {
        const used = new Set((BOOT.accounts || []).map((x) => x.code));
        const b = (BOOT.coa.bands[f.type.value] || [[0, 0]])[0];
        for (let c = b[0] + 10; c < b[1]; c += 10) if (!used.has(String(c))) { f.code.value = String(c); break; }
      }
    });
    $$('[data-x]', dlg.m).forEach((b) => b.addEventListener('click', async () => {
      try {
        if (b.dataset.x === 'toggle') { const r = await api.post('accounts.php', { op: 'toggle', id: a.id }); BOOT.accounts = r.accounts; toast(r.active ? 'Account switched on' : 'Account switched off'); }
        if (b.dataset.x === 'delete') {
          if (!(await dialog({ title: 'Delete ' + a.code + '?', body: 'Nothing has used this account, so it can go. This cannot be undone.', confirm: 'Delete', danger: true }))) return;
          const r = await api.post('accounts.php', { op: 'delete', id: a.id }); BOOT.accounts = r.accounts; toast('Account deleted');
        }
        F.closeModal(dlg.m);
        if (A.state.route === 'accounts') A.render();
      } catch (e) { toastError(e); }
    }));
  }
  function importDialog() {
    let csv = '';
    const dlg = F.formDialog({
      title: 'Import accounts', submit: 'Check the file', wide: true,
      html: `<p class="fwm-dialog__lead">A CSV with the columns <b>code, name, type</b> and optionally <b>subtype, normal_balance, parent_code, tax_code, description</b>. Every row is checked first; nothing is added unless all rows are fine.</p>
        <label class="fwf-drop">${icon('upload')}<b>Choose a CSV file</b><span>or paste it below</span><input type="file" accept=".csv,text/csv" hidden id="impFile"></label>
        <div class="field"><textarea class="textarea" id="impText" rows="7" placeholder="code,name,type,subtype&#10;6150,Software subscriptions,expense,operating_expense"></textarea></div><div id="impOut"></div>`,
      onSubmit: async (f, m) => {
        csv = $('#impText', m).value;
        const btn = $('[data-submit]', m);
        if (btn.dataset.ready === '1') {
          const r = await api.post('accounts.php', { op: 'import', csv, dry_run: false });
          BOOT.accounts = r.accounts;
          toast(`${r.imported} account(s) imported`);
          if (A.state.route === 'accounts') A.render();
          return;
        }
        const r = await api.post('accounts.php', { op: 'import', csv, dry_run: true });
        $('#impOut', m).innerHTML = r.errors.length ? `<div class="fwf-banner fwf-banner--danger">${icon('alert')}<span>${r.errors.map(esc).join('<br>')}</span></div>`
          : `<div class="fwf-banner fwf-banner--ok">${icon('check')}<span><b>${r.rows}</b> account(s) are ready to add.</span></div>`;
        if (!r.errors.length) { btn.dataset.ready = '1'; btn.textContent = 'Import ' + r.rows + ' account(s)'; }
        return false;
      },
    });
    $('#impFile', dlg.m).addEventListener('change', async (e) => { const fl = e.target.files[0]; if (fl) { $('#impText', dlg.m).value = await fl.text(); $('[data-submit]', dlg.m).dataset.ready = ''; } });
    $('#impText', dlg.m).addEventListener('input', () => { const b = $('[data-submit]', dlg.m); b.dataset.ready = ''; b.textContent = 'Check the file'; });
  }
  F.creators.account = () => accountEditor(null, null);

  A.register('journals', { render: renderJournals, newKind: () => 'journal' });
  A.register('ledger', { render: renderLedger, newKind: () => 'journal' });
  A.register('accounts', { render: renderAccounts, newKind: () => 'account' });
})();
