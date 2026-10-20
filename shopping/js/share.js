// /shopping/js/share.js — the list behind a shared link: tick off (with the
// name typed once, kept on this phone), add when allowed, and stay in step
// with everyone else (the change stamp is polled every 6 s).
(function () {
  'use strict';
  const { $, esc, icon, toast, toastError, ring, unitText, catOf, debounce, boot } = window.FWS;
  const token = boot.token;
  let V = boot.view;
  const csrf = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const nameIn = $('#guestName');
  try { if (nameIn) nameIn.value = localStorage.getItem('fws-guest-name') || ''; } catch (e) { /* fine */ }
  if (nameIn) nameIn.addEventListener('input', debounce(() => { try { localStorage.setItem('fws-guest-name', nameIn.value.trim()); } catch (e) { /* fine */ } }, 300));

  async function call(method, body) {
    let res;
    try {
      res = method === 'GET'
        ? await fetch('/shopping/api/share.php?' + new URLSearchParams({ t: token, ...body }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        : await fetch('/shopping/api/share.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ t: token, name: nameIn ? nameIn.value.trim() : '', ...body }) });
    } catch (e) { throw new Error('No signal — try again in a moment.'); }
    let d = null;
    try { d = await res.json(); } catch (e) { d = null; }
    if (res.status === 419) throw new Error('This page is old — reload it and try again.');
    if (!res.ok || !d || d.ok === false) throw new Error((d && d.error) || 'Something went wrong.');
    return d;
  }

  function render() {
    const items = V.items;
    const left = items.filter((i) => !i.bought);
    const done = items.filter((i) => i.bought);
    $('#trolley').innerHTML = `${ring(done.length, items.length, { cls: 'fws-ring--lg', label: done.length + '/' + items.length })}
      <div class="fws-trolley__text"><b>${left.length ? left.length + ' still to find' : 'Everything is in the trolley'}</b><small>${done.length} in the trolley</small></div>`;
    const row = (it) => {
      const c = catOf(it.category);
      return `<div class="fws-item${it.bought ? ' is-bought' : ''}${it.priority === 'high' && !it.bought ? ' is-urgent' : ''}" data-id="${it.id}" style="--c:${c.color}">
        <button class="fws-item__check" type="button" aria-pressed="${it.bought}" aria-label="${it.bought ? 'Take ' + esc(it.name) + ' out of the trolley' : 'Put ' + esc(it.name) + ' in the trolley'}"${V.can_check ? '' : ' disabled'}>${icon('check')}</button>
        <div class="fws-item__main"><div class="fws-item__name"><span class="fws-item__qty">${esc(unitText(it.qty_text, it.unit))}</span><span class="fws-item__label">${esc(it.name)}</span></div>
          ${it.notes || it.priority === 'high' || it.bought_by || it.store ? `<div class="fws-item__meta">${it.priority === 'high' && !it.bought ? `<span class="fws-flag fws-flag--danger">${icon('is-flame', 'i--xs')}Urgent</span>` : ''}${it.store && !it.bought ? `<span>${icon('is-store', 'i--xs')}${esc(it.store)}</span>` : ''}${it.notes ? `<span class="fws-item__note">${esc(it.notes)}</span>` : ''}${it.bought && it.bought_by ? `<span>${esc(it.bought_by)}</span>` : ''}</div>` : ''}
        </div></div>`;
    };
    let html = '';
    const by = {};
    left.forEach((i) => { (by[i.category] = by[i.category] || []).push(i); });
    Object.keys(boot.categories).filter((k) => by[k]).forEach((k) => {
      const c = catOf(k);
      html += `<section class="fws-group" style="--c:${c.color}"><h3 class="fws-group__head">${icon(c.icon, 'i--sm')}<span>${esc(c.label)}</span><small>${by[k].length}</small></h3><div class="fws-group__items">${by[k].map(row).join('')}</div></section>`;
    });
    if (!left.length && items.length) html += `<div class="fws-allin">${icon('is-trolley')}<div><b>Everything is in the trolley</b><small>Thank you!</small></div></div>`;
    if (done.length) html += `<section class="fws-group fws-group--trolley"><h3 class="fws-group__head">${icon('is-trolley', 'i--sm')}<span>In the trolley</span><small>${done.length}</small></h3><div class="fws-group__items">${done.map(row).join('')}</div></section>`;
    if (!items.length) html = `<div class="fws-blank">${icon('is-list')}<h3>Nothing on the list yet</h3></div>`;
    $('#groups').innerHTML = html;
  }

  $('#groups').addEventListener('click', async (e) => {
    const rowEl = e.target.closest('.fws-item');
    if (!rowEl || !V.can_check) return;
    const id = +rowEl.dataset.id;
    const it = V.items.find((i) => i.id === id);
    if (!it) return;
    if (nameIn && !nameIn.value.trim()) { toast('Type your name first, so they know who ticked it.'); nameIn.focus(); return; }
    it.bought = !it.bought;
    if (navigator.vibrate) { try { navigator.vibrate(12); } catch (er) { /* no buzz */ } }
    render();
    try { V = await call('POST', { op: 'toggle', id, bought: it.bought ? 1 : 0 }); render(); } catch (er) { it.bought = !it.bought; render(); toastError(er); }
  });

  const form = $('#addForm');
  if (form) form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const t = $('#addText').value.trim();
    if (!t) return;
    try { V = await call('POST', { op: 'add', text: t }); $('#addText').value = ''; render(); toast('Added.'); } catch (er) { toastError(er); }
  });
  if (form) $('#addText').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });

  setInterval(async () => {
    if (document.visibilityState !== 'visible') return;
    try {
      const v = await call('GET', { op: 'version' });
      if (v.version !== V.version) { V = await call('GET', { op: 'get' }); render(); }
    } catch (e) { /* next time */ }
  }, 6000);

  render();
})();
