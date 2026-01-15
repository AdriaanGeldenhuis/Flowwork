// /finances/assets/bank.js
// Bank Feeds Module

(function() {
  'use strict';

  let accounts = [];
  let transactions = [];
  let rules = [];

  // DOM Elements
  const tabButtons = document.querySelectorAll('.fw-finance__tab');
  const addBankAccountBtn = document.getElementById('addBankAccountBtn');
  const addRuleBtn = document.getElementById('addRuleBtn');
  const importForm = document.getElementById('importForm');
  const bankAccountForm = document.getElementById('bankAccountForm');
  const ruleForm = document.getElementById('ruleForm');
  const transactionsList = document.getElementById('transactionsList');
  const rulesList = document.getElementById('rulesList');
  const applyRulesBtn = document.getElementById('applyRulesBtn');

  // Load Accounts for dropdowns
  async function loadAccounts() {
    const result = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (result.ok) {
      accounts = result.data.filter(a => a.is_active == 1);
      populateAccountDropdowns();
    }
  }

  // Populate Account Dropdowns
  function populateAccountDropdowns() {
    const selects = ['glAccountId', 'ruleGlAccount'];
    selects.forEach(id => {
      const select = document.getElementById(id);
      if (select) {
        let html = '<option value="">Select Account</option>';
        accounts.forEach(a => {
          html += `<option value="${a.account_id}">${a.account_code} - ${a.account_name}</option>`;
        });
        select.innerHTML = html;
      }
    });
  }

  // Load Transactions
  async function loadTransactions() {
    if (!transactionsList) return;
    
    transactionsList.innerHTML = '<div class="fw-finance__loading">Loading transactions...</div>';

    const result = await FinanceAPI.request('/finances/ajax/bank_transaction_list.php');
    
    if (result.ok) {
      transactions = result.data;
      renderTransactions();
    } else {
      transactionsList.innerHTML = '<div class="fw-finance__empty-state">Failed to load transactions</div>';
    }
  }

  // Render Transactions
  function renderTransactions() {
    if (transactions.length === 0) {
      transactionsList.innerHTML = '<div class="fw-finance__empty-state">No transactions found. Import a statement to get started.</div>';
      return;
    }

    const html = transactions.map(tx => {
      const amountClass = tx.amount_cents >= 0 ? 'positive' : 'negative';
      return `
        <div class="fw-finance__bank-tx-card ${tx.matched ? 'matched' : ''}">
          <div class="fw-finance__bank-tx-header">
            <div>
              <strong>${formatDate(tx.tx_date)}</strong>
              ${tx.matched ? '<span class="fw-finance__badge fw-finance__badge--success">Matched</span>' : '<span class="fw-finance__badge">Unmatched</span>'}
            </div>
            <div class="fw-finance__bank-tx-amount fw-finance__bank-tx-amount--${amountClass}">
              ${formatCurrency(Math.abs(tx.amount_cents))}
            </div>
          </div>
          <div class="fw-finance__bank-tx-body">
            <div>${tx.description}</div>
            ${tx.reference ? `<div class="fw-finance__bank-tx-ref">Ref: ${tx.reference}</div>` : ''}
          </div>
          ${!tx.matched ? `
            <div class="fw-finance__bank-tx-actions">
              <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--primary match-tx" data-id="${tx.bank_tx_id}">
                Match & Post
              </button>
            </div>
          ` : ''}
        </div>
      `;
    }).join('');

    transactionsList.innerHTML = html;
    attachTransactionEvents();
  }

  // Attach Transaction Events
  function attachTransactionEvents() {
    document.querySelectorAll('.match-tx').forEach(btn => {
      btn.addEventListener('click', () => {
        const txId = btn.dataset.id;
        matchTransaction(txId);
      });
    });
  }

  // Match Transaction (simple auto-post)
  async function matchTransaction(txId) {
    const tx = transactions.find(t => t.bank_tx_id == txId);
    if (!tx) return;

    const account = prompt('Enter GL Account Code to post to:', '6900');
    if (!account) return;

    const result = await FinanceAPI.request('/finances/ajax/bank_match_transaction.php', 'POST', {
      bank_tx_id: txId,
      account_code: account
    });

    if (result.ok) {
      alert('Transaction matched and posted to GL!');
      loadTransactions();
    } else {
      alert('Error: ' + (result.error || 'Failed to match transaction'));
    }
  }

  // Load Rules
  async function loadRules() {
    if (!rulesList) return;

    rulesList.innerHTML = '<div class="fw-finance__loading">Loading rules...</div>';

    const result = await FinanceAPI.request('/finances/ajax/bank_rule_list.php');
    
    if (result.ok) {
      rules = result.data;
      renderRules();
    } else {
      rulesList.innerHTML = '<div class="fw-finance__empty-state">Failed to load rules</div>';
    }
  }

  // Render Rules
  function renderRules() {
    if (rules.length === 0) {
      rulesList.innerHTML = '<div class="fw-finance__empty-state">No rules configured. Add rules to auto-match transactions.</div>';
      return;
    }

    const html = rules.map(rule => `
      <div class="fw-finance__rule-card">
        <div class="fw-finance__rule-header">
          <strong>${rule.rule_name}</strong>
          ${rule.is_active == 1 ? '<span class="fw-finance__badge fw-finance__badge--success">Active</span>' : '<span class="fw-finance__badge">Inactive</span>'}
        </div>
        <div class="fw-finance__rule-body">
          <div>Match: ${rule.match_field} ${rule.match_operator} "${rule.match_value}"</div>
          <div>Post to: ${rule.account_code} - ${rule.account_name}</div>
        </div>
      </div>
    `).join('');

    rulesList.innerHTML = html;
  }

  // Apply Rules
  async function applyRules() {
    if (!confirm('Apply all active rules to unmatched transactions?')) return;

    applyRulesBtn.disabled = true;
    applyRulesBtn.innerHTML = '⏳ Applying...';

    const result = await FinanceAPI.request('/finances/ajax/bank_apply_rules.php', 'POST');

    applyRulesBtn.disabled = false;
    applyRulesBtn.innerHTML = '🤖 Apply Rules';

    if (result.ok) {
      alert(`Applied rules: ${result.data.matched} transactions matched`);
      loadTransactions();
    } else {
      alert('Error: ' + (result.error || 'Failed to apply rules'));
    }
  }

  // Import CSV
  async function handleImport(e) {
    e.preventDefault();

    const bankAccountId = document.getElementById('importBankAccount').value;
    const fileInput = document.getElementById('csvFile');
    const file = fileInput.files[0];

    if (!bankAccountId || !file) {
      showMessage('importMessage', 'Please select bank account and file', 'error');
      return;
    }

    const formData = new FormData();
    formData.append('bank_account_id', bankAccountId);
    formData.append('csv_file', file);

    try {
      const response = await fetch('/finances/ajax/bank_import.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.ok) {
        showMessage('importMessage', `Imported ${result.data.count} transactions successfully`, 'success');
        importForm.reset();
        
        setTimeout(() => {
          // Switch to transactions tab
          document.querySelector('[data-tab="transactions"]').click();
          loadTransactions();
        }, 1500);
      } else {
        showMessage('importMessage', result.error || 'Import failed', 'error');
      }
    } catch (error) {
      showMessage('importMessage', 'Upload failed: ' + error.message, 'error');
    }
  }

  // Save Bank Account
  async function saveBankAccount(e) {
    e.preventDefault();

    const data = {
      name: document.getElementById('bankAccountName').value,
      bank_name: document.getElementById('bankName').value,
      account_no: document.getElementById('accountNo').value,
      gl_account_id: document.getElementById('glAccountId').value,
      opening_balance: parseFloat(document.getElementById('openingBalance').value) || 0
    };

    const result = await FinanceAPI.request('/finances/ajax/bank_account_save.php', 'POST', data);

    if (result.ok) {
      showMessage('bankAccountMessage', 'Bank account saved successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('bankAccountModal');
        location.reload();
      }, 1000);
    } else {
      showMessage('bankAccountMessage', result.error || 'Failed to save', 'error');
    }
  }

  // Save Rule
  async function saveRule(e) {
    e.preventDefault();

    const data = {
      rule_name: document.getElementById('ruleName').value,
      match_field: document.getElementById('matchField').value,
      match_operator: document.getElementById('matchOperator').value,
      match_value: document.getElementById('matchValue').value,
      gl_account_id: document.getElementById('ruleGlAccount').value,
      description_template: document.getElementById('descriptionTemplate').value
    };

    const result = await FinanceAPI.request('/finances/ajax/bank_rule_save.php', 'POST', data);

    if (result.ok) {
      showMessage('ruleMessage', 'Rule saved successfully', 'success');
      setTimeout(() => {
        FinanceModal.close('ruleModal');
        loadRules();
      }, 1000);
    } else {
      showMessage('ruleMessage', result.error || 'Failed to save', 'error');
    }
  }

  // Tab Switching
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;

      tabButtons.forEach(b => b.classList.remove('fw-finance__tab--active'));
      btn.classList.add('fw-finance__tab--active');

      document.querySelectorAll('.fw-finance__tab-panel').forEach(panel => {
        panel.classList.remove('fw-finance__tab-panel--active');
      });

      document.getElementById(tab + 'Panel').classList.add('fw-finance__tab-panel--active');

      if (tab === 'transactions') {
        loadTransactions();
      } else if (tab === 'rules') {
        loadRules();
      }
    });
  });

  // Event Listeners
  if (addBankAccountBtn) {
    addBankAccountBtn.addEventListener('click', () => {
      bankAccountForm.reset();
      FinanceModal.open('bankAccountModal');
    });
  }

  if (addRuleBtn) {
    addRuleBtn.addEventListener('click', () => {
      ruleForm.reset();
      FinanceModal.open('ruleModal');
    });
  }

  if (importForm) {
    importForm.addEventListener('submit', handleImport);
  }

  if (bankAccountForm) {
    bankAccountForm.addEventListener('submit', saveBankAccount);
  }

  if (ruleForm) {
    ruleForm.addEventListener('submit', saveRule);
  }

  if (applyRulesBtn) {
    applyRulesBtn.addEventListener('click', applyRules);
  }

  if (document.getElementById('modalClose')) {
    document.getElementById('modalClose').addEventListener('click', () => FinanceModal.close('bankAccountModal'));
  }

  if (document.getElementById('cancelBtn')) {
    document.getElementById('cancelBtn').addEventListener('click', () => FinanceModal.close('bankAccountModal'));
  }

  if (document.getElementById('ruleModalClose')) {
    document.getElementById('ruleModalClose').addEventListener('click', () => FinanceModal.close('ruleModal'));
  }

  if (document.getElementById('ruleCancelBtn')) {
    document.getElementById('ruleCancelBtn').addEventListener('click', () => FinanceModal.close('ruleModal'));
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadAccounts();
  });

})();