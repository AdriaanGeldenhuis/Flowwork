// /admin/js/integrations.js — Integrations: copy the webhook address, reload
// after the Yoco keys are saved (the status above them changes), and
// disconnect (clears both keys through /admin/api/settings.php).
(function () {
  'use strict';
  const { $, $$, api, copy, toast, toastError, dialog } = window.FWA;
  $$('[data-copy-text]').forEach((b) => b.addEventListener('click', () => copy(b.dataset.copyText, 'Webhook address copied')));
  document.addEventListener('fwa:saved', () => setTimeout(() => location.reload(), 700));
  const off = $('#yocoDisconnect');
  if (off) off.addEventListener('click', async () => {
    const ok = await dialog({ title: 'Disconnect Yoco?', body: 'Both keys are removed. Invoices no longer offer “Pay now”; payments already made stay on their invoices.', confirm: 'Disconnect', danger: true });
    if (!ok) return;
    try {
      await api.post('settings.php', { area: 'payments', values: { yoco_sec_key: '__clear__', yoco_webhook_secret: '__clear__' } });
      toast('Yoco disconnected');
      setTimeout(() => location.reload(), 700);
    } catch (e) { toastError(e); }
  });
})();
