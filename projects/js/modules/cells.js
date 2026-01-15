/**
 * Cell Editing Module - COMPLETE ALL TYPES
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  // ===== MAIN EDIT CELL FUNCTION =====
  window.BoardApp.editCell = function(itemId, columnId, columnType, event) {
    if (!event) return;
    event.stopPropagation();
    
    const cellElement = event.currentTarget || event.target.closest('.fw-cell');
    if (!cellElement) return;
    
    console.log('📝 Edit cell:', { itemId, columnId, columnType });
    
    // Close any existing modals
    document.querySelectorAll('.fw-modal-overlay, .fw-cell-picker-overlay').forEach(el => el.remove());
    
    switch (columnType) {
      case 'text':
        editText(itemId, columnId, cellElement);
        break;
      case 'number':
        editNumber(itemId, columnId, cellElement);
        break;
      case 'status':
        editStatus(itemId, columnId, cellElement);
        break;
      case 'people':
        editPeople(itemId, columnId, cellElement);
        break;
      case 'date':
        editDate(itemId, columnId, cellElement);
        break;
      case 'priority':
        editPriority(itemId, columnId, cellElement);
        break;
      case 'supplier':
        editSupplier(itemId, columnId, cellElement);
        break;
      case 'dropdown':
        editDropdown(itemId, columnId, cellElement);
        break;
      case 'timeline':
        editTimeline(itemId, columnId, cellElement);
        break;
      case 'checkbox':
        editCheckbox(itemId, columnId, cellElement);
        break;
      case 'tags':
        editTags(itemId, columnId, cellElement);
        break;
      case 'link':
        editLink(itemId, columnId, cellElement);
        break;
      case 'email':
        editEmail(itemId, columnId, cellElement);
        break;
      case 'phone':
        editPhone(itemId, columnId, cellElement);
        break;
      case 'progress':
        editProgress(itemId, columnId, cellElement);
        break;
      case 'files':
        editFiles(itemId, columnId, cellElement);
        break;
      case 'formula':
        alert('Formula columns are calculated automatically. Edit the formula in column settings.');
        break;
      default:
        console.warn('Unknown column type:', columnType);
        alert(`Editor for ${columnType} not yet implemented`);
    }
  };
  
  // ===== TEXT EDITOR =====
  function editText(itemId, columnId, cellElement) {
    const currentValue = cellElement.textContent.trim().replace('+', '');
    
    createModal('Edit Text', `
      <textarea id="cellTextInput" class="fw-textarea" rows="4" placeholder="Enter text..." style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;resize:vertical;min-height:100px;">${currentValue}</textarea>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellTextInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => {
      const input = document.getElementById('cellTextInput');
      input?.focus();
      input?.setSelectionRange(input.value.length, input.value.length);
    }, 100);
  }

  // ===== NUMBER EDITOR =====
  function editNumber(itemId, columnId, cellElement) {
    const currentValue = cellElement.textContent.trim().replace('+', '');
    
    createModal('Edit Number', `
      <input type="number" id="cellNumberInput" class="fw-input" value="${currentValue}" step="any" placeholder="0" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:16px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellNumberInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => {
      const input = document.getElementById('cellNumberInput');
      input?.focus();
      input?.select();
    }, 100);
  }

  // ===== STATUS EDITOR =====
  function editStatus(itemId, columnId, cellElement) {
    const statuses = window.BOARD_DATA.statusConfig || {
      'todo': { label: 'To Do', color: '#64748b' },
      'working': { label: 'Working', color: '#fdab3d' },
      'stuck': { label: 'Stuck', color: '#e2445c' },
      'done': { label: 'Done', color: '#00c875' }
    };
    
    const currentValue = cellElement.textContent.trim().toLowerCase();
    
    const options = Object.keys(statuses).map(key => {
      const status = statuses[key];
      return `
        <button class="fw-picker-option ${currentValue === key ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '${key}')">
          <span style="width:20px;height:20px;border-radius:50%;background:${status.color};flex-shrink:0;"></span>
          <span style="flex:1;font-weight:600;">${status.label}</span>
          ${currentValue === key ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
        </button>
      `;
    }).join('');
    
    createModal('Select Status', `<div class="fw-picker-options">${options}</div>`);
  }

  // ===== PEOPLE EDITOR =====
  function editPeople(itemId, columnId, cellElement) {
    const users = window.BOARD_DATA.users || [];
    const currentUserId = cellElement.dataset.userId || '';
    
    if (users.length === 0) {
      alert('No users available');
      return;
    }
    
    const options = `
      <button class="fw-picker-option ${!currentUserId ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '')" data-name="Unassigned">
        <div class="fw-avatar-sm" style="background:#64748b;flex-shrink:0;">?</div>
        <span style="flex:1;font-weight:600;">Unassigned</span>
        ${!currentUserId ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
      ${users.map(u => `
        <button class="fw-picker-option ${u.id == currentUserId ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, ${u.id})" data-name="${u.first_name} ${u.last_name}">
          <div class="fw-avatar-sm" style="flex-shrink:0;">${u.first_name.charAt(0)}${u.last_name.charAt(0)}</div>
          <span style="flex:1;font-weight:600;">${u.first_name} ${u.last_name}</span>
          ${u.id == currentUserId ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
        </button>
      `).join('')}
    `;
    
    createModal('Assign Person', `
      <div class="fw-picker-search">
        <input type="text" class="fw-picker-search-input" placeholder="🔍 Search..." id="peopleSearchInput" oninput="window.filterPickerOptions(this.value)" />
      </div>
      <div class="fw-picker-options" id="peopleOptionsList">${options}</div>
    `);
    
    setTimeout(() => document.getElementById('peopleSearchInput')?.focus(), 100);
  }

  // ===== DATE EDITOR =====
  function editDate(itemId, columnId, cellElement) {
    const currentText = cellElement.querySelector('span')?.textContent || '';
    let dateValue = '';
    
    if (currentText) {
      const parsed = new Date(currentText);
      if (!isNaN(parsed.getTime())) {
        dateValue = parsed.toISOString().split('T')[0];
      }
    }
    
    createModal('Set Date', `
      <input type="date" id="cellDateInput" class="fw-input" value="${dateValue}" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:16px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellDateInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellDateInput')?.focus(), 100);
  }

  // ===== PRIORITY EDITOR =====
  function editPriority(itemId, columnId, cellElement) {
    const priorities = [
      { value: 'low', label: 'Low', color: '#10b981' },
      { value: 'medium', label: 'Medium', color: '#fdab3d' },
      { value: 'high', label: 'High', color: '#f97316' },
      { value: 'critical', label: 'Critical', color: '#ef4444' }
    ];
    
    const currentValue = cellElement.querySelector('.fw-priority-pill')?.dataset.value || '';
    
    const options = priorities.map(p => `
      <button class="fw-picker-option ${currentValue === p.value ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '${p.value}')">
        <span class="fw-priority-dot" style="background:${p.color};width:16px;height:16px;border-radius:50%;flex-shrink:0;"></span>
        <span style="flex:1;font-weight:600;">${p.label}</span>
        ${currentValue === p.value ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
    `).join('');
    
    createModal('Select Priority', `<div class="fw-picker-options">${options}</div>`);
  }

  // ===== SUPPLIER EDITOR =====
  function editSupplier(itemId, columnId, cellElement) {
    const suppliers = window.BOARD_DATA.suppliers || [];
    const currentName = cellElement.querySelector('.fw-supplier-name')?.textContent.trim() || '';
    
    if (suppliers.length === 0) {
      alert('No suppliers available. Add suppliers in CRM first.');
      return;
    }
    
    const options = suppliers.map(s => `
      <button class="fw-picker-option ${s.name === currentName ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, ${s.id})" data-name="${s.name}">
        <div style="width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#6c5ce7,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:16px;flex-shrink:0;">
          🏢
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600;font-size:15px;margin-bottom:2px;">${s.name}</div>
          ${s.phone || s.email ? `<div style="font-size:12px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap;">
            ${s.phone ? `<span>📞 ${s.phone}</span>` : ''}
            ${s.email ? `<span>✉️ ${s.email}</span>` : ''}
          </div>` : ''}
        </div>
        ${s.preferred ? '<div style="font-size:24px;flex-shrink:0;">⭐</div>' : ''}
        ${s.name === currentName ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
    `).join('');
    
    createModal('Select Supplier', `
      <div class="fw-picker-search">
        <input type="text" class="fw-picker-search-input" placeholder="🔍 Search suppliers..." id="supplierSearchInput" oninput="window.filterPickerOptions(this.value)" />
      </div>
      <div class="fw-picker-options" id="supplierOptionsList">
        <button class="fw-picker-option ${!currentName ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '')" data-name="None">
          <div style="width:40px;height:40px;border-radius:12px;background:#64748b;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:20px;flex-shrink:0;">?</div>
          <div style="flex:1;"><div style="font-weight:600;font-size:15px;">No Supplier</div></div>
          ${!currentName ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
        </button>
        ${options}
      </div>
    `);
    
    setTimeout(() => document.getElementById('supplierSearchInput')?.focus(), 100);
  }

  // ===== DROPDOWN EDITOR =====
  function editDropdown(itemId, columnId, cellElement) {
    const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
    if (!column) return;
    
    const config = column.config ? JSON.parse(column.config) : {};
    const options = config.options || ['Option 1', 'Option 2', 'Option 3'];
    const currentValue = cellElement.textContent.trim().replace('+', '');
    
    const optionsHtml = options.map(opt => `
      <button class="fw-picker-option ${currentValue === opt ? 'active' : ''}" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, '${opt.replace(/'/g, "\\'")}')">
        <span style="flex:1;font-weight:600;">${opt}</span>
        ${currentValue === opt ? '<span style="color:var(--accent-primary);">✓</span>' : ''}
      </button>
    `).join('');
    
    createModal('Select Option', `<div class="fw-picker-options">${optionsHtml}</div>`);
  }

  // ===== TIMELINE EDITOR =====
  function editTimeline(itemId, columnId, cellElement) {
    const currentText = cellElement.textContent.trim().replace('+', '');
    let startDate = '';
    let endDate = '';
    
    if (currentText && currentText.includes('→')) {
      const parts = currentText.split('→').map(p => p.trim());
      if (parts[0]) {
        const parsed = new Date(parts[0]);
        if (!isNaN(parsed.getTime())) {
          startDate = parsed.toISOString().split('T')[0];
        }
      }
      if (parts[1]) {
        const parsed = new Date(parts[1]);
        if (!isNaN(parsed.getTime())) {
          endDate = parsed.toISOString().split('T')[0];
        }
      }
    }
    
    createModal('Set Timeline', `
      <div style="display:grid;gap:16px;">
        <div>
          <label style="display:block;margin-bottom:8px;font-weight:600;color:var(--text-secondary);">Start Date</label>
          <input type="date" id="timelineStartInput" class="fw-input" value="${startDate}" style="width:100%;" />
        </div>
        <div>
          <label style="display:block;margin-bottom:8px;font-weight:600;color:var(--text-secondary);">End Date</label>
          <input type="date" id="timelineEndInput" class="fw-input" value="${endDate}" style="width:100%;" />
        </div>
      </div>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveTimelineValue(${itemId}, ${columnId})">Save</button>
      </div>
    `);
  }

  window.BoardApp.saveTimelineValue = function(itemId, columnId) {
    const start = document.getElementById('timelineStartInput').value;
    const end = document.getElementById('timelineEndInput').value;
    
    if (!start || !end) {
      alert('Please select both start and end dates');
      return;
    }
    
    if (new Date(start) > new Date(end)) {
      alert('End date must be after start date');
      return;
    }
    
    const value = JSON.stringify({ start, end });
    window.BoardApp.saveCellValue(itemId, columnId, value);
  };

  // ===== CHECKBOX EDITOR =====
  function editCheckbox(itemId, columnId, cellElement) {
    const currentValue = cellElement.textContent.includes('✅') ? '1' : '0';
    const newValue = currentValue === '1' ? '0' : '1';
    window.BoardApp.saveCellValue(itemId, columnId, newValue);
  }

  // ===== TAGS EDITOR =====
  function editTags(itemId, columnId, cellElement) {
    const currentValue = cellElement.textContent.trim().replace('+', '');
    
    createModal('Edit Tags', `
      <input type="text" id="cellTagsInput" class="fw-input" value="${currentValue}" placeholder="tag1, tag2, tag3" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <p style="font-size:12px;color:var(--text-muted);margin-top:8px;">Separate tags with commas</p>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellTagsInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellTagsInput')?.focus(), 100);
  }

  // ===== LINK EDITOR =====
  function editLink(itemId, columnId, cellElement) {
    const currentValue = cellElement.querySelector('a')?.href || '';
    
    createModal('Edit Link', `
      <input type="url" id="cellLinkInput" class="fw-input" value="${currentValue}" placeholder="https://example.com" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellLinkInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellLinkInput')?.focus(), 100);
  }

  // ===== EMAIL EDITOR =====
  function editEmail(itemId, columnId, cellElement) {
    const currentValue = cellElement.querySelector('a')?.textContent || cellElement.textContent.trim().replace('+', '');
    
    createModal('Edit Email', `
      <input type="email" id="cellEmailInput" class="fw-input" value="${currentValue}" placeholder="email@example.com" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellEmailInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellEmailInput')?.focus(), 100);
  }

  // ===== PHONE EDITOR =====
  function editPhone(itemId, columnId, cellElement) {
    const currentValue = cellElement.querySelector('a')?.textContent || cellElement.textContent.trim().replace('+', '');
    
    createModal('Edit Phone', `
      <input type="tel" id="cellPhoneInput" class="fw-input" value="${currentValue}" placeholder="+1 (555) 123-4567" style="width:100%;padding:12px;border:1px solid var(--input-border);border-radius:8px;background:var(--input-bg);color:var(--input-text);font-size:14px;" />
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('cellPhoneInput').value)">Save</button>
      </div>
    `);
    
    setTimeout(() => document.getElementById('cellPhoneInput')?.focus(), 100);
  }

  // ===== PROGRESS EDITOR =====
  function editProgress(itemId, columnId, cellElement) {
    const currentValue = parseInt(cellElement.dataset.value) || 0;
    
    createModal('Set Progress', `
      <div style="padding:20px 0;">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
          <div style="flex:1;height:12px;background:rgba(255,255,255,0.1);border-radius:12px;overflow:hidden;">
            <div id="progressFillPreview" style="height:100%;background:var(--accent-primary);width:${currentValue}%;transition:width 0.2s;"></div>
          </div>
          <div id="progressValueDisplay" style="font-size:20px;font-weight:700;color:var(--accent-primary);min-width:60px;text-align:right;">${currentValue}%</div>
        </div>
        <input type="range" id="progressSlider" min="0" max="100" value="${currentValue}" 
          style="width:100%;height:8px;border-radius:8px;background:rgba(255,255,255,0.1);outline:none;-webkit-appearance:none;"
          oninput="document.getElementById('progressFillPreview').style.width = this.value + '%'; document.getElementById('progressValueDisplay').textContent = this.value + '%';" />
      </div>
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.saveCellValue(${itemId}, ${columnId}, document.getElementById('progressSlider').value)">Save</button>
      </div>
    `);
  }

  // ===== FILES EDITOR =====
  function editFiles(itemId, columnId, cellElement) {
    createModal('Upload Files', `
      <div class="fw-upload-zone" onclick="document.getElementById('fileUploadInput').click()" style="border:2px dashed rgba(255,255,255,0.2);border-radius:12px;padding:40px;text-align:center;cursor:pointer;transition:all 0.2s;">
        <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" style="margin:0 auto 16px;opacity:0.5;">
          <path d="M21 15v18m0-9l-6 6m6-6l6 6"/>
          <path d="M9 24v12h30V24"/>
        </svg>
        <p style="font-weight:600;margin-bottom:8px;">Click to upload or drag files here</p>
        <p style="font-size:12px;color:var(--text-muted);">PDF, Images, Documents (Max 10MB)</p>
        <input type="file" id="fileUploadInput" multiple style="display:none;" onchange="BoardApp.handleFileUpload(${itemId}, ${columnId}, this.files)" />
      </div>
      <div id="uploadProgress" style="margin-top:16px;display:none;"></div>
    `);
  }

  window.BoardApp.handleFileUpload = function(itemId, columnId, files) {
    console.log('📁 Upload files:', itemId, columnId, files);
    alert('File upload functionality will be implemented soon');
    document.querySelector('.fw-modal-overlay')?.remove();
  };

  // ===== SAVE CELL VALUE =====
window.BoardApp.saveCellValue = function(itemId, columnId, value) {
  console.log('💾 Saving cell:', { itemId, columnId, value });
  
  const column = window.BOARD_DATA.columns.find(c => c.column_id == columnId);
  if (!column) {
    console.error('Column not found:', columnId);
    return;
  }
  
  window.BoardApp.apiCall('/projects/api/cell/update.php', {
    item_id: itemId,
    column_id: columnId,
    value: value
  })
  .then(data => {
    console.log('✅ Cell saved:', data);
    
    // Close modal
    document.querySelectorAll('.fw-modal-overlay, .fw-cell-picker-overlay').forEach(el => el.remove());
    
    // Update in memory
    if (!window.BOARD_DATA.valuesMap[itemId]) {
      window.BOARD_DATA.valuesMap[itemId] = {};
    }
    window.BOARD_DATA.valuesMap[itemId][columnId] = value;
    
    // Update DOM
    if (window.BoardApp.updateCellDOM) {
      window.BoardApp.updateCellDOM(itemId, columnId, value, column.type);
    } else {
      updateCellDisplay(itemId, columnId, value, column.type);
    }
    
    // ✅ NUUT: Recalculate formulas if this is a number column
    if (column.type === 'number' && window.BoardApp.recalculateFormulas) {
      setTimeout(() => {
        window.BoardApp.recalculateFormulas(itemId);
      }, 150);
    }
    
    // ✅ NUUT: Update aggregations
    const row = document.querySelector(`[data-item-id="${itemId}"]`);
    if (row) {
      const groupId = row.dataset.groupId;
      if (groupId && window.BoardApp.updateAggregations) {
        setTimeout(() => {
          window.BoardApp.updateAggregations(groupId);
        }, 200);
      }
    }
    
    // ✅ NUUT: Update board totals
    if (window.BoardApp.updateBoardTotals) {
      setTimeout(() => {
        window.BoardApp.updateBoardTotals();
      }, 250);
    }
    
    // ✅ NUUT: Dispatch event for formula dependencies
    document.dispatchEvent(new CustomEvent('cellUpdated', {
      detail: { itemId, columnId, value, columnType: column.type }
    }));
  })
  .catch(err => {
    console.error('❌ Save cell error:', err);
    alert('Failed to save:\n\n' + err.message);
  });
};

  // ===== UPDATE CELL DISPLAY =====
  function updateCellDisplay(itemId, columnId, value, columnType) {
    const cell = document.querySelector(`td[data-item-id="${itemId}"][data-column-id="${columnId}"]`);
    if (!cell) {
      console.warn('Cell not found for update:', itemId, columnId);
      return;
    }
    
    cell.dataset.value = value;
    
    switch (columnType) {
      case 'text':
        cell.innerHTML = value ? value : '<button class="fw-cell-empty">+</button>';
        break;
        
      case 'number':
        cell.innerHTML = value ? `<span class="fw-cell-number">${value}</span>` : '<button class="fw-cell-empty">+</button>';
        break;
        
      case 'status':
        if (value) {
          const statusConfig = window.BOARD_DATA.statusConfig[value] || { label: value, color: '#8b5cf6' };
          cell.innerHTML = `<span class="fw-status-badge" style="background: ${statusConfig.color};">${value.toUpperCase()}</span>`;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'priority':
        if (value) {
          const priorityColors = {
            'low': '#10b981',
            'medium': '#fdab3d',
            'high': '#f97316',
            'critical': '#ef4444'
          };
          cell.innerHTML = `
            <button class="fw-priority-pill fw-priority-pill--${value}" data-value="${value}">
              <span class="fw-priority-dot" style="background:${priorityColors[value]};"></span>
              <span class="fw-priority-label">${value.charAt(0).toUpperCase() + value.slice(1)}</span>
            </button>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'people':
        if (value) {
          const user = window.BOARD_DATA.users.find(u => u.id == value);
          if (user) {
            const initials = (user.first_name[0] + user.last_name[0]).toUpperCase();
            cell.innerHTML = `
              <div class="fw-user-pill">
                <div class="fw-avatar-sm">${initials}</div>
                <span class="fw-user-name">${user.first_name} ${user.last_name}</span>
              </div>
            `;
            cell.dataset.userId = user.id;
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'supplier':
        if (value) {
          const supplier = window.BOARD_DATA.suppliers.find(s => s.id == value);
          if (supplier) {
            cell.innerHTML = `
              <div class="fw-supplier-pill">
                <span class="fw-supplier-icon">🏢</span>
                <span class="fw-supplier-name">${supplier.name}</span>
                ${supplier.preferred ? '<span class="fw-supplier-badge">⭐</span>' : ''}
              </div>
            `;
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'date':
        if (value) {
          const date = new Date(value);
          const formatted = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          cell.innerHTML = `
            <div class="fw-date-pill">
              <svg width="14" height="14" fill="currentColor">
                <rect x="2" y="3" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
                <path d="M2 5h10M5 1v3M9 1v3"/>
              </svg>
              <span>${formatted}</span>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'checkbox':
        cell.innerHTML = value === '1' ? '✅' : '☐';
        break;
        
      case 'progress':
        if (value) {
          const percent = parseInt(value);
          cell.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="flex:1;height:8px;background:rgba(255,255,255,0.1);border-radius:8px;overflow:hidden;">
                <div style="height:100%;background:var(--accent-primary);width:${percent}%;"></div>
              </div>
              <span style="font-size:12px;font-weight:600;min-width:40px;">${percent}%</span>
            </div>
          `;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'link':
        if (value) {
          cell.innerHTML = `<a href="${value}" target="_blank" style="color:var(--accent-primary);text-decoration:underline;">🔗 Link</a>`;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'email':
        if (value) {
          cell.innerHTML = `<a href="mailto:${value}" style="color:var(--accent-primary);">✉️ ${value}</a>`;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'phone':
        if (value) {
          cell.innerHTML = `<a href="tel:${value}" style="color:var(--accent-primary);">📞 ${value}</a>`;
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'tags':
        if (value) {
          const tags = value.split(',').map(t => t.trim()).filter(t => t);
          cell.innerHTML = tags.map(tag => `<span class="fw-tag">${tag}</span>`).join('');
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'timeline':
        if (value) {
          try {
            const timeline = JSON.parse(value);
            const start = new Date(timeline.start).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            const end = new Date(timeline.end).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            cell.innerHTML = `<span class="fw-timeline-pill">📅 ${start} → ${end}</span>`;
          } catch (e) {
            cell.innerHTML = '<button class="fw-cell-empty">+</button>';
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      default:
        cell.innerHTML = value ? value : '<button class="fw-cell-empty">+</button>';
    }
  }

  // Expose updateCellDisplay
  window.BoardApp.updateCellDOM = updateCellDisplay;

  // ===== HELPER: FILTER PICKER OPTIONS =====
  window.filterPickerOptions = function(query) {
    const options = document.querySelectorAll('.fw-picker-option');
    const lowerQuery = query.toLowerCase();
    
    options.forEach(opt => {
      const name = opt.dataset.name || opt.textContent;
      opt.style.display = name.toLowerCase().includes(lowerQuery) ? 'flex' : 'none';
    });
  };

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up">
        <div class="fw-modal-header">
          <h3 style="margin:0;font-size:18px;font-weight:700;">${title}</h3>
          <button onclick="this.closest('.fw-modal-overlay').remove()" style="background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:24px;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;transition:all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='none'">×</button>
        </div>
        <div class="fw-modal-body">${content}</div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    
    // Add close on Escape
    const escHandler = (e) => {
      if (e.key === 'Escape') {
        modal.remove();
        document.removeEventListener('keydown', escHandler);
      }
    };
    document.addEventListener('keydown', escHandler);
    
    return modal;
  }

  console.log('✅ Cells module loaded - ALL column types supported');

})();