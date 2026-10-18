// /qi/js/view.js — the document page: the paper as the customer sees it, a
// rail with what is owed and paid and everything that happened, and every
// action the document's state allows (the server sends the list; the action
// API enforces it again).
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, popover, openModal, closeModal, tempModal, dialog, BOOT, money, fmtDate, relDue, relTime, daysFrom, debounce, narrow, pill, TYPE_NAMES, viewUrl, editUrl } = QI;

  let V = BOOT;
  const T = V.type;
  const NOUN = { quote: 'quote', invoice: 'invoice', credit_note: 'credit note', recurring: 'recurring invoice' }[T];
  const el = { head: $('#vwHead'), banner: $('#vwBanner'), paper: $('#vwPaper'), wrap: $('#vwPaperWrap'), stage: $('#vwStage'), rail: $('#vwRail'), mobile: $('#vwMobile') };
  let zoom = 0;
  const m$ = (v) => money(v, V.symbol || 'R');
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
  const listRoute = '/qi/#' + (T === 'credit_note' ? 'credit_notes' : T === 'recurring' ? 'recurring' : T + 's');

  // ------------------------------------------------------------ render
  function render() {
    renderHead();
    renderBanner();
    el.paper.innerHTML = V.html;
    renderRail();
    requestAnimationFrame(fit);
    document.title = TYPE_NAMES[T] + ' ' + V.number + ' · ' + (document.title.split(' · ').pop());
  }

  function renderHead() {
    const main = V.actions.filter((a) => a.group === 'main');
    const more = V.actions.filter((a) => a.group !== 'main');
    const visible = narrow() ? main.slice(0, 2) : main.slice(0, 4);
    const overflow = main.slice(visible.length).concat(more);
    const cust = V.customer ? V.customer.name : '';
    el.head.innerHTML = `
      <a class="icon-btn" href="${esc(listRoute)}" aria-label="Back to the list" data-tip="Back">${icon('arrow-left')}</a>
      <div class="fwq-head__title">
        <div class="fwq-eyebrow">${esc(TYPE_NAMES[T])}${cust ? ' · ' + esc(cust) : ''}</div>
        <h1>${esc(V.number)} ${pill(V.status)}</h1>
      </div>
      <div class="fwq-head__actions">
        ${visible.map((a) => btn(a)).join('')}
        ${overflow.length ? `<button class="icon-btn" id="vwMore" type="button" aria-label="More actions" aria-haspopup="menu">${icon('more-h')}</button>` : ''}
      </div>`;
    const moreBtn = $('#vwMore');
    if (moreBtn) moreBtn.addEventListener('click', () => {
      popover(moreBtn, overflow.map((a, i) => (i > 0 && a.tone === 'danger' && overflow[i - 1].tone !== 'danger' ? '<div class="fwm-pop__sep"></div>' : '')
        + `<button class="fwm-pop__item${a.tone === 'danger' ? ' is-danger' : ''}" data-v="${esc(a.key)}" type="button">${icon(a.icon)}${esc(a.label)}</button>`).join(''),
        { align: 'end', width: 240, onClick: (b) => { setTimeout(() => run(b.dataset.v), 0); } });
    });
  }
  const btn = (a) => `<button class="btn${a.tone === 'primary' ? ' btn--primary' : ''}${a.tone === 'danger' ? ' btn--danger' : ''}" type="button" data-act="${esc(a.key)}">${icon(a.icon)}<span>${esc(a.label)}</span></button>`;

  function renderBanner() {
    const s = V.summary || {};
    const items = [];
    if (V.deleted) items.push(['danger', 'trash', `This invoice was deleted. It is out of the ledger and hidden from your lists.`]);
    else if (V.archived) items.push(['muted', 'archive', 'Archived — hidden from the invoice list. It is still in the ledger.']);
    if (T === 'quote') {
      if (V.accepted && V.accepted.at) items.push(['success', 'iq-accept', `Accepted${V.accepted.name ? ' by <b>' + esc(V.accepted.name) + '</b>' : ''} on ${esc(fmtDate(String(V.accepted.at).slice(0, 10)))}.`]);
      if (V.status.key === 'expired') items.push(['warning', 'clock', `Expired on ${esc(fmtDate(s.expiry_date))}. Revise it to send a fresh version.`]);
      else if (s.expiry_date && ['sent', 'viewed'].includes(V.status.key)) {
        const d = daysFrom(s.expiry_date);
        if (d !== null && d <= 3) items.push(['warning', 'clock', `Valid until ${esc(fmtDate(s.expiry_date))} — ${esc(relDue(s.expiry_date))}.`]);
      }
    }
    if (T === 'invoice' && !V.deleted) {
      if (V.status.key === 'overdue') {
        const d = -daysFrom(s.due_date);
        items.push(['danger', 'alert', `<b>${d} day${d === 1 ? '' : 's'} overdue</b> — ${esc(m$(s.balance))} was due on ${esc(fmtDate(s.due_date))}.${V.can_write ? ' <button class="fwq-link" type="button" data-act="remind">Send a reminder</button>' : ''}`]);
      }
      if (V.lock.level === 'wording' && V.status.key !== 'paid') items.push(['muted', 'lock', esc(V.lock.reason)]);
      if (V.raw_status === 'cancelled') items.push(['muted', 'iq-void', 'Voided — this invoice is no longer owed and is out of the ledger.']);
    }
    if ((T === 'quote' || T === 'invoice') && V.viewed_at) items.push(['info', 'eye', `Viewed by the customer ${esc(relTime(V.viewed_at))}.`]);
    if (T === 'recurring' && V.status.key !== 'active') items.push(['muted', 'clock', V.status.label === 'Ended' ? 'This recurring invoice has ended.' : 'Paused — no invoices are generated until you resume it.']);
    el.banner.innerHTML = items.map(([tone, ic, html]) => `<div class="fwq-banner" data-tone="${tone}">${icon(ic)}<div>${html}</div></div>`).join('');
  }

  function summaryCard() {
    const s = V.summary || {};
    if (T === 'invoice') {
      const settled = (s.paid || 0) + (s.credited || 0) + (s.written_off || 0);
      const pct = s.total > 0 ? Math.max(0, Math.min(100, settled / s.total * 100)) : 0;
      const paidUp = s.balance <= 0.004 && V.raw_status !== 'draft' && V.raw_status !== 'cancelled';
      return `<div class="fwq-vw-sum">
        <div class="fwq-vw-sum__label">${paidUp ? 'Paid in full' : V.raw_status === 'cancelled' ? 'Voided' : 'Balance due'}</div>
        <div class="fwq-vw-sum__big${paidUp ? ' is-paid' : ''}">${paidUp ? icon('check') + esc(m$(s.total)) : esc(m$(s.balance))}</div>
        <div class="fwq-vw-sum__sub">${paidUp ? 'Settled' : 'of ' + esc(m$(s.total))}${s.due_date && !paidUp && V.raw_status !== 'cancelled' ? ' · due ' + esc(fmtDate(s.due_date)) + ' (' + esc(relDue(s.due_date)) + ')' : ''}</div>
        <div class="fwq-vw-bar"><i style="width:${pct}%"></i></div>
        <dl class="fwq-vw-facts">
          <div><dt>Issued</dt><dd>${esc(fmtDate(s.issue_date))}</dd></div>
          ${s.paid ? `<div><dt>Paid</dt><dd>${esc(m$(s.paid))}</dd></div>` : ''}
          ${s.credited ? `<div><dt>Credited</dt><dd>${esc(m$(s.credited))}</dd></div>` : ''}
          ${s.written_off ? `<div><dt>Written off</dt><dd>${esc(m$(s.written_off))}</dd></div>` : ''}
          ${s.reference ? `<div><dt>Reference</dt><dd>${esc(s.reference)}</dd></div>` : ''}
          ${s.exchange_rate ? `<div><dt>Rate</dt><dd>1 ${esc(V.currency)} = R ${esc(String(s.exchange_rate))}</dd></div>` : ''}
        </dl></div>`;
    }
    if (T === 'quote') {
      return `<div class="fwq-vw-sum">
        <div class="fwq-vw-sum__label">Quote total</div>
        <div class="fwq-vw-sum__big">${esc(m$(s.total))}</div>
        <div class="fwq-vw-sum__sub">${s.expiry_date ? 'Valid until ' + esc(fmtDate(s.expiry_date)) + (['sent', 'viewed', 'draft'].includes(V.status.key) ? ' (' + esc(relDue(s.expiry_date)) + ')' : '') : ''}</div>
        <dl class="fwq-vw-facts">
          <div><dt>Date</dt><dd>${esc(fmtDate(s.issue_date))}</dd></div>
          ${V.sent_at ? `<div><dt>Sent</dt><dd>${esc(relTime(V.sent_at))}</dd></div>` : ''}
          ${s.reference ? `<div><dt>Reference</dt><dd>${esc(s.reference)}</dd></div>` : ''}
          ${s.exchange_rate ? `<div><dt>Rate</dt><dd>1 ${esc(V.currency)} = R ${esc(String(s.exchange_rate))}</dd></div>` : ''}
        </dl></div>`;
    }
    if (T === 'credit_note') {
      return `<div class="fwq-vw-sum">
        <div class="fwq-vw-sum__label">Credit</div>
        <div class="fwq-vw-sum__big">${esc(m$(s.total))}</div>
        <div class="fwq-vw-sum__sub">${s.unapplied > 0.004 && V.raw_status !== 'draft' && V.raw_status !== 'cancelled' ? esc(m$(s.unapplied)) + ' not applied yet' : V.raw_status === 'applied' ? 'Applied in full' : ''}</div>
        <dl class="fwq-vw-facts"><div><dt>Date</dt><dd>${esc(fmtDate(s.issue_date))}</dd></div>${s.applied ? `<div><dt>Applied</dt><dd>${esc(m$(s.applied))}</dd></div>` : ''}</dl>
        ${V.reason ? `<p class="fwq-vw-note"><b>Reason:</b> ${esc(V.reason)}</p>` : ''}</div>`;
    }
    return `<div class="fwq-vw-sum">
      <div class="fwq-vw-sum__label">Each invoice</div>
      <div class="fwq-vw-sum__big">${esc(m$(s.total))}</div>
      <div class="fwq-vw-sum__sub">${esc(s.every)}${s.next ? ' · next on ' + esc(fmtDate(s.next)) : ''}</div>
      <dl class="fwq-vw-facts">
        <div><dt>Ends</dt><dd>${s.end ? esc(fmtDate(s.end)) : 'Never'}</dd></div>
        <div><dt>Payment terms</dt><dd>${s.terms === null ? 'Default' : s.terms === 0 ? 'On receipt' : s.terms + ' days'}</dd></div>
        <div><dt>Emailing</dt><dd>${s.auto_send ? 'Automatic' : 'You send them'}</dd></div>
        <div><dt>Generated</dt><dd>${s.count} invoice${s.count === 1 ? '' : 's'} · ${esc(m$(s.billed))}</dd></div>
      </dl></div>`;
  }

  function renderRail() {
    const parts = [];
    parts.push(`<div class="fwq-vw-only-wide">${summaryCard()}</div>`);
    el.mobile.innerHTML = summaryCard();
    const c = V.customer;
    if (c) {
      parts.push(railSec('users', 'Customer', `<div class="fwq-vw-cust">
        <a class="fwq-vw-cust__name" href="${esc(c.crm_url)}">${esc(c.name)}</a>
        ${c.legal_name ? `<div class="fwq-vw-muted">${esc(c.legal_name)}</div>` : ''}
        ${(c.address || []).length ? `<div class="fwq-vw-addr">${c.address.map(esc).join('<br>')}</div>` : ''}
        <div class="fwq-vw-muted">${[c.email, c.phone].filter(Boolean).map(esc).join(' · ')}</div>
        ${c.owing > 0 ? `<div class="fwq-ccard__owing">${icon('clock', 'i--sm')}Owes ${esc(money(c.owing, 'R'))} on ${c.open_invoices} invoice${c.open_invoices === 1 ? '' : 's'}</div>` : ''}
      </div>`));
    }
    if ((V.related || []).length || V.project) {
      parts.push(railSec('link', 'Linked', `<div class="fwq-vw-links">${(V.related || []).map((r) => `<a href="${esc(viewUrl(r.type, r.id))}">${icon(r.type === 'quote' ? 'iq-quote' : r.type === 'recurring' ? 'iq-recurring' : 'iq-invoice')}<span><small>${esc(r.label)}</small>${esc(r.number)}</span></a>`).join('')}
        ${V.project ? `<a href="${esc(V.project.url)}">${icon('kanban')}<span><small>Project</small>${esc(V.project.name)}</span></a>` : ''}</div>`));
    }
    if ((V.milestones || []).length) {
      parts.push(railSec('coins', 'Payment schedule', `<div class="fwq-vw-ms">${V.milestones.map((ms) => `<div class="fwq-vw-ms__row">
          <div><b>${esc(ms.label)}</b><small>${esc(m$(ms.amount))}${ms.due_date ? ' · due ' + esc(fmtDate(ms.due_date)) : ''}</small></div>
          ${T === 'invoice' ? `<span class="fwq-pill" data-tone="${ms.status === 'paid' ? 'success' : ms.status === 'overdue' ? 'danger' : ms.status === 'due' ? 'info' : 'muted'}">${esc(ms.status === 'paid' ? 'Paid' : ms.status === 'due' ? 'Due' : ms.status === 'overdue' ? 'Overdue' : 'Later')}</span>` : `<span class="fwq-vw-muted">${Math.round(ms.percentage * 100) / 100}%</span>`}
          ${T === 'invoice' && ms.paid > 0 && ms.status !== 'paid' ? `<div class="fwq-vw-bar fwq-vw-bar--sm"><i style="width:${Math.min(100, ms.paid / ms.amount * 100)}%"></i></div>` : ''}
        </div>`).join('')}</div>`));
    }
    if (T === 'invoice') {
      if ((V.payments || []).length) {
        parts.push(railSec('iq-pay', 'Payments', `<div class="fwq-vw-list">${V.payments.map((p) => `<div class="fwq-vw-list__row">
            <span class="fwq-vw-list__ic${p.refund ? ' is-out' : ''}">${icon(p.refund ? 'iq-refund' : 'iq-pay')}</span>
            <div><b>${esc(p.method)}</b><small>${esc(fmtDate(p.date))}${p.reference ? ' · ' + esc(p.reference) : ''}</small></div>
            <span class="fwq-vw-list__amt${p.refund ? ' is-out' : ''}">${esc(m$(p.amount))}</span></div>`).join('')}</div>`));
      }
      if ((V.credit_notes || []).length) {
        parts.push(railSec('iq-credit', 'Credit notes', `<div class="fwq-vw-list">${V.credit_notes.map((cn) => `<a class="fwq-vw-list__row" href="${esc(viewUrl('credit_note', cn.id))}">
            <span class="fwq-vw-list__ic">${icon('iq-credit')}</span><div><b>${esc(cn.number)}</b><small>${esc(fmtDate(cn.date))}${pill(cn.status)}</small></div>
            <span class="fwq-vw-list__amt">${esc(m$(cn.total))}</span></a>`).join('')}</div>`));
      }
    }
    if (T === 'credit_note' && (V.allocations || []).length) {
      parts.push(railSec('iq-invoice', 'Applied to', `<div class="fwq-vw-list">${V.allocations.map((a) => `<a class="fwq-vw-list__row" href="${esc(viewUrl('invoice', a.invoice_id))}">
          <span class="fwq-vw-list__ic">${icon('iq-invoice')}</span><div><b>${esc(a.number)}</b></div><span class="fwq-vw-list__amt">${esc(m$(a.amount))}</span></a>`).join('')}</div>`));
    }
    if (T === 'recurring') {
      if ((V.upcoming || []).length) {
        parts.push(railSec('calendar', 'Coming up', `<div class="fwq-vw-dates">${V.upcoming.map((d, i) => `<span class="${i === 0 ? 'is-next' : ''}"><b>${esc(fmtDate(d, false))}</b><small>${esc(String(d).slice(0, 4))}</small></span>`).join('')}</div>`));
      }
      parts.push(railSec('iq-invoice', 'Invoices generated', (V.generated || []).length ? `<div class="fwq-vw-list">${V.generated.map((g) => `<a class="fwq-vw-list__row" href="${esc(viewUrl('invoice', g.id))}">
          <span class="fwq-vw-list__ic">${icon('iq-invoice')}</span><div><b>${esc(g.number)}</b><small>${esc(fmtDate(g.date))}${pill(g.status)}</small></div>
          <span class="fwq-vw-list__amt">${esc(m$(g.total))}</span></a>`).join('')}</div>` : '<p class="fwq-vw-muted">None yet.</p>'));
    }
    const acts = V.activity || [];
    const emails = V.emails || [];
    parts.push(railSec('history', 'Timeline', acts.length || emails.length ? `<ol class="fwq-vw-tl">${timeline(acts, emails)}</ol>` : '<p class="fwq-vw-muted">Nothing has happened yet.</p>'));
    el.rail.innerHTML = parts.join('');
  }
  const railSec = (ic, title, body) => `<section class="fwq-vw-sec"><h3>${icon(ic, 'i--sm')}${esc(title)}</h3>${body}</section>`;
  const ACT_ICON = { created: 'plus', edited: 'pen', reposted: 'pen', sent: 'send', send_failed: 'alert', viewed: 'eye', accepted: 'iq-accept', declined: 'iq-decline', converted: 'iq-convert',
    issued: 'check', payment: 'iq-pay', refund: 'iq-refund', credit: 'iq-credit', unapplied: 'undo', approved: 'check', cancelled: 'iq-void', voided: 'iq-void', reverted: 'undo',
    written_off: 'minus', uncollectible: 'ban', archived: 'archive', restored: 'undo', deleted: 'trash', generated: 'zap', generate_failed: 'alert', paused: 'clock', resumed: 'play', pay_link: 'iq-pay', reminder: 'bell' };
  function timeline(acts, emails) {
    // email failures already appear as send_failed entries; show the email log only where it adds the delivery result
    const items = acts.map((a) => ({ at: a.at, html: `<li class="fwq-vw-tl__item${a.action === 'send_failed' || a.action === 'generate_failed' ? ' is-bad' : ''}"><span class="fwq-vw-tl__ic">${icon(ACT_ICON[a.action] || 'check')}</span>
        <div><b>${esc(a.label)}</b>${a.detail ? `<span>${esc(a.detail)}</span>` : ''}<small>${esc(relTime(a.at))}${a.actor ? ' · ' + esc(a.actor) : ''}</small></div></li>` }));
    if (!acts.some((a) => a.action === 'sent' || a.action === 'reminder' || a.action === 'send_failed')) {
      emails.forEach((e) => items.push({ at: e.at, html: `<li class="fwq-vw-tl__item${e.status !== 'sent' ? ' is-bad' : ''}"><span class="fwq-vw-tl__ic">${icon(e.status === 'sent' ? 'mail' : 'alert')}</span>
        <div><b>${e.status === 'sent' ? (e.reminder ? 'Reminder emailed' : 'Emailed') : 'Email failed'}</b><span>${esc(e.to)}${e.error ? ' — ' + esc(e.error) : ''}</span><small>${esc(relTime(e.at))}</small></div></li>` }));
    }
    items.sort((a, b) => String(b.at).localeCompare(String(a.at)));
    return items.map((i) => i.html).join('');
  }

  // ------------------------------------------------------------ paper
  function fit() {
    const qd = $('.qd', el.paper);
    if (!qd || !el.stage.clientWidth) return;
    const w = qd.offsetWidth;
    const avail = el.stage.clientWidth - (narrow() ? 16 : 56);
    const auto = Math.min(1, avail / w);
    const z = zoom ? Math.max(0.3, Math.min(1.6, zoom)) : auto;
    el.paper.style.transform = `scale(${z})`;
    el.paper.style.width = w + 'px';
    el.wrap.style.height = (qd.offsetHeight * z + (narrow() ? 30 : 70)) + 'px';
    const zb = $('#vwZoomFit');
    if (zb) zb.textContent = Math.round(z * 100) + '%';
  }
  window.addEventListener('resize', debounce(() => { fit(); renderHead(); }, 120));
  const curZoom = () => { const mm = /scale\(([\d.]+)\)/.exec(el.paper.style.transform || ''); return mm ? Number(mm[1]) : 1; };
  $('#vwZoomIn').addEventListener('click', () => { zoom = Math.min(1.6, (zoom || curZoom()) + 0.1); fit(); });
  $('#vwZoomOut').addEventListener('click', () => { zoom = Math.max(0.3, (zoom || curZoom()) - 0.1); fit(); });
  $('#vwZoomFit').addEventListener('click', () => { zoom = 0; fit(); });

  // ------------------------------------------------------------ actions
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act]');
    if (!b || !document.body.contains(b) || b.closest('.fwm-modal')) return;
    run(b.dataset.act);
  });
  async function reload() {
    try {
      V = Object.assign(await api.get('view.php', { type: T, id: V.id }), { today: V.today });
      render();
    } catch (e) { toastError(e); }
  }
  async function act(action, body = {}, opts = {}) {
    try {
      const r = await api.post('action.php', Object.assign({ type: T, id: V.id, action }, body));
      if (opts.done) opts.done(r.result || {});
      if (opts.reload !== false) await reload();
      return r.result || {};
    } catch (e) {
      if (e.data && e.data.issued) reload();
      if (opts.fail) opts.fail(e);
      else toastError(e);
      throw e;
    }
  }
  const field = (label, control, hint = '') => `<label class="field"><span class="label">${label}</span>${control}${hint ? `<span class="hint">${hint}</span>` : ''}</label>`;
  function modal(title, body, confirmLabel, opts = {}) {
    return new Promise((resolve) => {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
        <form class="fwm-dialog__body" novalidate>${opts.lead ? `<p class="fwm-dialog__lead">${opts.lead}</p>` : ''}${body}<p class="fwq-vw-err" role="alert" hidden></p></form>
        <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button>
        <button class="btn btn--primary${opts.danger ? ' fwq-danger' : ''}" type="button" data-go>${opts.icon ? icon(opts.icon) : ''}${esc(confirmLabel)}</button></div>`, opts.wide ? 'fwm-dialog--wide' : '', opts.wide ? '' : 'width:min(500px,100%)');
      const form = $('form', m);
      const go = $('[data-go]', m);
      const err = $('.fwq-vw-err', m);
      let busy = false;
      const submit = async () => {
        if (busy) return;
        busy = true;
        go.disabled = true;
        err.hidden = true;
        try {
          await opts.submit(form, m);
          m._onClose = null;
          closeModal(m);
          resolve(true);
        } catch (e) {
          err.textContent = e && e.message ? e.message : String(e);
          err.hidden = false;
          if (e && e.data && e.data.field && form.elements[e.data.field]) form.elements[e.data.field].focus();
        } finally {
          busy = false;
          go.disabled = false;
        }
      };
      go.addEventListener('click', submit);
      form.addEventListener('submit', (ev) => { ev.preventDefault(); submit(); });
      m._onClose = () => resolve(false);
      if (opts.init) opts.init(form, m);
      openModal(m);
    });
  }
  const val = (f, n) => (f.elements[n] ? f.elements[n].value : '');

  async function run(key) {
    const s = V.summary || {};
    switch (key) {
      case 'edit': location.href = V.links.edit; return;
      case 'pdf': window.open(V.links.pdf + '&inline=1', '_blank', 'noopener'); return;
      case 'duplicate': location.href = editUrl(T) + '&from=' + T + ':' + V.id; return;
      case 'recurring': location.href = editUrl('recurring') + '&from=invoice:' + V.id; return;
      case 'credit': location.href = editUrl('credit_note') + '&invoice_id=' + V.id; return;
      case 'send': case 'remind': return sendDialog(key === 'remind' ? 'reminder' : T);
      case 'payment': return paymentDialog();
      case 'refund': return refundDialog();
      case 'link': return linkDialog('link');
      case 'pay_link': return linkDialog('pay_link');
      case 'mark_sent': return act('mark_sent', {}, { done: () => toast('Marked as sent') }).catch(() => {});
      case 'issue': {
        const ok = await dialog({ title: 'Issue without emailing?', body: 'The invoice becomes a tax invoice, is posted to the ledger and can be paid. Use this when you send the PDF yourself (WhatsApp, print).', confirm: 'Issue invoice' });
        if (ok) act('issue', {}, { done: () => toast('Invoice issued') }).catch(() => {});
        return;
      }
      case 'convert': {
        const ok = await dialog({ title: 'Turn into an invoice?', body: V.raw_status === 'accepted' ? 'A draft invoice is made from this quote — lines, schedule and all. You can still change it before sending.' : 'The quote is marked as accepted and a draft invoice is made from it — lines, schedule and all.', confirm: 'Create invoice' });
        if (!ok) return;
        try {
          const r = await act('convert', {}, { reload: false });
          try { sessionStorage.setItem('qi-flash', 'Invoice ' + r.invoice_number + ' is ready — check it and send it.'); } catch (e) { /* private mode */ }
          location.href = viewUrl('invoice', r.invoice_id);
        } catch (e) { /* toast shown */ }
        return;
      }
      case 'accept':
        return modal('Mark as accepted', field('Accepted by <small>(optional)</small>', '<input class="input" name="name" maxlength="190" placeholder="Who accepted it">')
          + `<label class="switch" style="margin-top:12px"><input type="checkbox" name="convert" checked><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Also create the invoice</span></span></label>`,
          'Mark accepted', { icon: 'iq-accept', submit: async (f) => {
            const r = await act('accept', { name: val(f, 'name'), convert: f.elements.convert.checked }, { fail: (e) => { throw e; } });
            toast(r.invoice_number ? 'Accepted — invoice ' + r.invoice_number + ' created' : 'Quote accepted', r.invoice_id ? { action: { label: 'Open invoice', run: () => { location.href = viewUrl('invoice', r.invoice_id); } } } : {});
          } });
      case 'decline':
        return modal('Mark as declined', field('Reason <small>(optional)</small>', '<textarea class="textarea" name="reason" rows="3" maxlength="400" placeholder="e.g. Went with another supplier"></textarea>'),
          'Mark declined', { icon: 'iq-decline', submit: async (f) => { await act('decline', { reason: val(f, 'reason') }, { fail: (e) => { throw e; } }); toast('Quote marked as declined'); } });
      case 'void':
        return modal('Void ' + V.number + '?', field('Reason', '<input class="input" name="reason" maxlength="255" placeholder="e.g. Issued in error" required>'),
          'Void invoice', { danger: true, icon: 'iq-void', lead: 'The invoice is cancelled and its ledger entry reversed. The number stays used. To give the customer a document, use a credit note instead.',
            submit: async (f) => { await act('void', { reason: val(f, 'reason') }, { fail: (e) => { throw e; } }); toast('Invoice voided'); } });
      case 'credit_full':
        return modal('Cancel with a credit note', field('Reason on the credit note', '<textarea class="textarea" name="reason" rows="3" maxlength="2000" placeholder="e.g. Order cancelled by the customer"></textarea>', 'SARS requires the reason on the credit note.'),
          'Create credit note', { icon: 'iq-credit', lead: 'A credit note for the full invoice is created, approved (posted) and applied, so nothing is owed anymore.',
            submit: async (f) => {
              const r = await act('credit_full', { reason: val(f, 'reason') }, { fail: (e) => { throw e; } });
              toast('Credit note ' + r.credit_note_number + ' applied', { action: { label: 'Open it', run: () => { location.href = viewUrl('credit_note', r.credit_note_id); } } });
            } });
      case 'write_off':
        return modal('Write off', field('Amount', `<input class="input" name="amount" inputmode="decimal" value="${esc(Number(s.balance || 0).toFixed(2))}">`, 'The rest of the balance stays owing.')
          + field('Reason', '<input class="input" name="reason" maxlength="255" placeholder="e.g. Customer liquidated">'), 'Write off', { danger: true, icon: 'minus',
            lead: 'The amount moves to bad debts, with the VAT on it relieved (VAT Act s22).', submit: async (f) => { await act('write_off', { amount: val(f, 'amount'), reason: val(f, 'reason') }, { fail: (e) => { throw e; } }); toast('Written off'); } });
      case 'uncollectible':
        return modal('Mark uncollectible', field('Reason <small>(optional)</small>', '<input class="input" name="reason" maxlength="255">'), 'Mark uncollectible',
          { lead: 'The invoice stays in the ledger but leaves your follow-ups. Reopen it any time.', submit: async (f) => { await act('uncollectible', { reason: val(f, 'reason') }, { fail: (e) => { throw e; } }); toast('Marked uncollectible'); } });
      case 'reopen': {
        const ok = await dialog({ title: V.raw_status === 'uncollectible' ? 'Reopen this invoice?' : 'Back to draft?', body: V.raw_status === 'uncollectible' ? 'It becomes an open invoice you can follow up again.' : 'The invoice leaves the ledger and becomes an editable draft with the same number. Issue it again when it is ready.', confirm: 'Reopen' });
        if (ok) act('reopen', {}, { done: () => toast('Invoice reopened') }).catch(() => {});
        return;
      }
      case 'unapply': {
        const what = T === 'credit_note' ? 'Remove the credit from its invoice?' : 'Remove the payments from this invoice?';
        const body = T === 'credit_note' ? 'The invoice owes that amount again. The credit note stays approved so you can apply it elsewhere.' : 'The payments stay in the ledger as money on the customer\'s account; the invoice owes its full amount again.';
        const ok = await dialog({ title: what, body, confirm: 'Remove', danger: true });
        if (ok) act('unapply', {}, { done: () => toast('Removed') }).catch(() => {});
        return;
      }
      case 'archive': case 'unarchive':
        return act(key, {}, { done: () => toast(key === 'archive' ? 'Archived' : 'Back in the invoice list') }).catch(() => {});
      case 'restore':
        return act('restore', {}, { done: () => toast('Invoice restored') }).catch(() => {});
      case 'delete': {
        const ok = await dialog({ title: 'Delete ' + V.number + '?', body: T === 'invoice' && V.raw_status !== 'draft' ? 'The invoice is reversed out of the ledger and hidden. An administrator can restore it.' : 'This cannot be undone.', confirm: 'Delete', danger: true });
        if (!ok) return;
        try { await act('delete', {}, { reload: false }); try { sessionStorage.setItem('qi-flash', TYPE_NAMES[T] + ' ' + V.number + ' deleted'); } catch (e) { /* */ } location.href = listRoute; } catch (e) { /* toast */ }
        return;
      }
      case 'approve': {
        const ok = await dialog({ title: 'Approve ' + V.number + '?', body: 'The credit note is issued: it is posted to the ledger and reduces your output VAT on its date. It can then be applied to an invoice.', confirm: 'Approve' });
        if (!ok) return;
        try {
          await act('approve', {}, { reload: true });
          toast('Credit note approved');
          if ((V.apply_to || []).length) run('apply');
        } catch (e) { /* toast */ }
        return;
      }
      case 'apply': return applyDialog();
      case 'cancel':
        return modal('Cancel ' + V.number + '?', field('Reason <small>(optional)</small>', '<input class="input" name="reason" maxlength="255">'), 'Cancel credit note',
          { danger: true, lead: V.raw_status === 'approved' ? 'Its ledger entry is reversed.' : '', submit: async (f) => { await act('cancel', { reason: val(f, 'reason') }, { fail: (e) => { throw e; } }); toast('Credit note cancelled'); } });
      case 'run': {
        const ok = await dialog({ title: 'Generate the next invoice now?', body: 'An invoice for ' + esc(m$(s.total)) + ' is created and issued today' + (s.auto_send ? ' and emailed to the customer' : '') + '. The schedule moves on to the following date.', confirm: 'Generate invoice' });
        if (!ok) return;
        try {
          const r = await act('run', {});
          toast('Invoice ' + r.invoice_number + ' generated' + (r.emailed ? ' and emailed' : r.email_error ? ' — the email failed: ' + r.email_error : ''), { action: { label: 'Open', run: () => { location.href = viewUrl('invoice', r.invoice_id); } } });
        } catch (e) { /* toast */ }
        return;
      }
      case 'pause': case 'resume':
        return act(key, {}, { done: (r) => toast(key === 'pause' ? 'Paused' : 'Resumed — next invoice on ' + fmtDate(r.next_run_date)) }).catch(() => {});
      default: return;
    }
  }

  // send by email (quote, invoice, credit note, reminder)
  async function sendDialog(kind) {
    let c;
    try { c = (await api.get('view.php', { action: 'compose', kind, id: V.id })).compose; } catch (e) { toastError(e); return; }
    if (!c.accounts.length) {
      const m = tempModal(`<div class="fwm-dialog__head"><h2>Connect your mailbox first</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
        <div class="fwm-dialog__body"><p class="fwm-dialog__lead">Documents are emailed from your own address, through Flowwork Mail, with the PDF attached — so replies come back to you and everything is filed in your Sent folder.</p>
          <div class="fwq-vw-choices">
            <a class="fwq-vw-choice" href="/mail/">${icon('mail')}<span><b>Set up Mail</b><small>Add your mailbox (with its SMTP server), then come back.</small></span></a>
            ${T === 'quote' && V.raw_status === 'draft' ? `<button class="fwq-vw-choice" type="button" data-pick="mark_sent">${icon('check')}<span><b>Mark as sent</b><small>You will send the PDF yourself.</small></span></button>` : ''}
            ${T === 'invoice' && V.raw_status === 'draft' ? `<button class="fwq-vw-choice" type="button" data-pick="issue">${icon('check')}<span><b>Issue without emailing</b><small>You will send the PDF yourself.</small></span></button>` : ''}
            ${T !== 'credit_note' ? `<button class="fwq-vw-choice" type="button" data-pick="link">${icon('link')}<span><b>Copy the customer link</b><small>Paste it into WhatsApp or SMS.</small></span></button>` : ''}
            <button class="fwq-vw-choice" type="button" data-pick="pdf">${icon('download')}<span><b>Download the PDF</b></span></button>
          </div></div>`, '', 'width:min(520px,100%)');
      m.addEventListener('click', (e) => { const p = e.target.closest('[data-pick]'); if (p) { closeModal(m); run(p.dataset.pick); } });
      openModal(m);
      return;
    }
    const sugg = (c.suggestions || []).filter((x) => !c.to.includes(x.email));
    const title = kind === 'reminder' ? 'Send a reminder' : 'Email ' + NOUN + ' ' + c.number;
    await modal(title, `
      ${c.accounts.length > 1 ? field('From', `<select class="select" name="account_id">${c.accounts.map((a) => `<option value="${a.id}">${esc(a.name)} &lt;${esc(a.email)}&gt;</option>`).join('')}</select>`) : `<div class="fwq-vw-from">${icon('mail', 'i--sm')}From <b>${esc(c.accounts[0].email)}</b><input type="hidden" name="account_id" value="${c.accounts[0].id}"></div>`}
      ${field('To', `<input class="input" name="to" value="${esc(c.to.join(', '))}" placeholder="customer@example.com" autocomplete="off">`, sugg.length ? `Add: ${sugg.map((x) => `<button type="button" class="fwq-link" data-add="${esc(x.email)}">${esc(x.name || x.email)}</button>`).join(' · ')}` : 'Separate several addresses with commas.')}
      <div class="fwq-vw-cc" hidden>${field('Cc', '<input class="input" name="cc" autocomplete="off">')}</div>
      <button type="button" class="fwq-link fwq-vw-cctoggle">+ Cc</button>
      ${field('Subject', `<input class="input" name="subject" maxlength="255" value="${esc(c.subject)}">`)}
      ${field('Message', `<textarea class="textarea" name="body" rows="10" maxlength="10000">${esc(c.body)}</textarea>`)}
      <label class="fwq-vw-attach"><input type="checkbox" name="attach" checked>${icon('clip')}<span>${esc(c.filename)}</span></label>`,
      kind === 'reminder' ? 'Send reminder' : (T === 'invoice' && V.raw_status === 'draft' ? 'Issue & send' : 'Send'),
      { wide: true, icon: 'send',
        init: (f) => {
          f.addEventListener('click', (e) => {
            const a = e.target.closest('[data-add]');
            if (a) { const cur = f.elements.to.value.split(/[,;]+/).map((x) => x.trim()).filter(Boolean); if (!cur.includes(a.dataset.add)) cur.push(a.dataset.add); f.elements.to.value = cur.join(', '); a.remove(); }
            if (e.target.closest('.fwq-vw-cctoggle')) { $('.fwq-vw-cc', f).hidden = false; e.target.closest('.fwq-vw-cctoggle').remove(); f.elements.cc.focus(); }
          });
        },
        submit: async (f) => {
          const r = await act(kind === 'reminder' ? 'remind' : 'send', {
            to: val(f, 'to'), cc: val(f, 'cc'), subject: val(f, 'subject'), body: val(f, 'body'), account_id: Number(val(f, 'account_id')) || 0, attach: f.elements.attach.checked,
          }, { fail: (e) => { throw e; } });
          toast((kind === 'reminder' ? 'Reminder sent to ' : 'Sent to ') + (r.emailed || []).join(', '));
        } });
  }

  function paymentDialog() {
    const s = V.summary;
    const key = uuid();
    const foreign = V.currency !== 'ZAR';
    const methods = [['eft', 'EFT / bank transfer'], ['cash', 'Cash'], ['card', 'Card (machine)'], ['cheque', 'Cheque'], ['yoco', 'Yoco'], ['other', 'Other']];
    return modal('Record a payment', `
      <div class="row">${field('Amount received', `<input class="input fwq-vw-amount" name="amount" inputmode="decimal" value="${esc(Number(s.balance).toFixed(2))}" autofocus>`, `${esc(m$(s.balance))} is owing`)}
        ${field('Date received', `<input class="input" type="date" name="date" value="${esc(V.today)}" max="${esc(V.today)}">`)}</div>
      <div class="row">${field('How', `<select class="select" name="method">${methods.map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join('')}</select>`)}
        ${field('Reference <small>(optional)</small>', '<input class="input" name="reference" maxlength="190" placeholder="e.g. bank statement reference">')}</div>
      ${foreign ? field('Rate on the payment date <small>(1 ' + esc(V.currency) + ' in rand)</small>', `<input class="input" name="exchange_rate" inputmode="decimal" value="${esc(String(s.exchange_rate || ''))}">`, 'A different rate from the invoice books the difference as a realised exchange gain or loss.') : ''}
      ${field('Note <small>(optional)</small>', '<input class="input" name="notes" maxlength="2000">')}
      ${(V.milestones || []).length ? `<p class="hint" style="margin-top:12px">${icon('coins', 'i--sm')} The payment fills the schedule in order, oldest first.</p>` : ''}`,
      'Record payment', { icon: 'iq-pay', lead: V.raw_status === 'draft' ? 'This draft is issued first (posted to the ledger), then the payment is recorded.' : '',
        submit: async (f) => {
          const r = await act('payment', { amount: val(f, 'amount'), date: val(f, 'date'), method: val(f, 'method'), reference: val(f, 'reference'), notes: val(f, 'notes'),
            exchange_rate: foreign ? val(f, 'exchange_rate') : null, idempotency_key: key }, { fail: (e) => { throw e; } });
          toast(r.status === 'paid' ? 'Paid in full — thank you!' : 'Payment recorded — ' + m$(r.balance) + ' still owing');
        } });
  }

  function refundDialog() {
    const paid = V.summary.paid;
    return modal('Refund', `${field('Amount refunded', `<input class="input" name="amount" inputmode="decimal" value="${esc(Number(paid).toFixed(2))}">`, esc(m$(paid)) + ' was paid')}
      ${field('Reference <small>(optional)</small>', '<input class="input" name="reference" maxlength="190">')}
      ${field('Reason', '<input class="input" name="reason" maxlength="1000">')}`, 'Record refund',
      { icon: 'iq-refund', lead: 'Money paid back to the customer. The invoice owes that amount again — to cancel the sale and its VAT as well, also issue a credit note.',
        submit: async (f) => { await act('refund', { amount: val(f, 'amount'), reference: val(f, 'reference'), reason: val(f, 'reason') }, { fail: (e) => { throw e; } }); toast('Refund recorded'); } });
  }

  function applyDialog() {
    const list = V.apply_to || [];
    if (!list.length) { toast('This customer has no open invoices in ' + V.currency + ' to apply the credit to.'); return; }
    const own = (V.related || []).find((r) => r.type === 'invoice');
    return modal('Apply credit', `<div class="fwm-results">${list.map((i, n) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="invoice_id" value="${i.id}" ${(own ? own.id === i.id : n === 0) ? 'checked' : ''} style="accent-color:var(--accent)">
        <span class="fwm-result__text"><b>${esc(i.number)}</b><small>${esc(fmtDate(i.date))} · ${esc(money(i.balance, V.symbol))} owing</small></span></label>`).join('')}</div>`,
      'Apply ' + m$(V.summary.unapplied), { icon: 'iq-credit', lead: 'Up to ' + esc(m$(V.summary.unapplied)) + ' is taken off the invoice you choose.',
        submit: async (f) => {
          const pick = f.querySelector('input[name=invoice_id]:checked');
          const r = await act('apply', { invoice_id: pick ? Number(pick.value) : 0 }, { fail: (e) => { throw e; } });
          toast(m$(r.applied) + ' applied' + (r.unapplied > 0.004 ? ' — ' + m$(r.unapplied) + ' left to apply' : ''));
        } });
  }

  async function linkDialog(kind) {
    try {
      const r = await act(kind, {}, { reload: false });
      const url = r.url;
      let copied = false;
      try { await navigator.clipboard.writeText(url); copied = true; } catch (e) { copied = false; }
      const m = tempModal(`<div class="fwm-dialog__head"><h2>${kind === 'pay_link' ? 'Card payment link' : 'Customer link'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
        <div class="fwm-dialog__body"><p class="fwm-dialog__lead">${kind === 'pay_link' ? 'Opens the invoice and takes the customer to Yoco to pay the balance by card.' : 'The customer sees the ' + esc(NOUN) + ' online' + (T === 'quote' ? ' and can accept or decline it' : '') + ', and can download the PDF. No login needed.'}</p>
          <div class="fwq-vw-linkbox"><input class="input" readonly value="${esc(url)}"><button class="btn btn--primary" type="button" data-copy>${icon('copy')}${copied ? 'Copied' : 'Copy'}</button></div>
          <div class="fwq-vw-share"><a class="btn btn--sm" href="https://wa.me/?text=${encodeURIComponent(url)}" target="_blank" rel="noopener">${icon('whatsapp')}WhatsApp</a>
          <a class="btn btn--sm" href="${esc(url)}" target="_blank" rel="noopener">${icon('external')}Open</a></div></div>`, '', 'width:min(560px,100%)');
      $('[data-copy]', m).addEventListener('click', async (e) => { const inp = $('input', m); inp.select(); try { await navigator.clipboard.writeText(url); } catch (x) { document.execCommand('copy'); } e.currentTarget.innerHTML = icon('check') + 'Copied'; });
      openModal(m);
    } catch (e) { /* toast */ }
  }

  // ------------------------------------------------------------ go
  QI.initChrome();
  render();
  try {
    const f = sessionStorage.getItem('qi-flash');
    if (f) { sessionStorage.removeItem('qi-flash'); toast(f); }
  } catch (e) { /* private mode */ }
  const q = new URLSearchParams(location.search);
  if (q.get('do') && V.actions.some((a) => a.key === q.get('do'))) setTimeout(() => run(q.get('do')), 200);
})();
