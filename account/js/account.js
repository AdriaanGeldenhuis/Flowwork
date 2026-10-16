// /account/js/account.js — Signed-in devices.
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const list = $('#devices');
  const bar = $('#othersBar');
  const toastEl = $('#toast');
  let toastTimer = 0;
  // Present inside the Flowwork Android app (injected by the app before this script runs).
  const native = window.FlowWorkApp || null;
  let nativeStatus = null;   // { notifications, permission, push } from the app
  let lastData = null;       // last /account/api/sessions.php response

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  function toast(text, isError) {
    toastEl.textContent = text;
    toastEl.classList.toggle('is-error', !!isError);
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3500);
  }

  async function api(method, body) {
    const res = await fetch('/account/api/sessions.php', {
      method,
      credentials: 'same-origin',
      headers: Object.assign({ Accept: 'application/json' }, body ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    if (res.status === 401) { location.href = '/login.php?redirect=' + encodeURIComponent('/account/'); throw new Error('Signed out'); }
    let data = null;
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || 'Something went wrong. Please try again.');
    return data;
  }

  function ago(seconds) {
    if (seconds < 120) return 'Active now';
    const m = Math.round(seconds / 60);
    if (m < 60) return 'Active ' + m + ' min ago';
    const h = Math.round(m / 60);
    if (h < 24) return 'Active ' + h + ' hour' + (h === 1 ? '' : 's') + ' ago';
    const d = Math.round(h / 24);
    return 'Active ' + d + ' day' + (d === 1 ? '' : 's') + ' ago';
  }
  function since(seconds) {
    const d = new Date(Date.now() - seconds * 1000);
    return 'Signed in ' + d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
  }
  function iconFor(dev) {
    if (dev.is_app) return 'a-app';
    if (/iPad|tablet/i.test(dev.label)) return 'a-tablet';
    if (/iPhone|Android/i.test(dev.label)) return 'a-phone';
    return 'a-laptop';
  }

  function render(devices) {
    list.setAttribute('aria-busy', 'false');
    devices = devices.slice().sort((a, b) => (b.current ? 1 : 0) - (a.current ? 1 : 0)); // this device first
    const others = devices.filter((d) => !d.current).length;
    bar.hidden = others === 0;
    $('#countLabel').textContent = devices.length === 1 ? '1 signed-in device' : devices.length + ' signed-in devices';
    if (!devices.length) {
      list.innerHTML = '<li class="fwa-empty">No devices are signed in.</li>';
      return;
    }
    list.innerHTML = devices.map((d) => `
      <li class="fwa-card ${d.current ? 'is-current' : ''}" data-id="${d.id}">
        <span class="fwa-card__icon"><svg class="fwa-i"><use href="#${iconFor(d)}"/></svg></span>
        <div class="fwa-card__body">
          <div class="fwa-card__title">${esc(d.label || 'Unknown device')}
            ${d.current ? '<span class="fwa-badge fwa-badge--here">This device</span>' : ''}
            ${d.remembered ? '<span class="fwa-badge fwa-badge--kept">Stays signed in</span>' : ''}
            ${d.push ? '<span class="fwa-badge fwa-badge--push">Notifications on</span>' : ''}</div>
          <div class="fwa-card__meta">${d.current || d.seen_ago < 120 ? '<span class="fwa-dot"></span>' : ''}${esc(d.current ? 'Active now' : ago(d.seen_ago))}
            · ${esc(since(d.created_ago))}${d.ip ? ' · IP ' + esc(d.ip) : ''}</div>
        </div>
        <div class="fwa-card__actions">
          ${d.current
            ? '<a class="fwa-btn fwa-btn--sm" href="/logout.php">Sign out</a>'
            : `<button class="fwa-btn fwa-btn--sm fwa-btn--danger" type="button" data-revoke="${d.id}">Sign out</button>`}
        </div>
      </li>`).join('');
  }

  // Two taps instead of a confirm() dialog (dialogs are unreliable inside the app's WebView).
  function armed(btn, label) {
    if (btn.classList.contains('is-armed')) return true;
    btn.dataset.label = btn.dataset.label || btn.innerHTML;
    btn.classList.add('is-armed');
    btn.textContent = label;
    setTimeout(() => {
      if (!btn.isConnected || btn.disabled) return;
      btn.classList.remove('is-armed');
      btn.innerHTML = btn.dataset.label;
    }, 4000);
    return false;
  }

  // ------------------------------------------------------------ phone notifications
  const pushPanel = $('#pushPanel');
  const pushText = $('#pushText');
  const pushEnable = $('#pushEnable');
  const pushTest = $('#pushTest');

  function renderPush() {
    if (!lastData) return;
    const devices = lastData.devices || [];
    const phones = devices.filter((d) => d.push).length;
    const here = devices.find((d) => d.current) || {};
    let text = '';
    let off = false;
    let canEnable = false;
    if (native && nativeStatus) {
      if (!nativeStatus.notifications) {
        text = 'Off on this phone. Turn them on to get new email, event reminders and mentions.';
        off = true;
        canEnable = true;
      } else if (!lastData.push_ready) {
        text = "Allowed on this phone, but the server isn't sending yet (Firebase isn't set up).";
      } else if (here.push) {
        text = 'On for this phone: new email, event reminders and mentions arrive here.';
      } else {
        text = 'Allowed on this phone and connecting… reopen this page in a moment.';
      }
    } else if (native) {
      text = 'Checking this phone…';
    } else if (phones > 0) {
      text = 'On for ' + (phones === 1 ? '1 phone' : phones + ' phones') + ' with the Flowwork app.';
    } else {
      text = 'Install the Flowwork app on your phone and sign in to get new email, reminders and mentions there.';
    }
    pushText.textContent = text;
    pushPanel.classList.toggle('is-off', off);
    pushEnable.hidden = !canEnable;
    pushTest.hidden = !(lastData.push_ready && phones > 0);
    pushPanel.hidden = false;
  }

  if (native) {
    window.addEventListener('flowwork-native', (e) => {
      if (e.detail && e.detail.type === 'status') {
        const turnedOn = nativeStatus && !nativeStatus.notifications && e.detail.notifications;
        nativeStatus = e.detail;
        renderPush();
        if (turnedOn) setTimeout(load, 1500); // let the app bind its token, then refresh
      }
    });
    native.request('status');
  }

  pushEnable.addEventListener('click', () => { if (native) native.request('askNotifications'); });

  pushTest.addEventListener('click', async () => {
    if (pushTest.disabled) return;
    pushTest.disabled = true;
    try {
      const r = await api('POST', { action: 'test_push' });
      toast(r.sent === 1 ? 'Test sent to 1 phone' : 'Test sent to ' + r.sent + ' phones');
    } catch (err) {
      toast(err.message, true);
    } finally {
      setTimeout(() => { pushTest.disabled = false; }, 3000);
    }
  });

  async function load() {
    try {
      lastData = await api('GET');
      renderPush();
      render(lastData.devices);
    } catch (e) {
      list.setAttribute('aria-busy', 'false');
      list.innerHTML = `<li class="fwa-empty">${esc(e.message)}</li>`;
    }
  }

  list.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-revoke]');
    if (!btn || btn.disabled) return;
    if (!armed(btn, 'Tap again to sign out')) return;
    btn.disabled = true;
    try {
      const r = await api('POST', { action: 'revoke', id: Number(btn.dataset.revoke) });
      lastData = r;
      renderPush();
      render(r.devices);
      toast('Device signed out');
    } catch (err) {
      btn.disabled = false;
      toast(err.message, true);
    }
  });

  $('#revokeOthers').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    if (btn.disabled || !armed(btn, 'Tap again to confirm')) return;
    btn.disabled = true;
    try {
      const r = await api('POST', { action: 'revoke_others' });
      lastData = r;
      renderPush();
      render(r.devices);
      toast(r.signed_out === 1 ? '1 device signed out' : r.signed_out + ' devices signed out');
    } catch (err) {
      toast(err.message, true);
    } finally {
      btn.disabled = false;
      btn.classList.remove('is-armed');
      if (btn.dataset.label) btn.innerHTML = btn.dataset.label;
    }
  });

  load();
})();
