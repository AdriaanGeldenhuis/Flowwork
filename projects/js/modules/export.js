/**
 * Export/Import Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== EXPORT BOARD =====
  window.BoardApp.exportBoard = function() {
    const modal = createModal('Export Board', `
      <div class="fw-export-options">
        <button class="fw-export-card" onclick="BoardApp.exportToExcel()">
          <div class="fw-export-card__icon">📊</div>
          <div class="fw-export-card__content">
            <div class="fw-export-card__title">Excel (.xlsx)</div>
            <div class="fw-export-card__desc">Export to Microsoft Excel format with formatting</div>
          </div>
          <div class="fw-export-card__arrow">→</div>
        </button>
        
        <button class="fw-export-card" onclick="BoardApp.exportToCSV()">
          <div class="fw-export-card__icon">📄</div>
          <div class="fw-export-card__content">
            <div class="fw-export-card__title">CSV (.csv)</div>
            <div class="fw-export-card__desc">Export to CSV for use in spreadsheets</div>
          </div>
          <div class="fw-export-card__arrow">→</div>
        </button>
        
        <button class="fw-export-card" onclick="BoardApp.exportToJSON()">
          <div class="fw-export-card__icon">💾</div>
          <div class="fw-export-card__content">
            <div class="fw-export-card__title">JSON (.json)</div>
            <div class="fw-export-card__desc">Export raw data for backup or API use</div>
          </div>
          <div class="fw-export-card__arrow">→</div>
        </button>
      </div>
    `);
  };

  // ===== EXPORT TO EXCEL =====
  window.BoardApp.exportToExcel = function() {
    const url = `/projects/api/export/excel.php?board_id=${window.BOARD_DATA.boardId}`;
    window.location.href = url;
    
    document.querySelector('.fw-modal-overlay')?.remove();
    showToast('📊 Exporting to Excel...', 'info');
  };

  // ===== EXPORT TO CSV =====
  window.BoardApp.exportToCSV = function() {
    const url = `/projects/api/export/excel.php?board_id=${window.BOARD_DATA.boardId}`;
    window.location.href = url;
    
    document.querySelector('.fw-modal-overlay')?.remove();
    showToast('📄 Exporting to CSV...', 'info');
  };

  // ===== EXPORT TO JSON =====
  window.BoardApp.exportToJSON = function() {
    const data = {
      board: {
        id: window.BOARD_DATA.boardId,
        title: document.querySelector('.fw-board-title-input')?.value || 'Board',
        exported_at: new Date().toISOString()
      },
      columns: window.BOARD_DATA.columns,
      groups: window.BOARD_DATA.groups,
      items: window.BOARD_DATA.items,
      values: window.BOARD_DATA.valuesMap
    };
    
    const json = JSON.stringify(data, null, 2);
    const blob = new Blob([json], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = `board_${window.BOARD_DATA.boardId}_${Date.now()}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    document.querySelector('.fw-modal-overlay')?.remove();
    showToast('💾 JSON exported!', 'success');
  };

  // ===== SHOW IMPORT MODAL =====
  window.BoardApp.showImportModal = function() {
    const groups = window.BOARD_DATA.groups || [];
    
    const modal = createModal('Import Items', `
      <div class="fw-import-form">
        <div class="fw-form-group">
          <label>Import To Group</label>
          <select id="importGroupSelect" class="fw-select">
            ${groups.map(g => `<option value="${g.id}">${g.name}</option>`).join('')}
          </select>
        </div>
        
        <div class="fw-form-group">
          <label>CSV File</label>
          <input type="file" id="importFileInput" class="fw-file-input" accept=".csv,.txt" />
          <div class="fw-file-hint">
            📋 CSV should have headers: Item, Status, Assigned To, etc.
          </div>
        </div>
        
        <div class="fw-info-box">
          <strong>CSV Format Example:</strong>
          <pre style="margin-top: 8px; font-size: 12px;">Item,Status,Priority
Task 1,todo,high
Task 2,working,medium</pre>
        </div>
      </div>
      
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.importCSV()">Import</button>
      </div>
    `);
  };

  // ===== IMPORT CSV =====
  window.BoardApp.importCSV = function() {
    const groupId = document.getElementById('importGroupSelect')?.value;
    const fileInput = document.getElementById('importFileInput');
    
    if (!groupId) {
      alert('Please select a group');
      return;
    }
    
    if (!fileInput || !fileInput.files || !fileInput.files[0]) {
      alert('Please select a CSV file');
      return;
    }
    
    const file = fileInput.files[0];
    
    const formData = new FormData();
    formData.append('board_id', window.BOARD_DATA.boardId);
    formData.append('group_id', groupId);
    formData.append('csv_file', file);
    
    fetch('/projects/api/import/csv.php', {
      method: 'POST',
      headers: {
        'X-CSRF-Token': window.BOARD_DATA.csrfToken
      },
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      
      document.querySelector('.fw-modal-overlay')?.remove();
      
      if (data.data.errors && data.data.errors.length > 0) {
        showToast(`⚠️ Imported ${data.data.imported} items with ${data.data.errors.length} errors`, 'warning');
        console.warn('Import errors:', data.data.errors);
      } else {
        showToast(`✅ Imported ${data.data.imported} items successfully!`, 'success');
      }
      
      setTimeout(() => window.location.reload(), 1500);
    })
    .catch(err => {
      alert('Failed to import: ' + err.message);
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

  console.log('✅ Export/Import module loaded');

})();