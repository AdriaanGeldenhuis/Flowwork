/**
 * Flowwork Board - Main Controller
 * MUST LOAD FIRST - Initializes window.BoardApp
 *
 * Theme is handled by ui/header.js (cookie 'fw_theme', shared app-wide).
 * The board menu is initialized by the inline script in board.php + header.js.
 */

// ===== INITIALIZE BoardApp IMMEDIATELY =====
window.BoardApp = window.BoardApp || {};
window.BoardApp.currentView = 'table';
window.BoardApp.searchQuery = '';

const FW_VIEWS = ['table', 'kanban', 'calendar', 'gantt'];

// ===== FALLBACK: ADD COLUMN (overridden by modules/columns.js) =====
window.BoardApp.showAddColumnModal = window.BoardApp.showAddColumnModal || function() {
  if (typeof window.BoardApp._showAddColumnModal === 'function') {
    return window.BoardApp._showAddColumnModal();
  }

  const types = [
    { value: 'text', label: '📝 Text' },
    { value: 'number', label: '🔢 Number' },
    { value: 'status', label: '🔄 Status' },
    { value: 'people', label: '👤 People' },
    { value: 'date', label: '📅 Date' },
    { value: 'priority', label: '⚡ Priority' },
    { value: 'supplier', label: '🏢 Supplier' },
    { value: 'dropdown', label: '▼ Dropdown' },
    { value: 'formula', label: '∑ Formula' }
  ];

  const html = types.map(t =>
    `<button class="fw-dropdown-item" onclick="BoardApp.addColumn('${t.value}'); this.closest('.fw-dropdown').remove();">${t.label}</button>`
  ).join('');

  const menu = document.createElement('div');
  menu.className = 'fw-dropdown';
  menu.innerHTML = html;
  menu.style.cssText = 'position:fixed;right:20px;top:180px;z-index:9999;max-height:400px;overflow-y:auto;';

  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(menu);

  setTimeout(() => {
    document.addEventListener('click', () => menu.remove(), { once: true });
  }, 100);
};

window.BoardApp.addColumn = window.BoardApp.addColumn || function(type) {
  const name = prompt(`New ${type} column name:`);
  if (!name) return;

  const form = new FormData();
  form.append('board_id', window.BOARD_DATA.boardId);
  form.append('name', name);
  form.append('type', type);

  fetch('/projects/api/column.create.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    body: form
  }).then(r => r.json()).then(data => {
    if (!data.ok) throw new Error(data.error);
    window.location.reload();
  }).catch(err => {
    console.error('Add column error:', err);
    alert('Failed to add column: ' + err.message);
  });
};

// ===== VIEW SWITCHING =====
window.BoardApp.switchView = function(viewName) {
  if (!FW_VIEWS.includes(viewName)) viewName = 'table';

  window.BoardApp.currentView = viewName;

  const containers = {
    table: document.querySelector('.fw-board-container'),
    kanban: document.getElementById('fw-kanban-view'),
    calendar: document.getElementById('fw-calendar-view'),
    gantt: document.getElementById('fw-gantt-view')
  };

  Object.values(containers).forEach(el => {
    if (el) el.style.display = 'none';
  });

  const selected = containers[viewName];
  if (!selected) return;

  selected.style.display = viewName === 'kanban' ? 'flex' : 'block';

  try {
    if (viewName === 'kanban') {
      if (typeof BoardApp.renderKanban === 'function') BoardApp.renderKanban();
      else showViewError(selected, 'Kanban module not loaded');
    } else if (viewName === 'calendar') {
      if (typeof BoardApp.renderCalendar === 'function') BoardApp.renderCalendar();
      else showViewError(selected, 'Calendar module not loaded');
    } else if (viewName === 'gantt') {
      if (typeof BoardApp.renderGantt === 'function') BoardApp.renderGantt();
      else showViewError(selected, 'Gantt module not loaded');
    }
  } catch (err) {
    console.error('View render error:', err);
    showViewError(selected, err.message);
  }

  // Active button state (visual + a11y)
  document.querySelectorAll('.fw-view-btn').forEach(btn => {
    const isActive = btn.dataset.view === viewName;
    btn.classList.toggle('fw-view-btn--active', isActive);
    btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
  });

  // Keep the URL shareable and remember the choice per board
  try {
    const url = new URL(window.location.href);
    url.searchParams.set('view', viewName);
    history.replaceState(null, '', url.toString());
    localStorage.setItem('fw-board-view-' + window.BOARD_DATA.boardId, viewName);
  } catch (e) { /* URL/storage unavailable — non-fatal */ }

  if (window.fwAnnounce) window.fwAnnounce(viewName.charAt(0).toUpperCase() + viewName.slice(1) + ' view');
};

