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
      
      html += `
        <div class="fw-kanban-column" data-status="${key}">
          <div class="fw-kanban-column-header" style="background: ${col.color};">
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
              <button class="fw-kanban-card-menu" onclick="BoardApp.showItemMenu(${item.id}, event)">⋮</button>
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

        // Move card visually
        col.appendChild(card);

        // Update in backend
        BoardApp.updateItemStatus(itemId, newStatus);
      });
    });
  }

  // ===== UPDATE ITEM STATUS =====
  window.BoardApp.updateItemStatus = function(itemId, newStatus) {
    const form = new FormData();
    form.append('item_id', itemId);
    form.append('status', newStatus);

    fetch('/projects/api/item.update.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      console.log('✅ Item status updated');
      
      // Update local data
      const item = window.BOARD_DATA.items.find(i => i.id === itemId);
      if (item) item.status_label = newStatus;
    })
    .catch(err => {
      console.error('❌ Update status error:', err);
      alert('Failed to update status: ' + err.message);
    });
  };

  // ===== ADD CARD =====
  window.BoardApp.addKanbanCard = function(status) {
    const title = prompt('Card title:');
    if (!title || !title.trim()) return;

    const form = new FormData();
    form.append('board_id', window.BOARD_DATA.boardId);
    form.append('title', title.trim());
    form.append('status', status);

    fetch('/projects/api/item.create.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      window.location.reload();
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