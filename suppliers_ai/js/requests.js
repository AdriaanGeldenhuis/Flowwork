// /suppliers_ai/js/requests.js — the requests for quotes as cards: what each
// is for, how far the suppliers got (asked, replied, quoted), the best quote,
// when quotes are due, the winner and its order. Filter by state, mine, words.
(function () {
  'use strict';
  const F = window.FSA;
  const { $, $$, esc, icon, api, toast, toastError, money } = F;
  const BOOT = F.boot;
  const S = { all: BOOT.requests || [], filter: 'active', mine: false, q: '' };
  const host = $('#reqs');

  const matches = (r) => {
    if (S.mine && !r.mine) return false;
    if (S.q && !(r.title + ' ' + r.location).toLowerCase().includes(S.q)) return false;
    if (r.deleted) return false;
    switch (S.filter) {
      case 'active': return r.status === 'draft' || r.status === 'open';
      case 'compare': return r.status === 'open' && r.quoted > 0;
      case 'awarded': return r.status === 'awarded';
      case 'done': return r.status === 'closed' || r.status === 'cancelled';
      default: return true;
    }
  };

  function progress(r) {
    const n = Math.max(1, r.suppliers);
    const seg = [];
    for (let i = 0; i < r.suppliers; i++) {
      let s = 'to_ask';
      if (r.status === 'awarded' && i === 0) s = 'awarded';
      else if (i < r.quoted) s = 'quoted';
      else if (i < r.replied) s = 'replied';
      else if (i < r.asked) s = 'asked';
      else if (i >= r.suppliers - r.declined) s = 'declined';
      seg.push(`<i data-s="${s}"></i>`);
    }
    return `<span class="fsa-progress" role="img" aria-label="${r.asked} of ${n} asked, ${r.replied} replied, ${r.quoted} quoted">${seg.join('') || '<i></i>'}</span>`;
  }

  function card(r, i) {
    const t = r.trade;
    const done = ['awarded', 'closed', 'cancelled'].includes(r.status);
    const sub = r.status === 'draft' ? `${F.plural(r.suppliers, 'supplier', 'suppliers')} · not asked yet`
      : r.status === 'awarded' ? `Awarded to ${esc(r.winner || '—')}`
      : `${r.quoted} of ${r.suppliers} quoted${r.replied > r.quoted ? ' · ' + (r.replied - r.quoted) + ' replied' : ''}${r.declined ? ' · ' + r.declined + ' declined' : ''}`;
    const due = r.status === 'open' && r.quote_by ? `<span class="${r.late ? 'is-late' : ''}">${icon('clock', 'i--xs')} ${r.late ? 'Quotes were due ' : 'Quotes due '}${esc(r.quote_due)}</span>` : '';
    const right = r.status === 'awarded' && r.winning != null ? `<span class="fsa-num">${money(r.winning)}</span>`
      : r.best != null ? `<span>best <span class="fsa-num">${money(r.best)}</span></span>` : (r.location ? `<span>${icon('pin', 'i--xs')} ${esc(r.location)}</span>` : '');
    return `<a class="fsa-req${done ? ' is-done' : ''}" href="${esc(r.url)}" style="--i:${i};--c:${esc(t ? t.color : '#7a1f30')}">
      <div class="fsa-req__top"><span class="fsa-req__eyebrow">${icon(t ? t.icon : 'isa-quote')}${esc(t ? t.label : 'Request')}${r.mine ? '' : ' · ' + esc(r.who)}</span><span class="fsa-pill" data-s="${esc(r.status)}">${esc(r.status_label)}</span></div>
      <div class="fsa-req__name">${esc(r.title)}</div>
      <small>${sub}</small>
      ${progress(r)}
      <div class="fsa-req__foot">${due || `<span>${esc(F.ago(r.created_at))}</span>`}${r.po_id ? `<span class="fsa-flag fsa-flag--ok">${icon('is-order')}Ordered</span>` : ''}${right}</div>
    </a>`;
  }

  function draw() {
    const rows = S.all.filter(matches);
    if (!rows.length) {
      const msg = { active: 'Nothing is out with suppliers.', compare: 'No quotes in to compare yet.', awarded: 'Nothing awarded yet.', done: 'Nothing closed.', all: 'No requests yet.' }[S.filter];
      host.innerHTML = `<div class="fsa-empty fsa-reqs__empty"><span class="fsa-empty__seal">${icon('isa-quote')}</span><div><h3>${esc(msg)}</h3>
        <p>Find suppliers, shortlist two or three and ask them all for quotes in one go — each gets their own email, and replies are noticed for you.</p>
        ${BOOT.caps.search ? `<a class="btn btn--primary" href="/suppliers_ai/#find">${icon('isa-seal')}Find suppliers</a>` : ''}</div></div>`;
      return;
    }
    host.innerHTML = rows.map(card).join('');
  }

  $$('[data-filter]').forEach((b) => b.addEventListener('click', () => {
    S.filter = b.dataset.filter;
    $$('[data-filter]').forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', String(x === b)); });
    draw();
  }));
  $('#onlyMine').addEventListener('change', (e) => { S.mine = e.target.checked; draw(); });
  $('#reqFilter').addEventListener('input', F.debounce((e) => { S.q = e.target.value.trim().toLowerCase(); draw(); }, 120));
  draw();

  if (BOOT.deleted) {
    toast('The request was deleted.', { action: { label: 'Bring it back', run: async () => {
      try { await api.post('requests.php', { op: 'state', id: BOOT.deleted, to: 'restore' }); location.href = '/suppliers_ai/request.php?id=' + BOOT.deleted; } catch (e) { toastError(e); }
    } } });
    history.replaceState(null, '', '/suppliers_ai/requests.php');
  }
})();
