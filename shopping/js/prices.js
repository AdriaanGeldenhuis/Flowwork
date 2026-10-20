// /shopping/js/prices.js — the price book (find, sort) and the stores (add,
// change, delete; link to a CRM supplier; aisles they are good for).
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, busy, money, fmtDay, ago, modal, openModal, closeModal, dialog, catOf, boot } = window.FWS;
  let book = boot.book || [];
  let stores = boot.stores || [];
  let sort = 'recent';

  // ------------------------------------------------------------------ tabs
  function tab(name) {
    $$('[data-tab]').forEach((b) => { b.classList.toggle('is-on', b.dataset.tab === name); b.setAttribute('aria-selected', String(b.dataset.tab === name)); });
    $('#tabBook').hidden = name !== 'book';
    $('#tabStores').hidden = name !== 'stores';
    history.replaceState(null, '', name === 'stores' ? '#stores' : location.pathname);
  }
  $$('[data-tab]').forEach((b) => b.addEventListener('click', () => tab(b.dataset.tab)));

  // ------------------------------------------------------------------ book
  function drawBook() {
    const q = $('#bookQ').value.trim().toLowerCase();
    let rows = book.filter((b) => !q || b.name.toLowerCase().includes(q) || String(b.last_store || '').toLowerCase().includes(q));
    if (sort === 'up') rows = rows.filter((b) => b.trend !== 0).sort((a, b) => b.change_pct - a.change_pct);
    if (sort === 'az') rows = rows.slice().sort((a, b) => a.name.localeCompare(b.name));
    const unit = (u) => (u === 'ea' ? 'each' : 'a ' + u);
    $('#book').innerHTML = rows.length ? `<thead><tr><th>Item</th><th class="is-r">Last price</th><th class="is-hide-narrow">Where</th><th class="is-r is-hide-narrow">Best</th><th class="is-r is-hide-narrow">Bought</th></tr></thead>
      <tbody>${rows.map((b) => `<tr>
        <td><b>${esc(b.name)}</b><small>${esc(unit(b.unit))}</small></td>
        <td class="is-r"><b class="fws-num">${esc(money(b.last))}</b>${b.trend ? `<small><span class="fws-trend ${b.trend > 0 ? 'is-up' : 'is-down'}">${b.trend > 0 ? '▲' : '▼'} ${Math.abs(b.change_pct)}%</span> from ${esc(money(b.prev))}</small>` : `<small>${esc(fmtDay(b.last_at))}</small>`}</td>
        <td class="is-hide-narrow">${esc(b.last_store || '—')}<small>${esc(ago(b.last_at))}</small></td>
        <td class="is-r is-hide-narrow"><span class="fws-num">${esc(money(b.best))}</span>${b.best < b.last && b.best_store ? `<small>at ${esc(b.best_store)}</small>` : ''}</td>
        <td class="is-r is-hide-narrow">${b.n}×${b.stores > 1 ? `<small>${b.stores} stores</small>` : ''}</td></tr>`).join('')}</tbody>`
      : `<tbody><tr><td class="fws-panel__empty">${book.length ? (sort === 'up' ? 'No price has changed yet.' : 'Nothing matches.') : 'Nothing in the price book yet. In the shop, tick things off and say what they cost.'}</td></tr></tbody>`;
  }
  $('#bookQ').addEventListener('input', drawBook);
  $$('[data-sort]').forEach((b) => b.addEventListener('click', () => {
    sort = b.dataset.sort;
    $$('[data-sort]').forEach((x) => x.classList.toggle('is-on', x === b));
    drawBook();
  }));

  // ------------------------------------------------------------------ stores
  const mapsUrl = (s) => 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(s.name + ' ' + (s.address || boot.town || ''));
  function drawStores() {
    const host = $('#stores');
    if (!stores.length) {
      host.innerHTML = `<div class="fws-cards__empty"><span class="fws-cards__seal">${icon('is-store')}</span><div><h3>No stores yet</h3><p>Add the shops and suppliers you buy from — or pick one when you start shopping. A store linked to a CRM supplier keeps its phone number in step.</p></div></div>`;
      return;
    }
    host.innerHTML = stores.map((s) => `<article class="fws-store">
      <div class="fws-store__top">${s.preferred ? `<span class="fws-store__star" title="Preferred">${icon('star', 'i--sm')}</span>` : ''}<h3>${esc(s.name)}</h3></div>
      ${s.address ? `<small>${esc(s.address)}</small>` : ''}
      ${s.crm_name ? `<small>${icon('building', 'i--xs')} ${esc(s.crm_name)} in the CRM</small>` : ''}
      ${s.categories.length ? `<div class="fws-store__cats">${s.categories.map((c) => `<span class="fws-chip" style="--c:${catOf(c).color}">${icon(catOf(c).icon, 'i--xs')}${esc(catOf(c).label)}</span>`).join('')}</div>` : ''}
      <div class="fws-store__foot">
        <span class="fws-num">${s.bought ? `${s.bought} bought · ${esc(money(s.spent, { round: true }))}` : 'Nothing bought here yet'}</span>
        ${s.phone ? `<a class="icon-btn icon-btn--sm" href="tel:${esc(s.phone.replace(/[^\d+]/g, ''))}" aria-label="Call ${esc(s.name)}" data-tip="Call">${icon('phone', 'i--sm')}</a>` : ''}
        <a class="icon-btn icon-btn--sm" href="${esc(mapsUrl(s))}" target="_blank" rel="noopener" aria-label="${esc(s.name)} on the map" data-tip="Map">${icon('is-map', 'i--sm')}</a>
        ${boot.caps.write ? `<button class="icon-btn icon-btn--sm" type="button" data-store-edit="${s.id}" aria-label="Change ${esc(s.name)}" data-tip="Change">${icon('pen', 'i--sm')}</button>` : ''}
      </div></article>`).join('');
  }
  function editStore(s) {
    const cats = Object.keys(boot.categories || {});
    const m = modal(`<div class="fwm-dialog__head"><h2>${s ? esc(s.name) : 'A store'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fws-form" novalidate>
        <div class="field"><label class="label" for="sName">Name</label><input class="input" id="sName" maxlength="190" value="${esc(s ? s.name : '')}" placeholder="Builders Edenvale, Spar Main Road…" autocomplete="off"></div>
        <div class="row">
          <div class="field"><label class="label" for="sPhone">Phone</label><input class="input" id="sPhone" type="tel" maxlength="40" value="${esc(s ? s.phone : '')}" autocomplete="off"></div>
          <div class="field"><label class="label" for="sEmail">Email</label><input class="input" id="sEmail" type="email" maxlength="190" value="${esc(s ? s.email : '')}" autocomplete="off"></div>
        </div>
        <div class="field" style="margin-top:14px"><label class="label" for="sAddr">Address</label><input class="input" id="sAddr" maxlength="255" value="${esc(s ? s.address : '')}" placeholder="For directions" autocomplete="off"></div>
        <div class="row">
          <div class="field"><label class="label" for="sWeb">Website</label><input class="input" id="sWeb" maxlength="255" value="${esc(s ? s.website : '')}" autocomplete="off"></div>
          <div class="field"><label class="label" for="sCrm">CRM supplier</label><select class="select" id="sCrm"><option value="">Not linked</option>${(boot.suppliers || []).map((a) => `<option value="${a.id}"${s && s.crm_id === a.id ? ' selected' : ''}>${esc(a.name)}</option>`).join('')}</select></div>
        </div>
        <div class="field" style="margin-top:14px"><span class="label">Good for</span><div class="fws-catpick">${cats.map((c) => `<label><input type="checkbox" value="${esc(c)}"${s && s.categories.includes(c) ? ' checked' : ''}><span class="fws-chip" style="--c:${catOf(c).color}">${icon(catOf(c).icon, 'i--xs')}${esc(catOf(c).label)}</span></label>`).join('')}</div>
          <span class="hint">“Where to buy” suggests it for things in these aisles.</span></div>
        <label class="switch" style="margin-top:10px"><input type="checkbox" id="sPref"${s && s.preferred ? ' checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Preferred</span><span class="hint">Shown first.</span></span></label>
      </form>
      <div class="fwm-dialog__foot">${s ? `<button class="btn btn--ghost btn--danger" type="button" data-del>${icon('trash', 'i--sm')}Delete</button>` : ''}<span class="grow"></span>
        <button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>Save</button></div>`, { width: 560 });
    $('[data-save]', m).addEventListener('click', async (e) => {
      busy(e.currentTarget, true);
      try {
        const r = await api.post('stores.php', { op: 'save', id: s ? s.id : 0, name: $('#sName', m).value, phone: $('#sPhone', m).value, email: $('#sEmail', m).value, address: $('#sAddr', m).value,
          website: $('#sWeb', m).value, crm_id: $('#sCrm', m).value || 0, preferred: $('#sPref', m).checked ? 1 : 0, categories: $$('.fws-catpick input:checked', m).map((x) => x.value) });
        stores = r.stores;
        closeModal(m);
        drawStores();
        toast('Store saved.');
      } catch (er) { busy(e.currentTarget, false); toastError(er); }
    });
    const del = $('[data-del]', m);
    if (del) del.addEventListener('click', async () => {
      const ok = await dialog({ title: 'Delete this store?', body: `“${esc(s.name)}”. The price book keeps what was bought there, under its name.`, confirm: 'Delete', danger: true });
      if (!ok) return;
      try { stores = (await api.post('stores.php', { op: 'delete', id: s.id })).stores; closeModal(m); drawStores(); toast('Store deleted.'); } catch (er) { toastError(er); }
    });
    openModal(m);
  }
  $('#stores').addEventListener('click', (e) => {
    const b = e.target.closest('[data-store-edit]');
    if (b) editStore(stores.find((s) => s.id === +b.dataset.storeEdit));
  });
  const nb = $('#storeNew');
  if (nb) nb.addEventListener('click', () => editStore(null));

  drawBook();
  drawStores();
  if (location.hash === '#stores') tab('stores');
})();
