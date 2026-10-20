// /admin/js/people.js — People & roles: the table (search, role filter),
// the edit dialog and each person's actions (sign out everywhere, suspend /
// reactivate, remove). Data: boot.people; changes: /admin/api/people.php.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, closeModal, dialog, popover, closePop, initials, hue, busy } = window.FWA;
  const ROLES = FWA.boot.roles || {};
  const ME = FWA.boot.me;
  let people = FWA.boot.people || [];
  let filter = 'all';
  let q = '';

  const ROLE_TONE = { admin: 'accent', member: 'info', bookkeeper: 'gold', viewer: 'muted', pos: 'muted' };
  const name = (p) => (p.first_name + ' ' + p.last_name).trim() || p.email;
  function ago(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return '';
    const sec = (Date.now() - d) / 1000;
    if (sec < 3600) return Math.max(1, Math.floor(sec / 60)) + ' min ago';
    if (sec < 86400) return Math.floor(sec / 3600) + ' h ago';
    if (sec < 172800) return 'yesterday';
    if (sec < 7 * 86400) return Math.floor(sec / 86400) + ' days ago';
    return FWA.fmtDate(s);
  }

  function row(p) {
    const you = p.id === ME;
    const status = p.status === 'active' ? `<span class="fwa-pill" data-tone="success">Active</span>` : `<span class="fwa-pill" data-tone="danger">${esc(p.status === 'invited' ? 'Invited' : 'Suspended')}</span>`;
    return `<tr data-id="${p.id}"${p.status !== 'active' ? ' class="is-muted"' : ''}>
      <td><div class="fwa-who"><span class="fwa-ava fwa-ava--round" style="--h:${hue(name(p))}">${esc(initials(name(p)))}</span>
        <span class="fwa-who__text"><b>${esc(name(p))}${you ? '<span class="fwa-you">You</span>' : ''}</b><small>${esc(p.email)}</small></span></div></td>
      <td><span class="fwa-pill" data-tone="${ROLE_TONE[p.role] || 'muted'}">${esc((ROLES[p.role] || [p.role])[0])}</span></td>
      <td class="hide-narrow">${status}${p.devices ? `<small class="fwa-muted" style="display:block;margin-top:3px">${p.devices} device${p.devices === 1 ? '' : 's'} signed in</small>` : ''}</td>
      <td class="hide-narrow">${p.role !== 'bookkeeper' ? 'Paid seat' : '<span class="fwa-muted">No seat</span>'}${p.primary ? '' : `<small class="fwa-muted" style="display:block;margin-top:3px" title="Their account belongs to another company first">Guest from another company</small>`}</td>
      <td class="hide-narrow t-num">${p.projects || '<span class="fwa-muted">—</span>'}</td>
      <td class="hide-narrow">${p.last_login ? esc(ago(p.last_login)) : '<span class="fwa-muted">Never</span>'}</td>
      <td class="t-actions"><button class="fwa-mini" type="button" data-edit>${icon('i-pen')}Edit</button>
        <button class="icon-btn icon-btn--sm" type="button" data-more aria-label="More for ${esc(name(p))}" aria-haspopup="menu">${icon('more-h')}</button></td></tr>`;
  }
  function render() {
    const needle = q.toLowerCase();
    const list = people.filter((p) => (filter === 'all' || (filter === 'suspended' ? p.status !== 'active' : p.role === filter))
      && (!needle || (name(p) + ' ' + p.email).toLowerCase().includes(needle)));
    $('#peopleBody').innerHTML = list.map(row).join('');
    $('#peopleEmpty').hidden = list.length > 0;
  }
  const find = (id) => people.find((p) => p.id === id);
  function replace(person) {
    const i = people.findIndex((p) => p.id === person.id);
    const old = i >= 0 ? people[i] : {};
    const next = { ...old, ...person, projects: old.projects || 0, devices: person.status !== 'active' ? 0 : (old.devices || 0), last_login: person.last_login_at || old.last_login };
    if (i >= 0) people[i] = next; else people.push(next);
    render();
  }

  // ------------------------------------------------------------- edit dialog
  function edit(p) {
    const you = p.id === ME;
    const m = modal(`
      <div class="fwm-dialog__head"><span class="fwa-ava fwa-ava--round" style="--h:${hue(name(p))}">${esc(initials(name(p)))}</span><h2>${esc(name(p))}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <form class="fwm-dialog__body" novalidate id="editForm">
        <div class="fwa-form">
          <div class="field"><label class="label" for="edFirst">First name</label><input class="input" id="edFirst" name="first_name" maxlength="100" value="${esc(p.first_name)}"${p.primary ? '' : ' disabled'}></div>
          <div class="field"><label class="label" for="edLast">Surname</label><input class="input" id="edLast" name="last_name" maxlength="100" value="${esc(p.last_name)}"${p.primary ? '' : ' disabled'}></div>
          <div class="field is-full"><span class="label">Email</span><div class="fwa-secret"><code>${esc(p.email)}</code></div>
            <span class="hint">${p.primary ? 'People change their own email address.' : 'Their account belongs to another company, which looks after their name and email. You choose their role here.'}</span></div>
          <div class="field is-full"><span class="label">Role${you ? ' — yours' : ''}</span>${FWA.roleCards('role', p.role)}
            <span class="hint">Everyone uses a paid seat except Bookkeepers.</span></div>
        </div>
      </form>
      <div class="fwm-dialog__foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn btn--primary" type="button" id="edSave">${icon('check', 'i--sm')}Save changes</button></div>`, { width: 620 });
    const save = async () => {
      const f = $('#editForm', m);
      $$('.field__error', m).forEach((x) => x.remove());
      const body = { action: 'update', id: p.id, first_name: f.first_name.value.trim(), last_name: f.last_name.value.trim(), role: ($('input[name=role]:checked', m) || {}).value };
      const btn = $('#edSave', m);
      busy(btn, true);
      try {
        const r = await api.post('people.php', body);
        replace(r.person);
        closeModal(m);
        toast('Saved ' + name(r.person));
        if (you && r.person.role !== 'admin') setTimeout(() => location.assign('/home.php'), 900);
      } catch (e) {
        const errs = e.data && e.data.errors;
        if (errs) Object.entries(errs).forEach(([k, msg]) => {
          const el = $(`[name="${k}"]`, m);
          const box = el && (el.closest('.field') || el.closest('.fwa-roles').parentElement);
          if (box) { const d = document.createElement('div'); d.className = 'field__error'; d.textContent = msg; box.appendChild(d); }
          else toastError(msg);
        });
        else toastError(e);
      } finally { busy(btn, false); }
    };
    $('#edSave', m).addEventListener('click', save);
    $('#editForm', m).addEventListener('submit', (e) => { e.preventDefault(); save(); });
    openModal(m);
  }

  // ------------------------------------------------------------- actions
  async function act(p, action) {
    const who = name(p);
    const confirmations = {
      signout: { title: 'Sign ' + who + ' out everywhere?', body: `Every phone, tablet and computer ${esc(who)} is signed in on will ask for the password again.${p.id === ME ? ' This device stays signed in.' : ''}`, confirm: 'Sign out everywhere' },
      suspend: { title: 'Suspend ' + who + '?', body: `${esc(who)} is signed out at once and cannot sign in until you reactivate them. Their work stays where it is${p.is_seat ? ', and their seat is freed' : ''}.`, confirm: 'Suspend', danger: true },
      reactivate: { title: 'Reactivate ' + who + '?', body: `${esc(who)} can sign in again${p.is_seat ? ' and uses a paid seat' : ''}.`, confirm: 'Reactivate' },
      remove: { title: 'Remove ' + who + ' from the company?', body: `${esc(who)} loses access to this company and is taken off its projects and boards. Their tasks, comments and documents stay.${p.primary ? ' Because this is their own company, their Flowwork account is switched off too.' : ' Their other companies are not affected.'}`, confirm: 'Remove', danger: true, typeToConfirm: p.primary ? 'REMOVE' : null },
    };
    const c = confirmations[action];
    if (c && !(await dialog(c))) return;
    try {
      const r = await api.post('people.php', { action, id: p.id });
      if (action === 'remove') { people = people.filter((x) => x.id !== p.id); render(); toast(who + ' was removed'); return; }
      if (action === 'signout') { const x = find(p.id); if (x) x.devices = p.id === ME ? 1 : 0; render(); toast(r.devices === 1 ? 'Signed out of 1 device' : `Signed out of ${r.devices} devices`); return; }
      replace(r.person);
      toast(action === 'suspend' ? who + ' is suspended' : who + ' can sign in again');
    } catch (e) { toastError(e); }
  }
  function more(btn, p) {
    const you = p.id === ME;
    const el = popover(btn, `
      <button class="fwm-pop__item" type="button" data-v="edit">${icon('i-pen')}Edit name and role</button>
      <button class="fwm-pop__item" type="button" data-v="signout"${!p.primary && !you ? ' disabled title="Their own company looks after their devices"' : ''}>${icon('ia-logout-all')}Sign out everywhere</button>
      <div class="fwm-pop__sep"></div>
      ${p.status === 'active'
        ? `<button class="fwm-pop__item is-danger" type="button" data-v="suspend"${you || !p.primary ? ' disabled title="' + (you ? 'You cannot suspend yourself' : 'Their own company manages this — remove them instead') + '"' : ''}>${icon('ia-pause')}Suspend</button>`
        : `<button class="fwm-pop__item" type="button" data-v="reactivate"${!p.primary ? ' disabled' : ''}>${icon('ia-user-check')}Reactivate</button>`}
      <button class="fwm-pop__item is-danger" type="button" data-v="remove"${you ? ' disabled title="You cannot remove yourself"' : ''}>${icon('ia-user-off')}Remove from company</button>`, { align: 'end' });
    el.addEventListener('click', (e) => {
      const b = e.target.closest('[data-v]');
      if (!b || b.disabled) return;
      closePop();
      if (b.dataset.v === 'edit') edit(p); else act(p, b.dataset.v);
    });
  }

  $('#peopleBody').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const p = find(+tr.dataset.id);
    if (!p) return;
    if (e.target.closest('[data-edit]')) edit(p);
    else if (e.target.closest('[data-more]')) more(e.target.closest('[data-more]'), p);
  });
  $('#peopleBody').addEventListener('dblclick', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr && !e.target.closest('button')) { const p = find(+tr.dataset.id); if (p) edit(p); }
  });
  $('#peopleSearch').addEventListener('input', (e) => { q = e.target.value.trim(); render(); });
  $$('#roleChips .fwa-chip').forEach((c) => c.addEventListener('click', () => {
    filter = c.dataset.filter;
    $$('#roleChips .fwa-chip').forEach((x) => x.classList.toggle('is-on', x === c));
    render();
  }));
  render();
})();
