/**
 * Flowwork Calendar View
 * Monthly calendar fed by due dates, date columns or timeline columns.
 * Supports drag-to-reschedule and click-a-day quick create.
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  let currentDate = new Date();
  let dateSource = null; // 'due_date' | 'col_<id>' (date) | 'tl_<id>' (timeline)

  function storageKey() {
    return 'fw-calendar-source-' + window.BOARD_DATA.boardId;
  }

  function validSources() {
    const sources = [{ key: 'due_date', label: 'Due Date' }];
    (window.BOARD_DATA.columns || []).forEach(c => {
      if (c.type === 'date') sources.push({ key: 'col_' + c.column_id, label: c.name });
      if (c.type === 'timeline') sources.push({ key: 'tl_' + c.column_id, label: c.name });
    });
    return sources;
  }

  function getSource() {
    if (!dateSource) {
      try { dateSource = localStorage.getItem(storageKey()) || 'due_date'; } catch (e) { dateSource = 'due_date'; }
    }
    if (!validSources().some(s => s.key === dateSource)) dateSource = 'due_date';
    return dateSource;
  }

  window.BoardApp.setCalendarSource = function(key) {
    dateSource = key;
    try { localStorage.setItem(storageKey(), key); } catch (e) { /* storage unavailable */ }
    window.BoardApp.renderCalendar();
  };

  function sourceColumnId() {
    const src = getSource();
    const m = src.match(/^(?:col|tl)_(\d+)$/);
    return m ? parseInt(m[1], 10) : null;
  }

  function isTimelineSource() {
    return /^tl_/.test(getSource());
  }

  // Returns 'YYYY-MM-DD' or {start, end} strings, or null
  function getItemDate(item) {
    const src = getSource();
    if (src === 'due_date') {
      return item.due_date ? String(item.due_date).split(' ')[0] : null;
    }
    const colId = sourceColumnId();
    const raw = ((window.BOARD_DATA.valuesMap || {})[item.id] || {})[colId];
    if (!raw) return null;
    if (isTimelineSource()) {
      try {
        const t = JSON.parse(raw);
        if (t && t.start && t.end) return { start: String(t.start).split(' ')[0], end: String(t.end).split(' ')[0] };
      } catch (e) { /* not JSON */ }
      return null;
    }
    return String(raw).split(' ')[0];
  }

  // ===== RENDER CALENDAR VIEW =====
  window.BoardApp.renderCalendar = function() {
    const container = document.getElementById('fw-calendar-view');
    if (!container) return;

    const visible = window.BoardApp.getVisibleItems
      ? window.BoardApp.getVisibleItems()
      : (window.BOARD_DATA.items || []);
    const items = visible.filter(i => getItemDate(i) !== null);

    const sources = validSources();
    const src = getSource();
    const sourceSelect = sources.length > 1 ? `
      <select class="fw-select fw-calendar-toolbar-select" aria-label="Calendar date source" onchange="BoardApp.setCalendarSource(this.value)">
        ${sources.map(s => `<option value="${escapeHtml(s.key)}" ${s.key === src ? 'selected' : ''}>${escapeHtml(s.label)}</option>`).join('')}
      </select>
    ` : '';

    if (items.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="margin: 80px auto; max-width: 480px; text-align: center;">
          <div class="fw-empty-icon">📅</div>
          <div class="fw-empty-title">Nothing on the calendar</div>
          <div class="fw-empty-text">No items have a value for the selected date source.</div>
          ${sourceSelect ? `<div style="margin-top:16px;display:flex;justify-content:center;align-items:center;gap:8px;"><span style="font-size:13px;color:var(--text-muted);">Date source:</span>${sourceSelect}</div>` : ''}
        </div>
      `;
      return;
    }

    const html = `
      <div class="fw-calendar-container">
        <div class="fw-calendar-header">
          <button class="fw-btn fw-btn--secondary" onclick="BoardApp.prevMonth()">‹ Prev</button>
          <h2 class="fw-calendar-title">${getMonthName(currentDate)} ${currentDate.getFullYear()}</h2>
          <button class="fw-btn fw-btn--secondary" onclick="BoardApp.nextMonth()">Next ›</button>
          <button class="fw-btn fw-btn--text" onclick="BoardApp.todayMonth()">Today</button>
          ${sourceSelect}
        </div>
        <div class="fw-calendar-grid">
          ${renderCalendarGrid(items)}
        </div>
      </div>
    `;

    container.innerHTML = html;
    initCalendarInteractions(container);
  };

  // ===== RENDER CALENDAR GRID =====
  function renderCalendarGrid(items) {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();

    // Group items by date; timeline sources span every day in their range
    const itemsByDate = {};
    const push = (date, item, spanClass) => {
      if (!itemsByDate[date]) itemsByDate[date] = [];
      itemsByDate[date].push({ item, spanClass });
    };

    items.forEach(item => {
      const d = getItemDate(item);
      if (typeof d === 'string') {
        push(d, item, '');
      } else if (d && d.start && d.end) {
        const start = new Date(d.start);
        const end = new Date(d.end);
        if (isNaN(start) || isNaN(end)) return;
        for (let t = new Date(start); t <= end; t.setDate(t.getDate() + 1)) {
          const key = `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(t.getDate()).padStart(2, '0')}`;
          const isEdge = t.getTime() === start.getTime() || t.getTime() === end.getTime();
          push(key, item, isEdge ? '' : ' fw-calendar-item--span');
        }
      }
    });

    let html = '';

    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayNames.forEach(day => {
      html += `<div class="fw-calendar-day-header">${day}</div>`;
    });

    for (let i = 0; i < firstDay; i++) {
      html += '<div class="fw-calendar-day fw-calendar-day--empty"></div>';
    }

    const draggable = !isTimelineSource();

    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const dayItems = itemsByDate[dateStr] || [];

      const isToday =
        day === today.getDate() &&
        month === today.getMonth() &&
        year === today.getFullYear();

      html += `
        <div class="fw-calendar-day ${isToday ? 'fw-calendar-day--today' : ''}" data-date="${dateStr}" title="Click to add an item on ${dateStr}">
          <div class="fw-calendar-day-number">${day}</div>
          <div class="fw-calendar-day-items">
      `;

      dayItems.slice(0, 3).forEach(({ item, spanClass }) => {
        const statusColor = window.BOARD_DATA.statusConfig[item.status_label]?.color || '#64748b';
        html += `
          <div class="fw-calendar-item${spanClass}"
               style="border-left: 3px solid ${statusColor};"
               data-item-id="${item.id}"
               ${draggable && !spanClass ? 'draggable="true"' : ''}
               onclick="BoardApp.showItemDetails(${parseInt(item.id, 10)}); event.stopPropagation();"
               title="${escapeHtml(item.title)}">
            ${escapeHtml(item.title.substring(0, 30))}${item.title.length > 30 ? '...' : ''}
          </div>
        `;
      });

      if (dayItems.length > 3) {
        html += `<div class="fw-calendar-more">+${dayItems.length - 3} more</div>`;
      }

      html += `
          </div>
        </div>
      `;
    }

    return html;
  }

  // ===== INTERACTIONS: drag-to-reschedule + click-a-day quick create =====
  function initCalendarInteractions(container) {
    let draggedItemId = null;

    container.querySelectorAll('.fw-calendar-item[draggable="true"]').forEach(chip => {
      chip.addEventListener('dragstart', (e) => {
        draggedItemId = chip.dataset.itemId;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', draggedItemId);
      });
      chip.addEventListener('dragend', () => { draggedItemId = null; });
    });

    container.querySelectorAll('.fw-calendar-day:not(.fw-calendar-day--empty)').forEach(cell => {
      cell.addEventListener('dragover', (e) => {
        if (!draggedItemId) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        cell.classList.add('fw-drag-over');
      });

      cell.addEventListener('dragleave', () => cell.classList.remove('fw-drag-over'));

      cell.addEventListener('drop', (e) => {
        e.preventDefault();
        cell.classList.remove('fw-drag-over');
        if (!draggedItemId) return;
        rescheduleItem(draggedItemId, cell.dataset.date);
        draggedItemId = null;
      });

      // Quick create when clicking the empty part of a day
      cell.addEventListener('click', (e) => {
        if (e.target.closest('.fw-calendar-item')) return;
        showQuickCreate(cell.dataset.date);
      });
    });
  }

  function rescheduleItem(itemId, dateStr) {
    const item = (window.BOARD_DATA.items || []).find(i => String(i.id) === String(itemId));
    if (!item || !dateStr) return;

    const src = getSource();
    const colId = sourceColumnId();

    const onDone = () => {
      if (window.BoardApp.showToast) window.BoardApp.showToast('Rescheduled to ' + dateStr, 'success');
      window.BoardApp.renderCalendar();
    };
    const onFail = (err) => {
      if (window.BoardApp.showToast) window.BoardApp.showToast('Failed to reschedule: ' + err.message, 'error');
      else alert('Failed to reschedule: ' + err.message);
      window.BoardApp.renderCalendar();
    };

    if (src === 'due_date') {
      window.BoardApp.apiCall('/projects/api/item.update.php', {
        item_id: itemId,
        due_date: dateStr
      }).then(() => {
        item.due_date = dateStr;
        // Sync the table's date cell (backed by due_date fallback)
        const dateCol = (window.BOARD_DATA.columns || []).find(c => c.type === 'date');
        if (dateCol && window.BoardApp.updateCellDOM) {
          window.BoardApp.updateCellDOM(itemId, dateCol.column_id, dateStr, 'date');
        }
        onDone();
      }).catch(onFail);
    } else if (colId) {
      window.BoardApp.apiCall('/projects/api/cell/update.php', {
        item_id: itemId,
        column_id: colId,
        value: dateStr
      }).then(() => {
        if (!window.BOARD_DATA.valuesMap[itemId]) window.BOARD_DATA.valuesMap[itemId] = {};
        window.BOARD_DATA.valuesMap[itemId][colId] = dateStr;
        if (window.BoardApp.updateCellDOM) {
          window.BoardApp.updateCellDOM(itemId, colId, dateStr, 'date');
        }
        onDone();
      }).catch(onFail);
    }
  }

  // ===== QUICK CREATE =====
  function showQuickCreate(dateStr) {
    const groups = window.BOARD_DATA.groups || [];
    if (groups.length === 0) {
      alert('Create a group first (Table view → Add Group)');
      return;
    }

    document.querySelectorAll('.fw-modal-overlay').forEach(el => el.remove());

    const overlay = document.createElement('div');
    overlay.className = 'fw-modal-overlay';
    overlay.innerHTML = `
      <div class="fw-modal-content fw-slide-up" style="max-width: 420px;">
        <div class="fw-modal-header">
          <h3 style="margin:0;font-size:17px;font-weight:700;">New item on ${escapeHtml(dateStr)}</h3>
          <button type="button" class="fw-modal-close" style="background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:22px;">×</button>
        </div>
        <div class="fw-modal-body">
          <div class="fw-form-group">
            <label>Title</label>
            <input type="text" id="calQuickTitle" class="fw-input" placeholder="Item title..." />
          </div>
          <div class="fw-form-group">
            <label>Group</label>
            <select id="calQuickGroup" class="fw-select">
              ${groups.map(g => `<option value="${parseInt(g.id, 10)}">${escapeHtml(g.name)}</option>`).join('')}
            </select>
          </div>
          <div class="fw-modal-footer">
            <button type="button" class="fw-btn fw-btn--secondary fw-modal-cancel">Cancel</button>
            <button type="button" class="fw-btn fw-btn--primary fw-modal-create">Create</button>
          </div>
        </div>
      </div>
    `;

    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(overlay);

    const close = () => overlay.remove();
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    overlay.querySelector('.fw-modal-close').addEventListener('click', close);
    overlay.querySelector('.fw-modal-cancel').addEventListener('click', close);

    const create = () => {
      const title = overlay.querySelector('#calQuickTitle').value.trim();
      const groupId = overlay.querySelector('#calQuickGroup').value;
      if (!title) return;

      window.BoardApp.apiCall('/projects/api/create_item.php', {
        board_id: window.BOARD_DATA.boardId,
        group_id: groupId,
        title: title
      }).then(data => {
        const item = data.item;
        if (!item) throw new Error('Server did not return the new item');

        const src = getSource();
        const colId = sourceColumnId();

        if (src === 'due_date') {
          return window.BoardApp.apiCall('/projects/api/item.update.php', {
            item_id: item.id,
            due_date: dateStr
          }).then(() => { item.due_date = dateStr; return item; });
        }
        if (colId && !isTimelineSource()) {
          return window.BoardApp.apiCall('/projects/api/cell/update.php', {
            item_id: item.id,
            column_id: colId,
            value: dateStr
          }).then(() => {
            if (!window.BOARD_DATA.valuesMap[item.id]) window.BOARD_DATA.valuesMap[item.id] = {};
            window.BOARD_DATA.valuesMap[item.id][colId] = dateStr;
            return item;
          });
        }
        // Timeline source: create a single-day range
        if (colId) {
          const value = JSON.stringify({ start: dateStr, end: dateStr });
          return window.BoardApp.apiCall('/projects/api/cell/update.php', {
            item_id: item.id,
            column_id: colId,
            value: value
          }).then(() => {
            if (!window.BOARD_DATA.valuesMap[item.id]) window.BOARD_DATA.valuesMap[item.id] = {};
            window.BOARD_DATA.valuesMap[item.id][colId] = value;
            return item;
          });
        }
        return item;
      }).then(item => {
        close();
        // addItemToDOM inserts the table row AND pushes into BOARD_DATA.items
        if (window.BoardApp.addItemToDOM) {
          window.BoardApp.addItemToDOM(item, item.group_id);
        } else {
          window.BOARD_DATA.items.push(item);
        }
        window.BoardApp.renderCalendar();
        if (window.BoardApp.showToast) window.BoardApp.showToast('Item created', 'success');
      }).catch(err => {
        alert('Failed to create item: ' + err.message);
      });
    };

    overlay.querySelector('.fw-modal-create').addEventListener('click', create);
    overlay.querySelector('#calQuickTitle').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') create();
    });

    setTimeout(() => overlay.querySelector('#calQuickTitle')?.focus(), 50);
  }

  // ===== NAVIGATION =====
  window.BoardApp.prevMonth = function() {
    currentDate.setMonth(currentDate.getMonth() - 1);
    window.BoardApp.renderCalendar();
  };

  window.BoardApp.nextMonth = function() {
    currentDate.setMonth(currentDate.getMonth() + 1);
    window.BoardApp.renderCalendar();
  };

  window.BoardApp.todayMonth = function() {
    currentDate = new Date();
    window.BoardApp.renderCalendar();
  };

  // ===== SHOW ITEM DETAILS =====
  window.BoardApp.showItemDetails = function(itemId) {
    // Loose compare: ids arrive as strings from PHP but numbers from onclick
    const item = window.BOARD_DATA.items.find(i => String(i.id) === String(itemId));
    if (!item) return;

    const html = `
      <div class="fw-modal-header">
        <h2>${escapeHtml(item.title)}</h2>
        <button class="fw-modal-close" onclick="this.closest('.fw-modal-overlay').remove()">×</button>
      </div>
      <div class="fw-modal-body">
        <div class="fw-form-group">
          <label>Status</label>
          <span class="fw-status-badge" style="background: ${window.BOARD_DATA.statusConfig[item.status_label]?.color || '#64748b'};">
            ${escapeHtml(window.BOARD_DATA.statusConfig[item.status_label]?.label || 'No Status')}
          </span>
        </div>
        <div class="fw-form-group">
          <label>Assigned To</label>
          <span>${item.first_name ? escapeHtml(`${item.first_name} ${item.last_name}`) : 'Unassigned'}</span>
        </div>
        <div class="fw-form-group">
          <label>Due Date</label>
          <span>${item.due_date ? new Date(item.due_date).toLocaleDateString() : 'No date'}</span>
        </div>
        <div class="fw-form-group">
          <label>Priority</label>
          <span>${escapeHtml(item.priority || 'Medium')}</span>
        </div>
        <div style="margin-top: 24px;">
          <a href="/projects/board.php?board_id=${window.BOARD_DATA.boardId}" class="fw-btn fw-btn--primary">
            Open Board
          </a>
        </div>
      </div>
    `;

    const overlay = document.createElement('div');
    overlay.className = 'fw-modal-overlay';
    overlay.innerHTML = `<div class="fw-modal-content">${html}</div>`;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });

    const container = document.querySelector('.fw-proj') || document.body;
    container.appendChild(overlay);
  };

  // Helpers
  function getMonthName(date) {
    const months = ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'];
    return months[date.getMonth()];
  }

  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  console.log('✅ Calendar module loaded');
})();
