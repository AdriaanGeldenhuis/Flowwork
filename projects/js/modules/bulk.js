/**
 * Bulk Actions Module - WITH PROPER ERROR HANDLING
 */

window.BoardApp = window.BoardApp || {};

(function() {
  'use strict';

  let selectedItems = new Set();

  // Expose the selection read-only so other modules (shortcuts.js) can act on it
  Object.defineProperty(BoardApp, 'selectedItems', {
    get: () => selectedItems,
    configurable: true
  });

  // ===== TOGGLE ITEM SELECTION =====
  BoardApp.toggleItemSelection = function(itemId, checked) {
    if (checked) {
      selectedItems.add(itemId);
    } else {
      selectedItems.delete(itemId);
    }
    updateBulkBar();
  };

  // ===== TOGGLE GROUP SELECTION =====
  BoardApp.toggleGroupSelection = function(groupId, checked) {
    const groupEl = document.querySelector(`[data-group-id="${groupId}"]`);
    if (!groupEl) return;

    const checkboxes = groupEl.querySelectorAll('.fw-item-checkbox');
    checkboxes.forEach(cb => {
      cb.checked = checked;
      const itemId = parseInt(cb.dataset.itemId);
      if (checked) {
        selectedItems.add(itemId);
      } else {
        selectedItems.delete(itemId);
      }
    });

    updateBulkBar();
  };

  // ===== CLEAR SELECTION =====
  BoardApp.clearBulkSelection = function() {
    selectedItems.clear();
    document.querySelectorAll('.fw-item-checkbox').forEach(cb => cb.checked = false);
    document.querySelectorAll('.fw-checkbox').forEach(cb => cb.checked = false);
    updateBulkBar();
  };

  // Alias used by shortcuts.js
  BoardApp.clearSelection = BoardApp.clearBulkSelection;

  // ===== UPDATE BULK BAR =====
  function updateBulkBar() {
    const bar = document.getElementById('bulkActionBar');
    const count = document.getElementById('bulkCount');
    
    if (!bar || !count) return;

    if (selectedItems.size > 0) {
      bar.classList.add('active');
      count.textContent = `${selectedItems.size} selected`;
    } else {
      bar.classList.remove('active');
    }
  }

  // ===== HELPER: SAFE FETCH WITH ERROR HANDLING =====
  async function safeFetch(url, options) {
    try {
      const response = await fetch(url, options);
      
      // Check if response is OK
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      // Get response text first
      const text = await response.text();
      
      // Try to parse as JSON
      try {
        return JSON.parse(text);
      } catch (e) {
        console.error('❌ Invalid JSON response:', text);
        throw new Error('Server returned invalid JSON. Check console for details.');
      }
    } catch (error) {
      console.error('❌ Fetch error:', error);
      throw error;
    }
  }

  // ===== BULK UPDATE STATUS =====
  BoardApp.bulkUpdateStatus = function() {
    if (selectedItems.size === 0) {
      alert('❌ No items selected');
      return;
    }

    const statuses = window.BOARD_DATA.statusConfig || {
      'todo': { label: 'To Do', color: '#64748b' },
      'working': { label: 'Working', color: '#fdab3d' },
      'stuck': { label: 'Stuck', color: '#e2445c' },
      'done': { label: 'Done', color: '#00c875' }
    };

    const overlay = document.createElement('div');
    overlay.className = 'fw-modal-overlay';
    overlay.style.cssText = 'display: flex; align-items: center; justify-content: center;';
    
    const optionsHtml = Object.entries(statuses).map(([key, s]) => `
      <button class="fw-picker-option" data-value="${key}" style="cursor: pointer;">
        <span class="fw-status-badge" style="background: ${s.color}; color: white; padding: 6px 16px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase;">
          ${s.label}
        </span>
      </button>
    `).join('');

    overlay.innerHTML = `
      <div class="fw-modal-content" style="background: var(--modal-bg); border: 1px solid var(--modal-border); border-radius: 16px; padding: 24px; max-width: 400px; width: 90%;">
        <div class="fw-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
          <span style="font-size: 18px; font-weight: 700; color: var(--modal-text);">Select Status</span>
          <button class="fw-modal-close" style="width: 32px; height: 32px; border: none; background: var(--interactive-default); border-radius: 8px; color: var(--modal-text); font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center;">×</button>
        </div>
        <div class="fw-modal-body" style="color: var(--modal-text);">
          <div class="fw-picker-options" style="display: flex; flex-direction: column; gap: 8px;">
            ${optionsHtml}
          </div>
        </div>
      </div>
    `;

    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(overlay);

    overlay.querySelector('.fw-modal-close').addEventListener('click', () => overlay.remove());

    overlay.querySelectorAll('.fw-picker-option').forEach(btn => {
      btn.addEventListener('click', async () => {
        const status = btn.dataset.value;
        overlay.remove();

        try {
          const result = await safeFetch('/projects/api/bulk-update-status.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.BOARD_DATA.csrfToken
            },
            body: JSON.stringify({
              board_id: window.BOARD_DATA.boardId,
              item_ids: Array.from(selectedItems),
              status: status
            })
          });

          if (result.success) {
            // Patch each row's status cell in place — no reload
            const ids = Array.from(selectedItems);
            const col = (window.BOARD_DATA.columns || []).find(c => c.type === 'status');
            ids.forEach(id => {
              const item = window.BOARD_DATA.items.find(i => String(i.id) === String(id));
              if (item) item.status_label = status;
              if (col && window.BoardApp.updateCellDOM) {
                window.BoardApp.updateCellDOM(id, col.column_id, status, 'status');
              }
            });
            finishBulk(`Updated ${ids.length} item${ids.length > 1 ? 's' : ''}`);
          } else {
            alert('❌ Error: ' + (result.error || 'Unknown error'));
          }
        } catch (error) {
          alert('❌ Failed: ' + error.message);
        }
      });
    });

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.remove();
    });
  };

  // Common tail for optimistic bulk mutations
  function finishBulk(message) {
    BoardApp.clearBulkSelection();
    if (typeof BoardApp.showToast === 'function') {
      BoardApp.showToast(message, 'success');
    }
  }

  // ===== BULK ASSIGN =====
  BoardApp.bulkAssign = function() {
    if (selectedItems.size === 0) {
      alert('❌ No items selected');
      return;
    }

    const users = window.BOARD_DATA.users || [];

    const overlay = document.createElement('div');
    overlay.className = 'fw-modal-overlay';
    overlay.style.cssText = 'display: flex; align-items: center; justify-content: center;';
    
    const optionsHtml = users.map(u => `
      <button class="fw-picker-option" data-value="${u.id}" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--input-bg); border: 1px solid var(--input-border); border-radius: 8px; color: var(--modal-text); cursor: pointer; width: 100%; text-align: left;">
        <div class="fw-avatar-sm" style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700;">
          ${u.first_name.charAt(0)}${u.last_name.charAt(0)}
        </div>
        <span style="font-weight: 600;">${u.first_name} ${u.last_name}</span>
      </button>
    `).join('');

    overlay.innerHTML = `
      <div class="fw-modal-content" style="background: var(--modal-bg); border: 1px solid var(--modal-border); border-radius: 16px; padding: 24px; max-width: 400px; width: 90%; max-height: 80vh; overflow-y: auto;">
        <div class="fw-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
          <span style="font-size: 18px; font-weight: 700; color: var(--modal-text);">Assign To</span>
          <button class="fw-modal-close" style="width: 32px; height: 32px; border: none; background: var(--interactive-default); border-radius: 8px; color: var(--modal-text); font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center;">×</button>
        </div>
        <div class="fw-modal-body" style="color: var(--modal-text);">
          <div class="fw-picker-options" style="display: flex; flex-direction: column; gap: 8px;">
            ${optionsHtml}
          </div>
        </div>
      </div>
    `;

    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(overlay);

    overlay.querySelector('.fw-modal-close').addEventListener('click', () => overlay.remove());

    overlay.querySelectorAll('.fw-picker-option').forEach(btn => {
      btn.addEventListener('click', async () => {
        const userId = btn.dataset.value;
        overlay.remove();

        try {
          const result = await safeFetch('/projects/api/bulk-assign.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.BOARD_DATA.csrfToken
            },
            body: JSON.stringify({
              board_id: window.BOARD_DATA.boardId,
              item_ids: Array.from(selectedItems),
              user_id: parseInt(userId)
            })
          });

          if (result.success) {
            // Patch each row's people cell in place — no reload
            const ids = Array.from(selectedItems);
            const col = (window.BOARD_DATA.columns || []).find(c => c.type === 'people');
            const user = (window.BOARD_DATA.users || []).find(u => String(u.id) === String(userId));
            ids.forEach(id => {
              const item = window.BOARD_DATA.items.find(i => String(i.id) === String(id));
              if (item) {
                item.assigned_to = userId;
                item.first_name = user ? user.first_name : null;
                item.last_name = user ? user.last_name : null;
              }
              if (col && window.BoardApp.updateCellDOM) {
                window.BoardApp.updateCellDOM(id, col.column_id, userId, 'people');
              }
            });
            finishBulk(`Assigned ${ids.length} item${ids.length > 1 ? 's' : ''}`);
          } else {
            alert('❌ Error: ' + (result.error || 'Unknown error'));
          }
        } catch (error) {
          alert('❌ Failed: ' + error.message);
        }
      });
    });

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.remove();
    });
  };

  // ===== BULK MOVE =====
  BoardApp.bulkMove = function() {
    if (selectedItems.size === 0) {
      alert('❌ No items selected');
      return;
    }

    const groups = window.BOARD_DATA.groups || [];

    const overlay = document.createElement('div');
    overlay.className = 'fw-modal-overlay';
    overlay.style.cssText = 'display: flex; align-items: center; justify-content: center;';
    
    const optionsHtml = groups.map(g => `
      <button class="fw-picker-option" data-value="${g.id}" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--input-bg); border: 1px solid var(--input-border); border-radius: 8px; color: var(--modal-text); cursor: pointer; width: 100%; text-align: left;">
        <span style="color: ${g.color}; font-weight: 700; font-size: 14px;">${g.name}</span>
      </button>
    `).join('');

    overlay.innerHTML = `
      <div class="fw-modal-content" style="background: var(--modal-bg); border: 1px solid var(--modal-border); border-radius: 16px; padding: 24px; max-width: 400px; width: 90%;">
        <div class="fw-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
          <span style="font-size: 18px; font-weight: 700; color: var(--modal-text);">Move To Group</span>
          <button class="fw-modal-close" style="width: 32px; height: 32px; border: none; background: var(--interactive-default); border-radius: 8px; color: var(--modal-text); font-size: 20px; cursor: pointer; display: flex; align-items: center; justify-content: center;">×</button>
        </div>
        <div class="fw-modal-body" style="color: var(--modal-text);">
          <div class="fw-picker-options" style="display: flex; flex-direction: column; gap: 8px;">
            ${optionsHtml}
          </div>
        </div>
      </div>
    `;

    // ✅ FIX: Append to .fw-proj instead of body
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(overlay);

    overlay.querySelector('.fw-modal-close').addEventListener('click', () => overlay.remove());

    overlay.querySelectorAll('.fw-picker-option').forEach(btn => {
      btn.addEventListener('click', async () => {
        const groupId = btn.dataset.value;
        overlay.remove();

        try {
          const result = await safeFetch('/projects/api/bulk-move.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': window.BOARD_DATA.csrfToken
            },
            body: JSON.stringify({
              board_id: window.BOARD_DATA.boardId,
              item_ids: Array.from(selectedItems),
              group_id: parseInt(groupId)
            })
          });

          if (result.success) {
            // Move each row into the target group's tbody — no reload
            const ids = Array.from(selectedItems);
            const tbody = document.querySelector(`.fw-group[data-group-id="${groupId}"] tbody`);
            const sourceGroups = new Set();

            ids.forEach(id => {
              const item = window.BOARD_DATA.items.find(i => String(i.id) === String(id));
              if (item) {
                sourceGroups.add(String(item.group_id));
                item.group_id = groupId;
              }
              const row = document.querySelector(`tr.fw-item-row[data-item-id="${id}"]`);
              if (row && tbody) {
                tbody.querySelectorAll('.fw-empty-state').forEach(td => td.closest('tr')?.remove());
                tbody.insertBefore(row, tbody.querySelector('.fw-agg-row') || tbody.querySelector('.fw-add-row') || null);
                row.dataset.groupId = String(groupId);
              }
            });

            // Refresh counts + aggregations on every affected group
            sourceGroups.add(String(groupId));
            sourceGroups.forEach(gid => {
              const group = document.querySelector(`.fw-group[data-group-id="${gid}"]`);
              const countEl = group?.querySelector('.fw-group-count');
              if (countEl) countEl.textContent = group.querySelectorAll('tr.fw-item-row').length;
              if (window.BoardApp.updateAggregations) window.BoardApp.updateAggregations(gid);
            });
            if (window.BoardApp.updateBoardTotals) window.BoardApp.updateBoardTotals();

            finishBulk(`Moved ${ids.length} item${ids.length > 1 ? 's' : ''}`);
          } else {
            alert('❌ Error: ' + (result.error || 'Unknown error'));
          }
        } catch (error) {
          alert('❌ Failed: ' + error.message);
        }
      });
    });

    overlay.addEventListener('click', (e) => {
      if (e.target === overlay) overlay.remove();
    });
  };

  // ===== BULK DELETE (soft-archive with Undo) =====
  BoardApp.bulkDelete = async function() {
    if (selectedItems.size === 0) {
      if (typeof BoardApp.showToast === 'function') BoardApp.showToast('No items selected', 'warning');
      return;
    }

    const ids = Array.from(selectedItems);

    try {
      const result = await safeFetch('/projects/api/bulk-delete.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.BOARD_DATA.csrfToken
        },
        body: JSON.stringify({
          board_id: window.BOARD_DATA.boardId,
          item_ids: ids
        })
      });

      if (!result.success) {
        alert('❌ Error: ' + (result.error || 'Unknown error'));
        return;
      }

      // The server soft-archives, so deletion is reversible: hide the rows now,
      // finalize (or restore) when the Undo toast resolves.
      ids.forEach(id => {
        const row = document.querySelector(`tr.fw-item-row[data-item-id="${id}"]`);
        if (row) row.style.display = 'none';
      });
      BoardApp.clearBulkSelection();

      if (typeof BoardApp.showToast !== 'function') {
        location.reload();
        return;
      }

      BoardApp.showToast(`Deleted ${ids.length} item${ids.length > 1 ? 's' : ''}`, 'info', {
        duration: 8000,
        actionLabel: 'Undo',
        onAction: async () => {
          try {
            await Promise.all(ids.map(id => window.BoardApp.apiCall('/projects/api/item.update.php', {
              item_id: id,
              archived: 0
            })));
            ids.forEach(id => {
              const row = document.querySelector(`tr.fw-item-row[data-item-id="${id}"]`);
              if (row) row.style.display = '';
            });
            BoardApp.showToast('Items restored', 'success');
          } catch (err) {
            alert('Failed to restore items: ' + err.message);
          }
        },
        onExpire: () => {
          ids.forEach(id => {
            if (window.BoardApp.removeItemFromDOM) window.BoardApp.removeItemFromDOM(id);
          });
        }
      });
    } catch (error) {
      alert('❌ Failed: ' + error.message);
    }
  };

  console.log('✅ Bulk actions module loaded');
})();