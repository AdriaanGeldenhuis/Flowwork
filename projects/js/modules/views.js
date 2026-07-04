/**
 * Custom Views Module
 * Save the current view (type + filters + visible columns) and load saved
 * views by applying them client-side — no navigation, no dead URLs.
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // Cache of the last-listed views so loadView can apply without refetching
  let viewCache = {};

  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function fetchViews() {
    return fetch(`/projects/api/view/list.php?board_id=${window.BOARD_DATA.boardId}`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      const views = data.data.views || [];
      viewCache = {};
      views.forEach(v => { viewCache[v.id] = v; });
      return views;
    });
  }

  // ===== SHOW VIEWS MODAL =====
  window.BoardApp.showViewsModal = function() {
    fetchViews()
    .then(views => {
      const viewsHtml = views.length > 0 ? views.map(v => `
        <div class="fw-view-card">
          <div class="fw-view-card__header">
            <div class="fw-view-card__icon">👁️</div>
            <div class="fw-view-card__info">
              <div class="fw-view-card__name">${esc(v.name)}</div>
              <div class="fw-view-card__meta">
                ${esc(v.view_type)} • ${v.filters.length} filter${v.filters.length === 1 ? '' : 's'}
                ${v.is_shared ? ' • <span class="fw-badge">Shared</span>' : ''}
              </div>
            </div>
          </div>
          <div class="fw-view-card__actions">
            <button class="fw-btn fw-btn--secondary" onclick="BoardApp.loadView(${parseInt(v.id, 10)})">Load</button>
            ${v.is_owner ? `<button class="fw-btn fw-btn--text" onclick="BoardApp.deleteView(${parseInt(v.id, 10)})">Delete</button>` : ''}
          </div>
        </div>
      `).join('') : '<div class="fw-empty-state"><div class="fw-empty-icon">👁️</div><div class="fw-empty-title">No saved views yet</div><div class="fw-empty-text">Save your current filters and layout to reuse them later.</div></div>';

      createModal('Custom Views', `
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
    createModal('Save View', `
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
          <li>Current view type (${esc(window.BoardApp.currentView || 'table')})</li>
          <li>Current filters (${window.BoardApp.activeFilters?.length || 0})</li>
          <li>Column visibility</li>
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

    // Visible columns: server-visible minus any active view overlay
    const overlayHidden = window.BoardApp.viewHiddenColumns || new Set();
    const visibleColumns = (window.BOARD_DATA.columns || [])
      .filter(c => Number(c.visible) === 1)
      .map(c => parseInt(c.column_id, 10))
      .filter(id => !overlayHidden.has(id));

    window.BoardApp.apiCall('/projects/api/view/save.php', {
      board_id: window.BOARD_DATA.boardId,
      name: name,
      view_type: window.BoardApp.currentView || 'table',
      filters: JSON.stringify(window.BoardApp.activeFilters || []),
      sorts: JSON.stringify([]),
      visible_columns: JSON.stringify(visibleColumns),
      is_shared: isShared
    })
    .then(() => {
      document.querySelector('.fw-modal-overlay')?.remove();
      showToast('View saved: ' + name, 'success');
    })
    .catch(err => {
      alert('Failed to save view: ' + err.message);
    });
  };

  // ===== LOAD VIEW (apply client-side) =====
  window.BoardApp.loadView = function(viewId) {
    const apply = (view) => {
      if (!view) {
        showToast('View not found', 'error');
        return;
      }

      document.querySelector('.fw-modal-overlay')?.remove();

      // 1) View type
      const type = ['table', 'kanban', 'calendar', 'gantt', 'workload'].includes(view.view_type)
        ? view.view_type : 'table';
      window.BoardApp.switchView(type);

      // 2) Filters (shared visibility engine narrows every view)
      if (typeof window.BoardApp.applyFilterSet === 'function') {
        window.BoardApp.applyFilterSet(view.filters || []);
      }

      // 3) Column visibility — session overlay only. Saved views must not
      // rewrite the board-wide visible flag for everyone.
      if (Array.isArray(view.visible_columns) && view.visible_columns.length > 0) {
        const visibleSet = new Set(view.visible_columns.map(Number));
        const hiddenIds = (window.BOARD_DATA.columns || [])
          .filter(c => Number(c.visible) === 1)
          .map(c => parseInt(c.column_id, 10))
          .filter(id => !visibleSet.has(id));
        applyViewColumnOverlay(hiddenIds);
      } else {
        applyViewColumnOverlay([]);
      }

      // 4) Reflect the loaded view in the URL + Views button
      try {
        const url = new URL(window.location.href);
        url.searchParams.set('view_id', view.id);
        history.replaceState(null, '', url.toString());
      } catch (e) { /* non-fatal */ }
      markActiveView(view.name);

      showToast('View loaded: ' + view.name, 'success');
    };

    if (viewCache[viewId]) {
      apply(viewCache[viewId]);
    } else {
      fetchViews()
        .then(() => apply(viewCache[viewId]))
        .catch(err => alert('Failed to load view: ' + err.message));
    }
  };

  // Session-only column overlay for loaded views (separate from the shared
  // server visibility flag managed by column-visibility.js)
  function applyViewColumnOverlay(hiddenIds) {
    window.BoardApp.viewHiddenColumns = new Set(hiddenIds.map(Number));

    let styleEl = document.getElementById('fw-view-col-overlay');
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = 'fw-view-col-overlay';
      document.head.appendChild(styleEl);
    }
    styleEl.textContent = hiddenIds
      .map(id => `.fw-board-table [data-column-id="${parseInt(id, 10)}"] { display: none; }`)
      .join('\n');
  }

  function markActiveView(name) {
    const btn = document.querySelector('button[onclick="BoardApp.showViewsModal()"]');
    if (!btn) return;
    btn.innerHTML = `
      <svg width="14" height="14" fill="currentColor">
        <path d="M2 2h10v2H2zM2 6h10v2H2zM2 10h10v2H2z"/>
      </svg>
      ${esc(name)}
    `;
  }

  // ===== DELETE VIEW =====
  window.BoardApp.deleteView = function(viewId) {
    if (!confirm('Delete this view?')) return;

    window.BoardApp.apiCall('/projects/api/view/delete.php', {
      view_id: viewId
    })
    .then(() => {
      delete viewCache[viewId];
      showToast('View deleted', 'success');
      document.querySelector('.fw-modal-overlay')?.remove();
      window.BoardApp.showViewsModal();
    })
    .catch(err => {
      alert('Failed to delete view: ' + err.message);
    });
  };

  // ===== AUTO-LOAD a deep-linked saved view (?view_id=N) =====
  // Waits for fw:modules-ready so filters.js and column-visibility.js (which
  // load after this file) are guaranteed to be available.
  (function autoLoadFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const viewId = parseInt(params.get('view_id') || '', 10);
    if (!viewId) return;

    const start = () => window.BoardApp.loadView(viewId);
    if (window.__fwModulesReady) start();
    else window.addEventListener('fw:modules-ready', start, { once: true });
  })();

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 600px;">
        <div class="fw-modal-header">${esc(title)}</div>
        <div class="fw-modal-body">${content}</div>
      </div>
    `;

    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });

    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    return modal;
  }

  // ===== HELPER: TOAST (delegates to the canonical toast in ui.js) =====
  function showToast(message, type = 'info') {
    if (typeof window.BoardApp.showToast === 'function') {
      window.BoardApp.showToast(message, type);
    }
  }

  console.log('✅ Views module loaded');

})();
