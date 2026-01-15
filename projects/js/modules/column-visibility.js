/**
 * Column Visibility Module
 * Show/hide columns dynamically
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};
  window.BoardApp.hiddenColumns = new Set();

  // ===== SHOW COLUMN VISIBILITY MODAL =====
  window.BoardApp.showColumnVisibility = function() {
    const columns = window.BOARD_DATA.columns || [];
    
    const columnsHtml = columns.map(col => {
      const isHidden = window.BoardApp.hiddenColumns.has(col.column_id);
      
      return `
        <label class="fw-column-toggle">
          <input type="checkbox" 
            class="fw-checkbox" 
            data-column-id="${col.column_id}"
            ${!isHidden ? 'checked' : ''}
            onchange="BoardApp.toggleColumnVisibility(${col.column_id}, this.checked)" />
          <span class="fw-column-toggle-label">
            <span class="fw-column-toggle-icon">${getColumnIcon(col.type)}</span>
            <span class="fw-column-toggle-name">${col.name}</span>
            <span class="fw-column-toggle-type">${col.type}</span>
          </span>
        </label>
      `;
    }).join('');
    
    const modal = createModal('Column Visibility', `
      <div class="fw-column-visibility-list">
        ${columnsHtml}
      </div>
      
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--text" onclick="BoardApp.showAllColumns()">Show All</button>
        <button class="fw-btn fw-btn--text" onclick="BoardApp.hideAllColumns()">Hide All</button>
        <button class="fw-btn fw-btn--primary" onclick="this.closest('.fw-modal-overlay').remove()">Done</button>
      </div>
    `);
  };

  // ===== TOGGLE COLUMN VISIBILITY =====
  window.BoardApp.toggleColumnVisibility = function(columnId, visible) {
    if (visible) {
      window.BoardApp.hiddenColumns.delete(columnId);
    } else {
      window.BoardApp.hiddenColumns.add(columnId);
    }
    
    // Update DOM
    const headers = document.querySelectorAll(`th[data-column-id="${columnId}"]`);
    const cells = document.querySelectorAll(`td[data-column-id="${columnId}"]`);
    
    headers.forEach(th => th.style.display = visible ? '' : 'none');
    cells.forEach(td => td.style.display = visible ? '' : 'none');
    
    // Save to localStorage
    localStorage.setItem(
      `fw-hidden-columns-${window.BOARD_DATA.boardId}`,
      JSON.stringify([...window.BoardApp.hiddenColumns])
    );
  };

  // ===== SHOW ALL COLUMNS =====
  window.BoardApp.showAllColumns = function() {
    window.BOARD_DATA.columns.forEach(col => {
      const checkbox = document.querySelector(`input[data-column-id="${col.column_id}"]`);
      if (checkbox) {
        checkbox.checked = true;
        window.BoardApp.toggleColumnVisibility(col.column_id, true);
      }
    });
  };

  // ===== HIDE ALL COLUMNS =====
  window.BoardApp.hideAllColumns = function() {
    window.BOARD_DATA.columns.forEach(col => {
      const checkbox = document.querySelector(`input[data-column-id="${col.column_id}"]`);
      if (checkbox) {
        checkbox.checked = false;
        window.BoardApp.toggleColumnVisibility(col.column_id, false);
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

  // ===== LOAD SAVED VISIBILITY =====
  function loadSavedVisibility() {
    const saved = localStorage.getItem(`fw-hidden-columns-${window.BOARD_DATA.boardId}`);
    if (saved) {
      try {
        const hiddenIds = JSON.parse(saved);
        hiddenIds.forEach(id => {
          window.BoardApp.hiddenColumns.add(id);
          window.BoardApp.toggleColumnVisibility(id, false);
        });
      } catch (e) {
        console.error('Failed to load column visibility:', e);
      }
    }
  }

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 500px;">
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

  // Load saved visibility on init
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadSavedVisibility);
  } else {
    loadSavedVisibility();
  }

  console.log('✅ Column visibility module loaded');

})();