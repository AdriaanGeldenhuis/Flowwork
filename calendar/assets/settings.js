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

  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-calendar');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || 'light';
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