// /shopping/js/templates.js — the templates: use one (a new list), make one by
// typing it out, change or delete it.
(function () {
  'use strict';
  const { $, esc, icon, api, toast, toastError, busy, money, modal, openModal, closeModal, dialog, boot } = window.FWS;
  let list = boot.templates || [];
  const host = $('#tpls');

  function render() {
    if (!list.length) {
      host.innerHTML = `<div class="fws-cards__empty"><span class="fws-cards__seal">${icon('template')}</span><div><h3>No templates yet</h3>
        <p>${boot.caps.write ? 'Make one here, or open a list and choose “Save as a template” from its menu.' : 'Nobody has made one yet.'}</p></div></div>`;
      return;
    }
    host.innerHTML = list.map((t, i) => `<article class="fwm-tpl fws-tpl" id="t${t.id}" style="--i:${i}">
      <p class="fws-eyebrow">${icon(t.purpose === 'procurement' ? 'is-crate' : (t.purpose === 'groceries' ? 'is-basket' : 'is-list'), 'i--xs')}${esc(t.purpose_label)}</p>
      <h3>${esc(t.name)}</h3>
      <ul class="fws-tpl__items">${t.preview.map((p) => `<li>${esc(p)}</li>`).join('')}${t.count > t.preview.length ? `<li class="is-more">and ${t.count - t.preview.length} more</li>` : ''}</ul>
      <p class="fws-tpl__meta">${t.count} ${t.count === 1 ? 'item' : 'items'}${t.est ? ' · ≈ ' + esc(money(t.est, { round: true })) : ''}${t.used ? ' · used ' + t.used + (t.used === 1 ? ' time' : ' times') : ''}${t.by ? ' · by ' + esc(t.by) : ''}</p>
      <div class="fwm-tpl__foot">
        ${boot.caps.write ? `<button class="btn btn--primary btn--sm" type="button" data-use="${t.id}">${icon('is-list', 'i--sm')}Start a list</button>` : ''}
        <span class="grow"></span>
        ${t.edit ? `<button class="icon-btn icon-btn--sm" type="button" data-edit="${t.id}" aria-label="Change ${esc(t.name)}" data-tip="Change">${icon('pen', 'i--sm')}</button>
        <button class="icon-btn icon-btn--sm" type="button" data-del="${t.id}" aria-label="Delete ${esc(t.name)}" data-tip="Delete" data-tip-pos="left">${icon('trash', 'i--sm')}</button>` : ''}
      </div>
    </article>`).join('');
  }

  function edit(t) {
    const m = modal(`<div class="fwm-dialog__head"><h2>${t ? 'Change the template' : 'A new template'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body fws-form" novalidate>
        <div class="field"><label class="label" for="tName">Name</label><input class="input" id="tName" maxlength="120" value="${esc(t ? t.name : '')}" placeholder="Monthly office, Site start-up kit…" autocomplete="off"></div>
        <div class="field"><span class="label">What is it for?</span><div class="fws-purpose" role="radiogroup">${Object.entries(boot.purposes).map(([k, l]) => `<label class="fws-purpose__opt"><input type="radio" name="tPurpose" value="${esc(k)}"${k === (t ? t.purpose : (boot.prefs.default_purpose || 'general')) ? ' checked' : ''}><span>${icon(k === 'procurement' ? 'is-crate' : (k === 'groceries' ? 'is-basket' : 'is-list'))}${esc(l)}</span></label>`).join('')}</div></div>
        <div class="field"><label class="label" for="tText">Items — one a line</label><textarea class="textarea" id="tText" rows="9" placeholder="10 bags cement @ R95&#10;2x PVC elbow 20mm&#10;Gloves - 5 pairs">${esc(t ? t.text : '')}</textarea>
          <span class="hint">Read like a typed list: a count first or last, sizes stay in the name, “@ R95” is the price of one, “!” makes it urgent.</span></div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>Save</button></div>`, { width: 580 });
    $('[data-save]', m).addEventListener('click', async (e) => {
      busy(e.currentTarget, true);
      try {
        const r = await api.post('templates.php', { op: 'save', id: t ? t.id : 0, name: $('#tName', m).value, purpose: ($('input[name=tPurpose]:checked', m) || {}).value, text: $('#tText', m).value });
        list = r.templates;
        closeModal(m);
        render();
        const el = $('#t' + r.id);
        if (el) { el.classList.add('is-flash'); el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
        toast(t ? 'Template saved.' : 'Template made.');
      } catch (er) { busy(e.currentTarget, false); toastError(er); }
    });
    openModal(m);
  }

  host.addEventListener('click', async (e) => {
    const use = e.target.closest('[data-use]');
    const ed = e.target.closest('[data-edit]');
    const del = e.target.closest('[data-del]');
    const t = list.find((x) => x.id === +((use || ed || del || {}).dataset || {})[use ? 'use' : (ed ? 'edit' : 'del')]);
    if (!t) return;
    if (use) {
      const d = new Date();
      const name = await dialog({ title: 'Start a list from “' + t.name + '”', body: `${t.count} ${t.count === 1 ? 'item' : 'items'} go on it, with the prices you know.`, input: { label: 'Name', value: t.name + ' · ' + d.getDate() + ' ' + d.toLocaleString('en', { month: 'short' }), max: 120 }, confirm: 'Start the list' });
      if (name === null) return;
      try { location.href = (await api.post('templates.php', { op: 'use', id: t.id, name })).url; } catch (er) { toastError(er); }
    }
    if (ed) edit(t);
    if (del) {
      const ok = await dialog({ title: 'Delete this template?', body: `“${esc(t.name)}”. Lists made from it stay as they are.`, confirm: 'Delete', danger: true });
      if (!ok) return;
      try { list = (await api.post('templates.php', { op: 'delete', id: t.id })).templates; render(); toast('Template deleted.'); } catch (er) { toastError(er); }
    }
  });
  const nb = $('#tplNew');
  if (nb) nb.addEventListener('click', () => edit(null));

  render();
  const h = location.hash.match(/^#t(\d+)$/);
  if (h) { const el = $('#t' + h[1]); if (el) { el.classList.add('is-flash'); el.scrollIntoView({ block: 'center' }); } }
})();
