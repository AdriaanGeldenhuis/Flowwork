// /finances/assets/finance.js
// Core Finance Module JavaScript — shared runtime for all fw-finance pages.
// Dimension 3D glossy port: the FIN3D engine block (tilt/glare, count-up,
// sparklines, toasts) is ported from /qi/assets/qi.js, whose QI3D block was
// itself ported from /crm/assets/crm.js.

window.FIN3D = window.FIN3D || {};

(function() {
  'use strict';

  const THEME_COOKIE_NAME = 'fw_theme';
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
    return match ? match[2] : null;
  }

  function setCookie(name, value, days = 365) {
    const date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    document.cookie = name + '=' + value + ';expires=' + date.toUTCString() + ';path=/;SameSite=Lax';
  }

  // ========== THEME ==========
  // Contract: shared fw_theme cookie + data-theme attribute on the .fw-finance
  // element (body on index.php, <main> elsewhere) + #themeToggle/#themeIndicator.
  // Cookie-less default is dark — parity with the CRM/QI Dimension ports.
  const root = document.querySelector('.fw-finance');
  const themeToggle = document.getElementById('themeToggle');
  const themeIndicator = document.getElementById('themeIndicator');

  function applyTheme(theme, save = true) {
    if (!root) return;
    root.setAttribute('data-theme', theme);
    if (themeIndicator) {
      themeIndicator.textContent = `Theme: ${theme.charAt(0).toUpperCase() + theme.slice(1)}`;
    }
    if (save) setCookie(THEME_COOKIE_NAME, theme);
  }

  function initTheme() {
    if (!root) return;
    const savedTheme = getCookie(THEME_COOKIE_NAME) || 'dark';
    applyTheme(savedTheme, false);
  }

  initTheme();

  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      if (!root) return;
      const currentTheme = root.getAttribute('data-theme') || 'dark';
      const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
      applyTheme(newTheme, true);

      // Charts hold theme-resolved colours: destroy them so page scripts can
      // rebuild against the new palette on the fin:theme event.
      if (window.chartInstances) {
        Object.values(window.chartInstances).forEach(function(chart) {
          if (chart && typeof chart.destroy === 'function') chart.destroy();
        });
        window.chartInstances = {};
      }

      document.dispatchEvent(new CustomEvent('fin:theme', { detail: { theme: newTheme } }));
      if (window.FIN3D.redrawSparks) window.FIN3D.redrawSparks();
    });
  }

  // ========== KEBAB MENU ==========
  // aria-driven: aria-expanded on the toggle, aria-hidden on the menu.
  // Closes on outside click and Escape (returning focus to the toggle).
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

  // ========== MODAL MANAGEMENT (contract — do not alter) ==========
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

  // ========== API HELPER (contract — do not alter) ==========
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

      // Attach CSRF token on state-changing requests
      if (method !== 'GET') {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
          options.headers['X-CSRF-Token'] = csrfMeta.content;
        }
      }

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

  // ========== FORMATTERS & HELPERS (contract — do not alter) ==========

  // HTML Entity Escaping (XSS protection for dynamic table rendering)
  window.escapeHtml = function(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
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

  // Show Message — inline form banners (fw-finance__form-message--{type}).
  // Kept as-is: page scripts rely on this exact markup; toasts are additive.
  window.showMessage = function(containerId, message, type = 'success') {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '<div class="fw-finance__form-message fw-finance__form-message--' + escapeHtml(type) + '">' + escapeHtml(message) + '</div>';

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

  // ========== FIN3D: TOASTS ==========
  // Stack is created on first use. Entrance is a CSS keyframe (toast is
  // visible immediately on append); exit adds --out then removes after 300ms.
  // CSS white-space: pre-line renders \n in messages as line breaks.
  window.FIN3D.toast = function(message, type) {
    type = (type === 'error' || type === 'info') ? type : 'success';
    let stack = document.getElementById('finToastStack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'finToastStack';
      stack.className = 'fw-finance__toast-stack';
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'fw-finance__toast fw-finance__toast--' + type;
    toast.textContent = message;
    stack.appendChild(toast);
    setTimeout(() => {
      toast.classList.add('fw-finance__toast--out');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  };

  window.FIN3D.escapeHtml = window.escapeHtml;

  // ========== FIN3D: DIMENSION 3D TILT ENGINE ==========
  // Delegated pointer engine: works for cards rendered at any time (list
  // views load via fetch, so per-card binding at DOMContentLoaded never sees
  // them). Instead of writing inline transforms, it feeds the CSS custom
  // properties (--rx/--ry for tilt, --mx/--my for the specular glare) that
  // finance.css composes into the card transform.
  function init3DTilt() {
    if (REDUCE_MOTION) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const SELECTOR = '.fw-finance__kpi-card, [data-tilt]';
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
    const logoTile = document.querySelector('.fw-finance__logo-tile');
    if (!logoTile || REDUCE_MOTION) return;

    logoTile.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.05) rotate(-3deg)';
    });

    logoTile.addEventListener('mouseleave', function() {
      this.style.transform = '';
    });
  }

  // ========== FIN3D: COUNT-UP ==========
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

  // ========== FIN3D: SPARKLINES ==========
  // Tiny dependency-free sparklines for canvases marked with data-spark
  // (JSON array of numbers, >= 2 points). Redrawn on theme toggle via
  // FIN3D.redrawSparks(). data-spark-color must be 6-digit hex (the gradient
  // stops are built by appending alpha digits to the colour string).
  const SPARK_DEFAULT_COLOR = '#4ade80';
  const SPARK_HEX_RE = /^#[0-9a-fA-F]{6}$/;

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

    const colorAttr = canvas.getAttribute('data-spark-color');
    const color = (colorAttr && SPARK_HEX_RE.test(colorAttr)) ? colorAttr : SPARK_DEFAULT_COLOR;
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
  window.FIN3D.redrawSparks = initSparklines;

  // ========== DASHBOARD (index.php) — contract, leave as-is ==========

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

        // Bank reconciliation counter
        var bankEl = document.querySelector('#bankStat .fw-finance__stat-value');
        if (bankEl) {
          var count = stats.bank_unrec_count || 0;
          bankEl.textContent = count;
          var badge = document.getElementById('bankBadge');
          if (badge) {
            if (count > 0) {
              badge.textContent = count;
              badge.style.display = 'inline';
            } else {
              badge.style.display = 'none';
            }
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
        container.innerHTML = result.data.map(function(item) {
          return '<div class="fw-finance__activity-item"><div class="fw-finance__activity-icon">' + escapeHtml(item.icon || '📄') + '</div><div class="fw-finance__activity-content"><div class="fw-finance__activity-title">' + escapeHtml(item.title) + '</div><div class="fw-finance__activity-meta">' + escapeHtml(item.meta) + '</div></div></div>';
        }).join('');
      } else {
        container.innerHTML = '<div class="fw-finance__empty-state">No recent activity</div>';
      }
    } catch (error) {
      container.innerHTML = '<div class="fw-finance__empty-state">Failed to load activity</div>';
    }
  }

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    init3DTilt();
    initLogoTileEffect();
    initCountUp();
    initSparklines();
    loadDashboardStats();
    loadRecentActivity();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
