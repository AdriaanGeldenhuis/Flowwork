(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-calendar');
    if (!toggle || !body) return;

    // calendar.js already wired this toggle — don't double-bind
    if (toggle.dataset.themeBound) return;
    toggle.dataset.themeBound = '1';

    let theme = getCookie(THEME_COOKIE) || 'dark';
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

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  // ========== AJAX HELPER ==========
  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  async function fetchJSON(url, options = {}) {
    try {
      const headers = {
        'Content-Type': 'application/json',
        ...options.headers
      };
      const method = (options.method || 'GET').toUpperCase();
      if (method !== 'GET' && method !== 'HEAD') {
        const token = getCsrfToken();
        if (token) headers['X-CSRF-TOKEN'] = token;
      }
      const response = await fetch(url, { ...options, headers });
      return await response.json();
    } catch (error) {
      console.error('Fetch error:', error);
      return { ok: false, error: 'Network error' };
    }
  }

  function showNotification(message, type = 'info') {
    let stack = document.getElementById('calendarToastStack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'calendarToastStack';
      stack.className = 'fw-calendar__toast-stack';
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    const variant = (type === 'success' || type === 'error') ? type : 'info';
    const toast = document.createElement('div');
    toast.className = 'fw-calendar__toast fw-calendar__toast--' + variant;
    toast.textContent = message;
    stack.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('fw-calendar__toast--visible'));
    setTimeout(() => {
      toast.classList.remove('fw-calendar__toast--visible');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  }

  // ========== INLINE EDIT TITLE ==========
  function initInlineEdit() {
    const titleEl = document.getElementById('eventTitle');
    if (!titleEl || !window.EVENT_CONFIG.canEdit) return;

    let originalTitle = titleEl.textContent.trim();

    titleEl.addEventListener('blur', async () => {
      const newTitle = titleEl.textContent.trim();
      if (newTitle && newTitle !== originalTitle) {
        const data = await fetchJSON('/calendar/ajax/event_update.php', {
          method: 'POST',
          body: JSON.stringify({
            event_id: window.EVENT_CONFIG.eventId,
            updates: { title: newTitle }
          })
        });

        if (data.ok) {
          originalTitle = newTitle;
          showNotification('Title updated', 'success');
        } else {
          titleEl.textContent = originalTitle;
          showNotification(data.error || 'Failed to update', 'error');
        }
      }
    });

    titleEl.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        titleEl.blur();
      }
      if (e.key === 'Escape') {
        titleEl.textContent = originalTitle;
        titleEl.blur();
      }
    });
  }

  // ========== EDIT BUTTON ==========
  function initEditButton() {
    const btnEdit = document.getElementById('btnEdit');
    if (!btnEdit) return;

    btnEdit.addEventListener('click', () => {
      window.location.href = '/calendar/event_new.php?id=' + window.EVENT_CONFIG.eventId;
    });
  }

  // ========== DELETE BUTTON ==========
  function initDeleteButton() {
    const btnDelete = document.getElementById('btnDelete');
    if (!btnDelete) return;

    btnDelete.addEventListener('click', async () => {
      if (!confirm('Are you sure you want to delete this event? This action cannot be undone.')) {
        return;
      }

      const data = await fetchJSON('/calendar/ajax/event_delete.php', {
        method: 'POST',
        body: JSON.stringify({ event_id: window.EVENT_CONFIG.eventId })
      });

      if (data.ok) {
        showNotification('Event deleted', 'success');
        setTimeout(() => {
          window.location.href = '/calendar/';
        }, 1000);
      } else {
        showNotification(data.error || 'Failed to delete', 'error');
      }
    });
  }

  // ========== ADD PARTICIPANT ==========
  function initAddParticipant() {
    const btnAdd = document.getElementById('btnAddParticipant');
    if (!btnAdd) return;

    btnAdd.addEventListener('click', () => {
      showNotification('Participant management coming soon', 'info');
    });
  }

  // ========== ADD ATTACHMENT ==========
  function initAddAttachment() {
    const btnAdd = document.getElementById('btnAddAttachment');
    if (!btnAdd) return;

    btnAdd.addEventListener('click', () => {
      showNotification('Attachment upload coming soon', 'info');
    });
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initInlineEdit();
    initEditButton();
    initDeleteButton();
    initAddParticipant();
    initAddAttachment();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();