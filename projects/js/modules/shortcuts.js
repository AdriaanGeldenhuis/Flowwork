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
    'Escape': (e) => {
      document.querySelector('.fw-modal-overlay')?.remove();
      document.querySelector('.fw-cell-picker-overlay')?.remove();
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

  // Listen for keyboard events
  document.addEventListener('keydown', (e) => {
    const handler = shortcuts[e.key];
    if (handler) {
      handler(e);
    }
  });

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
    if (window.BoardApp.selectedItems.size === 0) return;
    
    if (!confirm(`Duplicate ${window.BoardApp.selectedItems.size} items?`)) return;
    
    const promises = Array.from(window.BoardApp.selectedItems).map(itemId => {
      return window.BoardApp.apiCall('/projects/api/item.duplicate.php', {
        item_id: itemId
      });
    });
    
    Promise.all(promises)
      .then(() => {
        showToast('✅ Items duplicated', 'success');
        setTimeout(() => window.location.reload(), 1000);
      })
      .catch(err => {
        alert('Failed to duplicate: ' + err.message);
      });
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

  // ===== TOAST NOTIFICATION =====
  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fw-toast fw-toast--${type}`;
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 12px 20px;
      background: var(--modal-bg);
      border: 1px solid var(--modal-border);
      border-radius: 8px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
      color: var(--modal-text);
      font-size: 14px;
      z-index: 10000;
      backdrop-filter: blur(10px);
      animation: slideInRight 0.3s ease;
    `;
    
    toast.textContent = message;
    // ✅ FIX: Append to .fw-proj
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(toast);
    
    setTimeout(() => {
      toast.style.animation = 'slideOutRight 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 2500);
  }

  console.log('✅ Keyboard shortcuts module loaded');
  console.log('💡 Press Ctrl/Cmd + / to see all shortcuts');

})();