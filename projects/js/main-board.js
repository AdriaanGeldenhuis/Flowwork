/**
 * Flowwork Board - Main Controller
 * MUST LOAD FIRST - Initializes window.BoardApp
 */

// ===== INITIALIZE BoardApp IMMEDIATELY =====
window.BoardApp = window.BoardApp || {};
window.BoardApp.currentView = 'table';

// ===== FALLBACK FUNCTIONS (in case modules not loaded yet) =====
window.BoardApp.showAddColumnModal = window.BoardApp.showAddColumnModal || function() {
  console.warn('⚠️ showAddColumnModal not loaded yet, using fallback');
  
  if (typeof window.BoardApp._showAddColumnModal === 'function') {
    return window.BoardApp._showAddColumnModal();
  }
  
  // Simple fallback
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
  menu.style.position = 'fixed';
  menu.style.right = '20px';
  menu.style.top = '180px';
  menu.style.zIndex = '9999';
  menu.style.maxHeight = '400px';
  menu.style.overflowY = 'auto';
  
  // ✅ FIX: Append to .fw-proj instead of body
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

console.log('🚀 BoardApp object created');

// ===== DOM READY =====
document.addEventListener('DOMContentLoaded', () => {
  console.log('🚀 BoardApp initializing...');
  
  initThemeToggle();
  initMenuToggle();
  initDelegatedEventListeners();
  
  const params = new URLSearchParams(window.location.search);
  const view = params.get('view') || 'table';
  window.BoardApp.switchView(view);
  
  console.log('✅ BoardApp initialized');
  console.log('📊 Data loaded:', {
    items: window.BOARD_DATA?.items?.length || 0,
    groups: window.BOARD_DATA?.groups?.length || 0,
    columns: window.BOARD_DATA?.columns?.length || 0,
    suppliers: window.BOARD_DATA?.suppliers?.length || 0
  });
});

// ===== DELEGATED EVENT LISTENERS =====
function initDelegatedEventListeners() {
  document.addEventListener('click', function(e) {
    // Column menu (3 dots on column header)
    const colMenuBtn = e.target.closest('.fw-icon-btn[onclick*="showColumnMenu"]');
    if (colMenuBtn) {
      e.stopPropagation();
      const onclick = colMenuBtn.getAttribute('onclick');
      if (onclick) {
        try {
          eval(onclick);
        } catch (err) {
          console.error('Column menu error:', err);
        }
      }
      return;
    }
    
    // Item menu (3 dots on row)
    const itemMenuBtn = e.target.closest('.fw-icon-btn[onclick*="showItemMenu"]');
    if (itemMenuBtn) {
      e.stopPropagation();
      const onclick = itemMenuBtn.getAttribute('onclick');
      if (onclick) {
        try {
          eval(onclick);
        } catch (err) {
          console.error('Item menu error:', err);
        }
      }
      return;
    }
    
    // Add column button (+)
    const addColBtn = e.target.closest('.fw-col-add-btn');
    if (addColBtn) {
      e.stopPropagation();
      window.BoardApp.showAddColumnModal();
      return;
    }
    
    // Group menu (3 dots on group header)
    const groupMenuBtn = e.target.closest('.fw-icon-btn[onclick*="showGroupMenu"]');
    if (groupMenuBtn) {
      e.stopPropagation();
      const onclick = groupMenuBtn.getAttribute('onclick');
      if (onclick) {
        try {
          eval(onclick);
        } catch (err) {
          console.error('Group menu error:', err);
        }
      }
      return;
    }
  });
  
  console.log('✅ Delegated event listeners initialized');
}

// ===== THEME TOGGLE =====
function initThemeToggle() {
  const toggle = document.getElementById('themeToggle');
  const body = document.querySelector('.fw-proj');
  
  if (!toggle || !body) return;
  
  const savedTheme = localStorage.getItem('fw-theme') || 'dark';
  body.setAttribute('data-theme', savedTheme);
  
  toggle.addEventListener('click', () => {
    const current = body.getAttribute('data-theme');
    const newTheme = current === 'dark' ? 'light' : 'dark';
    body.setAttribute('data-theme', newTheme);
    localStorage.setItem('fw-theme', newTheme);
    console.log('🎨 Theme switched to:', newTheme);
  });
}

// ===== MENU TOGGLE =====
function initMenuToggle() {
  const toggle = document.getElementById('menuToggle');
  const menu = document.getElementById('headerMenu');
  
  if (!toggle || !menu) return;
  
  toggle.addEventListener('click', (e) => {
    e.stopPropagation();
    const isHidden = menu.getAttribute('aria-hidden') !== 'false';
    menu.setAttribute('aria-hidden', !isHidden);
  });
  
  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target) && e.target !== toggle) {
      menu.setAttribute('aria-hidden', 'true');
    }
  });
}

