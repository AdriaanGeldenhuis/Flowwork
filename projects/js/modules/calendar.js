/**
 * Flowwork Calendar View
 * Monthly calendar with due date items
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  let currentDate = new Date();

  // ===== RENDER CALENDAR VIEW =====
  window.BoardApp.renderCalendar = function() {
    const container = document.getElementById('fw-calendar-view');
    if (!container) return;

    const items = window.BOARD_DATA.items.filter(i => i.due_date);

    const html = `
      <div class="fw-calendar-container">
        <div class="fw-calendar-header">
          <button class="fw-btn fw-btn--secondary" onclick="BoardApp.prevMonth()">‹ Prev</button>
          <h2 class="fw-calendar-title">${getMonthName(currentDate)} ${currentDate.getFullYear()}</h2>
          <button class="fw-btn fw-btn--secondary" onclick="BoardApp.nextMonth()">Next ›</button>
          <button class="fw-btn fw-btn--text" onclick="BoardApp.todayMonth()">Today</button>
        </div>
        <div class="fw-calendar-grid">
          ${renderCalendarGrid(items)}
        </div>
      </div>
    `;

    container.innerHTML = html;
    console.log('✅ Calendar view rendered');
  };

  // ===== RENDER CALENDAR GRID =====
  function renderCalendarGrid(items) {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();

    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = new Date();

    // Group items by date
    const itemsByDate = {};
    items.forEach(item => {
      const date = item.due_date.split(' ')[0]; // YYYY-MM-DD
      if (!itemsByDate[date]) itemsByDate[date] = [];
      itemsByDate[date].push(item);
    });

    let html = '';

    // Day headers
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    dayNames.forEach(day => {
      html += `<div class="fw-calendar-day-header">${day}</div>`;
    });

    // Empty cells before first day
    for (let i = 0; i < firstDay; i++) {
      html += '<div class="fw-calendar-day fw-calendar-day--empty"></div>';
    }

    // Days
    for (let day = 1; day <= daysInMonth; day++) {
      const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
      const dayItems = itemsByDate[dateStr] || [];
      
      const isToday = 
        day === today.getDate() && 
        month === today.getMonth() && 
        year === today.getFullYear();

      html += `
        <div class="fw-calendar-day ${isToday ? 'fw-calendar-day--today' : ''}" data-date="${dateStr}">
          <div class="fw-calendar-day-number">${day}</div>
          <div class="fw-calendar-day-items">
      `;

      dayItems.slice(0, 3).forEach(item => {
        const statusColor = window.BOARD_DATA.statusConfig[item.status_label]?.color || '#64748b';
        html += `
          <div class="fw-calendar-item" 
               style="border-left: 3px solid ${statusColor};" 
               onclick="BoardApp.showItemDetails(${item.id})"
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
    const item = window.BOARD_DATA.items.find(i => i.id === itemId);
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
            ${window.BOARD_DATA.statusConfig[item.status_label]?.label || 'No Status'}
          </span>
        </div>
        <div class="fw-form-group">
          <label>Assigned To</label>
          <span>${item.first_name ? `${item.first_name} ${item.last_name}` : 'Unassigned'}</span>
        </div>
        <div class="fw-form-group">
          <label>Due Date</label>
          <span>${item.due_date ? new Date(item.due_date).toLocaleDateString() : 'No date'}</span>
        </div>
        <div class="fw-form-group">
          <label>Priority</label>
          <span>${item.priority || 'Medium'}</span>
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
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  console.log('✅ Calendar module loaded');
})();