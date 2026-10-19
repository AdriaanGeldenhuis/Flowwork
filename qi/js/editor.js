// /qi/js/editor.js — the document editor: one form for quotes, invoices,
// credit notes and recurring invoices, with the paper drawn live beside it by
// the server (the same renderer as the PDF). Saving goes through
// /qi/api/document.php, which re-validates and recomputes everything; the
// amounts shown here use the same cent rounding so nothing jumps on save.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, popover, closePop, openModal, closeModal, tempModal, dialog, BOOT, money, fmtDate, parseYmd, ymd, debounce, narrow, pill, TYPE_NAMES, viewUrl, editUrl } = QI;

  const P = BOOT;
  const T = P.type;
  const NOUN = { quote: 'quote', invoice: 'invoice', credit_note: 'credit note', recurring: 'recurring invoice' }[T];
  const TODAY = P.today || ymd(new Date());
  let uidSeq = 0;
  const uid = () => 'r' + (++uidSeq);
  const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12));

  const S = {
    id: P.id, number: P.number, status: P.status, version: P.version, lock: P.lock,
    doc: Object.assign({}, P.doc),
    rows: (P.doc.rows || []).map((r) => Object.assign({ uid: uid() }, r)),
    ms: P.doc.milestones ? P.doc.milestones.map((m) => Object.assign({ uid: uid() }, m)) : null,
    customer: P.customer, invoice: P.invoice, invoices: null,
    dirty: false, saving: false, zoom: 0, seq: 0, clientKey: newKey(), lastTotals: null,
  };
  delete S.doc.rows;
  delete S.doc.milestones;
  const TAX = {};
  (P.options.tax || []).forEach((t) => { TAX[t.code] = t; });
  const CUR = {};
  (P.options.currencies || []).forEach((c) => { CUR[c.code] = c; });
  const fullLock = () => !P.can_edit || S.lock.level === 'full';
  const amountsLocked = () => fullLock() || S.lock.level === 'wording';
  const symbol = () => (CUR[S.doc.currency] || {}).symbol || S.doc.currency || 'R';

  const el = {
    head: $('#edHead'), sections: $('#edSections'), foot: $('#edFoot'), scroll: $('#edScroll'),
    paper: $('#edPaper'), wrap: $('#edPaperWrap'), stage: $('#edStageScroll'), editor: $('#editor'),
  };

  // ------------------------------------------------------------ arithmetic
  // Same as QiMoney (PHP): cents, VAT rounded per line, half away from zero.
  const rnd = (x) => { const v = Number(Number(x).toPrecision(15)); return Math.sign(v) * Math.round(Math.abs(v)); };
  function num(v) {
    if (typeof v === 'number') return isFinite(v) ? v : NaN;
    let s = String(v == null ? '' : v).replace(/[\s  ]|R(?=[\d\s-])/g, '').replace(/%$/, '');
    if (s === '') return 0;
    if (/^-?\d{1,3}(,\d{3})+(\.\d+)?$/.test(s)) s = s.replace(/,/g, '');
    else if (/^-?\d+,\d+$/.test(s)) s = s.replace(',', '.');
    return /^-?(\d+\.?\d*|\.\d+)(e-?\d+)?$/i.test(s) ? Number(s) : NaN;
  }
  const rateOf = (code) => (TAX[code] ? Number(TAX[code].rate) : 0);
  function line(r) {
    const qty = num(r.qty), price = num(r.price), disc = num(r.discount);
    const q = isNaN(qty) ? 0 : qty, p = isNaN(price) ? 0 : price, d = isNaN(disc) ? 0 : disc;
    const net = rnd((q * p - d) * 100);
    const vat = rnd(net * rateOf(r.tax_code) / 100);
    return { net, vat, gross: net + vat, before: rnd(q * p * 100), disc: rnd(d * 100) };
  }
  function totals() {
    const t = { before: 0, disc: 0, net: 0, vat: 0, byRate: {}, items: 0 };
    S.rows.forEach((r) => {
      if (r.kind !== 'item' || isEmptyRow(r)) return;
      const l = line(r);
      t.items++;
      t.before += l.before; t.disc += l.disc; t.net += l.net; t.vat += l.vat;
      const key = TAX[r.tax_code] ? TAX[r.tax_code].label : '0%';
      t.byRate[key] = t.byRate[key] || { net: 0, vat: 0, rate: rateOf(r.tax_code) };
      t.byRate[key].net += l.net; t.byRate[key].vat += l.vat;
    });
    t.total = t.net + t.vat;
    return t;
  }
  const isEmptyRow = (r) => r.kind === 'item' && !String(r.description || '').trim() && !num(r.price) && !num(r.discount);
  const m$ = (cents) => money(cents / 100, symbol());
  const fmtNum = (v, places = 2) => { const n = num(v); return isNaN(n) ? String(v) : n.toFixed(places); };
  const fmtQty = (v) => { const n = num(v); return isNaN(n) ? String(v) : String(Math.round(n * 1000) / 1000); };

  // ------------------------------------------------------------ head + foot
  function renderHead() {
    const isNew = !S.id;
    const title = T === 'recurring' ? (S.doc.template_name || (isNew ? 'New recurring invoice' : 'Recurring invoice')) : (S.number || TYPE_NAMES[T]);
    const back = isNew ? '/qi/#' + (T === 'credit_note' ? 'credit_notes' : T === 'recurring' ? 'recurring' : T + 's') : viewUrl(T, S.id);
    el.head.innerHTML = `
      <a class="icon-btn" href="${esc(back)}" id="edBack" aria-label="Back" data-tip="Back">${icon('arrow-left')}</a>
      <div class="fwq-head__title">
        <div class="fwq-eyebrow">${isNew ? 'New ' + esc(NOUN) : 'Edit ' + esc(NOUN)}${isNew && T !== 'recurring' ? ' · number on save' : ''}</div>
        <h1>${esc(title)} ${S.status && !isNew ? pill(S.status) : ''}</h1>
      </div>
      <div class="fwq-head__actions">
        <button class="icon-btn" id="edMore" type="button" aria-label="More" aria-haspopup="menu">${icon('more-h')}</button>
      </div>`;
    $('#edMore').addEventListener('click', (e) => moreMenu(e.currentTarget));
    $('#edBack').addEventListener('click', (e) => {
      if (!S.dirty) return;
      e.preventDefault();
      leaveGuard(() => { S.dirty = false; location.href = back; });
    });
  }
  async function leaveGuard(go) {
    const v = await dialog({ title: 'Leave without saving?', body: 'Your changes to this ' + esc(NOUN) + ' have not been saved.', confirm: 'Leave', cancel: 'Keep editing', danger: true });
    if (v) { clearBackup(); go(); }
  }
  function moreMenu(anchor) {
    const items = [];
    if (S.id && T !== 'recurring') items.push(`<a class="fwm-pop__item" data-v="pdf" href="/qi/ajax/download_pdf.php?type=${T}&id=${S.id}&inline=1" target="_blank" rel="noopener">${icon('download')}Open saved PDF</a>`);
    if (S.id) items.push(`<a class="fwm-pop__item" data-v="dup" href="${esc(editUrl(T) + '&from=' + T + ':' + S.id)}">${icon('copy')}Duplicate</a>`);
    if (S.id && T === 'quote') items.push(`<a class="fwm-pop__item" data-v="inv" href="${esc(editUrl('invoice') + '&from=quote:' + S.id)}">${icon('iq-invoice')}New invoice from this quote</a>`);
    if (S.id && T === 'invoice') items.push(`<a class="fwm-pop__item" data-v="rec" href="${esc(editUrl('recurring') + '&from=invoice:' + S.id)}">${icon('iq-recurring')}Make it recurring</a>`);
    if (!amountsLocked()) items.push(`<button class="fwm-pop__item" data-v="paste" type="button">${icon('table')}Paste lines from a spreadsheet</button>`);
    items.push(`<div class="fwm-pop__sep"></div><a class="fwm-pop__item" data-v="design" href="/qi/designer.php">${icon('iq-designer')}Change the look</a>`);
    items.push(`<button class="fwm-pop__item" data-v="keys" type="button">${icon('keyboard')}Keyboard shortcuts</button>`);
    popover(anchor, items.join(''), { align: 'end', width: 250, onClick: (b) => {
      if (b.dataset.v === 'paste') setTimeout(pasteLines, 0);
      if (b.dataset.v === 'keys') setTimeout(shortcuts, 0);
      if ((b.dataset.v === 'dup' || b.dataset.v === 'inv' || b.dataset.v === 'rec') && S.dirty) { clearBackup(); S.dirty = false; }
    } });
  }

  function renderFoot() {
    const t = totals();
    const ro = fullLock();
    const label = S.saving ? 'Saving…' : S.dirty ? 'Unsaved changes' : S.id ? 'All changes saved' : 'Not saved yet';
    el.foot.innerHTML = `
      <div class="fwq-ed-foot__state${S.dirty ? ' is-dirty' : ''}">${esc(label)}</div>
      <div class="fwq-ed-foot__total"><small>${T === 'credit_note' ? 'Credit' : 'Total'}</small><b>${esc(m$(t.total))}</b></div>
      ${ro ? '' : `<button class="btn btn--primary" id="edSave" type="button" ${S.saving ? 'disabled' : ''}>${icon('check')}${S.id ? 'Save' : 'Create ' + esc(NOUN)}</button>`}`;
    const b = $('#edSave');
    if (b) b.addEventListener('click', () => save({ andView: true }));
  }

  // ------------------------------------------------------------ sections
  const sec = (id, ic, title, body, aside = '') => `
    <section class="fwq-ecard" id="sec-${id}" data-sec="${id}">
      <header class="fwq-ecard__head">${icon(ic)}<h2>${title}</h2>${aside}</header>
      <div class="fwq-ecard__body">${body}</div>
    </section>`;
  const dis = (cond) => (cond ? 'disabled' : '');
  const field = (label, control, cls = '') => `<label class="field ${cls}"><span class="label">${label}</span>${control}</label>`;

  function renderAll() {
    const parts = [];
    if (S.lock.level !== 'none' || !P.can_edit) parts.push(lockBanner());
    else if (S.lock.repost) parts.push(`<div class="fwq-banner" data-tone="info">${icon('alert')}<div><b>This invoice was already issued.</b> Saving keeps its number and updates the ledger entry to match.</div></div>`);
    parts.push(sec('customer', 'users', T === 'credit_note' ? 'Customer &amp; invoice' : 'Customer', '<div id="custBox"></div>'));
    if (T === 'recurring') parts.push(sec('schedule', 'repeat', 'Schedule', scheduleBody()));
    parts.push(sec('details', 'calendar', 'Details', detailsBody()));
    parts.push(sec('lines', 'list', T === 'credit_note' ? 'What is credited' : 'Lines', '<div id="linesBox"></div>', `<span class="fwq-ecard__aside" id="linesCount"></span>`));
    if (T === 'quote' || T === 'invoice') parts.push(sec('milestones', 'coins', 'Payment schedule', '<div id="msBox"></div>'));
    parts.push(sec('notes', 'file-text', T === 'credit_note' ? 'Reason' : 'Notes &amp; terms', notesBody()));
    el.sections.innerHTML = parts.join('');
    renderCustomer();
    renderLines();
    if (T === 'quote' || T === 'invoice') renderMilestones();
    updateRateRow();
    updateScheduleSummary();
  }

  function lockBanner() {
    if (!P.can_edit) return `<div class="fwq-banner">${icon('lock')}<div>You have read-only access to Quotes &amp; Invoices.</div></div>`;
    const dup = S.id ? `<a class="btn btn--sm" href="${esc(editUrl(T) + '&from=' + T + ':' + S.id)}">${icon('copy')}Duplicate</a>` : '';
    return `<div class="fwq-banner" data-tone="${S.lock.level === 'full' ? 'muted' : 'warning'}">${icon('lock')}<div>${esc(S.lock.reason)}</div>${S.lock.level === 'full' ? dup : ''}</div>`;
  }

  function detailsBody() {
    const d = S.doc;
    const ro = amountsLocked();
    const chips = (target, list) => `<div class="fwq-chiprow" data-chips="${target}">${list.map(([v, l]) => `<button type="button" class="fwq-mini" data-days="${v}" ${dis(target === 'due_date' ? fullLock() || S.status.key === 'paid' : ro)}>${l}</button>`).join('')}</div>`;
    const cur = `<select class="select" data-f="currency" ${dis(ro || (T === 'credit_note' && d.invoice_id))}>${(P.options.currencies || []).map((c) => `<option value="${esc(c.code)}" ${c.code === d.currency ? 'selected' : ''}>${esc(c.code)} — ${esc(c.name)}</option>`).join('')}</select>`;
    const project = (P.options.projects || []).length ? field('Project', `<select class="select" data-f="project_id" ${dis(ro)}><option value="">No project</option>${P.options.projects.map((p) => `<option value="${p.id}" ${Number(d.project_id) === p.id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select>`) : '';
    const ref = field(T === 'credit_note' ? 'Reference' : 'Reference / PO number', `<input class="input" data-f="reference" maxlength="120" value="${esc(d.reference || '')}" placeholder="${T === 'credit_note' ? 'Optional' : 'e.g. PO 4471'}" ${dis(fullLock())}>`);
    let rows = '';
    if (T === 'quote') {
      rows = `<div class="row">${field('Quote date', `<input class="input" type="date" data-f="issue_date" value="${esc(d.issue_date)}" ${dis(ro)}>`)}
        ${field('Valid until', `<input class="input" type="date" data-f="expiry_date" value="${esc(d.expiry_date || '')}" ${dis(ro)}>`)}</div>
        ${chips('expiry_date', [[7, '7 days'], [14, '14 days'], [30, '30 days'], [60, '60 days'], [90, '90 days']])}`;
    } else if (T === 'invoice') {
      rows = `<div class="row">${field('Invoice date', `<input class="input" type="date" data-f="issue_date" value="${esc(d.issue_date)}" ${dis(ro)}>`)}
        ${field('Due date', `<input class="input" type="date" data-f="due_date" value="${esc(d.due_date || '')}" ${dis(fullLock() || S.status.key === 'paid')}>`)}</div>
        ${chips('due_date', [[0, 'On receipt'], [7, '7 days'], [14, '14 days'], [30, '30 days'], [60, '60 days'], ['eom', 'End of month']])}`;
    } else if (T === 'credit_note') {
      rows = `<div class="row">${field('Credit note date', `<input class="input" type="date" data-f="issue_date" value="${esc(d.issue_date)}" ${dis(ro)}>`)}</div>`;
    }
    const curRow = T === 'credit_note' ? '' : `<div class="row">${field('Currency', cur)}<div class="field" id="rateField"></div></div>`;
    return `${rows}<div class="row">${ref}${project}</div>${curRow}`;
  }

  function scheduleBody() {
    const d = S.doc;
    const ro = fullLock();
    const terms = Number(d.payment_terms_days == null ? P.defaults.payment_terms : d.payment_terms_days);
    return `
      ${field('Name', `<input class="input" data-f="template_name" maxlength="190" value="${esc(d.template_name || '')}" placeholder="e.g. Monthly retainer" ${dis(ro)}>`)}
      <div class="row">
        ${field('Bill every', `<div class="fwq-every"><input class="input" type="number" min="1" max="24" step="1" data-f="interval_count" value="${esc(d.interval_count || 1)}" ${dis(ro)}>
          <select class="select" data-f="frequency" ${dis(ro)}>${P.options.frequencies.map((f) => `<option value="${f.code}" ${f.code === d.frequency ? 'selected' : ''}>${esc(f.label)}${Number(d.interval_count) > 1 ? 's' : ''}</option>`).join('')}</select></div>`)}
        ${field('Next invoice on', `<input class="input" type="date" data-f="next_run_date" value="${esc(d.next_run_date || '')}" min="${esc(TODAY)}" ${dis(ro)}>`)}
      </div>
      <div class="row">
        ${field('Stop after <small>(optional)</small>', `<input class="input" type="date" data-f="end_date" value="${esc(d.end_date || '')}" ${dis(ro)}>`)}
        ${field('Payment terms', `<select class="select" data-f="payment_terms_days" ${dis(ro)}>${[0, 7, 14, 30, 45, 60, 90].concat([0, 7, 14, 30, 45, 60, 90].includes(terms) ? [] : [terms]).map((n) => `<option value="${n}" ${n === terms ? 'selected' : ''}>${n === 0 ? 'Due on receipt' : n + ' days'}</option>`).join('')}</select>`)}
      </div>
      <label class="switch"><input type="checkbox" data-f="auto_send" ${d.auto_send ? 'checked' : ''} ${dis(ro)}><span class="switch__track"></span>
        <span class="switch__text"><span class="switch__title">Email each invoice automatically</span><span class="hint">Sent from your mailbox with the PDF attached, to the customer's billing contact. Off: invoices wait for you to send them.</span></span></label>
      <p class="fwq-summary" id="schedSummary"></p>`;
  }

  function notesBody() {
    const d = S.doc;
    const ro = fullLock();
    if (T === 'credit_note') {
      return `${field('Why is this credit note issued?', `<select class="select" data-f="reason_code" ${dis(ro)}>${P.options.reasons.map((r) => `<option value="${r.code}" ${r.code === d.reason_code ? 'selected' : ''}>${esc(r.label)}</option>`).join('')}</select>`)}
        ${field('Reason on the credit note <small>(SARS requires it)</small>', `<textarea class="textarea" data-f="reason" rows="3" maxlength="2000" placeholder="e.g. Two balustrade panels returned unused" ${dis(ro)}>${esc(d.reason || '')}</textarea>`)}`;
    }
    const defBtns = ro ? '' : `<span class="fwq-field-actions">${P.defaults.terms && P.defaults.terms !== d.terms ? `<button type="button" class="fwq-link" data-act="terms-default">Use default</button>` : ''}${P.is_admin ? `<button type="button" class="fwq-link" data-act="terms-save">Save as default</button>` : ''}</span>`;
    return `
      ${field('Note to the customer', `<textarea class="textarea" data-f="customer_notes" rows="2" maxlength="5000" placeholder="Shown on the ${esc(NOUN)}, e.g. Thank you for your business" ${dis(ro)}>${esc(d.customer_notes || '')}</textarea>`)}
      <div class="field"><span class="label">Terms ${defBtns}</span><textarea class="textarea" data-f="terms" rows="4" maxlength="10000" placeholder="Payment terms, validity, conditions" ${dis(ro)}>${esc(d.terms || '')}</textarea></div>
      ${field('Internal note <small>(only your team sees this)</small>', `<textarea class="textarea fwq-internal" data-f="notes" rows="2" maxlength="5000" placeholder="Not shown on the document" ${dis(ro)}>${esc(d.notes || '')}</textarea>`)}`;
  }

  // ------------------------------------------------------------ customer
  function renderCustomer() {
    const box = $('#custBox');
    if (!box) return;
    const ro = amountsLocked() || (T === 'credit_note' && S.doc.invoice_id && S.id);
    const c = S.customer;
    if (!c) {
      box.innerHTML = `<div class="fwq-pick">
          ${icon('search')}<input class="fwq-pick__input" id="custSearch" type="search" placeholder="Search customers, or type a new name" autocomplete="off" spellcheck="false" ${dis(ro)}>
        </div>
        <p class="hint" style="margin:8px 2px 0">Customers come from CRM. Type a name that does not exist yet to add it here.</p>`;
      const input = $('#custSearch');
      attachSuggest(input, {
        fetch: (q) => api.get('document.php', { action: 'customers', q }).then((r) => r.customers),
        render: (c) => `<span class="fwq-avatar">${esc(initials(c.name))}</span><span class="fwm-suggest__text"><span class="fwm-suggest__name">${esc(c.name)}</span><span class="fwm-suggest__email">${esc([c.legal_name, c.email].filter(Boolean).join(' · ') || (c.kind === 'supplier' ? 'Supplier' : 'Customer'))}</span></span>${c.kind === 'supplier' ? '<span class="fwm-suggest__src">Supplier</span>' : ''}`,
        footer: (q) => `<span class="fwq-avatar is-new">${icon('plus')}</span><span class="fwm-suggest__text"><span class="fwm-suggest__name">${q ? 'Add “' + esc(q) + '” as a new customer' : 'Add a new customer'}</span><span class="fwm-suggest__email">Saved to CRM</span></span>`,
        onPick: (c) => pickCustomer(c.id),
        onFooter: (q) => newCustomer(q),
        title: (q) => (q ? 'Matches' : 'Recent'),
      });
      return;
    }
    const contacts = c.contacts || [];
    const lines = [c.legal_name, ...(c.address || [])].filter(Boolean);
    const meta = [c.email, c.phone, c.vat ? 'VAT ' + c.vat : ''].filter(Boolean);
    box.innerHTML = `<div class="fwq-ccard">
        <span class="fwq-avatar fwq-avatar--lg">${esc(initials(c.name))}</span>
        <div class="fwq-ccard__text">
          <div class="fwq-ccard__name">${esc(c.name)} ${c.kind === 'supplier' ? '<span class="fwq-pill" data-tone="muted">Supplier</span>' : ''}${c.deleted ? '<span class="fwq-pill" data-tone="danger">Deleted in CRM</span>' : ''}</div>
          ${lines.length ? `<div class="fwq-ccard__addr">${lines.map(esc).join('<br>')}</div>` : `<div class="fwq-ccard__addr is-missing">${icon('alert', 'i--sm')}No billing address in CRM${T === 'invoice' || T === 'credit_note' ? ' — tax invoices over R5,000 need one' : ''}</div>`}
          ${meta.length ? `<div class="fwq-ccard__meta">${meta.map(esc).join(' · ')}</div>` : ''}
          ${c.owing > 0 ? `<div class="fwq-ccard__owing">${icon('clock', 'i--sm')}Owes ${esc(money(c.owing, 'R'))} on ${c.open_invoices} open invoice${c.open_invoices === 1 ? '' : 's'}</div>` : ''}
        </div>
        <div class="fwq-ccard__actions">
          <a class="icon-btn icon-btn--sm" href="${esc(c.crm_url)}" target="_blank" rel="noopener" aria-label="Open in CRM" data-tip="Open in CRM">${icon('external')}</a>
          ${ro ? '' : `<button class="icon-btn icon-btn--sm" type="button" data-act="cust-clear" aria-label="Choose another customer" data-tip="Change">${icon('x')}</button>`}
        </div>
      </div>
      ${(T === 'quote' || T === 'invoice' || T === 'recurring') && contacts.length ? `<div class="row" style="margin-top:12px">${field('Attention', `<select class="select" data-f="contact_id" ${dis(amountsLocked())}><option value="">No specific person</option>${contacts.map((p) => `<option value="${p.id}" ${Number(S.doc.contact_id) === p.id ? 'selected' : ''}>${esc(p.name)}${p.role ? ' — ' + esc(p.role) : ''}${p.email ? ' · ' + esc(p.email) : ''}</option>`).join('')}</select>`)}</div>` : ''}
      ${T === 'credit_note' ? '<div id="cnInvoice"></div>' : ''}`;
    if (T === 'credit_note') renderCreditInvoice();
  }
  const initials = (n) => String(n || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';

  async function pickCustomer(id) {
    try {
      const r = await api.get('document.php', { action: 'customer', id });
      const c = r.customer;
      S.customer = c;
      S.doc.customer_id = c.id;
      S.doc.contact_id = (c.contacts || []).find((p) => p.primary) ? c.contacts.find((p) => p.primary).id : null;
      if (T === 'credit_note') { S.doc.invoice_id = null; S.invoice = null; S.invoices = null; }
      if (!S.id && c.currency && c.currency !== S.doc.currency && T !== 'credit_note') {
        S.doc.currency = c.currency;
        const sel = $('[data-f=currency]');
        if (sel) sel.value = c.currency;
        updateRateRow(true);
        toast(c.name + ' is billed in ' + c.currency + ' — the currency was switched.');
      }
      if (T === 'recurring' && !S.doc.template_name) {
        S.doc.template_name = c.name + ' — ' + ({ weekly: 'weekly', monthly: 'monthly', quarterly: 'quarterly', yearly: 'yearly' }[S.doc.frequency] || 'monthly');
        const n = $('[data-f=template_name]');
        if (n) n.value = S.doc.template_name;
        renderHead();
      }
      renderCustomer();
      changed();
    } catch (e) { toastError(e); }
  }

  function newCustomer(q) {
    const m = tempModal(`<div class="fwm-dialog__head"><h2>New customer</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" id="ncForm" autocomplete="off">
        <p class="fwm-dialog__lead">Saved to CRM, so you can use it on every document.</p>
        <div class="row">${field('Customer name', `<input class="input" name="name" maxlength="255" required value="${esc(q || '')}" autofocus>`)}${field('Registered name <small>(if different)</small>', '<input class="input" name="legal_name" maxlength="255">')}</div>
        <div class="row">${field('Email', '<input class="input" name="email" type="email" maxlength="190">')}${field('Phone', '<input class="input" name="phone" type="tel" maxlength="64">')}</div>
        <div class="row">${field('VAT number', '<input class="input" name="vat_no" maxlength="64" inputmode="numeric" placeholder="4xxxxxxxxx">')}${field('Registration number', '<input class="input" name="reg_no" maxlength="64">')}</div>
        <h3 class="fwq-subhead">Billing address</h3>
        ${field('Street', '<input class="input" name="line1" maxlength="255" autocomplete="address-line1">')}
        <div class="row">${field('Suburb', '<input class="input" name="line2" maxlength="255">')}${field('City', '<input class="input" name="city" maxlength="120">')}${field('Postal code', '<input class="input" name="postal_code" maxlength="20">')}</div>
        <h3 class="fwq-subhead">Contact person <small>(optional)</small></h3>
        <div class="row">${field('First name', '<input class="input" name="first_name" maxlength="120">')}${field('Last name', '<input class="input" name="last_name" maxlength="120">')}${field('Email', '<input class="input" name="contact_email" type="email" maxlength="190">')}</div>
      </form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="ncSave">${icon('check')}Add customer</button></div>`, 'fwm-dialog--wide');
    const form = $('#ncForm', m);
    const submit = async () => {
      const f = Object.fromEntries(new FormData(form).entries());
      if (!f.name.trim()) { form.elements.name.focus(); return; }
      const b = $('#ncSave', m);
      b.disabled = true;
      try {
        const r = await api.post('document.php', { action: 'customer_create', customer: {
          name: f.name, legal_name: f.legal_name, email: f.email, phone: f.phone, vat_no: f.vat_no, reg_no: f.reg_no,
          address: { line1: f.line1, line2: f.line2, city: f.city, postal_code: f.postal_code },
          contact: { first_name: f.first_name, last_name: f.last_name, email: f.contact_email },
        } });
        closeModal(m);
        S.customer = r.customer;
        S.doc.customer_id = r.customer.id;
        S.doc.contact_id = (r.customer.contacts || [])[0] ? r.customer.contacts[0].id : null;
        renderCustomer();
        changed();
        toast(r.customer.name + ' was added to CRM');
      } catch (e) {
        b.disabled = false;
        const map = { name: 'name', email: 'email', vat_no: 'vat_no', reg_no: 'reg_no', contact_email: 'contact_email' };
        if (e.data && map[e.data.field] && form.elements[map[e.data.field]]) form.elements[map[e.data.field]].focus();
        toastError(e);
      }
    };
    $('#ncSave', m).addEventListener('click', submit);
    form.addEventListener('submit', (e) => { e.preventDefault(); submit(); });
    openModal(m);
  }

  // credit note: which invoice is credited
  async function renderCreditInvoice() {
    const box = $('#cnInvoice');
    if (!box || !S.customer) return;
    const ro = amountsLocked();
    if (S.invoices === null && !ro) {
      box.innerHTML = '<div class="fwq-skel" style="height:40px;margin-top:12px"></div>';
      try { S.invoices = (await api.get('document.php', { action: 'invoices', customer_id: S.customer.id, except: S.id || '' })).invoices; } catch (e) { S.invoices = []; }
    }
    const list = (S.invoices || []).slice();
    if (S.invoice && !list.find((i) => i.id === S.invoice.id)) list.unshift(S.invoice);
    const inv = S.invoice;
    box.innerHTML = `<div class="row" style="margin-top:12px">${field('Credit against invoice', `<select class="select" id="cnInvSel" ${dis(ro)}>
        <option value="">No invoice — a standalone credit</option>
        ${list.map((i) => `<option value="${i.id}" ${inv && inv.id === i.id ? 'selected' : ''}>${esc(i.number)} · ${esc(fmtDate(i.date))} · ${esc(money(i.total, (CUR[i.currency] || {}).symbol || i.currency))}</option>`).join('')}
      </select>`)}</div>
      ${inv ? `<p class="hint fwq-cn-left">${icon('alert', 'i--sm')}Up to <b>${esc(money(inv.creditable, (CUR[inv.currency] || {}).symbol || inv.currency))}</b> of ${esc(inv.number)} can still be credited. The credit note uses the invoice's currency and rate.</p>` : (list.length ? '' : '<p class="hint">This customer has no issued invoices that can still be credited.</p>')}`;
    const sel = $('#cnInvSel');
    if (sel) sel.addEventListener('change', () => chooseCreditInvoice(Number(sel.value) || 0));
  }
  async function chooseCreditInvoice(id) {
    if (!id) {
      S.doc.invoice_id = null;
      S.invoice = null;
      renderCreditInvoice();
      changed();
      return;
    }
    try {
      const r = await api.get('document.php', { action: 'invoice', id, except: S.id || '' });
      S.invoice = r.invoice;
      S.doc.invoice_id = r.invoice.id;
      S.doc.currency = r.invoice.currency;
      S.doc.exchange_rate = r.invoice.exchange_rate;
      const touched = S.rows.some((x) => x.kind === 'item' && !isEmptyRow(x));
      const copy = !touched || await dialog({ title: 'Copy the invoice lines?', body: 'Replace the lines here with the ' + r.rows.length + ' line' + (r.rows.length === 1 ? '' : 's') + ' of ' + esc(r.invoice.number) + '? You can then remove what is not being credited.', confirm: 'Copy lines', cancel: 'Keep mine' });
      if (copy && r.rows.length) {
        S.rows = r.rows.map((x) => Object.assign({ uid: uid() }, x));
        renderLines();
      }
      renderCreditInvoice();
      changed();
    } catch (e) { toastError(e); }
  }

  // ------------------------------------------------------------ currency
  function updateRateRow(fetchNow) {
    const box = $('#rateField');
    if (!box) return;
    const cur = S.doc.currency;
    if (cur === P.options.base_currency || T === 'recurring') {
      box.innerHTML = T === 'recurring' && cur !== P.options.base_currency
        ? '<span class="label">Exchange rate</span><span class="hint" style="padding-top:10px">Taken from Finances → Exchange rates on each invoice date.</span>' : '';
      if (cur === P.options.base_currency) S.doc.exchange_rate = null;
      return;
    }
    const ro = amountsLocked();
    box.innerHTML = `<span class="label">Rate <small>(1 ${esc(cur)} in rand)</small></span>
      <div class="fwq-rate"><input class="input" data-f="exchange_rate" inputmode="decimal" value="${S.doc.exchange_rate ? esc(String(S.doc.exchange_rate)) : ''}" placeholder="Looked up on save" ${dis(ro)}>
      ${ro ? '' : `<button class="btn btn--sm" type="button" data-act="rate">${icon('refresh')}Look up</button>`}</div>`;
    if (fetchNow && !ro) lookupRate();
  }
  async function lookupRate() {
    try {
      const r = await api.get('document.php', { action: 'rate', currency: S.doc.currency, date: S.doc.issue_date });
      if (r.rate) {
        S.doc.exchange_rate = r.rate;
        const i = $('[data-f=exchange_rate]');
        if (i) i.value = String(r.rate);
        changed();
      } else {
        toast('No ' + S.doc.currency + ' rate is captured for ' + fmtDate(S.doc.issue_date) + '. Enter it here, or add one under Finances → Exchange rates.', { type: 'error' });
      }
    } catch (e) { toastError(e); }
  }

  // ------------------------------------------------------------ lines
  function renderLines() {
    const box = $('#linesBox');
    if (!box) return;
    const ro = amountsLocked();
    const taxOpts = (code) => (P.options.tax || []).map((t) => `<option value="${esc(t.code)}" ${t.code === code ? 'selected' : ''}>${esc(t.label)}</option>`).join('');
    const sections = sectionNets();
    const rows = S.rows.map((r, i) => {
      if (r.kind === 'heading') {
        return `<div class="fwq-li fwq-li--heading" data-uid="${r.uid}">
          ${ro ? '<span class="fwq-li__grip"></span>' : `<button class="fwq-li__grip" type="button" aria-label="Drag to reorder" tabindex="-1">${icon('grip')}</button>`}
          <input class="fwq-li__title" data-rf="title" maxlength="255" value="${esc(r.title || '')}" placeholder="Section heading, e.g. Site preparation" ${dis(ro)}>
          <span class="fwq-li__secnet">${sections[r.uid] != null ? esc(m$(sections[r.uid])) : ''}</span>
          ${ro ? '' : `<button class="icon-btn icon-btn--sm fwq-li__menu" type="button" data-act="row-menu" aria-label="Line options">${icon('more-h')}</button>`}
        </div>`;
      }
      const l = line(r);
      const d = num(r.discount);
      return `<div class="fwq-li" data-uid="${r.uid}">
        ${ro ? '<span class="fwq-li__grip"></span>' : `<button class="fwq-li__grip" type="button" aria-label="Drag to reorder" tabindex="-1">${icon('grip')}</button>`}
        <div class="fwq-li__main">
          <textarea class="fwq-li__desc" data-rf="description" rows="1" maxlength="5000" placeholder="Description of the work or item" ${dis(ro)}>${esc(r.description || '')}</textarea>
          <div class="fwq-li__nums">
            <label><span>Qty</span><input data-rf="qty" inputmode="decimal" value="${esc(r.qtyText != null ? r.qtyText : fmtQty(r.qty))}" ${dis(ro)}></label>
            <label class="fwq-li__unit"><span>Unit</span><input data-rf="unit" list="qiUnits" maxlength="50" value="${esc(r.unit || '')}" placeholder="ea" ${dis(ro)}></label>
            <label class="fwq-li__price"><span>Price</span><input data-rf="price" inputmode="decimal" value="${esc(r.priceText != null ? r.priceText : fmtNum(r.price))}" ${dis(ro)}></label>
            <label><span>Discount</span><input data-rf="discount" inputmode="decimal" value="${esc(r.discText != null ? r.discText : (d ? fmtNum(r.discount) : ''))}" placeholder="0 or 10%" ${dis(ro)}></label>
            <label class="fwq-li__vat"><span>VAT</span><select data-rf="tax_code" ${dis(ro)}>${taxOpts(r.tax_code)}</select></label>
          </div>
        </div>
        <div class="fwq-li__side">
          <span class="fwq-li__amt" data-amt>${esc(m$(l.net))}</span>
          <span class="fwq-li__sub" data-sub>${l.disc ? '−' + esc(m$(l.disc)) + ' discount' : ''}</span>
          ${ro ? '' : `<button class="icon-btn icon-btn--sm fwq-li__menu" type="button" data-act="row-menu" aria-label="Line options">${icon('more-h')}</button>`}
        </div>
      </div>`;
    }).join('');
    box.innerHTML = `<div class="fwq-lines" id="lines">${rows || '<p class="hint" style="padding:6px 4px">No lines yet.</p>'}</div>
      ${ro ? '' : `<div class="fwq-lines__add">
        <button class="btn btn--sm" type="button" data-act="add-line">${icon('plus')}Add line</button>
        ${T !== 'credit_note' ? `<button class="btn btn--sm btn--ghost" type="button" data-act="add-heading">${icon('iq-heading')}Add heading</button>` : ''}
        ${T !== 'credit_note' && (P.options.projects || []).length ? `<button class="btn btn--sm btn--ghost" type="button" data-act="import">${icon('kanban')}From a project board</button>` : ''}
        <span class="fwq-grow"></span><span class="fwq-kbdhint hide-narrow"><kbd class="fwm-kbd">Ctrl</kbd>+<kbd class="fwm-kbd">Enter</kbd> new line</span>
      </div>`}
      <div class="fwq-tot" id="sumBox"></div>
      <datalist id="qiUnits">${P.options.units.map((u) => `<option value="${esc(u)}">`).join('')}</datalist>`;
    $$('.fwq-li__desc', box).forEach(autoGrow);
    renderTotals();
  }
  function sectionNets() {
    const out = {};
    let cur = null;
    S.rows.forEach((r) => {
      if (r.kind === 'heading') { cur = r.uid; out[cur] = null; return; }
      if (cur && !isEmptyRow(r)) out[cur] = (out[cur] || 0) + line(r).net;
    });
    return out;
  }
  function renderTotals() {
    const t = totals();
    const box = $('#sumBox');
    const cnt = $('#linesCount');
    if (cnt) cnt.textContent = t.items ? t.items + ' line' + (t.items === 1 ? '' : 's') : '';
    if (box) {
      const rates = Object.entries(t.byRate).filter(([, v]) => v.rate > 0);
      box.innerHTML = `
        ${t.disc ? `<div class="fwq-tot__row"><span>Before discount</span><b>${esc(m$(t.before))}</b></div><div class="fwq-tot__row is-disc"><span>Discount</span><b>−${esc(m$(t.disc))}</b></div>` : ''}
        <div class="fwq-tot__row"><span>Subtotal</span><b>${esc(m$(t.net))}</b></div>
        ${rates.length ? rates.map(([k, v]) => `<div class="fwq-tot__row"><span>VAT ${esc(k)}${rates.length > 1 ? ' on ' + esc(m$(v.net)) : ''}</span><b>${esc(m$(v.vat))}</b></div>`).join('') : `<div class="fwq-tot__row"><span>VAT</span><b>${esc(m$(0))}</b></div>`}
        <div class="fwq-tot__row is-total"><span>${T === 'credit_note' ? 'Total credit' : 'Total'}${S.doc.currency !== P.options.base_currency ? ' (' + esc(S.doc.currency) + ')' : ''}</span><b>${esc(m$(t.total))}</b></div>
        ${S.doc.currency !== P.options.base_currency && num(S.doc.exchange_rate) > 0 ? `<div class="fwq-tot__row is-fx"><span>In rand at ${esc(String(S.doc.exchange_rate))}</span><b>${esc(money(t.total / 100 * num(S.doc.exchange_rate), 'R'))}</b></div>` : ''}`;
    }
    $$('.fwq-li').forEach((rowEl) => {
      const r = S.rows.find((x) => x.uid === rowEl.dataset.uid);
      if (!r) return;
      if (r.kind === 'heading') {
        const n = sectionNets()[r.uid];
        $('.fwq-li__secnet', rowEl).textContent = n != null ? m$(n) : '';
        return;
      }
      const l = line(r);
      $('[data-amt]', rowEl).textContent = m$(l.net);
      $('[data-sub]', rowEl).textContent = l.disc ? '−' + m$(l.disc) + ' discount' : '';
    });
    renderFoot();
    if (S.ms) renderMsTotals();
  }
  function autoGrow(t) {
    t.style.height = 'auto';
    t.style.height = Math.min(220, t.scrollHeight + 2) + 'px';
  }
  function addRow(kind, afterUid, focus = true, data = {}) {
    const r = kind === 'heading'
      ? Object.assign({ uid: uid(), kind: 'heading', title: '' }, data)
      : Object.assign({ uid: uid(), kind: 'item', description: '', qty: 1, unit: '', price: 0, discount: 0, tax_code: P.options.default_tax_code }, data);
    const i = afterUid ? S.rows.findIndex((x) => x.uid === afterUid) : -1;
    if (i >= 0) S.rows.splice(i + 1, 0, r); else S.rows.push(r);
    renderLines();
    changed();
    if (focus) {
      const rowEl = $(`.fwq-li[data-uid="${r.uid}"]`);
      const f = rowEl && $(kind === 'heading' ? '[data-rf=title]' : '[data-rf=description]', rowEl);
      if (f) { f.focus(); rowEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
    }
    return r;
  }
  function rowMenu(anchor, r) {
    const i = S.rows.indexOf(r);
    popover(anchor, `
      <button class="fwm-pop__item" data-v="below" type="button">${icon('plus')}Insert line below</button>
      ${T !== 'credit_note' ? `<button class="fwm-pop__item" data-v="heading" type="button">${icon('iq-heading')}Insert heading above</button>` : ''}
      <button class="fwm-pop__item" data-v="dup" type="button">${icon('copy')}Duplicate</button>
      <button class="fwm-pop__item" data-v="up" type="button" ${i === 0 ? 'disabled' : ''}>${icon('arrow-up')}Move up<small>Alt ↑</small></button>
      <button class="fwm-pop__item" data-v="down" type="button" ${i === S.rows.length - 1 ? 'disabled' : ''}>${icon('arrow-down')}Move down<small>Alt ↓</small></button>
      <div class="fwm-pop__sep"></div>
      <button class="fwm-pop__item is-danger" data-v="del" type="button">${icon('trash')}Remove</button>`, { align: 'end', width: 220, onClick: (b) => {
      const v = b.dataset.v;
      if (v === 'below') setTimeout(() => addRow('item', r.uid), 0);
      if (v === 'heading') {
        const h = { uid: uid(), kind: 'heading', title: '' };
        S.rows.splice(S.rows.indexOf(r), 0, h);
        renderLines(); changed();
        setTimeout(() => { const f = $(`.fwq-li[data-uid="${h.uid}"] [data-rf=title]`); if (f) f.focus(); }, 0);
      }
      if (v === 'dup') {
        const c = Object.assign({}, r, { uid: uid() });
        delete c.ref; delete c.src;
        S.rows.splice(S.rows.indexOf(r) + 1, 0, c);
        renderLines(); changed();
      }
      if (v === 'up' || v === 'down') moveRow(r, v === 'up' ? -1 : 1);
      if (v === 'del') removeRow(r);
    } });
  }
  function moveRow(r, dir) {
    const i = S.rows.indexOf(r), j = i + dir;
    if (i < 0 || j < 0 || j >= S.rows.length) return;
    S.rows.splice(i, 1);
    S.rows.splice(j, 0, r);
    renderLines();
    changed();
    const rowEl = $(`.fwq-li[data-uid="${r.uid}"]`);
    if (rowEl) { const f = $(r.kind === 'heading' ? '[data-rf=title]' : '[data-rf=description]', rowEl); if (f) f.focus(); }
  }
  function removeRow(r) {
    const i = S.rows.indexOf(r);
    if (i < 0) return;
    S.rows.splice(i, 1);
    if (!S.rows.some((x) => x.kind === 'item')) S.rows.push({ uid: uid(), kind: 'item', description: '', qty: 1, unit: '', price: 0, discount: 0, tax_code: P.options.default_tax_code });
    renderLines();
    changed();
    const label = r.kind === 'heading' ? 'Heading removed' : 'Line removed';
    toast(label, { action: { label: 'Undo', run: () => { S.rows.splice(Math.min(i, S.rows.length), 0, r); renderLines(); changed(); } } });
  }

  // drag to reorder (mouse and touch)
  function startDrag(e, grip) {
    const rowEl = grip.closest('.fwq-li');
    const list = $('#lines');
    if (!rowEl || !list) return;
    e.preventDefault();
    grip.setPointerCapture(e.pointerId);
    rowEl.classList.add('is-dragging');
    list.classList.add('is-sorting');
    const move = (ev) => {
      const after = $$('.fwq-li:not(.is-dragging)', list).find((x) => { const b = x.getBoundingClientRect(); return ev.clientY < b.top + b.height / 2; });
      if (after) list.insertBefore(rowEl, after); else list.appendChild(rowEl);
      const sr = el.scroll.getBoundingClientRect();
      if (ev.clientY < sr.top + 40) el.scroll.scrollTop -= 12;
      if (ev.clientY > sr.bottom - 40) el.scroll.scrollTop += 12;
    };
    const up = () => {
      grip.removeEventListener('pointermove', move);
      grip.removeEventListener('pointerup', up);
      grip.removeEventListener('pointercancel', up);
      rowEl.classList.remove('is-dragging');
      list.classList.remove('is-sorting');
      const order = $$('.fwq-li', list).map((x) => x.dataset.uid);
      const before = S.rows.map((x) => x.uid).join();
      S.rows.sort((a, b) => order.indexOf(a.uid) - order.indexOf(b.uid));
      if (S.rows.map((x) => x.uid).join() !== before) { renderLines(); changed(); }
    };
    grip.addEventListener('pointermove', move);
    grip.addEventListener('pointerup', up);
    grip.addEventListener('pointercancel', up);
  }

  // paste rows from Excel / Sheets: description, qty, unit price (tab separated)
  function pasteLines() {
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Paste lines</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><p class="fwm-dialog__lead">Copy rows from Excel or Google Sheets and paste them below. Columns: <b>description</b>, <b>quantity</b>, <b>unit price</b> — and optionally <b>unit</b> after the quantity.</p>
        <textarea class="textarea" id="pasteArea" rows="9" placeholder="Site clearing&#9;1&#9;18500&#10;Compaction&#9;240&#9;m²&#9;38.50"></textarea><p class="hint" id="pasteHint" style="margin-top:8px"></p></div>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="pasteGo" disabled>${icon('plus')}Add lines</button></div>`, 'fwm-dialog--wide');
    const area = $('#pasteArea', m);
    const parse = () => area.value.split(/\r?\n/).map((l) => l.split('\t').map((c) => c.trim())).filter((c) => c.join('') !== '').map((c) => {
      const nums = c.slice(1);
      let unit = '';
      if (nums.length >= 3 && isNaN(num(nums[1]))) unit = nums.splice(1, 1)[0];
      const qty = nums.length >= 2 ? num(nums[0]) : 1;
      const price = num(nums.length >= 2 ? nums[1] : nums[0] || 0);
      return { description: c[0], qty: isNaN(qty) ? 1 : qty, unit, price: isNaN(price) ? 0 : price };
    }).filter((r) => r.description);
    area.addEventListener('input', () => {
      const rows = parse();
      $('#pasteHint', m).textContent = rows.length ? rows.length + ' line' + (rows.length === 1 ? '' : 's') + ' found' : '';
      $('#pasteGo', m).disabled = !rows.length;
    });
    $('#pasteGo', m).addEventListener('click', () => {
      const rows = parse();
      S.rows = S.rows.filter((r) => !isEmptyRow(r));
      rows.forEach((r) => S.rows.push({ uid: uid(), kind: 'item', description: r.description, qty: r.qty, unit: r.unit, price: r.price, discount: 0, tax_code: P.options.default_tax_code }));
      closeModal(m);
      renderLines();
      changed();
      toast(rows.length + ' line' + (rows.length === 1 ? '' : 's') + ' added');
    });
    openModal(m);
  }

  // import a project board (groups become headings)
  async function importBoard() {
    const projects = P.options.projects || [];
    let pid = Number(S.doc.project_id) || 0;
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Lines from a project board</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><p class="fwm-dialog__lead">Every item on the board becomes a line, under its group as a heading. Quantity, price and VAT come from the board's number columns.</p>
        <div class="row">${field('Project', `<select class="select" id="ibProject"><option value="">Choose…</option>${projects.map((p) => `<option value="${p.id}" ${p.id === pid ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select>`)}
        ${field('Board', '<select class="select" id="ibBoard" disabled><option value="">Choose a project first</option></select>')}</div></div>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="ibGo" disabled>${icon('plus')}Add lines</button></div>`, 'fwm-dialog--wide');
    const pSel = $('#ibProject', m), bSel = $('#ibBoard', m), go = $('#ibGo', m);
    const loadBoards = async () => {
      bSel.disabled = true;
      go.disabled = true;
      if (!pSel.value) { bSel.innerHTML = '<option value="">Choose a project first</option>'; return; }
      bSel.innerHTML = '<option>Loading…</option>';
      try {
        const res = await fetch('/qi/ajax/get_project_boards.php?project_id=' + encodeURIComponent(pSel.value), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const d = await res.json();
        const boards = d.boards || [];
        bSel.innerHTML = boards.length ? boards.map((b) => `<option value="${b.board_id}">${esc(b.name)}</option>`).join('') : '<option value="">This project has no boards you can open</option>';
        bSel.disabled = !boards.length;
        go.disabled = !boards.length;
      } catch (e) { bSel.innerHTML = '<option value="">Could not load boards</option>'; }
    };
    pSel.addEventListener('change', loadBoards);
    go.addEventListener('click', async () => {
      go.disabled = true;
      try {
        const d = await api.ajax('import_from_project.php', { board_id: Number(bSel.value) });
        const items = d.items || [];
        if (!items.length) { toast('That board has no items to import.'); go.disabled = false; return; }
        S.rows = S.rows.filter((r) => !isEmptyRow(r));
        const std = P.options.default_tax_code;
        const zero = TAX.ZERO ? 'ZERO' : std;
        items.forEach((it) => {
          if (it.type === 'heading') S.rows.push({ uid: uid(), kind: 'heading', title: it.description });
          else S.rows.push({ uid: uid(), kind: 'item', description: it.description, qty: it.quantity, unit: '', price: it.unit_price, discount: 0, tax_code: Number(it.tax_rate) > 0 ? std : zero });
        });
        if (!S.doc.project_id && pSel.value) { S.doc.project_id = Number(pSel.value); const ps = $('[data-f=project_id]'); if (ps) ps.value = pSel.value; }
        closeModal(m);
        renderLines();
        changed();
        toast(items.filter((i) => i.type !== 'heading').length + ' lines imported');
      } catch (e) { go.disabled = false; toastError(e); }
    });
    openModal(m);
    if (pid) loadBoards();
  }

  // ------------------------------------------------------------ milestones
  function renderMilestones() {
    const box = $('#msBox');
    if (!box) return;
    const ro = amountsLocked();
    if (!S.ms) {
      box.innerHTML = `<label class="switch"><input type="checkbox" id="msToggle" ${dis(ro)}><span class="switch__track"></span>
        <span class="switch__text"><span class="switch__title">Split the total into payments</span><span class="hint">A deposit and a balance, or progress payments — shown on the ${esc(NOUN)} and tracked as payments come in.</span></span></label>`;
      return;
    }
    const rows = S.ms.map((m, i) => `<div class="fwq-ms" data-uid="${m.uid}">
        <span class="fwq-ms__no">${i + 1}</span>
        <input class="input fwq-ms__label" data-mf="label" maxlength="190" value="${esc(m.label || '')}" placeholder="e.g. Deposit" ${dis(ro)}>
        <div class="fwq-ms__val">
          <div class="seg seg--xs" role="group" aria-label="Percentage or amount"><button type="button" data-mode="percent" class="${m.mode !== 'amount' ? 'is-on' : ''}" ${dis(ro)}>%</button><button type="button" data-mode="amount" class="${m.mode === 'amount' ? 'is-on' : ''}" ${dis(ro)}>${esc(symbol())}</button></div>
          <input class="input" data-mf="${m.mode === 'amount' ? 'amount' : 'percentage'}" inputmode="decimal" value="${esc(m.mode === 'amount' ? fmtNum(m.amount) : String(Math.round(num(m.percentage) * 100) / 100))}" ${dis(ro)}>
        </div>
        <input class="input fwq-ms__date" type="date" data-mf="due_date" value="${esc(m.due_date || '')}" aria-label="Due date" ${dis(ro)}>
        <span class="fwq-ms__amt" data-msamt></span>
        ${ro ? '' : `<button class="icon-btn icon-btn--sm" type="button" data-act="ms-del" aria-label="Remove payment">${icon('x')}</button>`}
      </div>`).join('');
    box.innerHTML = `${ro ? '' : `<div class="fwq-chiprow" style="margin:0 0 10px">
        <span class="hint">Quick split:</span>
        <button type="button" class="fwq-mini" data-preset="50,50">50 / 50</button>
        <button type="button" class="fwq-mini" data-preset="30,70">30 / 70</button>
        <button type="button" class="fwq-mini" data-preset="30,40,30">30 / 40 / 30</button>
        <button type="button" class="fwq-mini" data-preset="25,25,25,25">4 × 25</button>
        <span class="fwq-grow"></span><button type="button" class="fwq-link" data-act="ms-off">Turn off</button></div>`}
      <div class="fwq-ms-list">${rows}</div>
      <div class="fwq-ms-foot">${ro ? '' : `<button class="btn btn--sm btn--ghost" type="button" data-act="ms-add">${icon('plus')}Add payment</button>`}<span class="fwq-grow"></span><span id="msTotal"></span></div>
      <div class="fwq-ms-bar"><i id="msBar"></i></div>`;
    renderMsTotals();
  }
  function msCents(m, totalC) { return m.mode === 'amount' ? rnd(num(m.amount) * 100) : rnd(totalC * num(m.percentage) / 100); }
  function renderMsTotals() {
    const t = totals().total;
    let sum = 0;
    S.ms.forEach((m) => {
      const c = msCents(m, t);
      sum += isNaN(c) ? 0 : c;
      const amt = $(`.fwq-ms[data-uid="${m.uid}"] [data-msamt]`);
      if (amt) amt.textContent = m.mode === 'amount' ? (t ? (Math.round(c / t * 10000) / 100) + '%' : '') : m$(isNaN(c) ? 0 : c);
    });
    const left = t - sum;
    const ok = Math.abs(left) <= S.ms.length;
    const tot = $('#msTotal');
    if (tot) {
      tot.className = 'fwq-ms-total' + (ok ? ' is-ok' : ' is-off');
      tot.innerHTML = ok ? `${icon('check', 'i--sm')}Adds up to the total` : left > 0 ? `${esc(m$(left))} still to allocate <button type="button" class="fwq-link" data-act="ms-fill">Add to last</button>` : `${esc(m$(-left))} too much`;
    }
    const bar = $('#msBar');
    if (bar) { bar.style.width = (t > 0 ? Math.min(100, sum / t * 100) : 0) + '%'; bar.classList.toggle('is-over', sum - t > S.ms.length); }
  }
  function msPreset(list) {
    const labels = list.length === 2 ? ['Deposit', 'Balance on completion'] : list.map((_, i) => i === 0 ? 'Deposit' : i === list.length - 1 ? 'Final payment' : 'Progress payment ' + i);
    S.ms = list.map((p, i) => ({ uid: uid(), label: labels[i], mode: 'percent', percentage: Number(p), amount: 0, due_date: null }));
    renderMilestones();
    changed();
  }

  // ------------------------------------------------------------ schedule (recurring)
  function updateScheduleSummary() {
    const p = $('#schedSummary');
    if (!p) return;
    const d = S.doc;
    const n = Math.max(1, Number(d.interval_count) || 1);
    const unit = { weekly: 'week', monthly: 'month', quarterly: 'quarter', yearly: 'year' }[d.frequency] || 'month';
    const day = parseYmd(d.next_run_date);
    const every = n === 1 ? 'every ' + unit : 'every ' + n + ' ' + unit + 's';
    const on = day && (unit === 'month' || unit === 'quarter') ? ', on the ' + ordinal(day.getDate()) + (day.getDate() > 28 ? ' (or the last day of shorter months)' : '') : day && unit === 'week' ? ' on ' + ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][day.getDay()] : '';
    p.innerHTML = day ? `${icon('repeat', 'i--sm')}<span>First invoice <b>${esc(fmtDate(d.next_run_date))}</b>, then ${esc(every + on)}${d.end_date ? ', until ' + esc(fmtDate(d.end_date)) : ''}. Each invoice is issued ${d.auto_send ? 'and emailed to the customer' : 'and waits for you to send it'}.</span>` : '';
  }
  const ordinal = (n) => n + (n % 10 === 1 && n % 100 !== 11 ? 'st' : n % 10 === 2 && n % 100 !== 12 ? 'nd' : n % 10 === 3 && n % 100 !== 13 ? 'rd' : 'th');

  // ------------------------------------------------------------ events
  el.sections.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.f) {
      S.doc[t.dataset.f] = t.type === 'checkbox' ? t.checked : t.value;
      if (t.dataset.f === 'template_name') renderHead();
      if (t.dataset.f === 'exchange_rate') renderTotals();
      changed();
      return;
    }
    const rowEl = t.closest('.fwq-li');
    if (rowEl && t.dataset.rf) {
      const r = S.rows.find((x) => x.uid === rowEl.dataset.uid);
      if (!r) return;
      const f = t.dataset.rf;
      if (f === 'description') { r.description = t.value; autoGrow(t); suggestItems(t, r); }
      else if (f === 'title') r.title = t.value;
      else if (f === 'unit') r.unit = t.value;
      else if (f === 'qty') { r.qtyText = t.value; r.qty = num(t.value); if (r.discPct != null) r.discount = Math.round(num(r.qty) * num(r.price) * r.discPct) / 100; }
      else if (f === 'price') { r.priceText = t.value; r.price = num(t.value); if (r.discPct != null) r.discount = Math.round(num(r.qty) * num(r.price) * r.discPct) / 100; }
      else if (f === 'discount') {
        r.discText = t.value;
        if (/%\s*$/.test(t.value)) { r.discPct = num(t.value); r.discount = isNaN(r.discPct) ? NaN : Math.round(num(r.qty) * num(r.price) * r.discPct) / 100; }
        else { r.discPct = null; r.discount = num(t.value); }
      }
      t.classList.toggle('is-bad', (f === 'qty' || f === 'price' || f === 'discount') && isNaN(num(t.value)));
      renderTotals();
      changed();
      return;
    }
    const msEl = t.closest('.fwq-ms');
    if (msEl && t.dataset.mf) {
      const m = S.ms.find((x) => x.uid === msEl.dataset.uid);
      if (!m) return;
      m[t.dataset.mf] = t.dataset.mf === 'percentage' || t.dataset.mf === 'amount' ? t.value : t.value || null;
      renderMsTotals();
      changed();
    }
  });
  el.sections.addEventListener('change', (e) => {
    const t = e.target;
    if (t.dataset.f) {
      S.doc[t.dataset.f] = t.type === 'checkbox' ? t.checked : t.value;
      if (t.dataset.f === 'currency') { S.doc.exchange_rate = null; updateRateRow(true); renderLines(); if (S.ms) renderMilestones(); }
      if (t.dataset.f === 'issue_date' && S.doc.currency !== P.options.base_currency && !amountsLocked()) lookupRate();
      if (['frequency', 'interval_count', 'next_run_date', 'end_date', 'auto_send'].includes(t.dataset.f)) {
        if (t.dataset.f === 'frequency' || t.dataset.f === 'interval_count') {
          const sel = $('[data-f=frequency]');
          if (sel) Array.from(sel.options).forEach((o) => { o.textContent = o.textContent.replace(/s$/, '') + (Number(S.doc.interval_count) > 1 ? 's' : ''); });
        }
        updateScheduleSummary();
      }
      if (t.dataset.f === 'project_id' && t.value && !S.doc.customer_id) {
        const p = (P.options.projects || []).find((x) => x.id === Number(t.value));
        if (p && p.client_id) pickCustomer(p.client_id);
      }
      changed();
      return;
    }
    const rowEl = t.closest('.fwq-li');
    if (rowEl && t.dataset.rf === 'tax_code') {
      const r = S.rows.find((x) => x.uid === rowEl.dataset.uid);
      if (r) { r.tax_code = t.value; renderTotals(); changed(); }
    }
    if (t.id === 'msToggle' && t.checked) msPreset([50, 50]);
  });
  // tidy numbers when a field is left
  el.sections.addEventListener('focusout', (e) => {
    const t = e.target;
    const rowEl = t.closest && t.closest('.fwq-li');
    if (!rowEl || !t.dataset.rf) return;
    const r = S.rows.find((x) => x.uid === rowEl.dataset.uid);
    if (!r) return;
    if (t.dataset.rf === 'price' && !isNaN(num(t.value))) { r.priceText = null; t.value = fmtNum(r.price); }
    if (t.dataset.rf === 'qty' && !isNaN(num(t.value))) { r.qtyText = null; t.value = fmtQty(r.qty); }
    if (t.dataset.rf === 'discount' && r.discPct == null && !isNaN(num(t.value))) { r.discText = null; t.value = num(t.value) ? fmtNum(r.discount) : ''; }
  });
  el.sections.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act], [data-days], [data-preset], [data-mode]');
    if (!b || b.disabled) return;
    if (b.dataset.days != null) {
      const target = b.closest('[data-chips]').dataset.chips;
      const base = parseYmd(S.doc.issue_date) || new Date();
      const d = b.dataset.days === 'eom' ? new Date(base.getFullYear(), base.getMonth() + 1, 0) : new Date(base.getFullYear(), base.getMonth(), base.getDate() + Number(b.dataset.days));
      S.doc[target] = ymd(d);
      const i = $(`[data-f="${target}"]`);
      if (i) i.value = S.doc[target];
      changed();
      return;
    }
    if (b.dataset.preset) { msPreset(b.dataset.preset.split(',')); return; }
    if (b.dataset.mode) {
      const m = S.ms.find((x) => x.uid === b.closest('.fwq-ms').dataset.uid);
      if (!m || m.mode === b.dataset.mode) return;
      const t = totals().total;
      if (b.dataset.mode === 'amount') { m.amount = (msCents(m, t) / 100).toFixed(2); m.mode = 'amount'; }
      else { m.percentage = t ? Math.round(msCents(m, t) / t * 10000) / 100 : 0; m.mode = 'percent'; }
      renderMilestones();
      changed();
      return;
    }
    const act = b.dataset.act;
    const rowEl = b.closest('.fwq-li');
    const r = rowEl ? S.rows.find((x) => x.uid === rowEl.dataset.uid) : null;
    if (act === 'row-menu' && r) rowMenu(b, r);
    if (act === 'add-line') addRow('item');
    if (act === 'add-heading') addRow('heading');
    if (act === 'import') importBoard();
    if (act === 'cust-clear') { S.customer = null; S.doc.customer_id = 0; S.doc.contact_id = null; if (T === 'credit_note') { S.doc.invoice_id = null; S.invoice = null; S.invoices = null; } renderCustomer(); changed(); setTimeout(() => { const s = $('#custSearch'); if (s) s.focus(); }, 0); }
    if (act === 'rate') lookupRate();
    if (act === 'ms-add') {
      const t = totals().total;
      const used = S.ms.reduce((a, m) => a + msCents(m, t), 0);
      S.ms.push({ uid: uid(), label: 'Payment ' + (S.ms.length + 1), mode: 'percent', percentage: t > 0 ? Math.max(0, Math.round((t - used) / t * 10000) / 100) : 0, amount: 0, due_date: null });
      renderMilestones();
      changed();
    }
    if (act === 'ms-del') { S.ms = S.ms.filter((x) => x.uid !== b.closest('.fwq-ms').dataset.uid); if (!S.ms.length) S.ms = null; renderMilestones(); changed(); }
    if (act === 'ms-off') { S.ms = null; renderMilestones(); changed(); }
    if (act === 'ms-fill') {
      const t = totals().total;
      const last = S.ms[S.ms.length - 1];
      const others = S.ms.slice(0, -1).reduce((a, m) => a + msCents(m, t), 0);
      if (last.mode === 'amount') last.amount = ((t - others) / 100).toFixed(2); else last.percentage = t ? Math.round((t - others) / t * 10000) / 100 : 0;
      renderMilestones();
      changed();
    }
    if (act === 'terms-default') { S.doc.terms = P.defaults.terms; const ta = $('[data-f=terms]'); if (ta) ta.value = S.doc.terms; changed(); }
    if (act === 'terms-save') saveDefault('terms', S.doc.terms || '');
  });
  el.sections.addEventListener('pointerdown', (e) => {
    const g = e.target.closest('.fwq-li__grip');
    if (g && g.tagName === 'BUTTON' && e.button === 0) startDrag(e, g);
  });
  el.sections.addEventListener('keydown', (e) => {
    const rowEl = e.target.closest && e.target.closest('.fwq-li');
    if (suggestKeys(e)) return;
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !amountsLocked()) { e.preventDefault(); addRow('item', rowEl ? rowEl.dataset.uid : null); return; }
    if (!rowEl) return;
    const r = S.rows.find((x) => x.uid === rowEl.dataset.uid);
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown') && r && !amountsLocked()) { e.preventDefault(); moveRow(r, e.key === 'ArrowUp' ? -1 : 1); return; }
    if (e.key === 'Enter' && !e.shiftKey && e.target.tagName === 'INPUT' && r) {
      e.preventDefault();
      const idx = S.rows.indexOf(r);
      const next = S.rows.slice(idx + 1).find((x) => x.kind === 'item');
      if (next) { const f = $(`.fwq-li[data-uid="${next.uid}"] [data-rf=description]`); if (f) f.focus(); }
      else addRow('item', r.uid);
    }
  });
  async function saveDefault(field, value) {
    try {
      await api.post('document.php', { action: 'save_default', field, value });
      P.defaults[field] = value;
      toast('Saved as the default ' + (field === 'terms' ? 'terms' : 'note') + ' for new documents');
    } catch (e) { toastError(e); }
  }

  // ------------------------------------------------------------ suggestions
  let sug = null; // {box, input, items, active, onPick, footer}
  function closeSuggest() { if (sug) { sug.box.remove(); sug = null; } }
  function showSuggest(input, items, opts) {
    closeSuggest();
    if (!items.length && !opts.footer) return;
    const box = document.createElement('div');
    box.className = 'fwm-suggest fwq-suggest';
    box.setAttribute('role', 'listbox');
    const head = opts.title ? `<div class="fwm-pop__title">${esc(opts.title)}</div>` : '';
    box.innerHTML = head + items.map((it, i) => `<div class="fwm-suggest__item" role="option" data-i="${i}">${opts.render(it)}</div>`).join('')
      + (opts.footer ? `<div class="fwm-suggest__item is-footer" role="option" data-i="footer">${opts.footer}</div>` : '');
    document.body.appendChild(box);
    const r = input.getBoundingClientRect();
    box.style.left = Math.max(8, Math.min(r.left, window.innerWidth - box.offsetWidth - 8)) + 'px';
    box.style.width = Math.max(300, Math.min(r.width, 460)) + 'px';
    const below = r.bottom + 6 + box.offsetHeight < window.innerHeight - 8;
    box.style.top = (below ? r.bottom + 6 : Math.max(8, r.top - box.offsetHeight - 6)) + 'px';
    sug = { box, input, items, active: -1, onPick: opts.onPick, onFooter: opts.onFooter };
    box.addEventListener('mousedown', (e) => {
      const it = e.target.closest('.fwm-suggest__item');
      if (!it) return;
      e.preventDefault();
      pickSuggest(it.dataset.i);
    });
  }
  function pickSuggest(i) {
    if (!sug) return;
    const s = sug;
    closeSuggest();
    if (i === 'footer') s.onFooter && s.onFooter(s.input.value.trim());
    else if (s.items[i]) s.onPick(s.items[i]);
  }
  function suggestKeys(e) {
    // the same keydown reaches the input's own listener and the form's; act once
    if (e.qiSug !== undefined) return e.qiSug;
    e.qiSug = suggestKey(e);
    return e.qiSug;
  }
  function suggestKey(e) {
    if (!sug || e.target !== sug.input) return false;
    const els = $$('.fwm-suggest__item', sug.box);
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      sug.active = (sug.active + (e.key === 'ArrowDown' ? 1 : -1) + els.length) % els.length;
      els.forEach((x, i) => x.classList.toggle('is-active', i === sug.active));
      return true;
    }
    if (e.key === 'Enter' && sug.active >= 0) { e.preventDefault(); pickSuggest(els[sug.active].dataset.i); return true; }
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeSuggest(); return true; }
    if (e.key === 'Tab') closeSuggest();
    return false;
  }
  function attachSuggest(input, opts) {
    let seq = 0;
    const run = debounce(async () => {
      const q = input.value.trim();
      const my = ++seq;
      try {
        const items = await opts.fetch(q);
        if (my !== seq || document.activeElement !== input) return;
        showSuggest(input, items, { render: opts.render, onPick: opts.onPick, footer: opts.footer ? opts.footer(q) : null, onFooter: opts.onFooter, title: opts.title ? opts.title(q) : '' });
      } catch (e) { /* offline: no suggestions */ }
    }, 160);
    input.addEventListener('input', run);
    input.addEventListener('focus', run);
    input.addEventListener('keydown', (e) => { if (suggestKeys(e)) return; if (e.key === 'Enter') { e.preventDefault(); if (input.value.trim()) opts.onFooter(input.value.trim()); } });
    input.addEventListener('blur', () => setTimeout(() => { if (sug && sug.input === input) closeSuggest(); }, 120));
  }
  // past lines for a description
  const itemSeq = { n: 0 };
  const suggestItems = debounce(async (t, r) => {
    const q = t.value.trim();
    if (q.length < 2 || document.activeElement !== t || q.includes('\n')) { if (sug && sug.input === t) closeSuggest(); return; }
    const my = ++itemSeq.n;
    try {
      const res = await api.get('document.php', { action: 'items', q });
      if (my !== itemSeq.n || document.activeElement !== t) return;
      const items = (res.items || []).filter((i) => i.description.trim().toLowerCase() !== q.toLowerCase());
      if (!items.length) { closeSuggest(); return; }
      showSuggest(t, items, {
        title: 'Used before',
        render: (i) => `<span class="fwm-suggest__text"><span class="fwm-suggest__name">${esc(i.description.split('\n')[0])}</span><span class="fwm-suggest__email">${esc([i.price != null ? money(i.price, symbol()) + (i.unit ? ' / ' + i.unit : '') : i.unit, i.source].filter(Boolean).join(' · '))}</span></span>`,
        onPick: (i) => {
          r.description = i.description;
          if (i.unit) r.unit = i.unit;
          if (i.price != null) { r.price = i.price; r.priceText = null; }
          if (i.tax_code && TAX[i.tax_code]) r.tax_code = i.tax_code;
          renderLines();
          changed();
          const f = $(`.fwq-li[data-uid="${r.uid}"] [data-rf=qty]`);
          if (f) { f.focus(); f.select(); }
        },
      });
    } catch (e) { /* ignore */ }
  }, 220);
  if (el.scroll) el.scroll.addEventListener('scroll', closeSuggest, { passive: true });

  // ------------------------------------------------------------ preview
  const preview = debounce(async () => {
    const my = ++S.seq;
    el.paper.classList.add('is-busy');
    try {
      const r = await api.post('document.php', { action: 'preview', type: T, id: S.id, doc: payload() });
      if (my !== S.seq) return;
      el.paper.innerHTML = r.html;
      S.lastTotals = r.totals;
      fit();
    } catch (e) {
      if (my === S.seq && e.status !== 429) el.paper.innerHTML = `<div class="fwq-empty" style="padding:40px">${icon('alert')}<p>${esc(e.message)}</p></div>`;
    } finally {
      if (my === S.seq) el.paper.classList.remove('is-busy');
    }
  }, 260);
  function fit() {
    const qd = $('.qd', el.paper);
    if (!qd || !el.stage.clientWidth) return;
    const w = qd.offsetWidth;
    const avail = el.stage.clientWidth - (narrow() ? 20 : 44);
    const auto = Math.min(1, avail / w);
    const z = S.zoom ? Math.max(0.3, Math.min(1.6, S.zoom)) : auto;
    el.paper.style.transform = `scale(${z})`;
    el.paper.style.width = w + 'px';
    el.wrap.style.height = (qd.offsetHeight * z + (narrow() ? 40 : 80)) + 'px';
    const zb = $('#edZoomFit');
    if (zb) zb.textContent = Math.round(z * 100) + '%';
  }
  window.addEventListener('resize', debounce(fit, 120));
  const curZoom = () => { const m = /scale\(([\d.]+)\)/.exec(el.paper.style.transform || ''); return m ? Number(m[1]) : 1; };
  $('#edZoomIn').addEventListener('click', () => { S.zoom = Math.min(1.6, (S.zoom || curZoom()) + 0.1); fit(); });
  $('#edZoomOut').addEventListener('click', () => { S.zoom = Math.max(0.3, (S.zoom || curZoom()) - 0.1); fit(); });
  $('#edZoomFit').addEventListener('click', () => { S.zoom = 0; fit(); });
  // clicking the paper jumps to what it shows
  el.paper.addEventListener('click', (e) => {
    const z = e.target.closest('[data-zone]');
    if (!z) return;
    const zone = z.dataset.zone;
    const target = { customer: 'customer', meta: T === 'recurring' ? 'schedule' : 'details', table: 'lines', totals: 'lines', notes: 'notes', terms: 'notes' }[zone];
    if (target) {
      if (narrow()) togglePreview(false);
      const s = $('#sec-' + target);
      if (s) {
        s.scrollIntoView({ block: 'start', behavior: 'smooth' });
        s.classList.remove('is-flash');
        void s.offsetWidth;
        s.classList.add('is-flash');
        const f = zone === 'notes' ? $('[data-f=customer_notes]') : zone === 'terms' ? $('[data-f=terms]') : null;
        if (f) setTimeout(() => f.focus({ preventScroll: true }), 300);
      }
    } else {
      popover({ x: e.clientX, y: e.clientY }, `<div class="fwm-pop__title">The look of every document</div>
        <a class="fwm-pop__item" data-v="d" href="/qi/designer.php">${icon('iq-designer')}Open the designer<small>logo, colours, layout</small></a>
        ${zone === 'payment' ? `<a class="fwm-pop__item" data-v="s" href="/qi/settings.php#banking">${icon('wallet')}Banking details<small>Settings</small></a>` : ''}`, { width: 260 });
    }
  });
  function togglePreview(on) {
    el.editor.classList.toggle('is-preview', on);
    document.body.classList.toggle('fwq-ed-previewing', on);
    if (on) setTimeout(fit, 30);
  }
  $('#edPreviewFab').addEventListener('click', () => togglePreview(true));
  $('#edClosePreview').addEventListener('click', () => togglePreview(false));

  // ------------------------------------------------------------ save
  function payload() {
    const d = Object.assign({}, S.doc);
    d.rows = S.rows.filter((r) => !(r.kind === 'item' && isEmptyRow(r))).map((r) => (r.kind === 'heading'
      ? { kind: 'heading', title: r.title || '' }
      : { kind: 'item', description: r.description || '', qty: isNaN(num(r.qty)) ? String(r.qtyText) : num(r.qty), unit: r.unit || '', price: isNaN(num(r.price)) ? String(r.priceText) : num(r.price),
          discount: isNaN(num(r.discount)) ? String(r.discText) : num(r.discount), tax_code: r.tax_code, ref: r.ref || undefined, src: r.src || undefined }));
    d.milestones = S.ms ? S.ms.map((m) => ({ label: m.label, mode: m.mode === 'amount' ? 'amount' : 'percent', percentage: num(m.percentage), amount: num(m.amount), due_date: m.due_date || null })) : [];
    if (d.exchange_rate === '' || d.exchange_rate == null) d.exchange_rate = null;
    else d.exchange_rate = num(d.exchange_rate);
    return d;
  }
  async function save({ andView = true, force = false } = {}) {
    if (S.saving || fullLock()) return;
    closeSuggest();
    S.saving = true;
    renderFoot();
    try {
      const r = await api.post('document.php', { action: 'save', type: T, id: S.id, version: S.version, force, client_key: S.clientKey, doc: payload() });
      clearBackup();
      S.dirty = false;
      const created = !S.id;
      S.id = r.id;
      S.number = r.number;
      S.version = r.version;
      S.lock = r.lock;
      if (r.status) S.status = r.status;
      S.clientKey = newKey();
      if (created) history.replaceState(null, '', editUrl(T, r.id));
      if (andView) {
        try { sessionStorage.setItem('qi-flash', (created ? TYPE_NAMES[T] + ' ' + r.number + ' created' : 'Saved') + (r.reposted ? ' — the ledger entry was updated' : '')); } catch (e) { /* private mode */ }
        location.href = r.urls.view;
        return;
      }
      toast(created ? TYPE_NAMES[T] + ' ' + r.number + ' created' : 'Saved' + (r.reposted ? ' — the ledger entry was updated' : ''));
      renderHead();
    } catch (e) {
      if (e.status === 409 && e.data && e.data.conflict) {
        const v = await dialog({ title: 'Someone else changed this ' + NOUN, body: 'It was saved by someone else while you were editing. Load their version (your changes are lost), or save yours over it.', choices: [{ v: 'mine', label: 'Save my version', hint: 'Replaces what they saved' }, { v: 'theirs', label: 'Load their version', hint: 'Discards your changes' }], confirm: 'Continue' });
        S.saving = false;
        if (v === 'mine') return save({ andView, force: true });
        if (v === 'theirs') { clearBackup(); S.dirty = false; location.reload(); return; }
      } else {
        if (e.data && e.data.field) highlight(e.data.field);
        toastError(e);
      }
    } finally {
      S.saving = false;
      renderFoot();
    }
  }
  function highlight(fieldName) {
    let target = null;
    const m = /^rows\.(\d+)\.(\w+)$/.exec(fieldName);
    if (m) {
      const sent = S.rows.filter((r) => !(r.kind === 'item' && isEmptyRow(r)));
      const r = sent[Number(m[1])];
      if (r) target = $(`.fwq-li[data-uid="${r.uid}"] [data-rf="${m[2] === 'tax_code' ? 'tax_code' : m[2]}"]`);
    } else if (fieldName === 'customer' || fieldName === 'contact') target = $('#custSearch') || $('#sec-customer');
    else if (fieldName === 'invoice') target = $('#cnInvSel') || $('#sec-customer');
    else if (fieldName === 'rows') target = $('#sec-lines');
    else if (/^milestones/.test(fieldName)) target = $('#sec-milestones');
    else target = $(`[data-f="${fieldName}"]`);
    if (!target) return;
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    if (target.matches('input, textarea, select')) {
      target.classList.add('is-bad');
      setTimeout(() => target.focus({ preventScroll: true }), 250);
      target.addEventListener('input', () => target.classList.remove('is-bad'), { once: true });
    } else {
      target.classList.remove('is-flash');
      void target.offsetWidth;
      target.classList.add('is-flash');
    }
  }
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); save({ andView: false }); }
  });
  window.addEventListener('beforeunload', (e) => { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

  function shortcuts() {
    const m = tempModal(`<div class="fwm-dialog__head"><h2>Keyboard shortcuts</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fwm-kbd-grid">
        <div class="fwm-kbd-row"><span>Save and keep editing</span><span><kbd class="fwm-kbd">Ctrl</kbd><kbd class="fwm-kbd">S</kbd></span></div>
        <div class="fwm-kbd-row"><span>New line below</span><span><kbd class="fwm-kbd">Ctrl</kbd><kbd class="fwm-kbd">Enter</kbd></span></div>
        <div class="fwm-kbd-row"><span>Next line (in qty, price…)</span><span><kbd class="fwm-kbd">Enter</kbd></span></div>
        <div class="fwm-kbd-row"><span>Move a line</span><span><kbd class="fwm-kbd">Alt</kbd><kbd class="fwm-kbd">↑</kbd><kbd class="fwm-kbd">↓</kbd></span></div>
        <div class="fwm-kbd-row"><span>Discount as a percentage</span><span><kbd class="fwm-kbd">10%</kbd></span></div>
      </div></div>`, 'fwm-dialog--wide');
    openModal(m);
  }

  // ------------------------------------------------------------ unsaved work backup (this browser only)
  const BK = 'qi-draft:' + T + ':' + (S.id || 'new');
  const backup = debounce(() => {
    if (!S.dirty) return;
    try { localStorage.setItem(BK, JSON.stringify({ at: Date.now(), version: S.version, doc: S.doc, rows: S.rows, ms: S.ms, customer: S.customer, invoice: S.invoice })); } catch (e) { /* storage full or blocked */ }
  }, 700);
  function clearBackup() { try { localStorage.removeItem(BK); } catch (e) { /* blocked */ } }
  function offerRestore() {
    let b = null;
    try { b = JSON.parse(localStorage.getItem(BK) || 'null'); } catch (e) { b = null; }
    if (!b || !b.at || Date.now() - b.at > 3 * 86400000 || (S.id && b.version !== S.version) || fullLock()) { if (b) clearBackup(); return; }
    toast('You have unsaved changes from ' + QI.relTime(new Date(b.at).toISOString().slice(0, 19).replace('T', ' ')) + '.', { timeout: 15000, action: { label: 'Restore', run: () => {
      S.doc = b.doc; S.rows = b.rows; S.ms = b.ms; S.customer = b.customer; S.invoice = b.invoice;
      uidSeq = Math.max(uidSeq, ...S.rows.map((r) => Number(String(r.uid).slice(1)) || 0), ...(S.ms || []).map((m) => Number(String(m.uid).slice(1)) || 0));
      renderAll();
      changed();
    } } });
  }

  function changed() {
    if (!S.dirty) { S.dirty = true; renderFoot(); }
    backup();
    preview();
  }

  // ------------------------------------------------------------ go
  QI.initChrome();
  renderHead();
  renderAll();
  renderFoot();
  preview();
  offerRestore();
  if (!S.customer && !fullLock()) setTimeout(() => { const s = $('#custSearch'); if (s && !narrow()) s.focus(); }, 60);
})();
