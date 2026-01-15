/**
 * Column Drag & Drop Module
 * Reorder columns by dragging headers
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};
  
  let columnDragState = {
    draggedColumn: null,
    draggedElement: null,
    draggedColumnId: null
  };

  // ===== INIT COLUMN DRAG & DROP =====
  function initColumnDragDrop() {
    console.log('🎯 Initializing column drag & drop...');
    
    makeColumnsHeadersDraggable();
    setupColumnDragHandlers();
    
    console.log('✅ Column drag & drop initialized');
  }

  // ===== MAKE COLUMN HEADERS DRAGGABLE =====
  function makeColumnsHeadersDraggable() {
    document.querySelectorAll('th[data-column-id]').forEach(th => {
      if (!th.hasAttribute('draggable')) {
        th.setAttribute('draggable', 'true');
        th.style.cursor = 'grab';
        
        // Add drag handle indicator
        const header = th.querySelector('.fw-col-header');
        if (header && !header.querySelector('.fw-drag-handle')) {
          const handle = document.createElement('span');
          handle.className = 'fw-drag-handle';
          handle.innerHTML = '⋮⋮';
          handle.style.cssText = `
            margin-right: 8px;
            color: var(--text-tertiary);
            cursor: grab;
            opacity: 0.5;
          `;
          header.insertBefore(handle, header.firstChild);
        }
      }
    });
  }

  // ===== SETUP COLUMN DRAG HANDLERS =====
  function setupColumnDragHandlers() {
    document.addEventListener('dragstart', (e) => {
      const th = e.target.closest('th[data-column-id]');
      if (!th || th.classList.contains('fw-col-checkbox') || th.classList.contains('fw-col-item') || th.classList.contains('fw-col-add')) {
        return;
      }
      
      columnDragState.draggedElement = th;
      columnDragState.draggedColumnId = parseInt(th.dataset.columnId);
      
      th.classList.add('fw-dragging-column');
      th.style.opacity = '0.5';
      
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', columnDragState.draggedColumnId);
      
      console.log('🎯 Column drag started:', columnDragState.draggedColumnId);
    });

    document.addEventListener('dragover', (e) => {
      if (!columnDragState.draggedElement) return;
      
      const targetTh = e.target.closest('th[data-column-id]');
      if (targetTh && targetTh !== columnDragState.draggedElement) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        
        // Remove previous indicators
        document.querySelectorAll('.fw-col-drag-over-left, .fw-col-drag-over-right').forEach(el => {
          el.classList.remove('fw-col-drag-over-left', 'fw-col-drag-over-right');
        });
        
        // Add indicator
        const rect = targetTh.getBoundingClientRect();
        const midpoint = rect.left + rect.width / 2;
        
        if (e.clientX < midpoint) {
          targetTh.classList.add('fw-col-drag-over-left');
        } else {
          targetTh.classList.add('fw-col-drag-over-right');
        }
      }
    });

    document.addEventListener('drop', (e) => {
      e.preventDefault();
      
      if (!columnDragState.draggedElement) return;
      
      const targetTh = e.target.closest('th[data-column-id]');
      if (!targetTh || targetTh === columnDragState.draggedElement) return;
      
      const targetColumnId = parseInt(targetTh.dataset.columnId);
      const rect = targetTh.getBoundingClientRect();
      const insertBefore = e.clientX < (rect.left + rect.width / 2);
      
      console.log('📍 Drop column:', {
        draggedColumnId: columnDragState.draggedColumnId,
        targetColumnId: targetColumnId,
        insertBefore: insertBefore
      });
      
      // Move column in DOM
      moveColumnInDOM(columnDragState.draggedElement, targetTh, insertBefore);
      
      // Save to server
      saveColumnOrder(columnDragState.draggedColumnId, targetColumnId, insertBefore);
      
      // Clean up
      document.querySelectorAll('.fw-col-drag-over-left, .fw-col-drag-over-right').forEach(el => {
        el.classList.remove('fw-col-drag-over-left', 'fw-col-drag-over-right');
      });
    });

    document.addEventListener('dragend', (e) => {
      if (!columnDragState.draggedElement) return;
      
      columnDragState.draggedElement.classList.remove('fw-dragging-column');
      columnDragState.draggedElement.style.opacity = '';
      
      document.querySelectorAll('.fw-col-drag-over-left, .fw-col-drag-over-right').forEach(el => {
        el.classList.remove('fw-col-drag-over-left', 'fw-col-drag-over-right');
      });
      
      columnDragState = {
        draggedColumn: null,
        draggedElement: null,
        draggedColumnId: null
      };
    });
  }

  // ===== MOVE COLUMN IN DOM =====
  function moveColumnInDOM(draggedTh, targetTh, insertBefore) {
    const draggedColumnId = draggedTh.dataset.columnId;
    
    // Get all table rows
    const tables = document.querySelectorAll('.fw-board-table');
    
    tables.forEach(table => {
      const headerRow = table.querySelector('thead tr');
      const bodyRows = table.querySelectorAll('tbody tr');
      
      // Move header
      const draggedHeader = headerRow.querySelector(`th[data-column-id="${draggedColumnId}"]`);
      const targetHeader = headerRow.querySelector(`th[data-column-id="${targetTh.dataset.columnId}"]`);
      
      if (draggedHeader && targetHeader) {
        if (insertBefore) {
          headerRow.insertBefore(draggedHeader, targetHeader);
        } else {
          headerRow.insertBefore(draggedHeader, targetHeader.nextSibling);
        }
      }
      
      // Move cells in each row
      bodyRows.forEach(row => {
        const draggedCell = row.querySelector(`td[data-column-id="${draggedColumnId}"]`);
        const targetCell = row.querySelector(`td[data-column-id="${targetTh.dataset.columnId}"]`);
        
        if (draggedCell && targetCell) {
          if (insertBefore) {
            row.insertBefore(draggedCell, targetCell);
          } else {
            row.insertBefore(draggedCell, targetCell.nextSibling);
          }
        }
      });
    });
  }

  // ===== SAVE COLUMN ORDER =====
  function saveColumnOrder(draggedColumnId, targetColumnId, insertBefore) {
    const data = {
      column_id: draggedColumnId,
      target_column_id: targetColumnId,
      insert_before: insertBefore ? 1 : 0
    };
    
    window.BoardApp.apiCall('/projects/api/column/reorder.php', data)
      .then(response => {
        console.log('✅ Column order saved:', response);
        showToast('✅ Column reordered', 'success');
        
        // Update in memory
        const columns = window.BOARD_DATA.columns;
        const draggedIndex = columns.findIndex(c => c.column_id == draggedColumnId);
        const targetIndex = columns.findIndex(c => c.column_id == targetColumnId);
        
        if (draggedIndex !== -1 && targetIndex !== -1) {
          const [removed] = columns.splice(draggedIndex, 1);
          const newIndex = insertBefore ? targetIndex : targetIndex + 1;
          columns.splice(newIndex, 0, removed);
        }
      })
      .catch(err => {
        console.error('❌ Save column order failed:', err);
        showToast('❌ Failed to reorder column', 'error');
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
  window.BoardApp.columnDragDrop = {
    reinit: makeColumnsHeadersDraggable
  };

  // ===== INITIALIZE =====
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initColumnDragDrop);
  } else {
    initColumnDragDrop();
  }

  console.log('✅ Column drag & drop module loaded');

})();