function showViewError(container, message) {
  container.innerHTML = `
    <div class="fw-empty-state" style="margin-top: 100px;">
      <div class="fw-empty-icon">⚠️</div>
      <div class="fw-empty-title">View Error</div>
      <div class="fw-empty-text">${window.BoardApp.escapeHtml ? window.BoardApp.escapeHtml(message) : ''}</div>
      <button class="fw-btn fw-btn--primary" onclick="location.reload()" style="margin-top: 16px;">
        Reload Page
      </button>
    </div>
  `;
}

// ===== INITIAL VIEW =====
// Precedence: ?view= URL param > last-used view (localStorage) > board default_view > table.
// Runs under a readyState guard because this script is injected by the module
// loader and may execute after DOMContentLoaded has already fired.
function fwInitBoard() {
  let view = null;

  const params = new URLSearchParams(window.location.search);
  const urlView = params.get('view');
  if (urlView && FW_VIEWS.includes(urlView)) view = urlView;

  if (!view) {
    try {
      const saved = localStorage.getItem('fw-board-view-' + window.BOARD_DATA.boardId);
      if (saved && FW_VIEWS.includes(saved)) view = saved;
    } catch (e) { /* storage unavailable */ }
  }

  if (!view) {
    const def = window.BOARD_DATA.defaultView;
    view = FW_VIEWS.includes(def) ? def : 'table';
  }

  window.BoardApp.switchView(view);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', fwInitBoard);
} else {
  fwInitBoard();
}

// ===== BOARD TITLE =====
window.BoardApp.updateBoardTitle = function(newTitle) {
  if (!newTitle.trim()) return;

  const form = new FormData();
  form.append('board_id', window.BOARD_DATA.boardId);
  form.append('title', newTitle.trim());

  fetch('/projects/api/board.update.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    body: form
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error);
  })
  .catch(err => {
    console.error('Update title error:', err);
    alert('Failed to update title: ' + err.message);
  });
};

// ===== SEARCH =====
let fwSearchTimer = null;

window.BoardApp.onSearchInput = function(value) {
  clearTimeout(fwSearchTimer);
  fwSearchTimer = setTimeout(() => fwApplySearch(value), 150);
};

function fwApplySearch(value) {
  const query = (value || '').toLowerCase().trim();
  window.BoardApp.searchQuery = query;

  const clearBtn = document.getElementById('boardSearchClear');
  if (clearBtn) clearBtn.style.display = query ? '' : 'none';

  let visibleCount = 0;

  document.querySelectorAll('.fw-item-row').forEach(row => {
    const title = row.querySelector('.fw-item-title')?.value.toLowerCase() || '';
    const isMatch = !query || title.includes(query);
    row.style.display = isMatch ? '' : 'none';
    if (isMatch) visibleCount++;
  });

  // Hide groups whose rows are all hidden (skip the totals block)
  document.querySelectorAll('.fw-group').forEach(group => {
    if (group.classList.contains('fw-board-totals-group')) return;
    const hasVisible = !query || !!group.querySelector('.fw-item-row:not([style*="display: none"])');
    group.style.display = hasVisible ? '' : 'none';
  });

  // No-results state
  let empty = document.getElementById('fwSearchEmpty');
  if (query && visibleCount === 0) {
    if (!empty) {
      empty = document.createElement('div');
      empty.id = 'fwSearchEmpty';
      empty.className = 'fw-empty-state';
      empty.style.padding = '60px 20px';
      const boardContainer = document.querySelector('.fw-board-container');
      if (boardContainer) boardContainer.prepend(empty);
    }
    const esc = window.BoardApp.escapeHtml || (s => s);
    empty.innerHTML = `
      <div class="fw-empty-icon">🔍</div>
      <div class="fw-empty-title">No items match "${esc(value.trim())}"</div>
      <div class="fw-empty-text">Try a different search term</div>
      <button class="fw-btn fw-btn--secondary" style="margin-top:12px;" onclick="BoardApp.clearSearch()">Clear search</button>
    `;
  } else if (empty) {
    empty.remove();
  }

  // Other views re-render from the shared filtered item set
  if (window.BoardApp.currentView !== 'table') {
    window.BoardApp.switchView(window.BoardApp.currentView);
  }

  if (window.fwAnnounce && query) window.fwAnnounce(`${visibleCount} items match`);
}

