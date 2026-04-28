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

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // Auto-inject CSRF token into all fetch POST/PUT/DELETE requests
  const _origFetch = window.fetch;
  window.fetch = function(url, options) {
    options = options || {};
    const method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
      const token = getCsrfToken();
      if (token) {
        if (options.headers instanceof Headers) {
          options.headers.set('X-CSRF-TOKEN', token);
        } else {
          options.headers = options.headers || {};
          options.headers['X-CSRF-TOKEN'] = token;
        }
      }
    }
    return _origFetch.call(window, url, options);
  };

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
    const filterDeleted = document.getElementById('filterDeleted');

    if (!listContainer) return;

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'suppliers';
    const accountType = activeTab === 'suppliers' ? 'supplier' : 'customer';
    let currentPage = 1;
    let selectedIds = new Set();

    function loadAccounts(page) {
      currentPage = page || 1;
      const search = searchInput ? searchInput.value : '';
      const status = filterStatus ? filterStatus.value : '';
      const industry = filterIndustry ? filterIndustry.value : '';
      const region = filterRegion ? filterRegion.value : '';
      const deleted = filterDeleted ? filterDeleted.value : '0';

      const params = new URLSearchParams({
        type: accountType,
        search: search,
        status: status,
        industry: industry,
        region: region,
        deleted: deleted,
        page: currentPage
      });

      listContainer.innerHTML = '<div class="fw-crm__loading">Loading accounts...</div>';

      fetch('/crm/ajax/search.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            renderAccounts(data.accounts, data.page, data.total_pages, data.total);
          } else {
            listContainer.innerHTML = '<div class="fw-crm__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-crm__loading">Network error</div>';
          console.error(err);
        });
    }

    function renderAccounts(accounts, page, totalPages, total) {
      if (accounts.length === 0) {
        listContainer.innerHTML = '<div class="fw-crm__loading">No accounts found</div>';
        updateBulkBar();
        return;
      }

      const cardsHtml = accounts.map(acc => {
        const safeName = escapeHtml(acc.name || '?');
        const initials = (acc.name || '?').substring(0, 2).toUpperCase();
        const avatarClass = accountType === 'supplier' ? 'fw-crm__account-avatar--supplier' : 'fw-crm__account-avatar--customer';
        const checked = selectedIds.has(String(acc.id)) ? 'checked' : '';
        const isDeleted = !!acc.deleted_at;
        const deletedChecked = isDeleted ? 'checked' : '';
        const rowClass = isDeleted ? 'fw-crm__account-row fw-crm__account-row--deleted' : 'fw-crm__account-row';
        const deletedBadge = isDeleted ? '<span class="fw-crm__badge fw-crm__badge--deleted">Deleted</span>' : '';
        const deletedTitle = isDeleted ? 'Untick to restore this account' : 'Tick to mark this account as deleted';

        const tags = (acc.tags || []).map(tag =>
          '<span class="fw-crm__tag" style="background:' + escapeHtml(tag.color || '#06b6d4') + '">' + escapeHtml(tag.name) + '</span>'
        ).join('');

        return `
          <div class="${rowClass}">
            <label class="fw-crm__bulk-check" title="Select for bulk action">
              <input type="checkbox" class="fw-crm__checkbox fw-crm__bulk-cb" data-id="${parseInt(acc.id, 10)}" ${checked}>
            </label>
            <a href="/crm/account_view.php?id=${parseInt(acc.id, 10)}" class="fw-crm__account-card">
              <div class="fw-crm__account-avatar ${avatarClass}">${escapeHtml(initials)}</div>
              <div class="fw-crm__account-info">
                <div class="fw-crm__account-name">${safeName} ${deletedBadge}</div>
                <div class="fw-crm__account-meta">
                  ${escapeHtml(acc.primary_contact || 'No contact')} &bull; ${escapeHtml(acc.email || 'No email')}
                </div>
                <div class="fw-crm__account-tags">${tags}</div>
              </div>
            </a>
            <label class="fw-crm__delete-check" title="${deletedTitle}">
              <input type="checkbox" class="fw-crm__checkbox fw-crm__delete-cb" data-id="${parseInt(acc.id, 10)}" ${deletedChecked}>
              <span>Deleted</span>
            </label>
          </div>
        `;
      }).join('');

      // Pagination controls
      let paginationHtml = '';
      if (totalPages > 1) {
        paginationHtml = '<div class="fw-crm__pagination">';
        paginationHtml += '<span class="fw-crm__pagination-info">' + total + ' results &middot; Page ' + page + ' of ' + totalPages + '</span>';
        paginationHtml += '<div class="fw-crm__pagination-btns">';
        if (page > 1) {
          paginationHtml += '<button class="fw-crm__btn fw-crm__btn--secondary fw-crm__page-btn" data-page="' + (page - 1) + '">&laquo; Prev</button>';
        }
        // Show max 5 page buttons around current
        const startP = Math.max(1, page - 2);
        const endP = Math.min(totalPages, page + 2);
        for (let i = startP; i <= endP; i++) {
          const active = i === page ? ' fw-crm__btn--primary' : ' fw-crm__btn--secondary';
          paginationHtml += '<button class="fw-crm__btn' + active + ' fw-crm__page-btn" data-page="' + i + '">' + i + '</button>';
        }
        if (page < totalPages) {
          paginationHtml += '<button class="fw-crm__btn fw-crm__btn--secondary fw-crm__page-btn" data-page="' + (page + 1) + '">Next &raquo;</button>';
        }
        paginationHtml += '</div></div>';
      }

      listContainer.innerHTML = cardsHtml + paginationHtml;

      // Attach pagination click handlers
      listContainer.querySelectorAll('.fw-crm__page-btn').forEach(btn => {
        btn.addEventListener('click', () => loadAccounts(parseInt(btn.dataset.page, 10)));
      });

      // Attach bulk checkbox handlers
      listContainer.querySelectorAll('.fw-crm__bulk-cb').forEach(cb => {
        cb.addEventListener('change', function(e) {
          e.stopPropagation();
          const id = this.dataset.id;
          if (this.checked) {
            selectedIds.add(id);
          } else {
            selectedIds.delete(id);
          }
          updateBulkBar();
        });
      });

      // Attach inline delete-tickbox handlers (per-row soft delete / restore)
      listContainer.querySelectorAll('.fw-crm__delete-cb').forEach(cb => {
        // Don't let clicks on the checkbox bubble up to the parent <a>
        cb.addEventListener('click', e => e.stopPropagation());
        cb.addEventListener('change', function(e) {
          e.stopPropagation();
          const id = parseInt(this.dataset.id, 10);
          const willDelete = this.checked;
          const verb = willDelete ? 'delete' : 'restore';
          if (!confirm('Are you sure you want to ' + verb + ' this account?')) {
            this.checked = !willDelete;
            return;
          }
          toggleDeleted(id, willDelete, this);
        });
      });

      updateBulkBar();
    }

    function toggleDeleted(id, willDelete, cb) {
      const params = new URLSearchParams();
      params.append('action', willDelete ? 'soft_delete' : 'restore');
      params.append('ids[]', id);

      cb.disabled = true;
      fetch('/crm/ajax/bulk_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            loadAccounts(currentPage);
          } else {
            cb.checked = !willDelete;
            cb.disabled = false;
            alert(data.error || 'Action failed');
          }
        })
        .catch(() => {
          cb.checked = !willDelete;
          cb.disabled = false;
          alert('Network error');
        });
    }

    // Bulk action bar
    function updateBulkBar() {
      let bar = document.getElementById('bulkBar');
      if (selectedIds.size === 0) {
        if (bar) bar.style.display = 'none';
        return;
      }
      if (!bar) {
        bar = document.createElement('div');
        bar.id = 'bulkBar';
        bar.className = 'fw-crm__bulk-bar';
        bar.innerHTML = `
          <span class="fw-crm__bulk-count"></span>
          <select class="fw-crm__select fw-crm__bulk-status-select">
            <option value="">Change Status...</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="prospect">Prospect</option>
          </select>
          <button class="fw-crm__btn fw-crm__btn--secondary fw-crm__bulk-apply">Apply</button>
          <button class="fw-crm__btn fw-crm__btn--secondary fw-crm__bulk-clear">Clear</button>
        `;
        listContainer.parentElement.insertBefore(bar, listContainer);

        bar.querySelector('.fw-crm__bulk-apply').addEventListener('click', applyBulkAction);
        bar.querySelector('.fw-crm__bulk-clear').addEventListener('click', () => {
          selectedIds.clear();
          listContainer.querySelectorAll('.fw-crm__bulk-cb').forEach(cb => cb.checked = false);
          updateBulkBar();
        });
      }
      bar.style.display = 'flex';
      bar.querySelector('.fw-crm__bulk-count').textContent = selectedIds.size + ' selected';
    }

    function applyBulkAction() {
      const bar = document.getElementById('bulkBar');
      const statusSelect = bar.querySelector('.fw-crm__bulk-status-select');
      const newStatus = statusSelect.value;
      if (!newStatus) { alert('Select a status first'); return; }
      if (!confirm('Change status of ' + selectedIds.size + ' account(s) to "' + newStatus + '"?')) return;

      const params = new URLSearchParams();
      params.append('action', 'update_status');
      params.append('status', newStatus);
      selectedIds.forEach(id => params.append('ids[]', id));

      fetch('/crm/ajax/bulk_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          selectedIds.clear();
          loadAccounts(currentPage);
        } else {
          alert(data.error || 'Bulk action failed');
        }
      })
      .catch(() => alert('Network error'));
    }

    // Initial load
    if (activeTab === 'suppliers' || activeTab === 'customers') {
      loadAccounts(1);
    }

    // Event listeners — reset to page 1 on filter/search changes
    if (searchInput) {
      searchInput.addEventListener('input', debounce(() => loadAccounts(1), 300));
    }
    if (filterStatus) {
      filterStatus.addEventListener('change', () => loadAccounts(1));
    }
    if (filterIndustry) {
      filterIndustry.addEventListener('change', () => loadAccounts(1));
    }
    if (filterRegion) {
      filterRegion.addEventListener('change', () => loadAccounts(1));
    }
    if (filterDeleted) {
      filterDeleted.addEventListener('change', () => loadAccounts(1));
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