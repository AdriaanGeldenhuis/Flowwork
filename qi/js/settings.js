// /qi/js/settings.js — Quotes & Invoices settings. Every section saves on its
// own (qi/api/settings.php); numbering shows the numbers it will produce and
// the email wording is previewed with real-looking values as you type.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, dialog } = QI;
  const body = $('#setBody');
  let D = null;
  const dirty = {};

  const field = (label, control, hint = '', cls = '') => `<label class="field ${cls}"><span class="label">${label}</span>${control}${hint ? `<span class="hint">${hint}</span>` : ''}</label>`;
  const inp = (sec, name, value, attrs = '') => `<input class="input" data-s="${sec}" name="${name}" value="${esc(value == null ? '' : value)}" ${attrs} ${D.can_edit ? '' : 'disabled'}>`;
  const card = (id, ic, title, lead, inner, save = true) => `
    <section class="fwq-set__card" id="${id}" data-card="${id}">
      <header>${icon(ic)}<div><h2>${title}</h2>${lead ? `<p>${lead}</p>` : ''}</div></header>
      <div class="fwq-set__body">${inner}</div>
      ${save && D.can_edit ? `<footer><span class="fwq-set__state" data-state="${id}"></span><button class="btn btn--primary" type="button" data-save="${id}">${icon('check')}Save</button></footer>` : ''}
    </section>`;

  function render() {
    const b = D.business, k = D.banking, n = D.numbering, d = D.defaults;
    body.innerHTML = `
      ${D.can_edit ? '' : `<div class="fwq-banner">${icon('lock')}<div>Only an administrator or bookkeeper can change these settings.</div></div>`}
      ${card('business', 'building', 'Business details', 'Printed on every quote and invoice. A VAT number makes your invoices tax invoices.', `
        <div class="row">${field('Business name', inp('business', 'name', b.name, 'maxlength="255"'))}${field('VAT number', inp('business', 'vat_number', b.vat_number, 'maxlength="12" inputmode="numeric" placeholder="4xxxxxxxxx"'), 'Leave empty if you are not VAT registered.')}</div>
        <div class="row">${field('Company registration', inp('business', 'reg_number', b.reg_number, 'maxlength="50"'))}${field('Income tax reference', inp('business', 'tax_reference', b.tax_reference, 'maxlength="12" inputmode="numeric"'))}${field('Tax number', inp('business', 'tax_number', b.tax_number, 'maxlength="50"'))}</div>
        <div class="row">${field('Phone', inp('business', 'phone', b.phone, 'maxlength="50" type="tel"'))}${field('Email', inp('business', 'email', b.email, 'maxlength="190" type="email"'))}${field('Website', inp('business', 'website', b.website, 'maxlength="190"'))}</div>
        <div class="row">${field('Street address', inp('business', 'address_line1', b.address_line1, 'maxlength="190"'))}${field('Suburb', inp('business', 'address_line2', b.address_line2, 'maxlength="190"'))}</div>
        <div class="row">${field('City', inp('business', 'city', b.city, 'maxlength="100"'))}${field('Province', inp('business', 'region', b.region, 'maxlength="100"'))}${field('Postal code', inp('business', 'postal', b.postal, 'maxlength="20"'))}</div>`)}
      ${card('banking', 'wallet', 'Banking', 'Where customers pay you by EFT — shown on invoices and the customer page. Other administrators are told when this changes.', `
        <div class="row">${field('Account holder', inp('banking', 'bank_account_holder', k.bank_account_holder, 'maxlength="190" placeholder="' + esc(b.name) + '"'))}${field('Bank', inp('banking', 'bank_name', k.bank_name, 'maxlength="100" list="banks"'))}</div>
        <div class="row">${field('Account number', inp('banking', 'bank_account_number', k.bank_account_number, 'maxlength="30" inputmode="numeric"'))}${field('Branch code', inp('banking', 'bank_branch_code', k.bank_branch_code, 'maxlength="10" inputmode="numeric"'))}</div>
        <datalist id="banks">${['ABSA', 'Capitec', 'FNB', 'Nedbank', 'Standard Bank', 'Investec', 'TymeBank', 'Discovery Bank', 'African Bank', 'Bidvest Bank'].map((x) => `<option value="${x}">`).join('')}</datalist>`)}
      ${card('numbering', 'hash', 'Numbering', 'How new documents are numbered. Existing documents keep their numbers.', `
        <div class="fwq-set__nums">
          ${[['quote', 'Quotes', 'quote_prefix', 'next_quote'], ['invoice', 'Invoices', 'invoice_prefix', 'next_invoice'], ['credit_note', 'Credit notes', 'credit_prefix', 'next_credit']].map(([t, label, pk, nk]) => `
            <div class="fwq-set__num">
              <b>${label}</b>
              ${field('Prefix', inp('numbering', pk, n[pk], 'maxlength="12"'))}
              ${field('Next number', inp('numbering', nk, D.next[t].number, 'type="number" min="1" max="99999999"'))}
              <div class="fwq-set__sample" data-sample="${t}"></div>
            </div>`).join('')}
        </div>
        <div class="row" style="margin-top:14px">
          ${field('Digits', `<select class="select" data-s="numbering" name="number_digits" ${D.can_edit ? '' : 'disabled'}>${[3, 4, 5, 6].map((x) => `<option value="${x}" ${n.number_digits === x ? 'selected' : ''}>${x} — ${'0'.repeat(x - 1)}1</option>`).join('')}</select>`)}
          ${field('Separator', `<select class="select" data-s="numbering" name="number_sep" ${D.can_edit ? '' : 'disabled'}>${[['-', 'Dash  INV2026-0001'], ['/', 'Slash  INV2026/0001'], ['.', 'Dot  INV2026.0001'], ['', 'None  INV20260001']].map(([v, l]) => `<option value="${esc(v)}" ${n.number_sep === v ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`)}
          <label class="switch" style="align-self:end"><input type="checkbox" data-s="numbering" name="number_year" ${n.number_year ? 'checked' : ''} ${D.can_edit ? '' : 'disabled'}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Include the year</span><span class="hint">Numbering restarts every January.</span></span></label>
        </div>`)}
      ${card('defaults', 'settings', 'Defaults for new documents', 'Filled in when you start a quote or invoice — change them per document whenever you like.', `
        <div class="row">
          ${field('Invoices are due', `<select class="select" data-s="defaults" name="default_payment_terms" ${D.can_edit ? '' : 'disabled'}>${[0, 7, 14, 30, 45, 60, 90].concat([0, 7, 14, 30, 45, 60, 90].includes(d.default_payment_terms) ? [] : [d.default_payment_terms]).map((x) => `<option value="${x}" ${x === d.default_payment_terms ? 'selected' : ''}>${x === 0 ? 'On receipt' : x + ' days after the invoice date'}</option>`).join('')}</select>`)}
          ${field('Quotes are valid for', `<select class="select" data-s="defaults" name="quote_validity_days" ${D.can_edit ? '' : 'disabled'}>${[7, 14, 21, 30, 60, 90].concat([7, 14, 21, 30, 60, 90].includes(d.quote_validity_days) ? [] : [d.quote_validity_days]).map((x) => `<option value="${x}" ${x === d.quote_validity_days ? 'selected' : ''}>${x} days</option>`).join('')}</select>`)}
          ${field('Currency', `<select class="select" data-s="defaults" name="default_currency" ${D.can_edit ? '' : 'disabled'}>${D.currencies.map((c) => `<option value="${esc(c.code)}" ${c.code === d.default_currency ? 'selected' : ''}>${esc(c.code)} — ${esc(c.name)}</option>`).join('')}</select>`)}
        </div>
        <div class="field" style="margin-top:14px"><span class="label">Terms</span><textarea class="textarea" data-s="defaults" name="default_terms" rows="4" maxlength="10000" ${D.can_edit ? '' : 'disabled'}>${esc(d.default_terms)}</textarea></div>
        <div class="field"><span class="label">Note to the customer</span><textarea class="textarea" data-s="defaults" name="default_notes" rows="2" maxlength="5000" placeholder="e.g. Thank you for your business" ${D.can_edit ? '' : 'disabled'}>${esc(d.default_notes)}</textarea></div>`)}
      ${card('emails', 'mail', 'Email wording', 'The message that goes out with each document. You can still change it every time you send.', `
        ${D.mailbox ? '' : `<div class="fwq-banner" data-tone="warning">${icon('alert')}<div>Emails go out from your own mailbox through Flowwork Mail. <a href="/mail/">Connect your mailbox</a> (with its SMTP server) to send documents.</div></div>`}
        <div class="seg" id="tplTabs" role="tablist">${['quote', 'invoice', 'reminder'].map((t, i) => `<button type="button" data-tpl="${t}" class="${i === 0 ? 'is-on' : ''}">${{ quote: 'Quote', invoice: 'Invoice', reminder: 'Payment reminder' }[t]}</button>`).join('')}</div>
        <div id="tplBox"></div>`)}
      ${card('payments', 'iq-pay', 'Card payments', 'Let customers pay invoices by card through Yoco, from the email, the PDF and the invoice page.', `
        <div class="fwq-set__yoco ${D.yoco.configured && D.yoco.webhook ? 'is-on' : ''}">
          <span class="fwq-set__dot"></span>
          <div><b>${D.yoco.configured ? (D.yoco.webhook ? 'Yoco is connected' : 'Yoco keys are saved — add the webhook secret') : 'Not set up'}</b>
          <span>${D.yoco.configured ? 'Mode: ' + esc(D.yoco.mode) + '. ' : ''}Keys are managed under Admin → Integrations.</span></div>
          ${D.can_edit ? `<a class="btn btn--sm" href="/admin/integrations.php">${icon('external')}Integrations</a>` : ''}
        </div>
        <p class="hint" style="margin:14px 0 8px">In the Yoco portal, send webhooks to this address and copy the signing secret it gives you into Integrations:</p>
        <div class="fwq-vw-linkbox"><input class="input" readonly value="${esc(D.yoco.webhook_url)}"><button class="btn" type="button" data-copy="${esc(D.yoco.webhook_url)}">${icon('copy')}Copy</button></div>`, false)}
      <section class="fwq-set__card fwq-set__look"><header>${icon('iq-designer')}<div><h2>The look of your documents</h2><p>Logo, colours, fonts, layout and wording on the paper are in the designer.</p></div></header>
        <div class="fwq-set__body"><a class="btn btn--primary" href="/qi/designer.php">${icon('iq-designer')}Open the designer</a></div></section>`;
    renderSamples();
    tplTab('quote');
  }

  // ------------------------------------------------------------ numbering preview (mirrors SequenceAllocator::code)
  function code(prefix, year, sep, digits, num) {
    const head = prefix + (year ? String(new Date().getFullYear()) : '');
    const padded = String(Math.max(1, num || 1)).padStart(digits, '0');
    return head === '' ? padded : head + sep + padded;
  }
  function renderSamples() {
    const c = $('#numbering');
    if (!c) return;
    const g = (n) => { const el = $(`[name="${n}"]`, c); return el ? (el.type === 'checkbox' ? el.checked : el.value) : ''; };
    const clean = (s) => String(s).replace(/[^A-Za-z0-9/._-]/g, '');
    const year = g('number_year'), sep = g('number_sep'), digits = Number(g('number_digits')) || 4;
    [['quote', 'quote_prefix', 'next_quote'], ['invoice', 'invoice_prefix', 'next_invoice'], ['credit_note', 'credit_prefix', 'next_credit']].forEach(([t, p, n]) => {
      const el = $(`[data-sample="${t}"]`, c);
      if (el) el.innerHTML = `Next: <b>${esc(code(clean(g(p)), year, sep, digits, Number(g(n))))}</b>`;
    });
  }

  // ------------------------------------------------------------ email templates
  let tplCur = 'quote';
  const tplState = {};
  const SAMPLE = { '{customer}': 'Riverside Lofts', '{first_name}': 'Thandi', '{number}': 'INV2026-0042', '{total}': 'R 18,975.00', '{balance}': 'R 9,487.50',
    '{due_date}': '14 November 2026', '{valid_until}': '28 October 2026', '{company}': '', '{sender}': '', '{link}': location.origin + '/qi/p/invoice.php?token=…' };
  function tplTab(t) {
    const box = $('#tplBox');
    if (!box) return;
    if (!tplState[t]) {
      const cur = D.emails[t] || {};
      tplState[t] = { subject: cur.custom ? cur.subject : D.email_defaults[t].subject, body: cur.custom ? cur.body : D.email_defaults[t].body, custom: !!cur.custom };
    }
    tplCur = t;
    $$('#tplTabs button').forEach((b) => b.classList.toggle('is-on', b.dataset.tpl === t));
    const s = tplState[t];
    box.innerHTML = `
      ${field('Subject', `<input class="input" id="tplSubject" maxlength="255" value="${esc(s.subject)}" ${D.can_edit ? '' : 'disabled'}>`)}
      <div class="field"><span class="label">Message <span class="fwq-field-actions">${D.can_edit ? '<button type="button" class="fwq-link" id="tplReset">Use the built-in wording</button>' : ''}</span></span>
        <textarea class="textarea" id="tplBody" rows="10" maxlength="10000" ${D.can_edit ? '' : 'disabled'}>${esc(s.body)}</textarea></div>
      <div class="fwq-set__ph">${D.can_edit ? Object.keys(SAMPLE).map((k) => `<button type="button" class="fwq-mini" data-ph="${esc(k)}">${esc(k)}</button>`).join('') : ''}</div>
      <div class="fwq-set__preview"><div class="label">Preview</div><div class="fwq-set__mail" id="tplPreview"></div></div>`;
    preview();
  }
  function preview() {
    const s = tplState[tplCur];
    const co = D.business.name || 'Your business';
    const vals = Object.assign({}, SAMPLE, { '{company}': co, '{sender}': 'You' }, {
      // placeholders of templates written for the previous editor
      '{QUOTE_NUMBER}': 'Q2026-0042', '{INVOICE_NUMBER}': 'INV2026-0042', '{CREDIT_NOTE_NUMBER}': 'CN2026-0007', '{DOCUMENT_NUMBER}': 'INV2026-0042',
      '{COMPANY_NAME}': co, '{CUSTOMER_NAME}': 'Riverside Lofts', '{CONTACT_NAME}': 'Thandi Mokoena', '{AMOUNT}': tplCur === 'quote' ? SAMPLE['{total}'] : SAMPLE['{balance}'],
      '{TOTAL}': SAMPLE['{total}'], '{BALANCE}': SAMPLE['{balance}'], '{EXPIRY_DATE}': SAMPLE['{valid_until}'], '{VALID_UNTIL}': SAMPLE['{valid_until}'],
      '{DUE_DATE}': SAMPLE['{due_date}'], '{ISSUE_DATE}': '18 October 2026', '{BANK_NAME}': D.banking.bank_name, '{BANK_ACCOUNT}': D.banking.bank_account_number,
      '{BANK_BRANCH}': D.banking.bank_branch_code, '{BANK_HOLDER}': D.banking.bank_account_holder || co, '{LINK}': SAMPLE['{link}'], '{PUBLIC_LINK}': SAMPLE['{link}'],
      '{VIEW_LINK}': SAMPLE['{link}'], '{SENDER_NAME}': 'You',
    });
    const fill = (txt) => Object.keys(vals).reduce((a, k) => a.split(k).join(vals[k]), txt);
    const p = $('#tplPreview');
    if (p) p.innerHTML = `<div class="fwq-set__mailsubj">${esc(fill(s.subject))}</div><div class="fwq-set__mailbody">${esc(fill(s.body)).replace(/\n/g, '<br>')}</div><div class="fwq-set__mailatt">${icon('clip', 'i--sm')}${tplCur === 'quote' ? 'Q2026-0042.pdf' : 'INV2026-0042.pdf'}</div>`;
  }

  // ------------------------------------------------------------ events
  body.addEventListener('input', (e) => {
    const t = e.target;
    if (t.dataset.s) {
      dirty[t.dataset.s] = true;
      markState(t.dataset.s);
      if (t.dataset.s === 'numbering') renderSamples();
    }
    if (t.id === 'tplSubject' || t.id === 'tplBody') {
      tplState[tplCur][t.id === 'tplSubject' ? 'subject' : 'body'] = t.value;
      dirty.emails = true;
      markState('emails');
      preview();
    }
  });
  body.addEventListener('change', (e) => { if (e.target.dataset.s === 'numbering') renderSamples(); });
  body.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-save], [data-tpl], [data-ph], #tplReset, [data-copy]');
    if (!b) return;
    if (b.dataset.save) return save(b.dataset.save, b);
    if (b.dataset.tpl) return tplTab(b.dataset.tpl);
    if (b.dataset.copy) { try { await navigator.clipboard.writeText(b.dataset.copy); toast('Copied'); } catch (x) { /* no clipboard */ } return; }
    if (b.id === 'tplReset') {
      tplState[tplCur] = { subject: D.email_defaults[tplCur].subject, body: D.email_defaults[tplCur].body, custom: false };
      dirty.emails = true;
      markState('emails');
      tplTab(tplCur);
      return;
    }
    if (b.dataset.ph) {
      const ta = $('#tplBody');
      if (!ta) return;
      const at = ta.selectionStart ?? ta.value.length;
      ta.value = ta.value.slice(0, at) + b.dataset.ph + ta.value.slice(ta.selectionEnd ?? at);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = at + b.dataset.ph.length;
      ta.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  function markState(sec) {
    const s = $(`[data-state="${sec}"]`);
    if (s) { s.textContent = dirty[sec] ? 'Unsaved changes' : ''; s.classList.toggle('is-dirty', !!dirty[sec]); }
  }
  async function save(sec, btn) {
    const c = $('#' + sec);
    let values = {};
    if (sec === 'emails') {
      ['quote', 'invoice', 'reminder'].forEach((t) => {
        if (!tplState[t]) return;
        const def = D.email_defaults[t];
        const same = tplState[t].subject === def.subject && tplState[t].body === def.body;
        values[t] = same ? { subject: '', body: '' } : { subject: tplState[t].subject, body: tplState[t].body };
      });
    } else {
      $$('[data-s="' + sec + '"]', c).forEach((el) => { values[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
    }
    if (sec === 'banking' && (values.bank_account_number !== D.banking.bank_account_number || values.bank_branch_code !== D.banking.bank_branch_code) && D.banking.bank_account_number) {
      const ok = await dialog({ title: 'Change the bank account?', body: 'New invoices and the customer page will show the new account. Make sure it is right — customers will pay into it. The other administrators will be notified.', confirm: 'Change account' });
      if (!ok) return;
    }
    btn.disabled = true;
    try {
      D = Object.assign(await api.post('settings.php', { action: 'save', section: sec, values }), {});
      dirty[sec] = false;
      const scroll = $('#setView').scrollTop;
      Object.keys(tplState).forEach((k) => delete tplState[k]);
      render();
      $('#setView').scrollTop = scroll;
      toast({ business: 'Business details saved', banking: 'Banking details saved', numbering: 'Numbering saved', defaults: 'Defaults saved', emails: 'Email wording saved' }[sec] || 'Saved');
    } catch (err) {
      toastError(err);
      if (err.data && err.data.field) { const f = $(`[name="${err.data.field}"]`, c); if (f) { f.classList.add('is-bad'); f.focus(); f.addEventListener('input', () => f.classList.remove('is-bad'), { once: true }); } }
    } finally {
      btn.disabled = false;
    }
  }
  window.addEventListener('beforeunload', (e) => { if (Object.values(dirty).some(Boolean)) { e.preventDefault(); e.returnValue = ''; } });

  // nav highlight
  const view = $('#setView');
  const nav = () => {
    const cards = $$('.fwq-set__card[id]');
    let cur = cards[0] ? cards[0].id : '';
    cards.forEach((c) => { if (c.getBoundingClientRect().top < 200) cur = c.id; });
    $$('#setNav [data-sec]').forEach((a) => a.classList.toggle('is-active', a.dataset.sec === cur));
  };
  view.addEventListener('scroll', nav, { passive: true });
  $$('#setNav [data-sec]').forEach((a) => a.addEventListener('click', (e) => {
    const t = $('#' + a.dataset.sec);
    if (!t) return;
    e.preventDefault();
    t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    history.replaceState(null, '', '#' + a.dataset.sec);
    if (QI.narrow()) QI.toggleDrawer(false);
  }));

  QI.initChrome();
  api.get('settings.php').then((d) => {
    D = d;
    render();
    nav();
    if (location.hash) { const t = $(location.hash); if (t) t.scrollIntoView({ block: 'start' }); }
  }).catch(toastError);
})();
