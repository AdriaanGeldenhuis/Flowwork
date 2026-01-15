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
    const body = document.querySelector('.fw-receipts');
    if (!toggle || !body) return;

    let theme = getCookie(THEME_COOKIE) || THEME_LIGHT;
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

    function renderReceipts(receipts) {
      if (receipts.length === 0) {
        listContainer.innerHTML = '<div class="fw-receipts__loading">No receipts found</div>';
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
            ${status ? `<div class="fw-receipts__receipt-status">${escapeHtml(status)}</div>` : ''}
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

  // ===== INIT =====
  function init() {
    initTheme();
    initKebabMenu();
    initReceiptsList();
    initWidgetPicker();                // <-- ensure picker works for "Change widget"
    const url = new URLSearchParams(window.location.search);
    if ((url.get('tab') || 'overview') === 'overview') initWidgets();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
