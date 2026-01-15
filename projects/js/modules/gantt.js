/**
 * Gantt Chart View Module
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  // ===== RENDER GANTT VIEW =====
  window.BoardApp.renderGanttView = function() {
    const container = document.getElementById('fw-gantt-view');
    if (!container) return;
    
    const items = window.BOARD_DATA.items.filter(i => i.start_date && i.end_date);
    
    if (items.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state">
          <div class="fw-empty-icon">📅</div>
          <div class="fw-empty-title">No timeline data</div>
          <div class="fw-empty-text">Items need start and end dates to show in Gantt view</div>
        </div>
      `;
      return;
    }
    
    // Calculate date range
    let minDate = new Date(items[0].start_date);
    let maxDate = new Date(items[0].end_date);
    
    items.forEach(item => {
      const start = new Date(item.start_date);
      const end = new Date(item.end_date);
      if (start < minDate) minDate = start;
      if (end > maxDate) maxDate = end;
    });
    
    // Add padding
    minDate.setDate(minDate.getDate() - 7);
    maxDate.setDate(maxDate.getDate() + 7);
    
    // Generate months
    const months = [];
    let current = new Date(minDate);
    while (current <= maxDate) {
      months.push({
        name: current.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }),
        start: new Date(current),
        days: new Date(current.getFullYear(), current.getMonth() + 1, 0).getDate()
      });
      current.setMonth(current.getMonth() + 1);
    }
    
    const totalDays = Math.ceil((maxDate - minDate) / (1000 * 60 * 60 * 24));
    const dayWidth = 30; // pixels per day
    
    let html = `
      <div class="fw-gantt-container">
        <div class="fw-gantt-header">
          <div class="fw-gantt-timeline">
            ${months.map(m => `
              <div class="fw-gantt-month" style="width: ${m.days * dayWidth}px;">
                ${m.name}
              </div>
            `).join('')}
          </div>
        </div>
        
        <div class="fw-gantt-body">
          ${items.map(item => {
            const start = new Date(item.start_date);
            const end = new Date(item.end_date);
            const startOffset = Math.floor((start - minDate) / (1000 * 60 * 60 * 24));
            const duration = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
            
            const statusColors = {
              'todo': '#64748b',
              'working': '#fdab3d',
              'stuck': '#e2445c',
              'done': '#00c875'
            };
            
            const color = statusColors[item.status_label] || '#8b5cf6';
            
            return `
              <div class="fw-gantt-row">
                <div class="fw-gantt-item-label">
                  <div class="fw-gantt-item-title">${item.title}</div>
                  <div class="fw-gantt-item-meta">${item.group_name}</div>
                </div>
                <div class="fw-gantt-timeline-track">
                  <div class="fw-gantt-bar" 
                    style="
                      left: ${startOffset * dayWidth}px;
                      width: ${duration * dayWidth}px;
                      background: ${color};
                    "
                    title="${item.title}\n${item.start_date} → ${item.end_date}">
                    <div class="fw-gantt-bar-label">${duration}d</div>
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    `;
    
    container.innerHTML = html;
  };

  console.log('✅ Gantt module loaded');

})();