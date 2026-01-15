/**
 * Subitems Module
 * Handles nested tasks under items
 */

(() => {
  'use strict';

  if (!window.BoardApp) {
    console.error('❌ BoardApp not initialized');
    return;
  }

  /**
   * Show subitems modal for an item
   */
  window.BoardApp.showSubitems = async function(itemId) {
    try {
      console.log('📋 Loading subitems for item:', itemId);

      // Get item title
      const itemRow = document.querySelector(`[data-item-id="${itemId}"]`);
      const itemTitle = itemRow?.querySelector('.fw-item-title')?.value || 'Item';

      // Load subitems
      const response = await fetch(`/projects/api/subitem/list.php?item_id=${itemId}`, {
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error);

      const subitems = data.subitems || [];

      // Create modal
      const modal = document.createElement('div');
      modal.className = 'fw-cell-picker-overlay';
      modal.innerHTML = `
        <div class="fw-cell-picker" style="max-width: 800px; width: 90%;">
          <div class="fw-picker-header">
            <div style="display: flex; align-items: center; gap: 12px;">
              <svg width="20" height="20" fill="currentColor">
                <path d="M4 6h12M4 10h12M4 14h12" stroke="currentColor" stroke-width="2"/>
              </svg>
              <div>
                <div style="font-size: 16px; font-weight: 600;">Subitems</div>
                <div style="font-size: 13px; opacity: 0.7; margin-top: 2px;">${itemTitle}</div>
              </div>
            </div>
            <button class="fw-picker-close" onclick="this.closest('.fw-cell-picker-overlay').remove()">&times;</button>
          </div>
          
          <div class="fw-picker-body" style="padding: 20px; max-height: 500px; overflow-y: auto;">
            <div id="subitemsList">
              ${subitems.length === 0 ? `
                <div style="text-align: center; padding: 40px; color: var(--text-tertiary);">
                  <div style="font-size: 48px; margin-bottom: 12px;">📝</div>
                  <div style="font-size: 14px;">No subitems yet</div>
                </div>
              ` : subitems.map(s => renderSubitemRow(s)).join('')}
            </div>
            
            <!-- Add New Subitem -->
            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border);">
              <div style="display: flex; gap: 12px;">
                <input type="text" 
                       id="newSubitemInput" 
                       class="fw-input" 
                       placeholder="+ Add subitem" 
                       style="flex: 1;"
                       onkeydown="if(event.key==='Enter') BoardApp.createSubitem(${itemId}, this.value, this)" />
                <button class="fw-btn fw-btn--primary" 
                        onclick="BoardApp.createSubitem(${itemId}, document.getElementById('newSubitemInput').value, document.getElementById('newSubitemInput'))">
                  Add
                </button>
              </div>
            </div>
          </div>
        </div>
      `;

      document.body.appendChild(modal);

      // Close on outside click
      modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
      });

      // Focus input
      setTimeout(() => {
        document.getElementById('newSubitemInput')?.focus();
      }, 100);

    } catch (err) {
      console.error('❌ Load subitems error:', err);
      alert('Failed to load subitems: ' + err.message);
    }
  };

  /**
   * Render a subitem row
   */
  function renderSubitemRow(subitem) {
    const isCompleted = subitem.completed == 1;
    const assigneeInitials = subitem.first_name && subitem.last_name 
      ? `${subitem.first_name[0]}${subitem.last_name[0]}` 
      : '?';

    return `
      <div class="fw-subitem-row" data-subitem-id="${subitem.id}" style="
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: var(--interactive-default);
        border-radius: 8px;
        margin-bottom: 8px;
        border-left: 3px solid ${isCompleted ? '#10b981' : '#64748b'};
      ">
        <!-- Checkbox -->
        <input type="checkbox" 
               class="fw-checkbox" 
               ${isCompleted ? 'checked' : ''}
               onchange="BoardApp.toggleSubitemComplete(${subitem.id}, this.checked)"
               style="width: 18px; height: 18px; cursor: pointer;" />
        
        <!-- Title -->
        <input type="text" 
               class="fw-input" 
               value="${subitem.title}"
               onblur="BoardApp.updateSubitemTitle(${subitem.id}, this.value)"
               style="flex: 1; ${isCompleted ? 'text-decoration: line-through; opacity: 0.6;' : ''}" />
        
        <!-- Assignee -->
        <div class="fw-avatar-sm" 
             title="${subitem.first_name || 'Unassigned'}"
             onclick="BoardApp.showSubitemAssignee(${subitem.id})"
             style="cursor: pointer; background: linear-gradient(135deg, #6366f1, #8b5cf6);">
          ${assigneeInitials}
        </div>
        
        <!-- Delete -->
        <button class="fw-icon-btn" 
                onclick="BoardApp.deleteSubitem(${subitem.id})"
                title="Delete subitem"
                style="color: var(--text-tertiary); opacity: 0.5; transition: opacity 0.2s;"
                onmouseover="this.style.opacity='1'"
                onmouseout="this.style.opacity='0.5'">
          <svg width="16" height="16" fill="currentColor">
            <path d="M3 6h10M5 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m1 0v8a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6h8z"/>
          </svg>
        </button>
      </div>
    `;
  }

  /**
   * Create a new subitem
   */
  window.BoardApp.createSubitem = async function(itemId, title, inputElement) {
    const trimmedTitle = title.trim();

    if (!trimmedTitle) {
      alert('Subitem title is required');
      return;
    }

    try {
      const form = new FormData();
      form.append('item_id', itemId);
      form.append('title', trimmedTitle);

      const response = await fetch('/projects/api/subitem/create.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error);

      console.log('✅ Subitem created:', data.subitem_id);

      // Clear input
      if (inputElement) inputElement.value = '';

      // Reload subitems modal
      document.querySelector('.fw-cell-picker-overlay')?.remove();
      window.BoardApp.showSubitems(itemId);

      // Update item row subitem count
      updateSubitemCount(itemId);

    } catch (err) {
      console.error('❌ Create subitem error:', err);
      alert('Failed to create subitem: ' + err.message);
    }
  };

  /**
   * Toggle subitem completion
   */
  window.BoardApp.toggleSubitemComplete = async function(subitemId, completed) {
    try {
      const form = new FormData();
      form.append('subitem_id', subitemId);
      form.append('completed', completed ? 1 : 0);

      const response = await fetch('/projects/api/subitem/update.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error);

      console.log('✅ Subitem toggled:', subitemId, completed);

      // Update UI
      const row = document.querySelector(`[data-subitem-id="${subitemId}"]`);
      if (row) {
        const input = row.querySelector('input[type="text"]');
        if (input) {
          if (completed) {
            input.style.textDecoration = 'line-through';
            input.style.opacity = '0.6';
            row.style.borderLeftColor = '#10b981';
          } else {
            input.style.textDecoration = 'none';
            input.style.opacity = '1';
            row.style.borderLeftColor = '#64748b';
          }
        }
      }

    } catch (err) {
      console.error('❌ Toggle subitem error:', err);
      alert('Failed to update subitem: ' + err.message);
    }
  };

  /**
   * Update subitem title
   */
  window.BoardApp.updateSubitemTitle = async function(subitemId, title) {
    const trimmedTitle = title.trim();

    if (!trimmedTitle) {
      alert('Subitem title cannot be empty');
      return;
    }

    try {
      const form = new FormData();
      form.append('subitem_id', subitemId);
      form.append('title', trimmedTitle);

      const response = await fetch('/projects/api/subitem/update.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error);

      console.log('✅ Subitem title updated:', subitemId);

    } catch (err) {
      console.error('❌ Update subitem error:', err);
      alert('Failed to update subitem: ' + err.message);
    }
  };

  /**
   * Delete subitem
   */
  window.BoardApp.deleteSubitem = async function(subitemId) {
    if (!confirm('Delete this subitem?')) return;

    try {
      const form = new FormData();
      form.append('subitem_id', subitemId);

      const response = await fetch('/projects/api/subitem/delete.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error);

      console.log('✅ Subitem deleted:', subitemId);

      // Remove from UI
      const row = document.querySelector(`[data-subitem-id="${subitemId}"]`);
      if (row) {
        row.style.opacity = '0';
        row.style.transform = 'translateX(-20px)';
        setTimeout(() => row.remove(), 300);
      }

      // Update count
      const itemId = row?.closest('[data-item-id]')?.dataset.itemId;
      if (itemId) updateSubitemCount(itemId);

    } catch (err) {
      console.error('❌ Delete subitem error:', err);
      alert('Failed to delete subitem: ' + err.message);
    }
  };

  /**
   * Show assignee picker for subitem
   */
  window.BoardApp.showSubitemAssignee = async function(subitemId) {
    const users = window.BOARD_DATA.users || [];

    if (users.length === 0) {
      alert('No users available');
      return;
    }

    const html = `
      <div class="fw-picker-header">Assign To</div>
      <div class="fw-picker-options">
        <button class="fw-picker-option" onclick="BoardApp.assignSubitem(${subitemId}, null)">
          <span style="width:32px;height:32px;border-radius:50%;background:#64748b;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">?</span>
          Unassigned
        </button>
        ${users.map(u => `
          <button class="fw-picker-option" onclick="BoardApp.assignSubitem(${subitemId}, ${u.id})">
            <span style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;color:white;font-weight:700;">${u.first_name[0]}${u.last_name[0]}</span>
            ${u.first_name} ${u.last_name}
          </button>
        `).join('')}
      </div>
    `;

    const picker = document.createElement('div');
    picker.className = 'fw-cell-picker-overlay';
    picker.innerHTML = `<div class="fw-cell-picker" style="max-width: 400px;">${html}</div>`;
    document.body.appendChild(picker);

    picker.addEventListener('click', (e) => {
      if (e.target === picker) picker.remove();
    });
  };

  /**
   * Assign subitem to user
   */
  window.BoardApp.assignSubitem = async function(subitemId, userId) {
    try {
      const form = new FormData();
      form.append('subitem_id', subitemId);
      form.append('assigned_to', userId || '');

      const response = await fetch('/projects/api/subitem/update.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
        body: form
      });

      const data = await response.json();
      if (!data.ok) throw new Error(data.error);

      console.log('✅ Subitem assigned:', subitemId, userId);

      // Close picker and reload subitems
      document.querySelectorAll('.fw-cell-picker-overlay').forEach(el => el.remove());

      // Find parent item ID
      const subitemRow = document.querySelector(`[data-subitem-id="${subitemId}"]`);
      const modal = subitemRow?.closest('.fw-cell-picker-overlay');
      const itemId = modal?.querySelector('[data-item-id]')?.dataset.itemId;

      if (itemId) {
        window.BoardApp.showSubitems(itemId);
      }

    } catch (err) {
      console.error('❌ Assign subitem error:', err);
      alert('Failed to assign subitem: ' + err.message);
    }
  };

  /**
   * Update subitem count badge on item row
   */
  function updateSubitemCount(itemId) {
    fetch(`/projects/api/subitem/list.php?item_id=${itemId}`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        const count = data.subitems.length;
        const row = document.querySelector(`[data-item-id="${itemId}"]`);
        
        if (row) {
          let badge = row.querySelector('.fw-subitem-badge');
          
          if (count > 0) {
            if (!badge) {
              badge = document.createElement('span');
              badge.className = 'fw-subitem-badge';
              badge.style.cssText = `
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 2px 8px;
                background: linear-gradient(135deg, #6366f1, #8b5cf6);
                color: white;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                margin-left: 8px;
                cursor: pointer;
              `;
              badge.onclick = () => window.BoardApp.showSubitems(itemId);
              
              const titleInput = row.querySelector('.fw-item-title');
              if (titleInput) {
                titleInput.parentNode.insertBefore(badge, titleInput.nextSibling);
              }
            }
            badge.innerHTML = `
              <svg width="10" height="10" fill="currentColor">
                <path d="M2 3h6M2 5h6M2 7h6"/>
              </svg>
              ${count}
            `;
          } else if (badge) {
            badge.remove();
          }
        }
      }
    })
    .catch(err => console.error('Update count error:', err));
  }

  console.log('✅ Subitems module loaded');

})();