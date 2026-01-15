(function() {
  'use strict';

  // ======== Utilities ========
  const THEME_COOKIE_NAME = 'fw_theme';
  const THEME_DARK  = 'dark';
  const THEME_LIGHT = 'light';

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? decodeURIComponent(match[2]) : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  // ======== Theme Toggle ========
  function initThemeToggle() {
    const toggleButton = document.getElementById('themeToggle');
    const themeIndicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-dashboard');
    if (!toggleButton || !body) return;

    // Initialize from cookie or default to light
    let currentTheme = getCookie(THEME_COOKIE_NAME) || THEME_LIGHT;
    applyTheme(currentTheme);

    toggleButton.addEventListener('click', function() {
      currentTheme = currentTheme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(currentTheme);
      setCookie(THEME_COOKIE_NAME, currentTheme);
    });

    function applyTheme(theme) {
      body.setAttribute('data-theme', theme);
      if (themeIndicator) {
        themeIndicator.textContent = 'Theme: ' + (theme === THEME_DARK ? 'Dark' : 'Light');
      }
    }
  }

  // ======== Kebab Menu ========
  function initKebabMenu() {
    const toggleButton = document.getElementById('kebabToggle');
    const menu = document.getElementById('kebabMenu');
    if (!toggleButton || !menu) return;

    toggleButton.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = menu.getAttribute('aria-hidden') === 'false';
      setMenuState(!isOpen);
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
      if (!menu.contains(e.target) && !toggleButton.contains(e.target)) {
        setMenuState(false);
      }
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && menu.getAttribute('aria-hidden') === 'false') {
        setMenuState(false);
        toggleButton.focus();
      }
    });

    // Keyboard navigation inside menu
    const menuItems = menu.querySelectorAll('.fw-dashboard__kebab-item');
    menuItems.forEach(function(item, index) {
      item.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          const nextIndex = (index + 1) % menuItems.length;
          menuItems[nextIndex].focus();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          const prevIndex = (index - 1 + menuItems.length) % menuItems.length;
          menuItems[prevIndex].focus();
        } else if (e.key === 'Tab' && !e.shiftKey && index === menuItems.length - 1) {
          setMenuState(false);
        } else if (e.key === 'Tab' && e.shiftKey && index === 0) {
          setMenuState(false);
        }
      });
    });

    function setMenuState(isOpen) {
      menu.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
      toggleButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      if (isOpen && menuItems.length > 0) {
        menuItems[0].focus();
      }
    }
  }

  // ======== Role Selection ========
  function initRoles() {
    const roleButtons = document.querySelectorAll('.fw-dashboard__role-btn');
    const widgets     = document.querySelectorAll('.fw-dashboard__widget');
    let currentRole   = localStorage.getItem('dashboardRole') || 'manager';
    applyRole(currentRole);

    roleButtons.forEach(function(btn) {
      if (btn.dataset.role === currentRole) {
        btn.classList.add('active');
      }
      btn.addEventListener('click', function() {
        currentRole = this.dataset.role;
        localStorage.setItem('dashboardRole', currentRole);
        applyRole(currentRole);
      });
    });

    function applyRole(role) {
      roleButtons.forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.role === role);
      });
      widgets.forEach(function(widget) {
        const roles = (widget.dataset.roles || '').split(',').map(r => r.trim());
        if (roles.includes(role)) {
          widget.style.display = '';
        } else {
          widget.style.display = 'none';
        }
      });
    }
  }

  // ======== Notifications ========
  function initNotifications() {
    const btn       = document.getElementById('notificationsBtn');
    const dropdown  = document.getElementById('notificationsDropdown');
    const badge     = document.getElementById('notificationsBadge');
    const list      = document.getElementById('notificationsList');
    const markAll   = document.getElementById('markAllRead');
    if (!btn || !dropdown) return;

    async function loadNotifications() {
      try {
        const res = await fetch('/calendar/ajax/notifications_get.php');
        const data = await res.json();
        if (data.ok) {
          renderNotifications(data.notifications);
          updateBadge(data.unread_count);
        }
      } catch (err) {
        console.error('Failed to load notifications', err);
      }
    }

    function renderNotifications(notifications) {
      if (!notifications || notifications.length === 0) {
        list.innerHTML = '<div class="fw-dashboard__notifications-empty">No notifications</div>';
        return;
      }
      const html = notifications.map(n => {
        const isUnread = !n.is_read;
        return `
          <a href="${n.link || '#'}" class="fw-dashboard__notification-item ${isUnread ? 'fw-dashboard__notification-item--unread' : ''}" data-id="${n.id}" onclick="markDashboardNotificationRead(${n.id})">
            <div class="fw-dashboard__notification-icon">
              ${getNotificationIcon(n.type)}
            </div>
            <div class="fw-dashboard__notification-content">
              <div class="fw-dashboard__notification-title">${escapeHtml(n.title)}</div>
              <div class="fw-dashboard__notification-message">${escapeHtml(n.message)}</div>
              <div class="fw-dashboard__notification-time">${timeAgo(n.created_at)}</div>
            </div>
          </a>
        `;
      }).join('');
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

    // Escape HTML entities to avoid XSS when injecting into DOM
    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
    }

    // Toggle dropdown visibility
    btn.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = dropdown.getAttribute('aria-hidden') === 'false';
      dropdown.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
      if (!isOpen) {
        loadNotifications();
      }
    });

    // Close on outside click
    document.addEventListener('click', function(e) {
      if (!dropdown.contains(e.target) && !btn.contains(e.target)) {
        dropdown.setAttribute('aria-hidden', 'true');
      }
    });

    // Mark all as read
    if (markAll) {
      markAll.addEventListener('click', async function() {
        try {
          const res = await fetch('/calendar/ajax/notifications_mark_all_read.php', { method: 'POST' });
          const data = await res.json();
          if (data.ok) {
            loadNotifications();
          }
        } catch (err) {
          console.error('Failed to mark notifications as read', err);
        }
      });
    }

    // Expose function to mark a single notification as read
    window.markDashboardNotificationRead = async function(notificationId) {
      try {
        await fetch('/calendar/ajax/notifications_mark_read.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id: notificationId })
        });
      } catch (err) {
        console.error('Failed to mark notification read', err);
      }
    };

    // Initial load and polling every minute
    loadNotifications();
    setInterval(loadNotifications, 60000);
  }

  // ======== Finance Stats ========
  function fetchFinanceStats() {
    fetch('/finances/ajax/dashboard_stats.php')
      .then(function(response) { return response.json(); })
      .then(function(data) {
        if (!data.ok || !data.data) return;
        const d = data.data;
        const cash   = document.getElementById('metric-cash-balance');
        const ar     = document.getElementById('metric-ar-outstanding');
        const ap     = document.getElementById('metric-ap-outstanding');
        const profit = document.getElementById('metric-net-profit');
        const vat    = document.getElementById('metric-vat-due');
        const bank   = document.getElementById('metric-bank-unrec');
        if (cash)   cash.textContent   = formatCurrency(d.cash_cents);
        if (ar)     ar.textContent     = formatCurrency(d.ar_cents);
        if (ap)     ap.textContent     = formatCurrency(d.ap_cents);
        if (profit) profit.textContent = formatCurrency(d.pl_month_cents);
        if (vat)    vat.textContent    = formatCurrency(d.vat_due_cents);
        if (bank)   bank.textContent   = d.bank_unrec_count;
      })
      .catch(function(err) {
        console.error('Failed to fetch finance stats', err);
      });
  }

  function formatCurrency(cents) {
    const value = (cents || 0) / 100;
    // Use local currency format (ZAR) if available; fallback to generic
    try {
      return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(value);
    } catch (e) {
      return 'R ' + value.toFixed(2);
    }
  }

  // ======== Drag & Drop Layout ========
  function initDragDrop() {
    const grid = document.getElementById('dashboardGrid');
    if (!grid) return;
    let draggedEl = null;

    // Restore saved order
    try {
      const savedOrder = JSON.parse(localStorage.getItem('dashboardLayout') || '[]');
      savedOrder.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) grid.appendChild(el);
      });
    } catch (err) {
      // ignore if JSON parse fails
    }

    grid.querySelectorAll('.fw-dashboard__widget').forEach(function(widget) {
      widget.setAttribute('draggable', 'true');
      widget.addEventListener('dragstart', function(e) {
        draggedEl = this;
        e.dataTransfer.effectAllowed = 'move';
        // optionally set a custom drag image
      });
      widget.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = this;
        if (target && draggedEl && target !== draggedEl) {
          target.classList.add('drag-over');
        }
      });
      widget.addEventListener('dragleave', function() {
        this.classList.remove('drag-over');
      });
      widget.addEventListener('drop', function(e) {
        e.preventDefault();
        const target = this;
        if (draggedEl && target !== draggedEl) {
          const nodes = Array.from(grid.children);
          const draggedIndex = nodes.indexOf(draggedEl);
          const targetIndex  = nodes.indexOf(target);
          if (draggedIndex < targetIndex) {
            grid.insertBefore(draggedEl, target.nextSibling);
          } else {
            grid.insertBefore(draggedEl, target);
          }
          saveLayout();
        }
        target.classList.remove('drag-over');
      });
    });

    function saveLayout() {
      const order = Array.from(grid.children).map(function(el) { return el.id; });
      localStorage.setItem('dashboardLayout', JSON.stringify(order));
    }
  }

  // ======== Initialization ========
  function init() {
    initThemeToggle();
    initKebabMenu();
    initRoles();
    initNotifications();
    fetchFinanceStats();
    initDragDrop();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();