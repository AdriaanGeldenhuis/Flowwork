/**
 * Keyboard Shortcuts Module
 */

(() => {
  'use strict';

  const shortcuts = {
    // Ctrl/Cmd + K - Search
    'k': (e) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        document.getElementById('boardSearchInput')?.focus();
      }
    },
    
    // Ctrl/Cmd + N - New Item
    'n': (e) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        const firstAddInput = document.querySelector('.fw-quick-add-input');
        if (firstAddInput) {
          firstAddInput.focus();
        }
      }
    },
    
    // Ctrl/Cmd + A - Select All
    'a': (e) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        selectAllItems();
      }
    },
    
    // Ctrl/Cmd + D - Duplicate
    'd': (e) => {
      if ((e.ctrlKey || e.metaKey) && window.BoardApp.selectedItems.size > 0) {
        e.preventDefault();
        duplicateSelected();
      }
    },
    
    // Delete/Backspace - Delete selected
    'Delete': (e) => {
      if (window.BoardApp.selectedItems.size > 0) {
        e.preventDefault();
        window.BoardApp.bulkDelete();
      }
    },
    'Backspace': (e) => {
      // Only if not in input
      if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
        if (window.BoardApp.selectedItems.size > 0) {
          e.preventDefault();
          window.BoardApp.bulkDelete();
        }
      }
    },
    
    // Escape - Close modals/clear selection
    // (.fw-static-modal overlays like #modalGuests are server-rendered and must
    // be hidden by their own controller, never removed from the DOM)
    'Escape': (e) => {
      document.querySelector('.fw-modal-overlay')?.remove();
      document.querySelector('.fw-cell-picker-overlay:not(.fw-static-modal)')?.remove();
      window.BoardApp.closeAllDropdowns();
      
      if (window.BoardApp.selectedItems.size > 0) {
        window.BoardApp.clearSelection();
      }
    },
    
    // Ctrl/Cmd + S - Save (prevent default)
    's': (e) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        showToast('✅ Changes saved automatically', 'success');
      }
    },
    
    // Ctrl/Cmd + / - Show shortcuts
    '/': (e) => {
      if (e.ctrlKey || e.metaKey) {
        e.preventDefault();
        showShortcutsModal();
      }
    }
  };

  // Don't hijack keystrokes while the user is typing in a field
  // (Escape is still allowed so it can close modals/pickers).
  const isEditable = (el) => {
    if (!el) return false;
    const t = el.tagName;
    return t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT' || el.isContentEditable;
  };

  // Listen for keyboard events
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' && isEditable(e.target)) return;
    const handler = shortcuts[e.key];
    if (handler) {
      handler(e);
    }
  });

  // ===== SCREEN-READER LIVE REGION =====
  // A single polite live region; window.fwAnnounce(msg) speaks a message to
  // assistive tech. Reuses the existing .fw-sr-only visually-hidden class.
  function getLiveRegion() {
    let el = document.getElementById('fw-live');
    if (!el) {
      el = document.createElement('div');
      el.id = 'fw-live';
      el.className = 'fw-sr-only';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    return el;
  }
  window.fwAnnounce = function (msg) {
    const el = getLiveRegion();
    el.textContent = '';
    // Clearing then setting on the next tick makes SRs re-announce identical text.
    setTimeout(() => { el.textContent = msg; }, 50);
  };

  // ===== SELECT ALL ITEMS =====
  function selectAllItems() {
    document.querySelectorAll('.fw-item-row').forEach(row => {
      const checkbox = row.querySelector('.fw-checkbox');
      if (checkbox && !checkbox.checked) {
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    showToast(`Selected ${window.BoardApp.selectedItems.size} items`, 'info');
  }

  // ===== DUPLICATE SELECTED =====
  function duplicateSelected() {
    const count = window.BoardApp.selectedItems.size;
    if (count === 0) return;

    const run = () => {
      // duplicateItem renders each copy in place — no reload needed
      Array.from(window.BoardApp.selectedItems).forEach(itemId => {
        window.BoardApp.duplicateItem(itemId);
      });
      window.BoardApp.clearSelection();
    };

    if (window.BoardApp.dialog) {
      window.BoardApp.dialog.confirm(`Duplicate ${count} item${count > 1 ? 's' : ''}?`, {
        title: 'Duplicate items',
        confirmLabel: 'Duplicate'
      }).then(ok => { if (ok) run(); });
    } else if (confirm(`Duplicate ${count} items?`)) {
      run();
    }
  }

  // ===== SHOW SHORTCUTS MODAL =====
  function showShortcutsModal() {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 600px;">
        <div class="fw-modal-header">⌨️ Keyboard Shortcuts</div>
        <div class="fw-modal-body">
          <div class="fw-shortcuts-grid">
            <div class="fw-shortcut">
              <kbd>Ctrl/Cmd + K</kbd>
              <span>Search items</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Ctrl/Cmd + N</kbd>
              <span>New item</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Ctrl/Cmd + A</kbd>
              <span>Select all</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Ctrl/Cmd + D</kbd>
              <span>Duplicate selected</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Delete</kbd>
              <span>Delete selected</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Escape</kbd>
              <span>Close modals / Clear selection</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Ctrl/Cmd + S</kbd>
              <span>Save (auto-saved)</span>
            </div>
            <div class="fw-shortcut">
              <kbd>Ctrl/Cmd + /</kbd>
              <span>Show this help</span>
            </div>
          </div>
        </div>
        <div class="fw-modal-footer">
          <button class="fw-btn fw-btn--primary" onclick="this.closest('.fw-modal-overlay').remove()">Got it!</button>
        </div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    // ✅ FIX: Append to .fw-proj
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
  }

  // ===== TOAST NOTIFICATION (delegates to the canonical toast in ui.js) =====
  function showToast(message, type = 'info') {
    if (typeof window.BoardApp.showToast === 'function') {
      window.BoardApp.showToast(message, type);
    } else if (window.fwAnnounce) {
      window.fwAnnounce(message);
    }
  }

  console.log('✅ Keyboard shortcuts module loaded');
  console.log('💡 Press Ctrl/Cmd + / to see all shortcuts');

})();