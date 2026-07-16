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
    draggedColumnId: null,
    overTh: null,
    before: null
  };

  // ===== INIT COLUMN DRAG & DROP =====
  function initColumnDragDrop() {
    console.log('🎯 Initializing column drag & drop...');
    
    makeColumnsHeadersDraggable();
    setupColumnDragHandlers();
    
    console.log('✅ Column drag & drop initialized');
  }

  // ===== MAKE COLUMN HEADERS DRAGGABLE (via a dedicated handle) =====
  // Only the ⋮⋮ handle starts a reorder — the <th> itself is NOT draggable, so
  // the rename input stays selectable and the resize handle keeps working.
  function makeColumnsHeadersDraggable() {
    document.querySelectorAll('th[data-column-id]').forEach(th => {
      const header = th.querySelector('.fw-col-header');
      if (header && !header.querySelector('.fw-drag-handle')) {
        const handle = document.createElement('span');
        handle.className = 'fw-drag-handle';
        handle.setAttribute('aria-label', 'Drag to reorder column');
        handle.setAttribute('title', 'Drag to reorder');
        handle.innerHTML = '⋮⋮';
        handle.style.cssText = `
          margin-right: 6px;
          padding: 4px 2px;
          color: var(--text-tertiary);
          cursor: grab;
          opacity: 0.5;
          touch-action: none;
          user-select: none;
        `;
        header.insertBefore(handle, header.firstChild);
      }
    });
  }

  const REORDERABLE = (th) => th
    && !th.classList.contains('fw-col-checkbox')
    && !th.classList.contains('fw-col-item')
    && !th.classList.contains('fw-col-add');

  function clearIndicators() {
    document.querySelectorAll('.fw-col-drag-over-left, .fw-col-drag-over-right')
      .forEach(el => el.classList.remove('fw-col-drag-over-left', 'fw-col-drag-over-right'));
  }

  // The header th under the given viewport point, excluding the dragged one and
  // the fixed (checkbox/item/add) columns.
  function targetThAt(x, y) {
    const el = document.elementFromPoint(x, y);
    const th = el && el.closest ? el.closest('th[data-column-id]') : null;
    if (!th || th === columnDragState.draggedElement || !REORDERABLE(th)) return null;
    return th;
  }

  // ===== SETUP COLUMN REORDER (Pointer Events: mouse + touch + pen) =====
  function setupColumnDragHandlers() {
    document.addEventListener('pointerdown', (e) => {
      const handle = e.target.closest('.fw-drag-handle');
      if (!handle) return;
      const th = handle.closest('th[data-column-id]');
      if (!REORDERABLE(th)) return;

      e.preventDefault();
      columnDragState.draggedElement = th;
      columnDragState.draggedColumnId = parseInt(th.dataset.columnId);
      columnDragState.overTh = null;
      columnDragState.before = null;

      th.classList.add('fw-dragging-column');
      th.style.opacity = '0.5';
      th.style.pointerEvents = 'none'; // so elementFromPoint returns the column underneath
      document.body.style.userSelect = 'none';
      handle.style.cursor = 'grabbing';
      try { handle.setPointerCapture(e.pointerId); } catch (_) {}
      console.log('🎯 Column reorder started:', columnDragState.draggedColumnId);
    });

    document.addEventListener('pointermove', (e) => {
      if (!columnDragState.draggedElement) return;
      e.preventDefault();
      clearIndicators();

      const th = targetThAt(e.clientX, e.clientY);
      if (!th) { columnDragState.overTh = null; return; }

      const rect = th.getBoundingClientRect();
      const before = e.clientX < rect.left + rect.width / 2;
      th.classList.add(before ? 'fw-col-drag-over-left' : 'fw-col-drag-over-right');
      columnDragState.overTh = th;
      columnDragState.before = before;
    });

    const finish = (e) => {
      if (!columnDragState.draggedElement) return;

      const dragged = columnDragState.draggedElement;
      const draggedColumnId = columnDragState.draggedColumnId;
      let targetTh = columnDragState.overTh;
      let before = columnDragState.before;

      // Reset visual state before the (possibly re-rendering) move.
      dragged.classList.remove('fw-dragging-column');
      dragged.style.opacity = '';
      dragged.style.pointerEvents = '';
      document.body.style.userSelect = '';
      document.querySelectorAll('.fw-drag-handle').forEach(h => h.style.cursor = 'grab');
      clearIndicators();

      // If pointermove didn't land on a target (e.g. quick touch flick), resolve
      // the drop target from the release point.
      if (!targetTh) {
        targetTh = targetThAt(e.clientX, e.clientY);
        if (targetTh) {
          const rect = targetTh.getBoundingClientRect();
          before = e.clientX < rect.left + rect.width / 2;
        }
      }

      columnDragState = { draggedColumn: null, draggedElement: null, draggedColumnId: null, overTh: null, before: null };

      if (!targetTh || targetTh.dataset.columnId == draggedColumnId) return;

      const targetColumnId = parseInt(targetTh.dataset.columnId);
      moveColumnInDOM(dragged, targetTh, before);
      saveColumnOrder(draggedColumnId, targetColumnId, before);
    };

    document.addEventListener('pointerup', finish);
    document.addEventListener('pointercancel', finish);
  }

  // ===== MOVE COLUMN IN DOM =====
  function moveColumnInDOM(draggedTh, targetTh, insertBefore) {
    const draggedColumnId = draggedTh.dataset.columnId;
    
    // Get all table rows
    const tables = document.querySelectorAll('.fw-board-table');
    
    tables.forEach(table => {
      const headerRow = table.querySelector('thead tr');
      const bodyRows = table.querySelectorAll('tbody tr');

      // Move the <col> too — column widths are positional, so leaving the
      // colgroup untouched shifts every width one column over after a drag
      const colgroup = table.querySelector('colgroup');
      if (colgroup) {
        const draggedCol = colgroup.querySelector(`col[data-column-id="${draggedColumnId}"]`);
        const targetCol = colgroup.querySelector(`col[data-column-id="${targetTh.dataset.columnId}"]`);
        if (draggedCol && targetCol) {
          colgroup.insertBefore(draggedCol, insertBefore ? targetCol : targetCol.nextSibling);
        }
      }

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
        showToast('Column reordered', 'success');
        
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
        showToast('Failed to reorder column', 'error');
        setTimeout(() => window.location.reload(), 1500);
      });
  }

  // ===== SHOW TOAST =====
  function showToast(message, type = 'info') {
    if (typeof window.BoardApp.showToast === 'function') {
      window.BoardApp.showToast(message, type);
    }
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