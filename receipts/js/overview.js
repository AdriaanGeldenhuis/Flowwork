// /receipts/js/overview.js — the overview: the capture card takes drops,
// "Where it went" switches between suppliers and accounts, and the waiting
// list and counts refresh as slips are sent in and read.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, money, fmtDay, ago, uploader, boot } = window.FWR;

  // Suppliers ⇄ accounts
  $$('[data-share]').forEach((b) => b.addEventListener('click', () => {
    $$('[data-share]').forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-selected', String(x === b)); });
    $$('[data-share-list]').forEach((l) => { l.hidden = l.dataset.shareList !== b.dataset.share; });
  }));

  // The capture card lights up under a drag
  const cardEl = $('#captureCard');
  if (cardEl) {
    ['dragenter', 'dragover'].forEach((t) => cardEl.addEventListener(t, () => cardEl.classList.add('is-over')));
    ['dragleave', 'drop'].forEach((t) => cardEl.addEventListener(t, () => cardEl.classList.remove('is-over')));
    cardEl.addEventListener('click', (e) => { if (!e.target.closest('button, a') && uploader.ready) uploader.pick(); });
  }

  const row = (r) => `<li><a href="/receipts/review.php?id=${r.id}">
      <span class="fwr-rows__thumb">${r.thumb ? `<img src="${esc(r.thumb)}" alt="" loading="lazy">` : icon('ir-pdf')}</span>
      <span class="fwr-rows__text"><b>${esc(r.supplier || r.vendor || (r.state === 'reading' ? 'Being read…' : 'Supplier not known yet'))}</b>
        <small>${esc([r.number ? '#' + r.number : '', r.date ? fmtDay(r.date) : '', boot.caps.all ? r.uploader : ''].filter(Boolean).join(' · ') || 'Sent in ' + ago(r.uploaded_at))}</small></span>
      <span class="fwr-rows__side"><span class="fwr-money">${r.amount != null ? esc(money(r.amount)) : ''}</span><span class="fwr-pill" data-tone="${window.FWR.STATE_TONE[r.state] || 'muted'}">${esc(r.state_label)}</span></span></a></li>`;

  const refresh = window.FWR.debounce(async () => {
    try {
      const { overview: ov } = await api.get('receipts.php', { op: 'overview' });
      if (!boot.overview.todo && ov.todo) { location.reload(); return; }
      const list = $('#queueRows');
      if (list) list.innerHTML = ov.queue.map(row).join('');
      const recent = $('#recentRows');
      if (recent) recent.innerHTML = ov.recent.slice(0, 6).map(row).join('');
      const s = $('[data-stat="review"]');
      if (s) s.textContent = ov.review + ov.reading;
      const start = $('[data-start-review]');
      if (start && ov.queue[0]) start.href = '/receipts/review.php?id=' + ov.queue[0].id;
      $$('[data-count="todo"]').forEach((c) => { c.textContent = ov.todo; });
      boot.overview.todo = ov.todo;
    } catch (e) { /* the next event tries again */ }
  }, 600);
  document.addEventListener('fwr:uploaded', refresh);
  document.addEventListener('fwr:read', refresh);
})();
