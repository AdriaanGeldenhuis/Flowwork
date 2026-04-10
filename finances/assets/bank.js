// /finances/assets/bank.js
// Bank Feeds Module — Production-ready, SARS-compliant

(function() {
  'use strict';

  let accounts = [];
  let taxCodes = [];
  let transactions = [];
  let rules = [];

  // Pagination state
  let txPage = 0;
  let txLimit = 50;
  let txTotal = 0;

  // DOM Elements
  const tabButtons = document.querySelectorAll('.fw-finance__tab');
  const addBankAccountBtn = document.getElementById('addBankAccountBtn');
  const addRuleBtn = document.getElementById('addRuleBtn');
  const reconcileBtn = document.getElementById('reconcileBtn');
  const importForm = document.getElementById('importForm');
  const bankAccountForm = document.getElementById('bankAccountForm');
  const ruleForm = document.getElementById('ruleForm');
  const reconcileForm = document.getElementById('reconcileForm');
  const transactionsList = document.getElementById('transactionsList');
  const rulesList = document.getElementById('rulesList');
  const applyRulesBtn = document.getElementById('applyRulesBtn');
  const filterBankAccount = document.getElementById('filterBankAccount');
  const filterMatched = document.getElementById('filterMatched');
  const filterDateFrom = document.getElementById('filterDateFrom');
  const filterDateTo = document.getElementById('filterDateTo');
  const txPagination = document.getElementById('txPagination');
  const txPrevBtn = document.getElementById('txPrevBtn');
  const txNextBtn = document.getElementById('txNextBtn');
  const txPageInfo = document.getElementById('txPageInfo');
  const txBadge = document.getElementById('txBadge');

  // Track loaded tabs to avoid duplicate fetches
  let loadedTabs = {};

  // ── Data Loading ───────────────────────────────────────────────────

  async function loadAccounts() {
    var result = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (result && result.ok) {
      accounts = result.data.filter(function(a) { return a.is_active == 1; });
      populateAccountDropdowns();
    }
  }

  async function loadTaxCodes() {
    var result = await FinanceAPI.request('/finances/ajax/tax_code_list.php');
    if (result && result.ok) {
      taxCodes = result.data.filter(function(t) { return t.is_active == 1; });
      populateTaxCodeDropdowns();
    }
  }

  async function loadCounters() {
    var result = await FinanceAPI.request('/finances/ajax/bank_get_counters.php');
    if (result && result.ok) {
      // Update tab badge
      if (result.total_unmatched > 0 && txBadge) {
        txBadge.textContent = result.total_unmatched;
        txBadge.style.display = 'inline';
      }
      // Update per-account badges
      var counters = result.counters || {};
      document.querySelectorAll('.bank-unmatched-badge').forEach(function(badge) {
        var accId = badge.getAttribute('data-account-id');
        var accCounters = counters[accId];
        if (accCounters) {
          var total = 0;
          for (var k in accCounters) { total += accCounters[k]; }
          if (total > 0) {
            badge.textContent = total + ' unmatched';
            badge.style.display = 'inline';
          }
        }
      });
    }
  }

  // ── Dropdown Population ────────────────────────────────────────────

  function populateAccountDropdowns() {
    var selects = ['glAccountId', 'ruleGlAccount'];
    selects.forEach(function(id) {
      var select = document.getElementById(id);
      if (!select) return;
      var html = '<option value="">Select Account</option>';
      accounts.forEach(function(a) {
        html += '<option value="' + escapeHtml(a.account_id) + '">'
              + escapeHtml(a.account_code) + ' - ' + escapeHtml(a.account_name)
              + '</option>';
      });
      select.innerHTML = html;
    });
  }

  function populateTaxCodeDropdowns() {
    var selects = ['ruleTaxCode'];
    selects.forEach(function(id) {
      var select = document.getElementById(id);
      if (!select) return;
      var html = '<option value="">No Tax Code</option>';
      taxCodes.forEach(function(t) {
        html += '<option value="' + escapeHtml(t.tax_code_id) + '">'
              + escapeHtml(t.code) + ' - ' + escapeHtml(t.description)
              + ' (' + escapeHtml(t.rate_percent) + '%)'
              + '</option>';
      });
      select.innerHTML = html;
    });
  }

  function buildAccountOptions() {
    var html = '<option value="">Select Account</option>';
    accounts.forEach(function(a) {
      html += '<option value="' + escapeHtml(a.account_code) + '">'
            + escapeHtml(a.account_code) + ' - ' + escapeHtml(a.account_name)
            + '</option>';
    });
    return html;
  }

  function buildTaxCodeOptions() {
    var html = '<option value="">No Tax Code</option>';
    taxCodes.forEach(function(t) {
      html += '<option value="' + escapeHtml(t.tax_code_id) + '">'
            + escapeHtml(t.code) + ' (' + escapeHtml(t.rate_percent) + '%)'
            + '</option>';
    });
    return html;
  }

  // ── Transactions ───────────────────────────────────────────────────

  async function loadTransactions() {
    if (!transactionsList) return;

    transactionsList.innerHTML = '<div class="fw-finance__loading">Loading transactions...</div>';

    var params = new URLSearchParams();
    params.set('limit', txLimit);
    params.set('offset', txPage * txLimit);

    var bankAccFilter = filterBankAccount ? filterBankAccount.value : '';
    var matchedFilter = filterMatched ? filterMatched.value : '';
    var dateFrom = filterDateFrom ? filterDateFrom.value : '';
    var dateTo = filterDateTo ? filterDateTo.value : '';

    if (bankAccFilter) params.set('bank_account_id', bankAccFilter);
    if (matchedFilter !== '') params.set('matched', matchedFilter);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);

    var result = await FinanceAPI.request('/finances/ajax/bank_transaction_list.php?' + params.toString());

    if (result && result.ok) {
      transactions = result.data;
      txTotal = result.total || result.data.length;
      renderTransactions();
      renderPagination();
    } else {
      transactionsList.innerHTML = '<div class="fw-finance__empty-state">Failed to load transactions</div>';
    }
  }

  function renderTransactions() {
    if (transactions.length === 0) {
      transactionsList.innerHTML = '<div class="fw-finance__empty-state">No transactions found. Import a statement to get started.</div>';
      return;
    }

    var html = transactions.map(function(tx) {
      var isCredit = parseInt(tx.amount_cents) >= 0;
      var amountClass = isCredit ? 'credit' : 'debit';
      var matched = parseInt(tx.matched) === 1;
      var statusClass = matched ? 'matched' : 'unmatched';
      var statusLabel = matched ? 'Matched' : 'Unmatched';

      var row = '<div class="fw-finance__bank-transaction-item" data-tx-id="' + escapeHtml(tx.bank_tx_id) + '">'
        + '<div class="fw-finance__bank-transaction-date">' + escapeHtml(formatDate(tx.tx_date)) + '</div>'
        + '<div class="fw-finance__bank-transaction-desc">'
        + '  <div class="fw-finance__bank-transaction-desc-main">' + escapeHtml(tx.description || '') + '</div>'
        + '  <div class="fw-finance__bank-transaction-desc-sub">'
        + escapeHtml(tx.bank_account_name || '')
        + (tx.reference ? ' &bull; Ref: ' + escapeHtml(tx.reference) : '')
        + '  </div>'
        + '</div>'
        + '<div class="fw-finance__bank-transaction-amount fw-finance__bank-transaction-amount--' + amountClass + '">'
        + (isCredit ? '+' : '-') + formatCurrency(Math.abs(parseInt(tx.amount_cents)))
        + '</div>'
        + '<span class="fw-finance__bank-transaction-status fw-finance__bank-transaction-status--' + statusClass + '">'
        + statusLabel
        + '</span>'
        + '<div class="fw-finance__bank-transaction-actions">';

      if (!matched) {
        row += '<button class="fw-finance__bank-transaction-btn match-tx-btn" data-id="' + escapeHtml(tx.bank_tx_id) + '" title="Match & Post">'
          + '<svg viewBox="0 0 24 24" fill="none"><path d="M9 12l2 2 4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/></svg>'
          + '</button>';
      } else {
        row += '<button class="fw-finance__bank-transaction-btn undo-match-btn" data-id="' + escapeHtml(tx.bank_tx_id) + '" title="Undo Match">'
          + '<svg viewBox="0 0 24 24" fill="none"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 3v5h5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
          + '</button>';
      }

      row += '</div></div>';

      // Inline match panel (hidden by default)
      if (!matched) {
        row += '<div class="fw-finance__bank-match-panel" id="matchPanel_' + escapeHtml(tx.bank_tx_id) + '" style="display:none">'
          + '<div class="fw-finance__form-row">'
          + '  <div class="fw-finance__form-group" style="flex:2">'
          + '    <label class="fw-finance__label">GL Account <span class="fw-finance__required">*</span></label>'
          + '    <select class="fw-finance__input match-gl-account">' + buildAccountOptions() + '</select>'
          + '  </div>'
          + '  <div class="fw-finance__form-group" style="flex:1">'
          + '    <label class="fw-finance__label">Tax Code</label>'
          + '    <select class="fw-finance__input match-tax-code">' + buildTaxCodeOptions() + '</select>'
          + '  </div>'
          + '</div>'
          + '<div class="fw-finance__form-actions" style="margin-top:8px">'
          + '  <button class="fw-finance__btn fw-finance__btn--primary fw-finance__btn--small confirm-match-btn" data-id="' + escapeHtml(tx.bank_tx_id) + '">Confirm Match</button>'
          + '  <button class="fw-finance__btn fw-finance__btn--secondary fw-finance__btn--small cancel-match-btn" data-id="' + escapeHtml(tx.bank_tx_id) + '">Cancel</button>'
          + '</div>'
          + '</div>';
      }

      return row;
    }).join('');

    transactionsList.innerHTML = html;
    attachTransactionEvents();
  }

  function renderPagination() {
    if (!txPagination) return;
    var totalPages = Math.ceil(txTotal / txLimit);
    if (totalPages <= 1) {
      txPagination.style.display = 'none';
      return;
    }
    txPagination.style.display = 'flex';
    txPrevBtn.disabled = txPage === 0;
    txNextBtn.disabled = txPage >= totalPages - 1;
    txPageInfo.textContent = 'Page ' + (txPage + 1) + ' of ' + totalPages + ' (' + txTotal + ' total)';
  }

  function attachTransactionEvents() {
    // Match buttons — open inline match panel
    document.querySelectorAll('.match-tx-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var txId = btn.getAttribute('data-id');
        var panel = document.getElementById('matchPanel_' + txId);
        if (panel) {
          // Close any other open panels
          document.querySelectorAll('.fw-finance__bank-match-panel').forEach(function(p) {
            p.style.display = 'none';
          });
          panel.style.display = 'block';
        }
      });
    });

    // Confirm match
    document.querySelectorAll('.confirm-match-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var txId = btn.getAttribute('data-id');
        var panel = document.getElementById('matchPanel_' + txId);
        var accountCode = panel.querySelector('.match-gl-account').value;
        var taxCodeId = panel.querySelector('.match-tax-code').value;
        if (!accountCode) {
          showMessage('statusText', 'Please select a GL account', 'error');
          return;
        }
        matchTransaction(txId, accountCode, taxCodeId);
      });
    });

    // Cancel match
    document.querySelectorAll('.cancel-match-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var txId = btn.getAttribute('data-id');
        var panel = document.getElementById('matchPanel_' + txId);
        if (panel) panel.style.display = 'none';
      });
    });

    // Undo match buttons
    document.querySelectorAll('.undo-match-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var txId = btn.getAttribute('data-id');
        undoMatch(txId);
      });
    });
  }

  // ── Match / Undo ───────────────────────────────────────────────────

  async function matchTransaction(txId, accountCode, taxCodeId) {
    var result = await FinanceAPI.request('/finances/ajax/bank_match_transaction.php', 'POST', {
      bank_tx_id: txId,
      account_code: accountCode,
      tax_code_id: taxCodeId || null
    });

    if (result && result.ok) {
      showMessage('statusText', 'Transaction matched and posted to GL');
      loadTransactions();
      loadCounters();
    } else {
      showMessage('statusText', (result && result.error) || 'Failed to match transaction', 'error');
    }
  }

  async function undoMatch(txId) {
    if (!confirm('Undo this match? The journal entry will be reversed.')) return;

    var result = await FinanceAPI.request('/finances/ajax/bank_undo_match.php', 'POST', {
      bank_tx_id: txId
    });

    if (result && result.ok) {
      showMessage('statusText', 'Match reversed successfully');
      loadTransactions();
      loadCounters();
    } else {
      showMessage('statusText', (result && result.error) || 'Failed to undo match', 'error');
    }
  }

  // ── Rules ──────────────────────────────────────────────────────────

  async function loadRules() {
    if (!rulesList) return;

    rulesList.innerHTML = '<div class="fw-finance__loading">Loading rules...</div>';

    var result = await FinanceAPI.request('/finances/ajax/bank_rule_list.php');

    if (result && result.ok) {
      rules = result.data;
      renderRules();
    } else {
      rulesList.innerHTML = '<div class="fw-finance__empty-state">Failed to load rules</div>';
    }
  }

  function renderRules() {
    if (rules.length === 0) {
      rulesList.innerHTML = '<div class="fw-finance__empty-state">No rules configured. Add rules to auto-match transactions.</div>';
      return;
    }

    var html = rules.map(function(rule) {
      var isActive = parseInt(rule.is_active) === 1;
      return '<div class="fw-finance__bank-rule-item">'
        + '<div class="fw-finance__bank-rule-icon">&#x2699;</div>'
        + '<div class="fw-finance__bank-rule-content">'
        + '  <div class="fw-finance__bank-rule-name">' + escapeHtml(rule.rule_name) + '</div>'
        + '  <div class="fw-finance__bank-rule-conditions">'
        + '    <span class="fw-finance__bank-rule-condition-badge">'
        + escapeHtml(rule.match_field) + ' ' + escapeHtml(rule.match_operator) + ' &ldquo;' + escapeHtml(rule.match_value) + '&rdquo;'
        + '    </span>'
        + '    <span>&rarr; ' + escapeHtml(rule.account_code) + ' - ' + escapeHtml(rule.account_name) + '</span>'
        + (rule.tax_code_id ? ' <span class="fw-finance__bank-rule-condition-badge">Tax: ' + escapeHtml(rule.tax_code || rule.tax_code_id) + '</span>' : '')
        + '  </div>'
        + '</div>'
        + '<div class="fw-finance__bank-rule-actions">'
        + '  <button class="fw-finance__bank-rule-btn toggle-rule-btn" data-id="' + escapeHtml(rule.id) + '" title="' + (isActive ? 'Deactivate' : 'Activate') + '">'
        + (isActive
            ? '<svg viewBox="0 0 24 24" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>')
        + '  </button>'
        + '  <button class="fw-finance__bank-rule-btn fw-finance__bank-rule-btn--delete delete-rule-btn" data-id="' + escapeHtml(rule.id) + '" title="Delete Rule">'
        + '    <svg viewBox="0 0 24 24" fill="none"><polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
        + '  </button>'
        + '</div>'
        + '</div>';
    }).join('');

    rulesList.innerHTML = html;
    attachRuleEvents();
  }

  function attachRuleEvents() {
    document.querySelectorAll('.toggle-rule-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        toggleRule(btn.getAttribute('data-id'));
      });
    });
    document.querySelectorAll('.delete-rule-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        deleteRule(btn.getAttribute('data-id'));
      });
    });
  }

  async function toggleRule(ruleId) {
    var result = await FinanceAPI.request('/finances/ajax/bank_rule_toggle.php', 'POST', {
      rule_id: ruleId
    });
    if (result && result.ok) {
      loadRules();
    } else {
      showMessage('statusText', (result && result.error) || 'Failed to toggle rule', 'error');
    }
  }

  async function deleteRule(ruleId) {
    if (!confirm('Delete this rule? This cannot be undone.')) return;

    var result = await FinanceAPI.request('/finances/ajax/bank_rule_delete.php', 'POST', {
      rule_id: ruleId
    });
    if (result && result.ok) {
      showMessage('statusText', 'Rule deleted');
      loadRules();
    } else {
      showMessage('statusText', (result && result.error) || 'Failed to delete rule', 'error');
    }
  }

  // ── Apply Rules ────────────────────────────────────────────────────

  async function applyRules() {
    if (!confirm('Apply all active rules to unmatched transactions?')) return;

    applyRulesBtn.disabled = true;
    applyRulesBtn.textContent = 'Applying...';

    var result = await FinanceAPI.request('/finances/ajax/bank_apply_rules.php', 'POST');

    applyRulesBtn.disabled = false;
    applyRulesBtn.textContent = 'Apply Rules';

    if (result && result.ok) {
      var count = result.matched || result.data && result.data.matched || 0;
      showMessage('statusText', count + ' transactions matched by rules');
      loadTransactions();
      loadCounters();
    } else {
      showMessage('statusText', (result && result.error) || 'Failed to apply rules', 'error');
    }
  }

  // ── Import (CSV + PDF) ──────────────────────────────────────────────

  var pdfParsedTransactions = []; // Stores parsed PDF transactions for confirm step

  async function handleImport(e) {
    e.preventDefault();

    var bankAccountId = document.getElementById('importBankAccount').value;
    var fileInput = document.getElementById('statementFile');
    var file = fileInput.files[0];

    if (!bankAccountId || !file) {
      showMessage('importMessage', 'Please select bank account and file', 'error');
      return;
    }

    var isPDF = file.name.toLowerCase().endsWith('.pdf') || file.type === 'application/pdf';

    if (isPDF) {
      await handlePDFImport(file, bankAccountId);
    } else {
      await handleCSVImport(file, bankAccountId);
    }
  }

  async function handleCSVImport(file, bankAccountId) {
    var submitBtn = document.getElementById('importSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';

    var formData = new FormData();
    formData.append('bank_account_id', bankAccountId);
    formData.append('csv_file', file);

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
      formData.append('csrf_token', csrfMeta.content);
    }

    try {
      var response = await fetch('/finances/ajax/bank_import.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });

      var result = await response.json();

      if (result.ok) {
        var msg = 'Imported ' + result.data.count + ' transactions';
        if (result.data.skipped) {
          msg += ' (' + result.data.skipped + ' skipped: duplicates or invalid)';
        }
        showMessage('importMessage', msg, 'success');
        importForm.reset();
        loadCounters();
        loadedTabs.transactions = false;
        setTimeout(function() {
          document.querySelector('[data-tab="transactions"]').click();
        }, 1500);
      } else {
        showMessage('importMessage', result.error || 'Import failed', 'error');
      }
    } catch (error) {
      showMessage('importMessage', 'Upload failed: ' + error.message, 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Upload & Parse';
    }
  }

  async function handlePDFImport(file, bankAccountId) {
    var submitBtn = document.getElementById('importSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Parsing PDF...';

    var formData = new FormData();
    formData.append('pdf_file', file);

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
      formData.append('csrf_token', csrfMeta.content);
    }

    try {
      var response = await fetch('/finances/ajax/bank_import_parse_pdf.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
      });

      var result = await response.json();

      if (result.ok && result.transactions && result.transactions.length > 0) {
        pdfParsedTransactions = result.transactions;
        showPDFPreview(result, file.name);
        showMessage('importMessage', 'PDF parsed successfully. Review the transactions below.', 'success');
      } else {
        var errMsg = result.error || 'No transactions found in PDF.';
        if (result.warnings && result.warnings.length > 0) {
          errMsg += ' ' + result.warnings.join('; ');
        }
        showMessage('importMessage', errMsg, 'error');
      }
    } catch (error) {
      showMessage('importMessage', 'PDF parsing failed: ' + error.message, 'error');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Upload & Parse';
    }
  }

  function showPDFPreview(result, fileName) {
    var previewCard = document.getElementById('pdfPreviewCard');
    var bankBadge = document.getElementById('pdfBankBadge');
    var warningsDiv = document.getElementById('pdfWarnings');
    var tbody = document.getElementById('pdfPreviewBody');
    var countSpan = document.getElementById('pdfSelectedCount');

    if (!previewCard) return;

    // Show detected bank
    var bankNames = {
      fnb: 'FNB', absa: 'ABSA', nedbank: 'Nedbank',
      standard_bank: 'Standard Bank', capitec: 'Capitec',
      investec: 'Investec', tymebank: 'TymeBank',
      discovery: 'Discovery Bank', generic: 'Unknown Bank'
    };
    bankBadge.textContent = bankNames[result.bank] || result.bank;

    // Show warnings
    if (result.warnings && result.warnings.length > 0) {
      warningsDiv.innerHTML = '<div class="fw-finance__alert fw-finance__alert--warning">'
        + result.warnings.map(function(w) { return escapeHtml(w); }).join('<br>')
        + '</div>';
    } else {
      warningsDiv.innerHTML = '';
    }

    // Build preview table rows
    var html = '';
    result.transactions.forEach(function(tx, idx) {
      var isDebit = tx.amount_cents < 0;
      var amountClass = isDebit ? 'color:#ef4444' : 'color:var(--neon-green, #4ade80)';
      html += '<tr>'
        + '<td><input type="checkbox" class="pdf-tx-check" data-idx="' + idx + '" checked></td>'
        + '<td>' + escapeHtml(tx.tx_date || '') + '</td>'
        + '<td>' + escapeHtml(tx.description || '') + '</td>'
        + '<td style="text-align:right;font-weight:600;' + amountClass + '">'
        + (isDebit ? '-' : '+') + 'R ' + escapeHtml(tx.amount_display || '0.00')
        + '</td>'
        + '</tr>';
    });
    tbody.innerHTML = html;

    // Update count
    countSpan.textContent = result.transactions.length + ' transactions found';

    // Store file name for confirm
    previewCard.setAttribute('data-filename', fileName);

    // Show preview card
    previewCard.style.display = 'block';

    // Attach select-all handler
    var selectAll = document.getElementById('pdfSelectAll');
    if (selectAll) {
      selectAll.checked = true;
      selectAll.onchange = function() {
        document.querySelectorAll('.pdf-tx-check').forEach(function(cb) {
          cb.checked = selectAll.checked;
        });
        updatePDFCount();
      };
    }

    // Attach individual checkbox handlers
    document.querySelectorAll('.pdf-tx-check').forEach(function(cb) {
      cb.onchange = updatePDFCount;
    });
  }

  function updatePDFCount() {
    var checked = document.querySelectorAll('.pdf-tx-check:checked').length;
    var total = document.querySelectorAll('.pdf-tx-check').length;
    var countSpan = document.getElementById('pdfSelectedCount');
    if (countSpan) countSpan.textContent = checked + ' of ' + total + ' selected';
  }

  async function confirmPDFImport() {
    var bankAccountId = document.getElementById('importBankAccount').value;
    if (!bankAccountId) {
      showMessage('importMessage', 'Please select a bank account', 'error');
      return;
    }

    // Collect only checked transactions
    var selectedTx = [];
    document.querySelectorAll('.pdf-tx-check:checked').forEach(function(cb) {
      var idx = parseInt(cb.getAttribute('data-idx'));
      if (pdfParsedTransactions[idx]) {
        selectedTx.push(pdfParsedTransactions[idx]);
      }
    });

    if (selectedTx.length === 0) {
      showMessage('importMessage', 'No transactions selected', 'error');
      return;
    }

    var confirmBtn = document.getElementById('pdfConfirmBtn');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Importing...';

    var previewCard = document.getElementById('pdfPreviewCard');
    var fileName = previewCard ? previewCard.getAttribute('data-filename') : 'pdf_import.pdf';

    var result = await FinanceAPI.request('/finances/ajax/bank_import_confirm.php', 'POST', {
      bank_account_id: parseInt(bankAccountId),
      transactions: selectedTx,
      source_file: fileName
    });

    confirmBtn.disabled = false;
    confirmBtn.textContent = 'Confirm Import';

    if (result && result.ok) {
      var msg = 'Imported ' + result.data.count + ' transactions from PDF';
      if (result.data.skipped > 0) {
        msg += ' (' + result.data.skipped + ' skipped: duplicates)';
      }
      showMessage('importMessage', msg, 'success');
      hidePDFPreview();
      importForm.reset();
      pdfParsedTransactions = [];
      loadCounters();
      loadedTabs.transactions = false;
      setTimeout(function() {
        document.querySelector('[data-tab="transactions"]').click();
      }, 1500);
    } else {
      showMessage('importMessage', (result && result.error) || 'Import failed', 'error');
    }
  }

  function hidePDFPreview() {
    var previewCard = document.getElementById('pdfPreviewCard');
    if (previewCard) previewCard.style.display = 'none';
    pdfParsedTransactions = [];
  }

  // ── Save Bank Account ──────────────────────────────────────────────

  async function saveBankAccount(e) {
    e.preventDefault();

    var data = {
      name: document.getElementById('bankAccountName').value,
      bank_name: document.getElementById('bankName').value,
      account_no: document.getElementById('accountNo').value,
      gl_account_id: document.getElementById('glAccountId').value,
      opening_balance: parseFloat(document.getElementById('openingBalance').value) || 0
    };

    var result = await FinanceAPI.request('/finances/ajax/bank_account_save.php', 'POST', data);

    if (result && result.ok) {
      showMessage('bankAccountMessage', 'Bank account saved successfully', 'success');
      setTimeout(function() {
        FinanceModal.close('bankAccountModal');
        location.reload();
      }, 1000);
    } else {
      showMessage('bankAccountMessage', (result && result.error) || 'Failed to save', 'error');
    }
  }

  // ── Save Rule ──────────────────────────────────────────────────────

  async function saveRule(e) {
    e.preventDefault();

    var data = {
      rule_name: document.getElementById('ruleName').value,
      match_field: document.getElementById('matchField').value,
      match_operator: document.getElementById('matchOperator').value,
      match_value: document.getElementById('matchValue').value,
      gl_account_id: document.getElementById('ruleGlAccount').value,
      tax_code_id: document.getElementById('ruleTaxCode').value || null,
      description_template: document.getElementById('descriptionTemplate').value
    };

    var result = await FinanceAPI.request('/finances/ajax/bank_rule_save.php', 'POST', data);

    if (result && result.ok) {
      showMessage('ruleMessage', 'Rule saved successfully', 'success');
      setTimeout(function() {
        FinanceModal.close('ruleModal');
        ruleForm.reset();
        loadRules();
      }, 1000);
    } else {
      showMessage('ruleMessage', (result && result.error) || 'Failed to save', 'error');
    }
  }

  // ── Reconcile ──────────────────────────────────────────────────────

  async function handleReconcile(e) {
    e.preventDefault();

    var data = {
      bank_account_id: document.getElementById('reconcileBankAccount').value,
      statement_date: document.getElementById('reconcileDate').value,
      closing_balance: parseFloat(document.getElementById('reconcileBalance').value) || null
    };

    if (!data.bank_account_id || !data.statement_date) {
      showMessage('reconcileMessage', 'Bank account and statement date are required', 'error');
      return;
    }

    var result = await FinanceAPI.request('/finances/ajax/bank_reconcile_close.php', 'POST', data);

    if (result && result.ok) {
      var msg = 'Reconciliation closed.';
      if (result.unmatched > 0) {
        msg += ' Warning: ' + result.unmatched + ' unmatched transactions remain on or before the statement date.';
      }
      showMessage('reconcileMessage', msg, result.unmatched > 0 ? 'warning' : 'success');
      setTimeout(function() {
        FinanceModal.close('reconcileModal');
        location.reload();
      }, 2000);
    } else {
      showMessage('reconcileMessage', (result && result.error) || 'Failed to close reconciliation', 'error');
    }
  }

  // ── Tab Switching ──────────────────────────────────────────────────

  tabButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tab = btn.getAttribute('data-tab');

      tabButtons.forEach(function(b) { b.classList.remove('fw-finance__tab--active'); });
      btn.classList.add('fw-finance__tab--active');

      document.querySelectorAll('.fw-finance__tab-panel').forEach(function(panel) {
        panel.classList.remove('fw-finance__tab-panel--active');
      });

      var panel = document.getElementById(tab + 'Panel');
      if (panel) panel.classList.add('fw-finance__tab-panel--active');

      if (tab === 'transactions' && !loadedTabs.transactions) {
        loadedTabs.transactions = true;
        loadTransactions();
      } else if (tab === 'rules' && !loadedTabs.rules) {
        loadedTabs.rules = true;
        loadRules();
      }
    });
  });

  // ── Filter & Pagination Events ─────────────────────────────────────

  var debouncedLoadTx = debounce(function() {
    txPage = 0;
    loadedTabs.transactions = true;
    loadTransactions();
  }, 300);

  if (filterBankAccount) filterBankAccount.addEventListener('change', debouncedLoadTx);
  if (filterMatched) filterMatched.addEventListener('change', debouncedLoadTx);
  if (filterDateFrom) filterDateFrom.addEventListener('change', debouncedLoadTx);
  if (filterDateTo) filterDateTo.addEventListener('change', debouncedLoadTx);

  if (txPrevBtn) {
    txPrevBtn.addEventListener('click', function() {
      if (txPage > 0) { txPage--; loadTransactions(); }
    });
  }
  if (txNextBtn) {
    txNextBtn.addEventListener('click', function() {
      if ((txPage + 1) * txLimit < txTotal) { txPage++; loadTransactions(); }
    });
  }

  // ── Event Listeners ────────────────────────────────────────────────

  if (addBankAccountBtn) {
    addBankAccountBtn.addEventListener('click', function() {
      bankAccountForm.reset();
      FinanceModal.open('bankAccountModal');
    });
  }

  if (addRuleBtn) {
    addRuleBtn.addEventListener('click', function() {
      ruleForm.reset();
      FinanceModal.open('ruleModal');
    });
  }

  if (reconcileBtn) {
    reconcileBtn.addEventListener('click', function() {
      if (reconcileForm) reconcileForm.reset();
      FinanceModal.open('reconcileModal');
    });
  }

  if (importForm) importForm.addEventListener('submit', handleImport);

  // PDF preview buttons
  var pdfConfirmBtn = document.getElementById('pdfConfirmBtn');
  var pdfCancelBtn = document.getElementById('pdfCancelBtn');
  if (pdfConfirmBtn) pdfConfirmBtn.addEventListener('click', confirmPDFImport);
  if (pdfCancelBtn) pdfCancelBtn.addEventListener('click', hidePDFPreview);
  if (bankAccountForm) bankAccountForm.addEventListener('submit', saveBankAccount);
  if (ruleForm) ruleForm.addEventListener('submit', saveRule);
  if (reconcileForm) reconcileForm.addEventListener('submit', handleReconcile);
  if (applyRulesBtn) applyRulesBtn.addEventListener('click', applyRules);

  // Modal close buttons
  var modalClosePairs = [
    ['modalClose', 'bankAccountModal'],
    ['cancelBtn', 'bankAccountModal'],
    ['ruleModalClose', 'ruleModal'],
    ['ruleCancelBtn', 'ruleModal'],
    ['reconcileModalClose', 'reconcileModal'],
    ['reconcileCancelBtn', 'reconcileModal']
  ];
  modalClosePairs.forEach(function(pair) {
    var el = document.getElementById(pair[0]);
    if (el) {
      el.addEventListener('click', function() { FinanceModal.close(pair[1]); });
    }
  });

  // ── Initialize ─────────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function() {
    loadAccounts();
    loadTaxCodes();
    loadCounters();
  });

})();
