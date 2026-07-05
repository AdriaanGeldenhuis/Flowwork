window.QI3D = window.QI3D || {};

(function() {
  'use strict';

  const THEME_COOKIE = 'fw_theme';
  const THEME_DARK = 'dark';
  const THEME_LIGHT = 'light';
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ========== TOASTS ==========
  window.QI3D.toast = function(message, type) {
    type = (type === 'error' || type === 'info') ? type : 'success';
    let stack = document.getElementById('qiToastStack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'qiToastStack';
      stack.className = 'fw-qi__toast-stack';
      stack.setAttribute('aria-live', 'polite');
      document.body.appendChild(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'fw-qi__toast fw-qi__toast--' + type;
    toast.textContent = message;
    stack.appendChild(toast);
    setTimeout(() => {
      toast.classList.add('fw-qi__toast--out');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  };

  // ========== UTILITIES ==========
  function esc(str) {
    const div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
  }
  window.QI3D.escapeHtml = esc;

  // Currency symbol for a list row. ZAR (or unknown) shows "R"; foreign
  // documents show their own symbol from window.QI_CURRENCY_SYMBOLS, or the
  // ISO code itself as a safe fallback.
  function moneySym(code) {
    if (!code || code === 'ZAR') return 'R';
    const map = window.QI_CURRENCY_SYMBOLS || {};
    return map[code] || code;
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
    const body = document.querySelector('.fw-qi');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || THEME_DARK;
    applyTheme(theme);

    toggle.addEventListener('click', () => {
      theme = theme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(theme);
      setCookie(THEME_COOKIE, theme);

      if (window.chartInstances) {
        Object.values(window.chartInstances).forEach(chart => {
          if (chart && typeof chart.destroy === 'function') chart.destroy();
        });
        window.chartInstances = {};
      }

      document.dispatchEvent(new CustomEvent('qi:theme', { detail: { theme } }));
      if (window.QI3D.redrawSparks) window.QI3D.redrawSparks();
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

  // ========== DIMENSION 3D ENGINE ==========
  // Delegated pointer engine: works for cards rendered at any time (list
  // views load via fetch, so per-card binding at DOMContentLoaded never sees
  // them). Instead of writing inline transforms, it feeds the CSS custom
  // properties (--rx/--ry for tilt, --mx/--my for the specular glare) that
  // qi.css composes into the card transform.
  function init3DTilt() {
    if (REDUCE_MOTION) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const SELECTOR = '.fw-qi__kpi-card, [data-tilt]';
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
    const logoTile = document.querySelector('.fw-qi__logo-tile');
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
  // (JSON array of numbers). Redrawn on theme toggle via QI3D.redrawSparks().
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

    const color = canvas.getAttribute('data-spark-color') || '#fbbf24';
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
  window.QI3D.redrawSparks = initSparklines;

  // ========== LIST VIEW (Quotes, Invoices, etc.) ==========
  function initList() {
    const listContainer = document.getElementById('qiList');
    const searchInput = document.getElementById('searchInput');
    const filterStatus = document.getElementById('filterStatus');
    const filterDateFrom = document.getElementById('filterDateFrom');
    const filterDateTo = document.getElementById('filterDateTo');

    if (!listContainer) return;

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'overview';

    if (activeTab === 'overview') return; // Skip if overview tab

    // Pagination state
    let currentPage = 1;
    const PAGE_SIZE = 20;
    let totalPages = 1;

    function getTypeFromTab(tab) {
        switch (tab) {
            case 'quotes': return 'quote';
            case 'invoices': return 'invoice';
            case 'recurring': return 'recurring';
            case 'credit_notes': return 'credit';
            default: return 'quote';
        }
    }

    // Pre-populate filters based on query string
    const urlParamsList = new URLSearchParams(window.location.search);
    const initialStatus = urlParamsList.get('status');
    if (filterStatus && initialStatus) {
        filterStatus.value = initialStatus;
    }

    function loadList() {
        const search = searchInput ? searchInput.value : '';
        const status = filterStatus ? filterStatus.value : '';
        const dateFrom = filterDateFrom ? filterDateFrom.value : '';
        const dateTo = filterDateTo ? filterDateTo.value : '';

        const type = getTypeFromTab(activeTab);
        const params = new URLSearchParams({
            type: type,
            q: search,
            status: status,
            page: currentPage,
            page_size: PAGE_SIZE
        });

        // Add date filters if present (not used server-side yet but retained)
        if (dateFrom) params.append('date_from', dateFrom);
        if (dateTo) params.append('date_to', dateTo);

        listContainer.innerHTML = `
            <div class="fw-qi__loading">
                <div class="fw-qi__spinner"></div>
                <p>Loading ${activeTab}...</p>
            </div>
        `;

        fetch('/qi/ajax/search.php?' + params.toString())
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    const rows = data.data && Array.isArray(data.data.rows) ? data.data.rows : [];
                    const total = data.data && typeof data.data.total !== 'undefined' ? data.data.total : 0;
                    totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                    if (rows.length > 0) {
                        // Render table and pagination
                        listContainer.innerHTML = renderList(rows, activeTab) + renderPagination();
                        attachPaginationEvents();
                    } else {
                        listContainer.innerHTML = renderEmptyState(activeTab);
                    }
                } else {
                    listContainer.innerHTML = '<div class="fw-qi__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
                }
            })
            .catch(err => {
                listContainer.innerHTML = '<div class="fw-qi__loading">Network error</div>';
            });
    }

    function renderPagination() {
        if (totalPages <= 1) return '';
        const prevDisabled = currentPage <= 1 ? 'disabled' : '';
        const nextDisabled = currentPage >= totalPages ? 'disabled' : '';
        return `
            <div class="fw-qi__pagination" style="margin-top: var(--fw-spacing-md); display: flex; justify-content: space-between; align-items: center;">
                <button class="fw-qi__btn fw-qi__btn--secondary" data-action="prev" ${prevDisabled}>Prev</button>
                <span>Page ${currentPage} of ${totalPages}</span>
                <button class="fw-qi__btn fw-qi__btn--secondary" data-action="next" ${nextDisabled}>Next</button>
            </div>
        `;
    }

    function attachPaginationEvents() {
        const pag = listContainer.querySelector('.fw-qi__pagination');
        if (!pag) return;
        pag.addEventListener('click', (e) => {
            const action = e.target.getAttribute('data-action');
            if (!action) return;
            if (action === 'prev' && currentPage > 1) {
                currentPage--;
                loadList();
            } else if (action === 'next' && currentPage < totalPages) {
                currentPage++;
                loadList();
            }
        });
    }

    function renderList(items, tab) {
        const headers = getHeaders(tab);
        
        let html = '<table class="fw-qi__table"><thead><tr>';
        headers.forEach(h => {
            html += `<th class="${h.align || ''}">${h.label}</th>`;
        });
        html += '</tr></thead><tbody>';

        items.forEach(item => {
            html += '<tr onclick="QIView.openItem(\'' + tab + '\', ' + parseInt(item.id) + ')">';
            headers.forEach(h => {
                let value = item[h.key] || '';
                if (h.key === 'status') {
                    // For recurring type, display status without badge; others use badge styling
                    if (tab === 'recurring') {
                        value = esc(item.status_label || item.status);
                    } else {
                        value = `<span class="fw-qi__badge fw-qi__badge--${esc(item.status)}">${esc(item.status_label || item.status)}</span>`;
                    }
                } else if (h.key === 'total' || h.key === 'balance_due') {
                    value = esc(moneySym(item.currency)) + ' ' + parseFloat(value || 0).toFixed(2);
                } else {
                    value = esc(value);
                }
                html += `<td class="${h.align || ''}">${value}</td>`;
            });
            html += '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function getHeaders(tab) {
        const headers = {
            'quotes': [
                { key: 'quote_number', label: '#', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'issue_date', label: 'Date', align: '' },
                { key: 'expiry_date', label: 'Expires', align: '' },
                { key: 'total', label: 'Amount', align: 'fw-qi__table-align-right' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ],
            'invoices': [
                { key: 'invoice_number', label: '#', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'issue_date', label: 'Date', align: '' },
                { key: 'due_date', label: 'Due', align: '' },
                { key: 'total', label: 'Total', align: 'fw-qi__table-align-right' },
                { key: 'balance_due', label: 'Balance', align: 'fw-qi__table-align-right' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ],
            'recurring': [
                { key: 'template_name', label: 'Name', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'frequency_label', label: 'Frequency', align: '' },
                { key: 'next_run_date', label: 'Next Run', align: '' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ],
            'credit_notes': [
                { key: 'credit_note_number', label: '#', align: '' },
                { key: 'customer_name', label: 'Customer', align: '' },
                { key: 'issue_date', label: 'Date', align: '' },
                { key: 'total', label: 'Amount', align: 'fw-qi__table-align-right' },
                { key: 'status', label: 'Status', align: 'fw-qi__table-align-center' }
            ]
        };
        return headers[tab] || headers['quotes'];
    }

    function renderEmptyState(tab) {
        const config = {
            'quotes': {
                icon: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
                title: 'No Quotes Yet',
                text: 'Create your first quote to get started',
                button: 'Create First Quote',
                link: '/qi/quote_new.php'
            },
            'invoices': {
                icon: '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
                title: 'No Invoices Yet',
                text: 'Create your first invoice to start billing',
                button: 'Create First Invoice',
                link: '/qi/invoice_new.php'
            },
            'recurring': {
                icon: '<path d="M23 6l-9.5 9.5-5-5L1 18"/><polyline points="17 6 23 6 23 12"/>',
                title: 'No Recurring Invoices',
                text: 'Set up automatic billing for regular customers',
                button: 'Create Recurring Invoice',
                link: '/qi/recurring.php'
            },
            'credit_notes': {
                icon: '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                title: 'No Credit Notes',
                text: 'Issue refunds or corrections when needed',
                button: 'Create Credit Note',
                link: '/qi/credit_note_new.php'
            }
        };

        const cfg = config[tab] || config['quotes'];

        return `
            <div class="fw-qi__empty-state">
                <div class="fw-qi__empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="64" height="64">
                        ${cfg.icon}
                    </svg>
                </div>
                <h3>${cfg.title}</h3>
                <p>${cfg.text}</p>
                <a href="${cfg.link}" class="fw-qi__btn fw-qi__btn--primary">
                    ${cfg.button}
                </a>
            </div>
        `;
    }

    // Initial load
    loadList();

    // Event listeners
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => {
            currentPage = 1;
            loadList();
        }, 300));
    }
    if (filterStatus) {
        filterStatus.addEventListener('change', () => {
            currentPage = 1;
            loadList();
        });
    }
    if (filterDateFrom) {
        filterDateFrom.addEventListener('change', () => {
            currentPage = 1;
            loadList();
        });
    }
    if (filterDateTo) {
        filterDateTo.addEventListener('change', () => {
            currentPage = 1;
            loadList();
        });
    }
  }

  // ========== GLOBAL HELPER ==========
  window.QIView = {
    openItem: function(tab, id) {
        const routes = {
            'quotes': '/qi/quote_view.php?id=',
            'invoices': '/qi/invoice_view.php?id=',
            'recurring': '/qi/recurring.php?id=',
            'credit_notes': '/qi/credit_note_view.php?id='
        };
        window.location.href = routes[tab] + id;
    }
  };

  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    initList();
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