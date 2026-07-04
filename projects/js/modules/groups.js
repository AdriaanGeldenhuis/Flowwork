/**
 * Group Management Module
 * Handles group operations: create, update, delete, color, collapse
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  /**
   * Toggle group collapse/expand
   */
  window.BoardApp.toggleGroup = function(groupId) {
    const group = document.querySelector(`[data-group-id="${groupId}"]`);
    if (!group) return;
    
    const isCollapsed = group.dataset.collapsed === 'true';
    const newState = !isCollapsed;
    
    group.dataset.collapsed = String(newState);
    
    // Save state to server
    const form = new FormData();
    form.append('group_id', groupId);
    form.append('collapsed', newState ? '1' : '0');
    
    fetch('/projects/api/group.update.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      console.log('✅ Group collapsed state updated');
    })
    .catch(err => {
      console.error('❌ Toggle error:', err);
      // Revert UI on error
      group.dataset.collapsed = String(isCollapsed);
    });
  };

  /**
   * Update group name
   */
  window.BoardApp.updateGroupName = function(groupId, newName) {
    const trimmedName = newName.trim();
    
    if (!trimmedName) {
      alert('Group name cannot be empty');
      return;
    }
    
    if (trimmedName.length > 100) {
      alert('Group name is too long (max 100 characters)');
      return;
    }
    
    console.log('📝 Updating group name:', { groupId, newName: trimmedName });
    
    const form = new FormData();
    form.append('group_id', groupId);
    form.append('name', trimmedName);
    
    fetch('/projects/api/group.update.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      console.log('✅ Group name updated');
    })
    .catch(err => {
      console.error('❌ Update name error:', err);
      alert('Failed to update group name: ' + err.message);
    });
  };

  /**
   * Show group context menu (color picker + delete)
   */
  window.BoardApp.showGroupMenu = function(groupId, event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    
    // Close any existing dropdowns
    window.BoardApp.closeAllDropdowns();
    
    const colors = [
      { name: 'Purple', value: '#8b5cf6', emoji: '💜' },
      { name: 'Blue', value: '#3b82f6', emoji: '💙' },
      { name: 'Green', value: '#10b981', emoji: '💚' },
      { name: 'Yellow', value: '#f59e0b', emoji: '💛' },
      { name: 'Red', value: '#ef4444', emoji: '❤️' },
      { name: 'Pink', value: '#ec4899', emoji: '💗' },
      { name: 'Orange', value: '#f97316', emoji: '🧡' },
      { name: 'Teal', value: '#14b8a6', emoji: '🩵' },
      { name: 'Indigo', value: '#6366f1', emoji: '💙' },
      { name: 'Gray', value: '#6b7280', emoji: '🤍' }
    ];
    
    const html = `
      <div class="fw-dropdown-section">
        <div class="fw-dropdown-label">Group Color</div>
        ${colors.map(c => `
          <button class="fw-dropdown-item" onclick="BoardApp.setGroupColor(${groupId}, '${c.value}')">
            <span style="display:inline-block;width:16px;height:16px;border-radius:50%;margin-right:10px;background:${c.value};box-shadow:0 0 8px ${c.value}40;"></span>
            ${c.name}
          </button>
        `).join('')}
      </div>
      <hr style="margin: 8px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.1);">
      <button class="fw-dropdown-item" onclick="BoardApp.duplicateGroup(${groupId})">
        <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
          <rect x="2" y="2" width="8" height="8" rx="1" stroke="currentColor" fill="none"/>
          <rect x="4" y="4" width="8" height="8" rx="1"/>
        </svg>
        Duplicate Group
      </button>
      <button class="fw-dropdown-item fw-dropdown-item--danger" onclick="BoardApp.deleteGroup(${groupId})">
        <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
          <path d="M3 6h8M5 6V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2m1 0v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6h6z"/>
        </svg>
        Delete Group
      </button>
    `;
    
    window.BoardApp.showDropdown(event.target, html);
  };

  /**
   * Set group color
   */
  window.BoardApp.setGroupColor = function(groupId, color) {
    console.log('🎨 Setting group color:', { groupId, color });
    
    const form = new FormData();
    form.append('group_id', groupId);
    form.append('color', color);
    
    fetch('/projects/api/group-color.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(async response => {
      console.log('📡 Response status:', response.status);
      console.log('📡 Response headers:', [...response.headers.entries()]);
      
      // Get response text first
      const text = await response.text();
      console.log('📡 Response body:', text);
      
      // Check if response is JSON
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        console.error('❌ Non-JSON response:', text.substring(0, 500));
        throw new Error('Server returned non-JSON response: ' + text.substring(0, 100));
      }
      
      // Parse JSON
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        console.error('❌ JSON parse error:', e);
        console.error('❌ Raw text:', text);
        throw new Error('Invalid JSON response: ' + e.message);
      }
      
      if (!data.ok) {
        throw new Error(data.error || 'Failed to update color');
      }
      
      return data;
    })
    .then(data => {
      console.log('✅ Color updated successfully:', data);
      
      // Update UI immediately
      const groupEl = document.querySelector(`[data-group-id="${groupId}"]`);
      if (!groupEl) {
        console.warn('⚠️ Group element not found');
        return;
      }
      
      const header = groupEl.querySelector('.fw-group-header');
      if (header) {
        header.style.borderLeftColor = color;
      }
      
      const nameInput = groupEl.querySelector('.fw-group-name');
      if (nameInput) {
        nameInput.style.color = color;
      }
      
      // Close dropdown
      window.BoardApp.closeAllDropdowns();
      
      // Show success toast if available
      if (typeof window.BoardApp.showToast === 'function') {
        window.BoardApp.showToast('Group color updated', 'success');
      }
    })
    .catch(err => {
      console.error('❌ Color update error:', err);
      console.error('❌ Error stack:', err.stack);
      alert('Failed to update color: ' + err.message);
    });
};

  /**
   * Build a new group section in the DOM by cloning an existing one and
   * rewiring its ids/handlers. Returns the new element, or null when there is
   * no group to clone from (caller should fall back to a reload).
   */
  function insertGroupIntoDOM(group) {
    const template = document.querySelector('.fw-group:not(.fw-board-totals-group)');
    const boardContainer = document.querySelector('.fw-board-container');
    if (!template || !boardContainer) return null;

    const gid = parseInt(group.id, 10);
    const color = group.color || '#8b5cf6';
    const clone = template.cloneNode(true);

    clone.id = 'group-' + gid;
    clone.dataset.groupId = String(gid);
    clone.dataset.collapsed = 'false';
    clone.style.display = '';
    clone.classList.remove('fw-dragging-group', 'fw-group-drag-over-top', 'fw-group-drag-over-bottom', 'fw-group-drop-target');

    // Header
    const header = clone.querySelector('.fw-group-header');
    header.style.borderLeftColor = color;
    header.style.background = '';
    header.querySelector('.fw-group-toggle')?.setAttribute('onclick', `BoardApp.toggleGroup(${gid})`);
    const nameInput = header.querySelector('.fw-group-name');
    nameInput.value = group.name;
    nameInput.style.color = color;
    nameInput.setAttribute('onblur', `BoardApp.updateGroupName(${gid}, this.value)`);
    header.querySelector('.fw-group-count').textContent = '0';
    header.querySelector('[onclick*="showGroupMenu"]')?.setAttribute('onclick', `BoardApp.showGroupMenu(${gid}, event)`);

    // Table head: select-all checkbox targets the new group
    const selectAll = clone.querySelector('thead .fw-col-checkbox input');
    if (selectAll) {
      selectAll.checked = false;
      selectAll.setAttribute('onchange', `BoardApp.toggleGroupSelection(${gid}, this.checked)`);
    }

    // Table body: strip cloned rows, insert an empty state
    const tbody = clone.querySelector('tbody');
    tbody.querySelectorAll('tr.fw-item-row').forEach(tr => tr.remove());
    tbody.querySelectorAll('.fw-empty-state').forEach(td => td.closest('tr')?.remove());

    const colCount = 3 + (window.BOARD_DATA.columns || []).length;
    const emptyRow = document.createElement('tr');
    emptyRow.innerHTML = `
      <td colspan="${colCount}" class="fw-empty-state">
        <div class="fw-empty-icon">📋</div>
        <div class="fw-empty-title">No items yet</div>
        <div class="fw-empty-text">Click "+ Add item" below to get started</div>
      </td>
    `;
    tbody.insertBefore(emptyRow, tbody.firstChild);

    // Aggregation row
    const aggRow = tbody.querySelector('.fw-group-agg-row');
    if (aggRow) {
      aggRow.dataset.groupId = String(gid);
      aggRow.querySelectorAll('.fw-agg-cell').forEach(cell => { cell.dataset.groupId = String(gid); });
    }

    // Quick-add row
    const addInput = tbody.querySelector('.fw-quick-add-input');
    if (addInput) {
      addInput.value = '';
      addInput.disabled = false;
      addInput.dataset.groupId = String(gid);
      addInput.setAttribute('onkeydown', `if(event.key==='Enter') BoardApp.quickAddItem(this, ${gid})`);
    }

    const totals = boardContainer.querySelector('.fw-board-totals-group');
    boardContainer.insertBefore(clone, totals || null);

    if (window.BoardApp.updateAggregations) window.BoardApp.updateAggregations(gid);
    return clone;
  }

  /**
   * Delete group and all its items
   */
  window.BoardApp.deleteGroup = function(groupId) {
    const groupEl = document.querySelector(`.fw-group[data-group-id="${groupId}"]`);
    const groupName = groupEl?.querySelector('.fw-group-name')?.value || 'this group';

    const doDelete = () => {
      const form = new FormData();
      form.append('group_id', groupId);

      fetch('/projects/api/group.delete.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error(data.error);

        // Remove the section and its items from local state — no reload
        groupEl?.remove();
        window.BOARD_DATA.groups = (window.BOARD_DATA.groups || []).filter(g => String(g.id) !== String(groupId));
        window.BOARD_DATA.items = (window.BOARD_DATA.items || []).filter(i => String(i.group_id) !== String(groupId));
        if (window.BoardApp.updateBoardTotals) window.BoardApp.updateBoardTotals();

        if (typeof window.BoardApp.showToast === 'function') {
          window.BoardApp.showToast(`Group "${groupName}" deleted`, 'success');
        }
      })
      .catch(err => {
        console.error('❌ Delete error:', err);
        alert('Failed to delete group: ' + err.message);
      });
    };

    if (window.BoardApp.dialog) {
      window.BoardApp.dialog.confirm(
        `Delete "${groupName}" and all its items? This cannot be undone.`,
        { title: 'Delete group', confirmLabel: 'Delete', danger: true }
      ).then(ok => { if (ok) doDelete(); });
    } else if (confirm(`Delete "${groupName}" and all its items?\n\nThis action cannot be undone.`)) {
      doDelete();
    }
  };

  /**
   * Duplicate group (renders the copy in place — no reload)
   */
  window.BoardApp.duplicateGroup = function(groupId) {
    window.BoardApp.apiCall('/projects/api/group.duplicate.php', {
      group_id: groupId,
      board_id: window.BOARD_DATA.boardId
    })
    .then(data => {
      const group = data.group;
      if (!group || !insertGroupIntoDOM(group)) {
        window.location.reload();
        return;
      }

      window.BOARD_DATA.groups.push(group);

      // Render the copied items with their cell values
      (data.items || []).forEach(item => {
        if (window.BoardApp.addItemToDOM) window.BoardApp.addItemToDOM(item, group.id);
        else window.BOARD_DATA.items.push(item);

        const values = (data.values || {})[item.id] || {};
        window.BOARD_DATA.valuesMap[item.id] = Object.assign({}, values);
        Object.entries(values).forEach(([colId, value]) => {
          const col = (window.BOARD_DATA.columns || []).find(c => String(c.column_id) === String(colId));
          if (col && window.BoardApp.renderCellFull) {
            window.BoardApp.renderCellFull(item.id, colId, value, col.type);
          }
        });
      });

      if (window.BoardApp.updateAggregations) window.BoardApp.updateAggregations(group.id);
      if (window.BoardApp.updateBoardTotals) window.BoardApp.updateBoardTotals();

      if (typeof window.BoardApp.showToast === 'function') {
        window.BoardApp.showToast('Group duplicated', 'success');
      }
    })
    .catch(err => {
      console.error('❌ Duplicate error:', err);
      alert('Failed to duplicate group: ' + err.message);
    });
  };

  /**
   * Show add group modal (styled prompt; renders the group in place)
   */
  window.BoardApp.showAddGroupModal = function() {
    const create = (trimmedName) => {
      const form = new FormData();
      form.append('board_id', window.BOARD_DATA.boardId);
      form.append('name', trimmedName);
      form.append('color', '#8b5cf6'); // Default purple color

      fetch('/projects/api/group.create.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      })
      .then(r => r.json())
      .then(data => {
        if (!data.ok) throw new Error(data.error);

        const newId = data.data?.group_id || data.data?.id || data.group_id || data.id;
        const group = { id: newId, name: trimmedName, color: '#8b5cf6', collapsed: 0 };

        if (!newId || !insertGroupIntoDOM(group)) {
          window.location.reload();
          return;
        }

        window.BOARD_DATA.groups.push(group);

        if (typeof window.BoardApp.showToast === 'function') {
          window.BoardApp.showToast('Group created', 'success');
        }

        // Put the cursor in the new group's quick-add input
        document.querySelector(`.fw-group[data-group-id="${newId}"] .fw-quick-add-input`)?.focus();
      })
      .catch(err => {
        console.error('❌ Create error:', err);
        alert('Failed to create group: ' + err.message);
      });
    };

    if (window.BoardApp.dialog) {
      window.BoardApp.dialog.prompt('Group name', {
        title: 'Add group',
        placeholder: 'e.g. Sprint 2, Snags, Electrical…',
        confirmLabel: 'Create',
        maxLength: 100
      }).then(name => { if (name) create(name); });
    } else {
      const name = prompt('Enter group name:');
      if (name && name.trim()) create(name.trim().substring(0, 100));
    }
  };

  /**
   * Reorder groups (drag & drop)
   */
  window.BoardApp.reorderGroups = function(groupId, newPosition) {
    console.log('📊 Reordering group:', { groupId, newPosition });
    
    const form = new FormData();
    form.append('group_id', groupId);
    form.append('position', newPosition);
    
    fetch('/projects/api/group.reorder.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      console.log('✅ Group reordered');
    })
    .catch(err => {
      console.error('❌ Reorder error:', err);
      window.location.reload(); // Reload to fix UI
    });
  };

  console.log('✅ Groups module loaded');

})();