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

const FW_VIEWS = ['table', 'kanban', 'calendar', 'gantt', 'workload'];

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
    gantt: document.getElementById('fw-gantt-view'),
    workload: document.getElementById('fw-workload-view')
  };

  Object.values(containers).forEach(el => {
    if (el) el.style.display = 'none';
  });

  const selected = containers[viewName];
  if (!selected) return;

  selected.style.display = 'block';

  // The bottom horizontal scroll bar only drives the table view; hiding it in
  // the other views removes a dead "SCROLL 0%" control (desktop + mobile).
  const scrollBar = document.querySelector('.fw-scroll-sync-bar');
  if (scrollBar) scrollBar.style.display = (viewName === 'table') ? '' : 'none';

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
    } else if (viewName === 'workload') {
      if (typeof BoardApp.renderWorkload === 'function') BoardApp.renderWorkload();
      else showViewError(selected, 'Workload module not loaded');
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

  // Item deep link: ?item=<id>[&open=comments] scrolls to the row, flashes it,
  // and optionally opens its comments — used by @mention notifications.
  const itemId = parseInt(params.get('item') || '', 10);
  if (itemId) {
    const row = document.querySelector(`tr.fw-item-row[data-item-id="${itemId}"]`);
    if (row) {
      row.scrollIntoView({ block: 'center' });
      row.classList.add('fw-item-added');
      setTimeout(() => row.classList.remove('fw-item-added'), 1200);
    }
    if (params.get('open') === 'comments' && typeof window.BoardApp.showComments === 'function') {
      setTimeout(() => window.BoardApp.showComments(itemId), 300);
    } else if (row && typeof window.BoardApp.openItemPanel === 'function') {
      setTimeout(() => window.BoardApp.openItemPanel(itemId), 300);
    }
  }
}

// Wait until every module has loaded (kanban/calendar/gantt renderers live in
// later files) — main.js dispatches fw:modules-ready even when some files fail.
if (window.__fwModulesReady) {
  fwInitBoard();
} else {
  window.addEventListener('fw:modules-ready', fwInitBoard, { once: true });
}

// ===== "NEW SINCE YOUR LAST VISIT" ROW DOTS =====
// Compares each item's updated_at (already in BOARD_DATA) against the last
// visit timestamp stored per board in localStorage. Opening/editing a row
// clears its dot.
(function initUnreadDots() {
  const KEY = 'fw-board-last-visit-' + window.BOARD_DATA.boardId;
  let lastVisit = 0;
  try { lastVisit = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) { return; }

  const stamp = () => {
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* ignore */ }
  };
  window.addEventListener('pagehide', stamp);
  document.addEventListener('visibilitychange', () => { if (document.hidden) stamp(); });

  if (!lastVisit) { stamp(); return; }

  const mark = () => {
    (window.BOARD_DATA.items || []).forEach(item => {
      if (!item.updated_at) return;
      const updated = new Date(String(item.updated_at).replace(' ', 'T')).getTime();
      if (!isNaN(updated) && updated > lastVisit) {
        document.querySelector(`tr.fw-item-row[data-item-id="${item.id}"] td.fw-col-item`)
          ?.classList.add('fw-row-unread');
      }
    });
  };

  if (window.__fwModulesReady) mark();
  else window.addEventListener('fw:modules-ready', mark, { once: true });

  document.addEventListener('click', (e) => {
    e.target.closest('tr.fw-item-row')?.querySelector('td.fw-col-item')?.classList.remove('fw-row-unread');
  });
})();

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

// ===== SHARED ITEM VISIBILITY (search + filters, consumed by ALL views) =====
// filters.js sets filteredItemIds (Set of string ids) after filter/apply.php;
// search sets searchQuery. Every view renders from getVisibleItems() so the
// table, kanban, calendar and gantt always agree on what is visible.
window.BoardApp.filteredItemIds = null;

window.BoardApp.getVisibleItems = function() {
  let items = window.BOARD_DATA.items || [];

  const ids = window.BoardApp.filteredItemIds;
  if (ids) items = items.filter(i => ids.has(String(i.id)));

  const q = window.BoardApp.searchQuery;
  if (q) items = items.filter(i => (i.title || '').toLowerCase().includes(q));

  return items;
};

let fwLastVisibleSignature = null;

