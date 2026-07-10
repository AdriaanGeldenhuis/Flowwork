(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

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

  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-calendar');
    if (!toggle || !body) return;

    // calendar.js already wired this toggle — don't double-bind
    if (toggle.dataset.themeBound) return;
    toggle.dataset.themeBound = '1';

    let theme = getCookie(THEME_COOKIE) || 'dark';
    body.setAttribute('data-theme', theme);
    if (indicator) indicator.textContent = 'Theme: ' + (theme === 'dark' ? 'Dark' : 'Light');

    toggle.addEventListener('click', () => {
      theme = theme === 'dark' ? 'light' : 'dark';
      body.setAttribute('data-theme', theme);
      if (indicator) indicator.textContent = 'Theme: ' + (theme === 'dark' ? 'Dark' : 'Light');
      setCookie(THEME_COOKIE, theme);
    });
  }

  function initFormSubmit() {
    const form = document.getElementById('settingsForm');
    const formMessage = document.getElementById('formMessage');

    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const formData = new FormData(form);
      const data = {
        timezone: formData.get('timezone'),
        week_start: parseInt(formData.get('week_start')),
        work_hours_start: formData.get('work_hours_start') + ':00',
        work_hours_end: formData.get('work_hours_end') + ':00',
        default_reminder_minutes: parseInt(formData.get('default_reminder_minutes')),
        default_view: formData.get('default_view'),
        enable_invoice_due: formData.get('enable_invoice_due') !== null ? parseInt(formData.get('enable_invoice_due')) : 1,
        enable_project_dates: formData.get('enable_project_dates') !== null ? parseInt(formData.get('enable_project_dates')) : 1
      };

      const result = await fetchJSON('/calendar/ajax/settings_save.php', {
        method: 'POST',
        body: JSON.stringify(data)
      });

      if (result.ok) {
        formMessage.textContent = 'Settings saved successfully';
        formMessage.className = 'fw-calendar__form-message fw-calendar__form-message--success';
        formMessage.style.display = 'block';
        
        setTimeout(() => {
          formMessage.style.display = 'none';
        }, 3000);
      } else {
        formMessage.textContent = result.error || 'Failed to save settings';
        formMessage.className = 'fw-calendar__form-message fw-calendar__form-message--error';
        formMessage.style.display = 'block';
      }
    });
  }

  function initIntegrations() {
    const btnSyncProjects = document.getElementById('btnSyncProjects');
    const btnSyncInvoices = document.getElementById('btnSyncInvoices');
    const btnSyncBoardItems = document.getElementById('btnSyncBoardItems');

    if (btnSyncProjects) {
      btnSyncProjects.addEventListener('click', async () => {
        btnSyncProjects.disabled = true;
        btnSyncProjects.textContent = 'Syncing...';

        const result = await fetchJSON('/calendar/ajax/integration_project_dates.php');

        if (result.ok) {
          showNotification(`Synced ${result.synced} projects`, 'success');
        } else {
          showNotification(result.error || 'Sync failed', 'error');
        }

        btnSyncProjects.disabled = false;
        btnSyncProjects.textContent = '🔄 Sync Project Dates';
      });
    }

    if (btnSyncInvoices) {
      btnSyncInvoices.addEventListener('click', async () => {
        btnSyncInvoices.disabled = true;
        btnSyncInvoices.textContent = 'Syncing...';

        const result = await fetchJSON('/calendar/ajax/integration_invoice_due.php');

        if (result.ok) {
          showNotification(`Synced ${result.synced} invoices`, 'success');
        } else {
          showNotification(result.error || 'Sync failed', 'error');
        }

        btnSyncInvoices.disabled = false;
        btnSyncInvoices.textContent = '🔄 Sync Invoice Due Dates';
      });
    }

    if (btnSyncBoardItems) {
      btnSyncBoardItems.addEventListener('click', async () => {
        btnSyncBoardItems.disabled = true;
        btnSyncBoardItems.textContent = 'Syncing...';

        const result = await fetchJSON('/calendar/ajax/integration_board_items.php');

        if (result.ok) {
          showNotification(`Synced ${result.synced} board items`, 'success');
        } else {
          showNotification(result.error || 'Sync failed', 'error');
        }

        btnSyncBoardItems.disabled = false;
        btnSyncBoardItems.textContent = '🔄 Sync Board Item Due Dates';
      });
    }
  }

  function init() {
    initTheme();
    initFormSubmit();
    initIntegrations();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();