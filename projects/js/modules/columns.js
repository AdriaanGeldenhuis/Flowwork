/**
 * Column Management Module - COMPLETE VERSION
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // Store selected type globally
  let selectedColumnType = null;

  // ===== SHOW ADD COLUMN MODAL =====
  window.BoardApp.showAddColumnModal = function() {
    console.log('➕ Opening add column modal...');
    
    // Reset selected type
    selectedColumnType = null;
    
    const columnTypes = [
      // Basic
      { type: 'text', icon: '📝', label: 'Text', description: 'Plain text or notes', color: '#64748b' },
      { type: 'number', icon: '🔢', label: 'Number', description: 'Numeric values', color: '#3b82f6' },
      { type: 'checkbox', icon: '☑️', label: 'Checkbox', description: 'Yes/No or Done/Not Done', color: '#10b981' },
      
      // Status & Priority
      { type: 'status', icon: '🎯', label: 'Status', description: 'Track item status', color: '#8b5cf6' },
      { type: 'priority', icon: '🔥', label: 'Priority', description: 'Set task priority', color: '#ef4444' },
      { type: 'progress', icon: '📊', label: 'Progress', description: '0-100% completion', color: '#06b6d4' },
      
      // People & Assignment
      { type: 'people', icon: '👤', label: 'People', description: 'Assign team members', color: '#f59e0b' },
      { type: 'supplier', icon: '🏢', label: 'Supplier', description: 'Link suppliers', color: '#6366f1' },
      
      // Date & Time
      { type: 'date', icon: '📅', label: 'Date', description: 'Single date picker', color: '#ec4899' },
      { type: 'timeline', icon: '📆', label: 'Timeline', description: 'Start and end dates', color: '#8b5cf6' },
      
      // Selection
      { type: 'dropdown', icon: '📋', label: 'Dropdown', description: 'Select from options', color: '#14b8a6' },
      { type: 'tags', icon: '🏷️', label: 'Tags', description: 'Multiple labels', color: '#a855f7' },
      
      // Contact
      { type: 'email', icon: '✉️', label: 'Email', description: 'Email addresses', color: '#0ea5e9' },
      { type: 'phone', icon: '📞', label: 'Phone', description: 'Phone numbers', color: '#22c55e' },
      { type: 'link', icon: '🔗', label: 'Link', description: 'Web URLs', color: '#3b82f6' },
      
      // Advanced
      { type: 'formula', icon: '∑', label: 'Formula', description: 'Calculate values', color: '#f97316' },
      { type: 'files', icon: '📎', label: 'Files', description: 'Upload attachments', color: '#64748b' }
    ];
    
    const categorizedTypes = {
      'Basic': columnTypes.filter(t => ['text', 'number', 'checkbox'].includes(t.type)),
      'Status & Progress': columnTypes.filter(t => ['status', 'priority', 'progress'].includes(t.type)),
      'People & Teams': columnTypes.filter(t => ['people', 'supplier'].includes(t.type)),
      'Dates & Time': columnTypes.filter(t => ['date', 'timeline'].includes(t.type)),
      'Selection': columnTypes.filter(t => ['dropdown', 'tags'].includes(t.type)),
      'Contact': columnTypes.filter(t => ['email', 'phone', 'link'].includes(t.type)),
      'Advanced': columnTypes.filter(t => ['formula', 'files'].includes(t.type))
    };
    
    let html = '<div class="fw-column-type-grid">';
    
    for (const [category, types] of Object.entries(categorizedTypes)) {
      html += `
        <div class="fw-column-category">
          <h4 class="fw-column-category-title">${category}</h4>
          <div class="fw-column-types">
      `;
      
      types.forEach(col => {
        html += `
          <button class="fw-column-type-card" 
            data-type="${col.type}" 
            onclick="event.preventDefault(); window.BoardApp.selectColumnType('${col.type}')">
            <div class="fw-column-type-icon" style="background: ${col.color};">
              ${col.icon}
            </div>
            <div class="fw-column-type-info">
              <div class="fw-column-type-label">${col.label}</div>
              <div class="fw-column-type-desc">${col.description}</div>
            </div>
          </button>
        `;
      });
      
      html += `
          </div>
        </div>
      `;
    }
    
    html += '</div>';
    
    createColumnModal('Add Column', html);
  };

  // ===== SELECT COLUMN TYPE =====
  window.BoardApp.selectColumnType = function(type) {
    console.log('✅ Selected column type:', type);
    
    selectedColumnType = type;
    
    document.querySelector('.fw-column-modal-overlay')?.remove();
    
    showColumnNameModal(type);
  };

  // ===== SHOW AGGREGATION SETTINGS =====
window.BoardApp.showAggregationSettings = function() {
  console.log('📊 Opening aggregation settings...');
  
  const numericColumns = window.BOARD_DATA.columns.filter(c => 
    c.type === 'number' || c.type === 'formula'
  );
  
  if (numericColumns.length === 0) {
    alert('No numeric columns available for aggregation');
    return;
  }
  
  let html = '<div class="fw-agg-settings">';
  html += '<p style="color: var(--text-muted); margin-bottom: 16px;">Choose how to aggregate each numeric column:</p>';
  
  numericColumns.forEach(col => {
    const config = col.config ? JSON.parse(col.config) : {};
    const currentAgg = config.agg || 'sum';
    
    html += `
      <div class="fw-form-group">
        <label>${col.name}</label>
        <select id="agg_${col.column_id}" class="fw-input">
          <option value="sum" ${currentAgg === 'sum' ? 'selected' : ''}>Sum (Total)</option>
          <option value="avg" ${currentAgg === 'avg' ? 'selected' : ''}>Average</option>
          <option value="min" ${currentAgg === 'min' ? 'selected' : ''}>Minimum</option>
          <option value="max" ${currentAgg === 'max' ? 'selected' : ''}>Maximum</option>
          <option value="count" ${currentAgg === 'count' ? 'selected' : ''}>Count</option>
        </select>
      </div>
    `;
  });
  
  html += '</div>';
  
  const modal = document.createElement('div');
  modal.className = 'fw-modal-overlay';
  modal.innerHTML = `
    <div class="fw-modal-content" style="max-width: 500px;">
      <div class="fw-modal-header">
        <h3 style="margin:0;">📊 Aggregation Settings</h3>
        <button onclick="this.closest('.fw-modal-overlay').remove()" style="background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:24px;">×</button>
      </div>
      <div class="fw-modal-body">
        ${html}
      </div>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveAggregationSettings()">Save Settings</button>
      </div>
    </div>
  `;
  
  modal.addEventListener('click', (e) => {
    if (e.target === modal) modal.remove();
  });
  
  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(modal);
};

// ===== SAVE AGGREGATION SETTINGS =====
window.BoardApp.saveAggregationSettings = function() {
  const numericColumns = window.BOARD_DATA.columns.filter(c => 
    c.type === 'number' || c.type === 'formula'
  );
  
  const updates = [];
  
  numericColumns.forEach(col => {
    const select = document.getElementById(`agg_${col.column_id}`);
    if (select) {
      const newAgg = select.value;
      const config = col.config ? JSON.parse(col.config) : {};
      config.agg = newAgg;
      
      updates.push({
        column_id: col.column_id,
        config: JSON.stringify(config)
      });
    }
  });
  
  console.log('💾 Saving aggregation settings:', updates);
  
  // Update all columns
  Promise.all(updates.map(update => 
    window.BoardApp.apiCall('/projects/api/column/update.php', update)
  ))
  .then(() => {
    console.log('✅ Aggregation settings saved');
    
    document.querySelector('.fw-modal-overlay')?.remove();
    
    const toast = document.createElement('div');
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 16px 24px;
      background: rgba(0, 200, 117, 0.9);
      color: white;
      border-radius: 8px;
      font-weight: 600;
      z-index: 10001;
    `;
    toast.textContent = '✅ Aggregation settings saved!';
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(toast);
    
    setTimeout(() => {
      toast.remove();
      window.location.reload();
    }, 1000);
  })
  .catch(err => {
    console.error('❌ Save aggregation settings error:', err);
    alert('Failed to save settings:\n\n' + err.message);
  });
};

  // ===== SHOW COLUMN NAME MODAL =====
  function showColumnNameModal(type) {
    console.log('📝 Showing name modal for type:', type);
    
    const columnIcons = {
      'text': '📝', 'number': '🔢', 'checkbox': '☑️',
      'status': '🎯', 'priority': '🔥', 'progress': '📊',
      'people': '👤', 'supplier': '🏢',
      'date': '📅', 'timeline': '📆',
      'dropdown': '📋', 'tags': '🏷️',
      'email': '✉️', 'phone': '📞', 'link': '🔗',
      'formula': '∑', 'files': '📎'
    };
    
    const icon = columnIcons[type] || '📌';
    
    let extraFields = '';
    
    if (type === 'dropdown') {
      extraFields = `
        <div class="fw-form-group">
          <label>Dropdown Options</label>
          <textarea id="dropdownOptions" class="fw-textarea" rows="4" placeholder="Enter each option on a new line">Option 1
Option 2
Option 3</textarea>
          <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">Enter each option on a new line</p>
        </div>
      `;
    } else if (type === 'formula') {
      // ✅ ENHANCED FORMULA UI (Match Edit Formula)
      const availableColumns = window.BOARD_DATA.columns.filter(c => 
        c.type === 'number' || c.type === 'formula'
      );
      
      const columnsHtml = availableColumns.length > 0 ? availableColumns.map(c => `
        <button type="button" class="fw-formula-col-btn" onclick="document.getElementById('formulaExpression').value += ' {${c.name}} '">
          {${c.name}}
        </button>
      `).join('') : '<p style="color:var(--text-muted);font-size:13px;">No numeric columns available yet</p>';
      
      extraFields = `
        <div class="fw-form-group">
          <label>Formula Expression</label>
          <input type="text" id="formulaExpression" class="fw-input" placeholder="{Quantity} * {Price/Unit}" />
          <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">
            💡 Click column names below to insert them, or type manually using {Column Name}
          </p>
        </div>
        
        <div class="fw-form-group">
          <label>Available Columns</label>
          <div class="fw-formula-columns">
            ${columnsHtml}
          </div>
        </div>
        
        <div class="fw-form-group">
          <label>Operators</label>
          <div class="fw-formula-operators">
            <button type="button" class="fw-formula-op-btn" onclick="document.getElementById('formulaExpression').value += ' + '">+</button>
            <button type="button" class="fw-formula-op-btn" onclick="document.getElementById('formulaExpression').value += ' - '">−</button>
            <button type="button" class="fw-formula-op-btn" onclick="document.getElementById('formulaExpression').value += ' * '">×</button>
            <button type="button" class="fw-formula-op-btn" onclick="document.getElementById('formulaExpression').value += ' / '">÷</button>
            <button type="button" class="fw-formula-op-btn" onclick="document.getElementById('formulaExpression').value += ' ( '">(</button>
            <button type="button" class="fw-formula-op-btn" onclick="document.getElementById('formulaExpression').value += ' ) '">)</button>
          </div>
        </div>
        
        <div class="fw-form-group">
          <label>Decimal Places</label>
          <input type="number" id="formulaPrecision" class="fw-input" value="2" min="0" max="10" />
        </div>
        
        <div class="fw-info-box" style="background:rgba(139,92,246,0.1);border:1px solid rgba(139,92,246,0.3);padding:16px;border-radius:8px;margin-top:16px;">
          <strong>Examples:</strong>
          <ul style="margin:8px 0 0 20px;font-size:13px;color:var(--text-secondary);">
            <li><code>{Quantity} * {Price}</code> - Basic multiplication</li>
            <li><code>({Price} * {Quantity}) * 1.2</code> - Add 20% markup</li>
            <li><code>{Total} - {Discount}</code> - Subtract discount</li>
          </ul>
        </div>
      `;
    } else if (type === 'number') {
      extraFields = `
        <div class="fw-form-group">
          <label>Number Format</label>
          <select id="numberFormat" class="fw-input">
            <option value="decimal">Decimal (1,234.56)</option>
            <option value="currency">Currency ($1,234.56)</option>
            <option value="percentage">Percentage (12.34%)</option>
          </select>
        </div>
        <div class="fw-form-group">
          <label>Decimal Places</label>
          <input type="number" id="numberPrecision" class="fw-input" value="2" min="0" max="10" />
        </div>
      `;
    }
    
    const html = `
      <div class="fw-column-setup">
        <div class="fw-column-setup-header">
          <div class="fw-column-type-badge">
            <span class="fw-column-type-icon-lg">${icon}</span>
            <span class="fw-column-type-name">${type.charAt(0).toUpperCase() + type.slice(1)}</span>
          </div>
        </div>
        
        <input type="hidden" id="selectedColumnType" value="${type}" />
        
        <div class="fw-form-group">
          <label>Column Name</label>
          <input type="text" id="columnNameInput" class="fw-input" placeholder="Enter column name..." value="${type.charAt(0).toUpperCase() + type.slice(1)}" />
        </div>
        
        ${extraFields}
        
        <div class="fw-modal-footer">
          <button type="button" class="fw-btn fw-btn--secondary" onclick="window.BoardApp.showAddColumnModal()">
            ← Back
          </button>
          <button type="button" class="fw-btn fw-btn--primary" onclick="window.BoardApp.createColumn()">
            Create Column
          </button>
        </div>
      </div>
    `;
    
    createColumnModal(`Create ${type.charAt(0).toUpperCase() + type.slice(1)} Column`, html);
    
    setTimeout(() => {
      const input = document.getElementById('columnNameInput');
      input?.focus();
      input?.select();
    }, 100);
  }

  // ===== CREATE COLUMN =====
  window.BoardApp.createColumn = function() {
    const typeInput = document.getElementById('selectedColumnType');
    const type = typeInput ? typeInput.value : selectedColumnType;
    
    if (!type) {
      alert('Column type not found. Please try again.');
      console.error('No column type selected');
      return;
    }
    
    const name = document.getElementById('columnNameInput')?.value.trim();
    
    if (!name) {
      alert('Please enter a column name');
      return;
    }
    
    console.log('🔨 Creating column:', { type, name });
    
    const data = {
      board_id: window.BOARD_DATA.boardId,
      name: name,
      type: type,
      width: 150
    };
    
    if (type === 'dropdown') {
      const optionsText = document.getElementById('dropdownOptions')?.value || '';
      const options = optionsText
        .split('\n')
        .map(o => o.trim())
        .filter(o => o);
      
      if (options.length === 0) {
        alert('Please add at least one dropdown option');
        return;
      }
      
      data.config = JSON.stringify({ options });
      console.log('Dropdown config:', data.config);
      
    } else if (type === 'formula') {
      const formula = document.getElementById('formulaExpression')?.value.trim();
      const precision = parseInt(document.getElementById('formulaPrecision')?.value) || 2;
      
      if (!formula) {
        alert('Please enter a formula expression');
        return;
      }
      
      // Validate formula contains at least one column reference
      if (!formula.includes('{') || !formula.includes('}')) {
        const confirmResult = window.confirm(
          'Your formula doesn\'t contain any column references (e.g. {Column Name}).\n\n' +
          'This will result in the same value for all items.\n\n' +
          'Continue anyway?'
        );
        if (!confirmResult) return;
      }
      
      data.config = JSON.stringify({ 
        formula: formula, 
        precision: precision, 
        agg: 'sum' 
      });
      console.log('Formula config:', data.config);
      
    } else if (type === 'number') {
      const format = document.getElementById('numberFormat')?.value || 'decimal';
      const precision = parseInt(document.getElementById('numberPrecision')?.value) || 2;
      data.config = JSON.stringify({ format, precision, agg: 'sum' });
      console.log('Number config:', data.config);
    }
    
    console.log('📤 Sending data:', data);
    
    // Show loading toast
    const loadingToast = document.createElement('div');
    loadingToast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 16px 24px;
      background: rgba(139, 92, 246, 0.9);
      color: white;
      border-radius: 8px;
      font-weight: 600;
      z-index: 10001;
    `;
    loadingToast.textContent = '⏳ Creating column...';
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(loadingToast);
    
    window.BoardApp.apiCall('/projects/api/column.create.php', data)
      .then(response => {
        console.log('✅ Column created:', response);
        
        document.querySelector('.fw-column-modal-overlay')?.remove();
        loadingToast.remove();
        
        const successToast = document.createElement('div');
        successToast.style.cssText = `
          position: fixed;
          top: 80px;
          right: 20px;
          padding: 16px 24px;
          background: rgba(0, 200, 117, 0.9);
          color: white;
          border-radius: 8px;
          font-weight: 600;
          z-index: 10001;
          animation: slideInRight 0.3s ease;
        `;
        
        if (type === 'formula') {
          successToast.textContent = `✅ ${name} formula created! Calculating...`;
        } else {
          successToast.textContent = `✅ ${name} column created!`;
        }
        
        container.appendChild(successToast);
        
        setTimeout(() => {
          successToast.remove();
          
          // If formula, trigger calculation before reload
          if (type === 'formula' && response.column_id) {
            window.BoardApp.apiCall('/projects/api/formula/calculate.php', {
              board_id: window.BOARD_DATA.boardId,
              column_id: response.column_id
            })
            .then(() => {
              console.log('✅ Formula calculated');
              window.location.reload();
            })
            .catch(err => {
              console.error('Formula calculation error:', err);
              window.location.reload(); // Reload anyway
            });
          } else {
            window.location.reload();
          }
        }, 1500);
      })
      .catch(err => {
        console.error('❌ Create column error:', err);
        loadingToast.remove();
        
        const errorToast = document.createElement('div');
        errorToast.style.cssText = `
          position: fixed;
          top: 80px;
          right: 20px;
          padding: 16px 24px;
          background: rgba(239, 68, 68, 0.9);
          color: white;
          border-radius: 8px;
          font-weight: 600;
          z-index: 10001;
        `;
        errorToast.textContent = '❌ Failed to create column';
        container.appendChild(errorToast);
        
        setTimeout(() => errorToast.remove(), 3000);
        
        alert('Failed to create column:\n\n' + err.message);
      });
  };

  // ===== UPDATE COLUMN NAME =====
  window.BoardApp.updateColumnName = function(columnId, newName) {
    if (!newName.trim()) return;
    
    console.log('📝 Updating column name:', columnId, newName);
    
    window.BoardApp.apiCall('/projects/api/column/update.php', {
      column_id: columnId,
      name: newName.trim()
    })
      .then(() => {
        console.log('✅ Column name updated');
        
        const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
        if (column) column.name = newName.trim();
      })
      .catch(err => {
        console.error('❌ Update column name error:', err);
        alert('Failed to update column name');
      });
  };

  // ===== SHOW COLUMN MENU =====
  window.BoardApp.showColumnMenu = function(columnId, event) {
    event?.stopPropagation();
    
    const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
    if (!column) return;
    
    let menuItems = '';
    
    if (column.type === 'formula') {
      menuItems = `
        <button class="fw-dropdown-item" onclick="BoardApp.editFormula(${columnId})">
          ∑ Edit Formula
        </button>
        <button class="fw-dropdown-item" onclick="BoardApp.recalculateColumn(${columnId})">
          🔄 Recalculate All
        </button>
        <button class="fw-dropdown-item" onclick="BoardApp.editColumnSettings(${columnId})">
          ⚙️ Column Settings
        </button>
        <button class="fw-dropdown-item" onclick="BoardApp.hideColumn(${columnId})">
          👁️ Hide Column
        </button>
        <button class="fw-dropdown-item fw-dropdown-item--danger" onclick="BoardApp.deleteColumn(${columnId})">
          🗑️ Delete Column
        </button>
      `;
    } else {
      menuItems = `
        <button class="fw-dropdown-item" onclick="BoardApp.editColumnSettings(${columnId})">
          ⚙️ Column Settings
        </button>
        <button class="fw-dropdown-item" onclick="BoardApp.hideColumn(${columnId})">
          👁️ Hide Column
        </button>
        <button class="fw-dropdown-item fw-dropdown-item--danger" onclick="BoardApp.deleteColumn(${columnId})">
          🗑️ Delete Column
        </button>
      `;
    }
    
    window.BoardApp.showDropdown(event.target, menuItems);
  };

  // ===== EDIT FORMULA =====
  window.BoardApp.editFormula = function(columnId) {
    window.BoardApp.closeAllDropdowns();
    
    const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
    if (!column) return;
    
    const config = column.config ? JSON.parse(column.config) : {};
    const currentFormula = config.formula || '';
    const currentPrecision = config.precision || 2;
    
    const availableColumns = window.BOARD_DATA.columns.filter(c => 
      c.column_id !== columnId && (c.type === 'number' || c.type === 'formula')
    );
    
    const columnsHtml = availableColumns.map(c => `
      <button class="fw-formula-col-btn" onclick="document.getElementById('formulaInput').value += ' {${c.name}} '">
        {${c.name}}
      </button>
    `).join('');
    
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 600px;">
        <div class="fw-modal-header">
          <h3 style="margin:0;">∑ Edit Formula: ${column.name}</h3>
          <button onclick="this.closest('.fw-modal-overlay').remove()" style="background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:24px;">×</button>
        </div>
        
        <div class="fw-modal-body">
          <div class="fw-form-group">
            <label>Formula Expression</label>
            <input type="text" id="formulaInput" class="fw-input" value="${currentFormula}" placeholder="{Quantity} * {Price/Unit}" />
            <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">
              💡 Click column names below to insert them, or type manually using {Column Name}
            </p>
          </div>
          
          <div class="fw-form-group">
            <label>Available Columns</label>
            <div class="fw-formula-columns">
              ${columnsHtml}
            </div>
          </div>
          
          <div class="fw-form-group">
            <label>Operators</label>
            <div class="fw-formula-operators">
              <button class="fw-formula-op-btn" onclick="document.getElementById('formulaInput').value += ' + '">+</button>
              <button class="fw-formula-op-btn" onclick="document.getElementById('formulaInput').value += ' - '">−</button>
              <button class="fw-formula-op-btn" onclick="document.getElementById('formulaInput').value += ' * '">×</button>
              <button class="fw-formula-op-btn" onclick="document.getElementById('formulaInput').value += ' / '">÷</button>
              <button class="fw-formula-op-btn" onclick="document.getElementById('formulaInput').value += ' ( '">(</button>
              <button class="fw-formula-op-btn" onclick="document.getElementById('formulaInput').value += ' ) '">)</button>
            </div>
          </div>
          
          <div class="fw-form-group">
            <label>Decimal Places</label>
            <input type="number" id="formulaPrecision" class="fw-input" value="${currentPrecision}" min="0" max="10" />
          </div>
          
          <div class="fw-info-box" style="background:rgba(139,92,246,0.1);border:1px solid rgba(139,92,246,0.3);padding:16px;border-radius:8px;margin-top:16px;">
            <strong>Examples:</strong>
            <ul style="margin:8px 0 0 20px;font-size:13px;color:var(--text-secondary);">
              <li><code>{Quantity} * {Price}</code> - Basic multiplication</li>
              <li><code>({Price} * {Quantity}) * 1.2</code> - Add 20% markup</li>
              <li><code>{Total} - {Discount}</code> - Subtract discount</li>
            </ul>
          </div>
        </div>
        
        <div class="fw-modal-footer">
          <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
          <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveFormula(${columnId})">Save Formula</button>
        </div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    
    setTimeout(() => document.getElementById('formulaInput')?.focus(), 100);
  };

  // ===== SAVE FORMULA =====
  window.BoardApp.saveFormula = function(columnId) {
    const formula = document.getElementById('formulaInput')?.value.trim();
    const precision = parseInt(document.getElementById('formulaPrecision')?.value) || 2;
    
    if (!formula) {
      alert('Please enter a formula');
      return;
    }
    
    console.log('💾 Saving formula:', { columnId, formula, precision });
    
    const config = JSON.stringify({
      formula: formula,
      precision: precision,
      agg: 'sum'
    });
    
    window.BoardApp.apiCall('/projects/api/column/update.php', {
      column_id: columnId,
      config: config
    })
    .then(() => {
      console.log('✅ Formula saved');
      
      document.querySelector('.fw-modal-overlay')?.remove();
      
      const toast = document.createElement('div');
      toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        padding: 16px 24px;
        background: rgba(0, 200, 117, 0.9);
        color: white;
        border-radius: 8px;
        font-weight: 600;
        z-index: 10001;
        animation: slideInRight 0.3s ease;
      `;
      toast.textContent = '✅ Formula updated! Recalculating...';
      const container = document.querySelector('.fw-proj') || document.body;
      container.appendChild(toast);
      
      setTimeout(() => {
        toast.remove();
        window.BoardApp.recalculateColumn(columnId);
      }, 1500);
    })
    .catch(err => {
      console.error('❌ Save formula error:', err);
      alert('Failed to save formula:\n\n' + err.message);
    });
  };

  // ===== RECALCULATE COLUMN =====
  window.BoardApp.recalculateColumn = function(columnId) {
    console.log('🔄 Recalculating column:', columnId);
    
    window.BoardApp.apiCall('/projects/api/formula/calculate.php', {
      board_id: window.BOARD_DATA.boardId,
      column_id: columnId
    })
    .then(() => {
      console.log('✅ Column recalculated');
      
      const toast = document.createElement('div');
      toast.style.cssText = `
        position: fixed;
        top: 80px;
        right: 20px;
        padding: 16px 24px;
        background: rgba(0, 200, 117, 0.9);
        color: white;
        border-radius: 8px;
        font-weight: 600;
        z-index: 10001;
      `;
      toast.textContent = '✅ Recalculated!';
      const container = document.querySelector('.fw-proj') || document.body;
      container.appendChild(toast);
      
      setTimeout(() => {
        toast.remove();
        window.location.reload();
      }, 1000);
    })
    .catch(err => {
      console.error('❌ Recalculate error:', err);
      alert('Failed to recalculate:\n\n' + err.message);
    });
  };

  // ===== EDIT COLUMN SETTINGS =====
  window.BoardApp.editColumnSettings = function(columnId) {
    window.BoardApp.closeAllDropdowns();
    
    const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
    if (!column) return;
    
    const config = column.config ? JSON.parse(column.config) : {};
    
    let settingsHtml = '';
    
    switch (column.type) {
      case 'number':
        settingsHtml = `
          <div class="fw-form-group">
            <label>Column Name</label>
            <input type="text" id="settingName" class="fw-input" value="${column.name}" />
          </div>
          
          <div class="fw-form-group">
            <label>Number Format</label>
            <select id="settingFormat" class="fw-input">
              <option value="decimal" ${config.format === 'decimal' ? 'selected' : ''}>Decimal (1,234.56)</option>
              <option value="currency" ${config.format === 'currency' ? 'selected' : ''}>Currency ($1,234.56)</option>
              <option value="percentage" ${config.format === 'percentage' ? 'selected' : ''}>Percentage (12.34%)</option>
            </select>
          </div>
          
          <div class="fw-form-group">
            <label>Decimal Places</label>
            <input type="number" id="settingPrecision" class="fw-input" value="${config.precision || 2}" min="0" max="10" />
          </div>
          
          <div class="fw-form-group">
            <label>Column Width (pixels)</label>
            <input type="number" id="settingWidth" class="fw-input" value="${column.width}" min="80" max="500" />
          </div>
        `;
        break;
        
      case 'text':
        settingsHtml = `
          <div class="fw-form-group">
            <label>Column Name</label>
            <input type="text" id="settingName" class="fw-input" value="${column.name}" />
          </div>
          
          <div class="fw-form-group">
            <label>Column Width (pixels)</label>
            <input type="number" id="settingWidth" class="fw-input" value="${column.width}" min="80" max="500" />
          </div>
        `;
        break;
        
      case 'dropdown':
        const options = config.options || [];
        settingsHtml = `
          <div class="fw-form-group">
            <label>Column Name</label>
            <input type="text" id="settingName" class="fw-input" value="${column.name}" />
          </div>
          
          <div class="fw-form-group">
            <label>Dropdown Options (one per line)</label>
            <textarea id="settingOptions" class="fw-textarea" rows="8">${options.join('\n')}</textarea>
          </div>
          
          <div class="fw-form-group">
            <label>Column Width (pixels)</label>
            <input type="number" id="settingWidth" class="fw-input" value="${column.width}" min="80" max="500" />
          </div>
        `;
        break;
        
      default:
        settingsHtml = `
          <div class="fw-form-group">
            <label>Column Name</label>
            <input type="text" id="settingName" class="fw-input" value="${column.name}" />
          </div>
          
          <div class="fw-form-group">
            <label>Column Width (pixels)</label>
            <input type="number" id="settingWidth" class="fw-input" value="${column.width}" min="80" max="500" />
          </div>
        `;
    }
    
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 500px;">
        <div class="fw-modal-header">
          <h3 style="margin:0;">⚙️ Column Settings: ${column.name}</h3>
          <button onclick="this.closest('.fw-modal-overlay').remove()" style="background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:24px;">×</button>
        </div>
        
        <div class="fw-modal-body">
          ${settingsHtml}
        </div>
        
        <div class="fw-modal-footer">
          <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
          <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveColumnSettings(${columnId}, '${column.type}')">Save Settings</button>
        </div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
  };

  // ===== SAVE COLUMN SETTINGS =====
  window.BoardApp.saveColumnSettings = function(columnId, columnType) {
    const name = document.getElementById('settingName')?.value.trim();
    const width = parseInt(document.getElementById('settingWidth')?.value) || 150;
    
    if (!name) {
      alert('Please enter a column name');
      return;
    }
    
    const data = {
      column_id: columnId,
      name: name,
      width: width
    };
    
    if (columnType === 'number') {
      const format = document.getElementById('settingFormat')?.value || 'decimal';
      const precision = parseInt(document.getElementById('settingPrecision')?.value) || 2;
      data.config = JSON.stringify({ format, precision, agg: 'sum' });
    } else if (columnType === 'dropdown') {
      const optionsText = document.getElementById('settingOptions')?.value || '';
      const options = optionsText.split('\n').map(o => o.trim()).filter(o => o);
      data.config = JSON.stringify({ options });
    }
    
    console.log('💾 Saving column settings:', data);
    
    window.BoardApp.apiCall('/projects/api/column/update.php', data)
      .then(() => {
        console.log('✅ Settings saved');
        
        document.querySelector('.fw-modal-overlay')?.remove();
        
        const toast = document.createElement('div');
        toast.style.cssText = `
          position: fixed;
          top: 80px;
          right: 20px;
          padding: 16px 24px;
          background: rgba(0, 200, 117, 0.9);
          color: white;
          border-radius: 8px;
          font-weight: 600;
          z-index: 10001;
        `;
        toast.textContent = '✅ Settings saved!';
        const container = document.querySelector('.fw-proj') || document.body;
        container.appendChild(toast);
        
        setTimeout(() => {
          toast.remove();
          window.location.reload();
        }, 1000);
      })
      .catch(err => {
        console.error('❌ Save settings error:', err);
        alert('Failed to save settings:\n\n' + err.message);
      });
  };

  // ===== DELETE COLUMN =====
  window.BoardApp.deleteColumn = function(columnId) {
    window.BoardApp.closeAllDropdowns();
    
    if (!confirm('⚠️ Delete this column?\n\nAll column data will be permanently lost.\n\nThis cannot be undone.')) {
      return;
    }
    
    console.log('🗑️ Deleting column:', columnId);
    
    const loadingToast = document.createElement('div');
    loadingToast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 16px 24px;
      background: rgba(139, 92, 246, 0.9);
      color: white;
      border-radius: 8px;
      font-weight: 600;
      z-index: 10001;
    `;
    loadingToast.textContent = '🗑️ Deleting column...';
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(loadingToast);
    
    window.BoardApp.apiCall('/projects/api/column/delete.php', {
      column_id: columnId
    })
      .then(() => {
        console.log('✅ Column deleted');
        loadingToast.remove();
        
        const successToast = document.createElement('div');
        successToast.style.cssText = `
          position: fixed;
          top: 80px;
          right: 20px;
          padding: 16px 24px;
          background: rgba(0, 200, 117, 0.9);
          color: white;
          border-radius: 8px;
          font-weight: 600;
          z-index: 10001;
        `;
        successToast.textContent = '✅ Column deleted!';
        container.appendChild(successToast);
        
        setTimeout(() => {
          successToast.remove();
          window.location.reload();
        }, 1000);
      })
      .catch(err => {
        console.error('❌ Delete column error:', err);
        loadingToast.remove();
        
        const errorToast = document.createElement('div');
        errorToast.style.cssText = `
          position: fixed;
          top: 80px;
          right: 20px;
          padding: 16px 24px;
          background: rgba(239, 68, 68, 0.9);
          color: white;
          border-radius: 8px;
          font-weight: 600;
          z-index: 10001;
        `;
        errorToast.textContent = '❌ Failed to delete column';
        container.appendChild(errorToast);
        
        setTimeout(() => errorToast.remove(), 3000);
      });
  };

  // ===== HIDE COLUMN =====
  window.BoardApp.hideColumn = function(columnId) {
    console.log('👁️ Hiding column:', columnId);
    
    window.BoardApp.apiCall('/projects/api/column/visibility.php', {
      column_id: columnId,
      visible: 0
    })
      .then(() => {
        console.log('✅ Column hidden');
        window.location.reload();
      })
      .catch(err => {
        console.error('❌ Hide column error:', err);
        alert('Failed to hide column');
      });
  };

  // ===== CREATE COLUMN MODAL =====
  function createColumnModal(title, content) {
    const existing = document.querySelector('.fw-column-modal-overlay');
    if (existing) existing.remove();
    
    const modal = document.createElement('div');
    modal.className = 'fw-column-modal-overlay';
    modal.innerHTML = `
      <div class="fw-column-modal-content">
        <div class="fw-column-modal-header">
          <h2>${title}</h2>
          <button class="fw-modal-close" onclick="this.closest('.fw-column-modal-overlay').remove()">✕</button>
        </div>
        <div class="fw-column-modal-body">
          ${content}
        </div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    
    const escHandler = (e) => {
      if (e.key === 'Escape') {
        modal.remove();
        document.removeEventListener('keydown', escHandler);
      }
    };
    document.addEventListener('keydown', escHandler);
    
    return modal;
  }

  console.log('✅ Columns module loaded (COMPLETE)');

})();

// ===== AUTO-SIZE COLUMNS (30..150) =====
(() => {
  'use strict';

  console.log('📐 Auto-size columns module loading...');

  // Simple debounce
  function debounce(fn, ms) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  // Create hidden measurement element
  let measureEl = null;
  function getMeasureEl() {
    if (!measureEl) {
      measureEl = document.createElement('span');
      measureEl.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;font-size:12px;font-family:system-ui,-apple-system,sans-serif;';
      document.body.appendChild(measureEl);
    }
    return measureEl;
  }

  function measureText(text) {
    const el = getMeasureEl();
    el.textContent = text || '';
    return el.offsetWidth;
  }

  // Auto size custom/data columns only (not checkbox/item/menu)
  window.BoardApp = window.BoardApp || {};
  window.BoardApp.autoSizeColumns = function(targetColumnId = null) {
    console.log('📐 Running autoSizeColumns', targetColumnId ? `for column ${targetColumnId}` : 'for all columns');

    const tables = Array.from(document.querySelectorAll('table.fw-board-table'));
    if (!tables.length) {
      console.log('📐 No tables found');
      return;
    }

    // Collect column IDs from headers (works for all tables)
    const allIds = new Set();
    tables.forEach(tbl => {
      tbl.querySelectorAll('th[data-column-id]').forEach(th => allIds.add(String(th.dataset.columnId)));
    });

    const ids = targetColumnId ? [String(targetColumnId)] : Array.from(allIds);
    if (!ids.length) {
      console.log('📐 No column IDs found');
      return;
    }

    console.log('📐 Processing columns:', ids);

    ids.forEach(id => {
      // Header text width
      let maxW = 0;
      const headerInputs = document.querySelectorAll(`th[data-column-id="${id}"] .fw-col-name-input`);
      headerInputs.forEach(inp => {
        const txt = (inp.value || inp.textContent || '').trim();
        const w = measureText(txt) + 50; // padding + menu button space
        maxW = Math.max(maxW, w);
      });

      // Cell text width (sample first 50 rows)
      const cells = Array.from(document.querySelectorAll(`td.fw-cell[data-column-id="${id}"]`)).slice(0, 50);
      cells.forEach(td => {
        const v = (td.dataset.value || td.textContent || '').trim();
        if (!v) return;
        const w = measureText(v) + 24; // padding
        maxW = Math.max(maxW, w);
      });

      // Clamp 30..150
      const w = Math.max(30, Math.min(150, Math.ceil(maxW || 60)));
      console.log(`📐 Column ${id}: maxW=${maxW}, final=${w}px`);

      // Apply width to colgroup col elements
      document.querySelectorAll(`table.fw-board-table col[data-column-id="${id}"]`)
        .forEach(col => {
          col.style.width = `${w}px`;
          col.style.minWidth = `${w}px`;
          col.style.maxWidth = `${w}px`;
        });

      // Apply width to th elements
      document.querySelectorAll(`table.fw-board-table th[data-column-id="${id}"]`)
        .forEach(th => {
          th.style.width = `${w}px`;
          th.style.minWidth = `${w}px`;
          th.style.maxWidth = `${w}px`;
        });

      // Apply width to td cells too
      document.querySelectorAll(`table.fw-board-table td.fw-cell[data-column-id="${id}"]`)
        .forEach(td => {
          td.style.width = `${w}px`;
          td.style.minWidth = `${w}px`;
          td.style.maxWidth = `${w}px`;
        });
    });

    console.log('📐 Auto-size complete');
  };

  const runAuto = debounce(() => window.BoardApp.autoSizeColumns(), 200);

  // Run after full page load (including images, styles)
  window.addEventListener('load', () => {
    console.log('📐 Window loaded, running auto-size...');
    setTimeout(runAuto, 100);
  });

  // Also run on DOMContentLoaded as backup
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      console.log('📐 DOM ready, scheduling auto-size...');
      setTimeout(runAuto, 300);
    });
  } else {
    setTimeout(runAuto, 300);
  }

  // Rerun on resize
  window.addEventListener('resize', runAuto);

  // Rerun when a cell changes
  document.addEventListener('cellUpdated', debounce((e) => {
    const colId = e?.detail?.columnId;
    if (colId) window.BoardApp.autoSizeColumns(colId);
  }, 200));

  console.log('📐 Auto-size columns module loaded');
})();

// ===== MANUAL COLUMN RESIZE (drag to resize) =====
(() => {
  'use strict';

  console.log('↔️ Column resize module loading...');

  let resizing = null; // { columnId, startX, startWidth, th, col, tds }

  function applyWidth(columnId, width) {
    // Clamp 30..150
    const w = Math.max(30, Math.min(150, Math.round(width)));

    // Apply to all matching elements
    document.querySelectorAll(`table.fw-board-table col[data-column-id="${columnId}"]`)
      .forEach(col => col.style.width = `${w}px`);

    document.querySelectorAll(`table.fw-board-table th[data-column-id="${columnId}"]`)
      .forEach(th => th.style.width = `${w}px`);

    document.querySelectorAll(`table.fw-board-table td.fw-cell[data-column-id="${columnId}"]`)
      .forEach(td => td.style.width = `${w}px`);

    return w;
  }

  function saveWidth(columnId, width) {
    console.log(`↔️ Saving column ${columnId} width: ${width}px`);

    const formData = new FormData();
    formData.append('column_id', columnId);
    formData.append('width', width);

    fetch('/projects/api/column/update.php', {
      method: 'POST',
      body: formData
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        console.log(`↔️ Column ${columnId} width saved`);
      } else {
        console.error('↔️ Failed to save width:', data.error);
      }
    })
    .catch(err => console.error('↔️ Error saving width:', err));
  }

  function onMouseDown(e) {
    const handle = e.target.closest('.fw-col-resize');
    if (!handle) return;

    e.preventDefault();
    e.stopPropagation();

    const columnId = handle.dataset.columnId;
    const th = handle.closest('th[data-column-id]');
    if (!th || !columnId) return;

    handle.classList.add('resizing');
    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';

    resizing = {
      columnId,
      startX: e.clientX,
      startWidth: th.offsetWidth,
      handle
    };

    console.log(`↔️ Start resize column ${columnId}, startWidth: ${resizing.startWidth}px`);
  }

  function onMouseMove(e) {
    if (!resizing) return;

    const delta = e.clientX - resizing.startX;
    const newWidth = resizing.startWidth + delta;
    applyWidth(resizing.columnId, newWidth);
  }

  function onMouseUp(e) {
    if (!resizing) return;

    const delta = e.clientX - resizing.startX;
    const finalWidth = applyWidth(resizing.columnId, resizing.startWidth + delta);

    resizing.handle.classList.remove('resizing');
    document.body.style.cursor = '';
    document.body.style.userSelect = '';

    console.log(`↔️ End resize column ${resizing.columnId}, finalWidth: ${finalWidth}px`);

    // Save to database
    saveWidth(resizing.columnId, finalWidth);

    resizing = null;
  }

  // Event listeners
  document.addEventListener('mousedown', onMouseDown);
  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);

  console.log('↔️ Column resize module loaded');
})();