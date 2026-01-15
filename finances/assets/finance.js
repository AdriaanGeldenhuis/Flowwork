// /finances/assets/finance.js
// Core Finance Module JavaScript

(function() {
  'use strict';

  // Theme Management
  const themeToggle = document.getElementById('themeToggle');
  const themeIndicator = document.getElementById('themeIndicator');
  const root = document.querySelector('.fw-finance');

  function initTheme() {
    const savedTheme = localStorage.getItem('fw-finance-theme') || 'light';
    applyTheme(savedTheme);
  }

  function applyTheme(theme) {
    root.setAttribute('data-theme', theme);
    if (themeIndicator) {
      themeIndicator.textContent = `Theme: ${theme.charAt(0).toUpperCase() + theme.slice(1)}`;
    }
    localStorage.setItem('fw-finance-theme', theme);
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const currentTheme = root.getAttribute('data-theme') || 'light';
      const newTheme = currentTheme === 'light' ? 'dark' : 'light';
      applyTheme(newTheme);
    });
  }

  // Kebab Menu
  const kebabToggle = document.getElementById('kebabToggle');
  const kebabMenu = document.getElementById('kebabMenu');

  if (kebabToggle && kebabMenu) {
    kebabToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isHidden = kebabMenu.getAttribute('aria-hidden') === 'true';
      kebabMenu.setAttribute('aria-hidden', !isHidden);
    });

    document.addEventListener('click', (e) => {
      if (!kebabMenu.contains(e.target) && e.target !== kebabToggle) {
        kebabMenu.setAttribute('aria-hidden', 'true');
      }
    });
  }

  // Modal Management
  window.FinanceModal = {
    open: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      }
    },
    close: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      }
    }
  };

  // API Helper
  window.FinanceAPI = {
    request: async function(endpoint, method = 'GET', data = null) {
      const options = {
        method: method,
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      };

      if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
        options.body = JSON.stringify(data);
      }

      try {
        const response = await fetch(endpoint, options);
        
        if (response.status === 401) {
          window.location.href = '/login.php';
          return null;
        }

        const result = await response.json();
        
        if (!result.ok && result.error) {
          console.error('API Error:', result.error);
        }

        return result;
      } catch (error) {
        console.error('Network error:', error);
        return { ok: false, error: 'Network error. Please check your connection.' };
      }
    }
  };

  // Currency Formatter
  window.formatCurrency = function(cents) {
    const amount = cents / 100;
    return 'R ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  };

  // Date Formatter
  window.formatDate = function(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-ZA', { 
      year: 'numeric', 
      month: 'short', 
      day: 'numeric' 
    });
  };

  // Show Message
  window.showMessage = function(containerId, message, type = 'success') {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = `
      <div class="fw-finance__form-message fw-finance__form-message--${type}">
        ${message}
      </div>
    `;

    setTimeout(() => {
      container.innerHTML = '';
    }, 5000);
  };

  // Debounce Helper
  window.debounce = function(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  };

  // Load Dashboard Stats (for index.php)
  async function loadDashboardStats() {
    if (!document.getElementById('cashStat')) return;

    try {
      const result = await FinanceAPI.request('/finances/ajax/dashboard_stats.php');
      
      if (result.ok) {
        const stats = result.data;
        
        document.querySelector('#cashStat .fw-finance__stat-value').textContent = 
          formatCurrency(stats.cash_cents || 0);
        
        document.querySelector('#arStat .fw-finance__stat-value').textContent = 
          formatCurrency(stats.ar_cents || 0);
        
        document.querySelector('#apStat .fw-finance__stat-value').textContent = 
          formatCurrency(stats.ap_cents || 0);
        
        document.querySelector('#vatStat .fw-finance__stat-value').textContent = 
          formatCurrency(stats.vat_due_cents || 0);
      }
    } catch (error) {
      console.error('Failed to load dashboard stats:', error);
    }
  }

  // Load Recent Activity (for index.php)
  async function loadRecentActivity() {
    const container = document.getElementById('activityList');
    if (!container) return;

    container.innerHTML = '<div class="fw-finance__loading">Loading activity...</div>';

    try {
      const result = await FinanceAPI.request('/finances/ajax/recent_activity.php');
      
      if (result.ok && result.data.length > 0) {
        container.innerHTML = result.data.map(item => `
          <div class="fw-finance__activity-item">
            <div class="fw-finance__activity-icon">${item.icon || '📄'}</div>
            <div class="fw-finance__activity-content">
              <div class="fw-finance__activity-title">${item.title}</div>
              <div class="fw-finance__activity-meta">${item.meta}</div>
            </div>
          </div>
        `).join('');
      } else {
        container.innerHTML = '<div class="fw-finance__empty-state">No recent activity</div>';
      }
    } catch (error) {
      container.innerHTML = '<div class="fw-finance__empty-state">Failed to load activity</div>';
    }
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    loadDashboardStats();
    loadRecentActivity();
  });

})();