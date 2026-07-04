/**
 * Flowwork Gantt View
 * Real task ranges (timeline columns > start/end dates > due date),
 * grouped into swimlanes per board group.
 */

(() => {
  'use strict';

  window.BoardApp = window.BoardApp || {};

  const DAY_MS = 1000 * 60 * 60 * 24;
  const DAY_WIDTH = 40; // pixels per day

  // When the user zooms manually we stop auto-fitting to the data
  let rangeOverride = null;

  // ===== RANGE RESOLUTION =====
  // Priority: first timeline-type column value > start_date+end_date >
  // start_date+due_date > due_date (single-day bar).
  function resolveRange(item) {
    const timelineCols = (window.BOARD_DATA.columns || []).filter(c => c.type === 'timeline');
    const values = (window.BOARD_DATA.valuesMap || {})[item.id] || {};

    for (const col of timelineCols) {
      const raw = values[col.column_id];
      if (!raw) continue;
      try {
        const t = JSON.parse(raw);
        if (t && t.start && t.end) {
          const start = new Date(t.start);
          const end = new Date(t.end);
          if (!isNaN(start) && !isNaN(end)) {
            return start <= end ? { start, end } : { start: end, end: start };
          }
        }
      } catch (e) { /* not JSON — ignore */ }
    }

    const parse = (v) => {
      if (!v) return null;
      const d = new Date(String(v).split(' ')[0]);
      return isNaN(d) ? null : d;
    };

    const start = parse(item.start_date);
    const end = parse(item.end_date);
    const due = parse(item.due_date);

    if (start && end) return start <= end ? { start, end } : { start: end, end: start };
    if (start && due) return start <= due ? { start, end: due } : { start: due, end: start };
    if (due) return { start: due, end: due };
    if (start) return { start, end: start };
    return null;
  }

  function collectTasks() {
    const items = window.BoardApp.getVisibleItems
      ? window.BoardApp.getVisibleItems()
      : (window.BOARD_DATA.items || []);

    return items
      .map(item => {
        const range = resolveRange(item);
        return range ? { item, range } : null;
      })
      .filter(Boolean);
  }

  // ===== RENDER GANTT VIEW =====
  window.BoardApp.renderGantt = function() {
    const container = document.getElementById('fw-gantt-view');
    if (!container) return;

    const tasks = collectTasks();

    if (tasks.length === 0) {
      container.innerHTML = `
        <div class="fw-empty-state" style="margin-top: 100px;">
          <div class="fw-empty-icon">📊</div>
          <div class="fw-empty-title">Nothing to plot yet</div>
          <div class="fw-empty-text">Give items a Due Date, Start/End dates, or a Timeline column value to see them here.</div>
        </div>
      `;
      return;
    }

    // Visible window: manual zoom override, else fit to the data with padding
    let winStart, winEnd;
    if (rangeOverride) {
      winStart = new Date(rangeOverride.start);
      winEnd = new Date(rangeOverride.end);
    } else {
      winStart = new Date(Math.min(...tasks.map(t => t.range.start.getTime())));
      winEnd = new Date(Math.max(...tasks.map(t => t.range.end.getTime())));
      winStart.setDate(winStart.getDate() - 7);
      winEnd.setDate(winEnd.getDate() + 14);
    }

    // Swimlanes: board groups in order, only those with plottable items
    const lanes = (window.BOARD_DATA.groups || [])
      .map(group => ({
        group,
        tasks: tasks.filter(t => String(t.item.group_id) === String(group.id))
      }))
      .filter(lane => lane.tasks.length > 0);

    const html = `
      <div class="fw-gantt-container">
        <div class="fw-gantt-header">
          <h2 class="fw-gantt-title">Project Timeline</h2>
          <div class="fw-gantt-controls">
            <button class="fw-btn fw-btn--secondary" aria-label="Zoom out" onclick="BoardApp.ganttZoomOut()">−</button>
            <button class="fw-btn fw-btn--secondary" aria-label="Zoom in" onclick="BoardApp.ganttZoomIn()">+</button>
            <button class="fw-btn fw-btn--text" onclick="BoardApp.ganttFitToScreen()">Fit</button>
          </div>
        </div>
        <div class="fw-gantt-content">
          <div class="fw-gantt-sidebar">
            ${renderSidebar(lanes)}
          </div>
          <div class="fw-gantt-chart">
            ${renderChart(lanes, winStart, winEnd)}
          </div>
        </div>
      </div>
    `;

    container.innerHTML = html;
  };

  // ===== SIDEBAR (grouped task list) =====
  function renderSidebar(lanes) {
    let html = '<div class="fw-gantt-task-list">';

    html += `
      <div class="fw-gantt-task-header">
        <span>Task</span>
      </div>
    `;

    lanes.forEach(lane => {
      const color = lane.group.color || '#8b5cf6';
      html += `
        <div class="fw-gantt-group-row" style="color: ${escapeHtml(color)}; border-left: 3px solid ${escapeHtml(color)};">
          ${escapeHtml(lane.group.name)}
        </div>
      `;

      lane.tasks.forEach(({ item }) => {
        const assignee = item.first_name
          ? `${item.first_name} ${item.last_name}`
          : 'Unassigned';

        html += `
          <div class="fw-gantt-task-row" data-item-id="${item.id}">
            <div class="fw-gantt-task-name">${escapeHtml(item.title)}</div>
            <div class="fw-gantt-task-meta">${escapeHtml(assignee)}</div>
          </div>
        `;
      });
    });

    html += '</div>';
    return html;
  }

  // ===== CHART (timeline + bars) =====
  function renderChart(lanes, winStart, winEnd) {
    const totalDays = Math.max(1, Math.ceil((winEnd - winStart) / DAY_MS));
    const chartWidth = totalDays * DAY_WIDTH;

    let html = `<div class="fw-gantt-timeline" style="width: ${chartWidth}px;">`;

    // Timeline header (dates)
    html += '<div class="fw-gantt-timeline-header">';
    for (let i = 0; i < totalDays; i++) {
      const date = new Date(winStart);
      date.setDate(date.getDate() + i);

      const isWeekend = date.getDay() === 0 || date.getDay() === 6;
      const isToday = isDateToday(date);

      html += `
        <div class="fw-gantt-timeline-day ${isWeekend ? 'fw-gantt-weekend' : ''} ${isToday ? 'fw-gantt-today' : ''}"
             style="width: ${DAY_WIDTH}px;">
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

    // Bars, aligned with the sidebar rows (36px group spacers, 50px task rows)
    html += '<div class="fw-gantt-bars">';

    lanes.forEach(lane => {
      html += '<div class="fw-gantt-group-spacer"></div>';

      lane.tasks.forEach(({ item, range }) => {
        const startOffsetDays = Math.floor((range.start - winStart) / DAY_MS);
        const durationDays = Math.max(1, Math.round((range.end - range.start) / DAY_MS) + 1);

        // Clip to the visible window
        let left = startOffsetDays * DAY_WIDTH;
        let width = durationDays * DAY_WIDTH;
        if (left < 0) { width += left; left = 0; }
        if (left > chartWidth) { left = chartWidth - 8; width = 8; }
        width = Math.max(8, Math.min(width, chartWidth - left));

        const statusColor = window.BOARD_DATA.statusConfig[item.status_label]?.color
          || lane.group.color
          || '#8b5cf6';

        const fmt = (d) => d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        const rangeLabel = `${fmt(range.start)} → ${fmt(range.end)}`;

        html += `
          <div class="fw-gantt-bar-row" style="height: 50px;">
            <div class="fw-gantt-bar"
                 style="left: ${left}px; width: ${width}px; background: ${escapeHtml(statusColor)};"
                 data-item-id="${item.id}"
                 onclick="BoardApp.showItemDetails(${parseInt(item.id, 10)})"
                 title="${escapeHtml(item.title)} (${escapeHtml(rangeLabel)})">
              <span class="fw-gantt-bar-label">${escapeHtml(item.title.substring(0, 20))}</span>
            </div>
          </div>
        `;
      });
    });

    html += '</div>';
    html += '</div>';

    return html;
  }

  // ===== ZOOM CONTROLS =====
  function currentWindow() {
    if (rangeOverride) return rangeOverride;
    const tasks = collectTasks();
    if (tasks.length === 0) return null;
    const start = new Date(Math.min(...tasks.map(t => t.range.start.getTime())));
    const end = new Date(Math.max(...tasks.map(t => t.range.end.getTime())));
    start.setDate(start.getDate() - 7);
    end.setDate(end.getDate() + 14);
    return { start, end };
  }

  window.BoardApp.ganttZoomIn = function() {
    const win = currentWindow();
    if (!win) return;
    const days = Math.ceil((win.end - win.start) / DAY_MS);
    const trim = Math.max(1, Math.floor(days * 0.2));
    const end = new Date(win.end);
    end.setDate(end.getDate() - trim);
    if (end > win.start) {
      rangeOverride = { start: new Date(win.start), end };
      window.BoardApp.renderGantt();
    }
  };

  window.BoardApp.ganttZoomOut = function() {
    const win = currentWindow();
    if (!win) return;
    const days = Math.ceil((win.end - win.start) / DAY_MS);
    const grow = Math.max(1, Math.floor(days * 0.2));
    const end = new Date(win.end);
    end.setDate(end.getDate() + grow);
    rangeOverride = { start: new Date(win.start), end };
    window.BoardApp.renderGantt();
  };

  window.BoardApp.ganttFitToScreen = function() {
    rangeOverride = null;
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
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  console.log('✅ Gantt module loaded');
})();
