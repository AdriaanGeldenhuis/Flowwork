(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';
  let participantsData = [];
  let remindersData = [];

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

  function showNotification(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      padding: 16px 24px;
      background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#06b6d4'};
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

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-calendar');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || 'light';
    applyTheme(theme);

    toggle.addEventListener('click', () => {
      theme = theme === 'dark' ? 'light' : 'dark';
      applyTheme(theme);
      setCookie(THEME_COOKIE, theme);
    });

    function applyTheme(t) {
      body.setAttribute('data-theme', t);
      if (indicator) {
        indicator.textContent = 'Theme: ' + (t === 'dark' ? 'Dark' : 'Light');
      }
    }
  }

  // ========== ALL DAY TOGGLE ==========
  function initAllDayToggle() {
    const allDayCheckbox = document.getElementById('allDay');
    const startTimeGroup = document.getElementById('startTimeGroup');
    const endTimeGroup = document.getElementById('endTimeGroup');
    const startTime = document.getElementById('startTime');
    const endTime = document.getElementById('endTime');

    if (!allDayCheckbox) return;

    function toggleTimeFields() {
      const isAllDay = allDayCheckbox.checked;
      startTimeGroup.style.display = isAllDay ? 'none' : 'block';
      endTimeGroup.style.display = isAllDay ? 'none' : 'block';
      startTime.required = !isAllDay;
      endTime.required = !isAllDay;
    }

    allDayCheckbox.addEventListener('change', toggleTimeFields);
    toggleTimeFields();
  }

  // ========== CALENDAR COLOR SYNC ==========
  function initCalendarColorSync() {
    const calendarSelect = document.getElementById('calendarId');
    const colorPicker = document.getElementById('eventColor');

    if (!calendarSelect || !colorPicker) return;

    calendarSelect.addEventListener('change', () => {
      const selectedOption = calendarSelect.options[calendarSelect.selectedIndex];
      const calendarColor = selectedOption.getAttribute('data-color');
      if (calendarColor) {
        colorPicker.value = calendarColor;
      }
    });
  }

  // ========== RECURRENCE ==========
  function initRecurrence() {
    const recurrenceType = document.getElementById('recurrenceType');
    const recurrenceOptions = document.getElementById('recurrenceOptions');
    const recurrenceIntervalLabel = document.getElementById('recurrenceIntervalLabel');
    const recurrenceEndType = document.getElementById('recurrenceEndType');
    const recurrenceEndDateGroup = document.getElementById('recurrenceEndDateGroup');
    const recurrenceCountGroup = document.getElementById('recurrenceCountGroup');

    if (!recurrenceType) return;

    recurrenceType.addEventListener('change', () => {
      const value = recurrenceType.value;
      
      if (value && value !== '') {
        recurrenceOptions.style.display = 'block';
        
        // Update interval label
        const labels = {
          'daily': 'day(s)',
          'weekly': 'week(s)',
          'monthly': 'month(s)',
          'yearly': 'year(s)',
          'custom': 'day(s)'
        };
        recurrenceIntervalLabel.textContent = labels[value] || 'day(s)';
      } else {
        recurrenceOptions.style.display = 'none';
      }
    });

    if (recurrenceEndType) {
      recurrenceEndType.addEventListener('change', () => {
        const value = recurrenceEndType.value;
        recurrenceEndDateGroup.style.display = value === 'on' ? 'block' : 'none';
        recurrenceCountGroup.style.display = value === 'after' ? 'block' : 'none';
      });
    }
  }

  // ========== PARTICIPANTS ==========
  function initParticipants() {
    const participantSelector = document.getElementById('participantSelector');
    const participantsList = document.getElementById('participantsList');

    if (!participantSelector) return;

    // Load existing participants
    if (window.EVENT_FORM_CONFIG && window.EVENT_FORM_CONFIG.participants) {
      participantsData = window.EVENT_FORM_CONFIG.participants.map(p => ({
        user_id: p.user_id,
        role: p.role || 'required'
      }));
      renderParticipants();
    }

    participantSelector.addEventListener('change', () => {
      const userId = participantSelector.value;
      if (!userId) return;

      // Check if already added
      if (participantsData.find(p => p.user_id == userId)) {
        showNotification('Participant already added', 'error');
        participantSelector.value = '';
        return;
      }

      participantsData.push({
        user_id: parseInt(userId),
        role: 'required'
      });

      renderParticipants();
      participantSelector.value = '';
    });

    function renderParticipants() {
      if (participantsData.length === 0) {
        participantsList.innerHTML = '<div style="font-size: 13px; color: var(--fw-text-muted); padding: 12px 0;">No participants added</div>';
        return;
      }

      const users = window.EVENT_FORM_CONFIG.companyUsers;
      const html = participantsData.map((p, index) => {
        const user = users.find(u => u.id == p.user_id);
        if (!user) return '';

        return `
          <div class="fw-calendar__participant">
            <div class="fw-calendar__participant-avatar">
              ${user.first_name.charAt(0)}${user.last_name.charAt(0)}
            </div>
            <div class="fw-calendar__participant-info">
              <div class="fw-calendar__participant-name">${user.first_name} ${user.last_name}</div>
              <div class="fw-calendar__participant-email">${user.email}</div>
            </div>
            <select class="fw-calendar__input" style="width: 120px;" onchange="updateParticipantRole(${index}, this.value)">
              <option value="required" ${p.role === 'required' ? 'selected' : ''}>Required</option>
              <option value="optional" ${p.role === 'optional' ? 'selected' : ''}>Optional</option>
            </select>
            <button type="button" class="fw-calendar__btn fw-calendar__btn--small" style="background: var(--accent-danger); color: white;" onclick="removeParticipant(${index})">
              Remove
            </button>
          </div>
        `;
      }).join('');

      participantsList.innerHTML = html;
    }

    // Expose functions globally for inline handlers
    window.updateParticipantRole = function(index, role) {
      participantsData[index].role = role;
    };

    window.removeParticipant = function(index) {
      participantsData.splice(index, 1);
      renderParticipants();
    };
  }

  // ========== REMINDERS ==========
  function initReminders() {
    const remindersList = document.getElementById('remindersList');
    const btnAddReminder = document.getElementById('btnAddReminder');

    if (!btnAddReminder) return;

    // Load existing reminders
    if (window.EVENT_FORM_CONFIG && window.EVENT_FORM_CONFIG.reminders) {
      remindersData = window.EVENT_FORM_CONFIG.reminders;
      renderReminders();
    }

    btnAddReminder.addEventListener('click', () => {
      remindersData.push({
        minutes_before: 15,
        channel: 'in_app'
      });
      renderReminders();
    });

    function renderReminders() {
      if (remindersData.length === 0) {
        remindersList.innerHTML = '<div style="font-size: 13px; color: var(--fw-text-muted); padding: 12px 0;">No reminders set</div>';
        return;
      }

      const html = remindersData.map((r, index) => `
        <div class="fw-calendar__reminder-item" style="display: flex; gap: 12px; align-items: center; padding: 12px; background: var(--fw-highlight); border-radius: 8px; margin-bottom: 8px;">
          <select class="fw-calendar__input" style="flex: 1;" onchange="updateReminderTime(${index}, this.value)">
            <option value="0" ${r.minutes_before == 0 ? 'selected' : ''}>At time of event</option>
            <option value="5" ${r.minutes_before == 5 ? 'selected' : ''}>5 minutes before</option>
            <option value="15" ${r.minutes_before == 15 ? 'selected' : ''}>15 minutes before</option>
            <option value="30" ${r.minutes_before == 30 ? 'selected' : ''}>30 minutes before</option>
            <option value="60" ${r.minutes_before == 60 ? 'selected' : ''}>1 hour before</option>
            <option value="120" ${r.minutes_before == 120 ? 'selected' : ''}>2 hours before</option>
            <option value="1440" ${r.minutes_before == 1440 ? 'selected' : ''}>1 day before</option>
            <option value="10080" ${r.minutes_before == 10080 ? 'selected' : ''}>1 week before</option>
          </select>
          <select class="fw-calendar__input" style="width: 130px;" onchange="updateReminderChannel(${index}, this.value)">
            <option value="in_app" ${r.channel === 'in_app' ? 'selected' : ''}>In-app</option>
            <option value="email" ${r.channel === 'email' ? 'selected' : ''}>Email</option>
          </select>
          <button type="button" class="fw-calendar__btn fw-calendar__btn--small" style="background: var(--accent-danger); color: white;" onclick="removeReminder(${index})">
            Remove
          </button>
        </div>
      `).join('');

      remindersList.innerHTML = html;
    }

    // Expose functions globally
    window.updateReminderTime = function(index, minutes) {
      remindersData[index].minutes_before = parseInt(minutes);
    };

    window.updateReminderChannel = function(index, channel) {
      remindersData[index].channel = channel;
    };

    window.removeReminder = function(index) {
      remindersData.splice(index, 1);
      renderReminders();
    };
  }

  // ========== CONFLICT CHECK ==========
  async function checkConflicts(startDatetime, endDatetime, participants) {
    const userIds = [window.EVENT_FORM_CONFIG.userId, ...participants.map(p => p.user_id)];
    
    const data = await fetchJSON('/calendar/ajax/check_conflicts.php', {
      method: 'POST',
      body: JSON.stringify({
        start_datetime: startDatetime,
        end_datetime: endDatetime,
        user_ids: userIds,
        exclude_event_id: window.EVENT_FORM_CONFIG.isEdit ? window.EVENT_FORM_CONFIG.eventId : null
      })
    });

    return data;
  }

  // ========== FORM SUBMISSION ==========
  function initFormSubmit() {
    const form = document.getElementById('eventForm');
    const btnSubmit = document.getElementById('btnSubmit');
    const formMessage = document.getElementById('formMessage');

    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      btnSubmit.disabled = true;
      btnSubmit.textContent = 'Saving...';

      const formData = new FormData(form);
      const data = {
        calendar_id: parseInt(formData.get('calendar_id')),
        title: formData.get('title'),
        description: formData.get('description'),
        location: formData.get('location'),
        color: formData.get('color'),
        all_day: formData.get('all_day') === 'on',
        visibility: formData.get('visibility'),
        participants: participantsData,
        reminders: remindersData
      };

      // Build datetime
      const startDate = formData.get('start_date');
      const endDate = formData.get('end_date');

      if (data.all_day) {
        data.start_datetime = startDate + ' 00:00:00';
        data.end_datetime = endDate + ' 23:59:59';
      } else {
        const startTime = formData.get('start_time');
        const endTime = formData.get('end_time');
        data.start_datetime = startDate + ' ' + startTime + ':00';
        data.end_datetime = endDate + ' ' + endTime + ':00';
      }

      // Build recurrence (RRULE)
      const recurrenceType = formData.get('recurrence_type');
      if (recurrenceType && recurrenceType !== '') {
        const interval = formData.get('recurrence_interval') || 1;
        const endType = formData.get('recurrence_end_type');
        
        let rrule = 'FREQ=' + recurrenceType.toUpperCase() + ';INTERVAL=' + interval;
        
        if (endType === 'on') {
          const endDate = formData.get('recurrence_end_date');
          if (endDate) {
            rrule += ';UNTIL=' + endDate.replace(/-/g, '') + 'T235959Z';
          }
        } else if (endType === 'after') {
          const count = formData.get('recurrence_count');
          if (count) {
            rrule += ';COUNT=' + count;
          }
        }

        data.recurrence = rrule;
      }

      // Check conflicts
      const conflictResult = await checkConflicts(data.start_datetime, data.end_datetime, participantsData);

      if (conflictResult.ok && conflictResult.has_conflicts) {
        const conflictDetails = conflictResult.conflicts.map(c => 
          `- ${c.events.length} event(s) for user ${c.user_id}`
        ).join('\n');

        const proceed = confirm(
          'Warning: This event conflicts with existing events:\n\n' +
          conflictDetails + '\n\n' +
          'Do you want to create it anyway?'
        );
        
        if (!proceed) {
          btnSubmit.disabled = false;
          btnSubmit.textContent = window.EVENT_FORM_CONFIG.isEdit ? 'Update Event' : 'Create Event';
          return;
        }
      }

      // Submit
      const isEdit = window.EVENT_FORM_CONFIG.isEdit;
      const url = isEdit ? '/calendar/ajax/event_update.php' : '/calendar/ajax/event_create.php';
      
      const payload = isEdit 
        ? { event_id: window.EVENT_FORM_CONFIG.eventId, updates: data }
        : data;

      const result = await fetchJSON(url, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      if (result.ok) {
        showNotification(isEdit ? 'Event updated' : 'Event created', 'success');
        
        setTimeout(() => {
          if (isEdit) {
            window.location.href = '/calendar/event_view.php?id=' + window.EVENT_FORM_CONFIG.eventId;
          } else {
            window.location.href = '/calendar/';
          }
        }, 1000);
      } else {
        formMessage.textContent = result.error || 'Failed to save event';
        formMessage.className = 'fw-calendar__form-message fw-calendar__form-message--error';
        formMessage.style.display = 'block';
        
        btnSubmit.disabled = false;
        btnSubmit.textContent = isEdit ? 'Update Event' : 'Create Event';
      }
    });
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initAllDayToggle();
    initCalendarColorSync();
    initRecurrence();
    initParticipants();
    initReminders();
    initFormSubmit();
  }

  // Add CSS animations
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