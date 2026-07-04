/**
 * Flowwork Workload View
 * One lane per person showing open / overdue counts and their items.
 * Dragging a card between lanes reassigns it (item.update assigned_to).
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function isDone(item) {
    return item.status_label === 'done';
  }

  function isOverdue(item) {
    if (!item.due_date || isDone(item)) return false;
    const due = new Date(String(item.due_date).split(' ')[0]);
    const today = new Date(new Date().toDateString());
    return !isNaN(due) && due < today;
  }

  window.BoardApp.renderWorkload = function() {
    const container = document.getElementById('fw-workload-view');
    if (!container) return;

    const items = window.BoardApp.getVisibleItems
      ? window.BoardApp.getVisibleItems()
      : (window.BOARD_DATA.items || []);

    if ((window.BOARD_DATA.items || []).length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="margin: 80px auto; max-width: 420px; text-align: center;">
          <div class="fw-empty-icon">⚖️</div>
          <div class="fw-empty-title">No items yet</div>
          <div class="fw-empty-text">Add items and assign them to people to see everyone's workload here.</div>
        </div>
      `;
      return;
    }

    // Lanes: every board user + Unassigned
    const lanes = (window.BOARD_DATA.users || []).map(u => ({
      key: String(u.id),
      label: `${u.first_name} ${u.last_name}`,
      initials: `${(u.first_name || '?')[0]}${(u.last_name || '?')[0]}`.toUpperCase(),
      items: []
    }));
    lanes.push({ key: 'none', label: 'Unassigned', initials: '?', items: [] });
    const byKey = Object.fromEntries(lanes.map(l => [l.key, l]));

    items.forEach(item => {
      (byKey[String(item.assigned_to)] || byKey['none']).items.push(item);
    });

    let html = '<div class="fw-kanban-container fw-workload-container">';

    lanes.forEach(lane => {
      const open = lane.items.filter(i => !isDone(i)).length;
      const overdue = lane.items.filter(isOverdue).length;
      const heat = open >= 8 ? '#ef4444' : open >= 4 ? '#f59e0b' : '#10b981';

      html += `
        <div class="fw-kanban-column fw-workload-lane" data-person="${esc(lane.key)}">
          <div class="fw-kanban-column-header" style="background: var(--bg-elevated, #262630); color: var(--text-primary, #fff);">
            <span class="fw-workload-avatar" style="background:${heat};">${esc(lane.initials)}</span>
            <span class="fw-kanban-column-title">${esc(lane.label)}</span>
            <span class="fw-kanban-column-count" title="Open items">${open}</span>
            ${overdue > 0 ? `<span class="fw-workload-overdue" title="Overdue">⚠ ${overdue}</span>` : ''}
          </div>
          <div class="fw-workload-meter"><div class="fw-workload-meter-fill" style="width:${Math.min(100, open * 12.5)}%;background:${heat};"></div></div>
          <div class="fw-kanban-column-body fw-workload-body" data-person="${esc(lane.key)}">
      `;

      lane.items.forEach(item => {
        const overdueClass = isOverdue(item) ? ' fw-workload-card--overdue' : '';
        const dueDate = item.due_date ? new Date(item.due_date).toLocaleDateString() : '';
        const statusColor = window.BOARD_DATA.statusConfig[item.status_label]?.color || '#64748b';

        html += `
          <div class="fw-kanban-card fw-workload-card${overdueClass}"
               data-item-id="${item.id}"
               data-person="${esc(lane.key)}"
               draggable="true"
               style="border-left: 3px solid ${esc(statusColor)};">
            <div class="fw-kanban-card-title">${esc(item.title)}</div>
            <div class="fw-kanban-card-meta">
              ${dueDate ? `<div class="fw-kanban-card-date">📅 ${esc(dueDate)}</div>` : '<span></span>'}
              <button type="button" class="fw-kanban-card-menu" aria-label="Card actions" onclick="BoardApp.showItemMenu(${parseInt(item.id, 10)}, event)">⋮</button>
            </div>
          </div>
        `;
      });

      html += `
          </div>
        </div>
      `;
    });

    html += '</div>';
    container.innerHTML = html;

    initWorkloadDragDrop(container);
  };

  // ===== DRAG TO REASSIGN =====
  function initWorkloadDragDrop(container) {
    container.querySelectorAll('.fw-workload-card').forEach(card => {
      card.addEventListener('dragstart', (e) => {
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.itemId);
        card.classList.add('fw-dragging');
      });
      card.addEventListener('dragend', () => card.classList.remove('fw-dragging'));
    });

    container.querySelectorAll('.fw-workload-body').forEach(body => {
      body.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        body.classList.add('fw-drag-over');
      });
      body.addEventListener('dragleave', () => body.classList.remove('fw-drag-over'));
      body.addEventListener('drop', (e) => {
        e.preventDefault();
        body.classList.remove('fw-drag-over');

        const card = container.querySelector('.fw-workload-card.fw-dragging');
        if (!card) return;

        const itemId = card.dataset.itemId;
        const newKey = body.dataset.person;
        if (newKey === card.dataset.person) return;

        const isNone = newKey === 'none';
        window.BoardApp.apiCall('/projects/api/item.update.php', {
          item_id: itemId,
          assigned_to: isNone ? '' : newKey
        }).then(() => {
          const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(itemId));
          if (item) {
            item.assigned_to = isNone ? null : newKey;
            const user = (window.BOARD_DATA.users || []).find(u => String(u.id) === String(newKey));
            item.first_name = user ? user.first_name : null;
            item.last_name = user ? user.last_name : null;
          }

          // Sync the table's people cell
          const col = (window.BOARD_DATA.columns || []).find(c => c.type === 'people');
          if (col && window.BoardApp.updateCellDOM) {
            window.BoardApp.updateCellDOM(itemId, col.column_id, isNone ? '' : newKey, 'people');
          }

          if (window.BoardApp.showToast) window.BoardApp.showToast('Item reassigned', 'success');
          window.BoardApp.renderWorkload();
        }).catch(err => {
          if (window.BoardApp.showToast) window.BoardApp.showToast('Failed to reassign: ' + err.message, 'error');
          window.BoardApp.renderWorkload();
        });
      });
    });
  }

  console.log('✅ Workload module loaded');
})();
