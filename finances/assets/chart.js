// /finances/assets/chart.js
// Chart of Accounts — SARS bookkeeping-grade

(function() {
  'use strict';

  // --- State -----------------------------------------------------------------
  let accounts = [];
  let taxCodes = [];
  let schema = null; // from API meta.schema
  let currentFilters = { search: '', type: '', status: '1' };

  // --- Subtype display labels ------------------------------------------------
  const SUBTYPE_LABELS = {
    bank: 'Bank', cash: 'Cash', accounts_receivable: 'Accounts Receivable',
    inventory: 'Inventory', prepayment: 'Prepayment', current_asset: 'Current Asset',
    non_current_asset: 'Non-Current Asset', fixed_asset: 'Fixed Asset',
    accumulated_depreciation: 'Accum. Depreciation', intangible_asset: 'Intangible',
    investment: 'Investment',
    accounts_payable: 'Accounts Payable', vat_control: 'VAT Control',
    paye_liability: 'PAYE', uif_liability: 'UIF', sdl_liability: 'SDL',
    income_tax_payable: 'Income Tax', provisional_tax: 'Provisional Tax',
    dividends_tax_payable: 'Dividends Tax', current_liability: 'Current Liability',
    non_current_liability: 'Non-Current Liability', loan: 'Loan',
    share_capital: 'Share Capital', retained_earnings: 'Retained Earnings',
    current_year_earnings: 'Current Year P&L', drawings: 'Drawings',
    operating_revenue: 'Operating Revenue', other_income: 'Other Income',
    interest_income: 'Interest Income', dividend_income: 'Dividend Income',
    forex_gain: 'Forex Gain',
    cost_of_sales: 'Cost of Sales', operating_expense: 'Operating Expense',
    payroll_expense: 'Payroll', depreciation: 'Depreciation',
    finance_cost: 'Finance Cost', forex_loss: 'Forex Loss', tax_expense: 'Tax Expense',
  };

  // --- Client-side subtype map (mirrors CoaSchema.php) -----------------------
  const SUBTYPES_BY_TYPE = {
    asset: ['bank','cash','accounts_receivable','inventory','prepayment','current_asset','fixed_asset','accumulated_depreciation','intangible_asset','investment','non_current_asset'],
    liability: ['accounts_payable','vat_control','paye_liability','uif_liability','sdl_liability','income_tax_payable','provisional_tax','dividends_tax_payable','current_liability','loan','non_current_liability'],
    equity: ['share_capital','retained_earnings','current_year_earnings','drawings'],
    revenue: ['operating_revenue','other_income','interest_income','dividend_income','forex_gain'],
    expense: ['cost_of_sales','operating_expense','payroll_expense','depreciation','finance_cost','forex_loss','tax_expense'],
  };

  const DEFAULT_NORMAL = { asset: 'debit', liability: 'credit', equity: 'credit', revenue: 'credit', expense: 'debit' };

  const TYPE_LABELS = { asset: 'ASSETS', liability: 'LIABILITIES', equity: 'EQUITY', revenue: 'REVENUE', expense: 'EXPENSES' };

  // --- DOM Elements ----------------------------------------------------------
  const accountsTree   = document.getElementById('accountsTree');
  const searchInput    = document.getElementById('searchInput');
  const filterType     = document.getElementById('filterType');
  const filterStatus   = document.getElementById('filterStatus');
  const addAccountBtn  = document.getElementById('addAccountBtn');
  const exportBtn      = document.getElementById('exportBtn');
  const importBtn      = document.getElementById('importBtn');
  const importFile     = document.getElementById('importFile');
  const accountForm    = document.getElementById('accountForm');
  const modalClose     = document.getElementById('modalClose');
  const cancelBtn      = document.getElementById('cancelBtn');
  const accountCount   = document.getElementById('accountCount');

  // --- Helpers ---------------------------------------------------------------
  // escapeHtml() and debounce() are provided globally by finance.js

  function showMessage(elementId, message, type) {
    const el = document.getElementById(elementId);
    if (!el) return;
    el.className = 'fw-finance__alert fw-finance__alert--' + type;
    el.textContent = message;
    el.style.display = 'block';
  }

  function formatBalance(amount) {
    if (amount === 0 || amount === null || amount === undefined) return '-';
    const abs = Math.abs(amount);
    const formatted = 'R ' + abs.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return amount < 0 ? '(' + formatted + ')' : formatted;
  }

  // --- Data Loading ----------------------------------------------------------
  async function loadAccounts() {
    accountsTree.innerHTML = '<div class="fw-finance__loading">Loading accounts...</div>';
    const result = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (result && result.ok) {
      accounts = result.data;
      if (result.meta && result.meta.schema) schema = result.meta.schema;
      renderAccounts();
      updateFooter();
    } else {
      accountsTree.innerHTML = '<div class="fw-finance__empty-state">Failed to load accounts</div>';
    }
  }

  async function loadTaxCodes() {
    const result = await FinanceAPI.request('/finances/ajax/tax_code_list.php');
    if (result && result.ok) {
      taxCodes = result.data;
      populateTaxCodeDropdown();
    }
  }

  // --- Rendering -------------------------------------------------------------
  function renderAccounts() {
    const filtered = filterAccounts();
    if (filtered.length === 0) {
      accountsTree.innerHTML = '<div class="fw-finance__empty-state">No accounts found</div>';
      return;
    }

    const grouped = {};
    ['asset','liability','equity','revenue','expense'].forEach(t => { grouped[t] = []; });
    filtered.forEach(a => {
      if (!a.parent_id) grouped[a.account_type]?.push(a);
    });

    let html = '';
    ['asset','liability','equity','revenue','expense'].forEach(type => {
      const list = grouped[type];
      if (!list || list.length === 0) return;
      html += '<div class="fw-finance__account-group">';
      html += '<div class="fw-finance__account-group-header">' + TYPE_LABELS[type] + '</div>';
      // Column header row
      html += '<div class="fw-finance__account-colheader">'
            + '<span class="fw-finance__col-code">Code</span>'
            + '<span class="fw-finance__col-name">Name</span>'
            + '<span class="fw-finance__col-subtype">Subtype</span>'
            + '<span class="fw-finance__col-normal">Dr/Cr</span>'
            + '<span class="fw-finance__col-balance">YTD Balance</span>'
            + '<span class="fw-finance__col-actions">Actions</span>'
            + '</div>';
      list.forEach(a => { html += renderAccountRow(a, 0, filtered); });
      html += '</div>';
    });

    accountsTree.innerHTML = html;
    attachAccountEventListeners();
  }

  function renderAccountRow(account, level, allFiltered) {
    const pad = 12 + (level * 20);
    const isLocked  = account.is_locked == 1;
    const isControl = account.is_control == 1;
    const isSystem  = account.is_system == 1;
    const isInactive = account.is_active == 0;

    // Badges
    let badges = '';
    if (isLocked)   badges += '<span class="fw-finance__badge fw-finance__badge--sars" title="SARS statutory / locked">SARS</span>';
    if (isControl)  badges += '<span class="fw-finance__badge fw-finance__badge--control" title="Control account">Ctrl</span>';
    if (isSystem && !isLocked && !isControl)
      badges += '<span class="fw-finance__badge fw-finance__badge--system" title="System account">Sys</span>';
    if (isInactive) badges += '<span class="fw-finance__badge fw-finance__badge--draft">Inactive</span>';

    // Balance display
    const bal = parseFloat(account.ytd_balance) || 0;
    const balClass = bal > 0 ? 'fw-finance__bal--positive' : (bal < 0 ? 'fw-finance__bal--negative' : '');

    // Subtype label
    const subtypeLabel = SUBTYPE_LABELS[account.account_subtype] || account.account_subtype || '';

    // Disable edit for locked? No — allow clicking to view. Server blocks saves.
    const canToggle = !isSystem && !isLocked;

    let html = '<div class="fw-finance__account-row' + (level > 0 ? ' fw-finance__account-row--child' : '') + '" data-id="' + account.account_id + '" style="padding-left:' + pad + 'px">';
    html += '<span class="fw-finance__col-code">' + escapeHtml(account.account_code) + '</span>';
    html += '<span class="fw-finance__col-name">' + escapeHtml(account.account_name) + ' ' + badges + '</span>';
    html += '<span class="fw-finance__col-subtype">' + escapeHtml(subtypeLabel) + '</span>';
    html += '<span class="fw-finance__col-normal">' + (account.normal_balance === 'debit' ? 'Dr' : 'Cr') + '</span>';
    html += '<span class="fw-finance__col-balance ' + balClass + '">' + formatBalance(bal) + '</span>';
    html += '<span class="fw-finance__col-actions">';
    html += '<button class="fw-finance__icon-btn edit-account" data-id="' + account.account_id + '" title="' + (isLocked ? 'View (locked)' : 'Edit') + '">'
          + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
          + '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'
          + '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>'
          + '</svg></button>';
    if (canToggle) {
      const toggleIcon = account.is_active == 1
        ? '<path d="M18 6L6 18M6 6l12 12"/>'
        : '<polyline points="20 6 9 17 4 12"/>';
      html += '<button class="fw-finance__icon-btn toggle-status" data-id="' + account.account_id + '" title="' + (account.is_active == 1 ? 'Deactivate' : 'Activate') + '">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + toggleIcon + '</svg></button>';
    }
    if (window.__COA_CONFIG?.isAdmin && !isSystem && !isLocked) {
      html += '<button class="fw-finance__icon-btn delete-account" data-id="' + account.account_id + '" title="Delete">'
            + '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>'
            + '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>'
            + '</svg></button>';
    }
    html += '</span></div>';

    // Render children
    const children = allFiltered.filter(a => a.parent_id == account.account_id);
    children.sort((a, b) => a.account_code.localeCompare(b.account_code));
    children.forEach(child => { html += renderAccountRow(child, level + 1, allFiltered); });

    return html;
  }

  // --- Filtering -------------------------------------------------------------
  function filterAccounts() {
    return accounts.filter(a => {
      if (currentFilters.search) {
        const s = currentFilters.search.toLowerCase();
        if (!a.account_code.toLowerCase().includes(s) && !a.account_name.toLowerCase().includes(s)) return false;
      }
      if (currentFilters.type && a.account_type !== currentFilters.type) return false;
      if (currentFilters.status !== '' && a.is_active != currentFilters.status) return false;
      return true;
    });
  }

  // --- Footer ----------------------------------------------------------------
  function updateFooter() {
    const filtered = filterAccounts();
    const locked = filtered.filter(a => a.is_locked == 1).length;
    const withBalance = filtered.filter(a => parseFloat(a.ytd_balance) !== 0).length;
    accountCount.textContent = filtered.length + ' accounts' + (locked ? ' \u00B7 ' + locked + ' locked' : '') + (withBalance ? ' \u00B7 ' + withBalance + ' with balances' : '');
  }

  // --- Modal: Open / Populate ------------------------------------------------
  function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Account';
    accountForm.reset();
    document.getElementById('accountId').value = '';
    document.getElementById('isActive').checked = true;
    document.getElementById('normalBalance').value = 'debit';
    document.getElementById('accountSubtype').innerHTML = '<option value="">Select subtype...</option>';
    enableFormFields(true);
    populateParentDropdown();
    FinanceModal.open('accountModal');
  }

  function openEditModal(accountId) {
    const account = accounts.find(a => a.account_id == accountId);
    if (!account) return;

    const isLocked = account.is_locked == 1;
    document.getElementById('modalTitle').textContent = isLocked ? 'View Account (Locked)' : 'Edit Account';
    document.getElementById('accountId').value = account.account_id;
    document.getElementById('accountCode').value = account.account_code;
    document.getElementById('accountName').value = account.account_name;
    document.getElementById('accountType').value = account.account_type;
    document.getElementById('accountDescription').value = account.description || '';
    document.getElementById('normalBalance').value = account.normal_balance || 'debit';
    document.getElementById('parentId').value = account.parent_id || '';
    document.getElementById('taxCodeId').value = account.tax_code_id || '';
    document.getElementById('isActive').checked = account.is_active == 1;

    // Populate subtype dropdown for this type, then set value
    populateSubtypeDropdown(account.account_type);
    document.getElementById('accountSubtype').value = account.account_subtype || '';

    // Disable fields for locked accounts (allow description + tax code)
    enableFormFields(!isLocked);
    if (isLocked) {
      document.getElementById('accountDescription').disabled = false;
      document.getElementById('taxCodeId').disabled = false;
      document.getElementById('saveBtn').textContent = 'Update Description';
    } else {
      document.getElementById('saveBtn').textContent = 'Save Account';
    }

    populateParentDropdown(accountId);
    FinanceModal.open('accountModal');
  }

  function enableFormFields(enabled) {
    ['accountCode','accountType','accountName','accountSubtype','normalBalance','parentId','taxCodeId','isActive','accountDescription'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = !enabled;
    });
  }

  // --- Dropdowns -------------------------------------------------------------
  function populateSubtypeDropdown(type) {
    const select = document.getElementById('accountSubtype');
    let html = '<option value="">Select subtype...</option>';
    const subtypes = SUBTYPES_BY_TYPE[type] || [];
    subtypes.forEach(st => {
      html += '<option value="' + st + '">' + (SUBTYPE_LABELS[st] || st) + '</option>';
    });
    select.innerHTML = html;
  }

  function populateParentDropdown(excludeId) {
    const select = document.getElementById('parentId');
    const selectedType = document.getElementById('accountType').value;
    let html = '<option value="">None (Top Level)</option>';
    accounts.filter(a => {
      if (excludeId && a.account_id == excludeId) return false;
      if (selectedType && a.account_type !== selectedType) return false;
      return a.is_active == 1;
    }).forEach(a => {
      html += '<option value="' + a.account_id + '">' + escapeHtml(a.account_code) + ' - ' + escapeHtml(a.account_name) + '</option>';
    });
    select.innerHTML = html;
  }

  function populateTaxCodeDropdown() {
    const select = document.getElementById('taxCodeId');
    let html = '<option value="">None</option>';
    taxCodes.forEach(tc => {
      html += '<option value="' + tc.tax_code_id + '">' + escapeHtml(tc.code) + ' - ' + escapeHtml(tc.description) + '</option>';
    });
    select.innerHTML = html;
  }

  // --- Save ------------------------------------------------------------------
  async function saveAccount(e) {
    e.preventDefault();

    const formData = {
      account_id:      document.getElementById('accountId').value || null,
      account_code:    document.getElementById('accountCode').value.trim(),
      account_name:    document.getElementById('accountName').value.trim(),
      account_type:    document.getElementById('accountType').value,
      account_subtype: document.getElementById('accountSubtype').value,
      normal_balance:  document.getElementById('normalBalance').value,
      description:     document.getElementById('accountDescription').value.trim(),
      parent_id:       document.getElementById('parentId').value || null,
      tax_code_id:     document.getElementById('taxCodeId').value || null,
      is_active:       document.getElementById('isActive').checked ? 1 : 0,
    };

    if (!formData.account_code || !formData.account_name || !formData.account_type) {
      showMessage('formMessage', 'Please fill in all required fields', 'error');
      return;
    }

    const result = await FinanceAPI.request('/finances/ajax/account_save.php', 'POST', formData);

    if (result && result.ok) {
      showMessage('formMessage', 'Account saved successfully', 'success');
      setTimeout(() => { FinanceModal.close('accountModal'); loadAccounts(); }, 800);
    } else {
      showMessage('formMessage', (result && result.error) || 'Failed to save account', 'error');
    }
  }

  // --- Toggle Status ---------------------------------------------------------
  async function toggleAccountStatus(accountId) {
    if (!confirm('Are you sure you want to change this account\'s status?')) return;
    const result = await FinanceAPI.request('/finances/ajax/account_toggle.php', 'POST', { account_id: accountId });
    if (result && result.ok) {
      loadAccounts();
    } else {
      alert((result && result.error) || 'Failed to update account status');
    }
  }

  // --- Delete ----------------------------------------------------------------
  async function deleteAccount(accountId) {
    const account = accounts.find(a => a.account_id == accountId);
    if (!account) return;
    if (!confirm('Permanently delete account ' + account.account_code + ' - ' + account.account_name + '?\n\nThis cannot be undone.')) return;
    const result = await FinanceAPI.request('/finances/ajax/account_delete.php', 'POST', { account_id: accountId });
    if (result && result.ok) {
      loadAccounts();
    } else {
      alert((result && result.error) || 'Failed to delete account');
    }
  }

  // --- CSV Import ------------------------------------------------------------
  async function importCSV(file) {
    const formData = new FormData();
    formData.append('csv', file);

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    if (csrfMeta) headers['X-CSRF-Token'] = csrfMeta.content;

    try {
      const resp = await fetch('/finances/ajax/account_import.php', {
        method: 'POST', body: formData, headers: headers, credentials: 'same-origin'
      });
      const result = await resp.json();
      if (result.ok) {
        alert('Imported ' + result.data.imported + ' account(s) successfully.');
        loadAccounts();
      } else {
        let msg = result.error || 'Import failed';
        if (result.details) msg += '\n\n' + result.details.join('\n');
        alert(msg);
      }
    } catch (err) {
      alert('Import failed: ' + err.message);
    }
  }

  // --- Export CSV (enhanced) -------------------------------------------------
  function exportToCSV() {
    const filtered = filterAccounts();
    let csv = 'Code,Name,Type,Subtype,Normal Balance,Parent Code,Tax Code,AFS Line Code,Description,YTD Balance,Active,System,Locked,Control\n';
    // CSV-safe escape: quote value and neutralise spreadsheet formula injection
    const esc = s => {
      let v = String(s ?? '');
      if (/^[=+\-@\t\r]/.test(v)) v = '\t' + v;     // neutralise formula
      return '"' + v.replace(/"/g, '""') + '"';
    };
    filtered.forEach(a => {
      const parent = a.parent_id ? (accounts.find(p => p.account_id == a.parent_id)?.account_code || '') : '';
      const tc = a.tax_code_id ? (taxCodes.find(t => t.tax_code_id == a.tax_code_id)?.code || '') : '';
      csv += [
        esc(a.account_code), esc(a.account_name), esc(a.account_type),
        esc(a.account_subtype), esc(a.normal_balance),
        esc(parent), esc(tc), esc(a.afs_line_code),
        esc(a.description),
        a.ytd_balance || 0, a.is_active == 1 ? 'Yes' : 'No',
        a.is_system == 1 ? 'Yes' : 'No', a.is_locked == 1 ? 'Yes' : 'No',
        a.is_control == 1 ? 'Yes' : 'No',
      ].join(',') + '\n';
    });
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'chart-of-accounts-' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    URL.revokeObjectURL(url);
  }

  // --- Event Listeners -------------------------------------------------------
  function attachAccountEventListeners() {
    document.querySelectorAll('.edit-account').forEach(btn => {
      btn.addEventListener('click', e => { e.stopPropagation(); openEditModal(btn.dataset.id); });
    });
    document.querySelectorAll('.toggle-status').forEach(btn => {
      btn.addEventListener('click', e => { e.stopPropagation(); toggleAccountStatus(btn.dataset.id); });
    });
    document.querySelectorAll('.delete-account').forEach(btn => {
      btn.addEventListener('click', e => { e.stopPropagation(); deleteAccount(btn.dataset.id); });
    });
  }

  // Toolbar filters
  if (searchInput) searchInput.addEventListener('input', debounce(e => {
    currentFilters.search = e.target.value;
    renderAccounts(); updateFooter();
  }, 300));

  if (filterType) filterType.addEventListener('change', e => {
    currentFilters.type = e.target.value;
    renderAccounts(); updateFooter();
  });

  if (filterStatus) filterStatus.addEventListener('change', e => {
    currentFilters.status = e.target.value;
    renderAccounts(); updateFooter();
  });

  // Buttons
  if (addAccountBtn) addAccountBtn.addEventListener('click', openAddModal);
  if (exportBtn) exportBtn.addEventListener('click', exportToCSV);
  if (importBtn) importBtn.addEventListener('click', () => importFile?.click());
  if (importFile) importFile.addEventListener('change', e => {
    if (e.target.files[0]) { importCSV(e.target.files[0]); e.target.value = ''; }
  });

  // Form
  if (accountForm) accountForm.addEventListener('submit', saveAccount);
  if (modalClose) modalClose.addEventListener('click', () => FinanceModal.close('accountModal'));
  if (cancelBtn)  cancelBtn.addEventListener('click', () => FinanceModal.close('accountModal'));

  // Account type change → update subtype dropdown + normal balance default + parent dropdown
  const accountTypeSelect = document.getElementById('accountType');
  if (accountTypeSelect) accountTypeSelect.addEventListener('change', () => {
    const type = accountTypeSelect.value;
    populateSubtypeDropdown(type);
    const normalSelect = document.getElementById('normalBalance');
    if (normalSelect && DEFAULT_NORMAL[type]) normalSelect.value = DEFAULT_NORMAL[type];
    populateParentDropdown(document.getElementById('accountId').value);
  });

  // --- Init ------------------------------------------------------------------
  document.addEventListener('DOMContentLoaded', () => {
    loadAccounts();
    loadTaxCodes();
  });

})();
