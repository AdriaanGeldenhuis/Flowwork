/**
 * Drag & Drop Module - COMPLETE VERSION
 * Reorder items within groups and move between groups
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};
  
  let dragState = {
    draggedItem: null,
    draggedElement: null,
    sourceGroupId: null,
    placeholder: null
  };

  // ===== INIT DRAG & DROP =====
  function initDragDrop() {
    console.log('🎯 Initializing drag & drop...');
    
    // Make all item rows draggable on load
    makeItemsDraggable();
    
    // Re-make draggable when new items are added
    document.addEventListener('itemAdded', makeItemsDraggable);
    
    setupDragHandlers();
    
    console.log('✅ Drag & drop initialized');
  }

  // ===== MAKE ITEMS DRAGGABLE =====
  function makeItemsDraggable() {
    document.querySelectorAll('.fw-item-row').forEach(row => {
      if (!row.hasAttribute('draggable')) {
        row.setAttribute('draggable', 'true');
        row.style.cursor = 'grab';
      }
    });
  }

  // ===== SETUP DRAG HANDLERS =====
  function setupDragHandlers() {
    // Drag start
    document.addEventListener('dragstart', handleDragStart);
    
    // Drag over
    document.addEventListener('dragover', handleDragOver);
    
    // Drag enter
    document.addEventListener('dragenter', handleDragEnter);
    
    // Drag leave
    document.addEventListener('dragleave', handleDragLeave);
    
    // Drop
    document.addEventListener('drop', handleDrop);
    
    // Drag end
    document.addEventListener('dragend', handleDragEnd);
  }

  // ===== DRAG START =====
  function handleDragStart(e) {
    const row = e.target.closest('.fw-item-row');
    if (!row) return;
    
    dragState.draggedElement = row;
    dragState.draggedItem = {
      id: parseInt(row.dataset.itemId),
      groupId: parseInt(row.dataset.groupId),
      title: row.querySelector('.fw-item-title')?.value || 'Item'
    };
    dragState.sourceGroupId = dragState.draggedItem.groupId;
    
    // Style
    row.classList.add('fw-dragging');
    row.style.opacity = '0.5';
    row.style.cursor = 'grabbing';
    
    // Create placeholder
    dragState.placeholder = createPlaceholder();
    
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', row.innerHTML);
    
    console.log('🎯 Drag started:', dragState.draggedItem);
  }

  // ===== DRAG OVER =====
  function handleDragOver(e) {
    if (!dragState.draggedElement) return;
    
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    
    const targetRow = e.target.closest('.fw-item-row');
    const targetGroup = e.target.closest('.fw-group');
    
    if (targetRow && targetRow !== dragState.draggedElement) {
      const rect = targetRow.getBoundingClientRect();
      const midpoint = rect.top + rect.height / 2;
      const isAbove = e.clientY < midpoint;
      
      // Remove all indicators
      document.querySelectorAll('.fw-drag-over-top, .fw-drag-over-bottom').forEach(el => {
        el.classList.remove('fw-drag-over-top', 'fw-drag-over-bottom');
      });
      
      // Add indicator
      if (isAbove) {
        targetRow.classList.add('fw-drag-over-top');
      } else {
        targetRow.classList.add('fw-drag-over-bottom');
      }
    }
    
    // Allow drop on group header
    if (targetGroup && !targetRow) {
      const groupHeader = e.target.closest('.fw-group-header');
      if (groupHeader) {
        e.preventDefault();
        groupHeader.style.background = 'rgba(139, 92, 246, 0.2)';
      }
    }
  }

  // ===== DRAG ENTER =====
  function handleDragEnter(e) {
    if (!dragState.draggedElement) return;
    
    const groupHeader = e.target.closest('.fw-group-header');
    if (groupHeader) {
      groupHeader.style.background = 'rgba(139, 92, 246, 0.2)';
    }
  }

  // ===== DRAG LEAVE =====
  function handleDragLeave(e) {
    const groupHeader = e.target.closest('.fw-group-header');
    if (groupHeader) {
      groupHeader.style.background = '';
    }
    
    const row = e.target.closest('.fw-item-row');
    if (row) {
      row.classList.remove('fw-drag-over-top', 'fw-drag-over-bottom');
    }
  }

  // ===== DROP =====
  function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    
    if (!dragState.draggedElement) return;
    
    // Clean up indicators
    document.querySelectorAll('.fw-drag-over-top, .fw-drag-over-bottom').forEach(el => {
      el.classList.remove('fw-drag-over-top', 'fw-drag-over-bottom');
    });
    
    const groupHeader = e.target.closest('.fw-group-header');
    if (groupHeader) {
      groupHeader.style.background = '';
    }
    
    const targetRow = e.target.closest('.fw-item-row');
    const targetGroup = e.target.closest('.fw-group');
    
    if (targetRow && targetRow !== dragState.draggedElement) {
      // Drop on row
      const rect = targetRow.getBoundingClientRect();
      const isAbove = e.clientY < (rect.top + rect.height / 2);
      
      const tbody = targetRow.closest('tbody');
      if (isAbove) {
        tbody.insertBefore(dragState.draggedElement, targetRow);
      } else {
        tbody.insertBefore(dragState.draggedElement, targetRow.nextSibling);
      }
      
      const targetGroupId = parseInt(targetRow.dataset.groupId);
      const targetItemId = parseInt(targetRow.dataset.itemId);
      
      // Update dataset
      dragState.draggedElement.dataset.groupId = targetGroupId;
      
      // Save to server
      saveItemPosition(
        dragState.draggedItem.id,
        targetGroupId,
        isAbove ? targetItemId : null
      );
      
    } else if (targetGroup && groupHeader) {
      // Drop on group header - move to end of group
      const targetGroupId = parseInt(targetGroup.dataset.groupId);
      const tbody = targetGroup.querySelector('tbody');
      const addRow = tbody.querySelector('.fw-add-row');
      
      tbody.insertBefore(dragState.draggedElement, addRow);
      
      // Update dataset
      dragState.draggedElement.dataset.groupId = targetGroupId;
      
      // Save to server
      saveItemPosition(dragState.draggedItem.id, targetGroupId, null);
    }
    
    console.log('✅ Drop completed');
  }

  // ===== DRAG END =====
  function handleDragEnd(e) {
    if (!dragState.draggedElement) return;
    
    // Clean up
    dragState.draggedElement.classList.remove('fw-dragging');
    dragState.draggedElement.style.opacity = '';
    dragState.draggedElement.style.cursor = 'grab';
    
    document.querySelectorAll('.fw-drag-over-top, .fw-drag-over-bottom').forEach(el => {
      el.classList.remove('fw-drag-over-top', 'fw-drag-over-bottom');
    });
    
    document.querySelectorAll('.fw-group-header').forEach(header => {
      header.style.background = '';
    });
    
    if (dragState.placeholder && dragState.placeholder.parentNode) {
      dragState.placeholder.remove();
    }
    
    // Reset state
    dragState = {
      draggedItem: null,
      draggedElement: null,
      sourceGroupId: null,
      placeholder: null
    };
    
    console.log('🎯 Drag ended');
  }

  // ===== CREATE PLACEHOLDER =====
  function createPlaceholder() {
    const placeholder = document.createElement('tr');
    placeholder.className = 'fw-drag-placeholder';
    placeholder.innerHTML = '<td colspan="100" style="height: 50px; background: rgba(139, 92, 246, 0.1); border: 2px dashed var(--primary);"></td>';
    return placeholder;
  }

  // ===== SAVE ITEM POSITION =====
  function saveItemPosition(itemId, newGroupId, beforeItemId) {
    console.log('💾 Saving position:', { itemId, newGroupId, beforeItemId });
    
    const data = {
      item_id: itemId,
      group_id: newGroupId
    };
    
    if (beforeItemId) {
      data.before_item_id = beforeItemId;
    }
    
    window.BoardApp.apiCall('/projects/api/item.move.php', data)
      .then(response => {
        console.log('✅ Item position saved:', response);
        
        // Update in memory
        const item = window.BOARD_DATA.items.find(i => i.id == itemId);
        if (item) {
          item.group_id = newGroupId;
        }
        
        // Update group counts
        updateAllGroupCounts();
        
        // Show success feedback
        showToast('✅ Item moved', 'success');
      })
      .catch(err => {
        console.error('❌ Save position failed:', err);
        showToast('❌ Failed to move item: ' + err.message, 'error');
        
        // Reload on error
        setTimeout(() => {
          window.location.reload();
        }, 1500);
      });
  }

  // ===== UPDATE GROUP COUNTS =====
  function updateAllGroupCounts() {
    document.querySelectorAll('.fw-group').forEach(group => {
      const groupId = group.dataset.groupId;
      const items = group.querySelectorAll('.fw-item-row:not(.fw-dragging)');
      const count = items.length;
      
      const countEl = group.querySelector('.fw-group-count');
      if (countEl) {
        countEl.textContent = count;
        
        // Animate count change
        countEl.style.transform = 'scale(1.2)';
        setTimeout(() => {
          countEl.style.transform = 'scale(1)';
        }, 200);
      }
    });
  }

  // ===== SHOW TOAST =====
  function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fw-toast fw-toast--${type}`;
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 12px 20px;
      background: rgba(33, 38, 45, 0.98);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 8px;
      color: white;
      font-size: 14px;
      font-weight: 600;
      z-index: 10000;
      backdrop-filter: blur(10px);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
      animation: slideInRight 0.3s ease;
    `;
    
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
      toast.style.animation = 'slideOutRight 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 2500);
  }

  // ===== EXPOSE FUNCTIONS =====
  window.BoardApp.dragDrop = {
    reinit: makeItemsDraggable,
    updateCounts: updateAllGroupCounts
  };

  // ===== INITIALIZE ON LOAD =====
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDragDrop);
  } else {
    initDragDrop();
  }

  console.log('✅ Drag & Drop module loaded');

})();