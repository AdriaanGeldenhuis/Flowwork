// /crm/js/crm-forms.js — every CRM editor, shared by the CRM home, the account
// page and the deal page: customer / supplier, contact, address, task, deal
// (quick create and the full deal sheet), activity composer, email, compliance
// document, "why was it lost", convert to project, custom fields and the
// account picker.
(function () {
  'use strict';
  const C = window.CRM;
  const { $, $$, esc, icon, api, toast, toastError, popover, openModal, closeModal, tempModal, dialog, BOOT, L, lower, money, money0, fmtDate, fmtTime, relTime, avatar, statusPill, stageMeta, tagChip, actIcon } = C;

  const fieldsFor = (entity, type) => (BOOT.fields || []).filter((f) => f.entity === entity && !f.archived && (entity !== 'account' || f.applies_to === 'all' || f.applies_to === type));
  const opt = (v, label, sel) => `<option value="${esc(v)}"${String(v) === String(sel) ? ' selected' : ''}>${esc(label)}</option>`;
  const peopleOptions = (sel, blank = '') => (blank ? opt('', blank, sel ?? '') : '') + (BOOT.people || []).map((p) => opt(p.id, p.id === BOOT.me.id ? p.name + ' (me)' : p.name, sel)).join('');
  const req = (on) => (on ? '<span class="req">*</span>' : '');
  const isReq = (k) => (BOOT.config.required || []).includes(k);

  // ------------------------------------------------------ custom fields
  function customFieldsHtml(defs, values = {}) {
    return defs.map((f) => {
      const v = values[f.id];
      const id = 'cf' + f.id;
      const lab = `<label class="label" for="${id}">${esc(f.label)}${req(f.required)}</label>`;
      const help = f.help ? `<span class="hint">${esc(f.help)}</span>` : '';
      let ctl;
      switch (f.type) {
        case 'textarea': ctl = `<textarea class="textarea" id="${id}" data-cf="${f.id}" maxlength="5000" style="min-height:70px">${esc(v || '')}</textarea>`; break;
        case 'number': ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="number" step="any" value="${esc(v || '')}">`; break;
        case 'money': ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="text" inputmode="decimal" placeholder="R 0.00" value="${esc(v || '')}">`; break;
        case 'date': ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="date" value="${esc(v || '')}">`; break;
        case 'select': ctl = `<select class="select" id="${id}" data-cf="${f.id}">${opt('', '—', v || '')}${f.options.map((o) => opt(o, o, v)).join('')}</select>`; break;
        case 'multiselect': ctl = `<div class="fwc-checks" data-cf="${f.id}" data-multi="1">${f.options.map((o) => `<label class="switch"><input type="checkbox" value="${esc(o)}"${(v || []).includes(o) ? ' checked' : ''}><span class="switch__track"></span><span class="switch__text">${esc(o)}</span></label>`).join('')}</div>`; break;
        case 'checkbox': return `<div class="field"><label class="switch"><input type="checkbox" id="${id}" data-cf="${f.id}"${v ? ' checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">${esc(f.label)}</span>${f.help ? `<span class="hint">${esc(f.help)}</span>` : ''}</span></label></div>`;
        case 'url': ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="url" placeholder="https://" value="${esc(v || '')}">`; break;
        case 'email': ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="email" value="${esc(v || '')}">`; break;
        case 'phone': ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="tel" value="${esc(v || '')}">`; break;
        default: ctl = `<input class="input" id="${id}" data-cf="${f.id}" type="text" maxlength="500" value="${esc(v || '')}">`;
      }
      return `<div class="field${f.type === 'textarea' || f.type === 'multiselect' ? ' fwc-span-all' : ''}">${lab}${ctl}${help}</div>`;
    }).join('');
  }
  function readCustom(root) {
    const out = {};
    $$('[data-cf]', root).forEach((el) => {
      const id = el.dataset.cf;
      if (el.dataset.multi) out[id] = $$('input:checked', el).map((x) => x.value);
      else if (el.type === 'checkbox') out[id] = el.checked;
      else out[id] = el.value;
    });
    return out;
  }
  function displayCustom(f, v) {
    if (f.type === 'checkbox') return v ? 'Yes' : 'No';
    if (f.type === 'multiselect') return (v || []).join(', ');
    if (f.type === 'money' && v !== '' && v != null) return money(v);
    if (f.type === 'date' && v) return fmtDate(v, 'always');
    if (f.type === 'url' && v) return `<a href="${esc(v)}" target="_blank" rel="noopener">${esc(v.replace(/^https?:\/\//, ''))}</a>`;
    if (f.type === 'email' && v) return `<a href="mailto:${esc(v)}">${esc(v)}</a>`;
    if (f.type === 'phone' && v) return `<a href="${C.telHref(v)}">${esc(v)}</a>`;
    return esc(v ?? '');
  }

  // ---------------------------------------------------- account picker
  /** An input that finds an account; calls onPick({id, name, type}). */
  function accountPicker(input, onPick, { type = null } = {}) {
    let seq = 0;
    const run = C.debounce(async () => {
      const q = input.value.trim();
      if (q.length < 1) { C.closePop(); return; }
      const my = ++seq;
      let d;
      try { d = await api.get('search.php', { q }); } catch (e) { return; }
      if (my !== seq || document.activeElement !== input) return;
      const rows = d.accounts.filter((a) => !type || a.type === type);
      popover(input, rows.length ? rows.map((a) => `<button class="fwm-pop__item" type="button" data-v="${a.id}" data-name="${esc(a.name)}" data-type="${a.type}">${avatar(a.name, 'fwc-ava--sm')}<span class="fwc-ellip">${esc(a.name)}</span><small>${esc(L(a.type))}</small></button>`).join('')
        : `<div class="fwm-pop__item" style="color:var(--ink-3)">No match — create the account first</div>`,
        { noFocus: true, width: Math.max(260, input.offsetWidth), onClick: (b) => { input.value = b.dataset.name; onPick({ id: +b.dataset.v, name: b.dataset.name, type: b.dataset.type }); } });
    }, 180);
    input.addEventListener('input', () => { onPick(null); run(); });
    input.addEventListener('focus', () => { if (input.value) run(); });
  }

  // -------------------------------------------------- busy button helper
  async function busy(btn, fn) {
    if (!btn) return fn();
    btn.classList.add('is-busy');
    try { return await fn(); } finally { btn.classList.remove('is-busy'); }
  }
  function errorIn(m, e) {
    let box = $('.fwc-err', m);
    if (!box) { box = document.createElement('div'); box.className = 'fwc-err'; ($('.fwm-dialog__body', m) || m).appendChild(box); }
    box.innerHTML = `${icon('alert', 'i--sm')}<span>${esc(e.message || String(e))}</span>`;
    box.scrollIntoView({ block: 'nearest' });
  }

  // ======================================================= account editor
  /** opts: {type, account (full row when editing), onSaved(res)} */
  function account(opts = {}) {
    if (!BOOT.can_write) return;
    const a = opts.account || null;
    const type = a ? a.type : (opts.type || 'customer');
    const editing = !!a;
    const v = a || { status: BOOT.rules.default_status[type] || 'active', owner_id: BOOT.me.id, preferred: false, currency: BOOT.rules.default_currency || '' };
    const st = BOOT.config.statuses;
    const lk = BOOT.lookups;
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>${editing ? 'Edit ' + esc(v.name) : 'New ' + esc(lower(type))}</h2>
        ${!editing && BOOT.config.modules.customers && BOOT.config.modules.suppliers ? `<div class="seg" role="tablist"><button type="button" data-type="customer" class="${type === 'customer' ? 'is-on' : ''}">${esc(L('customer'))}</button><button type="button" data-type="supplier" class="${type === 'supplier' ? 'is-on' : ''}">${esc(L('supplier'))}</button></div>` : ''}
        <button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body scroll" novalidate>
        <div class="fwc-form-sec"><h4>${icon('building')}Company</h4>
          <div class="fwc-grid2">
            <div class="field fwc-span2"><label class="label" for="aName">Name${req(true)}</label><input class="input" id="aName" name="name" maxlength="255" required value="${esc(v.name || '')}" placeholder="Trading name" autofocus></div>
            <div class="field"><label class="label" for="aLegal">Legal name</label><input class="input" id="aLegal" name="legal_name" maxlength="255" value="${esc(v.legal_name || '')}" placeholder="Registered name, if different"></div>
            <div class="field"><label class="label" for="aStatus">Status</label><select class="select" id="aStatus" name="status">${Object.entries(st).map(([k, s]) => opt(k, s.label, v.status)).join('')}</select></div>
            <div class="field"><label class="label" for="aOwner">Owner${req(isReq('owner_id'))}</label><select class="select" id="aOwner" name="owner_id">${peopleOptions(v.owner_id, 'Nobody')}</select></div>
            <div class="field" style="justify-content:flex-end"><label class="switch"><input type="checkbox" name="preferred"${v.preferred ? ' checked' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Preferred</span><span class="hint">Shown first in lists</span></span></label></div>
          </div></div>
        <div class="fwc-form-sec"><h4>${icon('phone')}How to reach them</h4>
          <div class="fwc-grid3">
            <div class="field"><label class="label" for="aEmail">Email${req(isReq('email'))}</label><input class="input" id="aEmail" name="email" type="email" maxlength="190" value="${esc(v.email || '')}" placeholder="accounts@…"></div>
            <div class="field"><label class="label" for="aPhone">Phone${req(isReq('phone'))}</label><input class="input" id="aPhone" name="phone" type="tel" maxlength="64" value="${esc(v.phone || '')}" placeholder="011 555 0100"></div>
            <div class="field"><label class="label" for="aWeb">Website</label><input class="input" id="aWeb" name="website" maxlength="255" value="${esc(v.website || '')}" placeholder="example.co.za"></div>
          </div></div>
        <div class="fwc-form-sec"><h4>${icon('file-text')}Registration &amp; classification</h4>
          <div class="fwc-grid3">
            <div class="field"><label class="label" for="aVat">VAT number${req(isReq('vat_no'))}</label><input class="input" id="aVat" name="vat_no" maxlength="64" value="${esc(v.vat_no || '')}" placeholder="4…"></div>
            <div class="field"><label class="label" for="aReg">Registration no.${req(isReq('reg_no'))}</label><input class="input" id="aReg" name="reg_no" maxlength="64" value="${esc(v.reg_no || '')}" placeholder="2015/123456/07"></div>
            <div class="field"><label class="label" for="aCur">Billing currency</label><input class="input" id="aCur" name="currency" maxlength="3" value="${esc(v.currency || '')}" placeholder="ZAR" style="text-transform:uppercase"></div>
            <div class="field"><label class="label" for="aInd">Industry${req(isReq('industry_id'))}</label><select class="select" id="aInd" name="industry_id">${opt('', '—', v.industry_id || '')}${lk.industries.map((x) => opt(x.id, x.name, v.industry_id)).join('')}</select></div>
            <div class="field"><label class="label" for="aReg2">Region${req(isReq('region_id'))}</label><select class="select" id="aReg2" name="region_id">${opt('', '—', v.region_id || '')}${lk.regions.map((x) => opt(x.id, x.name, v.region_id)).join('')}</select></div>
            ${!editing && BOOT.rules.tags ? `<div class="field"><label class="label">Tags</label><div class="fwc-taginput" id="aTags"><input type="text" placeholder="Add a tag" maxlength="64"></div></div>` : ''}
          </div></div>
        ${!editing ? `<div class="fwc-form-sec"><h4>${icon('user')}Main contact person${isReq('contact') ? '<small>Required</small>' : '<small>Optional</small>'}</h4>
          <div class="fwc-grid3">
            <div class="field"><label class="label" for="cFirst">First name</label><input class="input" id="cFirst" name="c_first_name" maxlength="120"></div>
            <div class="field"><label class="label" for="cLast">Last name</label><input class="input" id="cLast" name="c_last_name" maxlength="120"></div>
            <div class="field"><label class="label" for="cRole">Job title</label><input class="input" id="cRole" name="c_role" maxlength="120"></div>
            <div class="field"><label class="label" for="cEmail">Email</label><input class="input" id="cEmail" name="c_email" type="email" maxlength="190"></div>
            <div class="field"><label class="label" for="cMobile">Mobile</label><input class="input" id="cMobile" name="c_mobile" type="tel" maxlength="64"></div>
          </div></div>
        <div class="fwc-form-sec"><h4>${icon('ic-visit')}Address<small>Optional</small></h4>
          <div class="fwc-grid3">
            <div class="field fwc-span2"><label class="label" for="dLine1">Street address</label><input class="input" id="dLine1" name="d_line1" maxlength="255"></div>
            <div class="field"><label class="label" for="dLine2">Suburb / unit</label><input class="input" id="dLine2" name="d_line2" maxlength="255"></div>
            <div class="field"><label class="label" for="dCity">City</label><input class="input" id="dCity" name="d_city" maxlength="120"></div>
            <div class="field"><label class="label" for="dRegion">Province</label><input class="input" id="dRegion" name="d_region" maxlength="120"></div>
            <div class="field"><label class="label" for="dPostal">Postal code</label><input class="input" id="dPostal" name="d_postal_code" maxlength="20"></div>
          </div></div>` : ''}
        <div class="fwc-form-sec" id="aCustomSec"${fieldsFor('account', type).length ? '' : ' hidden'}><h4>${icon('ic-field')}More details</h4>
          <div class="fwc-grid2" id="aCustom">${customFieldsHtml(fieldsFor('account', type), (a && a.custom_values) || {})}</div></div>
        <div class="fwc-form-sec"><h4>${icon('ic-note')}Notes</h4>
          <div class="field"><textarea class="textarea" name="notes" maxlength="10000" placeholder="Anything worth knowing — how they like to work, payment habits, history…">${esc(v.notes || '')}</textarea></div></div>
        <div id="aDupes"></div>
      </form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button>
        <button class="btn btn--primary" type="button" data-save>${icon('check')}${editing ? 'Save changes' : 'Create ' + esc(lower(type))}</button></div>`, 'fwc-dialog');
    let curType = type;
    const tags = [];
    const tagHost = $('#aTags', m);
    if (tagHost) {
      const ti = $('input', tagHost);
      const redraw = () => { $$('.fwc-tag', tagHost).forEach((x) => x.remove()); tags.forEach((t, i) => ti.insertAdjacentHTML('beforebegin', `<span class="fwc-tag" style="--c:#b08d57"><span>${esc(t)}</span><button type="button" data-rm="${i}" aria-label="Remove">${icon('x', 'i--xs')}</button></span>`)); };
      const add = (t) => { t = t.trim(); if (t && !tags.some((x) => x.toLowerCase() === t.toLowerCase())) { tags.push(t); redraw(); } };
      ti.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(ti.value); ti.value = ''; C.closePop(); } else if (e.key === 'Backspace' && !ti.value && tags.length) { tags.pop(); redraw(); } });
      ti.addEventListener('input', () => {
        const q = ti.value.trim().toLowerCase();
        const hits = (BOOT.tags || []).filter((t) => t.name.toLowerCase().includes(q) && !tags.includes(t.name)).slice(0, 8);
        if (!q || !hits.length) { C.closePop(); return; }
        popover(ti, hits.map((t) => `<button class="fwm-pop__item" type="button" data-v="${esc(t.name)}">${tagChip(t)}</button>`).join(''), { noFocus: true, onClick: (b) => { add(b.dataset.v); ti.value = ''; ti.focus(); } });
      });
      tagHost.addEventListener('click', (e) => { const r = e.target.closest('[data-rm]'); if (r) { tags.splice(+r.dataset.rm, 1); redraw(); } else ti.focus(); });
    }
    $$('[data-type]', m).forEach((b) => b.addEventListener('click', () => {
      curType = b.dataset.type;
      $$('[data-type]', m).forEach((x) => x.classList.toggle('is-on', x === b));
      $('h2', m).textContent = 'New ' + lower(curType);
      $('[data-save]', m).innerHTML = icon('check') + 'Create ' + esc(lower(curType));
      const defs = fieldsFor('account', curType);
      $('#aCustom', m).innerHTML = customFieldsHtml(defs, {});
      $('#aCustomSec', m).hidden = !defs.length;
      const s = $('#aStatus', m);
      if (s) s.value = BOOT.rules.default_status[curType] || 'active';
    }));
    const form = $('form', m);
    const collect = (confirm) => {
      const f = new FormData(form);
      const g = (k) => (f.get(k) || '').toString();
      const body = { op: 'save', name: g('name'), legal_name: g('legal_name'), status: g('status'), owner_id: g('owner_id'), preferred: f.has('preferred') ? 1 : 0,
        email: g('email'), phone: g('phone'), website: g('website'), vat_no: g('vat_no'), reg_no: g('reg_no'), currency: g('currency'),
        industry_id: g('industry_id'), region_id: g('region_id'), notes: g('notes'), custom: readCustom($('#aCustom', m)) };
      if (editing) body.id = a.id;
      else {
        body.type = curType;
        body.tags = tags.slice();
        body.contact = { first_name: g('c_first_name'), last_name: g('c_last_name'), role: g('c_role'), email: g('c_email'), mobile: g('c_mobile') };
        body.address = { line1: g('d_line1'), line2: g('d_line2'), city: g('d_city'), region: g('d_region'), postal_code: g('d_postal_code'), type: curType === 'customer' ? 'billing' : 'head_office' };
        if (confirm) body.confirm_duplicate = 1;
      }
      return body;
    };
    let dirty = false;
    form.addEventListener('input', () => { dirty = true; });
    m._guard = () => !dirty || confirm('Discard your changes?');
    const save = (confirmDup) => busy($('[data-save]', m), async () => {
      const body = collect(confirmDup);
      if (!body.name.trim()) { errorIn(m, new Error('Give it a name.')); $('#aName', m).focus(); return; }
      try {
        const d = await api.post('accounts.php', body);
        if (d.duplicates) {
          $('#aDupes', m).innerHTML = `<div class="fwc-dupes"><b>${icon('alert', 'i--sm')} This looks like an account you already have</b>
            ${d.duplicates.map((x) => `<a href="${C.accountUrl(x.id)}" target="_blank" rel="noopener">${avatar(x.name, 'fwc-ava--sm')}${esc(x.name)} <small>${esc(x.why.join(', '))}</small></a>`).join('')}
            <button class="btn btn--sm" type="button" data-anyway style="margin-top:8px">Create it anyway</button></div>`;
          $('[data-anyway]', m).addEventListener('click', () => save(true));
          $('#aDupes', m).scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          return;
        }
        dirty = false;
        closeModal(m);
        toast(editing ? 'Saved' : `${L(curType)} created`, editing ? {} : { action: { label: 'Open', run: () => { location.href = C.accountUrl(d.id); } } });
        if (opts.onSaved) opts.onSaved(d);
        else if (!editing) location.href = C.accountUrl(d.id);
      } catch (e) { errorIn(m, e); }
    });
    $('[data-save]', m).addEventListener('click', () => save(false));
    form.addEventListener('submit', (e) => { e.preventDefault(); save(false); });
    form.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') save(false); });
    openModal(m);
  }

  // ========================================================= contact form
  function contact(opts = {}) {
    const c = opts.contact || {};
    const editing = !!c.id;
    const defs = fieldsFor('contact');
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>${editing ? 'Edit ' + esc(c.name) : 'New ' + esc(lower('contact'))}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body scroll" novalidate>
        <div class="fwc-grid2">
          <div class="field"><label class="label" for="ctF">First name</label><input class="input" id="ctF" name="first_name" maxlength="120" value="${esc(c.first_name || '')}" autofocus></div>
          <div class="field"><label class="label" for="ctL">Last name</label><input class="input" id="ctL" name="last_name" maxlength="120" value="${esc(c.last_name || '')}"></div>
          <div class="field fwc-span2"><label class="label" for="ctR">Job title</label><input class="input" id="ctR" name="role" maxlength="120" value="${esc(c.role || '')}" placeholder="Procurement manager, Site agent…"></div>
          <div class="field fwc-span2"><label class="label" for="ctE">Email</label><input class="input" id="ctE" name="email" type="email" maxlength="190" value="${esc(c.email || '')}"></div>
          <div class="field"><label class="label" for="ctP">Phone</label><input class="input" id="ctP" name="phone" type="tel" maxlength="64" value="${esc(c.phone || '')}"></div>
          <div class="field"><label class="label" for="ctM">Mobile / WhatsApp</label><input class="input" id="ctM" name="mobile" type="tel" maxlength="64" value="${esc(c.mobile || '')}"></div>
          ${customFieldsHtml(defs, c.custom || {})}
          <div class="field fwc-span-all"><label class="label" for="ctN">Notes</label><textarea class="textarea" id="ctN" name="notes" maxlength="5000" style="min-height:70px">${esc(c.notes || '')}</textarea></div>
          <div class="field fwc-span-all"><label class="switch"><input type="checkbox" name="primary"${c.primary ? ' checked disabled' : ''}><span class="switch__track"></span><span class="switch__text"><span class="switch__title">Main contact</span><span class="hint">Gets quotes, invoices and emails by default</span></span></label></div>
        </div></form>
      <div class="fwm-dialog__foot">${editing ? `<button class="btn btn--ghost btn--danger" type="button" data-del>${icon('trash')}Delete</button>` : ''}<span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>${icon('check')}Save</button></div>`, 'fwc-dialog fwc-dialog--sm');
    const form = $('form', m);
    const save = () => busy($('[data-save]', m), async () => {
      const f = new FormData(form);
      const body = { op: 'save', account_id: opts.account_id, first_name: f.get('first_name'), last_name: f.get('last_name'), role: f.get('role'), email: f.get('email'),
        phone: f.get('phone'), mobile: f.get('mobile'), notes: f.get('notes'), custom: readCustom(form) };
      if (f.has('primary') || c.primary) body.primary = 1;
      if (editing) body.id = c.id;
      try {
        const d = await api.post('contacts.php', body);
        closeModal(m);
        toast(editing ? 'Contact saved' : 'Contact added');
        if (opts.onSaved) opts.onSaved(d.contact);
      } catch (e) { errorIn(m, e); }
    });
    $('[data-save]', m).addEventListener('click', save);
    form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    const del = $('[data-del]', m);
    if (del) del.addEventListener('click', async () => {
      if (!(await dialog({ title: 'Delete ' + c.name + '?', body: 'Quotes, invoices and activity keep working; they just no longer point at this person.', confirm: 'Delete', danger: true }))) return;
      try { await api.post('contacts.php', { op: 'delete', id: c.id }); closeModal(m); toast('Contact deleted'); if (opts.onSaved) opts.onSaved(null); } catch (e) { toastError(e); }
    });
    openModal(m);
  }

  // ========================================================= address form
  function address(opts = {}) {
    const a = opts.address || {};
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>${a.id ? 'Edit address' : 'Add an address'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate><div class="fwc-grid2">
        <div class="field fwc-span2"><label class="label" for="adT">Kind</label><select class="select" id="adT" name="type">${Object.entries(BOOT.address_types).map(([k, l]) => opt(k, l, a.type || 'head_office')).join('')}</select></div>
        <div class="field fwc-span2"><label class="label" for="ad1">Street address${req(true)}</label><input class="input" id="ad1" name="line1" maxlength="255" value="${esc(a.line1 || '')}" autofocus></div>
        <div class="field fwc-span2"><label class="label" for="ad2">Suburb / unit</label><input class="input" id="ad2" name="line2" maxlength="255" value="${esc(a.line2 || '')}"></div>
        <div class="field"><label class="label" for="adC">City</label><input class="input" id="adC" name="city" maxlength="120" value="${esc(a.city || '')}"></div>
        <div class="field"><label class="label" for="adR">Province</label><input class="input" id="adR" name="region" maxlength="120" value="${esc(a.region || '')}"></div>
        <div class="field"><label class="label" for="adP">Postal code</label><input class="input" id="adP" name="postal_code" maxlength="20" value="${esc(a.postal_code || '')}"></div>
        <div class="field"><label class="label" for="adK">Country (2 letters)</label><input class="input" id="adK" name="country" maxlength="2" value="${esc(a.country || 'ZA')}" style="text-transform:uppercase"></div>
      </div></form>
      <div class="fwm-dialog__foot">${a.id ? `<button class="btn btn--ghost btn--danger" type="button" data-del>${icon('trash')}Delete</button>` : ''}<span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>${icon('check')}Save</button></div>`, 'fwc-dialog fwc-dialog--sm');
    const form = $('form', m);
    const save = () => busy($('[data-save]', m), async () => {
      const body = Object.fromEntries(new FormData(form).entries());
      Object.assign(body, { op: 'save', account_id: opts.account_id, id: a.id || 0 });
      try { await api.post('addresses.php', body); closeModal(m); toast('Address saved'); if (opts.onSaved) opts.onSaved(); } catch (e) { errorIn(m, e); }
    });
    $('[data-save]', m).addEventListener('click', save);
    form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    const del = $('[data-del]', m);
    if (del) del.addEventListener('click', async () => {
      if (!(await dialog({ title: 'Delete this address?', confirm: 'Delete', danger: true }))) return;
      try { await api.post('addresses.php', { op: 'delete', id: a.id }); closeModal(m); if (opts.onSaved) opts.onSaved(); } catch (e) { toastError(e); }
    });
    openModal(m);
  }

  // ============================================================ task form
  const REMIND = [['none', 'No reminder'], ['at', 'At the time'], ['15m', '15 minutes before'], ['1h', '1 hour before'], ['1d', 'The day before']];
  /** opts: {task, account: {id, name}, deal: {id, title}, onSaved(task), title, due_date} */
  function task(opts = {}) {
    if (!BOOT.can_write) return;
    const t = opts.task || null;
    const due = t && t.due_at ? t.due_at : null;
    const acc = (t && t.account) || opts.account || null;
    const deal = (t && t.deal) || opts.deal || null;
    const tomorrow = C.ymd(new Date(Date.now() + 86400000));
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>${t ? 'Edit ' + esc(lower('task')) : 'New ' + esc(lower('task'))}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate><div class="fwc-grid2">
        <div class="field fwc-span2"><label class="label" for="tkT">What needs doing${req(true)}</label><input class="input" id="tkT" name="title" maxlength="190" value="${esc((t && t.title) || opts.title || '')}" placeholder="Call back about the quote" autofocus></div>
        <div class="field"><label class="label" for="tkD">Due</label><input class="input" id="tkD" name="due_date" type="date" value="${esc(due ? due.slice(0, 10) : (t ? '' : (opts.due_date || tomorrow)))}"></div>
        <div class="field"><label class="label" for="tkH">Time <span class="fwc-muted" style="text-transform:none;letter-spacing:0">(optional)</span></label><input class="input" id="tkH" name="due_time" type="time" value="${esc(due && t && !t.all_day ? due.slice(11, 16) : '')}"></div>
        <div class="field"><label class="label" for="tkA">Assigned to</label><select class="select" id="tkA" name="assigned_to">${peopleOptions((t && t.assigned_to) || BOOT.me.id)}</select></div>
        <div class="field"><label class="label" for="tkR">Remind</label><select class="select" id="tkR" name="remind">${REMIND.map(([k, l]) => opt(k, l, (t && t.remind) || 'at')).join('')}</select></div>
        <div class="field"><label class="label" for="tkP">Priority</label><select class="select" id="tkP" name="priority">${opt('low', 'Low', (t && t.priority) || 'normal')}${opt('normal', 'Normal', (t && t.priority) || 'normal')}${opt('high', 'High', (t && t.priority) || 'normal')}</select></div>
        <div class="field"><label class="label" for="tkAcc">For</label><input class="input" id="tkAcc" value="${esc(acc ? acc.name : '')}" placeholder="Customer or supplier (optional)" ${deal ? 'disabled' : ''}>${deal ? `<span class="hint">${icon('i-kanban', 'i--xs')} ${esc(deal.title)}</span>` : ''}</div>
        <div class="field fwc-span2"><label class="label" for="tkN">Notes</label><textarea class="textarea" id="tkN" name="notes" maxlength="5000" style="min-height:70px">${esc((t && t.notes) || '')}</textarea></div>
      </div></form>
      <div class="fwm-dialog__foot">${t ? `<button class="btn btn--ghost btn--danger" type="button" data-del>${icon('trash')}Delete</button>` : ''}<span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>${icon('check')}${t ? 'Save' : 'Add task'}</button></div>`, 'fwc-dialog fwc-dialog--sm');
    let accountId = acc ? acc.id : 0;
    accountPicker($('#tkAcc', m), (a) => { accountId = a ? a.id : 0; });
    const form = $('form', m);
    const save = () => busy($('[data-save]', m), async () => {
      const f = new FormData(form);
      const body = { op: 'save', title: f.get('title'), due_date: f.get('due_date'), due_time: f.get('due_time'), assigned_to: f.get('assigned_to'), remind: f.get('remind'),
        priority: f.get('priority'), notes: f.get('notes'), account_id: accountId || 0 };
      if (deal) body.opportunity_id = deal.id;
      if (t) body.id = t.id;
      if (!$('#tkAcc', m).value.trim()) body.account_id = 0;
      try { const d = await api.post('tasks.php', body); closeModal(m); toast(t ? 'Task saved' : 'Task added'); if (opts.onSaved) opts.onSaved(d.task); } catch (e) { errorIn(m, e); }
    });
    $('[data-save]', m).addEventListener('click', save);
    form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    const del = $('[data-del]', m);
    if (del) del.addEventListener('click', async () => {
      if (!(await dialog({ title: 'Delete this task?', confirm: 'Delete', danger: true }))) return;
      try { await api.post('tasks.php', { op: 'delete', id: t.id }); closeModal(m); toast('Task deleted'); if (opts.onSaved) opts.onSaved(null); } catch (e) { toastError(e); }
    });
    openModal(m);
  }
  async function toggleTask(t, done) {
    const d = await api.post('tasks.php', { op: done ? 'done' : 'undo', id: t.id });
    if (done) toast('Done: ' + t.title, { action: { label: 'Undo', run: () => api.post('tasks.php', { op: 'undo', id: t.id }).then(() => document.dispatchEvent(new CustomEvent('crm:tasks'))) } });
    document.dispatchEvent(new CustomEvent('crm:tasks'));
    return d.task;
  }

  // ======================================================= deal (create)
  /** opts: {account: {id, name}, stage, onSaved(deal)} */
  function deal(opts = {}) {
    if (!BOOT.can_write) return;
    const open = (BOOT.stages || []).filter((s) => s.kind === 'open');
    const acc = opts.account || null;
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>New ${esc(lower('deal'))}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate><div class="fwc-grid2">
        <div class="field fwc-span2"><label class="label" for="dlT">Name${req(true)}</label><input class="input" id="dlT" name="title" maxlength="190" placeholder="Paving — Phase 2" autofocus></div>
        <div class="field fwc-span2"><label class="label" for="dlA">With${req(true)}</label><input class="input" id="dlA" value="${esc(acc ? acc.name : '')}" placeholder="Search ${esc(lower('customers'))} and ${esc(lower('suppliers'))}" ${acc ? 'disabled' : ''}></div>
        <div class="field"><label class="label" for="dlV">Value (R)</label><input class="input" id="dlV" name="amount" inputmode="decimal" placeholder="0.00"></div>
        <div class="field"><label class="label" for="dlC">Expected close</label><input class="input" id="dlC" name="close_date" type="date"></div>
        <div class="field"><label class="label" for="dlS">Stage</label><select class="select" id="dlS" name="stage">${open.map((s) => opt(s.key, s.label + ' · ' + s.probability + '%', opts.stage || open[0].key)).join('')}</select></div>
        <div class="field"><label class="label" for="dlO">Owner</label><select class="select" id="dlO" name="owner_id">${peopleOptions(BOOT.me.id)}</select></div>
        ${customFieldsHtml(fieldsFor('deal'), {})}
      </div></form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>${icon('check')}Create ${esc(lower('deal'))}</button></div>`, 'fwc-dialog fwc-dialog--sm');
    let accountId = acc ? acc.id : 0;
    if (!acc) accountPicker($('#dlA', m), (a) => { accountId = a ? a.id : 0; });
    const form = $('form', m);
    const save = () => busy($('[data-save]', m), async () => {
      const f = new FormData(form);
      if (!accountId) { errorIn(m, new Error('Choose who the deal is with.')); $('#dlA', m).focus(); return; }
      try {
        const d = await api.post('deals.php', { op: 'save', account_id: accountId, title: f.get('title'), amount: f.get('amount'), close_date: f.get('close_date'), stage: f.get('stage'), owner_id: f.get('owner_id'), custom: readCustom(form) });
        closeModal(m);
        toast(L('deal') + ' created', { action: { label: 'Open', run: () => sheet(d.deal.id) } });
        if (opts.onSaved) opts.onSaved(d.deal);
        document.dispatchEvent(new CustomEvent('crm:deals'));
      } catch (e) { errorIn(m, e); }
    });
    $('[data-save]', m).addEventListener('click', save);
    form.addEventListener('submit', (e) => { e.preventDefault(); save(); });
    openModal(m);
  }

  /** "Why was it lost?" → reason or null */
  function lostReason(d) {
    const reasons = BOOT.config.loss_reasons || [];
    return dialog({ title: 'Why was it lost?', body: `${esc(d.title)} · ${esc(money0(d.amount))}`, confirm: 'Mark as lost', choices: reasons.map((r) => ({ v: r, label: r })), other: true });
  }

  async function convert(d, templates) {
    const tpls = templates && templates.length ? templates : [{ key: 'construction_standard', name: 'Construction project' }];
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>Start a project</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body"><p class="fwm-dialog__lead">${esc(d.title)} becomes a project for ${esc(d.account)} with a budget of ${esc(money(d.amount))}.</p>
        <div class="field"><label class="label" for="pjN">Project name</label><input class="input" id="pjN" name="name" maxlength="255" value="${esc(d.title)}"></div>
        <div class="field"><label class="label">Template</label><div class="fwm-results">${tpls.map((t, i) => `<label class="fwm-result" style="cursor:pointer"><input type="radio" name="template" value="${esc(t.key)}" ${i === (tpls.findIndex((x) => x.key === 'construction_standard') >= 0 ? tpls.findIndex((x) => x.key === 'construction_standard') : 0) ? 'checked' : ''} style="accent-color:var(--accent)"><span class="fwm-result__text"><b>${esc(t.name)}</b>${t.hint ? `<small>${esc(t.hint)}</small>` : ''}</span></label>`).join('')}</div></div>
        <div class="row"><div class="field"><label class="label" for="pjS">Start</label><input class="input" id="pjS" name="start_date" type="date" value="${C.today()}"></div>
          <div class="field"><label class="label" for="pjE">Due</label><input class="input" id="pjE" name="end_date" type="date"></div></div></form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>${icon('briefcase')}Create project</button></div>`, 'fwc-dialog fwc-dialog--sm');
    return new Promise((resolve) => {
      m._onClose = () => resolve(null);
      $('[data-save]', m).addEventListener('click', () => busy($('[data-save]', m), async () => {
        const body = Object.fromEntries(new FormData($('form', m)).entries());
        try {
          const r = await api.post('deals.php', { op: 'convert', id: d.id, ...body });
          m._onClose = null;
          closeModal(m);
          toast(r.existing ? 'This deal already has a project' : 'Project created', { action: { label: 'Open project', run: () => { location.href = r.project.url; } } });
          resolve(r.project);
        } catch (e) { errorIn(m, e); }
      }));
      openModal(m);
    });
  }

  // ========================================================= deal sheet
  let sheetEl = null;
  /** The full deal: in a side sheet (default) or rendered into `host` (page mode). */
  async function sheet(id, opts = {}) {
    const page = !!opts.host;
    let host = opts.host;
    if (!page) {
      closeSheet();
      const scrim = document.createElement('div');
      scrim.className = 'fwc-sheet-scrim';
      host = document.createElement('aside');
      host.className = 'fwc-sheet';
      host.setAttribute('role', 'dialog');
      host.setAttribute('aria-modal', 'true');
      host.setAttribute('aria-label', L('deal'));
      host.innerHTML = `<div class="fwc-sheet__body"><div class="fwc-skel" style="height:40px;margin:22px 0 14px"></div><div class="fwc-skel" style="height:200px"></div></div>`;
      document.body.append(scrim, host);
      sheetEl = { host, scrim, onChange: opts.onChange };
      scrim.addEventListener('click', closeSheet);
      C.escHandlers.push(closeSheet);
    }
    let d;
    try { d = await api.get('deals.php', { id }); } catch (e) { host.innerHTML = C.errorBox(e); return; }
    renderSheet(host, d, page, opts);
  }
  function closeSheet() {
    if (!sheetEl) return;
    const s = sheetEl;
    sheetEl = null;
    s.host.remove();
    s.scrim.remove();
    const i = C.escHandlers.indexOf(closeSheet);
    if (i >= 0) C.escHandlers.splice(i, 1);
  }
  function renderSheet(host, data, page, opts) {
    const d = data.deal;
    const can = data.can_edit;
    const stages = BOOT.stages || [];
    const open = stages.filter((s) => s.kind === 'open');
    const idx = open.findIndex((s) => s.key === d.stage);
    const changed = () => { if (sheetEl && sheetEl.onChange) sheetEl.onChange(); if (opts.onChange) opts.onChange(); document.dispatchEvent(new CustomEvent('crm:deals')); };
    const reload = async () => { const nd = await api.get('deals.php', { id: d.id }); renderSheet(host, nd, page, opts); };
    const save = async (patch, msg) => {
      try { await api.post('deals.php', { op: 'save', id: d.id, ...patch }); if (msg) toast(msg); changed(); await reload(); } catch (e) { toastError(e); await reload(); }
    };
    const late = d.kind === 'open' && d.close_date && C.daysFrom(d.close_date) < 0;
    const defs = fieldsFor('deal');
    const head = `<div class="fwc-sheet__head">
        <div class="fwc-sheet__title">
          <div class="fwc-eyebrow">${esc(L('deal'))} · ${esc(d.account)}</div>
          <input id="dsTitle" value="${esc(d.title)}" maxlength="190" ${can ? '' : 'readonly'} aria-label="Deal name">
          <div class="fwc-sheet__sub"><a href="${C.accountUrl(d.account_id)}">${avatar(d.account, 'fwc-ava--xs')} ${esc(d.account)}</a>${C.stagePill(d.stage)}<span>${esc(money(d.amount))}</span>${d.close_date ? `<span class="${late ? 'fwc-money is-late' : ''}">${icon('calendar', 'i--xs')} ${esc(fmtDate(d.close_date, 'always'))}</span>` : ''}</div>
        </div>
        ${page ? '' : `<a class="icon-btn" href="${C.dealUrl(d.id)}" aria-label="Open as a page" data-tip="Open as a page">${icon('maximize-2')}</a><button class="icon-btn" type="button" data-x aria-label="Close">${icon('x')}</button>`}
      </div>`;
    const banner = d.kind === 'won' ? `<div class="fwc-banner fwc-banner--won">${icon('ic-trophy')}<span>Won ${d.stage_changed_at ? esc(relTime(d.stage_changed_at)) : ''}${data.project ? ` · project <a href="${esc(data.project.url)}">${esc(data.project.number || data.project.name)}</a>` : ''}</span>
        ${!data.project && can ? `<button class="btn btn--sm" type="button" data-convert>${icon('briefcase')}Start project</button>` : ''}</div>`
      : d.kind === 'lost' ? `<div class="fwc-banner fwc-banner--lost">${icon('ic-lost')}<span>Lost${d.loss_reason ? ': ' + esc(d.loss_reason) : ''}</span>${can ? `<button class="btn btn--sm" type="button" data-reopen>Reopen</button>` : ''}</div>`
      : (late ? `<div class="fwc-banner fwc-banner--warn">${icon('alert')}<span>Expected to close ${esc(C.relDay(d.close_date))}. Update the date or the stage.</span></div>` : '');
    const stageBar = d.kind === 'open' ? `<div class="fwc-stages" role="group" aria-label="Stage">${open.map((s, i) => `<button type="button" data-stage="${esc(s.key)}" class="${i < idx ? 'is-done' : i === idx ? 'is-on' : ''}" style="--c:${esc(s.color)}" title="${esc(s.label)} · ${s.probability}%" ${can ? '' : 'disabled'}>${esc(s.label)}</button>`).join('')}</div>` : '';
    const contactsOpts = data.contacts.map((c) => opt(c.id, c.name, d.contact_id || '')).join('');
    const body = `<div class="${page ? '' : 'fwc-sheet__body'}">
      ${banner}${stageBar}
      <div class="fwc-fields">
        <div class="field"><label class="label" for="dsAmt">Value (R)</label><input class="input" id="dsAmt" inputmode="decimal" value="${esc(d.amount ? d.amount.toFixed(2) : '')}" ${can ? '' : 'readonly'}></div>
        <div class="field"><label class="label" for="dsProb">Probability %</label><input class="input" id="dsProb" type="number" min="0" max="100" value="${esc(d.probability)}" ${can ? '' : 'readonly'}></div>
        <div class="field"><label class="label" for="dsClose">Expected close</label><input class="input" id="dsClose" type="date" value="${esc(d.close_date || '')}" ${can ? '' : 'readonly'}></div>
        <div class="field"><label class="label" for="dsOwner">Owner</label><select class="select" id="dsOwner" ${can && (BOOT.is_admin || d.owner_id === BOOT.me.id) ? '' : 'disabled'}>${peopleOptions(d.owner_id)}</select></div>
        <div class="field"><label class="label" for="dsContact">Contact</label><select class="select" id="dsContact" ${can ? '' : 'disabled'}>${opt('', '—', d.contact_id || '')}${contactsOpts}</select></div>
        <div class="field"><label class="label">Weighted</label><div class="input" style="display:flex;align-items:center;background:var(--surface-2)">${esc(money(d.amount * d.probability / 100))}</div></div>
        ${defs.length ? `<div class="field--wide"><div class="fwc-grid2" id="dsCustom">${customFieldsHtml(defs, data.custom || {})}</div></div>` : ''}
        <div class="field field--wide"><label class="label" for="dsNotes">Notes</label><textarea class="textarea" id="dsNotes" maxlength="10000" style="min-height:80px" ${can ? '' : 'readonly'} placeholder="Scope, decision makers, competition…">${esc(d.notes || '')}</textarea></div>
      </div>
      <div class="fwc-tabs" style="margin-top:18px" role="tablist"><button type="button" class="is-on" data-dtab="activity">Activity <small>${data.activity.length || ''}</small></button><button type="button" data-dtab="tasks">${esc(L('tasks'))} <small>${data.tasks.filter((t) => !t.done).length || ''}</small></button><button type="button" data-dtab="history">History</button></div>
      <div data-dpanel="activity">${BOOT.can_write ? '<div id="dsComposer"></div>' : ''}<div class="fwc-tl" id="dsActivity">${data.activity.length ? data.activity.map((a) => activityItem(a, { showAccount: false })).join('') : '<p class="fwc-muted" style="margin:6px 0 0 4px">Nothing logged against this deal yet.</p>'}</div></div>
      <div data-dpanel="tasks" hidden>${data.tasks.length ? data.tasks.map(taskRow).join('') : '<p class="fwc-muted">No tasks yet.</p>'}${BOOT.can_write ? `<button class="btn btn--sm" type="button" data-newtask style="margin-top:8px">${icon('plus')}Add a task</button>` : ''}</div>
      <div data-dpanel="history" hidden>${data.history.length ? `<div class="fwc-tl">${data.history.map((h) => `<div class="fwc-item"><span class="fwc-type" data-t="deal">${icon('history')}</span><div class="fwc-item__card is-plain"><div class="fwc-item__head"><b>${esc(h.who)}</b><span>${esc(h.text)}</span><time>${esc(relTime(h.at))}</time></div></div></div>`).join('')}</div>` : '<p class="fwc-muted">No changes recorded yet.</p>'}</div>
    </div>`;
    const foot = can ? `<div class="fwc-sheet__foot">
        ${d.kind === 'open' ? `<div class="fwc-wonlost"><button class="btn btn--sm" type="button" data-won style="color:var(--success)">${icon('ic-trophy')}Won</button><button class="btn btn--sm" type="button" data-lost>${icon('ic-lost')}Lost</button></div>` : ''}
        <span class="fwc-grow"></span>
        ${data.account.type === 'customer' ? `<a class="btn btn--sm" href="/qi/edit.php?type=quote&customer_id=${d.account_id}">${icon('iq-quote')}Quote</a>` : ''}
        <button class="btn btn--sm btn--ghost btn--danger" type="button" data-del aria-label="Delete deal">${icon('trash')}</button>
      </div>` : '';
    host.innerHTML = page ? `<div class="fwc-box" style="padding:0">${head}<div style="padding:4px 22px 18px">${body}</div>${foot}</div>` : head + body + foot;

    const x = $('[data-x]', host);
    if (x) x.addEventListener('click', closeSheet);
    // inline edits
    const title = $('#dsTitle', host);
    if (title && can) title.addEventListener('change', () => { if (title.value.trim()) save({ title: title.value.trim() }, 'Renamed'); });
    const bind = (sel, key, transform = (v) => v) => { const el = $(sel, host); if (el && can) el.addEventListener('change', () => save({ [key]: transform(el.value) })); };
    bind('#dsAmt', 'amount');
    bind('#dsProb', 'probability');
    bind('#dsClose', 'close_date');
    bind('#dsOwner', 'owner_id');
    bind('#dsContact', 'contact_id');
    const notes = $('#dsNotes', host);
    if (notes && can) notes.addEventListener('change', () => save({ notes: notes.value }));
    const cust = $('#dsCustom', host);
    if (cust && can) cust.addEventListener('change', () => save({ custom: readCustom(cust) }));
    $$('[data-stage]', host).forEach((b) => b.addEventListener('click', () => { if (b.dataset.stage !== d.stage) save({ stage: b.dataset.stage }, 'Moved to ' + stageMeta(b.dataset.stage).label); }));
    const won = $('[data-won]', host);
    if (won) won.addEventListener('click', async () => {
      await save({ stage: 'won' });
      toast('Won! ' + d.title, { actions: [
        ...(data.account.type === 'customer' ? [{ label: 'Create invoice', run: () => { location.href = '/qi/edit.php?type=invoice&customer_id=' + d.account_id; } }] : []),
        { label: 'Start project', run: () => convert({ ...d, stage: 'won' }, data.project_templates).then((p) => { if (p) reload(); }) },
      ] });
    });
    const lost = $('[data-lost]', host);
    if (lost) lost.addEventListener('click', async () => { const r = await lostReason(d); if (r !== null) save({ stage: 'lost', loss_reason: r || '' }, 'Marked as lost'); });
    const reopen = $('[data-reopen]', host);
    if (reopen) reopen.addEventListener('click', () => save({ stage: open[open.length - 1].key }, 'Reopened'));
    const conv = $('[data-convert]', host);
    if (conv) conv.addEventListener('click', () => convert(d, data.project_templates).then((p) => { if (p) reload(); }));
    const del = $('[data-del]', host);
    if (del) del.addEventListener('click', async () => {
      if (!(await dialog({ title: 'Delete ' + d.title + '?', body: 'Its activity and tasks stay on the account.', confirm: 'Delete', danger: true }))) return;
      try { await api.post('deals.php', { op: 'delete', id: d.id }); toast(L('deal') + ' deleted'); changed(); if (page) location.href = C.accountUrl(d.account_id, 'deals'); else closeSheet(); } catch (e) { toastError(e); }
    });
    $$('[data-dtab]', host).forEach((b) => b.addEventListener('click', () => {
      $$('[data-dtab]', host).forEach((y) => y.classList.toggle('is-on', y === b));
      $$('[data-dpanel]', host).forEach((p) => { p.hidden = p.dataset.dpanel !== b.dataset.dtab; });
    }));
    const comp = $('#dsComposer', host);
    if (comp) composer(comp, { account: { id: d.account_id, name: d.account }, deal: { id: d.id, title: d.title }, contacts: data.contacts, onSaved: () => { reload(); changed(); } });
    const nt = $('[data-newtask]', host);
    if (nt) nt.addEventListener('click', () => task({ account: { id: d.account_id, name: d.account }, deal: { id: d.id, title: d.title }, onSaved: reload }));
    $$('[data-tick]', host).forEach((b) => b.addEventListener('click', async (e) => { e.stopPropagation(); const t = data.tasks.find((y) => y.id === +b.dataset.tick); try { await toggleTask(t, !t.done); reload(); } catch (er) { toastError(er); } }));
    $$('[data-task]', host).forEach((r) => r.addEventListener('click', () => { const t = data.tasks.find((y) => y.id === +r.dataset.task); task({ task: t, onSaved: reload }); }));
    bindActivity(host, reload);
  }

  // ===================================================== activity pieces
  function activityItem(a, { showAccount = true } = {}) {
    const meta = (BOOT.activity_types || []).find((t) => t.key === a.type) || { label: a.type };
    const mine = a.by_id === BOOT.me.id || BOOT.is_admin;
    return `<div class="fwc-item${a.pinned ? ' is-pinned' : ''}" data-act-id="${a.id}">
      <span class="fwc-type" data-t="${esc(a.type)}">${actIcon(a.type)}</span>
      <div class="fwc-item__card">
        <div class="fwc-item__head"><b>${esc(a.subject || meta.label)}</b>${a.subject ? `<span>${esc(meta.label)}</span>` : ''}
          ${showAccount && a.account ? `<a href="${C.accountUrl(a.account.id)}">${esc(a.account.name)}</a>` : ''}
          <time title="${esc(a.at)}">${esc(relTime(a.at))}</time></div>
        ${a.body ? `<div class="fwc-item__body${a.body.length > 360 ? ' is-clamped' : ''}">${esc(a.body)}</div>` : ''}
        <div class="fwc-item__foot"><span class="fwc-muted" style="font-size:12px">${esc(a.by || 'Someone')}${a.via === 'mail' ? ' · sent from Flowwork' : ''}</span>
          ${a.contact ? `<span class="fwc-pill fwc-pill--plain" data-tone="muted">${icon('user', 'i--xs')} ${esc(a.contact.name)}</span>` : ''}
          ${a.deal ? `<button class="fwc-pill fwc-pill--plain" data-tone="gold" type="button" data-open-deal="${a.deal.id}" style="border:0;cursor:pointer">${icon('i-kanban', 'i--xs')} ${esc(a.deal.title)}</button>` : ''}
          ${BOOT.can_write ? `<span class="fwc-item__acts">
            <button class="icon-btn icon-btn--sm" type="button" data-pin="${a.id}" data-on="${a.pinned ? 0 : 1}" aria-label="${a.pinned ? 'Unpin' : 'Pin to the top'}" data-tip="${a.pinned ? 'Unpin' : 'Pin'}">${icon('pin', 'i--sm')}</button>
            ${mine ? `<button class="icon-btn icon-btn--sm" type="button" data-edit-act="${a.id}" aria-label="Edit" data-tip="Edit">${icon('pen', 'i--sm')}</button>
            <button class="icon-btn icon-btn--sm" type="button" data-del-act="${a.id}" aria-label="Delete" data-tip="Delete">${icon('trash', 'i--sm')}</button>` : ''}</span>` : ''}
        </div></div></div>`;
  }
  function bindActivity(host, reload) {
    host.addEventListener('click', async (e) => {
      const pin = e.target.closest('[data-pin]');
      const del = e.target.closest('[data-del-act]');
      const ed = e.target.closest('[data-edit-act]');
      const od = e.target.closest('[data-open-deal]');
      const clamp = e.target.closest('.fwc-item__body.is-clamped');
      if (clamp) { clamp.classList.remove('is-clamped'); return; }
      if (od && !host.classList.contains('fwc-sheet')) { sheet(+od.dataset.openDeal, { onChange: reload }); return; }
      if (pin) { try { await api.post('activity.php', { op: 'pin', id: +pin.dataset.pin, on: pin.dataset.on === '1' }); reload(); } catch (er) { toastError(er); } return; }
      if (del) {
        if (!(await dialog({ title: 'Delete this activity?', confirm: 'Delete', danger: true }))) return;
        try { await api.post('activity.php', { op: 'delete', id: +del.dataset.delAct }); toast('Deleted'); reload(); } catch (er) { toastError(er); }
        return;
      }
      if (ed) {
        const card = ed.closest('.fwc-item');
        const bodyEl = $('.fwc-item__body', card);
        const subj = $('.fwc-item__head b', card).textContent;
        const text = await dialog({ title: 'Edit activity', input: { value: bodyEl ? bodyEl.textContent : subj, multiline: true, max: 20000 }, confirm: 'Save' });
        if (text === null) return;
        try { await api.post('activity.php', { op: 'save', id: +ed.dataset.editAct, body: text }); reload(); } catch (er) { toastError(er); }
      }
    });
  }
  function taskRow(t) {
    const due = t.due_at ? (t.all_day ? fmtDate(t.due_at.slice(0, 10)) : fmtDate(t.due_at.slice(0, 10)) + ' ' + fmtTime(t.due_at)) : 'No date';
    return `<div class="fwc-task${t.done ? ' is-done' : ''}" data-task="${t.id}">
      <button class="fwc-task__tick" type="button" data-tick="${t.id}" aria-label="${t.done ? 'Mark as not done' : 'Mark as done'}">${icon('check')}</button>
      <div class="fwc-task__body"><span class="fwc-task__title">${esc(t.title)}</span>
        <span class="fwc-task__meta"><span class="${t.state === 'overdue' ? 'is-late' : t.state === 'today' ? 'is-today' : ''}">${icon('clock', 'i--xs')} ${esc(due)}</span>${t.account ? `<a href="${C.accountUrl(t.account.id)}" data-stop>${esc(t.account.name)}</a>` : ''}${t.deal ? `<span>${icon('i-kanban', 'i--xs')} ${esc(t.deal.title)}</span>` : ''}</span></div>
      <span class="fwc-task__side"><span class="fwc-prio" data-p="${esc(t.priority)}" title="${esc(t.priority)} priority"></span>${C.personAva(t.assigned_to, t.assignee)}</span></div>`;
  }

  /** The inline activity composer. opts: {account:{id,name}, deal:{id,title}, contacts[], deals[], onSaved} */
  function composer(host, opts) {
    const types = (BOOT.activity_types || []).filter((t) => t.on);
    if (!types.length) { host.innerHTML = ''; return; }
    let type = types[0].key === 'note' ? 'note' : types[0].key;
    const nowLocal = () => { const d = new Date(); return C.ymd(d) + 'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
    host.innerHTML = `<form class="fwc-composer is-collapsed" novalidate>
      <div class="fwc-composer__types" role="radiogroup" aria-label="Kind of activity">${types.map((t) => `<button type="button" role="radio" data-type="${t.key}" class="${t.key === type ? 'is-on' : ''}" aria-checked="${t.key === type}">${actIcon(t.key)}${esc(t.label)}</button>`).join('')}</div>
      <input class="fwc-composer__subject" name="subject" maxlength="190" placeholder="Subject (optional)">
      <textarea name="body" maxlength="20000" placeholder="${type === 'note' ? 'Write a note…' : 'What happened?'}" aria-label="Details"></textarea>
      <div class="fwc-composer__fu" hidden><span class="fwc-muted" style="font-size:12px">${icon('i-check-square', 'i--xs')} Follow up</span>
        <input class="input fwc-grow" name="fu_title" maxlength="190" placeholder="What is the next step?"><input class="input" name="fu_date" type="date" value="${C.ymd(new Date(Date.now() + 2 * 86400000))}"><select class="select" name="fu_who">${peopleOptions(BOOT.me.id)}</select></div>
      <div class="fwc-composer__more">
        ${opts.contacts && opts.contacts.length ? `<select class="select" name="contact_id" aria-label="With">${opt('', 'With…', '')}${opts.contacts.map((c) => opt(c.id, c.name, '')).join('')}</select>` : ''}
        ${!opts.deal && opts.deals && opts.deals.length ? `<select class="select" name="opportunity_id" aria-label="About deal">${opt('', 'About a ' + lower('deal') + '…', '')}${opts.deals.map((x) => opt(x.id, x.title, '')).join('')}</select>` : ''}
        <input class="input" type="datetime-local" name="at" value="${nowLocal()}" max="${nowLocal()}" aria-label="When">
        <button class="btn btn--sm btn--ghost" type="button" data-fu>${icon('plus')}Follow-up task</button>
        <span class="fwc-grow"></span>
        <button class="btn btn--sm" type="button" data-cancel>Cancel</button>
        <button class="btn btn--sm btn--primary" type="submit">${icon('check')}Log it</button>
      </div></form>`;
    const form = $('form', host);
    const ta = $('textarea', form);
    const expand = () => form.classList.remove('is-collapsed');
    ta.addEventListener('focus', expand);
    $$('[data-type]', form).forEach((b) => b.addEventListener('click', () => {
      type = b.dataset.type;
      $$('[data-type]', form).forEach((x) => { x.classList.toggle('is-on', x === b); x.setAttribute('aria-checked', x === b); });
      ta.placeholder = type === 'note' ? 'Write a note…' : type === 'call' ? 'What did you talk about?' : type === 'meeting' || type === 'visit' ? 'Who was there and what was decided?' : 'What happened?';
      expand();
      ta.focus();
    }));
    $('[data-fu]', form).addEventListener('click', () => { const fu = $('.fwc-composer__fu', form); fu.hidden = !fu.hidden; if (!fu.hidden) $('[name=fu_title]', form).focus(); });
    $('[data-cancel]', form).addEventListener('click', () => { form.reset(); $('.fwc-composer__fu', form).hidden = true; form.classList.add('is-collapsed'); ta.blur(); });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const btn = $('[type=submit]', form);
      busy(btn, async () => {
        const f = new FormData(form);
        if (!String(f.get('body') || '').trim() && !String(f.get('subject') || '').trim()) { ta.focus(); return; }
        const body = { op: 'save', account_id: opts.account.id, type, subject: f.get('subject'), body: f.get('body'), contact_id: f.get('contact_id') || 0, at: f.get('at') };
        if (opts.deal) body.opportunity_id = opts.deal.id;
        else if (f.get('opportunity_id')) body.opportunity_id = f.get('opportunity_id');
        if (!$('.fwc-composer__fu', form).hidden && String(f.get('fu_title') || '').trim()) body.followup = { title: f.get('fu_title'), due_date: f.get('fu_date'), assigned_to: f.get('fu_who'), remind: 'at' };
        try {
          const d = await api.post('activity.php', body);
          form.reset();
          $('.fwc-composer__fu', form).hidden = true;
          form.classList.add('is-collapsed');
          toast(d.task ? 'Logged, and a follow-up task was added' : 'Logged');
          if (opts.onSaved) opts.onSaved(d);
        } catch (er) { toastError(er); }
      });
    });
    form.addEventListener('keydown', (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') form.requestSubmit(); });
  }

  // ============================================================== email
  /** opts: {account:{id,name,type}, contact_id, deal_id, onSent} */
  async function email(opts = {}) {
    if (!BOOT.can_write) return;
    let c;
    try { c = await api.get('email.php', { account_id: opts.account.id, contact_id: opts.contact_id || 0 }); } catch (e) { toastError(e); return; }
    if (!c.mailboxes.length) {
      const go = await dialog({ title: 'Connect your mailbox', body: 'To email from Flowwork, add your mailbox (with its outgoing SMTP server) in Mail. Your messages are sent from your own address and replies come back to you.', confirm: 'Open Mail settings' });
      if (go) location.href = '/mail/settings.php';
      return;
    }
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>Email ${esc(opts.account.name)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate>
        <div class="fwc-grid2">
          ${c.mailboxes.length > 1 ? `<div class="field fwc-span2"><label class="label" for="emFrom">From</label><select class="select" id="emFrom" name="mailbox_id">${c.mailboxes.map((b) => opt(b.id, b.name + ' <' + b.email + '>', '')).join('')}</select></div>` : `<input type="hidden" name="mailbox_id" value="${c.mailboxes[0].id}">`}
          <div class="field fwc-span2"><label class="label" for="emTo">To</label><input class="input" id="emTo" name="to" value="${esc(c.to.join(', '))}" list="emSuggest" autocomplete="off" placeholder="name@company.co.za"><datalist id="emSuggest">${c.suggest.map((s) => `<option value="${esc(s)}">`).join('')}</datalist></div>
          <div class="field fwc-span2"><label class="label" for="emCc">Cc</label><input class="input" id="emCc" name="cc" autocomplete="off"></div>
          <div class="field fwc-span2"><label class="label" for="emTpl">Start from</label><select class="select" id="emTpl">${opt('', 'A blank message', '')}${c.templates.map((t) => opt(t.key, t.label, '')).join('')}</select></div>
          <div class="field fwc-span2"><label class="label" for="emSub">Subject</label><input class="input" id="emSub" name="subject" maxlength="300" autofocus></div>
          <div class="field fwc-span2"><label class="label" for="emBody">Message</label><textarea class="textarea" id="emBody" name="body" style="min-height:220px">${esc(c.greeting + c.signoff)}</textarea><span class="hint">Your signature from Mail is added. The email is filed under this account and logged as activity.</span></div>
        </div></form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-send>${icon('send')}Send</button></div>`, 'fwc-dialog');
    const form = $('form', m);
    $('#emTpl', m).addEventListener('change', (e) => {
      const t = c.templates.find((x) => x.key === e.target.value);
      $('#emSub', m).value = t ? t.subject : '';
      $('#emBody', m).value = t ? t.body : c.greeting + c.signoff;
    });
    $('[data-send]', m).addEventListener('click', () => busy($('[data-send]', m), async () => {
      const f = Object.fromEntries(new FormData(form).entries());
      try {
        await api.post('email.php', { ...f, account_id: opts.account.id, contact_id: opts.contact_id || 0, opportunity_id: opts.deal_id || 0 });
        closeModal(m);
        toast('Email sent');
        if (opts.onSent) opts.onSent();
      } catch (e) { errorIn(m, e); }
    }));
    openModal(m);
  }

  // ========================================================= compliance
  /** opts: {account_id, doc, types, onSaved} */
  function complianceDoc(opts = {}) {
    const doc = opts.doc || {};
    const types = opts.types || [];
    if (!types.length) { dialog({ title: 'No document types yet', body: 'An administrator adds the kinds of documents you collect (tax clearance, B-BBEE…) in Make it yours › Compliance.', cancel: '', confirm: 'OK' }); return; }
    const m = tempModal(`
      <div class="fwm-dialog__head"><h2>${doc.id ? 'Update document' : 'Add a document'}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate><div class="fwc-grid2">
        <div class="field fwc-span2"><label class="label" for="cdT">Document${req(true)}</label><select class="select" id="cdT" name="type_id">${types.map((t) => opt(t.id, t.name + (t.required ? ' (required)' : ''), doc.type_id || opts.type_id || '')).join('')}</select></div>
        <div class="field fwc-span2"><label class="label" for="cdR">Reference / certificate no.</label><input class="input" id="cdR" name="reference" maxlength="190" value="${esc(doc.reference || '')}"></div>
        <div class="field"><label class="label" for="cdI">Issued</label><input class="input" id="cdI" name="issue_date" type="date" value="${esc(doc.issue_date || '')}"></div>
        <div class="field"><label class="label" for="cdE">Expires</label><input class="input" id="cdE" name="expiry_date" type="date" value="${esc(doc.expiry_date || '')}"><span class="hint" id="cdHint"></span></div>
        <div class="field fwc-span2"><label class="label">File (PDF or image, 10 MB at most)</label>
          <label class="fwc-drop" id="cdDrop" style="padding:18px">${icon('upload')}<b id="cdName">${doc.has_file ? esc(doc.file_name) + ' — choose a file to replace it' : 'Choose a file or drop it here'}</b><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/*" hidden></label></div>
        <div class="field fwc-span2"><label class="label" for="cdN">Notes</label><textarea class="textarea" id="cdN" name="notes" maxlength="2000" style="min-height:60px">${esc(doc.notes || '')}</textarea></div>
      </div></form>
      <div class="fwm-dialog__foot"><span class="grow"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" data-save>${icon('check')}Save</button></div>`, 'fwc-dialog fwc-dialog--sm');
    const form = $('form', m);
    const fileIn = $('input[type=file]', m);
    const drop = $('#cdDrop', m);
    const hint = () => {
      const t = types.find((x) => x.id === +$('#cdT', m).value);
      $('#cdHint', m).textContent = t && t.expiry_months && !$('#cdE', m).value ? `Leave empty to use ${t.expiry_months} months from the issue date` : '';
    };
    $('#cdT', m).addEventListener('change', hint);
    $('#cdE', m).addEventListener('input', hint);
    hint();
    fileIn.addEventListener('change', () => { if (fileIn.files[0]) $('#cdName', m).textContent = fileIn.files[0].name; });
    ['dragover', 'dragenter'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, () => drop.classList.remove('is-over')));
    drop.addEventListener('drop', (e) => { e.preventDefault(); if (e.dataTransfer.files[0]) { fileIn.files = e.dataTransfer.files; $('#cdName', m).textContent = e.dataTransfer.files[0].name; } });
    $('[data-save]', m).addEventListener('click', () => busy($('[data-save]', m), async () => {
      const fd = new FormData(form);
      fd.append('op', 'save');
      fd.append('account_id', opts.account_id);
      fd.append('auto_expiry', '1');
      if (doc.id) fd.append('id', doc.id);
      if (!fileIn.files.length) fd.delete('file');
      try { await api.upload('compliance.php', fd); closeModal(m); toast('Document saved'); if (opts.onSaved) opts.onSaved(); } catch (e) { errorIn(m, e); }
    }));
    openModal(m);
  }

  C.forms = { account, contact, address, task, toggleTask, taskRow, deal, sheet, closeSheet, lostReason, convert, composer, activityItem, bindActivity, email, complianceDoc,
    customFieldsHtml, readCustom, displayCustom, fieldsFor, accountPicker, peopleOptions, opt, busy };
})();
