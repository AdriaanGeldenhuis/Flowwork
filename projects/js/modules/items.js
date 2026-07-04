/**
 * Item Management Module - CLEAN VERSION
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  // ===== UPDATE ITEM TITLE =====
  window.BoardApp.updateItemTitle = function(itemId, newTitle) {
    if (!newTitle.trim()) return;
    
    console.log('📝 Updating item title:', itemId, newTitle);
    
    window.BoardApp.apiCall('/projects/api/item.update.php', {
      item_id: itemId,
      title: newTitle.trim()
    }).then(data => {
      console.log('✅ Item title updated');
    }).catch(err => {
      console.error('❌ Update title error:', err);
      alert('Failed to update title: ' + err.message);
    });
  };

  // ===== QUICK ADD ITEM =====
window.BoardApp.quickAddItem = function(input, groupId) {
  const title = input.value.trim();
  if (!title) return;
  
  console.log('🔄 Creating item:', { title, groupId });
  
  const originalValue = input.value;
  input.value = '';
  input.disabled = true;
  
  window.BoardApp.apiCall('/projects/api/create_item.php', {
    board_id: window.BOARD_DATA.boardId,
    group_id: groupId,
    title: title
  }).then(data => {
    console.log('✅ Item created:', data);
    
    input.disabled = false;
    input.focus();
    
    if (data.item) {
      // Add to local data
      window.BOARD_DATA.items.push(data.item);
      
      // Add to DOM if function exists
      if (window.BoardApp.addItemToDOM) {
        window.BoardApp.addItemToDOM(data.item, groupId);
        
        // ✅ NUUT: Update group count
        const group = document.querySelector(`[data-group-id="${groupId}"]`);
        if (group) {
          const countEl = group.querySelector('.fw-group-count');
          const currentCount = parseInt(countEl?.textContent || '0');
          if (countEl) {
            countEl.textContent = currentCount + 1;
            countEl.classList.add('fw-count-updated');
            setTimeout(() => countEl.classList.remove('fw-count-updated'), 300);
          }
        }
        
        // ✅ NUUT: Update aggregations
        setTimeout(() => {
          if (window.BoardApp.updateAggregations) {
            window.BoardApp.updateAggregations(groupId);
          }
          
          // ✅ NUUT: Update board totals
          if (window.BoardApp.updateBoardTotals) {
            window.BoardApp.updateBoardTotals();
          }
        }, 200);
      } else {
        // Fallback to reload
        window.location.reload();
      }
    } else {
      window.location.reload();
    }
    
  }).catch(err => {
    console.error('❌ Create item error:', err);
    input.disabled = false;
    input.value = originalValue;
    alert('Failed to add item: ' + err.message);
  });
};

  // ===== DUPLICATE ITEM (renders the copy in place — no reload) =====
  window.BoardApp.duplicateItem = function(itemId) {
    window.BoardApp.apiCall('/projects/api/item.duplicate.php', {
      item_id: itemId
    }).then(data => {
      const item = data.item;
      if (!item || !window.BoardApp.addItemToDOM) {
        window.location.reload();
        return;
      }

      // addItemToDOM inserts the table row AND pushes into BOARD_DATA.items
      window.BoardApp.addItemToDOM(item, item.group_id);

      // Render the copied cell values
      const values = data.values || {};
      window.BOARD_DATA.valuesMap[item.id] = Object.assign({}, values);
      Object.entries(values).forEach(([colId, value]) => {
        const col = (window.BOARD_DATA.columns || []).find(c => String(c.column_id) === String(colId));
        if (col && window.BoardApp.renderCellFull) {
          window.BoardApp.renderCellFull(item.id, colId, value, col.type);
        }
      });

      // Item-field-backed cells (status/people/date/priority fallbacks)
      const fieldCells = [
        ['status', item.status_label], ['people', item.assigned_to],
        ['date', item.due_date ? String(item.due_date).split(' ')[0] : null], ['priority', item.priority]
      ];
      fieldCells.forEach(([type, value]) => {
        if (!value) return;
        const col = (window.BOARD_DATA.columns || []).find(c => c.type === type);
        if (col && !(values && values[col.column_id]) && window.BoardApp.renderCellFull) {
          window.BoardApp.renderCellFull(item.id, col.column_id, value, type);
        }
      });

      if (window.BoardApp.updateAggregations) window.BoardApp.updateAggregations(item.group_id);
      if (window.BoardApp.updateBoardTotals) window.BoardApp.updateBoardTotals();
      if (window.BoardApp.showToast) window.BoardApp.showToast('Item duplicated', 'success');
    }).catch(err => {
      console.error('❌ Duplicate error:', err);
      alert('Failed to duplicate: ' + err.message);
    });
  };

  // ===== DELETE ITEM (soft-archive with Undo) =====
  window.BoardApp.deleteItem = function(itemId) {
    // Archive instead of hard-deleting so the action is reversible from the toast
    window.BoardApp.apiCall('/projects/api/item.update.php', {
      item_id: itemId,
      archived: 1
    }).then(() => {
      const row = document.querySelector(`tr.fw-item-row[data-item-id="${itemId}"]`);
      if (row) row.style.display = 'none';

      if (typeof window.BoardApp.showToast !== 'function') {
        if (row) row.remove();
        return;
      }

      window.BoardApp.showToast('Item deleted', 'info', {
        duration: 8000,
        actionLabel: 'Undo',
        onAction: () => {
          window.BoardApp.apiCall('/projects/api/item.update.php', {
            item_id: itemId,
            archived: 0
          }).then(() => {
            if (row) row.style.display = '';
            window.BoardApp.showToast('Item restored', 'success');
          }).catch(err => {
            alert('Failed to restore item: ' + err.message);
          });
        },
        onExpire: () => {
          if (window.BoardApp.removeItemFromDOM) {
            window.BoardApp.removeItemFromDOM(itemId);
          } else if (row) {
            row.remove();
          }
        }
      });
    }).catch(err => {
      console.error('❌ Delete error:', err);
      alert('Failed to delete: ' + err.message);
    });
  };

  // ===== SHOW ITEM MENU =====
  /**
 * Show item context menu (3 dots on row)
 */
