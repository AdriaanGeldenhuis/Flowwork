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
    menu.style.zIndex = '9999';

    // Append first (off-screen) so we can measure the real menu size, then
    // clamp it inside the viewport — otherwise it overflows the right/bottom
    // edge on a phone.
    menu.style.visibility = 'hidden';
    menu.style.left = '-9999px';
    menu.style.top = '0px';
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(menu);

    const rect = target.getBoundingClientRect();
    const mw = menu.offsetWidth || 200;
    const mh = menu.offsetHeight || 250;
    const margin = 8;

    // Prefer right-aligned under the button; flip up if it won't fit below.
    let left = rect.right - mw;
    let top = rect.bottom + margin;
    if (top + mh > window.innerHeight - margin) top = rect.top - mh - margin;
    // Final clamp to the viewport on both axes.
    left = Math.max(margin, Math.min(left, window.innerWidth - mw - margin));
    top = Math.max(margin, Math.min(top, window.innerHeight - mh - margin));

    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
    menu.style.visibility = '';

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

  // ===== GLOBAL MODAL FOCUS MANAGEMENT =====
  // One manager for every modal implementation on the board: adds dialog
  // semantics, moves focus in on open, traps Tab inside the topmost overlay,
  // and returns focus to the opener when the overlay is removed.
  const OVERLAY_SELECTOR = '.fw-modal-overlay, .fw-column-modal-overlay';
  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  const openerMap = new WeakMap();

  function topOverlay() {
    // Static picker overlays (e.g. #modalGuests) participate when shown
    const overlays = Array.from(document.querySelectorAll(
      OVERLAY_SELECTOR + ', .fw-cell-picker-overlay[aria-hidden="false"]'
    ));
    return overlays.length ? overlays[overlays.length - 1] : null;
  }

  const modalObserver = new MutationObserver((mutations) => {
    mutations.forEach(m => {
      m.addedNodes.forEach(node => {
        if (!(node instanceof Element) || !node.matches(OVERLAY_SELECTOR)) return;

        openerMap.set(node, document.activeElement);

        const content = node.querySelector('.fw-modal-content, .fw-column-modal-content');
        if (content && !content.hasAttribute('role')) {
          content.setAttribute('role', 'dialog');
          content.setAttribute('aria-modal', 'true');
        }

        // Move focus in unless the modal already focused something itself
        setTimeout(() => {
          if (node.isConnected && !node.contains(document.activeElement)) {
            node.querySelector(FOCUSABLE)?.focus();
          }
        }, 60);
      });

      m.removedNodes.forEach(node => {
        if (!(node instanceof Element) || !node.matches || !node.matches(OVERLAY_SELECTOR)) return;
        const opener = openerMap.get(node);
        if (opener && opener.isConnected && typeof opener.focus === 'function') {
          opener.focus();
        }
      });
    });
  });
  modalObserver.observe(document.body, { childList: true, subtree: true });

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Tab') return;
    const overlay = topOverlay();
    if (!overlay) return;

    const focusables = Array.from(overlay.querySelectorAll(FOCUSABLE))
      .filter(el => el.offsetParent !== null);
    if (focusables.length === 0) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (!overlay.contains(document.activeElement)) {
      e.preventDefault();
      first.focus();
    } else if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  });

  console.log('✅ UI module loaded');

})();
