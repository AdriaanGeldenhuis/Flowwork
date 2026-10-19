// /crm/js/account.js — one customer or supplier: the header with quick actions,
// key numbers and health, the tabs (activity, contacts, deals, documents,
// compliance, details — in the company's order) and the rail (about, people,
// next steps, upcoming meetings). Data: the page payload, then /crm/api/account.php.
(function () {
  'use strict';
  const C = window.CRM;
  const { $, $$, esc, icon, api, toast, toastError, popover, dialog, BOOT, L, lower, money, money0, compact, fmtDate, fmtTime, relTime, relDay, avatar, statusPill, tagChip } = C;
  const F = C.forms;
  let A = BOOT.account;
  const host = $('#acct');
  const S = { tab: null, kind: 'all', items: [], pinned: [], next: null, more: false, docs: null, deals: null, comp: null, tasks: null, meetings: null };
  const isCust = () => A.type === 'customer';
  const can = BOOT.can_write;

  const tabs = () => {
    const off = BOOT.config.account_tabs_off || [];
    const mods = BOOT.config.modules;
    return BOOT.config.account_tabs.filter((t) => !off.includes(t) && !(t === 'deals' && !mods.pipeline) && !(t === 'compliance' && !mods.compliance));
  };
  const TAB_LABEL = { activity: 'Activity', contacts: () => L('contacts'), deals: () => L('deals'), documents: 'Documents', compliance: 'Compliance', details: 'Details' };
  const tabLabel = (t) => (typeof TAB_LABEL[t] === 'function' ? TAB_LABEL[t]() : TAB_LABEL[t]);
  const primaryPhone = () => A.phone || ((A.contact_list.find((c) => c.primary) || A.contact_list[0] || {}).mobile) || ((A.contact_list[0] || {}).phone) || '';
  const mobile = () => { const c = A.contact_list.find((x) => x.primary && x.mobile) || A.contact_list.find((x) => x.mobile); return c ? c.mobile : ''; };

  // ------------------------------------------------------------- render
  function render() {
    document.title = A.name + ' · ' + BOOT.company;
    const ts = tabs();
    if (!S.tab || !ts.includes(S.tab)) S.tab = ts[0] || 'details';
    host.innerHTML = `${hero()}${stats()}
      <div class="fwc-acct__body">
        <div class="fwc-acct__main">
          <div class="fwc-tabs" role="tablist">${ts.map((t) => `<button type="button" role="tab" data-tab="${t}" class="${S.tab === t ? 'is-on' : ''}" aria-selected="${S.tab === t}">${esc(tabLabel(t))} <small data-tabcount="${t}">${tabCount(t)}</small></button>`).join('')}</div>
          <div id="panel"></div>
        </div>
        <aside class="fwc-acct__rail" id="rail">${rail()}</aside>
      </div>`;
    bindHero();
    $$('[data-tab]', host).forEach((b) => b.addEventListener('click', () => showTab(b.dataset.tab)));
    showTab(S.tab, true);
    loadRail();
  }
  function tabCount(t) {
    if (t === 'contacts') return A.contact_list.length || '';
    if (t === 'deals') return A.open_deals.count || '';
    if (t === 'compliance') return A.compliance_state.missing.length + A.compliance_state.expired.length || '';
    return '';
  }

  function hero() {
    const deleted = !!A.deleted_at;
    const ph = primaryPhone();
    const mob = mobile();
    return `<header class="fwc-hero">
      ${avatar(A.name, 'fwc-ava--xl', A.preferred)}
      <div class="fwc-hero__main">
        <a class="fwc-back" href="/crm/#${isCust() ? 'customers' : 'suppliers'}">${icon('chev-left', 'i--xs')} ${esc(L(isCust() ? 'customers' : 'suppliers'))}</a>
        <h1>${esc(A.name)}${can && !deleted ? `<button class="icon-btn" type="button" data-act="edit" aria-label="Edit" data-tip="Edit details">${icon('pen', 'i--sm')}</button>` : ''}</h1>
        ${A.legal_name && A.legal_name !== A.name ? `<div class="fwc-hero__legal">${esc(A.legal_name)}</div>` : ''}
        <div class="fwc-hero__meta">
          <span class="fwc-pill fwc-pill--plain" data-tone="gold">${icon(isCust() ? 'ic-customer' : 'ic-supplier', 'i--xs')} ${esc(L(A.type))}</span>
          ${statusPill(A.status)}
          ${deleted ? `<span class="fwc-pill" data-tone="danger">Deleted ${esc(relTime(A.deleted_at))}</span>` : ''}
          ${A.owner ? `<span class="fwc-pill fwc-pill--plain" data-tone="muted">${C.personAva(A.owner.id, A.owner.name)} ${esc(A.owner.name)}</span>` : ''}
          ${!isCust() && A.compliance_state.blocking ? `<span class="fwc-pill" data-tone="danger">${icon('lock', 'i--xs')} Blocked by compliance</span>` : ''}
          <span class="fwc-tags" id="heroTags">${A.tags.map((t) => tagChip(t, can && BOOT.rules.tags)).join('')}${can && BOOT.rules.tags && !deleted ? `<button class="fwc-mini" type="button" data-act="tag">${icon('tag')}Tag</button>` : ''}</span>
        </div>
      </div>
      <div class="fwc-hero__acts">
        ${deleted ? (can ? `<button class="btn btn--primary" type="button" data-act="restore">${icon('undo')}Restore</button>` : '') : `
        ${can ? `<button class="btn btn--primary" type="button" data-act="log">${icon('message-plus')}<span>Log activity</span></button>` : ''}
        ${can ? `<button class="btn" type="button" data-act="email">${icon('mail')}<span class="hide-narrow">Email</span></button>` : ''}
        ${ph ? `<a class="btn" href="${C.telHref(ph)}" aria-label="Call ${esc(C.fmtPhone(ph))}">${icon('phone')}<span class="hide-narrow">Call</span></a>` : ''}
        ${mob ? `<a class="icon-btn" href="${C.waHref(mob)}" target="_blank" rel="noopener" aria-label="WhatsApp" data-tip="WhatsApp">${icon('whatsapp')}</a>` : ''}
        ${can ? `<button class="btn" type="button" data-act="new">${icon('plus')}<span class="hide-narrow">New</span>${icon('chev-down', 'i--xs')}</button>` : ''}
        <button class="icon-btn" type="button" data-act="more" aria-label="More">${icon('more-v')}</button>`}
      </div></header>`;
  }

  function stats() {
    const h = A.health;
    const m = A.money;
    const last = A.last_activity;
    const days = C.ago(last);
    const series = (A.series || []).map((x) => x.billed);
    const cards = [];
    cards.push(`<div class="fwc-stat fwc-stat--health" title="${esc(h.why.join(' · ') || 'Not enough history yet')}"><span class="fwc-ring" style="--p:${h.score};--c:${C.tone(h.tone)}"><b>${h.score}</b></span>
      <span style="min-width:0"><span class="fwc-stat__label">Health</span><div style="font-weight:600;font-size:14px">${esc(h.label)}</div><div class="fwc-stat__note">${esc(h.why[0] || 'Not enough history yet')}</div></span></div>`);
    cards.push(`<a class="fwc-stat" href="#documents" data-goto="documents" style="text-decoration:none;color:inherit"><span class="fwc-stat__label">${isCust() ? 'Owes you' : 'You owe'}</span><span class="fwc-stat__value">${m.balance > 0.004 ? `<small>R</small> ${esc(C.group(m.balance))}` : '—'}</span>
      <span class="fwc-stat__note${m.overdue > 0.004 ? ' is-late' : ''}">${m.overdue > 0.004 ? `${esc(money0(m.overdue))} overdue (${m.overdue_count})` : (m.balance > 0.004 ? 'Nothing overdue' : 'All settled')}</span></a>`);
    cards.push(`<div class="fwc-stat"><span class="fwc-stat__label">${isCust() ? 'Billed' : 'Spend'} · 12 months</span><span class="fwc-stat__value">${m.billed12 > 0.004 ? `<small>R</small> ${esc(compact(m.billed12, ''))}` : '—'}</span>
      <span class="fwc-stat__note">${A.lifetime.count ? `${esc(money0(A.lifetime.total))} lifetime · ${A.lifetime.count} ${isCust() ? (A.lifetime.count === 1 ? 'invoice' : 'invoices') : (A.lifetime.count === 1 ? 'bill' : 'bills')}` : 'Nothing yet'}</span>${C.sparkline(series, 'var(--gold)', 30)}</div>`);
    if (BOOT.config.modules.pipeline && (isCust() || A.open_deals.count)) cards.push(`<a class="fwc-stat" href="#deals" data-goto="deals" style="text-decoration:none;color:inherit"><span class="fwc-stat__label">Open ${esc(lower('deals'))}</span><span class="fwc-stat__value">${A.open_deals.count ? `<small>R</small> ${esc(compact(A.open_deals.value, ''))}` : '—'}</span><span class="fwc-stat__note">${A.open_deals.count ? A.open_deals.count + ' open' : 'None open'}</span></a>`);
    cards.push(`<div class="fwc-stat"><span class="fwc-stat__label">Last contact</span><span class="fwc-stat__value">${last ? esc(relTime(last)) : 'Never'}</span><span class="fwc-stat__note${days !== null && days > BOOT.config.cold_days ? ' is-late' : ''}">${days !== null && days > BOOT.config.cold_days ? 'Going cold — time to reach out' : (last ? fmtDate(last.slice(0, 10), 'always') : 'Log your first call or note')}</span></div>`);
    if (!isCust() && BOOT.config.modules.compliance) {
      const c = A.compliance_state;
      const map = { none: 'None required', valid: 'In order', expiring: 'Expiring soon', expired: 'Expired', missing: 'Missing' };
      cards.push(`<a class="fwc-stat" href="#compliance" data-goto="compliance" style="text-decoration:none;color:inherit"><span class="fwc-stat__label">Compliance</span><span class="fwc-stat__value" style="color:${c.status === 'valid' ? 'var(--success)' : c.status === 'none' ? 'inherit' : c.status === 'expiring' ? 'var(--warning)' : 'var(--danger)'}">${esc(map[c.status])}</span><span class="fwc-stat__note">${esc([...c.missing, ...c.expired].join(', ') || (c.docs ? c.docs + ' on file' : 'No documents yet'))}</span></a>`);
      const k = A.supplier_kpis || {};
      if (k.on_time !== null && k.on_time !== undefined) cards.push(`<div class="fwc-stat"><span class="fwc-stat__label">Delivered on time</span><span class="fwc-stat__value">${Math.round(k.on_time)}%</span><span class="fwc-stat__note">${k.defect_rate !== null ? Math.round(k.defect_rate * 10) / 10 + '% defects' : ''}</span></div>`);
    }
    return `<div class="fwc-stats">${cards.join('')}</div>`;
  }

  function rail() {
    const ph = A.phone;
    const addr = A.addresses[0];
    return `<section class="fwc-box"><div class="fwc-box__head"><h3>About</h3>${can && !A.deleted_at ? `<button class="icon-btn icon-btn--sm" type="button" data-act="edit" aria-label="Edit">${icon('pen', 'i--sm')}</button>` : ''}</div>
        <dl class="fwc-dl">
          <dt>Email</dt><dd class="${A.email ? '' : 'is-empty'}">${A.email ? `<a href="mailto:${esc(A.email)}">${esc(A.email)}</a>` : '—'}</dd>
          <dt>Phone</dt><dd class="${ph ? '' : 'is-empty'}">${ph ? `<a href="${C.telHref(ph)}">${esc(C.fmtPhone(ph))}</a>` : '—'}</dd>
          ${A.website ? `<dt>Website</dt><dd><a href="${esc(A.website)}" target="_blank" rel="noopener">${esc(A.website.replace(/^https?:\/\//, '').replace(/\/$/, ''))}</a></dd>` : ''}
          <dt>VAT</dt><dd class="${A.vat_no ? '' : 'is-empty'}">${esc(A.vat_no || '—')}</dd>
          ${A.reg_no ? `<dt>Reg. no.</dt><dd>${esc(A.reg_no)}</dd>` : ''}
          ${A.industry ? `<dt>Industry</dt><dd>${esc(A.industry)}</dd>` : ''}
          ${A.region ? `<dt>Region</dt><dd>${esc(A.region)}</dd>` : ''}
          ${A.currency && A.currency !== 'ZAR' ? `<dt>Currency</dt><dd>${esc(A.currency)}</dd>` : ''}
          ${addr ? `<dt>${esc(addr.type_label)}</dt><dd><a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([addr.line1, addr.line2, addr.city, addr.postal_code].filter(Boolean).join(', '))}" target="_blank" rel="noopener">${esc([addr.line1, addr.city].filter(Boolean).join(', '))}</a></dd>` : ''}
          ${customRail()}
        </dl></section>
      <section class="fwc-box"><div class="fwc-box__head"><h3>${esc(L('contacts'))}</h3>${can && !A.deleted_at ? `<button class="icon-btn icon-btn--sm" type="button" data-act="contact" aria-label="Add a contact" data-tip="Add">${icon('plus', 'i--sm')}</button>` : ''}</div>
        ${A.contact_list.length ? A.contact_list.slice(0, 4).map((c) => `<div class="fwc-person">${avatar(c.name, 'fwc-ava--sm fwc-ava--round')}<span class="fwc-person__text"><b>${esc(c.name)}${c.primary ? ` <span title="Main contact" style="color:var(--gold)">${icon('star', 'i--xs')}</span>` : ''}</b><small>${esc(c.role || c.email || c.mobile || c.phone || '')}</small></span>
          <span class="fwc-person__acts">${c.mobile || c.phone ? `<a class="icon-btn icon-btn--sm" href="${C.telHref(c.mobile || c.phone)}" aria-label="Call ${esc(c.name)}">${icon('phone', 'i--sm')}</a>` : ''}${c.email && can ? `<button class="icon-btn icon-btn--sm" type="button" data-mail="${c.id}" aria-label="Email ${esc(c.name)}">${icon('mail', 'i--sm')}</button>` : ''}</span></div>`).join('')
          + (A.contact_list.length > 4 ? `<button class="fwc-link" type="button" data-goto="contacts" style="font-size:12.5px;margin-top:6px">All ${A.contact_list.length} people</button>` : '')
          : `<p class="fwc-muted" style="margin:0;font-size:12.5px">No people yet.</p>`}</section>
      ${BOOT.config.modules.tasks ? `<section class="fwc-box" id="tasks"><div class="fwc-box__head"><h3>Next steps</h3>${can && !A.deleted_at ? `<button class="icon-btn icon-btn--sm" type="button" data-act="task" aria-label="Add a task" data-tip="Add">${icon('plus', 'i--sm')}</button>` : ''}</div><div id="railTasks"><div class="fwc-skel" style="height:44px"></div></div></section>` : ''}
      <section class="fwc-box"><div class="fwc-box__head"><h3>Meetings</h3><a class="icon-btn icon-btn--sm" href="/calendar/?new=1&link=crm_account:${A.id}&title=${encodeURIComponent('Meeting: ' + A.name)}" aria-label="Schedule a meeting" data-tip="Schedule">${icon('calendar-plus', 'i--sm')}</a></div><div id="railMeetings" class="fwc-meetings"><div class="fwc-skel" style="height:36px"></div></div></section>`;
  }
  function customRail() {
    const defs = F.fieldsFor('account', A.type);
    return defs.filter((f) => { const v = A.custom_values[f.id]; return v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && !v.length) && !(f.type === 'checkbox' && !v); })
      .slice(0, 8).map((f) => `<dt>${esc(f.label)}</dt><dd>${F.displayCustom(f, A.custom_values[f.id])}</dd>`).join('');
  }
  async function loadRail() {
    if (BOOT.config.modules.tasks) {
      try {
        const d = await api.get('account.php', { id: A.id, part: 'tasks', when: 'open' });
        S.tasks = d.rows;
        const box = $('#railTasks');
        if (box) {
          box.innerHTML = d.rows.length ? d.rows.slice(0, 6).map(F.taskRow).join('') : `<p class="fwc-muted" style="margin:0;font-size:12.5px">Nothing planned. ${can ? '<button class="fwc-link" type="button" data-act="task">Add a follow-up</button>' : ''}</p>`;
          bindTasks(box);
        }
      } catch (e) { /* the rail is a nicety */ }
    }
    try {
      const d = await api.get('account.php', { id: A.id, part: 'meetings' });
      const box = $('#railMeetings');
      if (box) box.innerHTML = d.events.length ? d.events.map((ev) => { const dt = C.parseDt(ev.start_datetime); return `<a href="${esc(ev.url)}"><span class="fwc-cal"><i>${esc(dt ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][dt.getMonth()] : '')}</i><b>${dt ? dt.getDate() : ''}</b></span><span class="fwc-rows__text"><b>${esc(ev.title)}</b><small>${ev.all_day ? 'All day' : esc(fmtTime(ev.start_datetime))} · ${esc(relDay(ev.start_datetime.slice(0, 10)))}</small></span></a>`; }).join('')
        : `<p class="fwc-muted" style="margin:0;font-size:12.5px">No meetings planned.</p>`;
    } catch (e) { const box = $('#railMeetings'); if (box) box.innerHTML = ''; }
  }
  function bindTasks(box) {
    box.addEventListener('click', async (e) => {
      if (e.target.closest('[data-stop]')) return;
      const tick = e.target.closest('[data-tick]');
      const row = e.target.closest('[data-task]');
      const rows = S.tasks || [];
      if (tick && can) {
        e.stopPropagation();
        const t = rows.find((x) => x.id === +tick.dataset.tick);
        tick.closest('.fwc-task').classList.add('is-done');
        try { await F.toggleTask(t, !t.done); } catch (er) { toastError(er); }
        loadRail();
        if (S.tab === 'activity') loadTimeline(true);
        return;
      }
      if (row && can) F.task({ task: rows.find((x) => x.id === +row.dataset.task), onSaved: loadRail });
    });
  }

  // -------------------------------------------------------- hero actions
  function bindHero() {
    host.addEventListener('click', heroClick);
  }
  async function heroClick(e) {
    const goto = e.target.closest('[data-goto]');
    if (goto) { e.preventDefault(); showTab(goto.dataset.goto); $('.fwc-tabs', host).scrollIntoView({ behavior: 'smooth', block: 'start' }); return; }
    const untag = e.target.closest('[data-untag]');
    if (untag) {
      try { await api.post('accounts.php', { op: 'tag_remove', id: A.id, tag_id: +untag.dataset.untag }); A.tags = A.tags.filter((t) => t.id !== +untag.dataset.untag); refreshHero(); } catch (er) { toastError(er); }
      return;
    }
    const mail = e.target.closest('[data-mail]');
    if (mail) { F.email({ account: A, contact_id: +mail.dataset.mail, onSent: afterLog }); return; }
    const b = e.target.closest('[data-act]');
    if (!b) return;
    const act = b.dataset.act;
    if (act === 'edit') F.account({ account: A, onSaved: reloadAccount });
    else if (act === 'log') { showTab('activity'); setTimeout(() => { const ta = $('#composer textarea'); if (ta) { ta.focus(); ta.scrollIntoView({ block: 'center', behavior: 'smooth' }); } }, 60); }
    else if (act === 'email') F.email({ account: A, onSent: afterLog });
    else if (act === 'task') F.task({ account: { id: A.id, name: A.name }, onSaved: loadRail });
    else if (act === 'contact') F.contact({ account_id: A.id, onSaved: reloadAccount });
    else if (act === 'tag') tagPicker(b);
    else if (act === 'restore') { try { await api.post('accounts.php', { op: 'restore', ids: [A.id] }); toast('Restored'); reloadAccount(); } catch (er) { toastError(er); } }
    else if (act === 'new') newMenu(b);
    else if (act === 'more') moreMenu(b);
  }
  function newMenu(b) {
    const items = [
      BOOT.config.modules.tasks ? `<button class="fwm-pop__item" type="button" data-v="task">${icon('i-check-square')}${esc(L('task'))}</button>` : '',
      BOOT.config.modules.pipeline ? `<button class="fwm-pop__item" type="button" data-v="deal">${icon('i-kanban')}${esc(L('deal'))}</button>` : '',
      `<button class="fwm-pop__item" type="button" data-v="contact">${icon('user')}${esc(L('contact'))}</button>`,
      `<a class="fwm-pop__item" data-v="meet" href="/calendar/?new=1&link=crm_account:${A.id}&title=${encodeURIComponent('Meeting: ' + A.name)}">${icon('calendar-plus')}Meeting</a>`,
      '<div class="fwm-pop__sep"></div>',
      isCust() ? `<a class="fwm-pop__item" data-v="q" href="/qi/edit.php?type=quote&customer_id=${A.id}">${icon('iq-quote')}Quote</a><a class="fwm-pop__item" data-v="i" href="/qi/edit.php?type=invoice&customer_id=${A.id}">${icon('iq-invoice')}Invoice</a>`
        : `<a class="fwm-pop__item" data-v="po" href="/procurement/po_new.php?supplier_id=${A.id}">${icon('ic-cart')}Purchase order</a>`,
      !isCust() ? `<button class="fwm-pop__item" type="button" data-v="rfq">${icon('mail')}Request a quote</button>` : '',
      BOOT.config.modules.compliance ? `<button class="fwm-pop__item" type="button" data-v="doc">${icon('shield')}Compliance document</button>` : '',
    ].join('');
    popover(b, items, { align: 'end', onClick: (it) => {
      const v = it.dataset.v;
      if (v === 'task') F.task({ account: { id: A.id, name: A.name }, onSaved: loadRail });
      if (v === 'deal') F.deal({ account: { id: A.id, name: A.name }, onSaved: () => { reloadAccount(); if (S.tab === 'deals') loadDeals(); } });
      if (v === 'contact') F.contact({ account_id: A.id, onSaved: reloadAccount });
      if (v === 'rfq') F.email({ account: A, onSent: afterLog });
      if (v === 'doc') compTypes().then((types) => F.complianceDoc({ account_id: A.id, types, onSaved: () => { S.comp = null; reloadAccount(); showTab('compliance'); } }));
    } });
  }
  function moreMenu(b) {
    const deleted = !!A.deleted_at;
    popover(b, `
      ${A.portal_url ? `<button class="fwm-pop__item" type="button" data-v="portal">${icon('ic-portal')}Copy ${isCust() ? 'client' : 'supplier'} portal link</button>` : ''}
      <button class="fwm-pop__item" type="button" data-v="copy">${icon('copy')}Copy details</button>
      ${can ? `<button class="fwm-pop__item" type="button" data-v="pref">${icon('star')}${A.preferred ? 'Not preferred' : 'Mark preferred'}</button>` : ''}
      ${BOOT.is_admin ? `<a class="fwm-pop__item" data-v="merge" href="/crm/dedupe.php?account=${A.id}">${icon('ic-merge')}Merge with another account</a>` : ''}
      ${can && !deleted ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" type="button" data-v="delete">${icon('trash')}Delete</button>` : ''}`, {
      align: 'end',
      onClick: async (it) => {
        const v = it.dataset.v;
        try {
          if (v === 'portal') { await navigator.clipboard.writeText(location.origin + A.portal_url); toast('Portal link copied — anyone with it can open the portal, so share it only with ' + A.name); }
          if (v === 'copy') {
            const lines = [A.legal_name || A.name, A.vat_no ? 'VAT ' + A.vat_no : '', A.reg_no ? 'Reg ' + A.reg_no : '', ...A.addresses.slice(0, 1).map((x) => [x.line1, x.line2, x.city, x.postal_code].filter(Boolean).join(', ')), A.email, A.phone].filter(Boolean);
            await navigator.clipboard.writeText(lines.join('\n'));
            toast('Details copied');
          }
          if (v === 'pref') { await api.post('accounts.php', { op: 'preferred', id: A.id, on: !A.preferred }); reloadAccount(); }
          if (v === 'delete') {
            if (!(await dialog({ title: 'Delete ' + A.name + '?', body: 'It moves to Deleted and can be restored. Quotes, invoices, bills and history are kept.', confirm: 'Delete', danger: true }))) return;
            await api.post('accounts.php', { op: 'delete', ids: [A.id] });
            toast(A.name + ' deleted');
            location.href = '/crm/#' + (isCust() ? 'customers' : 'suppliers');
          }
        } catch (er) { toastError(er); }
      },
    });
  }
  function tagPicker(b) {
    const have = A.tags.map((t) => t.id);
    popover(b, `<input class="fwm-pop__input" placeholder="Find or create a tag" maxlength="64"><div id="tagList">${BOOT.tags.filter((t) => !have.includes(t.id)).map((t) => `<button class="fwm-pop__item" type="button" data-v="${esc(t.name)}">${tagChip(t)}<small>${t.count}</small></button>`).join('')}</div>`, {
      width: 240,
      onClick: (it) => { addTag(it.dataset.v); },
    });
    const inp = $('.fwm-pop .fwm-pop__input');
    const list = $('#tagList');
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      const hits = BOOT.tags.filter((t) => !have.includes(t.id) && t.name.toLowerCase().includes(q));
      list.innerHTML = hits.map((t) => `<button class="fwm-pop__item" type="button" data-v="${esc(t.name)}">${tagChip(t)}<small>${t.count}</small></button>`).join('')
        + (q && !BOOT.tags.some((t) => t.name.toLowerCase() === q) ? `<button class="fwm-pop__item" type="button" data-v="${esc(inp.value.trim())}">${icon('plus')}Create “${esc(inp.value.trim())}”</button>` : '');
    });
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter' && inp.value.trim()) { e.preventDefault(); C.closePop(); addTag(inp.value.trim()); } });
  }
  async function addTag(name) {
    try {
      const d = await api.post('accounts.php', { op: 'tag_add', id: A.id, name });
      if (!A.tags.some((t) => t.id === d.tag.id)) A.tags.push(d.tag);
      if (!BOOT.tags.some((t) => t.id === d.tag.id)) BOOT.tags.push({ ...d.tag, count: 1 });
      refreshHero();
    } catch (e) { toastError(e); }
  }
  function refreshHero() {
    const h = $('.fwc-hero', host);
    if (h) h.outerHTML = hero();
  }
  async function reloadAccount() {
    try {
      const d = await api.get('account.php', { id: A.id });
      A = d.account;
      S.docs = null;
      S.comp = null;
      const keep = S.tab;
      host.removeEventListener('click', heroClick);
      S.tab = keep;
      render();
    } catch (e) { toastError(e); }
  }
  function afterLog() { if (S.tab === 'activity') loadTimeline(true); }

  // ---------------------------------------------------------------- tabs
  function showTab(t, initial = false) {
    S.tab = t;
    $$('[data-tab]', host).forEach((b) => { b.classList.toggle('is-on', b.dataset.tab === t); b.setAttribute('aria-selected', b.dataset.tab === t); });
    if (!initial) history.replaceState(null, '', '#' + t);
    const panel = $('#panel');
    panel.innerHTML = '';
    ({ activity: tabActivity, contacts: tabContacts, deals: tabDeals, documents: tabDocuments, compliance: tabCompliance, details: tabDetails })[t](panel);
  }

  // activity
  function tabActivity(panel) {
    const kinds = [['all', 'Everything'], ['notes', 'Notes'], ['calls', 'Calls'], ['emails', 'Emails'], ['meetings', 'Meetings'], ...(BOOT.config.modules.pipeline ? [['deals', L('deals')]] : []), ['documents', 'Documents'], ['tasks', 'Done tasks']];
    panel.innerHTML = `${can && !A.deleted_at ? '<div id="composer"></div>' : ''}
      <div class="fwc-rtabs">${kinds.map(([k, l]) => `<button class="fwc-chip${S.kind === k ? ' is-on' : ''}" type="button" data-kind="${k}">${esc(l)}</button>`).join('')}</div>
      <div id="pinned"></div><div class="fwc-tl" id="timeline"><div class="fwc-skel" style="height:70px;margin:8px 0 8px 46px"></div><div class="fwc-skel" style="height:70px;margin:8px 0 8px 46px"></div></div><div id="tlMore"></div>`;
    const comp = $('#composer', panel);
    if (comp) F.composer(comp, { account: { id: A.id, name: A.name }, contacts: A.contact_list, deals: (S.deals || []).filter((x) => x.kind === 'open'), onSaved: () => { loadTimeline(true); loadRail(); reloadStats(); } });
    if (!S.deals && BOOT.config.modules.pipeline && comp) {
      api.get('account.php', { id: A.id, part: 'deals' }).then((d) => { S.deals = d.deals; S.templates = d.project_templates; if (S.tab === 'activity' && !$('#composer textarea').value) F.composer($('#composer'), { account: { id: A.id, name: A.name }, contacts: A.contact_list, deals: d.deals.filter((x) => x.kind === 'open'), onSaved: () => { loadTimeline(true); loadRail(); reloadStats(); } }); }).catch(() => {});
    }
    $$('[data-kind]', panel).forEach((b) => b.addEventListener('click', () => { S.kind = b.dataset.kind; $$('[data-kind]', panel).forEach((x) => x.classList.toggle('is-on', x === b)); loadTimeline(true); }));
    F.bindActivity(panel, () => loadTimeline(true));
    loadTimeline(true);
    if (location.hash === '#log' && comp) setTimeout(() => { const ta = $('textarea', comp); if (ta) ta.focus(); }, 80);
  }
  async function reloadStats() {
    try {
      const d = await api.get('account.php', { id: A.id });
      A = d.account;
      const st = $('.fwc-stats', host);
      if (st) st.outerHTML = stats();
    } catch (e) { /* not essential */ }
  }
  async function loadTimeline(reset) {
    if (reset) { S.items = []; S.next = null; }
    let d;
    try { d = await api.get('account.php', { id: A.id, part: 'timeline', kind: S.kind, before: reset ? '' : S.next, limit: 30 }); } catch (e) { const tl = $('#timeline'); if (tl) tl.innerHTML = C.errorBox(e); return; }
    if (S.tab !== 'activity') return;
    if (reset) S.pinned = d.pinned;
    S.items = S.items.concat(d.items);
    S.next = d.next;
    S.more = d.more;
    const pinned = $('#pinned');
    if (pinned) pinned.innerHTML = S.pinned.length && S.kind === 'all' ? `<div class="fwc-tl fwc-pinned">${S.pinned.map((a) => F.activityItem(a, { showAccount: false })).join('')}</div>` : '';
    const tl = $('#timeline');
    if (!tl) return;
    if (!S.items.length) { tl.innerHTML = C.emptyState('ic-activity', 'Nothing here yet', can ? 'Log a call, a meeting or a note above. Emails linked in Mail, deals and documents show up here by themselves.' : 'No activity yet.', '', true); $('#tlMore').innerHTML = ''; return; }
    let lastDay = '';
    tl.innerHTML = S.items.map((it) => {
      const day = String(it.at).slice(0, 10);
      const sep = day !== lastDay ? `<div class="fwc-day" style="margin-left:46px">${esc(day === BOOT.today ? 'Today' : C.daysFrom(day) === -1 ? 'Yesterday' : fmtDate(day, 'always'))}</div>` : '';
      lastDay = day;
      return sep + timelineItem(it);
    }).join('');
    $('#tlMore').innerHTML = S.more ? `<button class="btn btn--block" type="button" id="moreBtn" style="margin-top:10px">Show older</button>` : '';
    const mb = $('#moreBtn');
    if (mb) mb.addEventListener('click', () => loadTimeline(false));
  }
  function timelineItem(it) {
    const at = `<time title="${esc(it.at)}">${esc(relTime(it.at))}</time>`;
    if (it.kind === 'interaction') return F.activityItem(it, { showAccount: false });
    if (it.kind === 'email') {
      return `<div class="fwc-item"><span class="fwc-type" data-t="email">${icon(it.outgoing ? 'send' : 'mail')}</span><div class="fwc-item__card">
        <div class="fwc-item__head"><b>${it.mine ? esc(it.subject) : (it.outgoing ? 'Email sent' : 'Email received')}</b><span>${it.outgoing ? 'from ' + esc(it.mailbox) : 'from ' + esc(it.who)}</span>${at}</div>
        ${it.mine && it.snippet ? `<div class="fwc-item__body is-clamped">${esc(it.snippet)}</div>` : !it.mine ? `<div class="fwc-item__body fwc-muted" style="font-size:12.5px">In ${esc(it.mailbox)}’s mailbox — only they can read it.</div>` : ''}
        ${it.url ? `<div class="fwc-item__foot"><a class="fwc-mini" href="${esc(it.url)}">${icon('external')}Open in Mail</a></div>` : ''}</div></div>`;
    }
    if (it.kind === 'task') return `<div class="fwc-item"><span class="fwc-type" data-t="task">${icon('check')}</span><div class="fwc-item__card is-plain"><div class="fwc-item__head"><b>${esc(it.title)}</b><span>done by ${esc(it.by || 'someone')}</span>${at}</div></div></div>`;
    if (it.kind === 'deal') {
      const label = it.event === 'opened' ? `New ${esc(lower('deal'))}` : it.event === 'won' ? 'Won' : 'Lost';
      return `<div class="fwc-item"><span class="fwc-type" data-t="${it.event === 'opened' ? 'deal' : it.event}">${icon(it.event === 'won' ? 'ic-trophy' : it.event === 'lost' ? 'ic-lost' : 'i-kanban')}</span><div class="fwc-item__card is-plain">
        <div class="fwc-item__head"><b>${label}: <button class="fwc-link" type="button" data-open-deal="${it.id}">${esc(it.title)}</button></b><span>${esc(money0(it.amount))}${it.reason ? ' · ' + esc(it.reason) : ''} · ${esc(it.by)}</span>${at}</div></div></div>`;
    }
    if (it.kind === 'doc') {
      const names = { quote: 'Quote', invoice: 'Invoice', po: 'Purchase order', compliance: 'Compliance document' };
      const verbs = { created: 'created', accepted: 'accepted by the customer', paid: 'paid in full', uploaded: 'uploaded' };
      const ic = { quote: 'iq-quote', invoice: 'iq-invoice', po: 'ic-cart', compliance: 'shield' }[it.doc];
      return `<div class="fwc-item"><span class="fwc-type" data-t="${it.event === 'paid' || it.event === 'accepted' ? 'won' : 'doc'}">${icon(ic)}</span><div class="fwc-item__card is-plain">
        <div class="fwc-item__head"><b>${esc(names[it.doc])} ${it.url ? `<a href="${esc(it.url)}">${esc(it.number)}</a>` : esc(it.number)}</b><span>${esc(verbs[it.event] || it.event)}${it.amount !== null ? ' · ' + esc(money(it.amount, it.currency === 'ZAR' ? 'R' : it.currency)) : ''}</span>${at}</div></div></div>`;
    }
    if (it.kind === 'created') return `<div class="fwc-item"><span class="fwc-type">${icon('ic-handshake')}</span><div class="fwc-item__card is-plain"><div class="fwc-item__head"><b>Added to the CRM</b><span>${it.by ? 'by ' + esc(it.by) : ''}</span>${at}</div></div></div>`;
    return '';
  }

  // contacts
  function tabContacts(panel) {
    const list = A.contact_list;
    const defs = F.fieldsFor('contact');
    panel.innerHTML = `${can && !A.deleted_at ? `<div style="display:flex;justify-content:flex-end;margin-bottom:10px"><button class="btn btn--sm" type="button" data-add-contact>${icon('plus')}Add a ${esc(lower('contact'))}</button></div>` : ''}
      ${list.length ? `<div class="fwc-contacts">${list.map((c) => {
        const ph = c.mobile || c.phone;
        return `<article class="fwc-contact"><div class="fwc-contact__top">${avatar(c.name, 'fwc-ava--round')}<div style="min-width:0"><div class="fwc-contact__name"><span class="fwc-ellip">${esc(c.name)}</span>${c.primary ? `<span class="fwc-star" title="Main contact">${icon('star')}</span>` : ''}</div><div class="fwc-contact__role">${esc(c.role || '')}</div></div></div>
          <div class="fwc-contact__lines">${c.email ? `<a href="mailto:${esc(c.email)}">${icon('mail')}${esc(c.email)}</a>` : ''}${c.phone ? `<a href="${C.telHref(c.phone)}">${icon('phone')}${esc(C.fmtPhone(c.phone))}</a>` : ''}${c.mobile ? `<a href="${C.telHref(c.mobile)}">${icon('phone')}${esc(C.fmtPhone(c.mobile))} <span class="fwc-muted">mobile</span></a>` : ''}
            ${defs.filter((f) => { const v = (c.custom || {})[f.id]; return v !== '' && v != null && !(Array.isArray(v) && !v.length) && !(f.type === 'checkbox' && !v); }).map((f) => `<span class="fwc-muted" style="font-size:12px">${esc(f.label)}: <span style="color:var(--ink-2)">${F.displayCustom(f, c.custom[f.id])}</span></span>`).join('')}
            ${c.notes ? `<span class="fwc-muted" style="font-size:12px;white-space:pre-wrap">${esc(c.notes)}</span>` : ''}</div>
          <div class="fwc-contact__acts">${ph ? `<a class="fwc-mini" href="${C.waHref(ph)}" target="_blank" rel="noopener">${icon('whatsapp')}WhatsApp</a>` : ''}${c.email && can ? `<button class="fwc-mini" type="button" data-mail="${c.id}">${icon('mail')}Email</button>` : ''}
            ${can && !c.primary ? `<button class="fwc-mini" type="button" data-primary="${c.id}">${icon('star')}Make main</button>` : ''}${can ? `<button class="fwc-mini" type="button" data-edit-contact="${c.id}">${icon('pen')}Edit</button>` : ''}</div></article>`;
      }).join('')}</div>`
      : C.emptyState('user', 'No people yet', `Add the people you deal with at ${esc(A.name)} — who signs, who pays, who is on site.`, can ? `<button class="btn btn--primary" type="button" data-add-contact>${icon('plus')}Add a ${esc(lower('contact'))}</button>` : '')}`;
    panel.onclick = async (e) => {
      const add = e.target.closest('[data-add-contact]');
      const ed = e.target.closest('[data-edit-contact]');
      const pr = e.target.closest('[data-primary]');
      if (add) F.contact({ account_id: A.id, onSaved: reloadAccount });
      if (ed) F.contact({ account_id: A.id, contact: list.find((c) => c.id === +ed.dataset.editContact), onSaved: reloadAccount });
      if (pr) { try { await api.post('contacts.php', { op: 'primary', id: +pr.dataset.primary }); toast('Main contact changed'); reloadAccount(); } catch (er) { toastError(er); } }
    };
  }

  // deals
  async function tabDeals(panel) {
    panel.innerHTML = `<div class="fwc-skel" style="height:120px"></div>`;
    await loadDeals();
  }
  async function loadDeals() {
    const panel = $('#panel');
    let d;
    try { d = await api.get('account.php', { id: A.id, part: 'deals' }); } catch (e) { panel.innerHTML = C.errorBox(e); return; }
    S.deals = d.deals;
    S.templates = d.project_templates;
    if (S.tab !== 'deals') return;
    const open = d.deals.filter((x) => x.kind === 'open');
    const closed = d.deals.filter((x) => x.kind !== 'open');
    const card = (x) => {
      const late = x.kind === 'open' && x.close_date && C.daysFrom(x.close_date) < 0;
      return `<a href="${C.dealUrl(x.id)}" data-deal="${x.id}"><span class="fwc-docs__num">${esc(x.title)}<small>${x.kind === 'open' ? `${Math.round(x.probability)}% · ` : ''}${x.close_date ? (x.kind === 'open' ? 'closes ' : '') + esc(fmtDate(x.close_date, 'always')) : 'no close date'}${x.loss_reason ? ' · ' + esc(x.loss_reason) : ''}</small></span>
        ${C.stagePill(x.stage)}<span class="fwc-docs__amt">${esc(money0(x.amount))}${late ? '<small class="is-late">past close date</small>' : `<small>${esc(x.owner)}</small>`}</span></a>`;
    };
    panel.innerHTML = `${can && !A.deleted_at ? `<div style="display:flex;justify-content:flex-end;margin-bottom:10px"><button class="btn btn--sm" type="button" data-add-deal>${icon('plus')}New ${esc(lower('deal'))}</button></div>` : ''}
      ${d.deals.length ? `${open.length ? `<div class="fwc-docgroup"><h4>Open <b>${esc(money0(open.reduce((a, x) => a + x.amount, 0)))}</b></h4><div class="fwc-docs">${open.map(card).join('')}</div></div>` : ''}
        ${closed.length ? `<div class="fwc-docgroup"><h4>Closed <b>${esc(money0(closed.filter((x) => x.kind === 'won').reduce((a, x) => a + x.amount, 0)))} won</b></h4><div class="fwc-docs">${closed.map(card).join('')}</div></div>` : ''}`
        : C.emptyState('i-kanban', `No ${lower('deals')} yet`, `Track opportunities with ${esc(A.name)} from first conversation to signed order.`, can ? `<button class="btn btn--primary" type="button" data-add-deal>${icon('plus')}New ${esc(lower('deal'))}</button>` : '')}`;
    panel.onclick = (e) => {
      const add = e.target.closest('[data-add-deal]');
      if (add) { F.deal({ account: { id: A.id, name: A.name }, onSaved: () => { loadDeals(); reloadStats(); } }); return; }
      const dl = e.target.closest('[data-deal]');
      if (dl && !e.ctrlKey && !e.metaKey) { e.preventDefault(); F.sheet(+dl.dataset.deal, { onChange: () => { loadDeals(); reloadStats(); } }); }
    };
  }

  // documents
  async function tabDocuments(panel) {
    panel.innerHTML = `<div class="fwc-skel" style="height:160px"></div>`;
    if (!S.docs) {
      try { S.docs = (await api.get('account.php', { id: A.id, part: 'documents' })).documents; } catch (e) { panel.innerHTML = C.errorBox(e); return; }
    }
    if (S.tab !== 'documents') return;
    const D = S.docs;
    const group = (title, rows, kind, total, newHref) => rows.length ? `<div class="fwc-docgroup"><h4>${esc(title)} <b>${total !== null ? esc(total) : ''}</b></h4><div class="fwc-docs">${rows.map((x) => `
      <a href="${esc(x.url)}"><span class="fwc-docs__num">${esc(x.number || x.name)}${x.name && x.number ? `<small>${esc(x.name)}</small>` : `<small>${esc(x.date ? fmtDate(x.date, 'always') : '')}${x.due ? ' · ' + (kind === 'quote' ? 'valid until ' : kind === 'project' ? 'due ' : 'due ') + esc(fmtDate(x.due, 'always')) : ''}${x.frequency ? ' · ' + esc(x.frequency) : ''}</small>`}</span>
        ${C.pill(x.status)}<span class="fwc-docs__amt">${x.total !== undefined ? esc(money(x.total, x.symbol)) : ''}${x.balance > 0.004 ? `<small class="${x.status.key === 'overdue' ? 'is-late' : ''}">${esc(money(x.balance, x.symbol))} due</small>` : (x.progress !== undefined ? `<small>${x.progress}% done</small>` : '')}</span></a>`).join('')}</div></div>` : '';
    const sum = (rows, k = 'balance') => rows.reduce((a, x) => a + (x[k] || 0), 0);
    let html = '';
    if (isCust()) {
      html += group('Invoices', D.invoices, 'invoice', sum(D.invoices) > 0.004 ? money0(sum(D.invoices)) + ' outstanding' : null);
      html += group('Quotes', D.quotes, 'quote', null);
      html += group('Recurring invoices', D.recurring, 'recurring', null);
      html += group('Credit notes', D.credit_notes, 'credit', null);
    } else {
      html += group('Supplier bills', D.bills, 'bill', sum(D.bills) > 0.004 ? money0(sum(D.bills)) + ' to pay' : null);
      html += group('Purchase orders', D.purchase_orders, 'po', null);
      if (D.invoices.length) html += group('Invoices', D.invoices, 'invoice', null);
    }
    html += group('Projects', D.projects, 'project', null);
    const actions = isCust()
      ? `<a class="btn btn--sm" href="/qi/edit.php?type=quote&customer_id=${A.id}">${icon('iq-quote')}New quote</a><a class="btn btn--sm" href="/qi/edit.php?type=invoice&customer_id=${A.id}">${icon('iq-invoice')}New invoice</a>`
      : `<a class="btn btn--sm" href="/procurement/po_new.php?supplier_id=${A.id}">${icon('ic-cart')}New purchase order</a>`;
    panel.innerHTML = `${can && !A.deleted_at ? `<div style="display:flex;justify-content:flex-end;gap:6px;margin-bottom:10px">${actions}</div>` : ''}${html || C.emptyState(isCust() ? 'iq-invoice' : 'ic-cart', 'No documents yet', isCust() ? 'Quotes, invoices, credit notes and projects for this customer appear here.' : 'Bills, purchase orders and projects for this supplier appear here.', '', true)}`;
  }

  // compliance
  async function compTypes() {
    if (S.comp) return S.comp.types;
    S.comp = await api.get('account.php', { id: A.id, part: 'compliance' });
    return S.comp.types;
  }
  async function tabCompliance(panel) {
    panel.innerHTML = `<div class="fwc-skel" style="height:140px"></div>`;
    try { S.comp = await api.get('account.php', { id: A.id, part: 'compliance' }); } catch (e) { panel.innerHTML = C.errorBox(e); return; }
    if (S.tab !== 'compliance') return;
    const c = S.comp;
    const st = c.state;
    const banner = st.blocking ? `<div class="fwc-banner fwc-banner--warn">${icon('lock')}<span>New purchases from ${esc(A.name)} are blocked until the missing or expired documents are on file.</span></div>`
      : st.status === 'valid' ? `<div class="fwc-banner fwc-banner--won">${icon('shield')}<span>Everything required is on file and valid.</span></div>` : '';
    const missing = !isCust() ? c.types.filter((t) => t.required && !c.docs.some((d) => d.type_id === t.id)) : [];
    const card = (d) => {
      const pct = d.expiry_date && d.issue_date ? Math.max(0, Math.min(100, 100 - (C.daysFrom(d.expiry_date) / Math.max(1, (C.parseYmd(d.expiry_date) - C.parseYmd(d.issue_date)) / 86400000)) * 100)) : (d.status === 'expired' ? 100 : 30);
      const color = d.status === 'expired' ? 'var(--danger)' : d.status === 'expiring' ? 'var(--warning)' : 'var(--success)';
      return `<article class="fwc-compdoc"><div class="fwc-compdoc__type">${icon('shield', 'i--sm')}<span class="fwc-ellip">${esc(d.type)}</span><span class="fwc-grow"></span>${C.pill({ label: d.status === 'expired' ? 'Expired' : d.status === 'expiring' ? 'Expiring' : 'Valid', tone: d.status === 'expired' ? 'danger' : d.status === 'expiring' ? 'warning' : 'success' })}</div>
        <div class="fwc-compdoc__exp">${d.reference ? esc(d.reference) + ' · ' : ''}${d.expiry_date ? (d.days < 0 ? 'expired ' : 'expires ') + esc(fmtDate(d.expiry_date, 'always')) + ` (${esc(relDay(d.expiry_date))})` : 'Does not expire'}</div>
        ${d.expiry_date ? `<div class="fwc-compdoc__bar"><i style="--p:${pct}%;--c:${color}"></i></div>` : ''}
        ${d.notes ? `<div class="fwc-muted" style="font-size:12px;white-space:pre-wrap">${esc(d.notes)}</div>` : ''}
        <div class="fwc-contact__acts">${d.has_file ? `<a class="fwc-mini" href="/crm/api/compliance.php?op=download&id=${d.id}&inline=1" target="_blank" rel="noopener">${icon('eye')}View</a><a class="fwc-mini" href="/crm/api/compliance.php?op=download&id=${d.id}">${icon('download')}Download</a>` : '<span class="fwc-muted" style="font-size:12px">No file attached</span>'}
          ${can ? `<button class="fwc-mini" type="button" data-edit-doc="${d.id}">${icon('pen')}Update</button><button class="fwc-mini" type="button" data-del-doc="${d.id}">${icon('trash')}</button>` : ''}</div></article>`;
    };
    panel.innerHTML = `${banner}${can ? `<div style="display:flex;justify-content:flex-end;margin-bottom:10px"><button class="btn btn--sm" type="button" data-add-doc>${icon('upload')}Add a document</button></div>` : ''}
      ${c.docs.length || missing.length ? `<div class="fwc-compdocs">${missing.map((t) => `<article class="fwc-compdoc is-missing"><div class="fwc-compdoc__type">${icon('alert', 'i--sm')}${esc(t.name)}</div><div class="fwc-compdoc__exp" style="color:var(--danger)">Required — not on file</div>${can ? `<div class="fwc-contact__acts"><button class="fwc-mini" type="button" data-add-doc="${t.id}">${icon('upload')}Upload</button></div>` : ''}</article>`).join('')}${c.docs.map(card).join('')}</div>`
        : C.emptyState('shield', 'No documents on file', c.types.length ? 'Upload tax clearance, B-BBEE certificates, insurance and the like. Flowwork reminds you before they expire.' : 'An administrator first sets up the document types in Make it yours › Compliance.', '', true)}`;
    panel.onclick = async (e) => {
      const add = e.target.closest('[data-add-doc]');
      const ed = e.target.closest('[data-edit-doc]');
      const del = e.target.closest('[data-del-doc]');
      const done = () => { reloadAccount().then(() => showTab('compliance')); };
      if (add) F.complianceDoc({ account_id: A.id, types: c.types, type_id: +add.dataset.addDoc || 0, onSaved: done });
      if (ed) F.complianceDoc({ account_id: A.id, types: c.types, doc: c.docs.find((d) => d.id === +ed.dataset.editDoc), onSaved: done });
      if (del) {
        if (!(await dialog({ title: 'Delete this document?', body: 'The file is removed too.', confirm: 'Delete', danger: true }))) return;
        try { await api.post('compliance.php', { op: 'delete', id: +del.dataset.delDoc }); toast('Document deleted'); done(); } catch (er) { toastError(er); }
      }
    };
  }

  // details
  function tabDetails(panel) {
    const defs = F.fieldsFor('account', A.type);
    const row = (label, val) => `<dt>${esc(label)}</dt><dd class="${val ? '' : 'is-empty'}">${val || '—'}</dd>`;
    panel.innerHTML = `<div style="display:grid;gap:12px">
      <section class="fwc-box"><div class="fwc-box__head"><h3>Company</h3>${can && !A.deleted_at ? `<button class="btn btn--sm" type="button" data-act="edit">${icon('pen')}Edit</button>` : ''}</div>
        <dl class="fwc-dl">${row('Name', esc(A.name))}${row('Legal name', esc(A.legal_name))}${row('Type', esc(L(A.type)))}${row('Status', statusPill(A.status))}${row('Owner', A.owner ? esc(A.owner.name) : '')}
          ${row('Email', A.email ? `<a href="mailto:${esc(A.email)}">${esc(A.email)}</a>` : '')}${row('Phone', A.phone ? `<a href="${C.telHref(A.phone)}">${esc(C.fmtPhone(A.phone))}</a>` : '')}${row('Website', A.website ? `<a href="${esc(A.website)}" target="_blank" rel="noopener">${esc(A.website)}</a>` : '')}
          ${row('VAT number', esc(A.vat_no))}${row('Registration no.', esc(A.reg_no))}${row('Industry', esc(A.industry))}${row('Region', esc(A.region))}${row('Billing currency', esc(A.currency || 'Company default'))}
          ${row('Preferred', A.preferred ? 'Yes' : 'No')}</dl></section>
      ${defs.length ? `<section class="fwc-box"><div class="fwc-box__head"><h3>More details</h3></div><dl class="fwc-dl">${defs.map((f) => row(f.label, F.displayCustom(f, A.custom_values[f.id]))).join('')}</dl></section>` : ''}
      <section class="fwc-box"><div class="fwc-box__head"><h3>Addresses</h3>${can && !A.deleted_at ? `<button class="btn btn--sm" type="button" data-add-addr>${icon('plus')}Add</button>` : ''}</div>
        ${A.addresses.length ? A.addresses.map((x) => `<div class="fwc-addr"><div class="fwc-addr__type">${icon('ic-visit', 'i--xs')}${esc(x.type_label)}</div>${esc([x.line1, x.line2].filter(Boolean).join(', '))}<br>${esc([x.city, x.region, x.postal_code].filter(Boolean).join(', '))}${x.country && x.country !== 'ZA' ? ' · ' + esc(x.country) : ''}
          <span class="fwc-addr__acts"><a class="icon-btn icon-btn--sm" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent([x.line1, x.line2, x.city, x.postal_code, x.country].filter(Boolean).join(', '))}" target="_blank" rel="noopener" aria-label="Map">${icon('external', 'i--sm')}</a>${can ? `<button class="icon-btn icon-btn--sm" type="button" data-edit-addr="${x.id}" aria-label="Edit">${icon('pen', 'i--sm')}</button>` : ''}</span></div>`).join('')
          : '<p class="fwc-muted" style="margin:0;font-size:12.5px">No address yet.</p>'}</section>
      <section class="fwc-box"><div class="fwc-box__head"><h3>Notes</h3></div><div style="white-space:pre-wrap;line-height:1.6;font-size:13px;color:${A.notes ? 'var(--ink-2)' : 'var(--ink-4)'}">${esc(A.notes || 'No notes yet.')}</div></section>
      <section class="fwc-box"><div class="fwc-box__head"><h3>Record</h3></div><dl class="fwc-dl">${row('Added', esc(fmtDate(String(A.created_at).slice(0, 10), 'always')) + (A.created_by_name ? ' by ' + esc(A.created_by_name) : ''))}${row('Last changed', A.updated_at ? esc(relTime(A.updated_at)) : '')}${A.deleted_at ? row('Deleted', esc(relTime(A.deleted_at))) : ''}</dl>
        ${A.deleted_at && BOOT.is_admin ? `<button class="btn btn--sm btn--danger" type="button" data-purge style="margin-top:12px">${icon('trash')}Delete for good</button>` : ''}</section></div>`;
    panel.onclick = async (e) => {
      const add = e.target.closest('[data-add-addr]');
      const ed = e.target.closest('[data-edit-addr]');
      if (add) F.address({ account_id: A.id, onSaved: reloadAccount });
      if (ed) F.address({ account_id: A.id, address: A.addresses.find((x) => x.id === +ed.dataset.editAddr), onSaved: reloadAccount });
      if (e.target.closest('[data-purge]')) {
        if (!(await dialog({ title: 'Delete ' + A.name + ' for good?', body: 'Contacts, activity, tasks, deals and compliance documents are removed. This cannot be undone.', confirm: 'Delete for good', danger: true }))) return;
        try { await api.post('accounts.php', { op: 'purge', id: A.id }); location.href = '/crm/#' + (isCust() ? 'customers' : 'suppliers'); } catch (er) { toastError(er); }
      }
    };
  }

  // ---------------------------------------------------------------- start
  C.initChrome();
  const h = location.hash.replace('#', '');
  if (h === 'log') S.tab = 'activity';
  else if (h === 'tasks') S.tab = null;
  else if (tabs().includes(h)) S.tab = h;
  render();
  if (h === 'tasks') setTimeout(() => { const t = $('#tasks'); if (t) t.scrollIntoView({ behavior: 'smooth' }); }, 200);
  if (new URLSearchParams(location.search).get('edit') === '1' && can) F.account({ account: A, onSaved: reloadAccount });
  document.addEventListener('crm:tasks', loadRail);
})();
