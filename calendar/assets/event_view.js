(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';

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