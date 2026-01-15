// /crm/assets/crm.js - COMPLETE WITH PLAYGROUND

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

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-crm');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || THEME_DARK;
    applyTheme(theme);

    toggle.addEventListener('click', () => {
      theme = theme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(theme);
      setCookie(THEME_COOKIE, theme);
      
      // Rebuild charts with new theme
      if (window.chartInstances && Object.keys(window.chartInstances).length > 0) {
        Object.values(window.chartInstances).forEach(chart => chart.destroy());
        if (typeof buildPlayground === 'function') {
          buildPlayground();
        }
      }
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
  }

  // ========== ACCOUNT LIST ==========
  function initAccountList() {
    const listContainer = document.getElementById('accountsList');
    const searchInput = document.getElementById('searchInput');
    const filterStatus = document.getElementById('filterStatus');
    const filterIndustry = document.getElementById('filterIndustry');
    const filterRegion = document.getElementById('filterRegion');

    if (!listContainer) return;

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'suppliers';
    const accountType = activeTab === 'suppliers' ? 'supplier' : 'customer';

    function loadAccounts() {
      const search = searchInput ? searchInput.value : '';
      const status = filterStatus ? filterStatus.value : '';
      const industry = filterIndustry ? filterIndustry.value : '';
      const region = filterRegion ? filterRegion.value : '';

      const params = new URLSearchParams({
        type: accountType,
        search: search,
        status: status,
        industry: industry,
        region: region
      });

      listContainer.innerHTML = '<div class="fw-crm__loading">Loading accounts...</div>';

      fetch('/crm/ajax/search.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            renderAccounts(data.accounts);
          } else {
            listContainer.innerHTML = '<div class="fw-crm__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-crm__loading">Network error</div>';
          console.error(err);
        });
    }

    function renderAccounts(accounts) {
      if (accounts.length === 0) {
        listContainer.innerHTML = '<div class="fw-crm__loading">No accounts found</div>';
        return;
      }

      const html = accounts.map(acc => {
        const initials = (acc.name || '?').substring(0, 2).toUpperCase();
        const avatarClass = accountType === 'supplier' ? 'fw-crm__account-avatar--supplier' : 'fw-crm__account-avatar--customer';
        
        const tags = (acc.tags || []).map(tag => 
          '<span class="fw-crm__tag" style="background:' + (tag.color || '#06b6d4') + '">' + tag.name + '</span>'
        ).join('');

        return `
          <a href="/crm/account_view.php?id=${acc.id}" class="fw-crm__account-card">
            <div class="fw-crm__account-avatar ${avatarClass}">${initials}</div>
            <div class="fw-crm__account-info">
              <div class="fw-crm__account-name">${acc.name}</div>
              <div class="fw-crm__account-meta">
                ${acc.primary_contact || 'No contact'} • ${acc.email || 'No email'}
              </div>
              <div class="fw-crm__account-tags">${tags}</div>
            </div>
          </a>
        `;
      }).join('');

      listContainer.innerHTML = html;
    }

    // Initial load
    if (activeTab === 'suppliers' || activeTab === 'customers') {
      loadAccounts();
    }

    // Event listeners
    if (searchInput) {
      searchInput.addEventListener('input', debounce(loadAccounts, 300));
    }
    if (filterStatus) {
      filterStatus.addEventListener('change', loadAccounts);
    }
    if (filterIndustry) {
      filterIndustry.addEventListener('change', loadAccounts);
    }
    if (filterRegion) {
      filterRegion.addEventListener('change', loadAccounts);
    }
  }

  // ========== MODAL SYSTEM ==========
  window.CRMModal = {open: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.add('fw-crm__modal-overlay--active');
        document.body.style.overflow = 'hidden';
      }
    },
    close: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.remove('fw-crm__modal-overlay--active');
        document.body.style.overflow = '';
      }
    }
  };

  // Close modals on overlay click
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('fw-crm__modal-overlay')) {
      e.target.classList.remove('fw-crm__modal-overlay--active');
      document.body.style.overflow = '';
    }
  });

  // Close modals on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.fw-crm__modal-overlay--active').forEach(modal => {
        modal.classList.remove('fw-crm__modal-overlay--active');
        document.body.style.overflow = '';
      });
    }
  });

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    initAccountList();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();