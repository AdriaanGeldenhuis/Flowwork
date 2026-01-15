/**
 * Activity Feed Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== SHOW ACTIVITY FEED =====
  window.BoardApp.showActivityFeed = function() {
    fetch(`/projects/api/activity/list.php?board_id=${window.BOARD_DATA.boardId}&limit=50`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      
      const activities = data.data.activities || [];
      
      const activitiesHtml = activities.length > 0 ? activities.map(a => {
        const icon = getActivityIcon(a.action);
        const description = getActivityDescription(a);
        
        return `
          <div class="fw-activity-item">
            <div class="fw-activity-icon">${icon}</div>
            <div class="fw-activity-content">
              <div class="fw-activity-header">
                <span class="fw-activity-user">${a.first_name} ${a.last_name}</span>
                <span class="fw-activity-action">${description}</span>
              </div>
              ${a.item_title ? `<div class="fw-activity-item-title">${a.item_title}</div>` : ''}
              <div class="fw-activity-time">${a.time_ago}</div>
            </div>
          </div>
        `;
      }).join('') : '<div class="fw-empty-state"><div class="fw-empty-icon">📋</div><div class="fw-empty-title">No activity yet</div></div>';
      
      const modal = createModal('Activity Feed', `
        <div class="fw-activity-feed">
          ${activitiesHtml}
        </div>
      `);
    })
    .catch(err => {
      alert('Failed to load activity: ' + err.message);
    });
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
    
    switch (action) {
      case 'item_created':
        return 'created item';
      case 'item_updated':
        return 'updated item';
      case 'item_deleted':
        return 'deleted item';
      case 'status_changed':
        return `changed status to <strong>${details.new_status || 'unknown'}</strong>`;
      case 'column_added':
        return `added column <strong>${details.name || 'unknown'}</strong>`;
      case 'group_added':
        return `added group <strong>${details.name || 'unknown'}</strong>`;
      case 'bulk_update':
        return `updated ${details.count || 0} items`;
      case 'comment_added':
        return 'added a comment';
      default:
        return action.replace(/_/g, ' ');
    }
  }

  // ===== HELPER: CREATE MODAL =====
  function createModal(title, content) {
    const modal = document.createElement('div');
    modal.className = 'fw-modal-overlay';
    modal.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 600px; max-height: 80vh;">
        <div class="fw-modal-header">${title}</div>
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