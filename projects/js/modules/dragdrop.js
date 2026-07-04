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
    sourceGroupId: null
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

    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(dragState.draggedItem.id));
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
    
    // Allow drop on group header OR anywhere in a group's body (incl. empty groups)
    if (targetGroup && !targetRow && !targetGroup.classList.contains('fw-board-totals-group')) {
      const groupHeader = e.target.closest('.fw-group-header');
      if (groupHeader) {
        groupHeader.style.background = 'rgba(139, 92, 246, 0.2)';
      } else if (e.target.closest('.fw-group-content')) {
        targetGroup.classList.add('fw-group-drop-target');
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

    const group = e.target.closest('.fw-group');
    if (group && !group.contains(e.relatedTarget)) {
      group.classList.remove('fw-group-drop-target');
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
    
    document.querySelectorAll('.fw-group-drop-target').forEach(el => el.classList.remove('fw-group-drop-target'));

    const targetRow = e.target.closest('.fw-item-row');
    const targetGroup = e.target.closest('.fw-group');

    if (targetRow && targetRow !== dragState.draggedElement) {
      // Drop on row (above or below its midpoint)
      const rect = targetRow.getBoundingClientRect();
      const isAbove = e.clientY < (rect.top + rect.height / 2);

      const tbody = targetRow.closest('tbody');
      if (isAbove) {
        tbody.insertBefore(dragState.draggedElement, targetRow);
      } else {
        tbody.insertBefore(dragState.draggedElement, targetRow.nextSibling);
      }

      const targetGroupId = parseInt(targetRow.dataset.groupId);
      dragState.draggedElement.dataset.groupId = targetGroupId;
      saveItemPosition(dragState.draggedItem.id, targetGroupId, dragState.sourceGroupId);

    } else if (targetGroup && !targetGroup.classList.contains('fw-board-totals-group')
               && (groupHeader || e.target.closest('.fw-group-content'))) {
      // Drop on the group header or anywhere in the group body — append to the
      // end of that group. This is also how empty groups accept their first item.
      const targetGroupId = parseInt(targetGroup.dataset.groupId);
      const tbody = targetGroup.querySelector('tbody');

      // An empty group shows a placeholder row — clear it before inserting
      tbody.querySelectorAll('.fw-empty-state').forEach(td => td.closest('tr')?.remove());

      const insertBeforeEl = tbody.querySelector('.fw-agg-row') || tbody.querySelector('.fw-add-row');
      tbody.insertBefore(dragState.draggedElement, insertBeforeEl || null);

      dragState.draggedElement.dataset.groupId = targetGroupId;
      saveItemPosition(dragState.draggedItem.id, targetGroupId, dragState.sourceGroupId);
    }
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
    
    document.querySelectorAll('.fw-group-drop-target').forEach(el => el.classList.remove('fw-group-drop-target'));

    // Reset state
    dragState = {
      draggedItem: null,
      draggedElement: null,
      sourceGroupId: null
    };
  }

  // ===== SAVE ITEM POSITION =====
  // Persist what the DOM now shows: move the item to its (possibly new) group,
  // then send the destination group's full row order to item.reorder.php.
  // The old before_item_id approach silently appended on drop-below, letting
  // the DOM and DB diverge until the next reload.
  function saveItemPosition(itemId, newGroupId, sourceGroupId) {
    const tbody = document.querySelector(`.fw-group[data-group-id="${newGroupId}"] tbody`);
    const orderedIds = tbody
      ? Array.from(tbody.querySelectorAll('tr.fw-item-row')).map(r => r.dataset.itemId)
      : [];

    const reorder = () => window.BoardApp.apiCall('/projects/api/item.reorder.php', {
      board_id: window.BOARD_DATA.boardId,
      group_id: newGroupId,
      ordered_item_ids: orderedIds
    });

    const changedGroup = String(newGroupId) !== String(sourceGroupId);
    const chain = changedGroup
      ? window.BoardApp.apiCall('/projects/api/item.move.php', { item_id: itemId, group_id: newGroupId }).then(reorder)
      : reorder();

    chain
      .then(() => {
        // Update in memory
        const item = window.BOARD_DATA.items.find(i => String(i.id) === String(itemId));
        if (item) item.group_id = newGroupId;

        updateAllGroupCounts();

        if (changedGroup && window.BoardApp.updateAggregations) {
          window.BoardApp.updateAggregations(sourceGroupId);
          window.BoardApp.updateAggregations(newGroupId);
        }

        showToast('Item moved', 'success');
      })
      .catch(err => {
        console.error('❌ Save position failed:', err);
        showToast('Failed to move item: ' + err.message, 'error');

        // Reload on error — the DOM may no longer match the server
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

  // ===== SHOW TOAST (delegates to the canonical toast in ui.js) =====
  function showToast(message, type = 'info') {
    if (typeof window.BoardApp.showToast === 'function') {
      window.BoardApp.showToast(message, type);
    }
  }

  // ===== EXPOSE FUNCTIONS =====
  window.BoardApp.dragDrop = {
    reinit: makeItemsDraggable,
    updateCounts: updateAllGroupCounts,
    persistDrop: saveItemPosition // (itemId, newGroupId, sourceGroupId) — used by touch-dnd.js
  };

  // ===== INITIALIZE ON LOAD =====
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDragDrop);
  } else {
    initDragDrop();
  }

  console.log('✅ Drag & Drop module loaded');

})();