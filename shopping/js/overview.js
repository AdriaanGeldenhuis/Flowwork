// /shopping/js/overview.js — the overview: the list cards (open / mine / team /
// done), "What do you need?" read as you type and made into a list (or added
// to an open one), and "bring it back" after a list was deleted.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, busy, money, ring, ago, due, debounce, unitText, catOf, boot } = window.FWS;
  let lists = boot.lists || [];
  let filter = 'active';

  // ------------------------------------------------------------------ cards
  function card(l, i) {
    const color = { procurement: '#9a6512', groceries: '#2f7457', general: '#7a1f30' }[l.purpose] || '#7a1f30';
    const pct = l.budget ? Math.min(100, Math.round((l.est_total / l.budget) * 100)) : 0;
    const bits = [];
    if (l.status === 'done') bits.push('Done ' + (l.done_at ? ago(l.done_at) : ''));
    else bits.push(l.left ? l.left + ' to buy' : (l.items ? 'All in the trolley' : 'Nothing on it yet'));
    if (l.due && l.status !== 'done') bits.push(`<span class="${l.late ? 'fws-late' : ''}">${esc(l.late ? due(l.due) : 'needed ' + due(l.due))}</span>`);
    return `<a class="fws-card${l.status === 'done' ? ' is-done' : ''}${l.shopping ? ' is-live' : ''}" href="/shopping/list.php?id=${l.id}" style="--c:${color};--i:${i}">
      <span class="fws-card__top">
        <span class="fws-card__eyebrow">${icon(l.purpose === 'procurement' ? 'is-crate' : (l.purpose === 'groceries' ? 'is-basket' : 'is-list'), 'i--xs')}${esc(l.purpose_label)}</span>
        ${l.shopping ? `<span class="fws-pill" data-tone="info">${esc((l.shopping.name || 'Someone').split(' ')[0])} is shopping</span>` : ''}
        ${l.shared === 'team' ? `<span class="fws-card__team" title="Shared with the team">${icon('users', 'i--xs')}</span>` : ''}
      </span>
      <span class="fws-card__main">
        <span class="fws-card__text"><b class="fws-card__name">${esc(l.name)}</b><small>${bits.join(' · ')}</small></span>
        ${ring(l.bought, l.items, { label: l.items ? l.bought + '/' + l.items : '0' })}
      </span>
      ${l.budget ? `<span class="fws-budget${l.over_budget ? ' is-over' : ''}"><i style="--p:${pct}%"></i></span>
        <span class="fws-budget__text"><span>${esc(money(l.est_total, { round: true }))}${l.paid ? ` <small>(${esc(money(l.paid, { round: true }))} paid)</small>` : ''}</span><span>of ${esc(money(l.budget, { round: true }))}</span></span>` :
        (l.est_total ? `<span class="fws-budget__text"><span>≈ ${esc(money(l.est_total, { round: true }))}</span><span></span></span>` : '')}
      <span class="fws-card__foot">
        <span class="fwm-avatar${l.mine ? ' fwm-avatar--me' : ''}" style="--h:${window.FWS.hue(l.owner)}" title="${esc(l.owner)}">${esc(window.FWS.initials(l.owner))}</span>
        ${l.project ? `<span class="fws-tag">${icon('briefcase', 'i--xs')}${esc(l.project)}</span>` : ''}
        ${l.urgent && l.status !== 'done' ? `<span class="fws-flag fws-flag--danger">${icon('is-flame', 'i--xs')}${l.urgent} urgent</span>` : ''}
        <small>${esc(ago(l.updated_at))}</small>
      </span>
    </a>`;
  }
  function render() {
    const host = $('#cards');
    if (!host) return;
    const rows = lists.filter((l) => filter === 'done' ? l.status === 'done' : (l.status !== 'done' && (filter === 'active' || (filter === 'mine' ? l.mine : l.shared === 'team'))));
    if (!rows.length) {
      const empty = {
        active: lists.length ? ['Everything is bought', 'Nothing is waiting. Type the next thing you need above.'] : ['No lists yet', 'Type or paste what you need above — it becomes your first list.'],
        mine: ['None of your own', 'Lists you make show here.'], team: ['No team lists', 'Share a list with the team and everyone can tick things off.'],
        done: ['Nothing finished yet', 'Lists you finish shopping for are kept here.'],
      }[filter];
      host.innerHTML = `<div class="fws-cards__empty"><span class="fws-cards__seal">${icon(filter === 'done' ? 'check' : 'is-list')}</span><div><h3>${esc(empty[0])}</h3><p>${esc(empty[1])}</p></div></div>`;
      return;
    }
    host.innerHTML = rows.map(card).join('');
  }
  $$('[data-filter]').forEach((b) => b.addEventListener('click', () => {
    filter = b.dataset.filter;
    $$('[data-filter]').forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', String(x === b)); });
    render();
  }));
  render();

  // Fresh numbers when coming back to the tab (someone may have been shopping)
  let lastLoad = Date.now();
  document.addEventListener('visibilitychange', async () => {
    if (document.visibilityState !== 'visible' || Date.now() - lastLoad < 20000) return;
    lastLoad = Date.now();
    try { lists = (await api.get('lists.php', { op: 'overview' })).lists; render(); } catch (e) { /* stay as it is */ }
  });

  // ------------------------------------------------------- deleted? undo
  if (boot.deleted) {
    history.replaceState(null, '', location.pathname);
    toast('The list was deleted.', { action: { label: 'Undo', run: async () => {
      try { const r = await api.post('lists.php', { op: 'restore', id: boot.deleted }); location.href = r.url; } catch (e) { toastError(e); }
    } }, timeout: 10000 });
  }

  // ------------------------------------------------------- What do you need?
  const text = $('#composerText');
  if (!text) return;
  const preview = $('#composerPreview');
  const foot = $('#composerFoot');
  const go = $('#composerGo');
  const target = $('#composerTarget');
  const nameIn = $('#composerName');
  let parsed = [];
  let seq = 0;
  const grow = () => { text.style.height = 'auto'; text.style.height = Math.min(320, text.scrollHeight + 2) + 'px'; };
  const draw = () => {
    const has = parsed.length > 0;
    preview.hidden = !has;
    foot.hidden = !has && !text.value.trim();
    go.disabled = !has;
    $('span', go).textContent = target.value === 'new' ? (has ? `Make the list (${parsed.length})` : 'Make the list') : (has ? `Add ${parsed.length}` : 'Add');
    nameIn.hidden = target.value !== 'new';
    if (!has) { preview.innerHTML = ''; return; }
    const est = parsed.reduce((s, i) => s + (i.price_cents ? Math.round(i.qty * i.price_cents) : 0), 0);
    preview.innerHTML = parsed.slice(0, 40).map((i) => {
      const c = catOf(i.category);
      return `<span class="fws-chip" style="--c:${c.color}" title="${esc(c.label)}">${icon(c.icon, 'i--xs')}<b>${esc(unitText(i.qty_text, i.unit))}</b>${esc(i.name)}${i.price_cents ? `<em>${esc(money(i.price_cents))}</em>` : ''}${i.priority === 'high' ? icon('is-flame', 'i--xs fws-chip__hot') : ''}</span>`;
    }).join('') + (parsed.length > 40 ? `<span class="fws-chip fws-chip--more">+${parsed.length - 40} more</span>` : '') +
      `<span class="fws-composer__sum">${parsed.length} ${parsed.length === 1 ? 'thing' : 'things'}${est ? ' · ≈ ' + esc(money(est, { round: true })) : ''}</span>`;
  };
  const read = debounce(async () => {
    const n = ++seq;
    const t = text.value;
    if (!t.trim()) { parsed = []; draw(); return; }
    try {
      const r = await api.post('items.php', { op: 'parse', text: t });
      if (n === seq) { parsed = r.items; draw(); }
    } catch (e) { /* the next keystroke tries again */ }
  }, 220);
  text.addEventListener('input', () => { grow(); foot.hidden = !text.value.trim() && !parsed.length; read(); });
  target.addEventListener('change', draw);
  $('#composerExample').addEventListener('click', () => {
    text.value = boot.prefs.default_purpose === 'groceries'
      ? 'Milk 2L x 2\nBrown bread\n1 dozen eggs\nCoffee 750g @ R129.99\nBananas 1kg\nDishwashing liquid'
      : '10 bags cement @ R95\n2x PVC elbow 20mm\n6m conduit 16mm\nBox of screws 4x40\nGloves - 5 pairs!\nMilk 2L, coffee, sugar';
    grow();
    text.focus();
    read();
  });
  const submit = async () => {
    if (!parsed.length) return;
    busy(go, true);
    try {
      if (target.value === 'new') {
        const r = await api.post('lists.php', { op: 'create', name: nameIn.value.trim(), text: text.value, purpose: boot.prefs.default_purpose || 'general' });
        location.href = r.url;
      } else {
        const r = await api.post('items.php', { op: 'add', list_id: +target.value, text: text.value });
        location.href = '/shopping/list.php?id=' + target.value + (r.added[0] ? '#item-' + r.added[0] : '');
      }
    } catch (e) { busy(go, false); toastError(e); }
  };
  go.addEventListener('click', submit);
  text.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); submit(); } });
  nameIn.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
})();
