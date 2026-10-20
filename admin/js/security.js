// /admin/js/security.js — Security: sign a single device out
// (/admin/api/security.php). The settings on the page are handled by admin.js.
(function () {
  'use strict';
  const { $, api, toast, toastError, dialog } = window.FWA;
  const body = $('#deviceBody');
  if (!body) return;
  body.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-signout]');
    if (!b) return;
    const tr = b.closest('tr');
    const who = tr.querySelector('.fwa-who__text b').textContent;
    const dev = tr.children[1].childNodes[0].textContent.trim();
    const ok = await dialog({ title: 'Sign this device out?', body: `<b>${FWA.esc(who)}</b> on <b>${FWA.esc(dev)}</b> will have to sign in again on it. Their other devices stay signed in.`, confirm: 'Sign out' });
    if (!ok) return;
    try {
      await api.post('security.php', { action: 'signout_device', session_id: +tr.dataset.sid });
      tr.remove();
      toast('Signed out of ' + dev);
    } catch (err) { toastError(err); }
  });
})();
