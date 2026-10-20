// /receipts/js/settings.js — the learning memory on the settings page:
// printed names → suppliers, suppliers → accounts, words → accounts, each
// one forgettable (the settings rows themselves are saved by receipts.js).
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, dialog } = window.FWR;
  const body = $('#learnBody');

  function chips(list, render, what, empty) {
    if (!list.length) return `<p class="fwr-memory__empty">${esc(empty)}</p>`;
    return `<ul class="fwr-memory">${list.map((x) => `<li>${render(x)}<button type="button" data-forget="${esc(what(x))}" aria-label="Forget this">${icon('x', 'i--xs')}</button></li>`).join('')}</ul>`;
  }
  function draw(l) {
    body.innerHTML = `
      <div class="fwr-set fwr-set--wide" style="padding-bottom:6px"><div class="fwr-set__text"><span class="fwr-set__label">Printed names → suppliers</span><span class="fwr-set__hint">${l.names.length} remembered</span></div></div>
      ${chips(l.names, (x) => `<span><b>${esc(x.key)}</b> <small>→ ${esc(x.supplier)}${x.hits > 1 ? ' · ' + x.hits + '×' : ''}</small></span>`, (x) => 'name:' + x.key, 'Nothing yet — it learns when you choose the supplier for a slip.')}
      <div class="fwr-set fwr-set--wide" style="padding-bottom:6px"><div class="fwr-set__text"><span class="fwr-set__label">Suppliers → accounts</span><span class="fwr-set__hint">${l.suppliers.length} remembered</span></div></div>
      ${chips(l.suppliers, (x) => `<span><b>${esc(x.supplier)}</b> <small>→ ${esc(x.account)}</small></span>`, (x) => 'supplier:' + x.id, 'Nothing yet.')}
      <div class="fwr-set fwr-set--wide" style="padding-bottom:6px"><div class="fwr-set__text"><span class="fwr-set__label">Words → accounts</span><span class="fwr-set__hint">${l.words.length} remembered — the newest first</span></div></div>
      ${chips(l.words.slice(0, 120), (x) => `<span><b>${esc(x.word)}</b> <small>→ ${esc(x.account)}</small></span>`, (x) => 'word:' + x.word, 'Nothing yet.')}`;
  }
  async function load() {
    try { draw((await api.get('settings.php')).learning); } catch (e) { body.innerHTML = `<p class="fwr-memory__empty">${esc(e.message)}</p>`; }
  }
  body.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-forget]');
    if (!b) return;
    try {
      const res = await api.post('settings.php', { op: 'forget', what: b.dataset.forget });
      draw(res.learning);
      toast('Forgotten');
    } catch (x) { toastError(x); }
  });
  $('#forgetAll').addEventListener('click', async () => {
    if (!await dialog({ title: 'Forget everything?', body: 'Receipts starts learning again from the next bill. Your bills and receipts are not touched.', confirm: 'Forget everything', danger: true })) return;
    try { draw((await api.post('settings.php', { op: 'forget', what: 'all' })).learning); toast('Everything forgotten'); } catch (x) { toastError(x); }
  });
  // Reader choice cards: show which one is picked as you click
  $$('input[name="ocr_provider"]').forEach((r) => r.addEventListener('change', () => { if (window.FWR.settings) window.FWR.settings.refresh(); }));
  load();
})();
