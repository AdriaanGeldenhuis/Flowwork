// /suppliers_ai/js/request.js — one request for quotes: ask the suppliers
// (an email each through Mail, WhatsApp with the message ready, or "I phoned"),
// see who replied (Mail is watched), write down the quotes, compare them with
// VAT against the budget, choose one, thank the others and make the order.
(function () {
  'use strict';
  const F = window.FSA;
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, busy, money, toCents, avatar, fmtDay, longDay, due, ago, popover } = F;
  const BOOT = F.boot;
  const app = $('#reqApp');
  const id = +app.dataset.id;
  let D = BOOT.data;
  let compose = null;

  const CH = { email: 'by email', whatsapp: 'on WhatsApp', phone: 'by phone', other: 'another way' };
  const VAT = { incl: 'VAT included', excl: 'plus VAT', none: 'no VAT' };
  const can = () => D.can || {};
  const req = () => D.request;
  const sups = () => D.suppliers || [];

  async function reload(fresh) {
    try {
      D = fresh || await api.get('requests.php', { op: 'get', id });
      render();
    } catch (e) { toastError(e); }
  }
  async function getCompose() {
    if (!compose) compose = await api.get('requests.php', { op: 'compose', id });
    return compose;
  }
  const fill = (s, x) => String(s || '').replace(/\{first_name\}/g, x.first_name || 'there').replace(/\{supplier\}/g, x.name);
  const dialFor = (x) => x.dial ? x.dial : '';

  // ------------------------------------------------------------------ render
  function render() {
    const r = req();
    const t = r.trade;
    const meta = [];
    if (r.location) meta.push(`<span class="fsa-tag">${icon('pin')}${esc(r.location)}</span>`);
    if (r.quantity) meta.push(`<span class="fsa-tag">${icon('hash')}${esc(r.quantity)}</span>`);
    if (r.needed_by) meta.push(`<span class="fsa-tag">${icon('calendar')}Needed ${esc(longDay(r.needed_by))}</span>`);
    if (r.quote_by) meta.push(`<span class="fsa-tag${r.status === 'open' && F.isPast(r.quote_by) ? ' fsa-tag--danger' : ''}">${icon('clock')}Quotes ${F.isPast(r.quote_by) ? 'were due' : 'due'} ${esc(due(r.quote_by))}</span>`);
    if (r.budget != null) meta.push(`<span class="fsa-tag fsa-tag--lock" title="Only your team sees the budget">${icon('lock')}Budget ${money(r.budget, { round: true })}</span>`);
    (r.requirements || []).forEach((q) => meta.push(`<span class="fsa-tag">${icon('shield')}${esc(q)}</span>`));
    const toAsk = sups().filter((x) => x.status === 'to_ask');
    const manage = can().manage;
    const live = !r.deleted && !['closed', 'cancelled'].includes(r.status);
    let primary = '';
    if (manage && live && r.status !== 'awarded' && toAsk.length) primary = `<button class="btn btn--primary" type="button" data-act="ask">${icon('isa-send-all')}Ask ${toAsk.length === sups().length ? 'the' : toAsk.length} supplier${toAsk.length === 1 ? '' : 's'}</button>`;
    else if (r.status === 'awarded' && can().order && !r.po) primary = `<button class="btn btn--primary" type="button" data-act="po">${icon('is-order')}Make the purchase order</button>`;
    else if (r.po) primary = `<a class="btn btn--primary" href="${esc(r.po.url)}">${icon('is-order')}Purchase order ${esc(r.po.number)}</a>`;
    app.innerHTML = `
      <header class="fsa-reqhead">
        <div class="fsa-reqhead__row">
          <div class="fsa-reqhead__text">
            <p class="fsa-eyebrow">${t ? icon(t.icon, 'i--xs') + esc(t.label) : 'Request for quotes'} <i>◆</i> <span class="fsa-pill" data-s="${esc(r.status)}">${esc(r.status_label)}</span> <i>◆</i> by ${esc(r.who || '—')} · ${esc(ago(r.created_at))}</p>
            <h1>${esc(r.title)}</h1>
          </div>
          <div class="fsa-reqhead__actions">
            ${primary}
            ${manage && live && r.status !== 'awarded' ? `<button class="btn" type="button" data-act="add">${icon('plus')}Add suppliers</button>` : ''}
            ${r.status === 'open' ? `<button class="icon-btn" type="button" data-act="refresh" aria-label="Check for replies" data-tip="Check for replies">${icon('refresh')}</button>` : ''}
            <button class="icon-btn" type="button" data-act="more" aria-label="More" aria-haspopup="menu" aria-expanded="false">${icon('more-h')}</button>
          </div>
        </div>
        <div class="fsa-meta">${meta.join('')}</div>
        ${r.details ? `<div class="fsa-details">${esc(r.details)}</div>` : ''}
      </header>
      ${r.deleted ? `<div class="fwm-banner fwm-banner--danger">${icon('trash')}<span class="grow">This request was deleted.</span>${manage ? `<button class="btn btn--sm" type="button" data-act="restore">Bring it back</button>` : ''}</div>` : nextStep()}
      <div class="fsa-grid">
        <div>
          ${compareHtml()}
          <section class="fsa-panel fsa-panel--flush" id="supsPanel">
            <div class="fsa-panel__head"><span class="fsa-panel__icon">${icon('i-users')}</span>
              <div class="fsa-panel__title"><h3>Suppliers</h3><p>${sups().length ? `${D.summary.asked} of ${sups().length} asked · ${D.summary.replied} replied · ${D.summary.quoted} quoted` : 'Nobody on the request yet.'}</p></div></div>
            <ul class="fsa-rs">${sups().map(rowHtml).join('') || `<li><p class="fsa-panel__empty">Add suppliers from your CRM, or find new ones.</p></li>`}</ul>
          </section>
        </div>
        <div class="fsa-side-col">${summaryHtml()}${poHtml()}${timelineHtml()}</div>
      </div>`;
    F.tickAgo();
  }

  function nextStep() {
    const r = req();
    const s = D.summary;
    const toAsk = sups().filter((x) => x.status === 'to_ask').length;
    const waiting = sups().filter((x) => x.status === 'asked').length;
    let ic = 'i-info', b = '', small = '', ok = false;
    if (r.status === 'awarded') {
      const w = sups().find((x) => x.status === 'awarded');
      ok = true; ic = 'isa-medal';
      b = `${w ? w.name : 'The supplier'} it is`;
      small = r.po ? `Purchase order ${r.po.number} is in Procurement.` : (can().order ? 'Make the purchase order when you are ready.' : 'An admin or bookkeeper can make the purchase order.');
    } else if (r.status === 'closed' || r.status === 'cancelled') {
      b = r.status === 'closed' ? 'Closed without choosing' : 'Cancelled'; small = 'Open it again to carry on.';
    } else if (!sups().length) {
      b = 'Add the suppliers to ask'; small = 'From your CRM, or find new ones.';
    } else if (toAsk) {
      ic = 'isa-send-all'; b = `Ask ${toAsk === 1 ? 'the supplier' : toAsk + ' suppliers'}`; small = 'Each gets their own email from your mailbox — or send it by WhatsApp.';
    } else if (s.quoted >= 2 || (s.quoted >= 1 && !waiting)) {
      ic = 'isa-scale'; b = `Compare the ${s.quoted === 1 ? 'quote' : s.quoted + ' quotes'} and choose`; small = s.best != null ? `The best is ${money(s.best)} with VAT.` : '';
    } else if (waiting) {
      ic = 'i-clock'; b = `Waiting for ${waiting === 1 ? 'one reply' : waiting + ' replies'}`;
      small = r.quote_by ? (F.isPast(r.quote_by) ? 'Quotes were due ' + due(r.quote_by) + ' — send a reminder.' : 'Quotes are due ' + due(r.quote_by) + '. Replies in Mail are noticed for you.') : 'Replies in Mail are noticed for you.';
    }
    return b ? `<div class="fsa-next${ok ? ' fsa-next--ok' : ''}">${icon(ic)}<div><b>${esc(b)}</b><small>${esc(small)}</small></div></div>` : '';
  }

  function compareHtml() {
    const q = sups().filter((x) => x.quote_incl != null);
    if (!q.length) return '';
    const r = req();
    const max = Math.max(...q.map((x) => x.quote_incl), r.budget || 0);
    const bpos = r.budget ? Math.min(100, (r.budget / max) * 100) : null;
    const rows = q.slice().sort((a, b) => a.quote_incl - b.quote_incl).map((x, i) => {
      const p = Math.max(4, (x.quote_incl / max) * 100);
      const cls = [x.best ? 'is-best' : '', x.over_budget ? 'is-over' : '', x.status === 'awarded' ? 'is-won' : ''].join(' ');
      return `<div class="fsa-bar ${cls}"><span class="fsa-bar__name">${x.status === 'awarded' ? icon('isa-medal') : ''}${esc(x.name)}</span>
        <span class="fsa-bar__track"><span class="fsa-bar__fill" style="--p:${p.toFixed(1)}%"></span>${x.lead_days != null ? `<span class="fsa-bar__lead">${x.lead_days} days</span>` : ''}${bpos != null ? `<span class="fsa-budget-line" style="--b:${bpos.toFixed(1)}%">${i === 0 ? '<span>Budget</span>' : ''}</span>` : ''}</span>
        <span class="fsa-bar__val">${money(x.quote_incl)}<small>${x.best ? 'best' : (x.over_budget ? 'over budget' : 'with VAT')}</small></span></div>`;
    }).join('');
    return `<section class="fsa-panel" style="margin-bottom:14px"><div class="fsa-panel__head"><span class="fsa-panel__icon">${icon('isa-scale')}</span>
        <div class="fsa-panel__title"><h3>Compare</h3><p>Every quote with VAT, so like is compared with like${r.budget ? ', against your budget' : ''}.</p></div></div>
      <div class="fsa-compare">${rows}${D.summary.quoted > 1 ? `<div class="fsa-compare__legend"><span>Average ${money(D.summary.average)}</span><span>Spread ${money(D.summary.highest - D.summary.best)}</span></div>` : ''}</div></section>`;
  }

  function rowHtml(x) {
    const r = req();
    const manage = can().manage && !r.deleted;
    const live = !['closed', 'cancelled'].includes(r.status);
    const lines = [];
    if (x.contact) lines.push(`<span>${icon('user')}${esc(x.contact)}</span>`);
    if (x.emails.length) lines.push(`<span>${icon('mail')}${esc(x.emails[0])}</span>`);
    else lines.push(`<span class="is-warn">${icon('mail')}No email in the CRM</span>`);
    if (x.phone) lines.push(`<span>${icon('i-phone')}<a href="tel:${esc(x.dial ? '+' + x.dial : x.phone)}">${esc(x.phone)}</a></span>`);
    const when = [];
    if (x.sent_at) when.push(`<span>${icon('isa-send-all')}Asked ${esc(CH[x.channel] || '')} <span data-ago="${esc(x.sent_at)}"></span>${x.sent_by ? ' by ' + esc(x.sent_by) : ''}${x.mail ? ` · <a href="${esc(x.mail)}">open in Mail</a>` : ''}</span>`);
    if (x.reminded_at) when.push(`<span>${icon('bell')}Reminded <span data-ago="${esc(x.reminded_at)}"></span></span>`);
    if (x.replied_at) when.push(`<span>${icon('i-reply')}Replied <span data-ago="${esc(x.replied_at)}"></span>${x.reply_mail ? ` · <a href="${esc(x.reply_mail)}">read it</a>` : ''}</span>`);
    if (x.declined_at) when.push(`<span class="is-warn">${icon('x')}Declined</span>`);
    if (x.compliance && ['expired', 'missing', 'expiring'].includes(x.compliance.status)) when.push(`<span class="${x.compliance.blocking || x.compliance.status === 'expired' ? 'is-danger' : 'is-warn'}" title="${esc((x.compliance.detail || []).join(' · '))}">${icon('shield')}${esc(x.compliance.blocking ? 'Blocked — ' + x.compliance.label.toLowerCase() : x.compliance.label)}</span>`);
    const quote = x.quote != null ? `<div class="fsa-rs__quote"><b>${money(x.quote_incl)}</b>
        <small>${x.quote_vat === 'excl' ? money(x.quote) + ' plus VAT' : esc(VAT[x.quote_vat] || '')}${x.lead_days != null ? ' · ' + x.lead_days + ' days' : ''}</small>
        ${x.best ? '<small class="is-best">Best quote</small>' : ''}${x.over_budget ? '<small class="is-over">Over budget</small>' : ''}${x.expired_quote ? '<small class="is-over">Quote expired ' + esc(fmtDay(x.valid_until)) + '</small>' : (x.valid_until ? '<small>Valid until ' + esc(fmtDay(x.valid_until)) + '</small>' : '')}
        ${x.quote_notes ? `<small title="${esc(x.quote_notes)}">“${esc(x.quote_notes.length > 40 ? x.quote_notes.slice(0, 40) + '…' : x.quote_notes)}”</small>` : ''}</div>` : '<div></div>';
    const acts = [];
    if (manage && live) {
      const blocked = x.compliance && x.compliance.blocking;
      if (x.status === 'to_ask' && r.status !== 'awarded' && !blocked) {
        if (x.emails.length) acts.push(`<button class="fsa-mini fsa-mini--primary" type="button" data-act="ask-one">${icon('mail')}Email</button>`);
        if (x.dial) acts.push(`<button class="fsa-mini fsa-mini--wa" type="button" data-act="wa">${icon('i-whatsapp')}WhatsApp</button>`);
        acts.push(`<button class="fsa-mini" type="button" data-act="phoned">${icon('i-phone')}I phoned</button>`);
      }
      if (['asked', 'replied', 'to_ask'].includes(x.status) && r.status !== 'awarded') acts.push(`<button class="fsa-mini${x.status === 'replied' ? ' fsa-mini--primary' : ''}" type="button" data-act="quote">${icon('isa-quote')}Add the quote</button>`);
      if (['quoted', 'not_chosen'].includes(x.status) && r.status !== 'awarded') acts.push(`<button class="fsa-mini" type="button" data-act="quote">${icon('pen')}Edit quote</button>`);
      if (['quoted', 'replied', 'asked'].includes(x.status) && r.status !== 'awarded' && !blocked) acts.push(`<button class="fsa-mini${x.status === 'quoted' ? ' fsa-mini--primary' : ''}" type="button" data-act="award">${icon('isa-medal')}Choose</button>`);
      if (x.status === 'asked' && x.emails.length && r.status !== 'awarded') acts.push(`<button class="fsa-mini" type="button" data-act="remind">${icon('bell')}Remind</button>`);
      if (x.status === 'asked' && x.dial && x.channel === 'whatsapp') acts.push(`<button class="fsa-mini fsa-mini--wa" type="button" data-act="wa">${icon('i-whatsapp')}Again</button>`);
      if (['asked', 'replied'].includes(x.status)) acts.push(`<button class="fsa-mini" type="button" data-act="declined">${icon('x')}Declined</button>`);
      if (x.status === 'declined') acts.push(`<button class="fsa-mini" type="button" data-act="undecline">${icon('undo')}Not declined</button>`);
      if (x.status === 'asked' && x.channel !== 'email') acts.push(`<button class="fsa-mini" type="button" data-act="unask">${icon('undo')}Not asked</button>`);
      if (x.status === 'awarded' && !r.po) acts.push(`<button class="fsa-mini" type="button" data-act="unaward">${icon('undo')}Undo the choice</button>`);
      acts.push('<span class="fsa-grow"></span>');
      if (x.status !== 'awarded') acts.push(`<button class="icon-btn icon-btn--sm" type="button" data-act="remove" aria-label="Take ${esc(x.name)} off the request" data-tip="Take off">${icon('trash')}</button>`);
    } else if (manage && x.status === 'awarded' && !r.po && r.status === 'awarded') {
      acts.push(`<button class="fsa-mini" type="button" data-act="unaward">${icon('undo')}Undo the choice</button>`);
    }
    const name = x.in_crm ? `<a href="/crm/account_view.php?id=${x.account_id}">${esc(x.name)}</a>` : esc(x.name);
    return `<li data-rid="${x.id}" class="${x.status === 'awarded' ? 'is-won' : ''}${['declined', 'not_chosen'].includes(x.status) ? ' is-out' : ''}" id="rs-${x.id}">
      ${avatar(x.name)}
      <div class="fsa-rs__main">
        <div class="fsa-rs__name"><b>${name}</b><span class="fsa-pill" data-s="${esc(x.status)}">${esc(x.status_label)}</span>${x.prospect ? '<span class="fsa-flag fsa-flag--info">Prospect</span>' : ''}</div>
        <div class="fsa-rs__line">${lines.join('')}</div>
        ${when.length ? `<div class="fsa-rs__line">${when.join('')}</div>` : ''}
      </div>
      ${quote}
      ${acts.length ? `<div class="fsa-rs__acts">${acts.join('')}</div>` : ''}
    </li>`;
  }

  function summaryHtml() {
    const s = D.summary;
    const r = req();
    const n = Math.max(1, s.suppliers);
    const p = s.suppliers ? s.quoted / n : 0;
    const c = 2 * Math.PI * 18;
    const vs = r.budget != null && s.best != null ? (s.best <= r.budget ? `<div class="is-good"><dt>Best vs budget</dt><dd>${money(r.budget - s.best)} under</dd></div>` : `<div class="is-over"><dt>Best vs budget</dt><dd>${money(s.best - r.budget)} over</dd></div>`) : '';
    return `<section class="fsa-panel fsa-summary">
      <div class="fsa-summary__top"><span class="fsa-ring fsa-ring--lg ${p >= 1 ? 'is-high' : 'is-mid'}" role="img" aria-label="${s.quoted} of ${s.suppliers} quoted">
          <svg viewBox="0 0 44 44" aria-hidden="true"><circle class="fsa-ring__bg" cx="22" cy="22" r="18"/><circle class="fsa-ring__fg" cx="22" cy="22" r="18" stroke-dasharray="${c.toFixed(2)}" stroke-dashoffset="${(c * (1 - p)).toFixed(2)}"/></svg><b>${s.quoted}/${s.suppliers}</b></span>
        <div><b>${s.quoted ? F.plural(s.quoted, 'quote', 'quotes') + ' in' : (s.asked ? 'Waiting for quotes' : 'Not asked yet')}</b><small>${s.asked} asked · ${s.replied} replied</small></div></div>
      <dl class="fsa-sums">
        ${s.best != null ? `<div><dt>Best</dt><dd>${money(s.best)}</dd></div>` : ''}
        ${s.quoted > 1 ? `<div><dt>Highest</dt><dd>${money(s.highest)}</dd></div><div><dt>Average</dt><dd>${money(s.average)}</dd></div>` : ''}
        ${r.budget != null ? `<div><dt>Budget (only your team sees it)</dt><dd>${money(r.budget)}</dd></div>` : ''}
        ${vs}
      </dl></section>`;
  }

  function poHtml() {
    const r = req();
    if (r.status !== 'awarded') return '';
    if (r.po) return `<section class="fsa-panel fsa-po"><small>Purchase order</small><b>${esc(r.po.number)} · ${money(r.po.total)}</b><small>A ${esc(r.po.status)} order in Procurement.</small><a class="btn" href="${esc(r.po.url)}">${icon('is-order')}Open the order</a></section>`;
    const w = sups().find((x) => x.status === 'awarded');
    if (!can().order) return `<section class="fsa-panel fsa-po"><b>Next: the order</b><small>An admin or bookkeeper makes the purchase order for ${esc(w ? w.name : 'the supplier')}.</small></section>`;
    return `<section class="fsa-panel fsa-po"><b>Make the order</b><small>A draft purchase order for ${esc(w ? w.name : 'the supplier')}${w && w.quote_incl != null ? ' of ' + money(w.quote_incl) : ''}, numbered from your sequence. ${w && !w.vat ? 'They have no VAT number in the CRM, so no VAT is added.' : ''}</small>
      <button class="btn btn--primary" type="button" data-act="po"${w && w.quote == null ? ' disabled title="Write down their quote first"' : ''}>${icon('is-order')}Make the purchase order</button></section>`;
  }

  const EV = {
    created: ['plus', (e) => `made the request${e.data.suppliers ? ' for ' + F.plural(e.data.suppliers, 'supplier', 'suppliers') : ''}`],
    asked: ['isa-send-all', (e) => `asked <b>${esc(e.supplier)}</b> ${esc(CH[e.data.channel] || '')}${e.data.from_outbox ? ' (sent from the Outbox)' : ''}`],
    replied: ['i-reply', (e) => `<b>${esc(e.supplier)}</b> replied`],
    quoted: ['isa-quote', (e) => `noted <b>${esc(e.supplier)}</b>’s quote of ${money(e.data.amount)} ${esc(VAT[e.data.vat] || '')}`],
    quote_cleared: ['x', (e) => `cleared <b>${esc(e.supplier)}</b>’s quote`],
    declined: ['x', (e) => `<b>${esc(e.supplier)}</b> declined`],
    awarded: ['isa-medal', (e) => `chose <b>${esc(e.supplier)}</b>`],
    unawarded: ['undo', () => 'undid the choice'],
    reminded: ['bell', (e) => `reminded <b>${esc(e.supplier)}</b>`],
    thanked: ['isa-thanks', (e) => `thanked <b>${esc(e.supplier)}</b>`],
    po: ['is-order', (e) => `made purchase order <b>${esc(e.data.number)}</b> (${money(e.data.total)})`],
    edited: ['pen', () => 'changed the details'],
    suppliers_added: ['plus', (e) => `added ${F.plural(e.data.count || 0, 'supplier', 'suppliers')}`],
    supplier_removed: ['trash', (e) => `took <b>${esc(e.data.name)}</b> off`],
    closed: ['check', () => 'closed it'], cancelled: ['x', () => 'cancelled it'], reopened: ['refresh', () => 'opened it again'],
    deleted: ['trash', () => 'deleted it'], restored: ['undo', () => 'brought it back'],
  };
  function timelineHtml() {
    const ev = D.events || [];
    if (!ev.length) return '';
    return `<section class="fsa-panel"><div class="fsa-panel__head"><span class="fsa-panel__icon">${icon('i-history')}</span><div class="fsa-panel__title"><h3>What happened</h3></div></div>
      <ul class="fsa-timeline">${ev.map((e) => { const d = EV[e.action] || ['i-info', () => esc(e.action)]; return `<li><span class="fsa-timeline__dot">${icon(d[0])}</span><span>${e.who ? '<b>' + esc(e.who) + '</b> ' : ''}${d[1](e)}<small>${esc(ago(e.at))}</small></span></li>`; }).join('')}</ul></section>`;
  }

  // ------------------------------------------------------------------ actions
  app.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const li = b.closest('[data-rid]');
    const x = li ? sups().find((s) => s.id === +li.dataset.rid) : null;
    const act = b.dataset.act;
    try {
      if (act === 'ask') return askDialog(sups().filter((s) => s.status === 'to_ask').map((s) => s.id));
      if (act === 'ask-one') return askDialog([x.id]);
      if (act === 'add') return addDialog();
      if (act === 'more') return moreMenu(b);
      if (act === 'refresh') { busy(b, true); const res = await api.post('requests.php', { op: 'refresh', id }); toast(res.found ? F.plural(res.found, 'new reply', 'new replies') : 'No new replies yet'); return reload(res); }
      if (act === 'restore') return reload(await api.post('requests.php', { op: 'state', id, to: 'restore' }));
      if (act === 'po') return makePo(b);
      if (!x) return;
      if (act === 'wa') return whatsapp(x);
      if (act === 'phoned') return reload(await api.post('requests.php', { op: 'mark', recipient_id: x.id, how: 'phone' }));
      if (act === 'declined') return reload(await api.post('requests.php', { op: 'mark', recipient_id: x.id, how: 'declined' }));
      if (act === 'undecline') return reload(await api.post('requests.php', { op: 'mark', recipient_id: x.id, how: 'undeclined' }));
      if (act === 'unask') return reload(await api.post('requests.php', { op: 'mark', recipient_id: x.id, how: 'unasked' }));
      if (act === 'quote') return quoteDialog(x);
      if (act === 'award') return award(x);
      if (act === 'unaward') {
        if (!(await dialog({ title: 'Undo the choice?', body: `${esc(x.name)} will no longer be the chosen supplier, and the request opens again.`, confirm: 'Undo' }))) return;
        return reload(await api.post('requests.php', { op: 'unaward', id }));
      }
      if (act === 'remind') return remind(x);
      if (act === 'remove') {
        if (!(await dialog({ title: `Take ${x.name} off?`, body: x.quote != null ? 'Their quote is removed with them.' : 'You can add them again later.', confirm: 'Take off', danger: true }))) return;
        return reload(await api.post('requests.php', { op: 'remove', recipient_id: x.id }));
      }
    } catch (err) { toastError(err); } finally { if (b.isConnected) busy(b, false); }
  });

  function moreMenu(btn) {
    const r = req();
    const manage = can().manage;
    const live = !['closed', 'cancelled'].includes(r.status);
    const el = popover(btn, `
      ${manage && !r.deleted ? `<button class="fwm-pop__item" type="button" data-m="edit">${icon('pen')}Edit the details</button>` : ''}
      <button class="fwm-pop__item" type="button" data-m="print">${icon('print')}Print the comparison</button>
      ${r.query_id ? `<a class="fwm-pop__item" href="/suppliers_ai/?search=${r.query_id}">${icon('i-search')}Open the search it came from</a>` : ''}
      <a class="fwm-pop__item" href="/suppliers_ai/?go=1&q=${encodeURIComponent(r.title + (r.location ? ' in ' + r.location : ''))}">${icon('isa-seal')}Find more suppliers</a>
      ${manage && !r.deleted ? `<div class="fwm-pop__sep"></div>
        ${live && r.status !== 'awarded' ? `<button class="fwm-pop__item" type="button" data-m="close">${icon('check')}Close without choosing</button><button class="fwm-pop__item" type="button" data-m="cancel">${icon('x')}Cancel the request</button>` : ''}
        ${!live ? `<button class="fwm-pop__item" type="button" data-m="reopen">${icon('refresh')}Open it again</button>` : ''}
        ${!r.po ? `<button class="fwm-pop__item" type="button" data-m="delete">${icon('trash')}Delete</button>` : ''}` : ''}`, { align: 'end' });
    el.addEventListener('click', async (ev) => {
      const m = ev.target.closest('[data-m]');
      if (!m) return;
      F.closePop();
      try {
        if (m.dataset.m === 'edit') return editDialog();
        if (m.dataset.m === 'print') return window.print();
        if (m.dataset.m === 'delete') {
          if (!(await dialog({ title: 'Delete this request?', body: 'You can bring it back from the list straight after.', confirm: 'Delete', danger: true }))) return;
          await api.post('requests.php', { op: 'state', id, to: 'delete' });
          location.href = '/suppliers_ai/requests.php?deleted=' + id;
          return;
        }
        if (m.dataset.m === 'cancel' && !(await dialog({ title: 'Cancel the request?', body: 'Suppliers are not told — let them know if they already quoted.', confirm: 'Cancel it', cancel: 'Keep it', danger: true }))) return;
        reload(await api.post('requests.php', { op: 'state', id, to: m.dataset.m }));
      } catch (err) { toastError(err); }
    });
  }

  // Ask: one email each, or WhatsApp / the email app for those without an address or mailbox
  async function askDialog(ids) {
    let c;
    try { c = await getCompose(); } catch (e) { return toastError(e); }
    const list = sups().filter((x) => ids.includes(x.id));
    const boxes = c.mailboxes || [];
    const m = modal(`<div class="fwm-dialog__head"><h2>Ask for quotes</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body fsa-form">
        ${boxes.length ? `<div class="row"><div class="field"><label class="label" for="akFrom">Send from</label><select class="select" id="akFrom">${boxes.map((b) => `<option value="${b.id}">${esc(b.name)} — ${esc(b.email)}</option>`).join('')}</select></div></div>`
          : `<div class="fsa-note fsa-note--warn">${icon('alert', 'i--sm')}<span>Connect your mailbox in <a href="/mail/settings.php">Mail</a> to send from Flowwork. Until then, send it by WhatsApp or from your own email app below.</span></div>`}
        <div class="field"><label class="label" for="akSubject">Subject</label><input class="input" id="akSubject" maxlength="300" value="${esc(c.subject)}"></div>
        <div class="field"><label class="label" for="akBody">Message <span class="fsa-muted">— {first_name} becomes each person’s name</span></label><textarea class="textarea" id="akBody" rows="11">${esc(c.body)}</textarea></div>
        <div class="fsa-keyrow">${c.ai ? `<button class="fsa-mini" type="button" id="akAi">${icon('i-sparkle')}Write it with AI</button>` : ''}<button class="fsa-mini" type="button" id="akPrev">${icon('eye')}Preview</button><span class="fsa-hint" style="margin:0">${icon('lock', 'i--sm')}The budget is never included.</span></div>
        <div class="fsa-paper" id="akPaper" hidden></div>
        <div class="fsa-form__title">To <small>each gets their own email — they never see each other</small></div>
        <div class="fsa-picklist" id="akList">${list.map((x) => `<div class="fsa-pickrow${x.emails.length ? '' : ' is-off'}" data-rid="${x.id}">
            <input type="checkbox" ${x.emails.length && boxes.length ? 'checked' : 'disabled'} aria-label="Email ${esc(x.name)}">${avatar(x.name)}
            <span class="fsa-pickrow__text"><b>${esc(x.name)}</b><small>${x.emails.length ? esc(x.emails[0]) : 'No email address in the CRM'}</small></span>
            <span class="fsa-pickrow__side"><span class="fsa-pickrow__state"></span>
              ${x.dial ? `<button class="fsa-mini fsa-mini--wa" type="button" data-wa="${x.id}">${icon('i-whatsapp')}WhatsApp</button>` : ''}
              ${x.emails.length && !boxes.length ? `<button class="fsa-mini" type="button" data-mailto="${x.id}">${icon('mail')}Email app</button>` : ''}</span></div>`).join('')}</div>
      </div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Close</button>${boxes.length ? `<button class="btn btn--primary" type="button" id="akGo">${icon('isa-send-all')}<span>Send</span></button>` : ''}</div>`, { width: 680 });
    const sel = () => $$('#akList input[type=checkbox]:checked', m).map((i) => +i.closest('[data-rid]').dataset.rid);
    const count = () => { const go = $('#akGo', m); if (go) { const n = sel().length; $('span', go).textContent = n ? `Send ${n} email${n === 1 ? '' : 's'}` : 'Send'; go.disabled = !n; } };
    $$('#akList input', m).forEach((i) => i.addEventListener('change', count));
    count();
    const preview = () => {
      const first = list.find((x) => sel().includes(x.id)) || list[0];
      const p = $('#akPaper', m);
      p.hidden = false;
      p.innerHTML = `<div class="fsa-paper__subject">${esc(fill($('#akSubject', m).value, first))}</div>${esc(fill($('#akBody', m).value, first))}`;
    };
    $('#akPrev', m).addEventListener('click', preview);
    if ($('#akAi', m)) $('#akAi', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      busy(b, true);
      try { const d = await api.post('requests.php', { op: 'draft', id }); $('#akSubject', m).value = d.subject; $('#akBody', m).value = d.body; preview(); toast('Written — read it through before sending'); }
      catch (err) { toastError(err); } finally { busy(b, false); }
    });
    m.addEventListener('click', async (ev) => {
      const wa = ev.target.closest('[data-wa]');
      if (wa) { const x = list.find((s) => s.id === +wa.dataset.wa); if (x) await whatsapp(x, $('.fsa-pickrow__state', wa.closest('.fsa-pickrow'))); }
      const mt = ev.target.closest('[data-mailto]');
      if (mt) {
        const x = list.find((s) => s.id === +mt.dataset.mailto);
        if (!x) return;
        location.href = `mailto:${encodeURIComponent(x.emails[0])}?subject=${encodeURIComponent(fill($('#akSubject', m).value, x))}&body=${encodeURIComponent(fill($('#akBody', m).value, x))}`;
        if (await dialog({ title: `Did you send it to ${x.name}?`, body: 'Then it is noted as asked.', confirm: 'Yes, sent', cancel: 'Not yet' })) {
          try { D = await api.post('requests.php', { op: 'mark', recipient_id: x.id, how: 'other' }); render(); $('.fsa-pickrow__state', mt.closest('.fsa-pickrow')).innerHTML = `<span class="fsa-pickrow__state is-ok">${icon('check', 'i--sm')}Noted</span>`; } catch (err) { toastError(err); }
        }
      }
    });
    const go = $('#akGo', m);
    if (go) go.addEventListener('click', async () => {
      const ids2 = sel();
      if (!ids2.length) return;
      busy(go, true);
      let sent = 0;
      for (const rid of ids2) {
        const row = $(`#akList [data-rid="${rid}"]`, m);
        const state = $('.fsa-pickrow__state', row);
        state.className = 'fsa-pickrow__state';
        state.innerHTML = '<span class="fsa-spin"></span>';
        try {
          await api.post('requests.php', { op: 'send', recipient_id: rid, mailbox_id: +$('#akFrom', m).value, subject: $('#akSubject', m).value, body: $('#akBody', m).value, kind: 'ask' });
          state.className = 'fsa-pickrow__state is-ok';
          state.innerHTML = icon('check', 'i--sm') + 'Sent';
          $('input', row).checked = false;
          $('input', row).disabled = true;
          sent++;
        } catch (err) {
          state.className = 'fsa-pickrow__state is-err';
          state.textContent = err.message;
        }
      }
      busy(go, false);
      count();
      compose = null;
      await reload();
      if (sent === ids2.length) { closeModal(m); toast(F.plural(sent, 'email', 'emails') + ' sent — replies are noticed for you'); }
      else if (sent) toast(`${sent} of ${ids2.length} sent — see the others`, { type: 'error' });
    });
    openModal(m);
  }

  async function whatsapp(x, stateEl) {
    let c;
    try { c = await getCompose(); } catch (e) { return toastError(e); }
    if (!x.dial) return toast('There is no phone number for ' + x.name + '.', { type: 'error' });
    window.open('https://wa.me/' + encodeURIComponent(x.dial) + '?text=' + encodeURIComponent(fill(c.whatsapp, x)), '_blank', 'noopener');
    if (x.status !== 'to_ask') return;
    if (!(await dialog({ title: `Sent it to ${x.name}?`, body: 'WhatsApp opened with the message ready. Once it is sent, it is noted as asked.', confirm: 'Yes, sent', cancel: 'Not yet' }))) return;
    try {
      D = await api.post('requests.php', { op: 'mark', recipient_id: x.id, how: 'whatsapp' });
      render();
      if (stateEl) { stateEl.className = 'fsa-pickrow__state is-ok'; stateEl.innerHTML = icon('check', 'i--sm') + 'Noted'; }
    } catch (e) { toastError(e); }
  }

  async function remind(x) {
    let c;
    try { c = await getCompose(); } catch (e) { return toastError(e); }
    if (!(c.mailboxes || []).length) return toast('Connect your mailbox in Mail to send reminders from Flowwork.', { type: 'error' });
    const m = modal(`<div class="fwm-dialog__head"><h2>Remind ${esc(x.name)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body fsa-form"><p class="fsa-dlg-lead">It goes into the same conversation, so their reply stays with the request.</p>
        <div class="field"><label class="label" for="rmBody">Message</label><textarea class="textarea" id="rmBody" rows="7">${esc(fill(c.reminder, x))}</textarea></div></div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="rmGo">${icon('bell')}Send the reminder</button></div>`, { width: 560 });
    $('#rmGo', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      busy(b, true);
      try {
        await api.post('requests.php', { op: 'send', recipient_id: x.id, mailbox_id: c.mailboxes[0].id, subject: 'Re: ' + fill(c.subject, x), body: $('#rmBody', m).value, kind: 'reminder' });
        closeModal(m);
        toast('Reminder sent');
        reload();
      } catch (err) { busy(b, false); toastError(err); }
    });
    openModal(m);
  }

  function quoteDialog(x) {
    const vat = x.quote_vat || (x.vat ? 'incl' : 'none');
    const m = modal(`<div class="fwm-dialog__head"><h2>${esc(x.name)}’s quote</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fsa-form" novalidate>
        <div class="row">
          <div class="field"><label class="label" for="qAmount">Amount</label><span class="fsa-money"><span>R</span><input id="qAmount" inputmode="decimal" value="${x.quote != null ? (x.quote / 100).toFixed(2) : ''}" placeholder="0.00" autofocus></span></div>
          <div class="field"><span class="label">VAT</span><div class="fsa-seg" role="radiogroup">${Object.entries({ incl: 'Included', excl: 'Plus VAT', none: 'No VAT' }).map(([k, l]) => `<label><input type="radio" name="qVat" value="${k}"${k === vat ? ' checked' : ''}><span>${l}</span></label>`).join('')}</div></div>
        </div>
        <p class="fsa-calc" id="qCalc"></p>
        <div class="row">
          <div class="field"><label class="label" for="qLead">Lead or delivery time</label><span class="fsa-unit"><input class="input" id="qLead" inputmode="numeric" value="${x.lead_days != null ? x.lead_days : ''}"><span>days</span></span></div>
          <div class="field"><label class="label" for="qValid">Valid until</label><input class="input" id="qValid" type="date" value="${esc(x.valid_until || '')}"></div>
        </div>
        <div class="field"><label class="label" for="qNotes">Notes <span class="fsa-muted">(optional)</span></label><input class="input" id="qNotes" maxlength="500" value="${esc(x.quote_notes || '')}" placeholder="Includes materials, 12-month warranty, 50% deposit…"></div>
        ${!x.vat ? `<p class="fsa-hint">${icon('i-info', 'i--sm')}${esc(x.name)} has no VAT number in the CRM.</p>` : ''}
      </form>
      <div class="fwm-dialog__foot">${x.quote != null ? `<button class="btn btn--ghost" type="button" id="qClear">Clear the quote</button>` : ''}<span style="flex:1"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="qGo">${icon('check')}Save</button></div>`, { width: 540 });
    const calc = () => {
      const c = toCents($('#qAmount', m).value);
      const v = ($('input[name=qVat]:checked', m) || {}).value;
      $('#qCalc', m).innerHTML = c == null ? '' : (v === 'excl' ? `<b>${money(Math.round(c * 1.15))}</b> with VAT` : `<b>${money(c)}</b> ${v === 'incl' ? 'with VAT' : '— no VAT charged'}`);
    };
    $('#qAmount', m).addEventListener('input', calc);
    $$('input[name=qVat]', m).forEach((r) => r.addEventListener('change', calc));
    calc();
    const save = async (clear) => {
      const b = $('#qGo', m);
      const amount = $('#qAmount', m).value.trim();
      if (!clear && toCents(amount) === null) { toast('Type the amount in rand.', { type: 'error' }); return; }
      busy(b, true);
      try {
        D = await api.post('requests.php', { op: 'quote', recipient_id: x.id, amount: clear ? '' : toCents(amount) / 100, vat: ($('input[name=qVat]:checked', m) || {}).value, lead_days: $('#qLead', m).value.trim(), valid_until: $('#qValid', m).value, notes: $('#qNotes', m).value });
        closeModal(m);
        render();
        const li = $('#rs-' + x.id);
        if (li) li.classList.add('is-flash');
      } catch (e) { busy(b, false); toastError(e); }
    };
    $('#qGo', m).addEventListener('click', () => save(false));
    $('form', m).addEventListener('submit', (e) => { e.preventDefault(); save(false); });
    if ($('#qClear', m)) $('#qClear', m).addEventListener('click', () => save(true));
    openModal(m);
  }

  async function award(x) {
    const others = sups().filter((s) => s.id !== x.id && s.quote != null);
    const cheaper = others.filter((s) => s.quote_incl < (x.quote_incl ?? Infinity));
    const body = `${x.quote_incl != null ? `Their quote: <b>${money(x.quote_incl)}</b> with VAT${x.lead_days != null ? ', ' + x.lead_days + ' days' : ''}.` : 'They have no quote written down yet.'}
      ${cheaper.length ? `<br>${F.plural(cheaper.length, 'quote is', 'quotes are')} lower — that is fine if they are better value.` : ''}
      ${x.prospect ? '<br>They become an active supplier in the CRM.' : ''}`;
    if (!(await dialog({ title: `Choose ${x.name}?`, body, confirm: 'Choose them' }))) return;
    try {
      D = await api.post('requests.php', { op: 'award', recipient_id: x.id });
      render();
      doneDialog(x);
    } catch (e) { toastError(e); }
  }

  function doneDialog(x) {
    const thank = sups().filter((s) => s.id !== x.id && (s.sent_at || s.quote != null) && s.emails.length && s.status !== 'declined');
    const m = modal(`<div class="fsa-done"><div class="fsa-done__seal">${icon('isa-medal')}</div><h2>${esc(x.name)} it is</h2>
        <p>${x.quote_incl != null ? `<b>${money(x.quote_incl)}</b> with VAT. ` : ''}${can().order ? 'Make the purchase order now, or later from this page.' : 'An admin or bookkeeper can make the purchase order.'}${thank.length ? ` ${F.plural(thank.length, 'other supplier', 'other suppliers')} can be thanked in one go.` : ''}</p>
        <div class="fsa-done__acts">${thank.length ? `<button class="btn" type="button" data-d="thank">${icon('isa-thanks')}Thank the others</button>` : ''}
          ${can().order && x.quote != null ? `<button class="btn btn--primary" type="button" data-d="po">${icon('is-order')}Make the purchase order</button>` : ''}
          <button class="btn btn--ghost" type="button" data-close>Done</button></div></div>`, { width: 480 });
    m.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-d]');
      if (!b) return;
      if (b.dataset.d === 'po') { await makePo(b); closeModal(m); }
      if (b.dataset.d === 'thank') { closeModal(m); thanksDialog(thank); }
    });
    openModal(m);
  }

  async function thanksDialog(list) {
    let c;
    try { c = await getCompose(); } catch (e) { return toastError(e); }
    if (!(c.mailboxes || []).length) return toast('Connect your mailbox in Mail to thank them from Flowwork.', { type: 'error' });
    const m = modal(`<div class="fwm-dialog__head"><h2>Thank the others</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body fsa-form"><p class="fsa-dlg-lead">A short, kind note keeps them keen for the next one. It goes into each conversation.</p>
        <div class="field"><label class="label" for="thBody">Message</label><textarea class="textarea" id="thBody" rows="7">${esc(c.thanks.body)}</textarea></div>
        <div class="fsa-picklist" style="margin-top:12px">${list.map((x) => `<div class="fsa-pickrow" data-rid="${x.id}"><input type="checkbox" checked aria-label="Thank ${esc(x.name)}">${avatar(x.name)}<span class="fsa-pickrow__text"><b>${esc(x.name)}</b><small>${esc(x.emails[0])}</small></span><span class="fsa-pickrow__state"></span></div>`).join('')}</div></div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Not now</button><button class="btn btn--primary" type="button" id="thGo">${icon('isa-thanks')}Send</button></div>`, { width: 560 });
    $('#thGo', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      busy(b, true);
      let ok = 0, n = 0;
      for (const row of $$('[data-rid]', m)) {
        if (!$('input', row).checked) continue;
        n++;
        const x = list.find((s) => s.id === +row.dataset.rid);
        const st = $('.fsa-pickrow__state', row);
        st.innerHTML = '<span class="fsa-spin"></span>';
        try {
          await api.post('requests.php', { op: 'send', recipient_id: x.id, mailbox_id: c.mailboxes[0].id, subject: fill(c.thanks.subject, x), body: $('#thBody', m).value, kind: 'thanks' });
          st.className = 'fsa-pickrow__state is-ok'; st.innerHTML = icon('check', 'i--sm') + 'Sent'; ok++;
          $('input', row).checked = false;
        } catch (err) { st.className = 'fsa-pickrow__state is-err'; st.textContent = err.message; }
      }
      busy(b, false);
      reload();
      if (ok === n) { closeModal(m); toast(F.plural(ok, 'thank-you', 'thank-yous') + ' sent'); }
    });
    openModal(m);
  }

  async function makePo(btn) {
    busy(btn, true);
    try {
      const res = await api.post('requests.php', { op: 'po', id });
      toast(`Purchase order ${res.number} made for ${res.supplier}`, { action: { label: 'Open it', run: () => { location.href = res.url; } }, timeout: 9000 });
      await reload();
    } catch (e) { toastError(e); } finally { if (btn.isConnected) busy(btn, false); }
  }

  function editDialog() {
    const r = req();
    const m = modal(`<div class="fwm-dialog__head"><h2>The details</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fsa-form" novalidate>
        <div class="field"><label class="label" for="edTitle">What you need quotes for</label><input class="input" id="edTitle" maxlength="190" value="${esc(r.title)}"></div>
        <div class="field"><label class="label" for="edDetails">Details</label><textarea class="textarea" id="edDetails" rows="4" maxlength="4000">${esc(r.details)}</textarea></div>
        <div class="row"><div class="field"><label class="label" for="edQty">Quantity</label><input class="input" id="edQty" maxlength="80" value="${esc(r.quantity)}"></div>
          <div class="field"><label class="label" for="edWhere">Where</label><input class="input" id="edWhere" maxlength="160" value="${esc(r.location)}"></div></div>
        <div class="row"><div class="field"><label class="label" for="edNeed">Needed by</label><input class="input" id="edNeed" type="date" value="${esc(r.needed_by || '')}"></div>
          <div class="field"><label class="label" for="edBy">Quotes by</label><input class="input" id="edBy" type="date" value="${esc(r.quote_by || '')}"></div></div>
        <div class="row"><div class="field"><label class="label" for="edBudget">Budget <span class="fsa-muted">(never sent)</span></label><span class="fsa-money"><span>R</span><input id="edBudget" inputmode="decimal" value="${r.budget != null ? (r.budget / 100).toFixed(2) : ''}"></span></div>
          <div class="field"><label class="label" for="edReqs">Ask them to include <span class="fsa-muted">(commas)</span></label><input class="input" id="edReqs" maxlength="500" value="${esc((r.requirements || []).join(', '))}"></div></div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="edGo">${icon('check')}Save</button></div>`, { width: 620 });
    $('#edGo', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      const budget = $('#edBudget', m).value.trim();
      if (budget && toCents(budget) === null) { toast('The budget is not an amount in rand.', { type: 'error' }); return; }
      busy(b, true);
      try {
        D = await api.post('requests.php', { op: 'update', id, title: $('#edTitle', m).value.trim(), details: $('#edDetails', m).value, quantity: $('#edQty', m).value.trim(), location: $('#edWhere', m).value.trim(),
          needed_by: $('#edNeed', m).value, quote_by: $('#edBy', m).value, budget: budget ? toCents(budget) / 100 : '', requirements: $('#edReqs', m).value.split(',').map((s) => s.trim()).filter(Boolean) });
        compose = null;
        closeModal(m);
        render();
      } catch (e) { busy(b, false); toastError(e); }
    });
    openModal(m);
  }

  function addDialog() {
    const have = new Set(sups().map((x) => x.account_id));
    const max = can().max || 8;
    const m = modal(`<div class="fwm-dialog__head"><h2>Add suppliers</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body fsa-form">
        <label class="fsa-search" style="max-width:none">${icon('search', 'i--sm')}<span class="sr-only">Find a supplier</span><input id="adQ" placeholder="Find a supplier in your CRM" autocomplete="off" autofocus></label>
        <div class="fsa-picklist" id="adList" style="margin-top:12px"><p class="fsa-panel__empty"><span class="fsa-spin"></span> Looking…</p></div>
        <p class="fsa-hint">${icon('i-info', 'i--sm')}Not in your CRM? <a href="/suppliers_ai/?go=1&q=${encodeURIComponent(req().title + (req().location ? ' in ' + req().location : ''))}">Find new suppliers</a> and shortlist them. At most ${max} on a request.</p>
      </div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="adGo" disabled>${icon('plus')}<span>Add</span></button></div>`, { width: 560 });
    const chosen = new Set();
    const draw = (rows) => {
      $('#adList', m).innerHTML = rows.length ? rows.map((s) => {
        const on = have.has(s.account_id);
        const blocked = s.compliance && s.compliance.blocking;
        return `<label class="fsa-pickrow${on || blocked ? ' is-off' : ''}"><input type="checkbox" value="${s.account_id}"${on ? ' checked disabled' : (blocked ? ' disabled' : (chosen.has(s.account_id) ? ' checked' : ''))}>${avatar(s.name)}
          <span class="fsa-pickrow__text"><b>${esc(s.name)}${s.preferred ? ' ★' : ''}</b><small>${on ? 'Already on the request' : blocked ? 'Blocked — ' + esc(s.compliance.label.toLowerCase()) : esc([s.email, s.phone].filter(Boolean).join(' · ') || 'No email or phone')}</small></span></label>`;
      }).join('') : '<p class="fsa-panel__empty">No supplier by that name.</p>';
    };
    const look = F.debounce(async () => {
      try { draw((await api.get('suppliers.php', { op: 'crm', q: $('#adQ', m).value.trim() })).suppliers); } catch (e) { toastError(e); }
    }, 220);
    $('#adQ', m).addEventListener('input', look);
    $('#adList', m).addEventListener('change', (e) => {
      const i = e.target.closest('input[type=checkbox]');
      if (!i) return;
      if (i.checked) chosen.add(+i.value); else chosen.delete(+i.value);
      const go = $('#adGo', m);
      go.disabled = !chosen.size;
      $('span', go).textContent = chosen.size ? 'Add ' + chosen.size : 'Add';
    });
    $('#adGo', m).addEventListener('click', async (ev) => {
      const b = ev.currentTarget;
      busy(b, true);
      try {
        const res = await api.post('requests.php', { op: 'add', id, suppliers: [...chosen].map((a) => ({ account_id: a })) });
        closeModal(m);
        reload(res);
        if (res.skipped && res.skipped.length) toast('Not added: ' + res.skipped.map((s) => s.name + ' (' + s.why + ')').join('; '), { type: 'error' });
        else toast(F.plural(res.added, 'supplier', 'suppliers') + ' added');
      } catch (e) { busy(b, false); toastError(e); }
    });
    look();
    openModal(m);
  }

  // ------------------------------------------------------------------ start
  render();
  try {
    const skipped = JSON.parse(sessionStorage.getItem('fsa-skipped') || 'null');
    if (skipped && skipped.length) toast('Not added: ' + skipped.map((s) => s.name + ' (' + s.why + ')').join('; '), { type: 'error', timeout: 12000 });
    sessionStorage.removeItem('fsa-skipped');
  } catch (e) { /* private mode */ }
  if (BOOT.ask) {
    history.replaceState(null, '', '/suppliers_ai/request.php?id=' + id);
    const toAsk = sups().filter((x) => x.status === 'to_ask').map((x) => x.id);
    if (toAsk.length && can().manage) askDialog(toAsk);
  }
  // Replies are noticed by themselves: look again every minute while the page is open
  setInterval(async () => {
    if (document.hidden || F.modalOpen || req().status !== 'open') return;
    try {
      const fresh = await api.get('requests.php', { op: 'get', id });
      const before = D.summary.replied;
      if (fresh.summary.replied > before) toast(F.plural(fresh.summary.replied - before, 'new reply', 'new replies'));
      if (JSON.stringify(fresh.suppliers) !== JSON.stringify(D.suppliers)) { D = fresh; render(); }
    } catch (e) { /* next time */ }
  }, 60000);
})();