// ===== VIEW SWITCHING (FIXED) =====
window.BoardApp.switchView = function(viewName) {
  console.log('🔄 Switching to view:', viewName);
  
  const views = ['table', 'kanban', 'calendar', 'gantt'];
  if (!views.includes(viewName)) {
    console.warn('⚠️ Invalid view:', viewName);
    viewName = 'table';
  }

  window.BoardApp.currentView = viewName;

  const containers = {
    table: document.querySelector('.fw-board-container'),
    kanban: document.getElementById('fw-kanban-view'),
    calendar: document.getElementById('fw-calendar-view'),
    gantt: document.getElementById('fw-gantt-view')
  };

  // Log container status
  console.log('📦 Containers found:', {
    table: !!containers.table,
    kanban: !!containers.kanban,
    calendar: !!containers.calendar,
    gantt: !!containers.gantt
  });

  // Hide all views
  Object.entries(containers).forEach(([name, el]) => {
    if (el) {
      el.style.display = 'none';
      console.log(`👁️ Hidden: ${name}`);
    }
  });

  // Show selected view
  const selectedContainer = containers[viewName];
  
  if (!selectedContainer) {
    console.error('❌ Container not found for view:', viewName);
    return;
  }

  // Set display
  selectedContainer.style.display = 'block';
  console.log(`✅ Showing: ${viewName}`);

  // Render view content
  try {
    if (viewName === 'kanban') {
      if (typeof BoardApp.renderKanban === 'function') {
        console.log('🎨 Rendering Kanban...');
        BoardApp.renderKanban();
      } else {
        console.error('❌ BoardApp.renderKanban not found');
        showViewError(selectedContainer, 'Kanban module not loaded');
      }
    } 
    else if (viewName === 'calendar') {
      if (typeof BoardApp.renderCalendar === 'function') {
        console.log('📅 Rendering Calendar...');
        BoardApp.renderCalendar();
      } else {
        console.error('❌ BoardApp.renderCalendar not found');
        showViewError(selectedContainer, 'Calendar module not loaded');
      }
    } 
    else if (viewName === 'gantt') {
      if (typeof BoardApp.renderGantt === 'function') {
        console.log('📊 Rendering Gantt...');
        BoardApp.renderGantt();
      } else {
        console.error('❌ BoardApp.renderGantt not found');
        showViewError(selectedContainer, 'Gantt module not loaded');
      }
    }
  } catch (err) {
    console.error('❌ View render error:', err);
    showViewError(selectedContainer, err.message);
  }

  // Update active button
  document.querySelectorAll('.fw-view-btn').forEach(btn => {
    const isActive = btn.dataset.view === viewName;
    btn.classList.toggle('fw-view-btn--active', isActive);
    console.log(`🔘 Button ${btn.dataset.view}: ${isActive ? 'active' : 'inactive'}`);
  });
  
  console.log('✅ View switch complete:', viewName);
};

// Helper: Show error in view
function showViewError(container, message) {
  container.innerHTML = `
    <div class="fw-empty-state" style="margin-top: 100px;">
      <div class="fw-empty-icon">⚠️</div>
      <div class="fw-empty-title">View Error</div>
      <div class="fw-empty-text">${message}</div>
      <button class="fw-btn fw-btn--primary" onclick="location.reload()" style="margin-top: 16px;">
        Reload Page
      </button>
    </div>
  `;
}

// ===== BOARD TITLE =====
window.BoardApp.updateBoardTitle = function(newTitle) {
  if (!newTitle.trim()) return;
  
  console.log('📝 Updating board title:', newTitle);
  
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
    console.log('✅ Board title updated');
  })
  .catch(err => {
    console.error('❌ Update title error:', err);
    alert('Failed to update title: ' + err.message);
  });
};

// ===== SEARCH =====
window.BoardApp.onSearchInput = function(value) {
  const rows = document.querySelectorAll('.fw-item-row');
  const query = value.toLowerCase();
  
  let visibleCount = 0;
  
  rows.forEach(row => {
    const title = row.querySelector('.fw-item-title')?.value.toLowerCase() || '';
    const isMatch = title.includes(query);
    row.style.display = isMatch ? '' : 'none';
    if (isMatch) visibleCount++;
  });
  
  console.log('🔍 Search results:', visibleCount, 'of', rows.length);
};

// ===== MODALS (PLACEHOLDERS) =====
window.BoardApp.showFilterModal = function() {
  console.log('🔍 Filter modal clicked');
  alert('Filter modal coming soon!');
};

window.BoardApp.showViewsModal = function() {
  console.log('👁️ Views modal clicked');
  alert('Views modal coming soon!');
};

window.BoardApp.exportBoard = function() {
  console.log('📤 Exporting board...');
  const url = `/projects/api/board.export.php?board_id=${window.BOARD_DATA.boardId}`;
  window.location.href = url;
};

window.BoardApp.showImportModal = function() {
  console.log('📥 Import modal clicked');
  alert('Import modal coming soon!');
};

window.BoardApp.showBoardSettings = function() {
  console.log('⚙️ Board settings clicked');
  alert('Board settings coming soon!');
};

// ===== UTILITY: CLOSE ALL DROPDOWNS =====
window.BoardApp.closeAllDropdowns = function() {
  document.querySelectorAll('.fw-dropdown').forEach(m => m.remove());
};

