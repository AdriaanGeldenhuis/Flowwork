/**
 * Flowwork Kanban View
 * Drag-and-drop cards across status columns
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== RENDER KANBAN VIEW =====
  window.BoardApp.renderKanban = function() {
    const container = document.getElementById('fw-kanban-view');
    if (!container) return;

    const items = window.BOARD_DATA.items;
    const statusConfig = window.BOARD_DATA.statusConfig;

    if (!items || items.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="margin: 80px auto; max-width: 420px; text-align: center;">
          <div class="fw-empty-icon">📋</div>
          <div class="fw-empty-title">No items yet</div>
          <div class="fw-empty-text">Switch to Table view and add your first item — it will show up here as a card.</div>
        </div>
      `;
      return;
    }

    // Group items by status
    const columns = {};
    Object.keys(statusConfig).forEach(key => {
      columns[key] = {
        label: statusConfig[key].label,
        color: statusConfig[key].color,
        items: []
      };
    });

    // Add ungrouped column for items without status
    columns['none'] = {
      label: 'No Status',
      color: '#64748b',
      items: []
    };

    items.forEach(item => {
      const status = item.status_label || 'none';
      if (columns[status]) {
        columns[status].items.push(item);
      } else {
        columns['none'].items.push(item);
      }
    });

    // Render HTML
    let html = '<div class="fw-kanban-container">';

    Object.keys(columns).forEach(key => {
      const col = columns[key];

      // Legible header text for the column's background color (WCAG contrast).
      const _h = (col.color || '#8b5cf6').replace('#', '');
      const _hh = _h.length === 3 ? _h.split('').map(x => x + x).join('') : _h;
      const _lum = _hh.length >= 6
        ? (0.2126 * parseInt(_hh.slice(0, 2), 16) + 0.7152 * parseInt(_hh.slice(2, 4), 16) + 0.0722 * parseInt(_hh.slice(4, 6), 16)) / 255
        : 0;
      const hdrText = _lum > 0.6 ? '#1a1a1a' : '#ffffff';

      html += `
        <div class="fw-kanban-column" data-status="${key}">
          <div class="fw-kanban-column-header" style="background: ${col.color}; color: ${hdrText};">
            <span class="fw-kanban-column-title">${col.label}</span>
            <span class="fw-kanban-column-count">${col.items.length}</span>
          </div>
          <div class="fw-kanban-column-body" data-status="${key}">
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
               data-status="${key}"
               draggable="true">
            <div class="fw-kanban-card-header">
              <span class="fw-kanban-card-priority">${priorityEmoji}</span>
              <button type="button" class="fw-kanban-card-menu" aria-label="Card actions" onclick="BoardApp.showItemMenu(${item.id}, event)">⋮</button>
            </div>
            <div class="fw-kanban-card-title">${escapeHtml(item.title)}</div>
            <div class="fw-kanban-card-meta">
              <div class="fw-kanban-card-assignee">
                <div class="fw-avatar-xs">${assignee.split(' ').map(n => n[0]).join('')}</div>
                <span>${assignee}</span>
              </div>
              ${dueDate ? `<div class="fw-kanban-card-date">📅 ${dueDate}</div>` : ''}
            </div>
          </div>
        `;
      });

      html += `
            <button class="fw-kanban-add-card" onclick="BoardApp.addKanbanCard('${key}')">
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

    console.log('✅ Kanban view rendered');
  };

  // ===== DRAG AND DROP =====
  function initKanbanDragDrop() {
    const cards = document.querySelectorAll('.fw-kanban-card');
    const columns = document.querySelectorAll('.fw-kanban-column-body');

    cards.forEach(card => {
      card.addEventListener('dragstart', (e) => {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', card.innerHTML);
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
        const newStatus = col.dataset.status;
        const sourceStatus = card.dataset.status;
        if (newStatus === sourceStatus) return;

        // Move card visually (optimistic); revert if the API call fails
        const sourceCol = card.parentElement;
        col.appendChild(card);
        card.dataset.status = newStatus;

        BoardApp.updateItemStatus(itemId, newStatus, () => {
          card.dataset.status = sourceStatus;
          if (sourceCol) sourceCol.appendChild(card);
        });
      });
    });
  }

  // ===== UPDATE ITEM STATUS =====
  // The API field is `status_label` (item.update.php ignores `status`); sending
  // '' clears the status for cards dropped on the "No Status" column.
  window.BoardApp.updateItemStatus = function(itemId, newStatus, onError) {
    const form = new FormData();
    form.append('item_id', itemId);
    form.append('status_label', newStatus === 'none' ? '' : newStatus);

    fetch('/projects/api/item.update.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);

      // Update local data (ids may be strings from PHP)
      const item = window.BOARD_DATA.items.find(i => String(i.id) === String(itemId));
      if (item) item.status_label = newStatus === 'none' ? null : newStatus;

      if (window.BoardApp.showToast) window.BoardApp.showToast('Status updated', 'success');
    })
    .catch(err => {
      console.error('❌ Update status error:', err);
      if (typeof onError === 'function') onError(err);
      if (window.BoardApp.showToast) window.BoardApp.showToast('Failed to update status: ' + err.message, 'error');
      else alert('Failed to update status: ' + err.message);
    });
  };

  // ===== ADD CARD =====
  window.BoardApp.addKanbanCard = function(status) {
    const title = prompt('Card title:');
    if (!title || !title.trim()) return;

    const groups = window.BOARD_DATA.groups || [];
    if (groups.length === 0) {
      alert('Create a group first (Table view → Add Group)');
      return;
    }

    // Items always belong to a group; new kanban cards land in the first group
    window.BoardApp.apiCall('/projects/api/create_item.php', {
      board_id: window.BOARD_DATA.boardId,
      group_id: groups[0].id,
      title: title.trim()
    })
    .then(data => {
      const item = data.item;
      if (!item) throw new Error('Server did not return the new item');

      if (status && status !== 'none') {
        // Set the card's column status, then reflect it locally
        return window.BoardApp.apiCall('/projects/api/item.update.php', {
          item_id: item.id,
          status_label: status
        }).then(() => {
          item.status_label = status;
          return item;
        });
      }
      return item;
    })
    .then(item => {
      // addItemToDOM inserts the table row AND pushes into BOARD_DATA.items
      if (window.BoardApp.addItemToDOM) {
        window.BoardApp.addItemToDOM(item, groups[0].id);
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

  // Helper
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  console.log('✅ Kanban module loaded');
})();