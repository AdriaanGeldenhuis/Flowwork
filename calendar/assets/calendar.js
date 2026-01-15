(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';

  // ========== UTILITIES ==========
  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      clearTimeout(timeout);
      timeout = setTimeout(() => func(...args), wait);
    };
  }

  function formatDate(date) {
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return date.toLocaleDateString('en-ZA', options);
  }

  function formatDateRange(start, end) {
    if (start.toDateString() === end.toDateString()) {
      return formatDate(start);
    }
    return formatDate(start) + ' – ' + formatDate(end);
  }

  // ========== AJAX HELPERS ==========
  async function fetchJSON(url, options = {}) {
    try {
      const response = await fetch(url, {
        ...options,
        headers: {
          'Content-Type': 'application/json',
          ...options.headers
        }
      });
      return await response.json();
    } catch (error) {
      console.error('Fetch error:', error);
      return { ok: false, error: 'Network error' };
    }
  }

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-calendar');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || THEME_LIGHT;
    applyTheme(theme);

    toggle.addEventListener('click', () => {
      theme = theme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(theme);
      setCookie(THEME_COOKIE, theme);
    });

    function applyTheme(t) {
      body.setAttribute('data-theme', t);
      if (indicator) {
        indicator.textContent = 'Theme: ' + (t === THEME_DARK ? 'Dark' : 'Light');
      }
    }
  }

  // ========== KEBAB MENU ==========
  function initKebabMenu() {
    const toggle = document.getElementById('kebabToggle');
    const menu = document.getElementById('kebabMenu');
    if (!toggle || !menu) return;

    toggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = menu.getAttribute('aria-hidden') === 'false';
      setMenuState(!isOpen);
    });

    document.addEventListener('click', (e) => {
      if (!menu.contains(e.target) && !toggle.contains(e.target)) {
        setMenuState(false);
      }
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
        setMenuState(false);
        toggle.focus();
      }
    });

    function setMenuState(isOpen) {
      menu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    // Kebab menu actions
    const btnSyncCalendars = document.getElementById('btnSyncCalendars');
    const btnPrintCalendar = document.getElementById('btnPrintCalendar');

    if (btnSyncCalendars) {
      btnSyncCalendars.addEventListener('click', () => {
        setMenuState(false);
        loadCalendarViewReal();
        showNotification('Calendars synced', 'success');
      });
    }

    if (btnPrintCalendar) {
      btnPrintCalendar.addEventListener('click', () => {
        setMenuState(false);
        window.print();
      });
    }
  }

  // ========== CALENDAR STATE ==========
  const CalendarState = {
    currentDate: new Date(),
    activeView: window.CALENDAR_CONFIG ? window.CALENDAR_CONFIG.activeView : 'week',
    calendars: [],
    events: [],
    notes: [],
    tasks: []
  };

  // ========== CALENDAR NAVIGATION ==========
  function initNavigation() {
    const btnToday = document.getElementById('btnToday');
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    const dateDisplay = document.getElementById('dateDisplay');

    if (btnToday) {
      btnToday.addEventListener('click', () => {
        CalendarState.currentDate = new Date();
        updateDateDisplay();
        loadCalendarViewReal();
      });
    }

    if (btnPrev) {
      btnPrev.addEventListener('click', () => {
        navigateCalendar(-1);
      });
    }

    if (btnNext) {
      btnNext.addEventListener('click', () => {
        navigateCalendar(1);
      });
    }

    function navigateCalendar(direction) {
      const view = CalendarState.activeView;
      const current = CalendarState.currentDate;

      if (view === 'day') {
        current.setDate(current.getDate() + direction);
      } else if (view === 'week') {
        current.setDate(current.getDate() + (direction * 7));
      } else if (view === 'month') {
        current.setMonth(current.getMonth() + direction);
      } else if (view === 'year') {
        current.setFullYear(current.getFullYear() + direction);
      }

      updateDateDisplay();
      loadCalendarViewReal();
    }

    function updateDateDisplay() {
      if (!dateDisplay) return;
      
      const view = CalendarState.activeView;
      const current = CalendarState.currentDate;
      let displayText = '';

      if (view === 'day') {
        displayText = formatDate(current);
      } else if (view === 'week') {
        const start = new Date(current);
        start.setDate(current.getDate() - current.getDay());
        const end = new Date(start);
        end.setDate(start.getDate() + 6);
        displayText = formatDateRange(start, end);
      } else if (view === 'month') {
        displayText = current.toLocaleDateString('en-ZA', { year: 'numeric', month: 'long' });
      } else if (view === 'year') {
        displayText = current.getFullYear().toString();
      } else {
        displayText = 'Agenda';
      }

      dateDisplay.textContent = displayText;
    }

    updateDateDisplay();
  }

  // ========== LOAD CALENDARS (REAL DATA) ==========
  async function loadCalendarsReal() {
    const calendarList = document.getElementById('calendarList');
    if (!calendarList) return;

    calendarList.innerHTML = '<div class="fw-calendar__loading">Loading...</div>';

    const data = await fetchJSON('/calendar/ajax/calendar_list.php');

    if (data.ok) {
      CalendarState.calendars = data.calendars;
      renderCalendarList(data.calendars);
    } else {
      calendarList.innerHTML = '<div style="color: var(--accent-danger); font-size: 13px;">Failed to load calendars</div>';
    }
  }

  function renderCalendarList(calendars) {
    const calendarList = document.getElementById('calendarList');
    if (!calendarList) return;

    if (calendars.length === 0) {
      calendarList.innerHTML = '<div style="font-size: 13px; color: var(--fw-text-muted);">No calendars yet</div>';
      return;
    }

    const html = calendars.map(cal => `
      <label style="display: flex; align-items: center; gap: 8px; padding: 8px; cursor: pointer; border-radius: 6px; transition: all 0.2s;" 
             class="fw-calendar__calendar-item" 
             onmouseenter="this.style.background='var(--fw-highlight)'" 
             onmouseleave="this.style.background='transparent'">
        <input type="checkbox" 
               checked 
               data-calendar-id="${cal.id}" 
               class="fw-calendar__calendar-toggle"
               style="width: 16px; height: 16px; cursor: pointer;">
        <span style="width: 12px; height: 12px; background: ${cal.color}; border-radius: 50%; flex-shrink: 0;"></span>
        <span style="font-size: 14px; color: var(--fw-text-primary); flex: 1;">${cal.name}</span>
        <span style="font-size: 11px; color: var(--fw-text-muted);">${cal.event_count}</span>
      </label>
    `).join('');

    calendarList.innerHTML = html;

    // Add event listeners
    document.querySelectorAll('.fw-calendar__calendar-toggle').forEach(checkbox => {
      checkbox.addEventListener('change', () => {
        loadCalendarViewReal();
      });
    });
  }

  // ========== LOAD CALENDAR VIEW (REAL DATA) ==========
  async function loadCalendarViewReal() {
    const viewContainer = document.getElementById('calendarView');
    if (!viewContainer) return;

    const view = CalendarState.activeView;
    const current = CalendarState.currentDate;

    // Calculate range based on view
    let rangeStart, rangeEnd;

    if (view === 'day') {
      rangeStart = new Date(current);
      rangeStart.setHours(0, 0, 0, 0);
      rangeEnd = new Date(current);
      rangeEnd.setHours(23, 59, 59, 999);
    } else if (view === 'week') {
      rangeStart = new Date(current);
      rangeStart.setDate(current.getDate() - current.getDay());
      rangeStart.setHours(0, 0, 0, 0);
      rangeEnd = new Date(rangeStart);
      rangeEnd.setDate(rangeStart.getDate() + 6);
      rangeEnd.setHours(23, 59, 59, 999);
    } else if (view === 'month') {
      rangeStart = new Date(current.getFullYear(), current.getMonth(), 1);
      rangeEnd = new Date(current.getFullYear(), current.getMonth() + 1, 0, 23, 59, 59, 999);
    } else {
      // Default 30 days
      rangeStart = new Date(current);
      rangeStart.setHours(0, 0, 0, 0);
      rangeEnd = new Date(current);
      rangeEnd.setDate(current.getDate() + 30);
    }

    // Get selected calendar IDs
    const selectedCalendars = Array.from(document.querySelectorAll('.fw-calendar__calendar-toggle:checked'))
      .map(cb => cb.dataset.calendarId)
      .join(',');

    viewContainer.innerHTML = '<div class="fw-calendar__loading">Loading...</div>';

    const params = new URLSearchParams({
      view: view,
      range_start: rangeStart.toISOString().slice(0, 19).replace('T', ' '),
      range_end: rangeEnd.toISOString().slice(0, 19).replace('T', ' '),
      calendar_ids: selectedCalendars
    });

    const data = await fetchJSON('/calendar/ajax/view_feed.php?' + params.toString());

    if (data.ok) {
      CalendarState.events = data.events;
      CalendarState.notes = data.notes;
      CalendarState.tasks = data.tasks;

      // Render appropriate view
      if (view === 'month') {
        renderMonthViewWithData(viewContainer, data.events);
      } else if (view === 'week') {
        renderWeekViewWithData(viewContainer, data.events);
      } else if (view === 'day') {
        renderDayViewWithData(viewContainer, data.events);
      } else if (view === 'agenda') {
        renderAgendaViewWithData(viewContainer, data.events);
      } else if (view === 'timeline') {
        renderTimelineViewWithData(viewContainer, data.events);
      } else if (view === 'year') {
        renderYearView(viewContainer);
      }
    } else {
      viewContainer.innerHTML = '<div style="color: var(--accent-danger); padding: 20px;">Failed to load calendar data</div>';
    }
  }

  // ========== MONTH VIEW WITH DATA ==========
  function renderMonthViewWithData(container, events) {
    const current = CalendarState.currentDate;
    const year = current.getFullYear();
    const month = current.getMonth();
    
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const startDate = new Date(firstDay);
    startDate.setDate(startDate.getDate() - firstDay.getDay());
    
    const days = [];
    const tempDate = new Date(startDate);
    
    while (days.length < 42) {
      days.push(new Date(tempDate));
      tempDate.setDate(tempDate.getDate() + 1);
    }

    // Group events by date
    const eventsByDate = {};
    events.forEach(event => {
      const dateKey = event.start_datetime.slice(0, 10);
      if (!eventsByDate[dateKey]) eventsByDate[dateKey] = [];
      eventsByDate[dateKey].push(event);
    });

    const html = `
      <div class="fw-calendar__month-view">
        <div class="fw-calendar__month-grid">
          <div class="fw-calendar__month-header">Sun</div>
          <div class="fw-calendar__month-header">Mon</div>
          <div class="fw-calendar__month-header">Tue</div>
          <div class="fw-calendar__month-header">Wed</div>
          <div class="fw-calendar__month-header">Thu</div>
          <div class="fw-calendar__month-header">Fri</div>
          <div class="fw-calendar__month-header">Sat</div>
          ${days.map(day => {
            const isOtherMonth = day.getMonth() !== month;
            const isToday = day.toDateString() === new Date().toDateString();
            const dayClasses = [
              'fw-calendar__month-day',
              isOtherMonth ? 'fw-calendar__month-day--other-month' : '',
              isToday ? 'fw-calendar__month-day--today' : ''
            ].filter(Boolean).join(' ');

            const dateKey = day.toISOString().slice(0, 10);
            const dayEvents = eventsByDate[dateKey] || [];

            return `
              <div class="${dayClasses}" data-date="${day.toISOString()}">
                <div class="fw-calendar__month-day-number">${day.getDate()}</div>
                <div class="fw-calendar__month-events">
                  ${dayEvents.slice(0, 3).map(event => `
                    <div class="fw-calendar__month-event" 
                         style="background: ${event.color || event.calendar_color}"
                         data-event-id="${event.id}"
                         title="${event.title}">
                      ${event.title}
                    </div>
                  `).join('')}
                  ${dayEvents.length > 3 ? `<div style="font-size: 11px; color: var(--fw-text-muted); padding: 2px;">+${dayEvents.length - 3} more</div>` : ''}
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    `;

    container.innerHTML = html;

    // Enable drag-drop after render
    setTimeout(() => {
      if (window.enableDragDrop) {
        enableDragDrop();
      }
    }, 100);

    // Re-attach click handlers
    document.querySelectorAll('.fw-calendar__month-event').forEach(el => {
      const eventId = el.getAttribute('data-event-id');
      if (eventId) {
        el.addEventListener('click', (e) => {
          e.stopPropagation();
          viewEvent(eventId);
        });
      }
    });
  }

  // ========== WEEK VIEW WITH DATA ==========
  function renderWeekViewWithData(container, events) {
    const current = CalendarState.currentDate;
    const startOfWeek = new Date(current);
    startOfWeek.setDate(current.getDate() - current.getDay());

    const days = [];
    for (let i = 0; i < 7; i++) {
      const day = new Date(startOfWeek);
      day.setDate(startOfWeek.getDate() + i);
      days.push(day);
    }

    const hours = Array.from({ length: 24 }, (_, i) => i);
    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    
    const html = `
      <div class="fw-calendar__week-view">
        <div class="fw-calendar__week-grid">
          <div class="fw-calendar__week-time"></div>
          ${days.map((day, idx) => {
            const isToday = day.toDateString() === new Date().toDateString();
            return `
              <div class="fw-calendar__month-header" style="${isToday ? 'background: rgba(6, 182, 212, 0.1); font-weight: 700;' : ''}">
                ${dayNames[idx]}<br>
                <span style="font-size: 18px;">${day.getDate()}</span>
              </div>
            `;
          }).join('')}
          ${hours.map(hour => `
            <div class="fw-calendar__week-time">${hour.toString().padStart(2, '0')}:00</div>
            ${days.map(() => '<div class="fw-calendar__week-slot"></div>').join('')}
          `).join('')}
        </div>
      </div>
    `;

    container.innerHTML = html;
  }

  // ========== DAY VIEW WITH DATA ==========
  function renderDayViewWithData(container, events) {
    const hours = Array.from({ length: 24 }, (_, i) => i);
    
    const html = `
      <div class="fw-calendar__day-view">
        <div class="fw-calendar__day-timeline">
          ${hours.map(hour => `
            <div class="fw-calendar__day-hour">${hour.toString().padStart(2, '0')}:00</div>
            <div class="fw-calendar__day-slot"></div>
          `).join('')}
        </div>
      </div>
    `;

    container.innerHTML = html;
  }

  // ========== AGENDA VIEW WITH DATA ==========
  function renderAgendaViewWithData(container, events) {
    if (events.length === 0) {
      container.innerHTML = '<div class="fw-calendar__loading">No events in this period</div>';
      return;
    }

    const html = `
      <div class="fw-calendar__agenda-view">
        <div class="fw-calendar__agenda-list">
          ${events.map(event => {
            const startDate = new Date(event.start_datetime);
            const endDate = new Date(event.end_datetime);
            const timeStr = event.all_day ? 'All Day' : 
              startDate.toLocaleTimeString('en-ZA', {hour: '2-digit', minute: '2-digit'}) + ' - ' +
              endDate.toLocaleTimeString('en-ZA', {hour: '2-digit', minute: '2-digit'});

            return `
              <div class="fw-calendar__agenda-item" 
                   style="border-left-color: ${event.color || event.calendar_color}; cursor: pointer;"
                   onclick="viewEvent(${event.id})">
                <div style="min-width: 120px;">
                  <div style="font-size: 14px; font-weight: 600; color: var(--fw-text-primary);">
                    ${startDate.toLocaleDateString('en-ZA', {month: 'short', day: 'numeric'})}
                  </div>
                  <div style="font-size: 13px; color: var(--fw-text-muted);">${timeStr}</div>
                </div>
                <div class="fw-calendar__agenda-details">
                  <div class="fw-calendar__agenda-title">${event.title}</div>
                  <div class="fw-calendar__agenda-meta">
                    ${event.location ? event.location + ' • ' : ''}${event.calendar_name}
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    `;

    container.innerHTML = html;
  }

  // ========== TIMELINE VIEW WITH DATA ==========
  function renderTimelineViewWithData(container, events) {
    const html = `
      <div class="fw-calendar__timeline-view">
        <div class="fw-calendar__timeline">
          <div class="fw-calendar__timeline-lanes">
            ${events.slice(0, 10).map(event => {
              const start = new Date(event.start_datetime);
              const end = new Date(event.end_datetime);
              const duration = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
              
              return `
                <div class="fw-calendar__timeline-lane">
                  <div class="fw-calendar__timeline-label">${event.title}</div>
                  <div class="fw-calendar__timeline-bar" 
                       style="width: ${Math.min(duration * 10, 80)}%; background: ${event.color || event.calendar_color}; cursor: pointer;"
                       onclick="viewEvent(${event.id})">
                    ${start.toLocaleDateString('en-ZA', {month: 'short', day: 'numeric'})} - ${end.toLocaleDateString('en-ZA', {month: 'short', day: 'numeric'})}
                  </div>
                </div>
              `;
            }).join('')}
          </div>
        </div>
      </div>
    `;

    container.innerHTML = html;
  }

  // ========== YEAR VIEW ==========
  function renderYearView(container) {
    const year = CalendarState.currentDate.getFullYear();
    const months = Array.from({ length: 12 }, (_, i) => i);
    
    const html = `
      <div class="fw-calendar__year-view">
        <div class="fw-calendar__year-grid">
          ${months.map(month => {
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const startDate = new Date(firstDay);
            startDate.setDate(startDate.getDate() - firstDay.getDay());
            
            const days = [];
            const tempDate = new Date(startDate);
            
            while (days.length < 42 && tempDate <= lastDay) {
              days.push(new Date(tempDate));
              tempDate.setDate(tempDate.getDate() + 1);
            }

            const monthName = firstDay.toLocaleDateString('en-ZA', { month: 'long' });

            return `
              <div class="fw-calendar__year-month">
                <div class="fw-calendar__year-month-title">${monthName}</div>
                <div class="fw-calendar__year-month-grid">
                  ${days.map(day => {
                    const isToday = day.toDateString() === new Date().toDateString();
                    const dayClasses = [
                      'fw-calendar__year-day',
                      isToday ? 'fw-calendar__year-day--today' : ''
                    ].filter(Boolean).join(' ');

                    return `<div class="${dayClasses}">${day.getDate()}</div>`;
                  }).join('')}
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    `;

    container.innerHTML = html;
  }

  // ========== MY DAY & TASKS ==========
  async function loadMyDay() {
    const myDayPane = document.getElementById('myDayPane');
    if (!myDayPane) return;

    myDayPane.innerHTML = '<div class="fw-calendar__loading">Loading tasks...</div>';

    // For now, show placeholder
    setTimeout(() => {
      myDayPane.innerHTML = `
        <div style="font-size: 13px; color: var(--fw-text-muted);">
          No tasks for today
        </div>
      `;
    }, 500);
  }

  // ========== DRAG AND DROP ==========
  function initDragDrop() {
    let draggedEvent = null;
    let draggedEventElement = null;

    // Make month events draggable
    function makeDraggable(element, eventId) {
      element.setAttribute('draggable', 'true');
      element.style.cursor = 'move';

      element.addEventListener('dragstart', (e) => {
        draggedEvent = eventId;
        draggedEventElement = element;
        element.style.opacity = '0.5';
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', element.innerHTML);
      });

      element.addEventListener('dragend', (e) => {
        element.style.opacity = '1';
        draggedEvent = null;
        draggedEventElement = null;
      });
    }

    // Make day cells droppable
    function makeDroppable(dayElement) {
      dayElement.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        dayElement.style.background = 'rgba(6, 182, 212, 0.1)';
      });

      dayElement.addEventListener('dragleave', (e) => {
        dayElement.style.background = '';
      });

      dayElement.addEventListener('drop', async (e) => {
        e.preventDefault();
        dayElement.style.background = '';

        if (!draggedEvent) return;

        const newDate = dayElement.getAttribute('data-date');
        if (!newDate) return;

        // Find the event in state
        const event = CalendarState.events.find(ev => ev.id == draggedEvent);
        if (!event) return;

        // Calculate new start/end
        const oldStart = new Date(event.start_datetime);
        const oldEnd = new Date(event.end_datetime);
        const duration = oldEnd - oldStart;

        const newStart = new Date(newDate);
        newStart.setHours(oldStart.getHours(), oldStart.getMinutes(), 0);
        
        const newEnd = new Date(newStart.getTime() + duration);

        // Update via AJAX
        const data = await fetchJSON('/calendar/ajax/event_move_resize.php', {
          method: 'POST',
          body: JSON.stringify({
            event_id: draggedEvent,
            start_datetime: newStart.toISOString().slice(0, 19).replace('T', ' '),
            end_datetime: newEnd.toISOString().slice(0, 19).replace('T', ' ')
          })
        });

        if (data.ok) {
          showNotification('Event moved', 'success');
          loadCalendarViewReal();
        } else {
          showNotification(data.error || 'Failed to move event', 'error');
        }
      });
    }

    // Apply to rendered events and days
    window.enableDragDrop = function() {
      // Make events draggable
      document.querySelectorAll('.fw-calendar__month-event').forEach(el => {
        const eventId = el.getAttribute('data-event-id');
        if (eventId) {
          makeDraggable(el, eventId);
        }
      });

      // Make day cells droppable
      document.querySelectorAll('.fw-calendar__month-day').forEach(makeDroppable);
    };
  }

  // ========== NOTIFICATIONS ==========
  function initNotifications() {
    const btn = document.getElementById('notificationsBtn');
    const dropdown = document.getElementById('notificationsDropdown');
    const badge = document.getElementById('notificationsBadge');
    const list = document.getElementById('notificationsList');
    const markAllRead = document.getElementById('markAllRead');

    if (!btn || !dropdown) return;

    // Load notifications
    async function loadNotifications() {
      const data = await fetchJSON('/calendar/ajax/notifications_get.php');
      
      if (data.ok) {
        renderNotifications(data.notifications);
        updateBadge(data.unread_count);
      }
    }

    function renderNotifications(notifications) {
      if (notifications.length === 0) {
        list.innerHTML = '<div class="fw-calendar__notifications-empty">No notifications</div>';
        return;
      }

      const html = notifications.map(n => `
        <a href="${n.link || '#'}" 
           class="fw-calendar__notification-item ${!n.is_read ? 'fw-calendar__notification-item--unread' : ''}"
           data-id="${n.id}"
           onclick="markAsRead(${n.id})">
          <div class="fw-calendar__notification-icon">
            ${getNotificationIcon(n.type)}
          </div>
          <div class="fw-calendar__notification-content">
            <div class="fw-calendar__notification-title">${n.title}</div>
            <div class="fw-calendar__notification-message">${n.message}</div>
            <div class="fw-calendar__notification-time">${timeAgo(n.created_at)}</div>
          </div>
        </a>
      `).join('');

      list.innerHTML = html;
    }

    function updateBadge(count) {
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'block';
      } else {
        badge.style.display = 'none';
      }
    }

    function getNotificationIcon(type) {
      const icons = {
        'calendar_reminder': '🔔',
        'event_invite': '📅',
        'event_updated': '✏️',
        'event_cancelled': '❌'
      };
      return icons[type] || '📬';
    }

    function timeAgo(dateStr) {
      const now = new Date();
      const past = new Date(dateStr);
      const diffMs = now - past;
      const diffMins = Math.floor(diffMs / 60000);
      
      if (diffMins < 1) return 'Just now';
      if (diffMins < 60) return diffMins + 'm ago';
      
      const diffHours = Math.floor(diffMins / 60);
      if (diffHours < 24) return diffHours + 'h ago';
      
      const diffDays = Math.floor(diffHours / 24);
      if (diffDays < 7) return diffDays + 'd ago';
      
      return past.toLocaleDateString();
    }

    // Toggle dropdown
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const isOpen = dropdown.getAttribute('aria-hidden') === 'false';
      dropdown.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
      
      if (!isOpen) {
        loadNotifications();
      }
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
        dropdown.setAttribute('aria-hidden', 'true');
      }
    });

    // Mark all as read
    if (markAllRead) {
      markAllRead.addEventListener('click', async () => {
        const data = await fetchJSON('/calendar/ajax/notifications_mark_all_read.php', {
          method: 'POST'
        });
        
        if (data.ok) {
          loadNotifications();
        }
      });
    }

    // Mark single as read
    window.markAsRead = async function(notificationId) {
      await fetchJSON('/calendar/ajax/notifications_mark_read.php', {
        method: 'POST',
        body: JSON.stringify({ id: notificationId })
      });
    };

    // Load on init
    loadNotifications();

    // Poll for new notifications every 60 seconds
    setInterval(loadNotifications, 60000);
  }

  // ========== EVENT ACTIONS ==========
  window.viewEvent = function(eventId) {
    window.location.href = '/calendar/event_view.php?id=' + eventId;
  };

  async function quickAddEvent(text) {
    const selectedCalendar = document.querySelector('.fw-calendar__calendar-toggle:checked');
    const calendarId = selectedCalendar ? selectedCalendar.dataset.calendarId : null;

    if (!calendarId) {
      showNotification('Please select a calendar first', 'error');
      return;
    }

    const data = await fetchJSON('/calendar/ajax/event_quick_add.php', {
      method: 'POST',
      body: JSON.stringify({ text, calendar_id: calendarId })
    });

    if (data.ok) {
      showNotification('Event created: ' + data.parsed.title, 'success');
      loadCalendarViewReal();
    } else {
      showNotification(data.error || 'Failed to create event', 'error');
    }
  }

  function showNotification(message, type = 'info') {
    // Simple toast notification
    const toast = document.createElement('div');
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 16px 24px;
      background: ${type === 'success' ? 'var(--accent-task)' : type === 'error' ? 'var(--accent-danger)' : 'var(--accent-calendar)'};
      color: white;
      border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.2);
      z-index: 10000;
      animation: slideIn 0.3s ease;
      font-weight: 600;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
      toast.style.animation = 'slideOut 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    initNavigation();
    initNotifications();
    loadCalendarViewReal();
    loadCalendarsReal();
    loadMyDay();
    initDragDrop();

    // Event handlers
    const btnNewEvent = document.getElementById('btnNewEvent');
    if (btnNewEvent) {
      btnNewEvent.addEventListener('click', () => {
        const text = prompt('Quick add event (e.g., "Meeting with John tomorrow at 3pm for 1h"):');
        if (text && text.trim()) {
          quickAddEvent(text.trim());
        }
      });
    }

    const btnAddCalendar = document.getElementById('btnAddCalendar');
    if (btnAddCalendar) {
      btnAddCalendar.addEventListener('click', async () => {
        const name = prompt('Calendar name:');
        if (name && name.trim()) {
          const data = await fetchJSON('/calendar/ajax/calendar_create.php', {
            method: 'POST',
            body: JSON.stringify({ name: name.trim(), type: 'personal', color: '#06b6d4' })
          });
          if (data.ok) {
            showNotification('Calendar created', 'success');
            loadCalendarsReal();
          } else {
            showNotification(data.error || 'Failed to create calendar', 'error');
          }
        }
      });
    }
  }

  // Add CSS for animations
  const style = document.createElement('style');
  style.textContent = `
    @keyframes slideIn {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(100%); opacity: 0; }
    }
  `;
  document.head.appendChild(style);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();