window.BoardApp.showItemMenu = function(itemId, event) {
  event?.stopPropagation();
  window.BoardApp.closeAllDropdowns();
  
  const html = `
    <button class="fw-dropdown-item" onclick="BoardApp.openItemPanel(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <rect x="2" y="2" width="10" height="10" rx="1" stroke="currentColor" fill="none"/>
        <path d="M8 2v10" stroke="currentColor" stroke-width="1.5"/>
      </svg>
      Open Item
    </button>
    <button class="fw-dropdown-item" onclick="BoardApp.showSubitems(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <path d="M4 6h8M4 9h8M4 12h8" stroke="currentColor" stroke-width="1.5"/>
      </svg>
      Subitems
    </button>
    <hr style="margin: 8px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.1);">
    <button class="fw-dropdown-item" onclick="BoardApp.showComments(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <path d="M3 3h8a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H6l-3 2V4a1 1 0 0 1 1-1z"/>
      </svg>
      Comments
    </button>
    <button class="fw-dropdown-item" onclick="BoardApp.duplicateItem(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <rect x="2" y="2" width="8" height="8" rx="1" stroke="currentColor" fill="none"/>
        <rect x="4" y="4" width="8" height="8" rx="1"/>
      </svg>
      Duplicate Item
    </button>
    <button class="fw-dropdown-item" onclick="BoardApp.showItemHistory(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <circle cx="7" cy="7" r="5" stroke="currentColor" fill="none"/>
        <path d="M7 4v3l2 2"/>
      </svg>
      Activity Log
    </button>
    <button class="fw-dropdown-item" onclick="BoardApp.copyItemLink(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <path d="M6 8a3 3 0 0 1 0-4l2-2a3 3 0 0 1 4 4l-1 1M8 6a3 3 0 0 1 0 4l-2 2a3 3 0 0 1-4-4l1-1" stroke="currentColor" fill="none" stroke-width="1.5"/>
      </svg>
      Copy Item Link
    </button>
    <hr style="margin: 8px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.1);">
    <button class="fw-dropdown-item fw-dropdown-item--danger" onclick="BoardApp.deleteItem(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <path d="M3 6h8M5 6V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2m1 0v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6h6z"/>
      </svg>
      Delete Item
    </button>
  `;
  
  window.BoardApp.showDropdown(event.target, html);
};

  // ===== COPY ITEM LINK =====
  window.BoardApp.copyItemLink = function(itemId) {
    const url = `${window.location.origin}/projects/board.php?board_id=${window.BOARD_DATA.boardId}&item=${itemId}`;
    const done = () => {
      if (window.BoardApp.showToast) window.BoardApp.showToast('Item link copied', 'success');
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(() => prompt('Copy this link:', url));
    } else {
      prompt('Copy this link:', url);
    }
  };

  console.log('✅ Items module loaded');

})();