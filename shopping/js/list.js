// /shopping/js/list.js — one list.
//   - items by aisle (or as added), the trolley at the bottom; add by typing or
//     pasting (read as you type; the same thing again adds up)
//   - tick off: at once on the screen, then sent; with no signal the ticks wait
//     on the phone (localStorage) and go when it is back — sending one twice is
//     harmless; other people's ticks appear as they happen (the change stamp is
//     polled: every 4 s while shopping, 15 s otherwise)
//   - in the shop: big rows, the trolley total against the budget, "what did it
//     cost?" as you tick, the screen kept awake, finishing with what was left
//   - edit an item, where to buy it, share a link, a purchase order, the list's
//     details, duplicate, template, CSV, print, delete
(function () {
  'use strict';
  const F = window.FWS;
  const { $, $$, esc, icon, api, toast, toastError, busy, money, toCents, rand, ring, ago, due, isLate, fmtDay, debounce, unitText, catOf, modal, openModal, closeModal, dialog, popover, closePop, boot } = F;
  const app = $('#listApp');
  if (!app) return;

  const S = {
    list: null, items: [], session: null, shares: [], activity: [], version: '',
    group: localStorage.getItem('fws-group') || boot.prefs.group_by || 'aisle',
    asking: null, // the item whose price is being asked
    flash: new Set(),
  };
  const CATS = Object.keys(boot.categories || {});
  const LIST_ID = +app.dataset.id;
  const can = () => ({ edit: !!(S.list && S.list.edit), manage: !!(S.list && S.list.manage), order: !!boot.caps.order });
  const shopping = () => !!S.session;

  function apply(d) {
    S.list = d.list; S.items = d.items; S.session = d.session; S.shares = d.shares || []; S.activity = d.activity || []; S.version = d.version;
    overlayOutbox();
    renderAll();
  }

  // ======================================================================= outbox
  // Ticks and prices wait here until the server has them.
  const OB_KEY = 'fws-outbox';
  const readOb = () => { try { return JSON.parse(localStorage.getItem(OB_KEY) || '[]').filter((x) => x && x.list && x.id); } catch (e) { return []; } };
  const writeOb = (q) => { try { localStorage.setItem(OB_KEY, JSON.stringify(q.slice(-500))); } catch (e) { /* private mode: sent straight away only */ } };
  let flushing = false;
  let offline = false;
  function queue(job) {
    const q = readOb().filter((x) => !(x.list === job.list && x.id === job.id && x.op === job.op));
    q.push({ ...job, t: Date.now() });
    writeOb(q);
    flush();
  }
  function overlayOutbox() {
    const q = readOb().filter((x) => x.list === LIST_ID);
    S.items.forEach((i) => { i._sync = false; });
    q.forEach((j) => {
      const it = S.items.find((i) => i.id === j.id);
      if (!it) return;
      it._sync = true;
      if (j.op === 'toggle') { it.bought = !!j.bought; if (!j.bought) it.paid = null; }
      if (j.op === 'price') it.paid = j.paid;
    });
  }
  async function flush() {
    if (flushing) return;
    flushing = true;
    try {
      for (;;) {
        const q = readOb();
        if (!q.length) break;
        const job = q[0];
        try {
          const body = job.op === 'toggle' ? { op: 'toggle', id: job.id, bought: job.bought } : { op: 'price', id: job.id, paid: job.paid == null ? '' : job.paid / 100 };
          const r = await api.post('items.php', body);
          writeOb(readOb().filter((x) => !(x.list === job.list && x.id === job.id && x.op === job.op && x.t === job.t)));
          setOffline(false);
          if (job.list === LIST_ID && r.item) {
            const k = S.items.findIndex((i) => i.id === r.item.id);
            if (k >= 0) S.items[k] = r.item;
            S.version = r.version;
            if (r.counts) Object.assign(S.list, { items: r.counts.items, bought: r.counts.bought, left: r.counts.left, paid: r.counts.paid, est_left: r.counts.est_left, est_total: r.counts.paid + r.counts.est_left });
            overlayOutbox();
            if (!S.asking) renderAll();
            else {
              // Keep the open price box (and its focus): only this row's "not sent yet" goes.
              const it = S.items.find((i) => i.id === job.id);
              const rowEl = $('#item-' + job.id);
              if (rowEl && it && !it._sync) { rowEl.classList.remove('is-sync'); const s = $('.fws-item__sync', rowEl); if (s) s.remove(); }
              renderTrolley();
              renderSide();
            }
          }
        } catch (e) {
          if (e.status === 0) { setOffline(true); break; }
          if (e.status === 429) break;
          // The server said no (removed meanwhile, no longer allowed …): forget this one.
          writeOb(readOb().filter((x) => !(x.list === job.list && x.id === job.id && x.op === job.op && x.t === job.t)));
          if (job.list === LIST_ID) { toastError(e); refresh(true); }
        }
      }
    } finally {
      flushing = false;
    }
  }
  function setOffline(on) {
    offline = on;
    const el = $('#offline');
    const n = readOb().length;
    el.hidden = !on && !n;
    el.innerHTML = on
      ? `${icon('is-offline')}<span><b>No signal.</b> ${n} ${n === 1 ? 'change is' : 'changes are'} kept on this phone and sent when you are back online.</span>`
      : (n ? `${icon('is-sync')}<span>Sending ${n} ${n === 1 ? 'change' : 'changes'}…</span>` : '');
    el.classList.toggle('is-off', on);
  }
  window.addEventListener('online', () => { setOffline(false); flush(); refresh(true); });
  window.addEventListener('offline', () => setOffline(true));

  // ======================================================================= sync
  let pollTimer = null;
  let lastPoll = 0;
  async function refresh(force = false) {
    try {
      if (!force) {
        const v = await api.get('lists.php', { op: 'version', id: LIST_ID });
        if (v.version === S.version) return;
      }
      if (S.asking || F.modalOpen) { S.version = ''; return; } // try again when the dialog is closed
      const d = await api.get('lists.php', { op: 'get', id: LIST_ID });
      apply(d);
    } catch (e) {
      if (e.status === 404) { toast('This list was deleted.', { type: 'error' }); setTimeout(() => { location.href = '/shopping/'; }, 1500); }
      if (e.status === 0) setOffline(true);
    }
  }
  function schedule() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(async () => {
      if (document.visibilityState === 'visible') { lastPoll = Date.now(); await flush(); await refresh(); }
      schedule();
    }, shopping() ? 4000 : 15000);
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      if (Date.now() - lastPoll > 3000) { flush(); refresh(); }
      wake(shopping());
    }
  });

  // ======================================================================= keep awake
  let lock = null;
  async function wake(on) {
    try {
      if (on && boot.prefs.keep_awake && 'wakeLock' in navigator && document.visibilityState === 'visible') {
        if (!lock) { lock = await navigator.wakeLock.request('screen'); lock.addEventListener('release', () => { lock = null; }); }
      } else if (!on && lock) { await lock.release(); lock = null; }
    } catch (e) { lock = null; }
  }

  // ======================================================================= render
  function renderAll() {
    document.body.classList.toggle('is-shopping', shopping());
    renderHead();
    renderTrolley();
    renderGroups();
    renderSide();
    setOffline(offline);
  }

  function renderHead() {
    const L = S.list;
    document.title = L.name + ' · Shopping';
    $('#listName').textContent = L.name;
    $('#listEyebrow').innerHTML = `${icon(L.purpose === 'procurement' ? 'is-crate' : (L.purpose === 'groceries' ? 'is-basket' : 'is-list'), 'i--xs')}${esc(L.purpose_label)} <i>◆</i> ${L.shared === 'team' ? icon('users', 'i--xs') + 'Team list' : icon('lock', 'i--xs') + 'Only ' + (L.mine ? 'you' : esc(L.owner))}${!L.mine ? ' <i>◆</i> ' + esc(L.owner) + '’s' : ''}`;
    const meta = [];
    if (L.status === 'done') meta.push(`<span class="fws-tag fws-tag--ok">${icon('check', 'i--xs')}Done ${esc(L.done_at ? fmtDay(L.done_at) : '')}</span>`);
    if (L.needed_by) meta.push(`<span class="fws-tag${isLate(L.needed_by) && L.status !== 'done' ? ' fws-tag--danger' : ''}">${icon('calendar', 'i--xs')}Needed ${esc(due(L.needed_by))}</span>`);
    if (L.project) meta.push(`<a class="fws-tag" href="${esc(L.project_url)}">${icon('briefcase', 'i--xs')}${esc(L.project)}</a>`);
    if (L.store) meta.push(`<span class="fws-tag">${icon('is-store', 'i--xs')}${esc(L.store)}</span>`);
    if (L.budget) meta.push(`<span class="fws-tag${L.over_budget ? ' fws-tag--danger' : ''}">${icon('wallet', 'i--xs')}Budget ${esc(money(L.budget, { round: true }))}</span>`);
    if (L.notes) meta.push(`<span class="fws-tag fws-tag--note" title="${esc(L.notes)}">${icon('message', 'i--xs')}${esc(L.notes)}</span>`);
    if (can().edit && !meta.length) meta.push(`<button class="fws-tag fws-tag--add" type="button" data-act="details">${icon('plus', 'i--xs')}Budget, date, project…</button>`);
    $('#listMeta').innerHTML = meta.join('');

    const acts = [];
    if (can().edit) {
      if (shopping()) acts.push(`<button class="btn btn--primary" type="button" data-act="finish">${icon('is-finish')}Finish shopping</button>`);
      else if (L.status === 'done') acts.push(`<button class="btn btn--primary" type="button" data-act="again">${icon('refresh')}Buy it all again</button><button class="btn" type="button" data-act="reopen">Open again</button>`);
      else acts.push(`<button class="btn btn--primary" type="button" data-act="shop"${L.items ? '' : ' disabled'}>${icon('is-trolley')}Start shopping</button>`);
    }
    if (can().manage && !shopping()) acts.push(`<button class="btn" type="button" data-act="share">${icon('share')}Share</button>`);
    if (can().order && L.left > 0 && !shopping()) acts.push(`<button class="btn hide-narrow" type="button" data-act="po">${icon('is-order')}Order</button>`);
    acts.push(`<button class="icon-btn" type="button" data-act="more" aria-label="More for this list" aria-haspopup="menu">${icon('more-h')}</button>`);
    $('#listActions').innerHTML = acts.join('');
  }

  function renderTrolley() {
    const el = $('#trolley');
    if (!shopping()) { el.hidden = true; el.innerHTML = ''; return; }
    const L = S.list;
    const s = S.session;
    const spent = L.paid;
    const toGo = L.est_left;
    const pct = L.budget ? Math.min(100, Math.round(((spent + toGo) / L.budget) * 100)) : 0;
    const spentPct = L.budget ? Math.min(100, Math.round((spent / L.budget) * 100)) : 0;
    el.hidden = false;
    el.innerHTML = `
      ${ring(L.bought, L.items, { cls: 'fws-ring--lg', label: L.bought + '/' + L.items })}
      <div class="fws-trolley__text">
        <b>${L.left ? `${L.left} still to find` : 'Everything is in the trolley'}</b>
        <small>${esc(money(spent))} in the trolley${toGo ? ' · ≈ ' + esc(money(toGo, { round: true })) + ' to go' : ''}${L.budget ? ` · budget ${esc(money(L.budget, { round: true }))}` : ''}</small>
        ${L.budget ? `<span class="fws-budget fws-budget--two${spent + toGo > L.budget ? ' is-over' : ''}"><i style="--p:${pct}%"></i><i class="is-spent" style="--p:${spentPct}%"></i></span>` : ''}
      </div>
      <button class="fws-trolley__store" type="button" data-act="store" aria-haspopup="menu">${icon('is-store', 'i--sm')}<span>${esc(s.store || 'Which store?')}</span>${icon('chev-down', 'i--xs')}</button>
      ${boot.prefs.keep_awake && 'wakeLock' in navigator ? `<span class="fws-trolley__awake" title="The screen stays on while you shop">${icon('is-awake', 'i--sm')}</span>` : ''}`;
  }

  const sortItems = (rows) => rows.slice().sort((a, b) => (S.group === 'aisle' ? ((b.priority === 'high') - (a.priority === 'high')) : 0) || a.order - b.order || a.id - b.id);

  function itemRow(it) {
    const c = catOf(it.category);
    const meta = [];
    if (it.priority === 'high' && !it.bought) meta.push(`<span class="fws-flag fws-flag--danger">${icon('is-flame', 'i--xs')}Urgent</span>`);
    if (it.needed_by && !it.bought) meta.push(`<span class="${isLate(it.needed_by) ? 'fws-late' : ''}">${icon('calendar', 'i--xs')}${esc(due(it.needed_by))}</span>`);
    if (S.group === 'order' && !it.bought) meta.push(`<span style="color:${c.color}">${icon(c.icon, 'i--xs')}${esc(c.label)}</span>`);
    if (it.store && !it.bought) meta.push(`<span>${icon('is-store', 'i--xs')}${esc(it.store)}</span>`);
    if (it.project && it.project !== S.list.project) meta.push(`<span>${icon('briefcase', 'i--xs')}${esc(it.project)}</span>`);
    if (it.po_number) meta.push(`<span class="fws-flag fws-flag--info">${icon('is-order', 'i--xs')}${esc(it.po_number)}</span>`);
    if (it.notes) meta.push(`<span class="fws-item__note">${esc(it.notes)}</span>`);
    if (it.bought) meta.push(`<span>${esc([it.bought_by, it.store].filter(Boolean).join(' · '))}${it.bought_at ? ' · ' + esc(ago(it.bought_at)) : ''}</span>`);
    if (it._sync) meta.push(`<span class="fws-item__sync">${icon('is-sync', 'i--xs')}Not sent yet</span>`);
    const price = it.bought && it.paid != null ? `<b>${esc(money(it.paid))}</b>` : (it.est_line != null ? `<span class="fws-muted">≈ ${esc(money(it.est_line))}</span>` : '');
    const ed = can().edit;
    return `<div class="fws-item${it.bought ? ' is-bought' : ''}${it.priority === 'high' && !it.bought ? ' is-urgent' : ''}${S.flash.has(it.id) ? ' is-new' : ''}${it._sync ? ' is-sync' : ''}" id="item-${it.id}" data-id="${it.id}" tabindex="-1" style="--c:${c.color}">
      <button class="fws-item__check" type="button" data-act="toggle" aria-pressed="${it.bought}" aria-label="${it.bought ? 'Take ' + esc(it.name) + ' out of the trolley' : 'Put ' + esc(it.name) + ' in the trolley'}"${ed ? '' : ' disabled'}>${icon('check')}</button>
      <div class="fws-item__main"${ed ? ' data-act="main"' : ''}>
        <div class="fws-item__name"><span class="fws-item__qty">${esc(unitText(it.qty_text, it.unit))}</span><span class="fws-item__label">${esc(it.name)}</span></div>
        ${meta.length ? `<div class="fws-item__meta">${meta.join('')}</div>` : ''}
      </div>
      <div class="fws-item__price">${price}</div>
      ${ed ? `<div class="fws-item__acts">
        ${it.bought ? `<button class="icon-btn icon-btn--sm" type="button" data-act="price" aria-label="What it cost" data-tip="What it cost">${icon('is-price', 'i--sm')}</button>` : `<button class="icon-btn icon-btn--sm" type="button" data-act="where" aria-label="Where to buy" data-tip="Where to buy">${icon('is-map', 'i--sm')}</button>`}
        <button class="icon-btn icon-btn--sm hide-narrow" type="button" data-act="edit" aria-label="Edit" data-tip="Edit">${icon('pen', 'i--sm')}</button>
        <button class="icon-btn icon-btn--sm" type="button" data-act="remove" aria-label="Remove ${esc(it.name)}" data-tip="Remove">${icon('trash', 'i--sm')}</button>
      </div>` : ''}
      ${S.asking === it.id ? priceAsk(it) : ''}
    </div>`;
  }

  function priceAsk(it) {
    const each = it.qty > 1;
    const est = it.est != null ? (each ? it.est : it.est_line) : null;
    return `<form class="fws-ask" data-ask="${it.id}" novalidate>
      <label for="ask${it.id}">What did it cost?</label>
      <span class="fws-ask__field"><span>R</span><input id="ask${it.id}" inputmode="decimal" autocomplete="off" placeholder="${est != null ? esc(rand(est)) : '0.00'}" value="${it.paid != null ? esc(rand(each ? Math.round(it.paid / it.qty) : it.paid)) : ''}"></span>
      ${each ? `<span class="seg fws-ask__per"><button type="button" class="is-on" data-per="each">each</button><button type="button" data-per="all">for all ${esc(it.qty_text)}</button></span>` : ''}
      <button class="btn btn--primary btn--sm" type="submit">Save</button>
      <button class="btn btn--ghost btn--sm" type="button" data-ask-skip>Skip</button>
    </form>`;
  }

  function renderGroups() {
    const host = $('#groups');
    // The one whose price is being asked stays where it was until it is answered.
    const pend = S.items.filter((i) => !i.bought || i.id === S.asking);
    const done = S.items.filter((i) => i.bought && i.id !== S.asking);
    $$('[data-group]').forEach((b) => { b.classList.toggle('is-on', b.dataset.group === S.group); b.setAttribute('aria-selected', String(b.dataset.group === S.group)); });
    const nBought = S.items.filter((i) => i.bought).length;
    $('#toolbarCount').textContent = S.items.length ? `${S.items.length - nBought} to buy · ${nBought} in the trolley` : '';
    if (!S.items.length) {
      host.innerHTML = `<div class="fws-blank">${icon('is-list')}<h3>Nothing on the list yet</h3><p>${can().edit ? 'Type what you need above — one thing a line, or paste a whole list. “10 bags cement @ R95”, “2x PVC elbow 20mm”, “milk 2L, bread, eggs”.' : 'Nothing has been added yet.'}</p></div>`;
      return;
    }
    let html = '';
    if (!pend.length || !pend.some((i) => !i.bought)) {
      if (pend.length) html += `<section class="fws-group"><div class="fws-group__items">${pend.map(itemRow).join('')}</div></section>`;
      html += `<div class="fws-allin">${icon('is-trolley')}<div><b>Everything is in the trolley</b><small>${shopping() ? 'Finish shopping when you are at the till.' : (S.list.status === 'done' ? 'This list is done.' : 'Nice work.')}</small></div>${shopping() && can().edit ? `<button class="btn btn--primary btn--sm" type="button" data-act="finish">${icon('is-finish', 'i--sm')}Finish</button>` : ''}</div>`;
    } else if (S.group === 'aisle') {
      const by = {};
      pend.forEach((i) => { (by[i.category] = by[i.category] || []).push(i); });
      CATS.filter((k) => by[k]).forEach((k) => {
        const c = catOf(k);
        html += `<section class="fws-group" style="--c:${c.color}"><h3 class="fws-group__head">${icon(c.icon, 'i--sm')}<span>${esc(c.label)}</span><small>${by[k].length}</small></h3>
          <div class="fws-group__items">${sortItems(by[k]).map(itemRow).join('')}</div></section>`;
      });
    } else {
      html += `<section class="fws-group"><div class="fws-group__items">${sortItems(pend).map(itemRow).join('')}</div></section>`;
    }
    if (done.length) {
      const closed = localStorage.getItem('fws-trolley-' + LIST_ID) === '0';
      const paid = done.reduce((s, i) => s + (i.paid || 0), 0);
      html += `<section class="fws-group fws-group--trolley${closed ? ' is-closed' : ''}">
        <button class="fws-group__head" type="button" data-act="trolley" aria-expanded="${!closed}">${icon('is-trolley', 'i--sm')}<span>In the trolley</span><small>${done.length}${paid ? ' · ' + esc(money(paid)) : ''}</small>${icon('chev-down', 'i--xs fws-group__chev')}</button>
        <div class="fws-group__items">${done.slice().sort((a, b) => String(b.bought_at || '').localeCompare(String(a.bought_at || ''))).map(itemRow).join('')}</div></section>`;
    }
    host.innerHTML = html;
    S.flash.clear();
    const ask = S.asking && $(`[data-ask="${S.asking}"] input`, host);
    if (ask) ask.focus({ preventScroll: true });
  }

  function renderSide() {
    const L = S.list;
    const noPrice = S.items.filter((i) => !i.bought && i.est == null).length;
    const left = L.budget ? L.budget - L.est_total : null;
    let html = `<section class="fws-panel fws-summary">
      <div class="fws-summary__top">${ring(L.bought, L.items, { cls: 'fws-ring--xl', label: L.items ? Math.round((L.bought / L.items) * 100) + '%' : '—' })}
        <div><b>${L.left} to buy</b><small>${L.bought} in the trolley${L.urgent ? ` · <span class="fws-late">${L.urgent} urgent</span>` : ''}</small></div></div>
      <dl class="fws-sums">
        <div><dt>Expected</dt><dd class="fws-num">${esc(money(L.est_total))}</dd></div>
        <div><dt>Paid so far</dt><dd class="fws-num">${esc(money(L.paid))}</dd></div>
        ${L.budget ? `<div class="${left < 0 ? 'is-over' : ''}"><dt>${left < 0 ? 'Over budget' : 'Left in the budget'}</dt><dd class="fws-num">${esc(money(Math.abs(left)))}</dd></div>` : ''}
      </dl>
      ${L.budget ? `<span class="fws-budget fws-budget--two${L.over_budget ? ' is-over' : ''}"><i style="--p:${Math.min(100, Math.round((L.est_total / L.budget) * 100))}%"></i><i class="is-spent" style="--p:${Math.min(100, Math.round((L.paid / L.budget) * 100))}%"></i></span>` : ''}
      ${noPrice && L.left ? `<p class="fws-summary__hint">${noPrice} ${noPrice === 1 ? 'thing has' : 'things have'} no price yet — prices come from what you paid last time, or add one when you edit.</p>` : ''}
    </section>`;
    if (S.session) {
      html += `<section class="fws-panel fws-live"><span class="fws-live__dot" aria-hidden="true"></span><div><b>${esc(S.session.by_name || 'Someone')} is shopping</b><small>Since ${esc(ago(S.session.since))}${S.session.store ? ' · ' + esc(S.session.store) : ''}. Ticks show up here as they happen.</small></div></section>`;
    }
    if (can().manage) {
      html += `<section class="fws-panel fws-panel--flush"><div class="fws-panel__head"><span class="fws-panel__icon">${icon('share')}</span><div class="fws-panel__title"><h3>Links</h3><p>For someone without Flowwork — a driver, a runner, family.</p></div>
        <button class="fws-mini" type="button" data-act="share">${icon('plus', 'i--xs')}New</button></div>
        ${S.shares.length ? `<ul class="fws-rows fws-links">${S.shares.map((s) => `<li><div class="fws-rows__item"><span class="fws-rows__tick">${icon('link', 'i--sm')}</span>
          <span class="fws-rows__text"><b>${s.can_edit ? 'Tick off and add' : (s.can_check ? 'Tick off' : 'Look only')}</b><small>Runs out ${esc(fmtDay(s.expires_at))}${s.last_used_at ? ' · opened ' + esc(ago(s.last_used_at)) : ' · not opened yet'}</small></span>
          <button class="icon-btn icon-btn--sm" type="button" data-copy="${esc(s.url)}" aria-label="Copy the link" data-tip="Copy">${icon('copy', 'i--sm')}</button>
          <button class="icon-btn icon-btn--sm" type="button" data-unshare="${s.id}" aria-label="Stop this link" data-tip="Stop it" data-tip-pos="left">${icon('x', 'i--sm')}</button></div></li>`).join('')}</ul>` : '<p class="fws-panel__empty">No links. Make one and send it on WhatsApp — they tick things off on their phone, no sign-in.</p>'}
      </section>`;
    }
    if (S.activity.length) {
      html += `<section class="fws-panel fws-panel--flush"><div class="fws-panel__head"><span class="fws-panel__icon">${icon('history')}</span><div class="fws-panel__title"><h3>What happened</h3></div></div>
        <ol class="fws-activity">${S.activity.slice(0, 12).map((a) => `<li><span class="fwm-avatar" style="--h:${F.hue(a.who)}">${esc(F.initials(a.who))}</span><span><b>${esc(a.who)}</b> ${esc(a.text)}<small data-ago="${esc(a.at)}">${esc(ago(a.at))}</small></span></li>`).join('')}</ol></section>`;
    }
    $('#listSide').innerHTML = html;
  }

  // ======================================================================= ticking
  function setItem(it) {
    const k = S.items.findIndex((i) => i.id === it.id);
    if (k >= 0) S.items[k] = it; else S.items.push(it);
  }
  function recount() {
    const L = S.list;
    L.items = S.items.length;
    L.bought = S.items.filter((i) => i.bought).length;
    L.left = L.items - L.bought;
    L.paid = S.items.reduce((s, i) => s + (i.bought ? i.paid || 0 : 0), 0);
    L.est_left = S.items.reduce((s, i) => s + (!i.bought && i.est_line ? i.est_line : 0), 0);
    L.est_total = L.paid + L.est_left;
    L.urgent = S.items.filter((i) => !i.bought && i.priority === 'high').length;
    L.over_budget = !!L.budget && L.est_total > L.budget;
  }
  function toggle(id) {
    const it = S.items.find((i) => i.id === id);
    if (!it || !can().edit) return;
    it.bought = !it.bought;
    if (!it.bought) {
      it.paid = null; it.bought_at = null; it.bought_by = '';
      if (S.asking === id) S.asking = null;
      writeOb(readOb().filter((x) => !(x.list === LIST_ID && x.id === id && x.op === 'price')));
    }
    else { it.bought_at = new Date().toISOString().slice(0, 19).replace('T', ' '); it.bought_by = 'You'; }
    it._sync = true;
    if (navigator.vibrate) { try { navigator.vibrate(it.bought ? 12 : [6, 40, 6]); } catch (e) { /* no buzz */ } }
    queue({ list: LIST_ID, op: 'toggle', id, bought: it.bought });
    if (it.bought && shopping() && boot.prefs.price_prompt) S.asking = id;
    recount();
    renderGroups();
    renderTrolley();
    renderSide();
    renderHeadCounts();
  }
  const renderHeadCounts = () => { const b = $('[data-act="shop"]'); if (b) b.disabled = !S.list.items; };
  function savePrice(id, cents) {
    const it = S.items.find((i) => i.id === id);
    if (!it) return;
    it.paid = cents;
    it._sync = true;
    queue({ list: LIST_ID, op: 'price', id, paid: cents });
    S.asking = null;
    recount();
    renderGroups();
    renderTrolley();
    renderSide();
  }
  function askPrice(id) {
    S.asking = id;
    renderGroups();
  }

  // ======================================================================= add
  const addText = $('#addText');
  if (addText) {
    const prev = $('#addPreview');
    const go = $('#addGo');
    let parsed = [];
    let seq = 0;
    const grow = () => { addText.style.height = 'auto'; addText.style.height = Math.min(260, addText.scrollHeight) + 'px'; };
    const draw = () => {
      go.disabled = !parsed.length;
      go.textContent = parsed.length > 1 ? `Add ${parsed.length}` : 'Add';
      prev.hidden = !parsed.length || (parsed.length === 1 && !addText.value.includes('\n') && addText.value.length < 3);
      prev.innerHTML = parsed.slice(0, 30).map((i) => {
        const c = catOf(i.category);
        const norm = (t) => String(t).toLowerCase().replace(/[^\p{L}\d]+/gu, ' ').trim();
        const dup = S.items.find((x) => !x.bought && norm(x.name) === norm(i.name) && (x.unit === i.unit || i.unit === 'ea' || x.unit === 'ea'));
        return `<span class="fws-chip" style="--c:${c.color}">${icon(c.icon, 'i--xs')}<b>${esc(unitText(i.qty_text, i.unit))}</b>${esc(i.name)}${i.price_cents ? `<em>${esc(money(i.price_cents))}</em>` : ''}${i.priority === 'high' ? icon('is-flame', 'i--xs fws-chip__hot') : ''}${dup ? '<em class="fws-chip__note">adds up</em>' : ''}</span>`;
      }).join('') + (parsed.length > 30 ? `<span class="fws-chip fws-chip--more">+${parsed.length - 30}</span>` : '');
    };
    const read = debounce(async () => {
      const n = ++seq;
      if (!addText.value.trim()) { parsed = []; draw(); return; }
      try { const r = await api.post('items.php', { op: 'parse', text: addText.value }); if (n === seq) { parsed = r.items; draw(); } } catch (e) { /* again on the next key */ }
    }, 180);
    addText.addEventListener('input', () => { grow(); read(); });
    addText.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); }
      if (e.key === 'Escape') { addText.value = ''; parsed = []; draw(); grow(); addText.blur(); }
    });
    const submit = async () => {
      const t = addText.value.trim();
      if (!t) return;
      busy(go, true);
      try {
        const r = await api.post('items.php', { op: 'add', list_id: LIST_ID, text: t });
        r.items.forEach((it) => { setItem(it); S.flash.add(it.id); });
        S.version = r.version;
        recount();
        addText.value = '';
        parsed = [];
        draw();
        grow();
        renderAll();
        const bits = [];
        if (r.added.length) bits.push(r.added.length === 1 ? 'Added' : `Added ${r.added.length}`);
        if (r.merged.length) bits.push(r.merged.length === 1 ? `${r.merged[0].name} is now ${r.merged[0].qty}` : `${r.merged.length} added up with what was there`);
        toast(bits.join(' · ') || 'Added');
        addText.focus();
      } catch (e) { toastError(e); } finally { busy(go, false); go.disabled = !addText.value.trim(); }
    };
    $('#addForm').addEventListener('submit', (e) => { e.preventDefault(); submit(); });
  }

  // ======================================================================= item dialogs
  let projectsCache = null;
  async function projects() {
    if (projectsCache) return projectsCache;
    try { projectsCache = (await api.get('lists.php', { op: 'projects' })).projects; } catch (e) { projectsCache = []; }
    return projectsCache;
  }
  const storeOptions = (sel) => `<option value="">No store</option>${(boot.stores || []).map((s) => `<option value="${s.id}"${s.id === sel ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}`;
  const unitOptions = (sel) => Object.entries(boot.units || { ea: 'each' }).map(([k, l]) => `<option value="${esc(k)}"${k === sel ? ' selected' : ''}>${esc(l)}</option>`).join('');
  const catOptions = (sel) => CATS.map((k) => `<option value="${esc(k)}"${k === sel ? ' selected' : ''}>${esc(catOf(k).label)}</option>`).join('');

  async function editItem(id) {
    const it = S.items.find((i) => i.id === id);
    if (!it || !can().edit) return;
    const pj = await projects();
    const m = modal(`<div class="fwm-dialog__head"><h2>${esc(it.name)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fws-form" novalidate>
        <div class="field"><label class="label" for="eiName">What</label><input class="input" id="eiName" maxlength="200" value="${esc(it.name)}" autocomplete="off"></div>
        <div class="row">
          <div class="field"><label class="label" for="eiQty">How many</label><input class="input" id="eiQty" inputmode="decimal" value="${esc(it.qty_text)}" autocomplete="off"></div>
          <div class="field"><label class="label" for="eiUnit">Unit</label><select class="select" id="eiUnit">${unitOptions(it.unit)}</select></div>
          <div class="field"><label class="label" for="eiEst">Price of one</label><input class="input" id="eiEst" inputmode="decimal" placeholder="R 0.00" value="${esc(rand(it.est))}" autocomplete="off"></div>
        </div>
        <div class="row">
          <div class="field"><label class="label" for="eiCat">Aisle</label><select class="select" id="eiCat">${catOptions(it.category)}</select></div>
          <div class="field"><label class="label" for="eiNeed">Needed by</label><input class="input" id="eiNeed" type="date" value="${esc(it.needed_by || '')}"></div>
        </div>
        <div class="row">
          <div class="field"><label class="label" for="eiStore">Buy it at</label><select class="select" id="eiStore">${storeOptions(it.store_id)}</select></div>
          <div class="field"><label class="label" for="eiProject">For the project</label><select class="select" id="eiProject"><option value="">No project</option>${pj.map((p) => `<option value="${p.id}"${p.id === it.project_id ? ' selected' : ''}>${esc(p.label)}</option>`).join('')}${it.project_id && !pj.find((p) => p.id === it.project_id) ? `<option value="${it.project_id}" selected>${esc(it.project || 'Current project')}</option>` : ''}</select></div>
        </div>
        <div class="field" style="margin-top:16px"><label class="label" for="eiNotes">Notes</label><input class="input" id="eiNotes" maxlength="500" value="${esc(it.notes)}" placeholder="Brand, colour, size — anything that helps whoever buys it" autocomplete="off"></div>
        <label class="switch" style="margin-top:10px"><input type="checkbox" id="eiUrgent"${it.priority === 'high' ? ' checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Urgent</span><span class="hint">Goes to the top of its aisle.</span></span></label>
        ${it.bought ? `<div class="field" style="margin-top:14px"><label class="label" for="eiPaid">What it cost (all ${esc(unitText(it.qty_text, it.unit))})</label><input class="input" id="eiPaid" inputmode="decimal" placeholder="R 0.00" value="${esc(rand(it.paid))}" autocomplete="off"></div>` : ''}
        ${it.added_by ? `<p class="hint" style="margin-top:12px">Added by ${esc(it.added_by)} ${esc(ago(it.created_at))}${it.bought && it.bought_by ? ' · bought by ' + esc(it.bought_by) : ''}.</p>` : ''}
      </form>
      <div class="fwm-dialog__foot"><button class="btn btn--ghost btn--danger" type="button" data-act="del">${icon('trash', 'i--sm')}Remove</button>
        ${it.bought ? '' : `<button class="btn btn--ghost" type="button" data-act="where">${icon('is-map', 'i--sm')}<span class="hide-narrow">Where to buy</span></button>`}<span class="grow"></span>
        <button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-act="save">Save</button></div>`, { width: 560 });
    const save = async () => {
      const btn = $('[data-act=save]', m);
      const est = $('#eiEst', m).value.trim();
      if (est && toCents(est) === null) { toast('The price is not an amount in rand.', { type: 'error' }); return; }
      const paidIn = $('#eiPaid', m);
      const paid = paidIn ? paidIn.value.trim() : null;
      if (paid && toCents(paid) === null) { toast('What it cost is not an amount in rand.', { type: 'error' }); return; }
      busy(btn, true);
      try {
        const r = await api.post('items.php', {
          op: 'update', id, name: $('#eiName', m).value, qty: $('#eiQty', m).value, unit: $('#eiUnit', m).value, est: est ? toCents(est) / 100 : '',
          category: $('#eiCat', m).value, needed_by: $('#eiNeed', m).value, store_id: $('#eiStore', m).value || 0, project_id: $('#eiProject', m).value || 0,
          notes: $('#eiNotes', m).value, priority: $('#eiUrgent', m).checked ? 'high' : 'med',
        });
        setItem(r.item);
        S.version = r.version;
        if (paidIn && (paid === '' ? null : toCents(paid)) !== it.paid) {
          const p = await api.post('items.php', { op: 'price', id, paid: paid === '' ? '' : toCents(paid) / 100 });
          setItem(p.item);
          S.version = p.version;
        }
        recount();
        closeModal(m);
        renderAll();
      } catch (e) { busy(btn, false); toastError(e); }
    };
    $('[data-act=save]', m).addEventListener('click', save);
    $('[data-act=del]', m).addEventListener('click', () => { closeModal(m); remove(id); });
    const wb = $('[data-act=where]', m);
    if (wb) wb.addEventListener('click', () => { closeModal(m); whereToBuy(id); });
    $('form', m).addEventListener('submit', (e) => { e.preventDefault(); save(); });
    $$('input', m).forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }));
    openModal(m);
  }

  async function remove(id) {
    const it = S.items.find((i) => i.id === id);
    if (!it) return;
    const row = $('#item-' + id);
    if (row) row.classList.add('is-leaving');
    try {
      const r = await api.post('items.php', { op: 'remove', id });
      S.items = S.items.filter((i) => i.id !== id);
      S.version = r.version;
      recount();
      setTimeout(renderAll, 180);
      toast(`Removed ${it.name}`, { action: { label: 'Undo', run: async () => {
        try { const u = await api.post('items.php', { op: 'restore', id }); setItem(u.item); S.flash.add(id); S.version = u.version; recount(); renderAll(); } catch (e) { toastError(e); }
      } } });
    } catch (e) { if (row) row.classList.remove('is-leaving'); toastError(e); }
  }

  async function whereToBuy(id) {
    const it = S.items.find((i) => i.id === id);
    if (!it) return;
    const m = modal(`<div class="fwm-dialog__head"><h2>Where to buy ${esc(it.name)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body"><div class="fws-where" id="whereBody"><div class="fws-where__wait"><span class="fws-spin"></span> Looking through what you know…</div></div></div>`, { width: 620 });
    openModal(m);
    const rowHtml = (r) => `<div class="fws-where__row${r.cheapest ? ' is-best' : ''}">
      <span class="fws-where__icon">${icon({ history: 'history', supplier: 'is-order', store: 'is-store', crm: 'building', ai: 'sparkle' }[r.kind] || 'is-store', 'i--sm')}</span>
      <div class="fws-where__text"><b>${esc(r.name)}${r.cheapest ? ' <span class="fws-flag fws-flag--ok">Cheapest</span>' : ''}${r.preferred ? ' <span class="fws-flag fws-flag--gold">Preferred</span>' : ''}</b><small>${esc(r.why)}</small></div>
      <div class="fws-where__acts">
        ${r.tel ? `<a class="icon-btn icon-btn--sm" href="${esc(r.tel)}" aria-label="Call" data-tip="Call">${icon('phone', 'i--sm')}</a>` : ''}
        ${r.wa ? `<a class="icon-btn icon-btn--sm" href="${esc(r.wa)}" target="_blank" rel="noopener" aria-label="WhatsApp" data-tip="WhatsApp">${icon('whatsapp', 'i--sm')}</a>` : ''}
        ${r.maps ? `<a class="icon-btn icon-btn--sm" href="${esc(r.maps)}" target="_blank" rel="noopener" aria-label="Directions" data-tip="Map">${icon('is-map', 'i--sm')}</a>` : ''}
        ${r.store_id || r.crm_id ? `<button class="fws-mini" type="button" data-plan-store="${r.store_id || ''}" data-plan-crm="${r.crm_id || ''}">${it.store_id && it.store_id === r.store_id ? icon('check', 'i--xs') + 'Planned' : 'Buy here'}</button>` : ''}
      </div></div>`;
    let data;
    try { data = await api.get('stores.php', { op: 'suggest', item_id: id }); } catch (e) { $('#whereBody', m).innerHTML = `<p class="fws-panel__empty">${esc(e.message)}</p>`; return; }
    const draw = (aiRows) => {
      let html = data.sections.map((s) => `<h4 class="fws-where__title">${esc(s.title)}</h4>${s.rows.map(rowHtml).join('')}`).join('');
      if (!data.sections.length) html += '<p class="fws-where__none">Nothing known yet about where this is bought. Tick it off with a price and a store, and next time it shows here.</p>';
      if (aiRows) html += `<h4 class="fws-where__title">${icon('sparkle', 'i--xs')}Ideas from AI <small>— check before you go</small></h4>${aiRows.length ? aiRows.map(rowHtml).join('') : '<p class="fws-where__none">No ideas this time.</p>'}`;
      html += `<div class="fws-where__foot">${data.ai && !aiRows ? `<button class="btn btn--sm" type="button" data-ai>${icon('sparkle', 'i--sm')}Ask AI for ideas</button>` : ''}
        <a class="btn btn--sm btn--ghost" href="${esc(data.search)}" target="_blank" rel="noopener">${icon('search', 'i--sm')}Search the map${data.town ? ' near ' + esc(data.town) : ''}</a>
        <a class="btn btn--sm btn--ghost" href="/shopping/prices.php#stores">${icon('is-store', 'i--sm')}Your stores</a></div>`;
      $('#whereBody', m).innerHTML = html;
    };
    draw(null);
    m.addEventListener('click', async (e) => {
      const ai = e.target.closest('[data-ai]');
      if (ai) {
        busy(ai, true);
        try { const r = await api.post('stores.php', { op: 'ai', item_id: id }); draw(r.rows); } catch (er) { busy(ai, false); toastError(er); }
        return;
      }
      const plan = e.target.closest('[data-plan-store],[data-plan-crm]');
      if (plan) {
        busy(plan, true);
        try {
          const r = await api.post('stores.php', { op: 'plan', item_id: id, store_id: plan.dataset.planStore || 0, crm_id: plan.dataset.planCrm || 0 });
          setItem(r.item);
          if (r.item.store_id && !(boot.stores || []).find((s) => s.id === r.item.store_id)) (boot.stores = boot.stores || []).push({ id: r.item.store_id, name: r.item.store });
          closeModal(m);
          renderAll();
          toast(`${it.name}: buy it at ${r.item.store}`);
        } catch (er) { busy(plan, false); toastError(er); }
      }
    });
  }

  // ======================================================================= list dialogs
  async function startShopping() {
    const stores = boot.stores || [];
    let storeId = S.list.store_id || boot.prefs.home_store_id || 0;
    if (stores.length) {
      const choice = await dialog({ title: 'Where are you shopping?', body: 'Prices you note are kept for that store, so next time you know where it was cheapest.',
        choices: [{ value: '0', label: 'I will say later', checked: !storeId }, ...stores.slice(0, 12).map((s) => ({ value: String(s.id), label: s.name, checked: s.id === storeId }))], confirm: 'Start shopping' });
      if (choice === null) return;
      storeId = +choice;
    }
    try {
      const d = await api.post('lists.php', { op: 'shop', id: LIST_ID, store_id: storeId || '' });
      apply(d);
      wake(true);
      schedule();
      window.scrollTo({ top: 0 });
      ($('#scroll') || document.body).scrollTo({ top: 0, behavior: 'smooth' });
      toast(boot.prefs.price_prompt ? 'Tick things off as they go in the trolley — the price is asked as you go.' : 'Tick things off as they go in the trolley.');
    } catch (e) { toastError(e); }
  }

  function chooseStore(anchor) {
    const stores = boot.stores || [];
    popover(anchor, `<div class="fwm-pop__title">Shopping at</div>
      ${stores.map((s) => `<button class="fwm-pop__item" type="button" data-store="${s.id}">${icon('is-store')}${esc(s.name)}${S.session && S.session.store_id === s.id ? icon('check', 'i--sm') : ''}</button>`).join('') || '<div class="fwm-pop__item" aria-disabled="true">No stores yet</div>'}
      <div class="fwm-pop__sep"></div><button class="fwm-pop__item" type="button" data-store-new>${icon('plus')}Another store…</button>`, { cls: 'fws-storepop' });
    const pop = document.querySelector('.fws-storepop');
    pop.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-store]');
      if (b) {
        closePop();
        try { apply(await api.post('lists.php', { op: 'shop_store', id: LIST_ID, store_id: +b.dataset.store })); } catch (er) { toastError(er); }
        return;
      }
      if (e.target.closest('[data-store-new]')) {
        closePop();
        const name = await dialog({ title: 'Which store?', input: { label: 'Name', placeholder: 'Builders Edenvale, Spar Main Road…', max: 190 }, confirm: 'Shop here' });
        if (!name) return;
        try {
          const r = await api.post('stores.php', { op: 'save', name });
          boot.stores = r.stores.map((s) => ({ id: s.id, name: s.name }));
          apply(await api.post('lists.php', { op: 'shop_store', id: LIST_ID, store_id: r.id }));
        } catch (er) { toastError(er); }
      }
    });
  }

  async function finish() {
    const L = S.list;
    await flush();
    const choices = L.left ? [
      { value: 'keep', label: `Keep the ${L.left} on this list`, hint: 'Shop for them another time.' },
      { value: 'move', label: 'Move them to a new list', hint: 'This list is done; the rest waits on its own list.' },
      { value: 'remove', label: 'Take them off', hint: 'You do not need them after all.' },
    ] : null;
    const spentLine = `<b>${L.bought}</b> of ${L.items} bought${L.paid ? ' for <b>' + esc(money(L.paid)) + '</b>' : ''}${L.budget ? ` — ${L.paid <= L.budget ? esc(money(L.budget - L.paid)) + ' under' : esc(money(L.paid - L.budget)) + ' over'} the budget` : ''}.`;
    const pick = await dialog({ title: 'Finish shopping', body: spentLine + (L.left ? ' What about the rest?' : ''), choices, confirm: 'Finish' });
    if (pick === null || pick === false) return;
    try {
      const d = await api.post('lists.php', { op: 'finish', id: LIST_ID, leftover: typeof pick === 'string' ? pick : 'keep' });
      const r = d.finish;
      apply(d);
      wake(false);
      schedule();
      if (r.done) celebrate(r);
      else toast(r.moved_to ? 'Done — the rest is on a new list.' : 'Shopping finished.', r.moved_to ? { action: { label: 'Open it', run: () => { location.href = '/shopping/list.php?id=' + r.moved_to; } } } : {});
    } catch (e) { toastError(e); }
  }

  function celebrate(r) {
    const m = modal(`<div class="fws-done">
        <span class="fws-done__seal" aria-hidden="true">${icon('check')}</span>
        <h2>All bought</h2>
        <p>${r.bought} ${r.bought === 1 ? 'thing' : 'things'}${r.paid ? ' for <b>' + esc(money(r.paid)) + '</b>' : ''}. Every price you noted is in the price book.</p>
        <div class="fws-done__acts">
          <a class="btn btn--primary" href="/receipts/upload.php">${icon('receipt')}Send the slip to Receipts</a>
          <button class="btn" type="button" data-close>Close</button>
        </div>
        ${r.moved_to ? `<p class="hint"><a href="/shopping/list.php?id=${r.moved_to}">The rest is on a new list</a></p>` : ''}
      </div>`, { width: 440, cls: 'fws-done-dlg' });
    openModal(m);
  }

  function shareDialog() {
    const L = S.list;
    const m = modal(`<div class="fwm-dialog__head"><h2>Share “${esc(L.name)}”</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body fws-form">
        <label class="switch"><input type="checkbox" id="shTeam"${L.shared === 'team' ? ' checked' : ''}><span class="switch__track"></span>
          <span class="switch__text"><span class="switch__title">Everyone in ${esc(boot.companies && boot.companies.find((c) => c.active) ? boot.companies.find((c) => c.active).name : 'the company')}</span><span class="hint">They see the list in Shopping, add to it and tick things off. Off: only you.</span></span></label>
        <h4 class="fws-form__title">${icon('link', 'i--xs')}A link for someone without Flowwork</h4>
        <p class="hint">They open it on their phone — no sign-in — and tick things off as they shop. They never see prices or the budget.</p>
        <div class="row">
          <div class="field"><span class="label">Works for</span><div class="seg" id="shDays"><button type="button" data-days="1">A day</button><button type="button" class="is-on" data-days="7">A week</button><button type="button" data-days="30">A month</button></div></div>
        </div>
        <label class="switch" style="margin-top:8px"><input type="checkbox" id="shEdit"><span class="switch__track"></span><span class="switch__text"><span class="switch__title">They may add things too</span><span class="hint">Off: they can only tick off what is on it.</span></span></label>
        <div class="fws-sharelink" id="shOut" hidden></div>
      </div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Done</button><button class="btn btn--primary" type="button" id="shGo">${icon('link', 'i--sm')}Make the link</button></div>`, { width: 540 });
    let days = 7;
    $$('#shDays button', m).forEach((b) => b.addEventListener('click', () => { days = +b.dataset.days; $$('#shDays button', m).forEach((x) => x.classList.toggle('is-on', x === b)); }));
    $('#shTeam', m).addEventListener('change', async (e) => {
      try {
        const r = await api.post('lists.php', { op: 'update', id: LIST_ID, shared: e.target.checked ? 'team' : 'private' });
        S.list = r.list; S.version = r.version; renderHead();
        toast(e.target.checked ? 'The team can see this list now.' : 'Only you can see this list now.');
      } catch (er) { e.target.checked = !e.target.checked; toastError(er); }
    });
    $('#shGo', m).addEventListener('click', async () => {
      const btn = $('#shGo', m);
      busy(btn, true);
      try {
        const r = await api.post('lists.php', { op: 'share', id: LIST_ID, days, can_check: 1, can_edit: $('#shEdit', m).checked ? 1 : 0 });
        S.shares = r.shares;
        renderSide();
        const msg = `${L.name} — the shopping list: ${r.url}`;
        const out = $('#shOut', m);
        out.hidden = false;
        out.innerHTML = `<input class="input" readonly value="${esc(r.url)}" aria-label="The link">
          <div class="fws-sharelink__acts"><button class="btn btn--sm" type="button" data-copy="${esc(r.url)}">${icon('copy', 'i--sm')}Copy</button>
          <a class="btn btn--sm" href="https://wa.me/?text=${encodeURIComponent(msg)}" target="_blank" rel="noopener">${icon('whatsapp', 'i--sm')}WhatsApp</a>
          ${navigator.share ? `<button class="btn btn--sm" type="button" data-native>${icon('share', 'i--sm')}More…</button>` : ''}</div>`;
        const nat = $('[data-native]', out);
        if (nat) nat.addEventListener('click', () => navigator.share({ title: L.name, text: L.name + ' — the shopping list', url: r.url }).catch(() => {}));
        $('input', out).select();
        busy(btn, false);
      } catch (e) { busy(btn, false); toastError(e); }
    });
    openModal(m);
  }

  async function poDialog() {
    let sup;
    try { sup = (await api.get('lists.php', { op: 'suppliers' })).suppliers; } catch (e) { toastError(e); return; }
    const rows = S.items.filter((i) => !i.bought && !i.po_id);
    if (!rows.length) { toast('Everything still to buy is already on a purchase order.'); return; }
    const m = modal(`<div class="fwm-dialog__head"><h2>A purchase order</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body fws-form">
        <p class="fwm-dialog__lead">A draft order in Procurement for what is still to buy. Prices as you would pay them, VAT included — the order shows them without VAT when the supplier has a VAT number.</p>
        <div class="field"><label class="label" for="poSup">Supplier</label><select class="select" id="poSup"><option value="">Choose a supplier…</option>${sup.map((s) => `<option value="${s.id}" data-vat="${s.vat ? 1 : 0}">${esc(s.name)}${s.preferred ? ' ★' : ''}</option>`).join('')}</select>
          ${sup.length ? '' : '<span class="hint">No suppliers in the CRM yet — <a href="/crm/#suppliers">add one</a>.</span>'}</div>
        <div class="fws-poitems" id="poItems">${rows.map((i) => `<label class="fws-poitem"><input type="checkbox" data-po="${i.id}" checked><span class="fws-poitem__name"><b>${esc(i.name)}</b><small>${esc(unitText(i.qty_text, i.unit))}</small></span>
          <span class="fws-ask__field"><span>R</span><input data-po-price="${i.id}" inputmode="decimal" placeholder="each" value="${esc(rand(i.est))}" aria-label="Price of one ${esc(i.name)}"></span></label>`).join('')}</div>
        <div class="fws-pototal" id="poTotal"></div>
      </div>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="poGo" disabled>${icon('is-order', 'i--sm')}Make the order</button></div>`, { width: 600 });
    const calc = () => {
      let t = 0, n = 0;
      rows.forEach((i) => {
        if (!$(`[data-po="${i.id}"]`, m).checked) return;
        n++;
        const c = toCents($(`[data-po-price="${i.id}"]`, m).value);
        if (c) t += Math.round(c * i.qty);
      });
      $('#poTotal', m).innerHTML = `${n} ${n === 1 ? 'line' : 'lines'} · <b>${esc(money(t))}</b> incl. VAT`;
      $('#poGo', m).disabled = !n || !$('#poSup', m).value;
    };
    m.addEventListener('input', calc);
    m.addEventListener('change', calc);
    calc();
    $('#poGo', m).addEventListener('click', async () => {
      const btn = $('#poGo', m);
      const lines = rows.filter((i) => $(`[data-po="${i.id}"]`, m).checked).map((i) => ({ id: i.id, price: (toCents($(`[data-po-price="${i.id}"]`, m).value) || 0) / 100 }));
      busy(btn, true);
      try {
        const r = await api.post('lists.php', { op: 'po', id: LIST_ID, supplier_id: +$('#poSup', m).value, lines });
        closeModal(m);
        apply(r.detail);
        toast(`${r.po_number} for ${r.supplier} — ${money(Math.round(r.total * 100))}`, { action: { label: 'Open it', run: () => { location.href = r.url; } }, timeout: 10000 });
      } catch (e) { busy(btn, false); toastError(e); }
    });
    openModal(m);
  }

  async function detailsDialog() {
    const L = S.list;
    const pj = await projects();
    const m = modal(`<div class="fwm-dialog__head"><h2>About this list</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fws-form" novalidate>
        <div class="field"><label class="label" for="ldName">Name</label><input class="input" id="ldName" maxlength="120" value="${esc(L.name)}" autocomplete="off"></div>
        <div class="field"><span class="label">What is it for?</span><div class="fws-purpose" role="radiogroup">${Object.entries(boot.purposes).map(([k, l]) => `<label class="fws-purpose__opt"><input type="radio" name="ldPurpose" value="${esc(k)}"${k === L.purpose ? ' checked' : ''}><span>${icon(k === 'procurement' ? 'is-crate' : (k === 'groceries' ? 'is-basket' : 'is-list'))}${esc(l)}</span></label>`).join('')}</div></div>
        <div class="row">
          <div class="field"><label class="label" for="ldBudget">Budget</label><input class="input" id="ldBudget" inputmode="decimal" placeholder="R 0.00" value="${esc(rand(L.budget))}" autocomplete="off"></div>
          <div class="field"><label class="label" for="ldNeed">Needed by</label><input class="input" id="ldNeed" type="date" value="${esc(L.needed_by || '')}"></div>
        </div>
        <div class="row">
          <div class="field"><label class="label" for="ldProject">For the project</label><select class="select" id="ldProject"><option value="">No project</option>${pj.map((p) => `<option value="${p.id}"${p.id === L.project_id ? ' selected' : ''}>${esc(p.label)}</option>`).join('')}${L.project_id && !pj.find((p) => p.id === L.project_id) ? `<option value="${L.project_id}" selected>${esc(L.project || 'Current project')}</option>` : ''}</select>
            <span class="hint">New items go to this project.</span></div>
          <div class="field"><label class="label" for="ldStore">Usually bought at</label><select class="select" id="ldStore">${storeOptions(L.store_id)}</select></div>
        </div>
        <div class="field" style="margin-top:16px"><label class="label" for="ldNotes">A note for whoever shops</label><input class="input" id="ldNotes" maxlength="500" value="${esc(L.notes)}" placeholder="Use the company card · deliver to site gate 2" autocomplete="off"></div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-act="save">Save</button></div>`, { width: 560 });
    const save = async () => {
      const b = $('#ldBudget', m).value.trim();
      if (b && toCents(b) === null) { toast('The budget is not an amount in rand.', { type: 'error' }); return; }
      const btn = $('[data-act=save]', m);
      busy(btn, true);
      try {
        const r = await api.post('lists.php', { op: 'update', id: LIST_ID, name: $('#ldName', m).value, purpose: ($('input[name=ldPurpose]:checked', m) || {}).value, budget: b ? toCents(b) / 100 : '',
          needed_by: $('#ldNeed', m).value, project_id: $('#ldProject', m).value || 0, store_id: $('#ldStore', m).value || 0, notes: $('#ldNotes', m).value });
        closeModal(m);
        S.version = '';
        await refresh(true);
        const nav = $(`[data-nav-list="${LIST_ID}"] .fwm-nav__label`);
        if (nav && r.list) nav.textContent = r.list.name;
      } catch (e) { busy(btn, false); toastError(e); }
    };
    $('[data-act=save]', m).addEventListener('click', save);
    $('form', m).addEventListener('submit', (e) => { e.preventDefault(); save(); });
    openModal(m);
  }

  function moreMenu(anchor) {
    const c = can();
    popover(anchor, `
      ${c.edit ? `<button class="fwm-pop__item" type="button" data-m="details">${icon('pen')}Name, budget, date, project…</button>` : ''}
      ${c.order && S.list.left ? `<button class="fwm-pop__item" type="button" data-m="po">${icon('is-order')}Make a purchase order</button>` : ''}
      ${c.manage ? `<button class="fwm-pop__item" type="button" data-m="share">${icon('share')}Share</button>` : ''}
      ${boot.caps.write ? `<button class="fwm-pop__item" type="button" data-m="dup">${icon('copy')}Duplicate</button>
      <button class="fwm-pop__item" type="button" data-m="tpl">${icon('template')}Save as a template</button>` : ''}
      <a class="fwm-pop__item" href="/shopping/api/lists.php?op=export&amp;id=${LIST_ID}">${icon('download')}Download (CSV)</a>
      <button class="fwm-pop__item" type="button" data-m="print">${icon('print')}Print</button>
      ${c.manage ? `<div class="fwm-pop__sep"></div><button class="fwm-pop__item is-danger" type="button" data-m="delete">${icon('trash')}Delete the list</button>` : ''}`, { align: 'end', cls: 'fws-more' });
    document.querySelector('.fws-more').addEventListener('click', async (e) => {
      const b = e.target.closest('[data-m]');
      if (!b) return;
      closePop();
      const k = b.dataset.m;
      if (k === 'details') detailsDialog();
      if (k === 'po') poDialog();
      if (k === 'share') shareDialog();
      if (k === 'print') window.print();
      if (k === 'dup') {
        const name = await dialog({ title: 'Duplicate', body: 'A new list with everything on this one, still to buy.', input: { label: 'Name', value: S.list.name + ' (again)', max: 120 }, confirm: 'Duplicate' });
        if (name === null) return;
        try { location.href = (await api.post('lists.php', { op: 'duplicate', id: LIST_ID, name })).url; } catch (er) { toastError(er); }
      }
      if (k === 'tpl') {
        const name = await dialog({ title: 'Save as a template', body: 'Start the next one from it in a tap. Everything on the list goes in, with the prices you know.', input: { label: 'Name', value: S.list.name, max: 120 }, confirm: 'Save' });
        if (name === null) return;
        try { const r = await api.post('lists.php', { op: 'template', id: LIST_ID, name }); toast('Saved as a template.', { action: { label: 'See it', run: () => { location.href = r.url; } } }); } catch (er) { toastError(er); }
      }
      if (k === 'delete') {
        const ok = await dialog({ title: 'Delete this list?', body: `“${esc(S.list.name)}” and its ${S.list.items} ${S.list.items === 1 ? 'item' : 'items'}. Links to it stop working. You can bring it back straight after.`, confirm: 'Delete', danger: true });
        if (!ok) return;
        try { await api.post('lists.php', { op: 'delete', id: LIST_ID }); location.href = '/shopping/?deleted=' + LIST_ID; } catch (er) { toastError(er); }
      }
    });
  }

  // ======================================================================= events
  app.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-act]');
    const copy = e.target.closest('[data-copy]');
    const unshare = e.target.closest('[data-unshare]');
    if (copy) { copyText(copy.dataset.copy); return; }
    if (unshare) {
      const ok = await dialog({ title: 'Stop this link?', body: 'Whoever has it can no longer open the list.', confirm: 'Stop it', danger: true });
      if (!ok) return;
      try { S.shares = (await api.post('lists.php', { op: 'unshare', id: LIST_ID, token_id: +unshare.dataset.unshare })).shares; renderSide(); } catch (er) { toastError(er); }
      return;
    }
    if (!b) return;
    const row = b.closest('.fws-item');
    const id = row ? +row.dataset.id : 0;
    switch (b.dataset.act) {
      case 'toggle': toggle(id); break;
      case 'edit': editItem(id); break;
      case 'main': if (!e.target.closest('a')) { if (shopping()) toggle(id); else editItem(id); } break; // in the shop a tap anywhere on the row ticks it
      case 'remove': remove(id); break;
      case 'where': whereToBuy(id); break;
      case 'price': askPrice(id); break;
      case 'shop': startShopping(); break;
      case 'finish': finish(); break;
      case 'store': chooseStore(b); break;
      case 'share': shareDialog(); break;
      case 'po': poDialog(); break;
      case 'details': detailsDialog(); break;
      case 'more': moreMenu(b); break;
      case 'reopen': try { apply(await api.post('lists.php', { op: 'reopen', id: LIST_ID })); } catch (er) { toastError(er); } break;
      case 'again': try { location.href = (await api.post('lists.php', { op: 'duplicate', id: LIST_ID, name: S.list.name.replace(/ \(again\)$/, '') + ' (again)' })).url; } catch (er) { toastError(er); } break;
      case 'trolley': {
        const sec = b.closest('.fws-group--trolley');
        const closed = !sec.classList.contains('is-closed');
        sec.classList.toggle('is-closed', closed);
        b.setAttribute('aria-expanded', String(!closed));
        try { localStorage.setItem('fws-trolley-' + LIST_ID, closed ? '0' : '1'); } catch (er) { /* fine */ }
        break;
      }
    }
  });
  // Price asked
  $('#groups').addEventListener('submit', (e) => {
    const f = e.target.closest('[data-ask]');
    if (!f) return;
    e.preventDefault();
    const id = +f.dataset.ask;
    const it = S.items.find((i) => i.id === id);
    const v = $('input', f).value.trim();
    const c = v === '' ? null : toCents(v);
    if (v !== '' && (c === null || c < 0)) { toast('That is not an amount in rand.', { type: 'error' }); return; }
    const per = $('.fws-ask__per .is-on', f);
    savePrice(id, c === null ? null : (per && per.dataset.per === 'each' ? Math.round(c * it.qty) : c));
  });
  $('#groups').addEventListener('click', (e) => {
    const skip = e.target.closest('[data-ask-skip]');
    if (skip) { S.asking = null; renderGroups(); return; }
    const per = e.target.closest('[data-per]');
    if (per) { $$('[data-per]', per.parentElement).forEach((x) => x.classList.toggle('is-on', x === per)); $('input', per.closest('form')).focus(); }
  });
  $('#groups').addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && e.target.closest('[data-ask]')) { e.stopPropagation(); S.asking = null; renderGroups(); }
  });
  $$('[data-group]').forEach((b) => b.addEventListener('click', () => {
    S.group = b.dataset.group;
    try { localStorage.setItem('fws-group', S.group); } catch (er) { /* fine */ }
    renderGroups();
  }));

  async function copyText(t) {
    try { await navigator.clipboard.writeText(t); toast('The link is copied.'); }
    catch (e) { dialog({ title: 'The link', input: { value: t }, confirm: 'Close', cancel: '' }); }
  }

  // Keyboard: n add · s shop · j/k move · x or space tick · e edit · Delete remove
  document.addEventListener('keydown', (e) => {
    if (F.typingIn(e.target) || e.ctrlKey || e.metaKey || e.altKey || F.modalOpen) return;
    const rows = $$('.fws-item');
    const cur = document.activeElement && document.activeElement.closest ? document.activeElement.closest('.fws-item') : null;
    const k = e.key;
    if (k === 'n' && addText) { e.preventDefault(); addText.focus(); }
    else if (k === 's' && can().edit) { e.preventDefault(); shopping() ? finish() : (S.list.items && S.list.status !== 'done' && startShopping()); }
    else if ((k === 'j' || k === 'k') && rows.length) {
      e.preventDefault();
      const i = cur ? rows.indexOf(cur) : -1;
      const next = rows[k === 'j' ? Math.min(rows.length - 1, i + 1) : Math.max(0, i - 1)];
      next.focus();
      next.scrollIntoView({ block: 'nearest' });
    } else if (cur && (k === 'x' || k === ' ')) { e.preventDefault(); toggle(+cur.dataset.id); requestAnimationFrame(() => { const r = $('#item-' + cur.dataset.id); if (r) r.focus(); }); }
    else if (cur && k === 'e') { e.preventDefault(); editItem(+cur.dataset.id); }
    else if (cur && (k === 'Delete' || k === 'Backspace')) { e.preventDefault(); remove(+cur.dataset.id); }
  });

  // ======================================================================= start
  apply(boot.detail);
  if (location.hash.startsWith('#item-')) {
    const id = +location.hash.slice(6);
    S.flash.add(id);
    renderGroups();
    const el = $('#item-' + id);
    if (el) setTimeout(() => el.scrollIntoView({ block: 'center', behavior: 'smooth' }), 120);
  }
  flush();
  schedule();
  wake(shopping());
})();
