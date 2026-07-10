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

  // ========== CSRF ==========
  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // Auto-inject the CSRF token into all fetch POST/PUT/DELETE/PATCH requests
  // (receipts/api/*.php validates X-CSRF-TOKEN on every state-changing call).
  // If the caller already set a token header (review.js does, as
  // "X-CSRF-Token"), leave it alone: appending a second record key that
  // normalizes to the same header name would combine into "tok, tok" and
  // fail hash_equals server-side.
  const _origFetch = window.fetch;
  window.fetch = function(url, options) {
    options = options || {};
    const method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
      const token = getCsrfToken();
      if (token) {
        if (options.headers instanceof Headers) {
          options.headers.set('X-CSRF-TOKEN', token);
        } else if (Array.isArray(options.headers)) {
          if (!options.headers.some(h => String(h && h[0]).toLowerCase() === 'x-csrf-token')) {
            options.headers.push(['X-CSRF-TOKEN', token]);
          }
        } else {
          options.headers = options.headers || {};
          if (!Object.keys(options.headers).some(k => k.toLowerCase() === 'x-csrf-token')) {
            options.headers['X-CSRF-TOKEN'] = token;
          }
        }
      }
    }
    return _origFetch.call(window, url, options);
  };

  // ========== TOASTS ==========
  // ReceiptsToast('Saved', 'success' | 'error' | 'info') — non-blocking
  // feedback for async actions, stacked bottom-right inside the module root.
  window.ReceiptsToast = function(message, type) {
    type = (type === 'error' || type === 'info') ? type : 'success';
    const root = document.querySelector('.fw-receipts') || document.body;
    let stack = document.getElementById('receiptsToastStack');
    if (!stack) {
      stack = document.createElement('div');
      stack.id = 'receiptsToastStack';
      stack.className = 'fw-receipts__toast-stack';
      stack.setAttribute('aria-live', 'polite');
      root.appendChild(stack);
    }
    const toast = document.createElement('div');
    toast.className = 'fw-receipts__toast fw-receipts__toast--' + type;
    toast.textContent = message;
    stack.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('fw-receipts__toast--visible'));
    setTimeout(() => {
      toast.classList.remove('fw-receipts__toast--visible');
      setTimeout(() => toast.remove(), 300);
    }, 3500);
  };

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-receipts');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || THEME_DARK;
    applyTheme(theme);

    toggle.addEventListener('click', () => {
      theme = theme === THEME_DARK ? THEME_LIGHT : THEME_DARK;
      applyTheme(theme);
      setCookie(THEME_COOKIE, theme);
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

  // ========== RECEIPTS LIST ==========
  function initReceiptsList() {
    const listContainer = document.getElementById('receiptsList');
    const searchInput = document.getElementById('searchInput');
    const filterSupplier = document.getElementById('filterSupplier');

    if (!listContainer) return;

    const urlParams = new URLSearchParams(window.location.search);
    const activeTab = urlParams.get('tab') || 'overview';

    const LIST_TABS = ['inbox', 'exceptions', 'approved', 'all'];
    if (!LIST_TABS.includes(activeTab)) {
      return; // do not fetch for overview
    }

    function loadReceipts() {
      const search = searchInput ? searchInput.value : '';
      const supplier = filterSupplier ? filterSupplier.value : '';

      const params = new URLSearchParams({
        tab: activeTab,
        search: search,
        supplier: supplier
      });

      listContainer.innerHTML = '<div class="fw-receipts__loading">Loading receipts...</div>';

      fetch('ajax/list_' + activeTab + '.php?' + params.toString())
        .then(res => res.json())
        .then(data => {
          if (data.ok) {
            renderReceipts(data.receipts);
          } else {
            listContainer.innerHTML = '<div class="fw-receipts__loading">Error: ' + (data.error || 'Unknown error') + '</div>';
          }
        })
        .catch(err => {
          listContainer.innerHTML = '<div class="fw-receipts__loading">Network error</div>';
          console.error(err);
        });
    }

    // Map receipt/bill statuses to glossy badge variants
    // (green=approved/success, red=failed/exception, cyan=processing, amber=pending).
    function statusBadgeClass(status) {
      const s = String(status || '').toLowerCase();
      if (['approved', 'posted', 'paid', 'sent', 'viewed', 'completed', 'success', 'matched'].includes(s)) {
        return ' fw-receipts__badge--success';
      }
      if (['failed', 'error', 'exception', 'blocked', 'cancelled', 'expired'].includes(s)) {
        return ' fw-receipts__badge--failed';
      }
      if (['processing', 'parsed', 'ocr', 'matching', 'review'].includes(s)) {
        return ' fw-receipts__badge--processing';
      }
      if (['pending', 'draft', 'awaiting', 'queued'].includes(s)) {
        return ' fw-receipts__badge--pending';
      }
      return '';
    }

    function renderReceipts(receipts) {
      if (receipts.length === 0) {
        listContainer.innerHTML =
          '<div class="fw-receipts__empty-state">No receipts found' +
          '<small>Upload a receipt or adjust your search filters.</small></div>';
        return;
      }

      const html = receipts.map(rec => {
        const vendorName = rec.vendor_name || 'Unknown Vendor';
        const invoiceNum = rec.invoice_number || 'No invoice #';
        const total = rec.total ? 'R' + parseFloat(rec.total).toFixed(2) : '—';
        const date = rec.uploaded_at || '';
        const status = rec.ocr_status || rec.invoice_status || '';

        return `
          <a href="review.php?id=${rec.file_id}" class="fw-receipts__receipt-card">
            <div class="fw-receipts__receipt-icon">
              <svg viewBox="0 0 24 24" fill="none">
                <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <div class="fw-receipts__receipt-info">
              <div class="fw-receipts__receipt-vendor">${escapeHtml(vendorName)}</div>
              <div class="fw-receipts__receipt-meta">
                ${escapeHtml(invoiceNum)} • ${total}
              </div>
              <div class="fw-receipts__receipt-date">${escapeHtml(date)}</div>
            </div>
            ${status ? `<span class="fw-receipts__badge${statusBadgeClass(status)}">${escapeHtml(status)}</span>` : ''}
          </a>
        `;
      }).join('');

      listContainer.innerHTML = html;
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    loadReceipts();

    if (searchInput) {
      searchInput.addEventListener('input', debounce(loadReceipts, 300));
    }
    if (filterSupplier) {
      filterSupplier.addEventListener('change', loadReceipts);
    }
  }

  // ========== MODAL SYSTEM ==========
  window.ReceiptsModal = {
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

  // Close modals on overlay click
  document.addEventListener('click', (e) => {
    if (e.target.classList.contains('fw-receipts__modal-overlay')) {
      e.target.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.fw-receipts__modal-overlay[aria-hidden="false"]').forEach(modal => {
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
      });
    }
  });

  // ========== WIDGETS OVERVIEW (3-dot menu, info + layout + type + remove) ==========
  function initWidgets() {
    const grid = document.getElementById('widgetsGrid');
    if (!grid) return;

    fetch('api/widgets.php')
      .then(r => r.json())
      .then(payload => {
        if (!payload.ok) return;
        const layout = payload.layout || [];
        const data   = payload.data   || {};
        const REG    = window.ReceiptsWidgets || {};

        grid.innerHTML = '';
        layout.forEach(slot => {
          const key  = slot.widget_key || null;
          const meta = key ? REG[key] : null;
          const size = (slot.size && ['sm','md','lg'].includes(slot.size)) ? slot.size : (meta?.defaultSize || 'md');
          const cfg  = (slot.config && typeof slot.config === 'object') ? slot.config : {};

          const wrap = document.createElement('div');
          wrap.className = 'fw-wslot fw-wslot--' + size;

          const card = document.createElement('div');
          card.className = 'fw-widget';

          const head = document.createElement('div'); head.className = 'fw-whead';
          const left = document.createElement('div'); left.className = 'fw-wtitle-wrap';
          const ic   = document.createElement('div'); ic.className = 'fw-wicon'; ic.textContent = meta?.icon || '⋯';
          const ttl  = document.createElement('div'); ttl.className = 'fw-wtitle';
          ttl.textContent = cfg.customTitle || meta?.title || (key || 'Empty');
          left.append(ic, ttl);

          const menuWrap = document.createElement('div'); menuWrap.className = 'fw-wmenu-wrap';
          const menuBtn  = document.createElement('button'); menuBtn.type='button'; menuBtn.className = 'fw-wmenu-btn'; menuBtn.innerHTML = '⋯';
          const menu     = document.createElement('div'); menu.className = 'fw-wmenu';
          const miInfo   = document.createElement('div'); miInfo.className='fw-wmenu-item';   miInfo.textContent='Change info';
          const miLayout = document.createElement('div'); miLayout.className='fw-wmenu-item'; miLayout.textContent='Change layout';
          const miType   = document.createElement('div'); miType.className='fw-wmenu-item';   miType.textContent='Change widget';
          const miRemove = document.createElement('div'); miRemove.className='fw-wmenu-item'; miRemove.textContent='Remove';
          menu.append(miInfo, miLayout, miType, miRemove);
          menuWrap.append(menuBtn, menu);
          head.append(left, menuWrap);

          const body = document.createElement('div'); body.className = 'fw-wbody';

          if (!key) {
            body.innerHTML = '';
            const addBtn = document.createElement('button');
            addBtn.className = 'fw-receipts__add-widget';
            addBtn.textContent = '+';
            addBtn.addEventListener('click', () => openWidgetPicker(slot.id));
            card.append(head, body);
            card.append(addBtn);
            wrap.append(card);
            grid.append(wrap);
            return;
          }

          try { meta.render(body, data); } catch(e){ console.error(e); body.textContent='Render error'; }

          menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.classList.toggle('open');
          });
          document.addEventListener('click', () => menu.classList.remove('open'), { once:true });

          miInfo.addEventListener('click', () => {
            menu.classList.remove('open');
            openConfigDialog({ slotId: slot.id, widgetKey: key, current: cfg }, (newCfg) => {
              persistSlot({ slotId: slot.id, widgetKey: key, config: newCfg }).then(initWidgets);
            });
          });

          miLayout.addEventListener('click', () => {
            menu.classList.remove('open');
            const cycle = { sm:'md', md:'lg', lg:'sm' };
            const next = cycle[size] || 'md';
            persistSlot({ slotId: slot.id, widgetKey: key, size: next }).then(initWidgets);
          });

          // Change widget: open the picker
          miType.addEventListener('click', () => {
            menu.classList.remove('open');
            openWidgetPicker(slot.id);
          });

          miRemove.addEventListener('click', () => {
            menu.classList.remove('open');
            persistSlot({ slotId: slot.id, widgetKey: null }).then(initWidgets);
          });

          card.append(head, body);
          wrap.append(card);
          grid.append(wrap);
        });
      })
      .catch(err => console.error(err));
  }

  function persistSlot(payload) {
    return fetch('api/widgets.php', {
      method: 'POST',
      headers: { 'Content-Type':'application/json' },
      body: JSON.stringify(payload)
    }).then(r=>r.json());
  }

  // ---- Simple config modal (no HTML changes needed) ----
  function openConfigDialog(opts, onSave) {
    const { slotId, widgetKey, current } = opts || {};
    const REG = window.ReceiptsWidgets || {};
    const meta = REG[widgetKey];

    const overlay = document.createElement('div');
    overlay.className = 'fw-receipts__modal-overlay';
    overlay.setAttribute('aria-hidden','false');

    const modal = document.createElement('div');
    modal.className = 'fw-receipts__widget-picker';
    modal.style.maxWidth = '460px';

    const title = document.createElement('h2');
    title.className = 'fw-receipts__widget-picker-title';
    title.textContent = `Configure: ${meta?.title || widgetKey}`;

    const form = document.createElement('div');
    form.style.display = 'grid';
    form.style.gap = '10px';

    const lblTitle = document.createElement('label'); lblTitle.textContent = 'Title';
    const inpTitle = document.createElement('input'); inpTitle.type='text'; inpTitle.className = 'fw-receipts__input';
    inpTitle.value = current?.customTitle || '';

    form.append(lblTitle, inpTitle);

    if (widgetKey === 'this_month_spend') {
      const lblRange = document.createElement('label'); lblRange.textContent='Range (days)';
      const selRange = document.createElement('select'); selRange.className='fw-receipts__select';
      [30,60,90].forEach(d=>{
        const o = document.createElement('option'); o.value=d; o.textContent=d+' days';
        if ((current?.rangeDays||30) === d) o.selected = true;
        selRange.append(o);
      });
      form.append(lblRange, selRange);
      modal._extraRange = selRange;
    }

    const row = document.createElement('div');
    row.style.display = 'flex'; row.style.gap = '8px'; row.style.justifyContent='flex-end';
    const btnCancel = document.createElement('button'); btnCancel.type='button'; btnCancel.className='fw-receipts__btn fw-receipts__btn--secondary'; btnCancel.textContent='Cancel';
    const btnSave   = document.createElement('button'); btnSave.type='button';   btnSave.className='fw-receipts__btn fw-receipts__btn--primary';   btnSave.textContent='Save';
    row.append(btnCancel, btnSave);

    modal.append(title, form, row);
    overlay.append(modal);
    document.body.append(overlay);
    document.body.style.overflow = 'hidden';

    function close() {
      overlay.setAttribute('aria-hidden','true');
      overlay.remove();
      document.body.style.overflow = '';
    }

    btnCancel.addEventListener('click', close);
    overlay.addEventListener('click', (e)=>{ if(e.target === overlay) close(); });

    btnSave.addEventListener('click', () => {
      const newCfg = Object.assign({}, current);
      newCfg.customTitle = inpTitle.value.trim() || undefined;
      if (widgetKey === 'this_month_spend' && modal._extraRange) {
        newCfg.rangeDays = parseInt(modal._extraRange.value, 10);
      }
      close();
      if (typeof onSave === 'function') onSave(newCfg);
    });
  }

  // ===== Widget Picker wiring (needed for "Change widget") =====
  function initWidgetPicker() {
    const list = document.getElementById('widgetPickerList');
    if (!list) return;
    list.addEventListener('click', (e) => {
      const li = e.target.closest('li[data-key]');
      if (!li) return;
      const key = li.getAttribute('data-key');
      selectWidget(key);
    });
  }

  function openWidgetPicker(slotId) {
    const modal = document.getElementById('widgetPickerModal');
    if (!modal) return;
    modal.dataset.slotId = String(slotId);
    modal.setAttribute('aria-hidden','false');
    document.body.style.overflow = 'hidden';
  }

  function closeWidgetPicker() {
    const modal = document.getElementById('widgetPickerModal');
    if (!modal) return;
    modal.setAttribute('aria-hidden','true');
    document.body.style.overflow = '';
  }
  // index.php's picker Close button calls this via inline onclick.
  window.closeWidgetPicker = closeWidgetPicker;

  function selectWidget(widgetKey) {
    const modal = document.getElementById('widgetPickerModal');
    const slotId = Number(modal?.dataset?.slotId || 0);
    if (!slotId || !widgetKey) {
      closeWidgetPicker();
      return;
    }
    closeWidgetPicker();
    persistSlot({ slotId, widgetKey }).then(() => {
      // re-render overview immediately
      initWidgets();
    });
  }

  // ========== DIMENSION 3D ENGINE ==========
  const REDUCE_MOTION = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Delegated pointer engine: works for cards rendered at any time (receipt
  // lists and widgets load via fetch, so per-card binding at DOMContentLoaded
  // never sees them). Instead of writing inline transforms, it feeds the CSS
  // custom properties (--rx/--ry for tilt, --mx/--my for the specular glare)
  // that receipts.css/widgets.css compose into the card transform.
  function init3DTilt() {
    if (REDUCE_MOTION) return;
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const SELECTOR = '.fw-receipts__receipt-card, .fw-widget';
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

  // ===== LOGO TILE PLAYFUL TILT =====
  function initLogoTileEffect() {
    if (REDUCE_MOTION) return;
    const logoTile = document.querySelector('.fw-receipts__logo-tile');
    if (!logoTile) return;
    logoTile.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.05) rotate(-3deg)';
    });
    logoTile.addEventListener('mouseleave', function() {
      this.style.transform = '';
    });
  }

  // ===== INIT =====
  function init() {
    initTheme();
    initKebabMenu();
    initReceiptsList();
    initWidgetPicker();                // <-- ensure picker works for "Change widget"
    initLogoTileEffect();
    init3DTilt();
    const url = new URLSearchParams(window.location.search);
    if ((url.get('tab') || 'overview') === 'overview') initWidgets();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