window.BoardApp.refreshTableVisibility = function() {
  const hasQuery = !!window.BoardApp.searchQuery;
  const hasFilters = !!window.BoardApp.filteredItemIds;
  const narrowing = hasQuery || hasFilters;

  const visibleIds = new Set(window.BoardApp.getVisibleItems().map(i => String(i.id)));
  let visibleCount = 0;

  document.querySelectorAll('tr.fw-item-row').forEach(row => {
    const show = !narrowing || visibleIds.has(String(row.dataset.itemId));
    row.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });

  // Hide groups whose rows are all hidden (skip the totals block); update counts
  document.querySelectorAll('.fw-group').forEach(group => {
    if (group.classList.contains('fw-board-totals-group')) return;
    const visibleRows = Array.from(group.querySelectorAll('tr.fw-item-row'))
      .filter(r => r.style.display !== 'none').length;
    group.style.display = (narrowing && visibleRows === 0) ? 'none' : '';
    const countEl = group.querySelector('.fw-group-count');
    if (countEl) countEl.textContent = visibleRows;
  });

  // Clear-search button
  const clearBtn = document.getElementById('boardSearchClear');
  if (clearBtn) clearBtn.style.display = hasQuery ? '' : 'none';

  // No-results state
  let empty = document.getElementById('fwSearchEmpty');
  if (narrowing && visibleCount === 0) {
    if (!empty) {
      empty = document.createElement('div');
      empty.id = 'fwSearchEmpty';
      empty.className = 'fw-empty-state';
      empty.style.padding = '60px 20px';
      const boardContainer = document.querySelector('.fw-board-container');
      if (boardContainer) boardContainer.prepend(empty);
    }
    const esc = window.BoardApp.escapeHtml || (s => s);
    const parts = [];
    if (hasQuery) parts.push(`search "${esc(window.BoardApp.searchQuery)}"`);
    if (hasFilters) parts.push('the active filters');
    empty.innerHTML = `
      <div class="fw-empty-icon">🔍</div>
      <div class="fw-empty-title">No items match ${parts.join(' and ')}</div>
      <div class="fw-empty-text">Try adjusting your search or filters</div>
      <div style="margin-top:12px;display:flex;gap:8px;justify-content:center;">
        ${hasQuery ? '<button class="fw-btn fw-btn--secondary" onclick="BoardApp.clearSearch()">Clear search</button>' : ''}
        ${hasFilters ? '<button class="fw-btn fw-btn--secondary" onclick="BoardApp.clearFilters()">Clear filters</button>' : ''}
      </div>
    `;
  } else if (empty) {
    empty.remove();
  }

  // Non-table views re-render from the same visible set — but only when the
  // set actually changed (typing a search shouldn't rebuild kanban per keystroke)
  const signature = narrowing ? Array.from(visibleIds).sort().join(',') : '*all*';
  if (window.BoardApp.currentView !== 'table' && signature !== fwLastVisibleSignature) {
    window.BoardApp.switchView(window.BoardApp.currentView);
  }
  fwLastVisibleSignature = signature;

  if (window.fwAnnounce && narrowing) window.fwAnnounce(`${visibleCount} items match`);
};

// ===== SEARCH =====
let fwSearchTimer = null;

window.BoardApp.onSearchInput = function(value) {
  clearTimeout(fwSearchTimer);
  fwSearchTimer = setTimeout(() => {
    window.BoardApp.searchQuery = (value || '').toLowerCase().trim();
    window.BoardApp.refreshTableVisibility();
  }, 150);
};

window.BoardApp.clearSearch = function() {
  const input = document.getElementById('boardSearchInput');
  if (input) input.value = '';
  clearTimeout(fwSearchTimer);
  window.BoardApp.searchQuery = '';
  window.BoardApp.refreshTableVisibility();
  input?.focus();
};

