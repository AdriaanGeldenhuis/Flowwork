/**
 * Custom Views Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== SHOW VIEWS MODAL =====
  window.BoardApp.showViewsModal = function() {
    // Load views
    fetch(`/projects/api/view/list.php?board_id=${window.BOARD_DATA.boardId}`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      
      const views = data.data.views || [];
      
      const viewsHtml = views.length > 0 ? views.map(v => `
        <div class="fw-view-card">
          <div class="fw-view-card__header">
            <div class="fw-view-card__icon">👁️</div>
            <div class="fw-view-card__info">
              <div class="fw-view-card__name">${v.name}</div>
              <div class="fw-view-card__meta">
                ${v.view_type} • ${v.filters.length} filters
                ${v.is_shared ? ' • <span class="fw-badge">Shared</span>' : ''}
              </div>
            </div>
          </div>
          <div class="fw-view-card__actions">
            <button class="fw-btn fw-btn--secondary" onclick="BoardApp.loadView(${v.id})">Load</button>
            ${v.is_owner ? `<button class="fw-btn fw-btn--text" onclick="BoardApp.deleteView(${v.id})">Delete</button>` : ''}
          </div>
        </div>
      `).join('') : '<div class="fw-empty-state"><div class="fw-empty-icon">👁️</div><div class="fw-empty-title">No saved views yet</div></div>';
      
      const modal = createModal('Custom Views', `
        <div class="fw-views-list">
          ${viewsHtml}
        </div>
        
        <div class="fw-modal-footer">
          <button class="fw-btn fw-btn--primary" onclick="BoardApp.showSaveViewModal()">
            💾 Save Current View
          </button>
        </div>
      `);
    })
    .catch(err => {
      alert('Failed to load views: ' + err.message);
    });
  };

  // ===== SHOW SAVE VIEW MODAL =====
  window.BoardApp.showSaveViewModal = function() {
    const modal = createModal('Save View', `
      <div class="fw-form-group">
        <label>View Name</label>
        <input type="text" id="viewNameInput" class="fw-input" placeholder="My Custom View" />
      </div>
      
      <div class="fw-form-group">
        <label class="fw-checkbox-label">
          <input type="checkbox" id="viewSharedCheck" />
          <span>Share with team</span>
        </label>
      </div>
      
      <div class="fw-info-box">
        This will save:
        <ul>
          <li>Current filters (${window.BoardApp.activeFilters?.length || 0})</li>
          <li>Column visibility</li>
          <li>Sort order</li>
        </ul>
      </div>
      
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCurrentView()">Save View</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('viewNameInput')?.focus(), 100);
  };

  // ===== SAVE CURRENT VIEW =====
  window.BoardApp.saveCurrentView = function() {
    const name = document.getElementById('viewNameInput')?.value.trim();
    const isShared = document.getElementById('viewSharedCheck')?.checked ? 1 : 0;
    
    if (!name) {
      alert('Please enter a view name');
      return;
    }
    
    // Get visible columns
    const visibleColumns = [];
    document.querySelectorAll('th[data-column-id]').forEach(th => {
      if (th.offsetParent !== null) { // visible
        visibleColumns.push(parseInt(th.dataset.columnId));
      }
    });
    
    window.BoardApp.apiCall('/projects/api/view/save.php', {
      board_id: window.BOARD_DATA.boardId,
      name: name,
      view_type: window.BoardApp.currentView || 'table',
      filters: JSON.stringify(window.BoardApp.activeFilters || []),
      sorts: JSON.stringify([]),
      visible_columns: JSON.stringify(visibleColumns),
      is_shared: isShared
    })
    .then(data => {
      document.querySelector('.fw-modal-overlay')?.remove();
      showToast('✅ View saved: ' + name, 'success');
    })
    .catch(err => {
      alert('Failed to save view: ' + err.message);
    });
  };

  // ===== LOAD VIEW =====
  window.BoardApp.loadView = function(viewId) {
    // Reload page with view parameter
    window.location.href = `?board_id=${window.BOARD_DATA.boardId}&view=${viewId}`;
  };

  // ===== DELETE VIEW =====
  window.BoardApp.deleteView = function(viewId) {
    if (!confirm('Delete this view?')) return;
    
    window.BoardApp.apiCall('/projects/api/view/delete.php', {
      view_id: viewId
    })
    .then(() => {
      showToast('✅ View deleted', 'success');
      window.BoardApp.showViewsModal();
    })
    .catch(err => {
      alert('Failed to delete view: ' + err.message);
    });
  };

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 600px;">
        <div class="fw-modal-header">${title}</div>
        <div class="fw-modal-body">${content}</div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    // ✅ FIX: Append to .fw-proj
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    return modal;
  }

  // ===== HELPER: TOAST =====
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
      color: var(--modal-text);
      font-size: 14px;
      z-index: 10000;
      backdrop-filter: blur(10px);
    `;
    toast.textContent = message;
    // ✅ FIX: Append to .fw-proj
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }

  console.log('✅ Views module loaded');

})();