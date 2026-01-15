/**
 * Advanced Filtering Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};
  window.BoardApp.activeFilters = [];

  // ===== SHOW FILTER MODAL =====
  window.BoardApp.showFilterModal = function() {
    const modal = createModal('Advanced Filters', `
      <div class="fw-filters-builder">
        <div id="filtersList" class="fw-filters-list"></div>
        
        <button class="fw-btn fw-btn--secondary" onclick="BoardApp.addFilterRow()">
          + Add Filter
        </button>
      </div>
      
      <div class="fw-modal-footer">
        <button class="fw-btn fw-btn--text" onclick="BoardApp.clearFilters()">Clear All</button>
        <button class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
        <button class="fw-btn fw-btn--primary" onclick="BoardApp.applyFilters()">Apply Filters</button>
      </div>
    `);
    
    // Add existing filters
    if (window.BoardApp.activeFilters.length > 0) {
      window.BoardApp.activeFilters.forEach(f => addFilterRow(f));
    } else {
      addFilterRow();
    }
  };

  // ===== ADD FILTER ROW =====
  window.BoardApp.addFilterRow = function(filter = null) {
    addFilterRow(filter);
  };

  function addFilterRow(filter = null) {
    const list = document.getElementById('filtersList');
    if (!list) return;
    
    const row = document.createElement('div');
    row.className = 'fw-filter-row';
    
    const fields = [
      { value: 'title', label: 'Title' },
      { value: 'status_label', label: 'Status' },
      { value: 'assigned_to', label: 'Assigned To' },
      { value: 'priority', label: 'Priority' },
      { value: 'due_date', label: 'Due Date' },
      { value: 'group_id', label: 'Group' },
      ...window.BOARD_DATA.columns.map(c => ({
        value: `column_${c.column_id}`,
        label: c.name
      }))
    ];
    
    row.innerHTML = `
      <select class="fw-select fw-filter-field" onchange="BoardApp.onFilterFieldChange(this)">
        <option value="">Select field...</option>
        ${fields.map(f => `<option value="${f.value}" ${filter?.field === f.value ? 'selected' : ''}>${f.label}</option>`).join('')}
      </select>
      
      <select class="fw-select fw-filter-operator">
        <option value="equals">Equals</option>
      </select>
      
      <input type="text" class="fw-input fw-filter-value" placeholder="Value..." value="${filter?.value || ''}" />
      
      <button class="fw-icon-btn" onclick="this.closest('.fw-filter-row').remove()" title="Remove filter">
        <svg width="14" height="14" fill="currentColor">
          <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="2"/>
        </svg>
      </button>
    `;
    
    list.appendChild(row);
    
    // Update operators based on field
    if (filter?.field) {
      const fieldSelect = row.querySelector('.fw-filter-field');
      onFilterFieldChange(fieldSelect);
      
      // Set operator
      if (filter?.operator) {
        row.querySelector('.fw-filter-operator').value = filter.operator;
      }
    }
  }

  // ===== ON FILTER FIELD CHANGE =====
  window.BoardApp.onFilterFieldChange = function(select) {
    onFilterFieldChange(select);
  };

  function onFilterFieldChange(select) {
    const row = select.closest('.fw-filter-row');
    const operatorSelect = row.querySelector('.fw-filter-operator');
    const valueInput = row.querySelector('.fw-filter-value');
    
    const field = select.value;
    
    let ops = [];
    let valueType = 'text';
    
    if (field === 'title') {
      ops = [
        { value: 'contains', label: 'Contains' },
        { value: 'not_contains', label: 'Does not contain' },
        { value: 'equals', label: 'Equals' },
        { value: 'is_empty', label: 'Is empty' },
        { value: 'is_not_empty', label: 'Is not empty' }
      ];
    } else if (field === 'status_label') {
      ops = [
        { value: 'equals', label: 'Is' }
      ];
      valueType = 'status';
    } else if (field === 'assigned_to') {
      ops = [
        { value: 'equals', label: 'Is' },
        { value: 'is_empty', label: 'Is unassigned' },
        { value: 'is_not_empty', label: 'Is assigned' }
      ];
      valueType = 'people';
    } else if (field === 'priority') {
      ops = [
        { value: 'equals', label: 'Is' }
      ];
      valueType = 'priority';
    } else if (field === 'due_date') {
      ops = [
        { value: 'equals', label: 'Is' },
        { value: 'before', label: 'Before' },
        { value: 'after', label: 'After' },
        { value: 'is_empty', label: 'Is empty' },
        { value: 'is_not_empty', label: 'Is set' },
        { value: 'is_overdue', label: 'Is overdue' }
      ];
      valueType = 'date';
    } else if (field === 'group_id') {
      ops = [
        { value: 'equals', label: 'Is' }
      ];
      valueType = 'group';
    } else if (field.startsWith('column_')) {
      // Get column type
      const columnId = parseInt(field.replace('column_', ''));
      const column = window.BOARD_DATA.columns.find(c => c.column_id === columnId);
      
      if (column) {
        if (column.type === 'number') {
          ops = [
            { value: 'equals', label: 'Equals' },
            { value: 'greater_than', label: 'Greater than' },
            { value: 'less_than', label: 'Less than' },
            { value: 'is_empty', label: 'Is empty' },
            { value: 'is_not_empty', label: 'Is not empty' }
          ];
          valueType = 'number';
        } else if (column.type === 'date') {
          ops = [
            { value: 'equals', label: 'Is' },
            { value: 'before', label: 'Before' },
            { value: 'after', label: 'After' },
            { value: 'is_empty', label: 'Is empty' },
            { value: 'is_not_empty', label: 'Is not empty' }
          ];
          valueType = 'date';
        } else {
          ops = [
            { value: 'contains', label: 'Contains' },
            { value: 'equals', label: 'Equals' },
            { value: 'is_empty', label: 'Is empty' },
            { value: 'is_not_empty', label: 'Is not empty' }
          ];
        }
      }
    }
    
    // Update operator select
    operatorSelect.innerHTML = ops.map(o => 
      `<option value="${o.value}">${o.label}</option>`
    ).join('');
    
    // Update value input based on type
    if (valueType === 'status') {
      const statuses = window.BOARD_DATA.statusConfig;
      valueInput.outerHTML = `
        <select class="fw-select fw-filter-value">
          ${Object.keys(statuses).map(k => 
            `<option value="${k}">${statuses[k].label}</option>`
          ).join('')}
        </select>
      `;
    } else if (valueType === 'people') {
      const users = window.BOARD_DATA.users;
      valueInput.outerHTML = `
        <select class="fw-select fw-filter-value">
          ${users.map(u => 
            `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`
          ).join('')}
        </select>
      `;
    } else if (valueType === 'priority') {
      valueInput.outerHTML = `
        <select class="fw-select fw-filter-value">
          <option value="low">Low</option>
          <option value="medium">Medium</option>
          <option value="high">High</option>
          <option value="extreme">Extreme</option>
        </select>
      `;
    } else if (valueType === 'group') {
      const groups = window.BOARD_DATA.groups;
      valueInput.outerHTML = `
        <select class="fw-select fw-filter-value">
          ${groups.map(g => 
            `<option value="${g.id}">${g.name}</option>`
          ).join('')}
        </select>
      `;
    } else if (valueType === 'date') {
      valueInput.type = 'date';
    } else if (valueType === 'number') {
      valueInput.type = 'number';
    } else {
      valueInput.type = 'text';
    }
  }

  // ===== APPLY FILTERS =====
  window.BoardApp.applyFilters = function() {
    const rows = document.querySelectorAll('.fw-filter-row');
    const filters = [];
    
    rows.forEach(row => {
      const field = row.querySelector('.fw-filter-field').value;
      const operator = row.querySelector('.fw-filter-operator').value;
      const value = row.querySelector('.fw-filter-value').value;
      
      if (field && operator) {
        filters.push({ field, operator, value });
      }
    });
    
    window.BoardApp.activeFilters = filters;
    
    // Update filter chip
    const chip = document.getElementById('filterChip');
    if (chip) {
      if (filters.length > 0) {
        chip.innerHTML = `
          <svg width="14" height="14" fill="currentColor"><path d="M0 1h14M2 5h10M4 9h6"/></svg>
          Filters (${filters.length})
        `;
        chip.classList.add('fw-chip--active');
      } else {
        chip.innerHTML = `
          <svg width="14" height="14" fill="currentColor"><path d="M0 1h14M2 5h10M4 9h6"/></svg>
          Filters
        `;
        chip.classList.remove('fw-chip--active');
      }
    }
    
    document.querySelector('.fw-modal-overlay')?.remove();
    
    if (filters.length > 0) {
      // Apply filters via API
      window.BoardApp.apiCall('/projects/api/filter/apply.php', {
        board_id: window.BOARD_DATA.boardId,
        filters: JSON.stringify(filters)
      }).then(data => {
        console.log('✅ Filters applied:', data);
        // Update UI with filtered items
        renderFilteredItems(data.items);
      }).catch(err => {
        alert('Failed to apply filters: ' + err.message);
      });
    } else {
      // Show all items
      window.location.reload();
    }
  };

  // ===== CLEAR FILTERS =====
  window.BoardApp.clearFilters = function() {
    window.BoardApp.activeFilters = [];
    
    const chip = document.getElementById('filterChip');
    if (chip) {
      chip.innerHTML = `
        <svg width="14" height="14" fill="currentColor"><path d="M0 1h14M2 5h10M4 9h6"/></svg>
        Filters
      `;
      chip.classList.remove('fw-chip--active');
    }
    
    document.querySelector('.fw-modal-overlay')?.remove();
    window.location.reload();
  };

  // ===== RENDER FILTERED ITEMS =====
  function renderFilteredItems(items) {
    // Hide all items first
    document.querySelectorAll('.fw-item-row').forEach(row => {
      row.style.display = 'none';
    });
    
    // Show filtered items
    const itemIds = items.map(i => i.id);
    itemIds.forEach(id => {
      const row = document.querySelector(`[data-item-id="${id}"]`);
      if (row) row.style.display = '';
    });
    
    // Show empty state if no results
    if (items.length === 0) {
      const firstGroup = document.querySelector('.fw-group tbody');
      if (firstGroup) {
        const emptyRow = document.createElement('tr');
        emptyRow.className = 'fw-filter-empty';
        emptyRow.innerHTML = `
          <td colspan="100" class="fw-empty-state">
            <div class="fw-empty-icon">🔍</div>
            <div class="fw-empty-title">No items match your filters</div>
            <div class="fw-empty-text">Try adjusting your filter criteria</div>
            <button class="fw-btn fw-btn--primary" onclick="BoardApp.clearFilters()">Clear Filters</button>
          </td>
        `;
        firstGroup.insertBefore(emptyRow, firstGroup.firstChild);
      }
    }
    
    // Update group counts
    document.querySelectorAll('.fw-group').forEach(group => {
      const groupId = group.dataset.groupId;
      const visibleItems = group.querySelectorAll('.fw-item-row[style=""]').length;
      const countEl = group.querySelector('.fw-group-count');
      if (countEl) countEl.textContent = visibleItems;
    });
  }

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 800px;">
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

  console.log('✅ Filters module loaded');

})();