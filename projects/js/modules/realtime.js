/**
 * Real-Time Updates Module
 * Updates DOM without page reload - Enhanced with proper event handling
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== UPDATE CELL IN DOM =====
  window.BoardApp.updateCellDOM = function(itemId, columnId, value, columnType) {
    const cell = document.querySelector(
      `.fw-cell[data-item-id="${itemId}"][data-column-id="${columnId}"]`
    );
    
    if (!cell) {
      console.warn('Cell not found in DOM:', itemId, columnId);
      return;
    }
    
    console.log('🔄 Updating cell DOM:', { itemId, columnId, value, columnType });
    
    // Store old value for comparison
    const oldValue = cell.dataset.value;
    cell.dataset.value = value || '';
    
    // Clear existing content
    cell.innerHTML = '';
    
    switch (columnType) {
      case 'text':
        cell.textContent = value || '';
        break;
        
      case 'number':
        if (value !== null && value !== '') {
          const span = document.createElement('span');
          span.className = 'fw-cell-number';
          span.textContent = value;
          cell.appendChild(span);
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'status':
        if (value) {
          const config = window.BOARD_DATA.statusConfig[value];
          if (config) {
            const badge = document.createElement('span');
            badge.className = 'fw-status-badge';
            badge.style.background = config.color;
            badge.textContent = config.label;
            cell.appendChild(badge);
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'people':
        if (value) {
          const user = window.BOARD_DATA.users.find(u => u.id == value);
          if (user) {
            const div = document.createElement('div');
            div.className = 'fw-user-pill';
            div.innerHTML = `
              <div class="fw-avatar-sm">${user.first_name.charAt(0)}${user.last_name.charAt(0)}</div>
              <span class="fw-user-name">${user.first_name} ${user.last_name}</span>
            `;
            cell.appendChild(div);
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'date':
        if (value) {
          const div = document.createElement('div');
          div.className = 'fw-date-pill';
          const date = new Date(value);
          const formatted = date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric' 
          });
          div.innerHTML = `
            <svg width="14" height="14" fill="currentColor">
              <rect x="2" y="3" width="10" height="9" rx="1" stroke="currentColor" stroke-width="1.5" fill="none"/>
              <path d="M2 5h10M5 1v3M9 1v3"/>
            </svg>
            <span>${formatted}</span>
          `;
          cell.appendChild(div);
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'priority':
        if (value) {
          const priorities = {
            low: { label: 'Low', color: '#10b981' },
            medium: { label: 'Medium', color: '#fdab3d' },
            high: { label: 'High', color: '#f97316' },
            critical: { label: 'Critical', color: '#ef4444' }
          };
          const p = priorities[value];
          if (p) {
            const btn = document.createElement('button');
            btn.className = 'fw-priority-pill';
            btn.dataset.value = value;
            btn.innerHTML = `
              <span class="fw-priority-dot" style="background:${p.color}"></span>
              <span class="fw-priority-label">${p.label}</span>
            `;
            cell.appendChild(btn);
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'supplier':
        if (value) {
          const supplier = window.BOARD_DATA.suppliers.find(s => s.id == value);
          if (supplier) {
            const div = document.createElement('div');
            div.className = 'fw-supplier-pill';
            div.innerHTML = `
              <span class="fw-supplier-icon">🏢</span>
              <span class="fw-supplier-name">${supplier.name}</span>
              ${supplier.preferred ? '<span class="fw-supplier-badge">⭐</span>' : ''}
            `;
            cell.appendChild(div);
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'dropdown':
        if (value) {
          cell.textContent = value;
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
            cell.textContent = `${start} → ${end}`;
          } catch (e) {
            cell.textContent = value;
          }
        } else {
          cell.innerHTML = '<button class="fw-cell-empty">+</button>';
        }
        break;
        
      case 'formula':
        if (value !== null && value !== '') {
          const span = document.createElement('span');
          span.className = 'fw-cell-formula';
          span.textContent = value;
          cell.appendChild(span);
        } else {
          cell.textContent = '—';
        }
        break;
        
      default:
        cell.textContent = value || '';
    }
    
    // Add flash animation only if value changed
    if (oldValue !== (value || '')) {
      cell.classList.add('fw-cell-updated');
      setTimeout(() => cell.classList.remove('fw-cell-updated'), 600);
      
      console.log('✨ Cell updated with animation');
    }
    
    // Update local data cache
    updateLocalDataCache(itemId, columnId, value);
    
    // ✅ TRIGGER AGGREGATION UPDATE
    const row = cell.closest('.fw-item-row');
    if (row) {
      const groupId = row.dataset.groupId;
      if (groupId) {
        setTimeout(() => {
          if (window.BoardApp.updateAggregations) {
            window.BoardApp.updateAggregations(groupId);
          }
          if (window.BoardApp.updateBoardTotals) {
            window.BoardApp.updateBoardTotals();
          }
        }, 100);
      }
    }
  };

  // ===== ADD ITEM TO DOM =====
  window.BoardApp.addItemToDOM = function(item, groupId) {
    console.log('➕ Adding item to DOM:', item);
    
    const group = document.querySelector(`[data-group-id="${groupId}"]`);
    if (!group) {
      console.error('Group not found:', groupId);
      return;
    }
    
    const tbody = group.querySelector('tbody');
    if (!tbody) {
      console.error('Table body not found');
      return;
    }
    
    // Remove empty state if present
    const emptyState = tbody.querySelector('.fw-empty-state');
    if (emptyState) {
      emptyState.closest('tr').remove();
    }
    
    // Create new row
    const tr = document.createElement('tr');
    tr.className = 'fw-item-row';
    tr.dataset.itemId = item.id;
    tr.dataset.groupId = groupId;
    tr.setAttribute('draggable', 'true');
    tr.style.cursor = 'grab';
    
    // Build row HTML
    let html = `
      <td class="fw-col-checkbox">
        <input type="checkbox" class="fw-checkbox fw-item-checkbox" 
               data-item-id="${item.id}"
               onchange="BoardApp.toggleItemSelection(${item.id}, this.checked)" />
      </td>
      <td class="fw-col-item">
        <input type="text" class="fw-item-title" value="${escapeHtml(item.title)}" 
          onblur="BoardApp.updateItemTitle(${item.id}, this.value)" />
      </td>
    `;
    
    // Add cells for each column
    window.BOARD_DATA.columns.forEach(col => {
      html += `
        <td class="fw-cell" 
          data-type="${col.type}" 
          data-item-id="${item.id}" 
          data-column-id="${col.column_id}"
          data-value=""
          onclick="BoardApp.editCell(${item.id}, ${col.column_id}, '${col.type}', event)">
          <button class="fw-cell-empty">+</button>
        </td>
      `;
    });
    
    // Add menu
    html += `
      <td class="fw-col-menu">
        <button class="fw-icon-btn" onclick="BoardApp.showItemMenu(${item.id}, event)">
          <svg width="14" height="14" fill="currentColor">
            <circle cx="7" cy="3" r="1.2"/>
            <circle cx="7" cy="7" r="1.2"/>
            <circle cx="7" cy="11" r="1.2"/>
          </svg>
        </button>
      </td>
    `;
    
    tr.innerHTML = html;
    
    // Insert before add row or aggregation row
    const addRow = tbody.querySelector('.fw-add-row');
    const aggRow = tbody.querySelector('.fw-agg-row');
    
    if (aggRow) {
      tbody.insertBefore(tr, aggRow);
    } else if (addRow) {
      tbody.insertBefore(tr, addRow);
    } else {
      tbody.appendChild(tr);
    }
    
    // Add to local data cache
    window.BOARD_DATA.items.push(item);
    
    // Add flash animation
    tr.classList.add('fw-item-added');
    setTimeout(() => tr.classList.remove('fw-item-added'), 600);
    
    // Update group count
    updateGroupCount(groupId);
    
    // Dispatch event for drag & drop to reinit
    document.dispatchEvent(new CustomEvent('itemAdded', { 
      detail: { itemId: item.id, groupId: groupId } 
    }));
    
    console.log('✅ Item added to DOM');
  };

  // ===== REMOVE ITEM FROM DOM =====
  window.BoardApp.removeItemFromDOM = function(itemId) {
    const row = document.querySelector(`[data-item-id="${itemId}"]`);
    if (!row) {
      console.warn('Item row not found:', itemId);
      return;
    }
    
    const groupId = row.dataset.groupId;
    
    // Animate removal
    row.style.transition = 'opacity 0.3s, transform 0.3s';
    row.style.opacity = '0';
    row.style.transform = 'translateX(-20px)';
    
    setTimeout(() => {
      row.remove();
      
      // Remove from local data cache
      const index = window.BOARD_DATA.items.findIndex(i => i.id == itemId);
      if (index > -1) {
        window.BOARD_DATA.items.splice(index, 1);
      }
      
      updateGroupCount(groupId);
      
      // Check if group is empty
      const group = document.querySelector(`[data-group-id="${groupId}"]`);
      const tbody = group?.querySelector('tbody');
      const rows = tbody?.querySelectorAll('.fw-item-row');
      
      if (rows && rows.length === 0) {
        // Show empty state
        const emptyRow = document.createElement('tr');
        emptyRow.innerHTML = `
          <td colspan="${3 + window.BOARD_DATA.columns.length}" class="fw-empty-state">
            <div class="fw-empty-icon">📋</div>
            <div class="fw-empty-title">No items yet</div>
            <div class="fw-empty-text">Click "+ Add item" below to get started</div>
          </td>
        `;
        const addRow = tbody.querySelector('.fw-add-row');
        tbody.insertBefore(emptyRow, addRow);
      }
      
      console.log('✅ Item removed from DOM');
    }, 300);
  };

  // ===== UPDATE AGGREGATIONS =====
  window.BoardApp.updateAggregations = function(groupId) {
    console.log('🔢 Updating aggregations for group:', groupId);
    
    const group = document.querySelector(`[data-group-id="${groupId}"]`);
    if (!group) return;
    
    const aggRow = group.querySelector('.fw-group-agg-row');
    if (!aggRow) return;
    
    // Get all items in this group
    const itemRows = group.querySelectorAll('.fw-item-row');
    
    // Update each column's aggregation
    window.BOARD_DATA.columns.forEach(col => {
      if (!['number', 'formula'].includes(col.type)) return;
      
      const config = col.config ? JSON.parse(col.config) : {};
      const aggType = config.agg || 'sum';
      const precision = config.precision || 2;
      
      const aggCell = aggRow.querySelector(`[data-column-id="${col.column_id}"]`);
      if (!aggCell) return;
      
      // Collect values
      const values = [];
      itemRows.forEach(row => {
        const cell = row.querySelector(`[data-column-id="${col.column_id}"]`);
        const value = parseFloat(cell?.dataset.value);
        if (!isNaN(value)) {
          values.push(value);
        }
      });
      
      // Calculate
      let result = 0;
      if (values.length > 0) {
        switch (aggType) {
          case 'sum':
            result = values.reduce((a, b) => a + b, 0);
            break;
          case 'avg':
            result = values.reduce((a, b) => a + b, 0) / values.length;
            break;
          case 'min':
            result = Math.min(...values);
            break;
          case 'max':
            result = Math.max(...values);
            break;
          case 'count':
            result = values.length;
            break;
        }
      }
      
      const formatted = result.toLocaleString('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision
      });
      
      aggCell.innerHTML = `
        <span class="fw-agg-value">
          <span class="fw-agg-type">${aggType.toUpperCase()}</span>
          ${formatted}
        </span>
      `;
      
      // Flash effect
      aggCell.classList.add('fw-cell-updated');
      setTimeout(() => aggCell.classList.remove('fw-cell-updated'), 600);
    });
    
    console.log('✅ Group aggregations updated');
  };

  // ===== UPDATE BOARD TOTALS =====
  window.BoardApp.updateBoardTotals = function() {
    console.log('🔢 Updating board totals...');
    
    const boardTotalsRow = document.querySelector('.fw-board-agg-row');
    if (!boardTotalsRow) return;
    
    window.BOARD_DATA.columns.forEach(col => {
      if (!['number', 'formula'].includes(col.type)) return;
      
      const config = col.config ? JSON.parse(col.config) : {};
      const aggType = config.agg || 'sum';
      const precision = config.precision || 2;
      
      const aggCell = boardTotalsRow.querySelector(`[data-column-id="${col.column_id}"]`);
      if (!aggCell) return;
      
      // Collect all values from ALL groups
      const allValues = [];
      document.querySelectorAll('.fw-item-row').forEach(row => {
        const cell = row.querySelector(`[data-column-id="${col.column_id}"]`);
        const value = parseFloat(cell?.dataset.value);
        if (!isNaN(value)) {
          allValues.push(value);
        }
      });
      
      // Calculate
      let result = 0;
      if (allValues.length > 0) {
        switch (aggType) {
          case 'sum':
            result = allValues.reduce((a, b) => a + b, 0);
            break;
          case 'avg':
            result = allValues.reduce((a, b) => a + b, 0) / allValues.length;
            break;
          case 'min':
            result = Math.min(...allValues);
            break;
          case 'max':
            result = Math.max(...allValues);
            break;
          case 'count':
            result = allValues.length;
            break;
        }
      }
      
      const formatted = result.toLocaleString('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision
      });
      
      aggCell.innerHTML = `
        <span class="fw-agg-value fw-board-agg-value">
          <span class="fw-agg-type">${aggType.toUpperCase()}</span>
          <strong>${formatted}</strong>
        </span>
      `;
      
      // Flash effect
      aggCell.classList.add('fw-cell-updated');
      setTimeout(() => aggCell.classList.remove('fw-cell-updated'), 600);
    });
    
    console.log('✅ Board totals updated');
  };

  // ===== UPDATE GROUP COUNT =====
  function updateGroupCount(groupId) {
    const group = document.querySelector(`[data-group-id="${groupId}"]`);
    if (!group) return;
    
    const tbody = group.querySelector('tbody');
    const rows = tbody?.querySelectorAll('.fw-item-row');
    const count = rows ? rows.length : 0;
    
    const countEl = group.querySelector('.fw-group-count');
    if (countEl) {
      countEl.textContent = count;
      
      // Flash animation
      countEl.classList.add('fw-count-updated');
      setTimeout(() => countEl.classList.remove('fw-count-updated'), 300);
    }
  }

  // ===== UPDATE LOCAL DATA CACHE =====
  function updateLocalDataCache(itemId, columnId, value) {
    if (!window.BOARD_DATA.valuesMap[itemId]) {
      window.BOARD_DATA.valuesMap[itemId] = {};
    }
    window.BOARD_DATA.valuesMap[itemId][columnId] = value;
  }

  // ===== HELPER: ESCAPE HTML =====
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  console.log('✅ Real-time updates module loaded');
})();