// /finances/assets/finance.js
// Core Finance Module JavaScript

(function() {
  'use strict';

  console.log('Finance.js loaded');

  // ========== THEME MANAGEMENT (FIXED) ==========
  const themeToggle = document.getElementById('themeToggle');
  const root = document.querySelector('.fw-finance');

  console.log('Theme toggle:', themeToggle);
  console.log('Root element:', root);

  function initTheme() {
    if (!root) return;
    
    const savedTheme = localStorage.getItem('fw-finance-theme') || 'light';
    console.log('Loading saved theme:', savedTheme);
    applyTheme(savedTheme, false);
  }

  function applyTheme(theme, save = true) {
    if (!root) return;
    
    root.setAttribute('data-theme', theme);
    console.log('Applied theme:', theme);
    
    if (save) {
      localStorage.setItem('fw-finance-theme', theme);
      console.log('Saved theme to localStorage:', theme);
    }
  }

  if (themeToggle) {
    console.log('Theme toggle button found, attaching listener');
    
    themeToggle.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      
      const currentTheme = root.getAttribute('data-theme') || 'light';
      const newTheme = currentTheme === 'light' ? 'dark' : 'light';
      
      console.log('Toggling theme:', currentTheme, '->', newTheme);
      applyTheme(newTheme, true);
    });
  } else {
    console.error('Theme toggle button NOT found!');
  }

  // Kebab Menu
  const kebabToggle = document.getElementById('kebabToggle');
  const kebabMenu = document.getElementById('kebabMenu');

  console.log('Kebab toggle:', kebabToggle);
  console.log('Kebab menu:', kebabMenu);

  if (kebabToggle && kebabMenu) {
    kebabToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      const isHidden = kebabMenu.getAttribute('aria-hidden') === 'true';
      kebabMenu.setAttribute('aria-hidden', !isHidden);
      console.log('Kebab menu toggled, hidden:', !isHidden);
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
        console.log('Modal opened:', modalId);
      }
    },
    close: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        console.log('Modal closed:', modalId);
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
        console.log('API Request:', endpoint, method, data);
        const response = await fetch(endpoint, options);
        
        if (response.status === 401) {
          window.location.href = '/login.php';
          return null;
        }

        const result = await response.json();
        console.log('API Response:', result);
        
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
        
        const cashValue = document.querySelector('#cashStat .fw-finance__stat-value');
        const arValue   = document.querySelector('#arStat .fw-finance__stat-value');
        const apValue   = document.querySelector('#apStat .fw-finance__stat-value');
        const plValue   = document.querySelector('#plStat .fw-finance__stat-value');
        const vatValue  = document.querySelector('#vatStat .fw-finance__stat-value');
        const bankValue = document.querySelector('#bankStat .fw-finance__stat-value');
        const bankBadge = document.getElementById('bankBadge');

        if (cashValue) cashValue.textContent = formatCurrency(stats.cash_cents || 0);
        if (arValue)   arValue.textContent   = formatCurrency(stats.ar_cents || 0);
        if (apValue)   apValue.textContent   = formatCurrency(stats.ap_cents || 0);
        if (plValue)   plValue.textContent   = formatCurrency(stats.pl_month_cents || 0);
        if (vatValue)  vatValue.textContent  = formatCurrency(stats.vat_due_cents || 0);
        if (bankValue) bankValue.textContent = (stats.bank_unrec_count || 0).toString();
        // Show a badge for unreconciled bank transactions if any
        if (bankBadge) {
          const count = stats.bank_unrec_count || 0;
          if (count > 0) {
            bankBadge.textContent = count + ' unreconciled';
            bankBadge.style.display = 'inline-block';
            // Use warning/danger colour for emphasis
            bankBadge.classList.add('fw-finance__badge--overdue');
          } else {
            bankBadge.textContent = '';
            bankBadge.style.display = 'none';
            bankBadge.classList.remove('fw-finance__badge--overdue');
          }
        }
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

  // Initialize - RUN THEME FIRST!
  console.log('Initializing Finance Module...');
  initTheme(); // Apply theme IMMEDIATELY
  
  document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM Content Loaded');
    initTheme(); // Apply theme again after DOM ready (belt & braces)
    loadDashboardStats();
    loadRecentActivity();
  });

})();