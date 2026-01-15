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

  function formatCurrency(cents) {
    return 'R' + (cents / 100).toFixed(2);
  }

  function showToast(message, type = 'info') {
    // Simple toast notification
    const toast = document.createElement('div');
    toast.className = 'fw-shopping__toast fw-shopping__toast--' + type;
    toast.textContent = message;
    toast.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      padding: 16px 24px;
      background: ${type === 'error' ? '#ef4444' : type === 'success' ? '#10b981' : '#192f59'};
      color: white;
      border-radius: 12px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.2);
      z-index: 9999;
      animation: slideIn 0.3s ease;
    `;
    document.body.appendChild(toast);
    setTimeout(() => {
      toast.style.animation = 'slideOut 0.3s ease';
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  async function apiCall(endpoint, data = {}) {
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      return await response.json();
    } catch (error) {
      console.error('API Error:', error);
      return { ok: false, error: 'Network error' };
    }
  }

  // ========== THEME TOGGLE ==========
  function initTheme() {
    const toggle = document.getElementById('themeToggle');
    const indicator = document.getElementById('themeIndicator');
    const body = document.querySelector('.fw-shopping');
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

  // ========== INDEX PAGE - QUICK ADD ==========
  function initQuickAdd() {
    const input = document.getElementById('quickAddInput');
    const btnParse = document.getElementById('btnQuickParse');
    const btnCreate = document.getElementById('btnQuickCreate');
    const preview = document.getElementById('quickAddPreview');

    if (!input || !btnParse || !btnCreate || !preview) return;

    let parsedItems = [];

    btnParse.addEventListener('click', async () => {
      const text = input.value.trim();
      if (!text) {
        showToast('Please enter items', 'error');
        return;
      }

      btnParse.disabled = true;
      btnParse.innerHTML = '<span class="fw-shopping__spinner"></span> Parsing...';

      const result = await apiCall('/shopping/ajax/ai_item_parse.php', { text });

      btnParse.disabled = false;
      btnParse.textContent = 'Parse Items';

      if (result.ok) {
        parsedItems = result.items;
        renderPreview(parsedItems);
        btnCreate.disabled = false;
      } else {
        showToast(result.error || 'Failed to parse', 'error');
      }
    });

    btnCreate.addEventListener('click', async () => {
      if (parsedItems.length === 0) return;

      const listName = prompt('List name:', 'Shopping List ' + new Date().toLocaleDateString());
      if (!listName) return;

      btnCreate.disabled = true;
      btnCreate.innerHTML = '<span class="fw-shopping__spinner"></span> Creating...';

      const result = await apiCall('/shopping/ajax/list_create.php', {
        name: listName,
        items: parsedItems
      });

      btnCreate.disabled = false;
      btnCreate.textContent = 'Create List';

      if (result.ok) {
        showToast('List created!', 'success');
        window.location.href = '/shopping/list.php?id=' + result.list_id;
      } else {
        showToast(result.error || 'Failed to create', 'error');
      }
    });

    function renderPreview(items) {
      if (items.length === 0) {
        preview.style.display = 'none';
        return;
      }

      preview.style.display = 'block';
      preview.innerHTML = '<h4 style="margin: 0 0 12px 0; font-size: 14px; color: var(--fw-text-muted);">Parsed Items:</h4>' +
        items.map(item => `
          <div class="fw-shopping__preview-item">
            <div class="fw-shopping__preview-item-qty">${item.qty}x</div>
            <div class="fw-shopping__preview-item-name">${item.name}</div>
            <div class="fw-shopping__preview-item-unit">${item.unit}</div>
          </div>
        `).join('');
    }
  }

  // ========== INDEX PAGE - MY LISTS ==========
  function initMyLists() {
    const container = document.getElementById('myListsContainer');
    if (!container) return;

    loadLists();

    async function loadLists() {
      container.innerHTML = '<div class="fw-shopping__loading">Loading lists...</div>';

      const result = await apiCall('/shopping/ajax/list_get.php', { mode: 'my' });

      if (result.ok) {
        renderLists(result.lists);
      } else {
        container.innerHTML = '<div class="fw-shopping__loading">Error: ' + (result.error || 'Failed to load') + '</div>';
      }
    }

    function renderLists(lists) {
      if (lists.length === 0) {
        container.innerHTML = '<div class="fw-shopping__loading">No lists yet. Create one above!</div>';
        return;
      }

      container.innerHTML = lists.map(list => {
        const statusClass = 'fw-shopping__list-card-badge--' + list.status;
        return `
          <a href="/shopping/list.php?id=${list.id}" class="fw-shopping__list-card">
            <div class="fw-shopping__list-card-header">
              <h3 class="fw-shopping__list-card-title">${list.name}</h3>
              <span class="fw-shopping__list-card-badge ${statusClass}">${list.status}</span>
            </div>
            <div class="fw-shopping__list-card-meta">
              ${list.purpose} • Created ${list.created_at}
            </div>
            <div class="fw-shopping__list-card-stats">
              <div class="fw-shopping__list-stat">
                <div class="fw-shopping__list-stat-value">${list.item_count}</div>
                <div class="fw-shopping__list-stat-label">Items</div>
              </div>
              <div class="fw-shopping__list-stat">
                <div class="fw-shopping__list-stat-value">${list.bought_count}</div>
                <div class="fw-shopping__list-stat-label">Bought</div>
              </div>
              <div class="fw-shopping__list-stat">
                <div class="fw-shopping__list-stat-value">${formatCurrency(list.est_total_cents || 0)}</div>
                <div class="fw-shopping__list-stat-label">Est. Total</div>
              </div>
            </div>
          </a>
        `;
      }).join('');
    }
  }

  // ========== LIST PAGE - ITEMS & AI (LIVE UPDATES) ==========
window.ShoppingListPage = {
  listId: null,
  selectedItemId: null,

  init: function(listId) {
    this.listId = listId;
    this.loadItems();
    this.initAddItem();
    this.initBuyingMode();
  },

  loadItems: async function() {
    const result = await apiCall('/shopping/ajax/list_get.php', { 
      list_id: this.listId,
      include_items: true 
    });

    if (result.ok) {
      this.renderItems(result.items);
    } else {
      showToast(result.error || 'Failed to load items', 'error');
    }
  },

  renderItems: function(items) {
    const tbody = document.querySelector('.fw-shopping__items-table tbody');
    if (!tbody) return;

    if (items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; padding: 24px; color: var(--fw-text-muted);">No items yet. Add one above!</td></tr>';
      return;
    }

    tbody.innerHTML = items.map(item => {
      const rowClass = item.status === 'bought' ? 'fw-shopping__item-row--bought' : '';
      const checked = item.status === 'bought' ? 'checked' : '';
      const priorityClass = 'fw-shopping__item-priority--' + item.priority;
      
      return `
        <tr class="${rowClass}" data-item-id="${item.id}">
          <td>
            <input type="checkbox" 
                   class="fw-shopping__item-checkbox" 
                   ${checked} 
                   onchange="ShoppingListPage.toggleCheck(${item.id}, this.checked)">
          </td>
          <td>
            <div class="fw-shopping__item-name">${item.name_raw}</div>
            ${item.notes ? '<div style="font-size: 12px; color: var(--fw-text-muted);">' + item.notes + '</div>' : ''}
          </td>
          <td class="fw-shopping__item-qty">${item.qty} ${item.unit}</td>
          <td><span class="fw-shopping__item-priority ${priorityClass}"></span></td>
          <td>${item.needed_by || '-'}</td>
          <td>${item.project_name || '-'}</td>
          <td>
            <div class="fw-shopping__item-actions">
              <button class="fw-shopping__item-btn" onclick="ShoppingListPage.findStores(${item.id})" title="Find stores">
                🔍
              </button>
              <button class="fw-shopping__item-btn" onclick="ShoppingListPage.editItem(${item.id})" title="Edit">
                ✏️
              </button>
              <button class="fw-shopping__item-btn" onclick="ShoppingListPage.deleteItem(${item.id})" title="Delete">
                🗑️
              </button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  },

  toggleCheck: async function(itemId, checked) {
    const result = await apiCall('/shopping/ajax/item_check_toggle.php', {
      list_id: this.listId,
      item_id: itemId,
      checked: checked
    });

    if (result.ok) {
      showToast(checked ? '✓ Item marked as bought' : '↩ Item unmarked', 'success');
      // Update row style immediately
      const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
      if (row) {
        if (checked) {
          row.classList.add('fw-shopping__item-row--bought');
        } else {
          row.classList.remove('fw-shopping__item-row--bought');
        }
      }
      // Refresh to update stats
      this.loadItems();
    } else {
      showToast(result.error || 'Failed to update', 'error');
      // Revert checkbox
      const checkbox = document.querySelector(`tr[data-item-id="${itemId}"] .fw-shopping__item-checkbox`);
      if (checkbox) checkbox.checked = !checked;
    }
  },

  findStores: async function(itemId) {
    this.selectedItemId = itemId;
    
    const aiPanel = document.getElementById('aiSuggestions');
    if (!aiPanel) return;

    aiPanel.innerHTML = '<div class="fw-shopping__ai-loading"><span class="fw-shopping__spinner"></span> 🤖 Finding best stores...</div>';

    const result = await apiCall('/shopping/ajax/ai_stores_suggest.php', {
      list_id: this.listId,
      item_id: itemId
    });

    if (result.ok) {
      this.renderStoreSuggestions(result.candidates);
    } else {
      aiPanel.innerHTML = '<div class="fw-shopping__ai-empty">⚠️ ' + (result.error || 'No stores found') + '</div>';
    }
  },

  renderStoreSuggestions: function(candidates) {
    const aiPanel = document.getElementById('aiSuggestions');
    if (!aiPanel) return;

    if (candidates.length === 0) {
      aiPanel.innerHTML = '<div class="fw-shopping__ai-empty">No stores found nearby</div>';
      return;
    }

    aiPanel.innerHTML = candidates.map(c => `
      <div class="fw-shopping__store-card">
        <div class="fw-shopping__store-card-header">
          <div>
            <h4 class="fw-shopping__store-name">${c.store_name}</h4>
            <div class="fw-shopping__store-details">
              ${c.distance_km ? c.distance_km + ' km away' : ''} 
              ${c.address ? (c.distance_km ? ' • ' : '') + c.address : ''}
            </div>
          </div>
          <div class="fw-shopping__store-score">${c.score.toFixed(1)}</div>
        </div>
        
        ${c.est_price_cents ? 
          '<div class="fw-shopping__store-price"><span class="fw-shopping__store-price-label">Est. Price:</span>' + 
          formatCurrency(c.est_price_cents) + '</div>' : ''}
        
        ${c.explanation ? 
          '<div class="fw-shopping__store-explanation">' + c.explanation + '</div>' : ''}
        
        <div class="fw-shopping__store-actions">
          ${c.phone ? 
            '<button class="fw-shopping__store-action-btn" onclick="window.open(\'tel:' + c.phone + '\')">📞 Call</button>' : ''}
          ${c.phone ? 
            '<button class="fw-shopping__store-action-btn" onclick="window.open(\'https://wa.me/' + c.phone.replace(/[^0-9]/g,'') + '\')">💬 WhatsApp</button>' : ''}
          ${c.lat && c.lng ? 
            '<button class="fw-shopping__store-action-btn" onclick="window.open(\'https://www.google.com/maps/dir/?api=1&destination=' + c.lat + ',' + c.lng + '\')">🗺️ Navigate</button>' : ''}
          <button class="fw-shopping__store-action-btn" onclick="ShoppingListPage.selectStore(${c.id})">✅ Select</button>
        </div>
      </div>
    `).join('');
  },

  selectStore: function(candidateId) {
    showToast('✓ Store selected!', 'success');
    // TODO: Save preferred store for this item
  },

  initAddItem: function() {
    const form = document.getElementById('addItemForm');
    if (!form) return;

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const formData = new FormData(form);
      const data = {
        list_id: this.listId,
        name: formData.get('name'),
        qty: formData.get('qty'),
        unit: formData.get('unit'),
        priority: formData.get('priority')
      };

      const submitBtn = form.querySelector('button[type="submit"]');
      const originalText = submitBtn.innerHTML;
      submitBtn.innerHTML = '<span class="fw-shopping__spinner"></span>';
      submitBtn.disabled = true;

      const result = await apiCall('/shopping/ajax/item_add.php', data);

      submitBtn.innerHTML = originalText;
      submitBtn.disabled = false;

      if (result.ok) {
        showToast('✓ Item added!', 'success');
        form.reset();
        form.style.display = 'none';
        // Refresh items list
        this.loadItems();
      } else {
        showToast(result.error || 'Failed to add', 'error');
      }
    });
  },

  initBuyingMode: function() {
    const btnStart = document.getElementById('btnStartBuying');
    const btnStop = document.getElementById('btnStopBuying');

    if (btnStart) {
      btnStart.addEventListener('click', () => this.startBuyingMode());
    }

    if (btnStop) {
      btnStop.addEventListener('click', () => this.stopBuyingMode());
    }
  },

  startBuyingMode: async function() {
    const result = await apiCall('/shopping/ajax/session_start_buying.php', {
      list_id: this.listId
    });

    if (result.ok) {
      showToast('🛒 Buying mode activated!', 'success');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast(result.error || 'Failed to start', 'error');
    }
  },

  stopBuyingMode: async function() {
    const result = await apiCall('/shopping/ajax/session_close.php', {
      list_id: this.listId
    });

    if (result.ok) {
      showToast('⏹️ Buying mode stopped', 'success');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast(result.error || 'Failed to stop', 'error');
    }
  },

  editItem: function(itemId) {
    showToast('✏️ Edit feature coming soon', 'info');
  },

  deleteItem: async function(itemId) {
    if (!confirm('Delete this item?')) return;

    const result = await apiCall('/shopping/ajax/item_remove.php', {
      list_id: this.listId,
      item_id: itemId
    });

    if (result.ok) {
      showToast('🗑️ Item deleted', 'success');
      // Remove row immediately
      const row = document.querySelector(`tr[data-item-id="${itemId}"]`);
      if (row) {
        row.style.opacity = '0';
        row.style.transform = 'translateX(-20px)';
        setTimeout(() => {
          row.remove();
          // Check if table is empty
          const tbody = document.querySelector('.fw-shopping__items-table tbody');
          if (tbody && tbody.children.length === 0) {
            this.loadItems(); // Reload to show "no items" message
          }
        }, 300);
      }
    } else {
      showToast(result.error || 'Failed to delete', 'error');
    }
  }

  ,
  /**
   * Create a new Purchase Order from this shopping list.
   * Requires a supplierId to be provided.
   * Sends a POST request to `/shopping/ajax/list.to_po.php` with
   * the current list id and selected supplier id. On success,
   * redirects the user to the newly created PO’s view page. On
   * failure, displays an error toast.
   * @param {string|number} supplierId
   * @returns {Promise<void>}
   */
  createPo: async function (supplierId) {
    if (!supplierId) {
      showToast('Supplier not selected', 'error');
      return;
    }
    const data = {
      list_id: this.listId,
      supplier_id: supplierId
    };
    const result = await apiCall('/shopping/ajax/list.to_po.php', data);
    if (result.ok) {
      showToast('📦 Purchase Order created', 'success');
      // Redirect to the PO view page
      if (result.po_id) {
        window.location.href = '/procurement/po_view.php?id=' + result.po_id;
      }
    } else {
      showToast(result.error || 'Failed to create PO', 'error');
    }
  }
};
  
  // ========== INIT ==========
  function init() {
    initTheme();
    initKebabMenu();
    initQuickAdd();
    initMyLists();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();