window.BoardApp.clearSearch = function() {
  const input = document.getElementById('boardSearchInput');
  if (input) input.value = '';
  clearTimeout(fwSearchTimer);
  fwApplySearch('');
  input?.focus();
};

// ===== FALLBACK MODALS (overridden by filters.js / views.js when loaded) =====
window.BoardApp.showFilterModal = window.BoardApp.showFilterModal || function() {
  alert('Filters are unavailable — the filters module failed to load. Refresh the page.');
};

window.BoardApp.showViewsModal = window.BoardApp.showViewsModal || function() {
  alert('Views are unavailable — the views module failed to load. Refresh the page.');
};

window.BoardApp.exportBoard = function() {
  window.location.href = `/projects/api/board.export.php?board_id=${window.BOARD_DATA.boardId}`;
};

window.BoardApp.showImportModal = window.BoardApp.showImportModal || function() {
  if (window.BoardApp.showToast) window.BoardApp.showToast('Import is coming soon', 'info');
  else alert('Import modal coming soon!');
};

window.BoardApp.showBoardSettings = window.BoardApp.showBoardSettings || function() {
  if (window.BoardApp.showToast) window.BoardApp.showToast('Board settings are coming soon', 'info');
  else alert('Board settings coming soon!');
};

// ===== UTILITY: DROPDOWNS (canonical versions live in modules/ui.js) =====
window.BoardApp.closeAllDropdowns = window.BoardApp.closeAllDropdowns || function() {
  document.querySelectorAll('.fw-dropdown').forEach(m => m.remove());
};

window.BoardApp.showDropdown = window.BoardApp.showDropdown || function(target, html) {
  window.BoardApp.closeAllDropdowns();

  const menu = document.createElement('div');
  menu.className = 'fw-dropdown';
  menu.innerHTML = html;
  menu.style.position = 'fixed';

  const rect = target.getBoundingClientRect();
  let left = rect.left - 200;
  let top = rect.bottom + 8;

  if (left < 20) left = rect.left;
  if (top + 300 > window.innerHeight) top = rect.top - 320;
  if (top < 20) top = 20;

  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
  menu.style.zIndex = '9999';

  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(menu);

  setTimeout(() => {
    const closeHandler = (e) => {
      if (!menu.contains(e.target) && e.target !== target) {
        menu.remove();
        document.removeEventListener('click', closeHandler);
      }
    };
    document.addEventListener('click', closeHandler);
  }, 100);
};

// ===== BOARD MEMBERS =====
window.BoardApp.showBoardMembers = function() {
  const esc = window.BoardApp.escapeHtml || (s => s);

  const html = `
    <div class="fw-modal-header">
      <h2>Board Members</h2>
      <button class="fw-modal-close" onclick="this.closest('.fw-modal-overlay').remove()">×</button>
    </div>
    <div class="fw-modal-body">
      <div class="fw-form-group">
        <label>Add Member</label>
        <select id="addMemberSelect" class="fw-select">
          <option value="">Select user...</option>
          ${window.BOARD_DATA.users.map(u =>
            `<option value="${u.id}">${esc(u.first_name + ' ' + u.last_name)}</option>`
          ).join('')}
        </select>
      </div>
      <div class="fw-form-group">
        <label>Role</label>
        <select id="memberRoleSelect" class="fw-select">
          <option value="viewer">Viewer</option>
          <option value="member">Member</option>
          <option value="manager">Manager</option>
          <option value="owner">Owner</option>
        </select>
      </div>
      <button class="fw-btn fw-btn--primary" onclick="BoardApp.addBoardMember()">Add Member</button>
      <hr style="margin: 24px 0; border: 0; border-top: 1px solid rgba(255,255,255,0.1);">
      <h3 style="margin-bottom: 16px;">Current Members</h3>
      <div id="currentMembersList">Loading...</div>
    </div>
  `;

  const overlay = document.createElement('div');
  overlay.className = 'fw-modal-overlay';
  overlay.innerHTML = `<div class="fw-modal-content">${html}</div>`;

  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(overlay);

  BoardApp.loadBoardMembers();
};

