// /admin/js/invites.js — Invitations: filter by state, copy the link,
// resend (fresh link + email) and withdraw. Data: boot.invites; changes:
// /admin/api/invites.php.
(function () {
  'use strict';
  const { $, $$, esc, icon, api, toast, toastError, dialog, copy, hue, initials, busy, modal, openModal } = window.FWA;
  const ROLES = FWA.boot.roles || {};
  let invites = FWA.boot.invites || [];
  let filter = invites.some((i) => i.state === 'open') ? 'open' : 'all';

  const days = (s) => Math.ceil((new Date(String(s).replace(' ', 'T')) - Date.now()) / 86400000);
  function status(i) {
    if (i.state === 'joined') return `<span class="fwa-pill" data-tone="success">Joined</span><small class="fwa-muted" style="display:block;margin-top:3px">${esc(i.joined_as || '')}${i.used_at ? ' · ' + esc(FWA.fmtDate(i.used_at)) : ''}</small>`;
    if (i.state === 'expired') return `<span class="fwa-pill" data-tone="muted">Expired</span><small class="fwa-muted" style="display:block;margin-top:3px">${esc(FWA.fmtDate(i.expires_at))}</small>`;
    const d = days(i.expires_at);
    return `<span class="fwa-pill" data-tone="${d <= 1 ? 'warning' : 'gold'}">Waiting</span><small class="fwa-muted" style="display:block;margin-top:3px">${d <= 0 ? 'Expires today' : d === 1 ? 'Expires tomorrow' : 'Expires in ' + d + ' days'}</small>`;
  }
  function row(i) {
    return `<tr data-id="${i.id}"${i.state === 'expired' ? ' class="is-muted"' : ''}>
      <td><div class="fwa-who"><span class="fwa-ava fwa-ava--round" style="--h:${hue(i.email)}">${esc(initials(i.email))}</span><span class="fwa-who__text"><b>${esc(i.email)}</b>${i.sent_by ? `<small>by ${esc(i.sent_by)}</small>` : ''}</span></div></td>
      <td><span class="fwa-pill fwa-pill--plain">${esc((ROLES[i.role] || [i.role])[0])}</span></td>
      <td>${status(i)}</td>
      <td class="hide-narrow">${esc(FWA.fmtDate(i.created_at))}${i.state !== 'joined' ? `<small class="fwa-muted" style="display:block;margin-top:3px">${i.emailed ? 'Emailed' : 'Link only — not emailed'}</small>` : ''}</td>
      <td class="t-actions">${i.state === 'joined' ? '' : `
        ${i.state === 'open' ? `<button class="fwa-mini" type="button" data-link>${icon('i-copy')}Copy link</button>` : ''}
        <button class="fwa-mini" type="button" data-resend>${icon('ia-resend')}${i.state === 'expired' ? 'Renew & send' : 'Resend'}</button>
        <button class="fwa-mini fwa-mini--danger" type="button" data-revoke aria-label="Withdraw the invitation for ${esc(i.email)}">${icon('x')}</button>`}</td></tr>`;
  }
  function render() {
    const list = invites.filter((i) => filter === 'all' || i.state === filter);
    $('#inviteBody').innerHTML = list.map(row).join('');
    $('#inviteEmpty').hidden = list.length > 0;
    $('#inviteEmptyText').innerHTML = filter === 'open' ? 'No invitations are waiting. <button class="fwa-link" type="button" data-add-person>Invite someone</button>' : 'Nothing in this list.';
    $$('#inviteChips .fwa-chip').forEach((c) => {
      c.classList.toggle('is-on', c.dataset.filter === filter);
      const n = c.dataset.filter === 'all' ? invites.length : invites.filter((i) => i.state === c.dataset.filter).length;
      $('small', c).textContent = n;
    });
  }

  function showLink(title, r, email) {
    const ok = r.emailed;
    const m = modal(`<div class="fwm-dialog__head"><h2>${esc(title)}</h2><button class="icon-btn" type="button" data-close aria-label="Close">${icon('x')}</button></div>
      <div class="fwm-dialog__body">
        ${r.emailed === undefined ? '' : `<div class="fwa-banner ${ok ? 'fwa-banner--ok' : 'fwa-banner--warn'}">${icon(ok ? 'check' : 'alert')}<div class="fwa-banner__body">${ok ? `<b>Emailed to ${esc(email)}</b>${r.via === 'mailbox' ? ' from your mailbox.' : '.'}` : `<b>Could not email it.</b> ${esc(r.email_error || '')}`}</div></div>`}
        <div class="field"><span class="label">Invitation link</span><div class="fwa-secret"><code>${esc(r.link)}</code><button class="btn btn--sm" type="button" data-copy>${icon('i-copy', 'i--sm')}Copy</button></div>
        <span class="hint">Anyone with this link can join your company — send it only to ${esc(email)}.</span></div>
      </div>
      <div class="fwm-dialog__foot"><button class="btn btn--primary" type="button" data-close>Done</button></div>`, { width: 560 });
    $('[data-copy]', m).addEventListener('click', () => copy(r.link, 'Link copied'));
    openModal(m);
  }

  $('#inviteBody').addEventListener('click', async (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const inv = invites.find((x) => x.id === +tr.dataset.id);
    if (!inv) return;
    const btn = e.target.closest('button');
    if (!btn) return;
    try {
      if (btn.hasAttribute('data-link')) {
        busy(btn, true);
        const r = await api.post('invites.php', { action: 'link', id: inv.id });
        busy(btn, false);
        await copy(r.link, 'Invitation link copied — paste it into an email or WhatsApp');
      } else if (btn.hasAttribute('data-resend')) {
        busy(btn, true);
        const r = await api.post('invites.php', { action: 'resend', id: inv.id });
        busy(btn, false);
        Object.assign(inv, r.invite, { state: 'open', emailed: inv.emailed || r.emailed, created_at: r.invite.created_at });
        render();
        if (r.emailed) toast('Sent again to ' + inv.email + ' — the link now works for another ' + days(r.invite.expires_at) + ' days');
        else showLink('Invitation renewed', r, inv.email);
      } else if (btn.hasAttribute('data-revoke')) {
        const ok = await dialog({ title: 'Withdraw this invitation?', body: `The link sent to <b>${esc(inv.email)}</b> stops working straight away.`, confirm: 'Withdraw', danger: true });
        if (!ok) return;
        await api.post('invites.php', { action: 'revoke', id: inv.id });
        invites = invites.filter((x) => x.id !== inv.id);
        render();
        toast('Invitation withdrawn');
      }
    } catch (err) { busy(btn, false); toastError(err); }
  });
  $$('#inviteChips .fwa-chip').forEach((c) => c.addEventListener('click', () => { filter = c.dataset.filter; render(); }));
  render();
})();
