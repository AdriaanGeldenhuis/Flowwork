/**
 * UI Helper Functions
 * Shared primitives every other module can rely on:
 *  - BoardApp.showDropdown / closeAllDropdowns
 *  - BoardApp.showToast (single canonical toast with optional action button)
 *  - BoardApp.escapeHtml
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  window.BoardApp.showDropdown = function(target, html) {
    const menu = document.createElement('div');
    menu.className = 'fw-dropdown';
    menu.innerHTML = html;
    menu.style.position = 'fixed';

    const rect = target.getBoundingClientRect();
    let left = rect.left - 200;
    let top = rect.bottom + 8;

    if (left < 20) left = rect.left;
    if (top + 250 > window.innerHeight) top = rect.top - 260;

    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.zIndex = '9999';

    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(menu);

    setTimeout(() => {
      document.addEventListener('click', () => menu.remove(), { once: true });
    }, 100);
  };

  window.BoardApp.closeAllDropdowns = function() {
    document.querySelectorAll('.fw-dropdown').forEach(m => m.remove());
  };

  // ===== ESCAPE HTML =====
  window.BoardApp.escapeHtml = function(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };

  // ===== TOAST NOTIFICATIONS =====
  // Single stacking container; opts: { duration, actionLabel, onAction, onExpire }
  function getToastContainer() {
    let el = document.getElementById('fw-toast-container');
    if (!el) {
      el = document.createElement('div');
      el.id = 'fw-toast-container';
      el.className = 'fw-toast-container';
      (document.querySelector('.fw-proj') || document.body).appendChild(el);
    }
    return el;
  }

  window.BoardApp.showToast = function(message, type = 'info', opts = {}) {
    const duration = opts.duration || 3000;

    if (window.fwAnnounce) window.fwAnnounce(message);

    const toast = document.createElement('div');
    toast.className = `fw-toast fw-toast--${type}`;

    const msg = document.createElement('span');
    msg.className = 'fw-toast__msg';
    msg.textContent = message;
    toast.appendChild(msg);

    let expired = false;
    let actioned = false;

    const dismiss = (viaExpire) => {
      if (expired) return;
      expired = true;
      toast.style.animation = 'fwToastOut 0.25s ease forwards';
      setTimeout(() => toast.remove(), 250);
      if (viaExpire && !actioned && typeof opts.onExpire === 'function') {
        try { opts.onExpire(); } catch (e) { console.error(e); }
      }
    };

    if (opts.actionLabel && typeof opts.onAction === 'function') {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'fw-toast__action';
      btn.textContent = opts.actionLabel;
      btn.addEventListener('click', () => {
        actioned = true;
        try { opts.onAction(); } catch (e) { console.error(e); }
        dismiss(false);
      });
      toast.appendChild(btn);
    }

    getToastContainer().appendChild(toast);
    const timer = setTimeout(() => dismiss(true), duration);
    toast.addEventListener('click', (e) => {
      if (e.target === toast || e.target === msg) {
        clearTimeout(timer);
        dismiss(true);
      }
    });

    return toast;
  };

  console.log('✅ UI module loaded');

})();