window.BoardApp.loadBoardMembers = function() {
  const esc = window.BoardApp.escapeHtml || (s => s);

  fetch(`/projects/api/board.members.php?board_id=${window.BOARD_DATA.boardId}`, {
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken }
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error);

    const list = document.getElementById('currentMembersList');
    if (!list) return;

    if (!data.members || data.members.length === 0) {
      list.innerHTML = '<p style="color: var(--text-muted);">No members yet</p>';
      return;
    }

    list.innerHTML = data.members.map(m => `
      <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px; background: var(--interactive-default); border-radius: 8px; margin-bottom: 8px;">
        <div style="display: flex; align-items: center; gap: 12px;">
          <div class="fw-avatar-sm">${esc((m.first_name || '?')[0] + (m.last_name || '?')[0])}</div>
          <div>
            <div style="font-weight: 600; color: var(--text-primary);">${esc(m.first_name + ' ' + m.last_name)}</div>
            <div style="font-size: 13px; color: var(--text-muted);">${esc(m.email)}</div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
          <span style="padding: 4px 12px; background: rgba(139, 92, 246, 0.2); color: var(--accent-primary); border-radius: 12px; font-size: 12px; font-weight: 600;">${esc(m.role)}</span>
          <button class="fw-btn fw-btn--text" onclick="BoardApp.removeBoardMember(${parseInt(m.user_id, 10)})" title="Remove">×</button>
        </div>
      </div>
    `).join('');
  })
  .catch(err => {
    console.error('Load members error:', err);
    const list = document.getElementById('currentMembersList');
    if (list) list.innerHTML = '<p style="color: #ef4444;">Failed to load members</p>';
  });
};

window.BoardApp.addBoardMember = function() {
  const userId = document.getElementById('addMemberSelect')?.value;
  const role = document.getElementById('memberRoleSelect')?.value || 'viewer';

  if (!userId) {
    alert('Please select a user');
    return;
  }

  const form = new FormData();
  form.append('board_id', window.BOARD_DATA.boardId);
  form.append('user_id', userId);
  form.append('role', role);

  fetch('/projects/api/board.member.add.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    body: form
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error);
    BoardApp.loadBoardMembers();
    document.getElementById('addMemberSelect').value = '';
    if (window.BoardApp.showToast) window.BoardApp.showToast('Member added', 'success');
  })
  .catch(err => {
    console.error('Add member error:', err);
    alert('Failed to add member: ' + err.message);
  });
};

window.BoardApp.removeBoardMember = function(userId) {
  if (!confirm('Remove this member from the board?')) return;

  const form = new FormData();
  form.append('board_id', window.BOARD_DATA.boardId);
  form.append('user_id', userId);

  fetch('/projects/api/board.member.remove.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    body: form
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error);
    BoardApp.loadBoardMembers();
    if (window.BoardApp.showToast) window.BoardApp.showToast('Member removed', 'success');
  })
  .catch(err => {
    console.error('Remove member error:', err);
    alert('Failed to remove member: ' + err.message);
  });
};

window.BoardApp.duplicateBoard = function() {
  if (!confirm('Duplicate this board?')) return;

  const form = new FormData();
  form.append('board_id', window.BOARD_DATA.boardId);

  fetch('/projects/api/board.duplicate.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    body: form
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error);
    window.location.href = `/projects/board.php?board_id=${data.data?.board_id || data.new_board_id}`;
  })
  .catch(err => {
    console.error('Duplicate error:', err);
    alert('Failed to duplicate board: ' + err.message);
  });
};

window.BoardApp.archiveBoard = function() {
  if (!confirm('Archive this board? It can be restored later.')) return;

  const form = new FormData();
  form.append('board_id', window.BOARD_DATA.boardId);

  fetch('/projects/api/board.archive.php', {
    method: 'POST',
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    body: form
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error);
    window.location.href = `/projects/view.php?project_id=${window.BOARD_DATA.projectId}`;
  })
  .catch(err => {
    console.error('Archive error:', err);
    alert('Failed to archive board: ' + err.message);
  });
};

console.log('✅ main-board.js loaded');
