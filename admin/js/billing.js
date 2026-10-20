// /admin/js/billing.js — Subscription: move to another plan, cancel or
// resume (/admin/api/billing.php). Each asks first and reloads after.
(function () {
  'use strict';
  const { $, $$, esc, api, toast, toastError, dialog, busy } = window.FWA;
  $$('[data-plan]').forEach((b) => b.addEventListener('click', async () => {
    const up = b.dataset.up === '1';
    const ok = await dialog({
      title: (up ? 'Upgrade to ' : 'Move to ') + b.dataset.name + '?',
      body: `Your plan becomes <b>${esc(b.dataset.name)}</b> at <b>${esc(b.dataset.price)}</b> a month${up ? ', and the extra room is ready at once' : ''}.`,
      confirm: up ? 'Upgrade' : 'Move plan',
    });
    if (!ok) return;
    busy(b, true);
    try {
      const r = await api.post('billing.php', { action: 'change_plan', plan_id: +b.dataset.plan });
      toast('You are now on the ' + r.plan + ' plan');
      setTimeout(() => location.reload(), 800);
    } catch (e) { toastError(e); busy(b, false); }
  }));
  const t = $('#subToggle');
  if (t) t.addEventListener('click', async () => {
    const cancel = t.dataset.action === 'cancel';
    const end = FWA.boot.periodEnd;
    const ok = await dialog(cancel
      ? { title: 'Cancel your subscription?', body: `Flowwork stops renewing it${end ? ' and you keep access until <b>' + esc(end) + '</b>' : ''}. Nothing is deleted; you can resume any time before then.`, confirm: 'Cancel subscription', cancel: 'Keep it', danger: true }
      : { title: 'Resume your subscription?', body: 'It carries on renewing as before.', confirm: 'Resume' });
    if (!ok) return;
    busy(t, true);
    try {
      await api.post('billing.php', { action: t.dataset.action });
      toast(cancel ? 'Subscription cancelled' : 'Welcome back — your subscription carries on');
      setTimeout(() => location.reload(), 800);
    } catch (e) { toastError(e); busy(t, false); }
  });
})();
