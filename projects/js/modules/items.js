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

  // ===== DUPLICATE ITEM =====
  window.BoardApp.duplicateItem = function(itemId) {
    console.log('📋 Duplicating item:', itemId);
    
    window.BoardApp.apiCall('/projects/api/item.duplicate.php', {
      item_id: itemId
    }).then(data => {
      console.log('✅ Item duplicated:', data.item_id);
      window.location.reload();
    }).catch(err => {
      console.error('❌ Duplicate error:', err);
      alert('Failed to duplicate: ' + err.message);
    });
  };

  // ===== DELETE ITEM =====
  window.BoardApp.deleteItem = function(itemId) {
    if (!confirm('Delete this item permanently?')) return;
    
    console.log('🗑️ Deleting item:', itemId);
    
    window.BoardApp.apiCall('/projects/api/item.delete.php', {
      item_id: itemId
    }).then(() => {
      console.log('✅ Item deleted');
      
      const row = document.querySelector(`[data-item-id="${itemId}"]`);
      if (row) {
        row.style.transition = 'opacity 0.3s, transform 0.3s';
        row.style.opacity = '0';
        row.style.transform = 'translateX(-20px)';
        setTimeout(() => row.remove(), 300);
      }
      
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
    <button class="fw-dropdown-item" onclick="BoardApp.showSubitems(${itemId})">
      <svg width="14" height="14" fill="currentColor" style="margin-right: 8px;">
        <path d="M4 6h8M4 9h8M4 12h8" stroke="currentColor" stroke-width="1.5"/>
      </svg>
      Subitems
    </button>
    <hr style="margin: 8px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.1);">
    <button class="fw-dropdown-item" onclick="BoardApp.showItemComments(${itemId})">
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

  console.log('✅ Items module loaded');

})();