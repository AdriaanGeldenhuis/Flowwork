/**
 * Group Drag & Drop Module
 * Reorder groups by dragging headers
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};
  
  let groupDragState = {
    draggedGroup: null,
    draggedElement: null,
    draggedGroupId: null
  };

  // ===== INIT GROUP DRAG & DROP =====
  function initGroupDragDrop() {
    console.log('🎯 Initializing group drag & drop...');
    
    makeGroupHeadersDraggable();
    setupGroupDragHandlers();
    
    console.log('✅ Group drag & drop initialized');
  }

  // ===== MAKE GROUP HEADERS DRAGGABLE =====
  function makeGroupHeadersDraggable() {
    document.querySelectorAll('.fw-group-header').forEach(header => {
      if (!header.hasAttribute('draggable')) {
        header.setAttribute('draggable', 'true');
        header.style.cursor = 'grab';
        
        // Add drag handle
        const toggle = header.querySelector('.fw-group-toggle');
        if (toggle) {
          toggle.style.cursor = 'grab';
        }
      }
    });
  }

  // ===== SETUP GROUP DRAG HANDLERS =====
  function setupGroupDragHandlers() {
    document.addEventListener('dragstart', (e) => {
      const header = e.target.closest('.fw-group-header');
      if (!header) return;
      
      const group = header.closest('.fw-group');
      if (!group) return;
      
      groupDragState.draggedElement = group;
      groupDragState.draggedGroupId = parseInt(group.dataset.groupId);
      
      group.classList.add('fw-dragging-group');
      group.style.opacity = '0.6';
      
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', groupDragState.draggedGroupId);
      
      console.log('🎯 Group drag started:', groupDragState.draggedGroupId);
    });

    document.addEventListener('dragover', (e) => {
      if (!groupDragState.draggedElement) return;
      
      const targetHeader = e.target.closest('.fw-group-header');
      if (!targetHeader) return;
      
      const targetGroup = targetHeader.closest('.fw-group');
      if (!targetGroup || targetGroup === groupDragState.draggedElement) return;
      
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      
      // Remove previous indicators
      document.querySelectorAll('.fw-group-drag-over-top, .fw-group-drag-over-bottom').forEach(el => {
        el.classList.remove('fw-group-drag-over-top', 'fw-group-drag-over-bottom');
      });
      
      // Add indicator
      const rect = targetGroup.getBoundingClientRect();
      const midpoint = rect.top + rect.height / 2;
      
      if (e.clientY < midpoint) {
        targetGroup.classList.add('fw-group-drag-over-top');
      } else {
        targetGroup.classList.add('fw-group-drag-over-bottom');
      }
    });

    document.addEventListener('drop', (e) => {
      e.preventDefault();
      
      if (!groupDragState.draggedElement) return;
      
      const targetHeader = e.target.closest('.fw-group-header');
      if (!targetHeader) return;
      
      const targetGroup = targetHeader.closest('.fw-group');
      if (!targetGroup || targetGroup === groupDragState.draggedElement) return;
      
      const targetGroupId = parseInt(targetGroup.dataset.groupId);
      const rect = targetGroup.getBoundingClientRect();
      const insertBefore = e.clientY < (rect.top + rect.height / 2);
      
      console.log('📍 Drop group:', {
        draggedGroupId: groupDragState.draggedGroupId,
        targetGroupId: targetGroupId,
        insertBefore: insertBefore
      });
      
      // Move group in DOM
      const container = groupDragState.draggedElement.parentNode;
      if (insertBefore) {
        container.insertBefore(groupDragState.draggedElement, targetGroup);
      } else {
        container.insertBefore(groupDragState.draggedElement, targetGroup.nextSibling);
      }
      
      // Save to server
      saveGroupOrder(groupDragState.draggedGroupId, targetGroupId, insertBefore);
      
      // Clean up
      document.querySelectorAll('.fw-group-drag-over-top, .fw-group-drag-over-bottom').forEach(el => {
        el.classList.remove('fw-group-drag-over-top', 'fw-group-drag-over-bottom');
      });
    });

    document.addEventListener('dragend', (e) => {
      if (!groupDragState.draggedElement) return;
      
      groupDragState.draggedElement.classList.remove('fw-dragging-group');
      groupDragState.draggedElement.style.opacity = '';
      
      document.querySelectorAll('.fw-group-drag-over-top, .fw-group-drag-over-bottom').forEach(el => {
        el.classList.remove('fw-group-drag-over-top', 'fw-group-drag-over-bottom');
      });
      
      groupDragState = {
        draggedGroup: null,
        draggedElement: null,
        draggedGroupId: null
      };
    });
  }

  // ===== SAVE GROUP ORDER =====
  function saveGroupOrder(draggedGroupId, targetGroupId, insertBefore) {
    const data = {
      group_id: draggedGroupId,
      target_group_id: targetGroupId,
      insert_before: insertBefore ? 1 : 0
    };
    
    window.BoardApp.apiCall('/projects/api/group/reorder.php', data)
      .then(response => {
        console.log('✅ Group order saved:', response);
        showToast('✅ Group reordered', 'success');
        
        // Update in memory
        const groups = window.BOARD_DATA.groups;
        const draggedIndex = groups.findIndex(g => g.id == draggedGroupId);
        const targetIndex = groups.findIndex(g => g.id == targetGroupId);
        
        if (draggedIndex !== -1 && targetIndex !== -1) {
          const [removed] = groups.splice(draggedIndex, 1);
          const newIndex = insertBefore ? targetIndex : targetIndex + 1;
          groups.splice(newIndex, 0, removed);
        }
      })
      .catch(err => {
        console.error('❌ Save group order failed:', err);
        showToast('❌ Failed to reorder group', 'error');
        setTimeout(() => window.location.reload(), 1500);
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
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  }

  // ===== EXPOSE FUNCTIONS =====
  window.BoardApp.groupDragDrop = {
    reinit: makeGroupHeadersDraggable
  };

  // ===== INITIALIZE =====
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initGroupDragDrop);
  } else {
    initGroupDragDrop();
  }

  console.log('✅ Group drag & drop module loaded');

})();