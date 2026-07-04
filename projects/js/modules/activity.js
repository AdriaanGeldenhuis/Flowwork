/**
 * Activity Feed Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // Local escape helper — activity data is user-generated content
  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ===== SHOW ACTIVITY FEED (whole board or a single item) =====
  function showActivity(title, itemId) {
    let url = `/projects/api/activity/list.php?board_id=${window.BOARD_DATA.boardId}&limit=50`;
    if (itemId) url += `&item_id=${encodeURIComponent(itemId)}`;

    fetch(url, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);

      const activities = data.data.activities || [];

      const activitiesHtml = activities.length > 0 ? activities.map(a => {
        const icon = getActivityIcon(a.action);
        const description = getActivityDescription(a);
        const itemTitle = a.item_title || (a.details && a.details.title) || '';
        const userName = (a.first_name || a.last_name) ? `${a.first_name || ''} ${a.last_name || ''}`.trim() : 'Someone';

        return `
          <div class="fw-activity-item">
            <div class="fw-activity-icon">${icon}</div>
            <div class="fw-activity-content">
              <div class="fw-activity-header">
                <span class="fw-activity-user">${esc(userName)}</span>
                <span class="fw-activity-action">${description}</span>
              </div>
              ${itemTitle ? `<div class="fw-activity-item-title">${esc(itemTitle)}</div>` : ''}
              <div class="fw-activity-time">${esc(a.time_ago)}</div>
            </div>
          </div>
        `;
      }).join('') : '<div class="fw-empty-state"><div class="fw-empty-icon">📋</div><div class="fw-empty-title">No activity yet</div><div class="fw-empty-text">Changes to this board will show up here.</div></div>';

      createModal(title, `
        <div class="fw-activity-feed">
          ${activitiesHtml}
        </div>
      `);
    })
    .catch(err => {
      alert('Failed to load activity: ' + err.message);
    });
  }

  window.BoardApp.showActivityFeed = function() {
    showActivity('Activity Feed');
  };

  // Per-item history — wired to the row menu's "Activity Log" entry
  window.BoardApp.showItemHistory = function(itemId) {
    const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(itemId));
    showActivity(item ? `Activity — ${item.title}` : 'Item Activity', itemId);
  };

  // ===== GET ACTIVITY ICON =====
  function getActivityIcon(action) {
    const icons = {
      'item_created': '➕',
      'item_updated': '✏️',
      'item_deleted': '🗑️',
      'item_moved': '↔️',
      'status_changed': '🔄',
      'column_added': '📊',
      'column_deleted': '❌',
      'group_added': '📁',
      'group_deleted': '🗑️',
      'bulk_update': '⚡',
      'comment_added': '💬'
    };
    return icons[action] || '📌';
  }

  // ===== GET ACTIVITY DESCRIPTION =====
  function getActivityDescription(activity) {
    const { action, details } = activity;
    
    const d = details || {};

    switch (action) {
      case 'item_created':
        return 'created item';
      case 'item_updated':
        return 'updated item';
      case 'item_deleted':
        return 'deleted item';
      case 'item_moved':
        return 'moved item';
      case 'status_changed':
        return `changed status to <strong>${esc(d.new_status || 'unknown')}</strong>`;
      case 'column_added':
        return `added column <strong>${esc(d.name || 'unknown')}</strong>`;
      case 'group_added':
        return `added group <strong>${esc(d.name || 'unknown')}</strong>`;
      case 'bulk_update':
        return `updated ${parseInt(d.count, 10) || 0} items`;
      case 'comment_added':
        return 'added a comment';
      default:
        return esc(String(action).replace(/_/g, ' '));
    }
  }

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 600px; max-height: 80vh;">
        <div class="fw-modal-header">${esc(title)}</div>
        <div class="fw-modal-body" style="max-height: 60vh; overflow-y: auto;">
          ${content}
        </div>
      </div>
    `;
    
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    // ✅ FIX: Append to .fw-proj
    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(modal);
    return modal;
  }

  console.log('✅ Activity module loaded');

})();