/**
 * Flowwork Gantt View
 * Timeline view with task dependencies
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  let ganttStartDate = new Date();
  let ganttEndDate = new Date();
  ganttStartDate.setMonth(ganttStartDate.getMonth() - 1);
  ganttEndDate.setMonth(ganttEndDate.getMonth() + 2);

  // ===== RENDER GANTT VIEW =====
  window.BoardApp.renderGantt = function() {
    const container = document.getElementById('fw-gantt-view');
    if (!container) return;

    const items = window.BOARD_DATA.items.filter(i => i.due_date);

    if (items.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="margin-top: 100px;">
          <div class="fw-empty-icon">📊</div>
          <div class="fw-empty-title">No items with due dates</div>
          <div class="fw-empty-text">Add due dates to items to see them in Gantt view</div>
        </div>
      `;
      return;
    }

    // Calculate date range
    const dates = items.map(i => new Date(i.due_date));
    ganttStartDate = new Date(Math.min(...dates));
    ganttEndDate = new Date(Math.max(...dates));
    
    // Add padding
    ganttStartDate.setDate(ganttStartDate.getDate() - 7);
    ganttEndDate.setDate(ganttEndDate.getDate() + 14);

    const html = `
      <div class="fw-gantt-container">
        <div class="fw-gantt-header">
          <h2 class="fw-gantt-title">Project Timeline</h2>
          <div class="fw-gantt-controls">
            <button class="fw-btn fw-btn--secondary" onclick="BoardApp.ganttZoomOut()">−</button>
            <button class="fw-btn fw-btn--secondary" onclick="BoardApp.ganttZoomIn()">+</button>
            <button class="fw-btn fw-btn--text" onclick="BoardApp.ganttFitToScreen()">Fit</button>
          </div>
        </div>
        <div class="fw-gantt-content">
          <div class="fw-gantt-sidebar">
            ${renderGanttSidebar(items)}
          </div>
          <div class="fw-gantt-chart">
            ${renderGanttChart(items)}
          </div>
        </div>
      </div>
    `;

    container.innerHTML = html;
    console.log('✅ Gantt view rendered');
  };

  // ===== SIDEBAR (Task List) =====
  function renderGanttSidebar(items) {
    let html = '<div class="fw-gantt-task-list">';
    
    html += `
      <div class="fw-gantt-task-header">
        <span>Task</span>
      </div>
    `;

    items.forEach(item => {
      const assignee = item.first_name 
        ? `${item.first_name} ${item.last_name}` 
        : 'Unassigned';

      html += `
        <div class="fw-gantt-task-row" data-item-id="${item.id}">
          <div class="fw-gantt-task-name">${escapeHtml(item.title)}</div>
          <div class="fw-gantt-task-meta">${assignee}</div>
        </div>
      `;
    });

    html += '</div>';
    return html;
  }

  // ===== CHART (Timeline) =====
  function renderGanttChart(items) {
    const totalDays = Math.ceil((ganttEndDate - ganttStartDate) / (1000 * 60 * 60 * 24));
    const dayWidth = 40; // pixels per day

    let html = `<div class="fw-gantt-timeline" style="width: ${totalDays * dayWidth}px;">`;

    // Timeline header (dates)
    html += '<div class="fw-gantt-timeline-header">';
    for (let i = 0; i < totalDays; i++) {
      const date = new Date(ganttStartDate);
      date.setDate(date.getDate() + i);
      
      const isWeekend = date.getDay() === 0 || date.getDay() === 6;
      const isToday = isDateToday(date);

      html += `
        <div class="fw-gantt-timeline-day ${isWeekend ? 'fw-gantt-weekend' : ''} ${isToday ? 'fw-gantt-today' : ''}"
             style="width: ${dayWidth}px;">
          <div class="fw-gantt-date-label">
            ${date.getDate()}
          </div>
          <div class="fw-gantt-month-label">
            ${i === 0 || date.getDate() === 1 ? getMonthAbbr(date) : ''}
          </div>
        </div>
      `;
    }
    html += '</div>';

    // Task bars
    html += '<div class="fw-gantt-bars">';
    
    items.forEach(item => {
      const dueDate = new Date(item.due_date);
      const startDate = new Date(dueDate);
      startDate.setDate(startDate.getDate() - 7); // Default 7-day duration

      const daysSinceStart = Math.floor((startDate - ganttStartDate) / (1000 * 60 * 60 * 24));
      const duration = 7; // days
      
      const left = daysSinceStart * dayWidth;
      const width = duration * dayWidth;

      const statusColor = window.BOARD_DATA.statusConfig[item.status_label]?.color || '#8b5cf6';

      html += `
        <div class="fw-gantt-bar-row" style="height: 50px;">
          <div class="fw-gantt-bar" 
               style="left: ${left}px; width: ${width}px; background: ${statusColor};"
               data-item-id="${item.id}"
               onclick="BoardApp.showItemDetails(${item.id})"
               title="${escapeHtml(item.title)}">
            <span class="fw-gantt-bar-label">${escapeHtml(item.title.substring(0, 20))}</span>
          </div>
        </div>
      `;
    });

    html += '</div>';
    html += '</div>';

    return html;
  }

  // ===== ZOOM CONTROLS =====
  window.BoardApp.ganttZoomIn = function() {
    const days = Math.ceil((ganttEndDate - ganttStartDate) / (1000 * 60 * 60 * 24));
    ganttEndDate.setDate(ganttEndDate.getDate() - Math.floor(days * 0.2));
    window.BoardApp.renderGantt();
  };

  window.BoardApp.ganttZoomOut = function() {
    const days = Math.ceil((ganttEndDate - ganttStartDate) / (1000 * 60 * 60 * 24));
    ganttEndDate.setDate(ganttEndDate.getDate() + Math.floor(days * 0.2));
    window.BoardApp.renderGantt();
  };

  window.BoardApp.ganttFitToScreen = function() {
    const items = window.BOARD_DATA.items.filter(i => i.due_date);
    if (items.length === 0) return;

    const dates = items.map(i => new Date(i.due_date));
    ganttStartDate = new Date(Math.min(...dates));
    ganttEndDate = new Date(Math.max(...dates));
    
    ganttStartDate.setDate(ganttStartDate.getDate() - 7);
    ganttEndDate.setDate(ganttEndDate.getDate() + 14);

    window.BoardApp.renderGantt();
  };

  // Helpers
  function isDateToday(date) {
    const today = new Date();
    return date.getDate() === today.getDate() &&
           date.getMonth() === today.getMonth() &&
           date.getFullYear() === today.getFullYear();
  }

  function getMonthAbbr(date) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 
                    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[date.getMonth()];
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  console.log('✅ Gantt module loaded');
})();