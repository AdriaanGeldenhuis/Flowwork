/**
 * Flowwork Kanban View
 * Drag-and-drop cards, pivotable by Status / Priority / Person / Group
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  const GROUP_MODES = ['status', 'priority', 'person', 'group'];
  const PRIORITIES = [
    { key: 'critical', label: 'Critical', color: '#ef4444' },
    { key: 'high', label: 'High', color: '#f97316' },
    { key: 'medium', label: 'Medium', color: '#fdab3d' },
    { key: 'low', label: 'Low', color: '#10b981' }
  ];

  let groupByMode = null;

  function storageKey() {
    return 'fw-kanban-groupby-' + window.BOARD_DATA.boardId;
  }

  function getGroupBy() {
    if (!groupByMode) {
      try {
        groupByMode = localStorage.getItem(storageKey()) || 'status';
      } catch (e) {
        groupByMode = 'status';
      }
      if (!GROUP_MODES.includes(groupByMode)) groupByMode = 'status';
    }
    return groupByMode;
  }

  window.BoardApp.setKanbanGroupBy = function(mode) {
    if (!GROUP_MODES.includes(mode)) return;
    groupByMode = mode;
    try { localStorage.setItem(storageKey(), mode); } catch (e) { /* storage unavailable */ }
    window.BoardApp.renderKanban();
  };

  // ===== COLUMN BUILDER (per group-by mode) =====
  function buildColumns(mode, items) {
    const columns = [];
    const byKey = {};

    const addColumn = (key, label, color) => {
      const col = { key: String(key), label, color, items: [] };
      columns.push(col);
      byKey[String(key)] = col;
      return col;
    };

    if (mode === 'status') {
      Object.entries(window.BOARD_DATA.statusConfig || {}).forEach(([key, s]) => addColumn(key, s.label, s.color));
      addColumn('none', 'No Status', '#64748b');
      items.forEach(item => (byKey[item.status_label] || byKey['none']).items.push(item));
    } else if (mode === 'priority') {
      PRIORITIES.forEach(p => addColumn(p.key, p.label, p.color));
      addColumn('none', 'No Priority', '#64748b');
      items.forEach(item => (byKey[item.priority] || byKey['none']).items.push(item));
    } else if (mode === 'person') {
      (window.BOARD_DATA.users || []).forEach(u => addColumn(u.id, `${u.first_name} ${u.last_name}`, '#8b5cf6'));
      addColumn('none', 'Unassigned', '#64748b');
      items.forEach(item => (byKey[String(item.assigned_to)] || byKey['none']).items.push(item));
    } else { // group
      (window.BOARD_DATA.groups || []).forEach(g => addColumn(g.id, g.name, g.color || '#8b5cf6'));
      items.forEach(item => {
        const col = byKey[String(item.group_id)];
        if (col) col.items.push(item);
      });
    }

    return columns;
  }

  // ===== RENDER KANBAN VIEW =====
  window.BoardApp.renderKanban = function() {
    const container = document.getElementById('fw-kanban-view');
    if (!container) return;

    const allItems = window.BOARD_DATA.items || [];
    const items = window.BoardApp.getVisibleItems ? window.BoardApp.getVisibleItems() : allItems;
    const mode = getGroupBy();

    if (allItems.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="margin: 80px auto; max-width: 420px; text-align: center;">
          <div class="fw-empty-icon">📋</div>
          <div class="fw-empty-title">No items yet</div>
          <div class="fw-empty-text">Switch to Table view and add your first item — it will show up here as a card.</div>
        </div>
      `;
      return;
    }

    const columns = buildColumns(mode, items);

    // Group-by toolbar
    let html = `
      <div class="fw-kanban-toolbar">
        <label for="kanbanGroupBySelect" class="fw-kanban-toolbar__label">Group by</label>
        <select id="kanbanGroupBySelect" class="fw-select fw-kanban-toolbar__select" onchange="BoardApp.setKanbanGroupBy(this.value)">
          <option value="status" ${mode === 'status' ? 'selected' : ''}>Status</option>
          <option value="priority" ${mode === 'priority' ? 'selected' : ''}>Priority</option>
          <option value="person" ${mode === 'person' ? 'selected' : ''}>Person</option>
          <option value="group" ${mode === 'group' ? 'selected' : ''}>Group</option>
        </select>
        ${items.length !== allItems.length ? `<span class="fw-kanban-toolbar__filtered">Showing ${items.length} of ${allItems.length} items (filtered)</span>` : ''}
      </div>
    `;

    html += '<div class="fw-kanban-container">';

    columns.forEach(col => {
      // Legible header text for the column's background color (WCAG contrast).
      const hdrText = readableText(col.color);

      html += `
        <div class="fw-kanban-column" data-status="${escapeHtml(col.key)}">
          <div class="fw-kanban-column-header" style="background: ${escapeHtml(col.color)}; color: ${hdrText};">
            <span class="fw-kanban-column-title">${escapeHtml(col.label)}</span>
            <span class="fw-kanban-column-count">${col.items.length}</span>
          </div>
          <div class="fw-kanban-column-body" data-status="${escapeHtml(col.key)}">
      `;

      col.items.forEach(item => {
        const assignee = item.first_name
          ? `${item.first_name} ${item.last_name}`
          : 'Unassigned';

        const priority = item.priority || 'medium';
        const priorityEmoji = {
          critical: '🔴',
          high: '🟠',
          medium: '🟡',
          low: '🟢'
        }[priority] || '⚪';

        const dueDate = item.due_date
          ? new Date(item.due_date).toLocaleDateString()
          : '';

        html += `
          <div class="fw-kanban-card"
               data-item-id="${item.id}"
               data-status="${escapeHtml(col.key)}"
               draggable="true">
            <div class="fw-kanban-card-header">
              <span class="fw-kanban-card-priority">${priorityEmoji}</span>
              <button type="button" class="fw-kanban-card-menu" aria-label="Card actions" onclick="BoardApp.showItemMenu(${parseInt(item.id, 10)}, event)">⋮</button>
            </div>
            <div class="fw-kanban-card-title">${escapeHtml(item.title)}</div>
            <div class="fw-kanban-card-meta">
              <div class="fw-kanban-card-assignee">
                <div class="fw-avatar-xs">${escapeHtml(assignee.split(' ').map(n => n[0]).join(''))}</div>
                <span>${escapeHtml(assignee)}</span>
              </div>
              ${dueDate ? `<div class="fw-kanban-card-date">📅 ${escapeHtml(dueDate)}</div>` : ''}
            </div>
          </div>
        `;
      });

      html += `
            <button class="fw-kanban-add-card" onclick="BoardApp.addKanbanCard('${escapeHtml(col.key)}')">
              + Add card
            </button>
          </div>
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;

    // Initialize drag-and-drop
    initKanbanDragDrop();
  };

  // ===== DRAG AND DROP =====
  function initKanbanDragDrop() {
    const cards = document.querySelectorAll('.fw-kanban-card');
    const columns = document.querySelectorAll('.fw-kanban-column-body');

    cards.forEach(card => {
      card.addEventListener('dragstart', (e) => {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.itemId);
        card.classList.add('fw-dragging');
      });

      card.addEventListener('dragend', () => {
        card.classList.remove('fw-dragging');
      });
    });

    columns.forEach(col => {
      col.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        col.classList.add('fw-drag-over');
      });

      col.addEventListener('dragleave', () => {
        col.classList.remove('fw-drag-over');
      });

      col.addEventListener('drop', (e) => {
        e.preventDefault();
        col.classList.remove('fw-drag-over');

        const card = document.querySelector('.fw-dragging');
        if (!card) return;

        const itemId = parseInt(card.dataset.itemId);
        const newKey = col.dataset.status;
        const sourceKey = card.dataset.status;
        if (newKey === sourceKey) return;

        // Move card visually (optimistic); revert if the API call fails
        const sourceCol = card.parentElement;
        const addBtn = col.querySelector('.fw-kanban-add-card');
        if (addBtn) col.insertBefore(card, addBtn);
        else col.appendChild(card);
        card.dataset.status = newKey;

        persistCardMove(itemId, getGroupBy(), newKey, () => {
          card.dataset.status = sourceKey;
          if (sourceCol) sourceCol.appendChild(card);
          window.BoardApp.renderKanban();
        });
      });
    });
  }

  // ===== PERSIST A CARD MOVE (per group-by mode) =====
  function persistCardMove(itemId, mode, newKey, onError) {
    const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(itemId));
    const isNone = newKey === 'none';

    const done = (mutate) => {
      if (item) mutate(item);
      if (window.BoardApp.showToast) window.BoardApp.showToast('Card moved', 'success');

      // Sync the table view DOM so switching back stays consistent
      if (mode === 'group') {
        const row = document.querySelector(`tr.fw-item-row[data-item-id="${itemId}"]`);
        const tbody = document.querySelector(`.fw-group[data-group-id="${newKey}"] tbody`);
        if (row && tbody) {
          tbody.insertBefore(row, tbody.querySelector('.fw-agg-row'));
          row.dataset.groupId = String(newKey);
        }
      } else {
        const typeMap = { status: 'status', priority: 'priority', person: 'people' };
        const col = (window.BOARD_DATA.columns || []).find(c => c.type === typeMap[mode]);
        if (col && window.BoardApp.updateCellDOM) {
          window.BoardApp.updateCellDOM(itemId, col.column_id, isNone ? '' : newKey, typeMap[mode]);
        }
      }

      window.BoardApp.renderKanban();
    };
    const fail = (err) => {
      console.error('❌ Card move error:', err);
      if (typeof onError === 'function') onError(err);
      if (window.BoardApp.showToast) window.BoardApp.showToast('Failed to move card: ' + err.message, 'error');
      else alert('Failed to move card: ' + err.message);
    };

    if (mode === 'status') {
      window.BoardApp.apiCall('/projects/api/item.update.php', {
        item_id: itemId,
        status_label: isNone ? '' : newKey
      }).then(() => done(i => { i.status_label = isNone ? null : newKey; })).catch(fail);
    } else if (mode === 'priority') {
      window.BoardApp.apiCall('/projects/api/item.update.php', {
        item_id: itemId,
        priority: isNone ? '' : newKey
      }).then(() => done(i => { i.priority = isNone ? null : newKey; })).catch(fail);
    } else if (mode === 'person') {
      window.BoardApp.apiCall('/projects/api/item.update.php', {
        item_id: itemId,
        assigned_to: isNone ? '' : newKey
      }).then(() => done(i => {
        i.assigned_to = isNone ? null : newKey;
        const user = (window.BOARD_DATA.users || []).find(u => String(u.id) === String(newKey));
        i.first_name = user ? user.first_name : null;
        i.last_name = user ? user.last_name : null;
      })).catch(fail);
    } else { // group
      window.BoardApp.apiCall('/projects/api/item.move.php', {
        item_id: itemId,
        group_id: newKey
      }).then(() => done(i => { i.group_id = newKey; })).catch(fail);
    }
  }

  // Kept for compatibility with older callers: status-mode move
  window.BoardApp.updateItemStatus = function(itemId, newStatus, onError) {
    persistCardMove(itemId, 'status', newStatus, onError);
  };

  // Used by touch-dnd.js: persist a card move in whatever mode is active
  window.BoardApp.kanbanPersistMove = function(itemId, newKey, onError) {
    persistCardMove(itemId, getGroupBy(), newKey, onError);
  };

  // ===== ADD CARD =====
  window.BoardApp.addKanbanCard = function(colKey) {
    if (window.BoardApp.dialog) {
      window.BoardApp.dialog.prompt('Card title', {
        title: 'Add card',
        placeholder: 'What needs doing?',
        confirmLabel: 'Add'
      }).then(title => { if (title) createKanbanCard(colKey, title); });
    } else {
      const title = prompt('Card title:');
      if (title && title.trim()) createKanbanCard(colKey, title.trim());
    }
  };

  function createKanbanCard(colKey, title) {
    const mode = getGroupBy();
    const groups = window.BOARD_DATA.groups || [];
    if (groups.length === 0) {
      alert('Create a group first (Table view → Add Group)');
      return;
    }

    // In group mode the card is created straight into the target group;
    // in other modes it lands in the first group and then gets its bucket field set.
    const targetGroupId = mode === 'group' ? colKey : groups[0].id;

    window.BoardApp.apiCall('/projects/api/create_item.php', {
      board_id: window.BOARD_DATA.boardId,
      group_id: targetGroupId,
      title: title.trim()
    })
    .then(data => {
      const item = data.item;
      if (!item) throw new Error('Server did not return the new item');

      const isNone = colKey === 'none';
      let fieldUpdate = null;
      if (mode === 'status' && !isNone) fieldUpdate = { status_label: colKey };
      else if (mode === 'priority' && !isNone) fieldUpdate = { priority: colKey };
      else if (mode === 'person' && !isNone) fieldUpdate = { assigned_to: colKey };

      if (fieldUpdate) {
        return window.BoardApp.apiCall('/projects/api/item.update.php', {
          item_id: item.id,
          ...fieldUpdate
        }).then(() => {
          if (fieldUpdate.status_label) item.status_label = colKey;
          if (fieldUpdate.priority) item.priority = colKey;
          if (fieldUpdate.assigned_to) {
            item.assigned_to = colKey;
            const user = (window.BOARD_DATA.users || []).find(u => String(u.id) === String(colKey));
            if (user) { item.first_name = user.first_name; item.last_name = user.last_name; }
          }
          return item;
        });
      }
      return item;
    })
    .then(item => {
      // addItemToDOM inserts the table row AND pushes into BOARD_DATA.items
      if (window.BoardApp.addItemToDOM) {
        window.BoardApp.addItemToDOM(item, item.group_id || targetGroupId);
      } else {
        window.BOARD_DATA.items.push(item);
      }
      window.BoardApp.renderKanban();
      if (window.BoardApp.showToast) window.BoardApp.showToast('Card added', 'success');
    })
    .catch(err => {
      console.error('❌ Add card error:', err);
      alert('Failed to add card: ' + err.message);
    });
  };

  // Helpers
  function readableText(color) {
    const h = (color || '#8b5cf6').replace('#', '');
    const hh = h.length === 3 ? h.split('').map(x => x + x).join('') : h;
    const lum = hh.length >= 6
      ? (0.2126 * parseInt(hh.slice(0, 2), 16) + 0.7152 * parseInt(hh.slice(2, 4), 16) + 0.0722 * parseInt(hh.slice(4, 6), 16)) / 255
      : 0;
    return lum > 0.6 ? '#1a1a1a' : '#ffffff';
  }

  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  console.log('✅ Kanban module loaded');
})();
