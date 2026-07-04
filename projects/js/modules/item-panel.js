/**
 * Item Detail Side Panel
 * One place to see and edit everything about an item: title, every column
 * value (opens the standard cell editors), comments and per-item activity.
 * Subitems keep their dedicated modal (openable from here).
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  let openItemId = null;

  function esc(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function getPanel() {
    let panel = document.getElementById('fw-item-panel');
    if (!panel) {
      panel = document.createElement('aside');
      panel.id = 'fw-item-panel';
      panel.className = 'fw-item-panel';
      panel.setAttribute('role', 'dialog');
      panel.setAttribute('aria-label', 'Item details');
      (document.querySelector('.fw-proj') || document.body).appendChild(panel);
    }
    return panel;
  }

  window.BoardApp.openItemPanel = function(itemId) {
    const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(itemId));
    if (!item) return;

    openItemId = itemId;
    renderPanel(item);
    getPanel().classList.add('fw-item-panel--open');
    setTimeout(() => document.getElementById('fwPanelTitle')?.focus(), 50);
  };

  window.BoardApp.closeItemPanel = function() {
    const closingId = openItemId;
    openItemId = null;
    getPanel().classList.remove('fw-item-panel--open');
    // Hand focus back to the row so keyboard users keep their place
    if (closingId) {
      document.querySelector(`tr.fw-item-row[data-item-id="${closingId}"] .fw-item-title`)?.focus();
    }
  };

  function renderPanel(item) {
    const panel = getPanel();
    const columns = window.BOARD_DATA.columns || [];

    const cellsHtml = columns
      .filter(c => Number(c.visible) === 1)
      .map(col => {
        const colId = parseInt(col.column_id, 10);
        // Mirror the already-rendered table cell so pickers read the same state
        const tableCell = document.querySelector(`td.fw-cell[data-item-id="${item.id}"][data-column-id="${colId}"]`);
        const valueHtml = tableCell ? tableCell.innerHTML : '<button class="fw-cell-empty">+</button>';
        const dataValue = tableCell ? (tableCell.dataset.value || '') : '';

        return `
          <div class="fw-panel-row">
            <div class="fw-panel-row__label">${esc(col.name)}</div>
            <div class="fw-panel-row__value fw-cell"
                 data-type="${esc(col.type)}"
                 data-item-id="${parseInt(item.id, 10)}"
                 data-column-id="${colId}"
                 data-value="${esc(dataValue)}"
                 onclick="BoardApp.editCell(${parseInt(item.id, 10)}, ${colId}, '${esc(col.type)}', event)">
              ${valueHtml}
            </div>
          </div>
        `;
      }).join('');

    panel.innerHTML = `
      <div class="fw-panel-header">
        <input type="text" id="fwPanelTitle" class="fw-panel-title" value="${esc(item.title)}"
               aria-label="Item title"
               onblur="BoardApp.panelUpdateTitle(${parseInt(item.id, 10)}, this.value)" />
        <button type="button" class="fw-panel-close" aria-label="Close panel" onclick="BoardApp.closeItemPanel()">×</button>
      </div>

      <div class="fw-panel-tabs" role="tablist">
        <button type="button" class="fw-panel-tab fw-panel-tab--active" data-tab="details" onclick="BoardApp.panelSwitchTab('details')">Details</button>
        <button type="button" class="fw-panel-tab" data-tab="comments" onclick="BoardApp.panelSwitchTab('comments')">Comments</button>
        <button type="button" class="fw-panel-tab" data-tab="activity" onclick="BoardApp.panelSwitchTab('activity')">Activity</button>
      </div>

      <div class="fw-panel-body">
        <div class="fw-panel-pane" data-pane="details">
          ${cellsHtml}
          <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="fw-btn fw-btn--secondary" onclick="BoardApp.showSubitems(${parseInt(item.id, 10)})">☑️ Subitems</button>
            <button type="button" class="fw-btn fw-btn--secondary" onclick="BoardApp.duplicateItem(${parseInt(item.id, 10)})">📋 Duplicate</button>
            <button type="button" class="fw-btn fw-btn--secondary" onclick="BoardApp.closeItemPanel(); BoardApp.deleteItem(${parseInt(item.id, 10)})">🗑️ Delete</button>
          </div>
        </div>

        <div class="fw-panel-pane" data-pane="comments" style="display:none;">
          <div id="fwPanelComments"><div class="fw-empty-text" style="padding:16px;">Loading…</div></div>
          <div class="fw-panel-comment-box">
            <textarea id="fwPanelCommentInput" class="fw-textarea" rows="2" placeholder="Add a comment… use @name to mention"></textarea>
            <button type="button" class="fw-btn fw-btn--primary" onclick="BoardApp.panelPostComment(${parseInt(item.id, 10)})">Post</button>
          </div>
        </div>

        <div class="fw-panel-pane" data-pane="activity" style="display:none;">
          <div id="fwPanelActivity"><div class="fw-empty-text" style="padding:16px;">Loading…</div></div>
        </div>
      </div>
    `;
  }

  window.BoardApp.panelSwitchTab = function(tab) {
    const panel = getPanel();
    panel.querySelectorAll('.fw-panel-tab').forEach(btn => {
      btn.classList.toggle('fw-panel-tab--active', btn.dataset.tab === tab);
    });
    panel.querySelectorAll('.fw-panel-pane').forEach(pane => {
      pane.style.display = pane.dataset.pane === tab ? '' : 'none';
    });

    if (tab === 'comments') loadComments();
    if (tab === 'activity') loadActivity();
  };

  window.BoardApp.panelUpdateTitle = function(itemId, newTitle) {
    const title = newTitle.trim();
    if (!title) return;
    window.BoardApp.updateItemTitle(itemId, title);
    // Sync the table row + local cache
    const rowInput = document.querySelector(`tr.fw-item-row[data-item-id="${itemId}"] .fw-item-title`);
    if (rowInput) rowInput.value = title;
    const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(itemId));
    if (item) item.title = title;
  };

  function loadComments() {
    if (!openItemId) return;
    const target = document.getElementById('fwPanelComments');
    if (!target) return;

    fetch(`/projects/api/comment/list.php?item_id=${openItemId}`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      const comments = data.data.comments || [];

      target.innerHTML = comments.length ? comments.map(c => `
        <div class="fw-comment">
          <div class="fw-comment__avatar">${esc((c.first_name || '?').charAt(0) + (c.last_name || '?').charAt(0))}</div>
          <div class="fw-comment__content">
            <div class="fw-comment__header">
              <span class="fw-comment__author">${esc((c.first_name || '') + ' ' + (c.last_name || ''))}</span>
              <span class="fw-comment__time">${esc(c.time_ago)}</span>
            </div>
            <div class="fw-comment__text">${esc(c.comment).replace(/@(\w+)/g, '<span class="fw-mention">@$1</span>')}</div>
          </div>
        </div>
      `).join('') : '<div class="fw-empty-text" style="padding:16px;">No comments yet — start the conversation.</div>';
    })
    .catch(err => {
      target.innerHTML = `<div class="fw-empty-text" style="padding:16px;">Failed to load comments: ${esc(err.message)}</div>`;
    });
  }

  window.BoardApp.panelPostComment = function(itemId) {
    const input = document.getElementById('fwPanelCommentInput');
    if (!input) return;
    const comment = input.value.trim();
    if (!comment) return;

    input.disabled = true;
    window.BoardApp.apiCall('/projects/api/comment/add.php', {
      item_id: itemId,
      comment: comment
    }).then(() => {
      input.value = '';
      input.disabled = false;
      loadComments();
      if (window.BoardApp.showToast) window.BoardApp.showToast('Comment posted', 'success');
    }).catch(err => {
      input.disabled = false;
      alert('Failed to post comment: ' + err.message);
    });
  };

  function loadActivity() {
    if (!openItemId) return;
    const target = document.getElementById('fwPanelActivity');
    if (!target) return;

    fetch(`/projects/api/activity/list.php?board_id=${window.BOARD_DATA.boardId}&item_id=${openItemId}&limit=30`, {
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      const activities = data.data.activities || [];

      target.innerHTML = activities.length ? activities.map(a => {
        const who = (a.first_name || a.last_name) ? `${a.first_name || ''} ${a.last_name || ''}`.trim() : 'Someone';
        return `
          <div class="fw-activity-item">
            <div class="fw-activity-content">
              <div class="fw-activity-header">
                <span class="fw-activity-user">${esc(who)}</span>
                <span class="fw-activity-action">${esc(String(a.action).replace(/_/g, ' '))}</span>
              </div>
              <div class="fw-activity-time">${esc(a.time_ago)}</div>
            </div>
          </div>
        `;
      }).join('') : '<div class="fw-empty-text" style="padding:16px;">No activity for this item yet.</div>';
    })
    .catch(err => {
      target.innerHTML = `<div class="fw-empty-text" style="padding:16px;">Failed to load activity: ${esc(err.message)}</div>`;
    });
  }

  // Keep the open panel in sync when a cell is saved anywhere
  document.addEventListener('cellUpdated', (e) => {
    if (openItemId && String(e.detail?.itemId) === String(openItemId)) {
      const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(openItemId));
      if (item && getPanel().classList.contains('fw-item-panel--open')) {
        const activeTab = getPanel().querySelector('.fw-panel-tab--active')?.dataset.tab || 'details';
        renderPanel(item);
        window.BoardApp.panelSwitchTab(activeTab);
      }
    }
  });

  // Escape closes the panel (when no modal is open on top of it)
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && openItemId && !document.querySelector('.fw-modal-overlay')) {
      window.BoardApp.closeItemPanel();
    }
  });

  console.log('✅ Item panel module loaded');
})();
