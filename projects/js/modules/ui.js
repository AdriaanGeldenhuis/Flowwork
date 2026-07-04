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

  // ===== DIALOG SERVICE (styled replacements for confirm()/prompt()) =====
  function buildDialog({ title, bodyHtml, buttons }) {
    const overlay = document.createElement('div');
    overlay.className = 'fw-modal-overlay';
    overlay.innerHTML = `
      <div class="fw-modal-content fw-slide-up" role="dialog" aria-modal="true" style="max-width: 420px;">
        <div class="fw-modal-header">
          <h3 style="margin:0;font-size:17px;font-weight:700;">${window.BoardApp.escapeHtml(title)}</h3>
        </div>
        <div class="fw-modal-body">
          ${bodyHtml}
          <div class="fw-modal-footer">${buttons}</div>
        </div>
      </div>
    `;
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(overlay);
    return overlay;
  }

  window.BoardApp.dialog = {
    /**
     * @returns {Promise<boolean>}
     */
    confirm(message, { title = 'Are you sure?', confirmLabel = 'Confirm', danger = false } = {}) {
      return new Promise(resolve => {
        const overlay = buildDialog({
          title,
          bodyHtml: `<p style="margin:0 0 8px;">${window.BoardApp.escapeHtml(message)}</p>`,
          buttons: `
            <button type="button" class="fw-btn fw-btn--secondary" data-act="cancel">Cancel</button>
            <button type="button" class="fw-btn ${danger ? 'fw-btn--danger' : 'fw-btn--primary'}" data-act="ok">${window.BoardApp.escapeHtml(confirmLabel)}</button>
          `
        });

        const previouslyFocused = document.activeElement;
        const finish = (result) => {
          overlay.remove();
          document.removeEventListener('keydown', onKey);
          if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus();
          resolve(result);
        };
        const onKey = (e) => {
          if (e.key === 'Escape') { e.stopPropagation(); finish(false); }
          if (e.key === 'Enter') { e.stopPropagation(); finish(true); }
        };

        overlay.querySelector('[data-act="ok"]').addEventListener('click', () => finish(true));
        overlay.querySelector('[data-act="cancel"]').addEventListener('click', () => finish(false));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(false); });
        document.addEventListener('keydown', onKey);
        overlay.querySelector('[data-act="ok"]').focus();
      });
    },

    /**
     * @returns {Promise<string|null>} trimmed value, or null on cancel
     */
    prompt(message, { title = 'Enter a value', placeholder = '', value = '', confirmLabel = 'Save', maxLength = 0 } = {}) {
      return new Promise(resolve => {
        const overlay = buildDialog({
          title,
          bodyHtml: `
            <label style="display:block;margin-bottom:8px;font-size:13px;color:var(--text-secondary,inherit);">${window.BoardApp.escapeHtml(message)}</label>
            <input type="text" class="fw-input" id="fwDialogPromptInput" style="width:100%;"
                   placeholder="${window.BoardApp.escapeHtml(placeholder)}"
                   value="${window.BoardApp.escapeHtml(value)}"
                   ${maxLength > 0 ? `maxlength="${maxLength}"` : ''} />
          `,
          buttons: `
            <button type="button" class="fw-btn fw-btn--secondary" data-act="cancel">Cancel</button>
            <button type="button" class="fw-btn fw-btn--primary" data-act="ok">${window.BoardApp.escapeHtml(confirmLabel)}</button>
          `
        });

        const input = overlay.querySelector('#fwDialogPromptInput');
        const previouslyFocused = document.activeElement;
        const finish = (result) => {
          overlay.remove();
          if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus();
          resolve(result);
        };
        const submit = () => {
          const v = input.value.trim();
          if (!v) { input.focus(); return; }
          finish(v);
        };

        overlay.querySelector('[data-act="ok"]').addEventListener('click', submit);
        overlay.querySelector('[data-act="cancel"]').addEventListener('click', () => finish(null));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) finish(null); });
        input.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') { e.stopPropagation(); submit(); }
          if (e.key === 'Escape') { e.stopPropagation(); finish(null); }
        });

        setTimeout(() => { input.focus(); input.select(); }, 50);
      });
    }
  };

  console.log('✅ UI module loaded');

})();
