// /crm/assets/crm.js - COMPLETE WITH PLAYGROUND
window.CRM = window.CRM || {};

(function() {
  'use strict';

  // ========== TOASTS ==========
  // CRM.toast('Saved', 'success' | 'error') — non-blocking feedback for async
  // actions. Falls back gracefully if called before DOM is ready.
  window.CRM.toast = function(message, type) {
    type = type === 'error' ? 'error' : 'success';
    let stack = document.getElementById('crmToastStack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'crmToastStack';
      stack.className = 'fw-crm__toast-stack';
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'fw-crm__toast fw-crm__toast--' + type;
    toast.textContent = message;
    stack.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('fw-crm__toast--visible'));
    setTimeout(() => {
      toast.classList.remove('fw-crm__toast--visible');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  };

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
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }
  window.CRM.escapeHtml = escapeHtml;

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
        window.chartInstances = {};
        if (typeof buildPlayground === 'function') {
          buildPlayground();
        }
      }

      // Let page-level scripts (dashboard charts, sparklines) re-render
      document.dispatchEvent(new CustomEvent('crm:theme', { detail: { theme } }));
      if (window.CRM.redrawSparks) window.CRM.redrawSparks();
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
        const rowClass = isDeleted ? 'fw-crm__account-row fw-crm__account-row--deleted' : 'fw-crm__account-row';
        const deletedBadge = isDeleted ? '<span class="fw-crm__badge fw-crm__badge--deleted">Deleted</span>' : '';

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

      updateBulkBar();
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
          <select class="fw-crm__select fw-crm__bulk-action-select">
            <option value="">Choose action...</option>
            <optgroup label="Change status">
              <option value="status:active">Set status: Active</option>
              <option value="status:inactive">Set status: Inactive</option>
              <option value="status:prospect">Set status: Prospect</option>
              <option value="status:banned">Set status: Banned</option>
            </optgroup>
            <optgroup label="Lifecycle">
              <option value="soft_delete">Delete</option>
              <option value="restore">Restore</option>
            </optgroup>
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
      const actionSelect = bar.querySelector('.fw-crm__bulk-action-select');
      const value = actionSelect.value;
      if (!value) { CRM.toast('Choose an action first', 'error'); return; }

      const params = new URLSearchParams();
      let confirmMsg;
      if (value.startsWith('status:')) {
        const newStatus = value.slice('status:'.length);
        confirmMsg = 'Change status of ' + selectedIds.size + ' account(s) to "' + newStatus + '"?';
        params.append('action', 'update_status');
        params.append('status', newStatus);
      } else if (value === 'soft_delete') {
        confirmMsg = 'Delete ' + selectedIds.size + ' account(s)? They can be restored later.';
        params.append('action', 'soft_delete');
      } else if (value === 'restore') {
        confirmMsg = 'Restore ' + selectedIds.size + ' account(s)?';
        params.append('action', 'restore');
      } else {
        alert('Unknown action');
        return;
      }
      if (!confirm(confirmMsg)) return;
      selectedIds.forEach(id => params.append('ids[]', id));

      fetch('/crm/ajax/bulk_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
      .then(res => res.json())
      .then(data => {
        if (data.ok) {
          const n = data.affected || data.updated || '';
          CRM.toast((n ? n + ' ' : '') + 'account(s) updated');
          selectedIds.clear();
          loadAccounts(currentPage);
        } else {
          CRM.toast(data.error || 'Bulk action failed', 'error');
        }
      })
      .catch(() => CRM.toast('Network error', 'error'));
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
  // The stylesheet only displays overlays via [aria-hidden="false"]
  // (display:none !important otherwise), so open/close MUST drive that
  // attribute — the --active class alone never showed anything.
  window.CRMModal = {open: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.add('fw-crm__modal-overlay--active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
      }
    },
    close: function(modalId) {
      const modal = document.getElementById(modalId);
      if (modal) {
        modal.classList.remove('fw-crm__modal-overlay--active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      }
    }
  };

  // Close modals on overlay click
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('fw-crm__modal-overlay')) {
      e.target.classList.remove('fw-crm__modal-overlay--active');
      e.target.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }
  });

  // Close modals on Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.fw-crm__modal-overlay--active').forEach(modal => {
        modal.classList.remove('fw-crm__modal-overlay--active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      });
    }
  });

  // ========== DIMENSION 3D ENGINE ==========
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Delegated pointer engine: works for cards rendered at any time (account
  // lists load via fetch, so per-card binding at DOMContentLoaded never sees
  // them). Instead of writing inline transforms, it feeds the CSS custom
  // properties (--rx/--ry for tilt, --mx/--my for the specular glare) that
  // crm.css composes into the card transform.
  function init3DTilt() {
    if (REDUCE_MOTION) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const SELECTOR = '.fw-crm__account-card, .fw-crm__contact-card, .fw-crm__address-card, .fw-crm__kpi-card';
    const MAX_TILT = 6; // degrees
    let activeCard = null;
    let lastEvent = null;
    let rafId = 0;

    function applyFrame() {
      rafId = 0;
      if (!activeCard || !lastEvent) return;

      const rect = activeCard.getBoundingClientRect();
      if (!rect.width || !rect.height) return;

      const px = (lastEvent.clientX - rect.left) / rect.width;
      const py = (lastEvent.clientY - rect.top) / rect.height;
      const rx = (0.5 - py) * MAX_TILT;
      const ry = (px - 0.5) * MAX_TILT;

      activeCard.style.setProperty('--rx', rx.toFixed(2) + 'deg');
      activeCard.style.setProperty('--ry', ry.toFixed(2) + 'deg');
      activeCard.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
      activeCard.style.setProperty('--my', (py * 100).toFixed(1) + '%');
    }

    function resetCard(card) {
      if (!card) return;
      card.style.removeProperty('--rx');
      card.style.removeProperty('--ry');
      card.style.removeProperty('--mx');
      card.style.removeProperty('--my');
    }

    document.addEventListener('pointermove', function(e) {
      if (e.buttons > 0) return; // don't tilt mid-drag / text selection
      const card = e.target && e.target.closest ? e.target.closest(SELECTOR) : null;

      if (card !== activeCard) {
        resetCard(activeCard);
        activeCard = card;
      }
      if (!card) return;

      lastEvent = e;
      if (!rafId) rafId = requestAnimationFrame(applyFrame);
    }, { passive: true });

    // Pointer left the page entirely (no pointermove fires on the way out)
    document.documentElement.addEventListener('pointerleave', function() {
      resetCard(activeCard);
      activeCard = null;
    });
  }

  function initLogoTileEffect() {
    const logoTile = document.querySelector('.fw-crm__logo-tile');
    if (!logoTile || REDUCE_MOTION) return;

    logoTile.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.05) rotate(-3deg)';
    });

    logoTile.addEventListener('mouseleave', function() {
      this.style.transform = '';
    });
  }

  // Animated count-up for stat values marked with data-countup.
  // Supports prefix/suffix (R, %, k, M) via data-prefix/data-suffix and
  // decimals via data-decimals. Falls back to instant text when motion is off.
  function initCountUp() {
    const els = document.querySelectorAll('[data-countup]');
    if (!els.length) return;

    els.forEach(function(el) {
      const target = parseFloat(el.getAttribute('data-countup'));
      if (isNaN(target)) return;
      const prefix = el.getAttribute('data-prefix') || '';
      const suffix = el.getAttribute('data-suffix') || '';
      const decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);

      function fmt(v) {
        return prefix + v.toLocaleString(undefined, {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        }) + suffix;
      }

      if (REDUCE_MOTION) {
        el.textContent = fmt(target);
        return;
      }

      const duration = 900;
      const start = performance.now();

      function tick(now) {
        const p = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = fmt(target * eased);
        if (p < 1) requestAnimationFrame(tick);
      }

      requestAnimationFrame(tick);
    });
  }

  // Tiny dependency-free sparklines for canvases marked with data-spark
  // (JSON array of numbers). Redrawn on theme toggle via CRM.redrawSparks().
  function drawSparkline(canvas) {
    let values;
    try {
      values = JSON.parse(canvas.getAttribute('data-spark'));
    } catch (e) {
      return;
    }
    if (!Array.isArray(values) || values.length < 2) return;

    const dpr = window.devicePixelRatio || 1;
    const cssWidth = canvas.clientWidth || 120;
    const cssHeight = canvas.clientHeight || 34;
    canvas.width = cssWidth * dpr;
    canvas.height = cssHeight * dpr;

    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, cssWidth, cssHeight);

    const color = canvas.getAttribute('data-spark-color') || '#06b6d4';
    const max = Math.max.apply(null, values);
    const min = Math.min.apply(null, values);
    const range = (max - min) || 1;
    const stepX = cssWidth / (values.length - 1);
    const pad = 3;

    function ptY(v) {
      return cssHeight - pad - ((v - min) / range) * (cssHeight - pad * 2);
    }

    // Soft area fill
    const grad = ctx.createLinearGradient(0, 0, 0, cssHeight);
    grad.addColorStop(0, color + '55');
    grad.addColorStop(1, color + '00');
    ctx.beginPath();
    ctx.moveTo(0, cssHeight);
    values.forEach(function(v, i) { ctx.lineTo(i * stepX, ptY(v)); });
    ctx.lineTo(cssWidth, cssHeight);
    ctx.closePath();
    ctx.fillStyle = grad;
    ctx.fill();

    // Line
    ctx.beginPath();
    values.forEach(function(v, i) {
      if (i === 0) ctx.moveTo(0, ptY(v));
      else ctx.lineTo(i * stepX, ptY(v));
    });
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.shadowColor = color;
    ctx.shadowBlur = 6;
    ctx.stroke();
    ctx.shadowBlur = 0;

    // End dot
    ctx.beginPath();
    ctx.arc(cssWidth - 1.5, ptY(values[values.length - 1]), 2.5, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.fill();
  }

  function initSparklines() {
    document.querySelectorAll('canvas[data-spark]').forEach(drawSparkline);
  }
  window.CRM.redrawSparks = initSparklines;

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    initAccountList();
    init3DTilt();
    initLogoTileEffect();
    initCountUp();
    initSparklines();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();