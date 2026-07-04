/**
 * Column Visibility Module
 * Single source of truth: board_columns.visible (server flag, shared by the
 * whole team). board.php renders ALL columns and hides visible=0 ones via the
 * #fw-col-visibility stylesheet, so toggling here is a pure CSS operation —
 * no reload, and hidden columns can be re-shown with their data intact.
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  // Rebuild the visibility stylesheet from BOARD_DATA.columns[].visible
  window.BoardApp.refreshColumnVisibilityCss = function() {
    let styleEl = document.getElementById('fw-col-visibility');
    if (!styleEl) {
      styleEl = document.createElement('style');
      styleEl.id = 'fw-col-visibility';
      document.head.appendChild(styleEl);
    }
    styleEl.textContent = (window.BOARD_DATA.columns || [])
      .filter(c => !Number(c.visible))
      .map(c => `.fw-board-table [data-column-id="${parseInt(c.column_id, 10)}"] { display: none; }`)
      .join('\n');
  };

  // Persist + apply a visibility change (used by the modal and Hide Column)
  window.BoardApp.setColumnVisibility = function(columnId, visible) {
    const col = (window.BOARD_DATA.columns || []).find(c => String(c.column_id) === String(columnId));
    if (!col) return Promise.resolve();

    const previous = col.visible;
    col.visible = visible ? 1 : 0;
    window.BoardApp.refreshColumnVisibilityCss();

    return window.BoardApp.apiCall('/projects/api/column.visibility.php', {
      column_id: columnId,
      visible: visible ? 1 : 0
    }).then(() => {
      if (window.BoardApp.showToast) {
        window.BoardApp.showToast(visible ? `Column "${col.name}" shown` : `Column "${col.name}" hidden`, 'success');
      }
    }).catch(err => {
      // Roll back the optimistic change
      col.visible = previous;
      window.BoardApp.refreshColumnVisibilityCss();
      if (window.BoardApp.showToast) window.BoardApp.showToast('Failed to update column visibility: ' + err.message, 'error');
      else alert('Failed to update column visibility: ' + err.message);
    });
  };

  // Back-compat name used by the modal checkboxes
  window.BoardApp.toggleColumnVisibility = function(columnId, visible) {
    return window.BoardApp.setColumnVisibility(columnId, visible);
  };

  // ===== SHOW COLUMN VISIBILITY MODAL =====
  window.BoardApp.showColumnVisibility = function() {
    const columns = window.BOARD_DATA.columns || [];

    const columnsHtml = columns.map(col => {
      const isVisible = Number(col.visible) === 1;

      return `
        <label class="fw-column-toggle">
          <input type="checkbox"
            class="fw-checkbox"
            data-column-id="${parseInt(col.column_id, 10)}"
            ${isVisible ? 'checked' : ''}
            onchange="BoardApp.toggleColumnVisibility(${parseInt(col.column_id, 10)}, this.checked)" />
          <span class="fw-column-toggle-label">
            <span class="fw-column-toggle-icon">${getColumnIcon(col.type)}</span>
            <span class="fw-column-toggle-name">${esc(col.name)}</span>
            <span class="fw-column-toggle-type">${esc(col.type)}</span>
          </span>
        </label>
      `;
    }).join('');

    createModal('Column Visibility', `
      <p style="font-size:12px;color:var(--text-muted);margin:0 0 12px;">
        Visibility is shared with everyone on this board.
      </p>
      <div class="fw-column-visibility-list">
        ${columnsHtml}
      </div>

      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--text" onclick="BoardApp.showAllColumns()">Show All</button>
        <button class="fw-btn fw-btn--primary" onclick="this.closest('.fw-modal-overlay').remove()">Done</button>
      </div>
    `);
  };

  // ===== SHOW ALL COLUMNS =====
  window.BoardApp.showAllColumns = function() {
    (window.BOARD_DATA.columns || []).forEach(col => {
      if (Number(col.visible) !== 1) {
        const checkbox = document.querySelector(`input[data-column-id="${col.column_id}"]`);
        if (checkbox) checkbox.checked = true;
        window.BoardApp.setColumnVisibility(col.column_id, true);
      }
    });
  };

  // ===== GET COLUMN ICON =====
  function getColumnIcon(type) {
    const icons = {
      'text': '📝',
      'number': '🔢',
      'status': '🔄',
      'people': '👤',
      'date': '📅',
      'timeline': '📊',
      'dropdown': '▼',
      'formula': '∑',
      'priority': '⚡',
      'supplier': '🏢',
      'checkbox': '☑️'
    };
    return icons[type] || '📌';
  }

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 500px;">
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

  // One-time migration: the old implementation kept per-user hidden columns in
  // localStorage. That store is obsolete (server flag wins) — drop it.
  try { localStorage.removeItem(`fw-hidden-columns-${window.BOARD_DATA.boardId}`); } catch (e) { /* ignore */ }

  console.log('✅ Column visibility module loaded');

})();