// ===== UTILITY: SHOW DROPDOWN =====
window.BoardApp.showDropdown = function(target, html) {
  // Close any existing dropdowns
  window.BoardApp.closeAllDropdowns();
  
  const menu = document.createElement('div');
  menu.className = 'fw-dropdown';
  menu.innerHTML = html;
  menu.style.position = 'fixed';
  
  const rect = target.getBoundingClientRect();
  let left = rect.left - 200;
  let top = rect.bottom + 8;
  
  // Keep on screen
  if (left < 20) left = rect.left;
  if (top + 300 > window.innerHeight) top = rect.top - 320;
  if (top < 20) top = 20;
  
  menu.style.left = left + 'px';
  menu.style.top = top + 'px';
  menu.style.zIndex = '9999';
  
  // ✅ FIX: Append to .fw-proj instead of body
  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(menu);
  
  // Close on outside click
  setTimeout(() => {
    const closeHandler = (e) => {
      if (!menu.contains(e.target) && e.target !== target) {
        menu.remove();
        document.removeEventListener('click', closeHandler);
      }
    };
    document.addEventListener('click', closeHandler);
  }, 100);
  
  console.log('📋 Dropdown shown');
};

// ===== DEBUG FUNCTIONS =====
window.BoardApp.debug = function() {
  console.log('🔍 BoardApp Debug Info:');
  console.log('Current View:', window.BoardApp.currentView);
  console.log('Board Data:', window.BOARD_DATA);
  console.log('Available Functions:', Object.keys(window.BoardApp).filter(k => typeof window.BoardApp[k] === 'function'));
};

// ===== KEYBOARD SHORTCUTS =====
document.addEventListener('keydown', function(e) {
  // Ctrl/Cmd + K = Search focus
  if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
    e.preventDefault();
    document.getElementById('boardSearchInput')?.focus();
  }
  
  // Escape = Close modals/dropdowns
  if (e.key === 'Escape') {
    document.querySelector('.fw-modal-overlay')?.remove();
    window.BoardApp.closeAllDropdowns();
  }
});

// ===== EXPOSE FOR DEBUGGING =====
window.debugBoard = function() {
  window.BoardApp.debug();
};

// ===== BOARD MENU FUNCTIONS =====
window.BoardApp.showBoardMembers = function() {
  console.log('👥 Board members clicked');
  
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
            `<option value="${u.id}">${u.first_name} ${u.last_name}</option>`
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
  
  // ===== VIEW SWITCHING (ENHANCED) =====
window.BoardApp.switchView = function(viewName) {
  const views = ['table', 'kanban', 'calendar', 'gantt'];
  if (!views.includes(viewName)) viewName = 'table';

  window.BoardApp.currentView = viewName;

  const containers = {
    table: document.querySelector('.fw-board-container'),
    kanban: document.getElementById('fw-kanban-view'),
    calendar: document.getElementById('fw-calendar-view'),
    gantt: document.getElementById('fw-gantt-view')
  };

  // Hide all
  Object.values(containers).forEach(el => {
    if (el) el.style.display = 'none';
  });

  // Show selected and render
  if (containers[viewName]) {
    containers[viewName].style.display = viewName === 'kanban' ? 'flex' : 'block';

    // Render view
    if (viewName === 'kanban' && typeof BoardApp.renderKanban === 'function') {
      BoardApp.renderKanban();
    } else if (viewName === 'calendar' && typeof BoardApp.renderCalendar === 'function') {
      BoardApp.renderCalendar();
    } else if (viewName === 'gantt' && typeof BoardApp.renderGantt === 'function') {
      BoardApp.renderGantt();
    }
  }

  // Update active button
  document.querySelectorAll('.fw-view-btn').forEach(btn => {
    btn.classList.toggle('fw-view-btn--active', btn.dataset.view === viewName);
  });
  
  console.log('📋 Switched to view:', viewName);
};

  // ✅ FIX: Append to .fw-proj instead of body
  const container = document.querySelector('.fw-proj') || document.body;
  container.appendChild(overlay);
  
  // Load current members
  BoardApp.loadBoardMembers();
};

window.BoardApp.loadBoardMembers = function() {
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
          <div class="fw-avatar-sm">${m.first_name[0]}${m.last_name[0]}</div>
          <div>
            <div style="font-weight: 600; color: var(--text-primary);">${m.first_name} ${m.last_name}</div>
            <div style="font-size: 13px; color: var(--text-muted);">${m.email}</div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 12px;">
          <span style="padding: 4px 12px; background: rgba(139, 92, 246, 0.2); color: var(--accent-primary); border-radius: 12px; font-size: 12px; font-weight: 600;">${m.role}</span>
          <button class="fw-btn fw-btn--text" onclick="BoardApp.removeBoardMember(${m.user_id})" title="Remove">×</button>
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
    window.location.href = `/projects/board.php?board_id=${data.new_board_id}`;
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
console.log('💡 Type "debugBoard()" in console for debug info');
console.log('💡 Type "BoardApp.showAddColumnModal()" to test column modal');