// ===== LOAD MORE ITEMS (boards past the server render cap) =====
// Pages the remaining rows in through api/board.load.php (100 per request,
// aligned with the 500-row server render cap).
window.BoardApp.loadMoreItems = function() {
  const btn = document.getElementById('loadMoreItemsBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Loading…'; }

  const per = 100;
  const loaded = (window.BOARD_DATA.items || []).length;
  const page = Math.floor(loaded / per) + 1;

  fetch(`/projects/api/board.load.php?board_id=${window.BOARD_DATA.boardId}&page=${page}&per=${per}`, {
    headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
    credentials: 'same-origin'
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) throw new Error(data.error || 'Failed to load items');
    const payload = data.data || {};
    const items = payload.items || [];
    const values = payload.values || [];

    // Merge values first so renderers can read them
    values.forEach(v => {
      if (!window.BOARD_DATA.valuesMap[v.item_id]) window.BOARD_DATA.valuesMap[v.item_id] = {};
      window.BOARD_DATA.valuesMap[v.item_id][v.column_id] = v.value;
    });

    items.forEach(item => {
      // Skip rows already on the page (addItemToDOM also dedupes the cache)
      if (document.querySelector(`tr.fw-item-row[data-item-id="${item.id}"]`)) return;
      if (window.BoardApp.hydrateItemRow) {
        window.BoardApp.hydrateItemRow(item);
      } else {
        window.BOARD_DATA.items.push(item);
      }
    });

    const total = payload.pagination ? payload.pagination.total : window.BOARD_DATA.totalItems;
    const nowLoaded = (window.BOARD_DATA.items || []).length;
    const countEl = document.getElementById('loadedItemCount');
    if (countEl) countEl.textContent = nowLoaded;

    if (nowLoaded >= total || items.length === 0) {
      document.getElementById('truncationBanner')?.remove();
      // Everything is loaded — client-side aggregations are now accurate
      (window.BOARD_DATA.groups || []).forEach(g => {
        if (window.BoardApp.updateAggregations) window.BoardApp.updateAggregations(g.id);
      });
      if (window.BoardApp.updateBoardTotals) window.BoardApp.updateBoardTotals();
    } else if (btn) {
      btn.disabled = false;
      btn.textContent = 'Load more';
    }
  })
  .catch(err => {
    if (btn) { btn.disabled = false; btn.textContent = 'Load more'; }
    if (window.BoardApp.showToast) window.BoardApp.showToast('Failed to load more items: ' + err.message, 'error');
  });
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

// ===== BOARD SETTINGS =====
window.BoardApp.showBoardSettings = function() {
  const esc = window.BoardApp.escapeHtml || (s => s);
  const currentTitle = document.querySelector('.fw-board-title-display')?.textContent.trim() || '';
  const currentDefault = FW_VIEWS.includes(window.BOARD_DATA.defaultView) ? window.BOARD_DATA.defaultView : 'table';

  const overlay = document.createElement('div');
  overlay.className = 'fw-modal-overlay';
  overlay.innerHTML = `
    <div class="fw-modal-content fw-slide-up" style="max-width: 440px;">
      <div class="fw-modal-header">
        <h3 style="margin:0;font-size:17px;font-weight:700;">Board Settings</h3>
        <button type="button" class="fw-modal-close" onclick="this.closest('.fw-modal-overlay').remove()" style="background:none;border:none;color:inherit;cursor:pointer;font-size:22px;">×</button>
      </div>
      <div class="fw-modal-body">
        <div class="fw-form-group">
          <label for="boardSettingsTitle">Board Name</label>
          <input type="text" id="boardSettingsTitle" class="fw-input" value="${esc(currentTitle)}" maxlength="150" />
        </div>
        <div class="fw-form-group">
          <label for="boardSettingsDefaultView">Default View (for everyone opening this board)</label>
          <select id="boardSettingsDefaultView" class="fw-select">
            ${FW_VIEWS.map(v => `<option value="${v}" ${v === currentDefault ? 'selected' : ''}>${v.charAt(0).toUpperCase() + v.slice(1)}</option>`).join('')}
          </select>
        </div>
        <div class="fw-modal-footer">
          <button type="button" class="fw-btn fw-btn--secondary" onclick="this.closest('.fw-modal-overlay').remove()">Cancel</button>
          <button type="button" class="fw-btn fw-btn--primary" id="boardSettingsSave">Save</button>
        </div>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

  overlay.querySelector('#boardSettingsSave').addEventListener('click', () => {
    const title = overlay.querySelector('#boardSettingsTitle').value.trim();
    const defaultView = overlay.querySelector('#boardSettingsDefaultView').value;
    if (!title) return;

    const form = new FormData();
    form.append('board_id', window.BOARD_DATA.boardId);
    form.append('title', title);
    form.append('default_view', defaultView);

    fetch('/projects/api/board.update.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': window.BOARD_DATA.csrfToken },
      body: form
    })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) throw new Error(data.error);
      overlay.remove();

      const titleEl = document.querySelector('.fw-board-title-display');
      if (titleEl) titleEl.textContent = title;
      document.title = title + ' – Flowwork';
      window.BOARD_DATA.defaultView = defaultView;

      if (window.BoardApp.showToast) window.BoardApp.showToast('Board settings saved', 'success');
    })
    .catch(err => {
      alert('Failed to save settings: ' + err.message);
    });
  });

  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(overlay);
  setTimeout(() => overlay.querySelector('#boardSettingsTitle')?.focus(), 50);
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
