// /receipts/js/capture.js — the "Snap a receipt" page: the note goes with
// the slips sent in from here, and the list of this person's latest slips
// follows them from read to the books.
(function () {
  'use strict';
  const { $, esc, icon, api, money, fmtDay, ago, uploader, debounce, STATE_TONE } = window.FWR;
  const note = $('#snapNote');
  try { note.value = sessionStorage.getItem('fwr-note') || ''; } catch (e) { /* private mode */ }
  note.addEventListener('input', () => { try { sessionStorage.setItem('fwr-note', note.value); } catch (e) { /* ignore */ } });
  uploader.setNote(() => note.value.trim());

  const list = $('#mine');
  async function load() {
    try {
      const res = await api.get('receipts.php', { op: 'list', view: 'all', mine: 1, sort: 'new' });
      list.innerHTML = res.rows.length ? res.rows.slice(0, 12).map((r) => `<li><a href="/receipts/review.php?id=${r.id}">
          <span class="fwr-rows__thumb">${r.thumb ? `<img src="${esc(r.thumb)}" alt="" loading="lazy">` : icon('ir-pdf')}</span>
          <span class="fwr-rows__text"><b>${esc(r.supplier || r.vendor || r.name)}</b><small>${esc([r.date ? fmtDay(r.date) : '', ago(r.uploaded_at), r.note ? '“' + r.note + '”' : ''].filter(Boolean).join(' · '))}</small></span>
          <span class="fwr-rows__side"><span class="fwr-money">${r.amount != null ? esc(money(r.amount)) : ''}</span><span class="fwr-pill" data-tone="${STATE_TONE[r.state] || 'muted'}">${esc(r.state_label)}</span></span></a></li>`).join('')
        : '<li><div class="fwr-rows__item fwr-muted">Nothing sent in yet — your first slip shows here.</div></li>';
    } catch (e) {
      list.innerHTML = `<li><div class="fwr-rows__item fwr-muted">${esc(e.message)}</div></li>`;
    }
  }
  const later = debounce(load, 500);
  document.addEventListener('fwr:uploaded', later);
  document.addEventListener('fwr:read', later);
  document.addEventListener('fwr:done', () => { note.value = ''; try { sessionStorage.removeItem('fwr-note'); } catch (e) { /* ignore */ } });
  load();
})();
