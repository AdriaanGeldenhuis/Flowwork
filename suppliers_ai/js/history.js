// /suppliers_ai/js/history.js — every search, newest first: open one again
// (as it was found) or search again for today's results; everyone's or mine.
(function () {
  'use strict';
  const F = window.FSA;
  const { $, $$, esc, icon, api, toastError } = F;
  const BOOT = F.boot;
  const S = { mine: false, q: '', page: 1, data: BOOT.history };
  const host = $('#hist');
  const pager = $('#pager');

  function draw() {
    const d = S.data || { rows: [], pages: 1, page: 1, total: 0 };
    if (!d.rows.length) {
      host.innerHTML = `<li class="fsa-hist__empty">${S.q || S.mine ? 'No searches like that.' : 'No searches yet — they show here once the team starts looking.'}</li>`;
    } else {
      host.innerHTML = d.rows.map((r) => `<li>
        <span class="fsa-hist__icon">${icon(r.legacy ? 'alert' : (r.ai ? 'i-sparkle' : 'i-search'))}</span>
        <span class="fsa-hist__text"><a href="/suppliers_ai/?search=${r.id}">“${esc(r.text)}”</a>
          <span class="fsa-hist__meta"><span>${esc(r.mine ? 'You' : (r.who || 'Someone'))} · <span data-ago="${esc(r.at)}"></span></span>
            ${r.location ? `<span>${icon('pin', 'i--xs')} ${esc(r.location)}</span>` : ''}
            <span>${r.results} found</span>
            ${r.actions ? `<span>${F.plural(r.actions, 'contact', 'contacts')}</span>` : ''}
            ${r.requests ? `<span class="fsa-flag fsa-flag--gold">${icon('isa-quote')}${F.plural(r.requests, 'request', 'requests')}</span>` : ''}
            ${r.external ? `<span class="fsa-flag">${icon('i-globe')}${r.ai ? 'Google & AI' : 'Google'}</span>` : ''}
            ${r.legacy ? `<span class="fsa-flag fsa-flag--warn" title="The old Suppliers AI asked the AI for phone numbers and emails — many were made up">${icon('alert')}Old search — check details</span>` : ''}</span></span>
        <span class="fsa-hist__acts"><a class="fsa-mini" href="/suppliers_ai/?search=${r.id}">${icon('eye')}Open</a>${BOOT.caps.search ? `<a class="fsa-mini" href="/suppliers_ai/?go=1&q=${encodeURIComponent(r.text)}">${icon('refresh')}Search again</a>` : ''}</span>
      </li>`).join('');
    }
    pager.innerHTML = d.pages > 1 ? `<button class="btn btn--sm" type="button" data-p="${d.page - 1}"${d.page <= 1 ? ' disabled' : ''}>${icon('chev-left')}Newer</button>
      <span>Page ${d.page} of ${d.pages} · ${d.total} searches</span>
      <button class="btn btn--sm" type="button" data-p="${d.page + 1}"${d.page >= d.pages ? ' disabled' : ''}>Older${icon('chev-right')}</button>` : (d.total ? `<span>${F.plural(d.total, 'search', 'searches')}</span>` : '');
    F.tickAgo();
  }
  let seq = 0;
  async function load() {
    const mine = ++seq; // typing on: only the answer to the latest filter is drawn
    try {
      const data = await api.get('search.php', { op: 'history', mine: S.mine ? 1 : '', q: S.q, page: S.page });
      if (mine !== seq) return;
      S.data = data;
      draw();
    } catch (e) { if (mine === seq) toastError(e); }
  }
  $$('[data-mine]').forEach((b) => b.addEventListener('click', () => {
    S.mine = b.dataset.mine === '1';
    S.page = 1;
    $$('[data-mine]').forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', String(x === b)); });
    load();
  }));
  $('#histFilter').addEventListener('input', F.debounce((e) => { S.q = e.target.value.trim(); S.page = 1; load(); }, 250));
  pager.addEventListener('click', (e) => { const b = e.target.closest('[data-p]'); if (b && !b.disabled) { S.page = +b.dataset.p; load(); const sc = $('#scroll'); if (sc && sc.scrollTo) sc.scrollTo({ top: 0, behavior: 'smooth' }); } });
  draw();
})();
