/**
 * Real-Time Updates Module
 * Updates DOM without page reload - Enhanced with proper event handling
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== UPDATE CELL IN DOM =====
  // Rendering is delegated to the single canonical renderer in cells.js
  // (BoardApp.renderCellFull) so every column type gets its rich display.
  // This wrapper adds the change flash, cache update, and aggregation refresh.
  window.BoardApp.updateCellDOM = function(itemId, columnId, value, columnType) {
    const cell = document.querySelector(
      `.fw-cell[data-item-id="${itemId}"][data-column-id="${columnId}"]`
    );

    if (!cell) {
      console.warn('Cell not found in DOM:', itemId, columnId);
      return;
    }

    const oldValue = cell.dataset.value;

    if (typeof window.BoardApp.renderCellFull === 'function') {
      window.BoardApp.renderCellFull(itemId, columnId, value, columnType);
    } else {
      // Minimal safe fallback if cells.js failed to load
      cell.dataset.value = value || '';
      cell.textContent = value || '';
    }

    // Add flash animation only if value changed
    if (oldValue !== (value || '')) {
      cell.classList.add('fw-cell-updated');
      setTimeout(() => cell.classList.remove('fw-cell-updated'), 600);
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
      
      let formatted = result.toLocaleString('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision
      });

      const affix = config.affix || '';
      const affixPos = config.affixPosition === 'suffix' ? 'suffix' : 'prefix';
      if (affix) {
        const sep = '<span style="display:inline-block;width:0.25em;"></span>';
        formatted = affixPos === 'prefix' ? affix + sep + formatted : formatted + sep + affix;
      }

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
      
      let formatted = result.toLocaleString('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision
      });

      const affix = config.affix || '';
      const affixPos = config.affixPosition === 'suffix' ? 'suffix' : 'prefix';
      if (affix) {
        const sep = '<span style="display:inline-block;width:0.25em;"></span>';
        formatted = affixPos === 'prefix' ? affix + sep + formatted : formatted + sep + affix;
      }

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

  // ===== NEAR-REAL-TIME POLLING =====
  // Every ~20s pull board_audit_log events past our cursor and patch the DOM.
  // Paused while the tab is hidden or an editor/modal is open; own edits are
  // skipped (they were already applied optimistically).
  const POLL_INTERVAL_MS = 20000;
  let pollCursor = parseInt(window.BOARD_DATA.lastAuditId, 10) || 0;
  let pollTimer = null;
  let structuralBannerShown = false;

  function editorOpen() {
    return !!document.querySelector('.fw-modal-overlay, .fw-cell-picker-overlay[aria-hidden="false"], .fw-cell-inline-input');
  }

  function showUpdateBanner(text) {
    if (structuralBannerShown) return;
    structuralBannerShown = true;

    const banner = document.createElement('div');
    banner.setAttribute('role', 'status');
    banner.style.cssText = 'position:fixed;bottom:60px;left:50%;transform:translateX(-50%);' +
      'background:var(--modal-bg, #1e1e28);border:1px solid var(--modal-border, rgba(255,255,255,0.15));' +
      'color:var(--modal-text, #fff);padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;' +
      'z-index:10001;box-shadow:0 8px 24px rgba(0,0,0,0.35);display:flex;gap:12px;align-items:center;';
    banner.appendChild(Object.assign(document.createElement('span'), { textContent: text }));

    const btn = document.createElement('button');
    btn.textContent = 'Refresh';
    btn.className = 'fw-btn fw-btn--primary';
    btn.style.padding = '4px 12px';
    btn.addEventListener('click', () => location.reload());
    banner.appendChild(btn);

    (document.querySelector('.fw-proj') || document.body).appendChild(banner);
  }

  function applyEvent(event) {
    if (String(event.user_id) === String(window.BOARD_DATA.currentUserId)) return;

    const who = (event.first_name || event.last_name)
      ? `${event.first_name || ''} ${event.last_name || ''}`.trim()
      : 'A teammate';
    const d = event.details || {};

    switch (event.action) {
      case 'item_updated':
        if (event.item_id && d.column_id !== undefined) {
          const col = (window.BOARD_DATA.columns || []).find(c => String(c.column_id) === String(d.column_id));
          if (col) {
            window.BoardApp.updateCellDOM(event.item_id, d.column_id, d.value ?? '', col.type);
          }
        }
        break;

      case 'status_changed': {
        const col = (window.BOARD_DATA.columns || []).find(c => c.type === 'status');
        const value = d.new_status && d.new_status !== 'none' ? d.new_status : '';
        const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(event.item_id));
        if (item) item.status_label = value || null;
        if (col) window.BoardApp.updateCellDOM(event.item_id, col.column_id, value, 'status');
        break;
      }

      case 'item_moved': {
        const row = document.querySelector(`tr.fw-item-row[data-item-id="${event.item_id}"]`);
        const tbody = d.group_id ? document.querySelector(`.fw-group[data-group-id="${d.group_id}"] tbody`) : null;
        if (row && tbody) {
          tbody.querySelectorAll('.fw-empty-state').forEach(td => td.closest('tr')?.remove());
          tbody.insertBefore(row, tbody.querySelector('.fw-agg-row') || tbody.querySelector('.fw-add-row') || null);
          row.dataset.groupId = String(d.group_id);
          const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(event.item_id));
          if (item) item.group_id = d.group_id;
        }
        break;
      }

      case 'item_deleted':
        if (event.item_id && document.querySelector(`tr.fw-item-row[data-item-id="${event.item_id}"]`)) {
          window.BoardApp.removeItemFromDOM(event.item_id);
        }
        break;

      case 'comment_added':
        if (event.item_id && window.BoardApp.bumpCommentBadge) {
          window.BoardApp.bumpCommentBadge(event.item_id);
        }
        break;

      case 'item_created':
      case 'item_restored':
      case 'column_added':
      case 'group_added':
      case 'bulk_update':
        // Structural changes need fresh server-rendered markup
        showUpdateBanner(`${who} updated the board`);
        break;

      default:
        break;
    }
  }

  function pollChanges() {
    if (document.hidden || editorOpen()) return;

    fetch(`/projects/api/board.changes.php?board_id=${window.BOARD_DATA.boardId}&since=${pollCursor}`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) return;
      const payload = data.data || {};
      (payload.events || []).forEach(applyEvent);
      pollCursor = payload.last_id || pollCursor;
    })
    .catch(() => { /* transient network error — try again next tick */ });
  }

  function initPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(pollChanges, POLL_INTERVAL_MS);
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) pollChanges();
    });
  }

  initPolling();

  console.log('✅ Real-time updates module loaded');
})();