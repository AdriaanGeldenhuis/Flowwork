// /finances/assets/chart.js
// Chart of Accounts Management

(function() {
  'use strict';

  let accounts = [];
  let taxCodes = [];
  let currentFilters = {
    search: '',
    type: '',
    status: '1'
  };

  // DOM Elements
  const accountsTree = document.getElementById('accountsTree');
  const searchInput = document.getElementById('searchInput');
  const filterType = document.getElementById('filterType');
  const filterStatus = document.getElementById('filterStatus');
  const addAccountBtn = document.getElementById('addAccountBtn');
  const exportBtn = document.getElementById('exportBtn');
  const accountModal = document.getElementById('accountModal');
  const accountForm = document.getElementById('accountForm');
  const modalClose = document.getElementById('modalClose');
  const cancelBtn = document.getElementById('cancelBtn');
  const accountCount = document.getElementById('accountCount');

  // Load Accounts
  async function loadAccounts() {
    accountsTree.innerHTML = '<div class="fw-finance__loading">Loading accounts...</div>';

    const result = await FinanceAPI.request('/finances/ajax/account_list.php');
    
    if (result.ok) {
      accounts = result.data;
      renderAccounts();
      updateAccountCount();
    } else {
      accountsTree.innerHTML = '<div class="fw-finance__empty-state">Failed to load accounts</div>';
    }
  }

  // Load Tax Codes
  async function loadTaxCodes() {
    const result = await FinanceAPI.request('/finances/ajax/tax_code_list.php');
    
    if (result.ok) {
      taxCodes = result.data;
      populateTaxCodeDropdown();
    }
  }

  // Render Accounts
  function renderAccounts() {
    const filtered = filterAccounts();
    const grouped = groupAccountsByType(filtered);

    if (filtered.length === 0) {
      accountsTree.innerHTML = '<div class="fw-finance__empty-state">No accounts found</div>';
      return;
    }

    let html = '';

    ['asset', 'liability', 'equity', 'revenue', 'expense'].forEach(type => {
      if (grouped[type] && grouped[type].length > 0) {
        html += `<div class="fw-finance__account-group">`;
        html += `<div class="fw-finance__account-group-header">${type.toUpperCase()}</div>`;
        
        grouped[type].forEach(account => {
          html += renderAccountItem(account);
        });
        
        html += `</div>`;
      }
    });

    accountsTree.innerHTML = html;
    attachAccountEventListeners();
  }

  // Render Single Account Item
  function renderAccountItem(account, level = 0) {
    const typeClass = `fw-finance__account-type--${account.account_type}`;
    const itemClass = level > 0 ? 'fw-finance__account-item--child' : 
                      (hasChildren(account.account_id) ? 'fw-finance__account-item--parent' : '');
    
    const paddingLeft = 16 + (level * 24);
    
    let html = `
      <div class="fw-finance__account-item ${itemClass}" data-id="${account.account_id}" style="padding-left: ${paddingLeft}px;">
        <div class="fw-finance__account-info">
          <span class="fw-finance__account-code">${escapeHtml(account.account_code)}</span>
          <span class="fw-finance__account-name">${escapeHtml(account.account_name)}</span>
          <span class="fw-finance__account-type ${typeClass}">${escapeHtml(account.account_type)}</span>
          ${account.is_active == 0 ? '<span class="fw-finance__badge fw-finance__badge--draft">Inactive</span>' : ''}
        </div>
        <div class="fw-finance__account-actions">
          <button class="fw-finance__icon-btn edit-account" data-id="${account.account_id}" title="Edit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          ${account.is_system == 0 ? `
            <button class="fw-finance__icon-btn toggle-status" data-id="${account.account_id}" title="${account.is_active == 1 ? 'Deactivate' : 'Activate'}">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                ${account.is_active == 1 ? 
                  '<path d="M18 6L6 18M6 6l12 12"/>' : 
                  '<polyline points="20 6 9 17 4 12"/>'}
              </svg>
            </button>
          ` : ''}
        </div>
      </div>
    `;

    // Render children
    const children = accounts.filter(a => a.parent_id == account.account_id);
    if (children.length > 0) {
      children.forEach(child => {
        html += renderAccountItem(child, level + 1);
      });
    }

    return html;
  }

  // Filter Accounts
  function filterAccounts() {
    return accounts.filter(account => {
      // Search filter
      if (currentFilters.search) {
        const search = currentFilters.search.toLowerCase();
        const matchesSearch = 
          account.account_code.toLowerCase().includes(search) ||
          account.account_name.toLowerCase().includes(search);
        if (!matchesSearch) return false;
      }

      // Type filter
      if (currentFilters.type && account.account_type !== currentFilters.type) {
        return false;
      }

      // Status filter
      if (currentFilters.status !== '') {
        if (account.is_active != currentFilters.status) {
          return false;
        }
      }

      return true;
    });
  }

  // Group Accounts by Type
  function groupAccountsByType(accountList) {
    const grouped = {
      asset: [],
      liability: [],
      equity: [],
      revenue: [],
      expense: []
    };

    accountList.forEach(account => {
      if (!account.parent_id) {
        grouped[account.account_type].push(account);
      }
    });

    return grouped;
  }

  // Check if account has children
  function hasChildren(accountId) {
    return accounts.some(a => a.parent_id == accountId);
  }

  // Update Account Count
  function updateAccountCount() {
    const filtered = filterAccounts();
    accountCount.textContent = `${filtered.length} account${filtered.length !== 1 ? 's' : ''}`;
  }

  // Attach Event Listeners to Account Items
  function attachAccountEventListeners() {
    document.querySelectorAll('.edit-account').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const accountId = btn.dataset.id;
        openEditModal(accountId);
      });
    });

    document.querySelectorAll('.toggle-status').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const accountId = btn.dataset.id;
        await toggleAccountStatus(accountId);
      });
    });
  }

  // Open Add Modal
  function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Account';
    accountForm.reset();
    document.getElementById('accountId').value = '';
    document.getElementById('isActive').checked = true;
    populateParentAccountDropdown();
    FinanceModal.open('accountModal');
  }

  // Open Edit Modal
  async function openEditModal(accountId) {
    const account = accounts.find(a => a.account_id == accountId);
    if (!account) return;

    document.getElementById('modalTitle').textContent = 'Edit Account';
    document.getElementById('accountId').value = account.account_id;
    document.getElementById('accountCode').value = account.account_code;
    document.getElementById('accountName').value = account.account_name;
    document.getElementById('accountType').value = account.account_type;
    document.getElementById('parentId').value = account.parent_id || '';
    document.getElementById('taxCodeId').value = account.tax_code_id || '';
    document.getElementById('isActive').checked = account.is_active == 1;

    populateParentAccountDropdown(accountId);
    FinanceModal.open('accountModal');
  }

  // Populate Parent Account Dropdown
  function populateParentAccountDropdown(excludeId = null) {
    const select = document.getElementById('parentId');
    const selectedType = document.getElementById('accountType').value;
    
    let html = '<option value="">None (Top Level)</option>';
    
    const eligible = accounts.filter(a => {
      if (excludeId && a.account_id == excludeId) return false;
      if (selectedType && a.account_type !== selectedType) return false;
      return true;
    });

    eligible.forEach(account => {
      html += `<option value="${account.account_id}">${escapeHtml(account.account_code)} - ${escapeHtml(account.account_name)}</option>`;
    });

    select.innerHTML = html;
  }

  // Populate Tax Code Dropdown
  function populateTaxCodeDropdown() {
    const select = document.getElementById('taxCodeId');
    
    let html = '<option value="">None</option>';
    
    taxCodes.forEach(code => {
      html += `<option value="${code.tax_code_id}">${escapeHtml(code.code)} - ${escapeHtml(code.description)}</option>`;
    });

    select.innerHTML = html;
  }

  // Save Account
  async function saveAccount(e) {
    e.preventDefault();

    const formData = {
      account_id: document.getElementById('accountId').value,
      account_code: document.getElementById('accountCode').value.trim(),
      account_name: document.getElementById('accountName').value.trim(),
      account_type: document.getElementById('accountType').value,
      parent_id: document.getElementById('parentId').value || null,
      tax_code_id: document.getElementById('taxCodeId').value || null,
      is_active: document.getElementById('isActive').checked ? 1 : 0
    };

    if (!formData.account_code || !formData.account_name || !formData.account_type) {
      showMessage('formMessage', 'Please fill in all required fields', 'error');
      return;
    }

    const result = await FinanceAPI.request('/finances/ajax/account_save.php', 'POST', formData);

    if (result.ok) {
      showMessage('formMessage', 'Account saved successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('accountModal');
        loadAccounts();
      }, 1000);
    } else {
      showMessage('formMessage', result.error || 'Failed to save account', 'error');
    }
  }

  // Toggle Account Status
  async function toggleAccountStatus(accountId) {
    if (!confirm('Are you sure you want to change this account\'s status?')) return;

    const result = await FinanceAPI.request('/finances/ajax/account_toggle.php', 'POST', { account_id: accountId });

    if (result.ok) {
      loadAccounts();
    } else {
      alert(result.error || 'Failed to update account status');
    }
  }

  // Export to CSV
  function exportToCSV() {
    const filtered = filterAccounts();
    
    let csv = 'Account Code,Account Name,Type,Parent,Active\n';
    
    filtered.forEach(account => {
      const parent = account.parent_id ? 
        accounts.find(a => a.account_id == account.parent_id)?.account_code || '' : '';
      
      csv += `"${account.account_code}","${account.account_name}","${account.account_type}","${parent}","${account.is_active == 1 ? 'Yes' : 'No'}"\n`;
    });

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `chart-of-accounts-${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
  }

  // Helper: Show Message
  function showMessage(elementId, message, type) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    element.className = `fw-finance__alert fw-finance__alert--${type}`;
    element.textContent = message;
    element.style.display = 'block';
  }

  // Helper: Escape HTML
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Helper: Debounce
  function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  // Event Listeners
  if (searchInput) {
    searchInput.addEventListener('input', debounce((e) => {
      currentFilters.search = e.target.value;
      renderAccounts();
      updateAccountCount();
    }, 300));
  }

  if (filterType) {
    filterType.addEventListener('change', (e) => {
      currentFilters.type = e.target.value;
      renderAccounts();
      updateAccountCount();
    });
  }

  if (filterStatus) {
    filterStatus.addEventListener('change', (e) => {
      currentFilters.status = e.target.value;
      renderAccounts();
      updateAccountCount();
    });
  }

  if (addAccountBtn) {
    addAccountBtn.addEventListener('click', openAddModal);
  }

  if (exportBtn) {
    exportBtn.addEventListener('click', exportToCSV);
  }

  if (accountForm) {
    accountForm.addEventListener('submit', saveAccount);
  }

  if (modalClose) {
    modalClose.addEventListener('click', () => FinanceModal.close('accountModal'));
  }

  if (cancelBtn) {
    cancelBtn.addEventListener('click', () => FinanceModal.close('accountModal'));
  }

  // Account Type change - update parent dropdown
  const accountTypeSelect = document.getElementById('accountType');
  if (accountTypeSelect) {
    accountTypeSelect.addEventListener('change', () => {
      const excludeId = document.getElementById('accountId').value;
      populateParentAccountDropdown(excludeId);
    });
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadAccounts();
    loadTaxCodes();
  });

})();