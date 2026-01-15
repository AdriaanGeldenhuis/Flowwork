/**
 * Flowwork Suppliers Module
 * Handles supplier columns, linking, and selection
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};
  
  // Load suppliers from server
  window.BoardApp.loadSuppliers = async function() {
    try {
      const response = await fetch('/projects/api/supplier/list.php', {
        method: 'GET',
        headers: {
          'X-CSRF-Token': window.BOARD_DATA.csrfToken
        }
      });
      
      const data = await response.json();
      
      if (!data.ok) {
        throw new Error(data.error || 'Failed to load suppliers');
      }
      
      return data.suppliers || [];
    } catch (err) {
      console.error('Load suppliers error:', err);
      return [];
    }
  };
  
  // Link supplier to column
  window.BoardApp.linkSupplierToColumn = async function(columnId, supplierId) {
    try {
      const form = new FormData();
      form.append('column_id', columnId);
      form.append('supplier_id', supplierId);
      
      const response = await fetch('/projects/api/column/supplier-link.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': window.BOARD_DATA.csrfToken
        },
        body: form
      });
      
      const data = await response.json();
      
      if (!data.ok) {
        throw new Error(data.error || 'Failed to link supplier');
      }
      
      // Show success message
      window.BoardApp.showToast('Supplier linked successfully!', 'success');
      
      // Reload page to show changes
      setTimeout(() => {
        window.location.reload();
      }, 500);
      
    } catch (err) {
      console.error('Link supplier error:', err);
      window.BoardApp.showToast(err.message || 'Failed to link supplier', 'error');
    }
  };
  
  // Show supplier picker for cell
  window.BoardApp.editSupplier = async function(itemId, columnId, cellElement) {
    const suppliers = window.BOARD_DATA.suppliers || [];
    
    if (suppliers.length === 0) {
      window.BoardApp.showToast('No suppliers available', 'warning');
      return;
    }
    
    const currentValue = cellElement.textContent.trim();
    
    const content = `
      <div class="fw-picker-header">Select Supplier</div>
      <div class="fw-picker-search">
        <input type="text" class="fw-picker-search-input" placeholder="Search suppliers..." id="supplierSearchInput" oninput="BoardApp.filterSupplierOptions(this.value)" />
      </div>
      <div class="fw-picker-options" id="supplierOptionsList">
        <button class="fw-picker-option" onclick="BoardApp.saveSupplierValue(${itemId}, ${columnId}, '')" data-name="None">
          <span style="width:32px;height:32px;border-radius:50%;background:#64748b;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">?</span>
          None
        </button>
        ${suppliers.map(s => `
          <button class="fw-picker-option ${s.name === currentValue ? 'active' : ''}" onclick="BoardApp.saveSupplierValue(${itemId}, ${columnId}, ${s.id})" data-name="${s.name}">
            <span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6c5ce7,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:14px;">🏢</span>
            <div style="flex:1;">
              <div style="font-weight:600;">${s.name}</div>
              ${s.phone ? `<div style="font-size:12px;color:var(--text-tertiary);">${s.phone}</div>` : ''}
            </div>
            ${s.preferred ? '<span style="font-size:18px;">⭐</span>' : ''}
          </button>
        `).join('')}
      </div>
    `;
    
    const picker = document.createElement('div');
    picker.className = 'fw-cell-picker-overlay';
    picker.innerHTML = `<div class="fw-cell-picker">${content}</div>`;
    document.body.appendChild(picker);
    
    picker.addEventListener('click', (e) => {
      if (e.target === picker) picker.remove();
    });
    
    setTimeout(() => document.getElementById('supplierSearchInput')?.focus(), 50);
  };
  
  window.BoardApp.filterSupplierOptions = function(query) {
    const options = document.querySelectorAll('#supplierOptionsList .fw-picker-option');
    const lowerQuery = query.toLowerCase();
    
    options.forEach(opt => {
      const name = opt.dataset.name || 'None';
      opt.style.display = name.toLowerCase().includes(lowerQuery) ? 'flex' : 'none';
    });
  };
  
  window.BoardApp.saveSupplierValue = async function(itemId, columnId, supplierId) {
    try {
      const form = new FormData();
      form.append('item_id', itemId);
      form.append('column_id', columnId);
      form.append('value', supplierId);
      
      const response = await fetch('/projects/api/cell/update.php', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': window.BOARD_DATA.csrfToken
        },
        body: form
      });
      
      const data = await response.json();
      
      if (!data.ok) {
        throw new Error(data.error || 'Failed to update supplier');
      }
      
      document.querySelector('.fw-cell-picker-overlay')?.remove();
      window.location.reload();
      
    } catch (err) {
      console.error('Save supplier error:', err);
      window.BoardApp.showToast(err.message || 'Failed to save supplier', 'error');
    }
  };
  
  // Show link supplier modal for column
  window.BoardApp.showLinkSupplierModal = async function(columnId) {
    const suppliers = await window.BoardApp.loadSuppliers();
    
    if (suppliers.length === 0) {
      window.BoardApp.showToast('No suppliers available. Create suppliers in CRM first.', 'warning');
      return;
    }
    
    const content = `
      <div class="fw-picker-header">Link Supplier to Column</div>
      <div class="fw-picker-body">
        <p style="margin-bottom: var(--space-lg); color: var(--text-secondary);">
          When you link a supplier to this column, all items will automatically pull data from that supplier.
        </p>
        <div class="fw-picker-options">
          ${suppliers.map(s => `
            <button class="fw-picker-option" onclick="BoardApp.linkSupplierToColumn(${columnId}, ${s.id})">
              <span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6c5ce7,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">🏢</span>
              <div style="flex:1;">
                <div style="font-weight:600;">${s.name}</div>
                ${s.email ? `<div style="font-size:12px;color:var(--text-tertiary);">${s.email}</div>` : ''}
              </div>
              ${s.preferred ? '<span style="font-size:18px;">⭐</span>' : ''}
            </button>
          `).join('')}
        </div>
      </div>
    `;
    
    const modal = document.createElement('div');
    modal.className = 'fw-cell-picker-overlay';
    modal.innerHTML = `<div class="fw-cell-picker">${content}</div>`;
    document.body.appendChild(modal);
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
  };
  
  // Toast notification system
  window.BoardApp.showToast = function(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fw-toast fw-toast--${type}`;
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 16px 24px;
      background: linear-gradient(135deg, rgba(33, 38, 45, 0.98), rgba(22, 27, 34, 0.98));
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 12px;
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.6);
      color: white;
      font-size: 14px;
      font-weight: 600;
      z-index: 10000;
      backdrop-filter: blur(20px);
      animation: toastIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    `;
    
    const icons = {
      success: '✅',
      error: '❌',
      warning: '⚠️',
      info: 'ℹ️'
    };
    
    toast.innerHTML = `${icons[type] || icons.info} ${message}`;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
      toast.classList.add('fw-toast--closing');
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  };
  
  console.log('✅ Suppliers module initialized');
  
})();