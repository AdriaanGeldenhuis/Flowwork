// /admin/js/projects.js — Projects & access: the project list (search,
// open / closed) and the access dialog: who is on the project and their
// role, who can see it, and extra access to single boards (plus export and
// archive). Data: boot.projects; changes: /admin/api/projects.php.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, modal, openModal, dialog, initials, hue, busy } = window.FWA;
  let projects = FWA.boot.projects || [];
  let filter = 'open';
  let q = '';
  const PROJECT_ROLES = { owner: 'Owner', manager: 'Manager', member: 'Member', viewer: 'Viewer' };
  const ROLE_HINT = { owner: 'Everything, including deleting it', manager: 'Settings, people and boards', member: 'Works on the boards', viewer: 'Reads and comments' };

  function visPill(p) {
    return p.visibility === 'company'
      ? `<span class="fwa-pill" data-tone="success">Everyone</span>`
      : `<span class="fwa-pill" data-tone="muted">Members only</span>`;
  }
  function row(p) {
    return `<tr data-id="${p.id}"${p.closed ? ' class="is-muted"' : ''}>
      <td><div class="fwa-who"><span class="fwa-ava fwa-ava--sm" style="--h:${hue(p.name)};background:${esc(p.color)};color:#fff">${esc(initials(p.name))}</span>
        <span class="fwa-who__text"><b>${esc(p.name)}</b><small>${esc([p.status_label, p.client].filter(Boolean).join(' · '))}</small></span></div></td>
      <td>${visPill(p)}</td>
      <td class="hide-narrow">${esc(p.owner || '—')}</td>
      <td class="t-num hide-narrow">${p.members}</td>
      <td class="t-num hide-narrow">${p.boards}</td>
      <td class="t-actions"><button class="fwa-mini" type="button" data-manage>${icon('i-users')}Access</button><a class="fwa-mini" href="${esc(p.url)}">${icon('external')}Open</a></td></tr>`;
  }
  function render() {
    const n = q.toLowerCase();
    const list = projects.filter((p) => (filter === 'all' || (filter === 'closed') === p.closed) && (!n || (p.name + ' ' + p.client).toLowerCase().includes(n)));
    $('#projBody').innerHTML = list.map(row).join('');
    $('#projEmpty').hidden = list.length > 0;
  }

  // ------------------------------------------------------------ access dialog
  function manage(p) {
    let d = null;
    const m = modal(`<div class="fwm-dialog__head"><span class="fwa-ava fwa-ava--sm" style="background:${esc(p.color)};color:#fff">${esc(initials(p.name))}</span><h2>${esc(p.name)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body" id="accBody"><div class="fwa-loading"><span class="fwa-spin"></span>Loading who has access…</div></div>
      <div class="fwm-dialog__foot"><a class="btn btn--ghost" href="${esc(p.url)}">${icon('external', 'i--sm')}Open the project</a><span class="grow"></span><button class="btn btn--primary" type="button" data-close>Done</button></div>`, { wide: true });
    openModal(m);
    const body = $('#accBody', m);

    function personOptions(exclude) {
      const ex = new Set(exclude);
      const list = d.people.filter((x) => !ex.has(x.id));
      return list.length ? `<option value="">Choose a person…</option>` + list.map((x) => `<option value="${x.id}">${esc(x.name)} — ${esc(x.email)}</option>`).join('') : '<option value="">Everyone is already here</option>';
    }
    function draw() {
      const vis = d.project.visibility;
      body.innerHTML = `
        <h3 class="fwm-section-title" style="margin-top:0">Who can see it</h3>
        <div class="seg" role="radiogroup" aria-label="Who can see it" style="margin-bottom:6px">
          <button type="button" data-vis="members" class="${vis !== 'company' ? 'is-on' : ''}">${icon('i-lock', 'i--sm')} The people on it</button>
          <button type="button" data-vis="company" class="${vis === 'company' ? 'is-on' : ''}">${icon('i-globe', 'i--sm')} Everyone in the company</button>
        </div>
        <p class="hint" style="margin:6px 0 0">${vis === 'company' ? 'Every colleague can open and read it; only the people below can change it.' : 'Only the people below (and admins) can open it.'}</p>

        <h3 class="fwm-section-title">People on the project · ${d.members.length}</h3>
        <div class="fwa-members">${d.members.map((x) => `
          <div class="fwa-member" data-uid="${x.id}">
            <span class="fwa-ava fwa-ava--sm fwa-ava--round" style="--h:${hue(x.name)}">${esc(initials(x.name))}</span>
            <span class="fwa-who__text fwa-grow"><b>${esc(x.name)}${x.active ? '' : ' <span class="fwa-muted">(no longer here)</span>'}</b><small>${esc(x.email)}${x.implicit ? ' · ' + (x.role === 'owner' ? 'created the project' : 'project manager') : ''}</small></span>
            ${x.implicit
              ? `<span class="fwa-pill fwa-pill--plain" title="${x.role === 'owner' ? 'Created the project' : 'Set as the project manager in the project'}">${esc(PROJECT_ROLES[x.role] || x.role)}</span>
                 ${x.role === 'owner' ? '<span style="width:30px"></span>' : `<button class="icon-btn icon-btn--sm" type="button" data-remove aria-label="Take ${esc(x.name)} off as project manager">${icon('x')}</button>`}`
              : `<select class="select" data-role aria-label="Role for ${esc(x.name)}">${Object.entries(PROJECT_ROLES).map(([k, l]) => `<option value="${k}"${k === x.role ? ' selected' : ''}>${l}</option>`).join('')}</select>
                 <button class="icon-btn icon-btn--sm" type="button" data-remove aria-label="Take ${esc(x.name)} off the project">${icon('x')}</button>`}
          </div>`).join('') || '<p class="fwa-muted">Nobody yet.</p>'}</div>
        <div class="fwa-add"><select class="select" id="addWho" aria-label="Person to add">${personOptions(d.members.map((x) => x.id))}</select>
          <select class="select" id="addRole" aria-label="Their role">${Object.entries(PROJECT_ROLES).map(([k, l]) => `<option value="${k}"${k === 'member' ? ' selected' : ''} title="${esc(ROLE_HINT[k])}">${l}</option>`).join('')}</select>
          <button class="btn btn--sm btn--primary" type="button" id="addGo">${icon('plus', 'i--sm')}Add</button></div>

        <h3 class="fwm-section-title">Boards · ${d.boards.length}</h3>
        ${d.boards.length ? d.boards.map((b) => `
          <details class="fwa-board fwm-details"${b.grants.length ? ' open' : ''} data-board="${b.id}">
            <summary>${icon('chev-right', 'i--sm')}<span class="fwa-grow">${esc(b.title)} <span class="fwa-muted" style="font-weight:400">· ${b.items} item${b.items === 1 ? '' : 's'}${b.archived ? ' · archived' : ''}</span></span>
              ${b.grants.length ? `<span class="fwa-pill" data-tone="gold">${b.grants.length} extra</span>` : ''}</summary>
            <div class="fwa-board__body">
              ${d.board_grants ? `<p class="hint" style="margin:0 0 8px">Give someone just this board, without the rest of the project.</p>
              ${b.grants.map((g) => `<div class="fwa-member" data-guid="${g.id}"><span class="fwa-ava fwa-ava--sm fwa-ava--round" style="--h:${hue(g.name)}">${esc(initials(g.name))}</span>
                <span class="fwa-who__text fwa-grow"><b>${esc(g.name)}</b><small>${esc(g.email)} · ${g.role === 'viewer' ? 'can view' : 'can edit'}</small></span>
                <button class="icon-btn icon-btn--sm" type="button" data-revoke aria-label="Remove ${esc(g.name)}’s access to this board">${icon('x')}</button></div>`).join('')}
              <div class="fwa-add"><select class="select" data-gwho aria-label="Person">${personOptions(b.grants.map((g) => g.id))}</select>
                <select class="select" data-grole aria-label="Access"><option value="editor">Can edit</option><option value="viewer">Can view</option></select>
                <button class="btn btn--sm" type="button" data-grant>${icon('plus', 'i--sm')}Give access</button></div>` : '<p class="hint">Board-only access is not available on this database.</p>'}
              <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap">
                <a class="fwa-mini" href="${esc(b.url)}">${icon('external')}Open board</a>
                <a class="fwa-mini" href="/projects/api/export.php?board_id=${b.id}">${icon('download')}Export to Excel (CSV)</a>
                <button class="fwa-mini${b.archived ? '' : ' fwa-mini--danger'}" type="button" data-archive="${b.archived ? '0' : '1'}">${icon('archive')}${b.archived ? 'Restore board' : 'Archive board'}</button>
              </div>
            </div>
          </details>`).join('') : '<p class="fwa-muted">This project has no boards yet.</p>'}`;
    }
    async function call(body, okMsg) {
      try {
        d = await api.post('projects.php', body);
        draw();
        sync();
        if (okMsg) toast(okMsg);
      } catch (e) { toastError(e); draw(); }
    }
    function sync() {
      const pr = projects.find((x) => x.id === p.id);
      if (!pr) return;
      pr.visibility = d.project.visibility;
      pr.members = d.members.filter((x) => !x.implicit).length;
      pr.boards = d.boards.filter((b) => !b.archived).length;
      render();
    }
    body.addEventListener('change', (e) => {
      const sel = e.target.closest('[data-role]');
      if (sel) { const uid = +sel.closest('[data-uid]').dataset.uid; call({ action: 'member_set', id: p.id, user_id: uid, role: sel.value }, 'Role changed'); }
    });
    body.addEventListener('click', async (e) => {
      const t = e.target.closest('button');
      if (!t) return;
      if (t.dataset.vis && t.dataset.vis !== d.project.visibility) {
        call({ action: 'visibility', id: p.id, visibility: t.dataset.vis }, t.dataset.vis === 'company' ? 'Shared with everyone in the company' : 'Now only for the people on it');
      } else if (t.hasAttribute('data-remove')) {
        const row = t.closest('[data-uid]');
        const x = d.members.find((y) => y.id === +row.dataset.uid);
        if (await dialog({ title: 'Take ' + x.name + ' off the project?', body: 'They can no longer open it' + (d.project.visibility === 'company' ? ' to make changes (they can still read it, as it is shared with everyone).' : '.'), confirm: 'Take off', danger: true })) {
          call({ action: 'member_remove', id: p.id, user_id: x.id }, x.name + ' was taken off');
        }
      } else if (t.id === 'addGo') {
        const who = +$('#addWho', body).value;
        if (!who) return toast('Choose a person to add.', { type: 'error' });
        busy(t, true);
        await call({ action: 'member_set', id: p.id, user_id: who, role: $('#addRole', body).value }, 'Added to the project');
      } else if (t.hasAttribute('data-grant')) {
        const box = t.closest('[data-board]');
        const who = +$('[data-gwho]', box).value;
        if (!who) return toast('Choose a person.', { type: 'error' });
        busy(t, true);
        await call({ action: 'board_grant', board_id: +box.dataset.board, user_id: who, role: $('[data-grole]', box).value }, 'Board access given');
      } else if (t.hasAttribute('data-revoke')) {
        const box = t.closest('[data-board]');
        call({ action: 'board_revoke', board_id: +box.dataset.board, user_id: +t.closest('[data-guid]').dataset.guid }, 'Board access removed');
      } else if (t.dataset.archive) {
        const box = t.closest('[data-board]');
        const b = d.boards.find((x) => x.id === +box.dataset.board);
        const on = t.dataset.archive === '1';
        if (on && !(await dialog({ title: 'Archive “' + b.title + '”?', body: 'It is hidden from the project but nothing is deleted — you can restore it here or in Projects.', confirm: 'Archive' }))) return;
        call({ action: 'board_archive', board_id: b.id, archived: on }, on ? 'Board archived' : 'Board restored');
      }
    });
    api.get('projects.php', { id: p.id }).then((r) => { d = r; draw(); }).catch((e) => { body.innerHTML = `<p class="fwa-muted">${esc(e.message)}</p>`; });
  }

  $('#projBody').addEventListener('click', (e) => {
    const b = e.target.closest('[data-manage]');
    if (!b) return;
    const p = projects.find((x) => x.id === +b.closest('tr').dataset.id);
    if (p) manage(p);
  });
  $('#projSearch').addEventListener('input', (e) => { q = e.target.value.trim(); render(); });
  $$('#projChips .fwa-chip').forEach((c) => c.addEventListener('click', () => { filter = c.dataset.filter; $$('#projChips .fwa-chip').forEach((x) => x.classList.toggle('is-on', x === c)); render(); }));
  render();
})();
