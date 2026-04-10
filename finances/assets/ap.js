// /finances/assets/ap.js
// Accounts Payable Module – Production-ready with all tab panels

(function() {
  'use strict';

  var E = window.escapeHtml || function(s) { return s == null ? '' : String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
  var canWrite = window.AP_CONFIG && window.AP_CONFIG.canWrite;

  // Track which tabs have been loaded to avoid duplicate fetches
  var loaded = {};

  // ============================================================
  // Tab Switching
  // ============================================================
  var tabButtons = document.querySelectorAll('.fw-finance__tab');
  var panels = document.querySelectorAll('.fw-finance__tab-panel');

  tabButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tab = btn.dataset.tab;
      // Deactivate all
      tabButtons.forEach(function(b) { b.classList.remove('fw-finance__tab--active'); });
      panels.forEach(function(p) { p.classList.remove('fw-finance__tab-panel--active'); });
      // Activate selected
      btn.classList.add('fw-finance__tab--active');
      var panelId = tab + 'Panel';
      var panel = document.getElementById(panelId);
      if (panel) panel.classList.add('fw-finance__tab-panel--active');
      // Lazy-load data for the tab
      loadTab(tab);
    });
  });

  function loadTab(tab) {
    switch (tab) {
      case 'manual':   if (!loaded.manual) { loadManualTab(); loaded.manual = true; } break;
      case 'receipts': if (!loaded.receipts) { loadReceiptsTab(); loaded.receipts = true; } break;
      case 'bills':    if (!loaded.bills) { loadBillsTab(); loaded.bills = true; } break;
      case 'payments': if (!loaded.payments) { loadPaymentsTab(); loaded.payments = true; } break;
      case 'credits':  if (!loaded.credits) { loadCreditsTab(); loaded.credits = true; } break;
      case 'aging':    if (!loaded.aging) { loadAgingTab(); loaded.aging = true; } break;
      case 'statements': break; // loaded on-demand via Generate button
      case 'threeway': break; // loaded on-demand via Load button
    }
  }

  // ============================================================
  // Manual Bill Entry Tab
  // ============================================================
  var manualBillForm = document.getElementById('manualBillForm');
  var supplierSelect = document.getElementById('supplierId');
  var expenseAccountSelect = document.getElementById('expenseAccount');
  var billAmountInput = document.getElementById('billAmount');
  var vatAmountInput = document.getElementById('vatAmount');
  var totalAmountInput = document.getElementById('totalAmount');

  function loadManualTab() {
    if (!manualBillForm) return;
    loadSuppliers();
    loadAccounts();
  }

  async function loadSuppliers() {
    if (!supplierSelect) return;
    var result = await FinanceAPI.request('/finances/ajax/ap_supplier_list.php');
    if (result && result.ok) {
      var html = '<option value="">Select Supplier</option>';
      result.data.forEach(function(s) { html += '<option value="' + E(s.id) + '">' + E(s.name) + '</option>'; });
      supplierSelect.innerHTML = html;
    }
  }

  async function loadAccounts() {
    if (!expenseAccountSelect) return;
    var result = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (result && result.ok) {
      var accounts = result.data.filter(function(a) { return a.is_active == 1 && (a.account_type === 'expense' || a.account_type === 'asset'); });
      var html = '<option value="">Select Account</option>';
      accounts.forEach(function(a) { html += '<option value="' + E(a.account_id) + '">' + E(a.account_code) + ' - ' + E(a.account_name) + '</option>'; });
      expenseAccountSelect.innerHTML = html;
    }
  }

  function calculateTotal() {
    if (!billAmountInput || !vatAmountInput || !totalAmountInput) return;
    var amount = parseFloat(billAmountInput.value) || 0;
    var vat = parseFloat(vatAmountInput.value) || 0;
    totalAmountInput.value = (amount + vat).toFixed(2);
  }

  if (billAmountInput) billAmountInput.addEventListener('input', calculateTotal);
  if (vatAmountInput) vatAmountInput.addEventListener('input', calculateTotal);

  if (manualBillForm) {
    manualBillForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      var data = {
        supplier_id: supplierSelect.value,
        bill_date: document.getElementById('billDate').value,
        invoice_number: document.getElementById('invoiceNumber').value,
        due_date: document.getElementById('dueDate').value,
        expense_account_id: expenseAccountSelect.value,
        amount: parseFloat(billAmountInput.value) || 0,
        vat_amount: parseFloat(vatAmountInput.value) || 0,
        description: document.getElementById('description').value
      };
      if (!data.supplier_id || !data.expense_account_id || data.amount <= 0) {
        showMessage('billFormMessage', 'Please fill in all required fields', 'error');
        return;
      }
      var result = await FinanceAPI.request('/finances/ajax/ap_post_bill.php', 'POST', data);
      if (result && result.ok) {
        showMessage('billFormMessage', 'Bill posted to GL successfully!', 'success');
        manualBillForm.reset();
        document.getElementById('billDate').value = new Date().toISOString().split('T')[0];
        calculateTotal();
      } else {
        showMessage('billFormMessage', (result && result.error) || 'Failed to post bill', 'error');
      }
    });
  }

  // ============================================================
  // Receipts Tab
  // ============================================================
  function loadReceiptsTab() {
    var container = document.getElementById('receiptsContent');
    if (!container) return;
    container.innerHTML = '<div class="fw-finance__empty-state">Receipts integration: Use the <a href="/receipts/">Receipts Module</a> to scan and OCR supplier invoices, then convert them to AP bills from the review screen.</div>';
  }

  // ============================================================
  // Bills List Tab
  // ============================================================
  async function loadBillsTab(status) {
    var container = document.getElementById('billsListContent');
    if (!container) return;
    container.innerHTML = '<div class="fw-finance__loading">Loading bills...</div>';
    var url = '/finances/ap/api/bill_list.php';
    if (status) url += '?status=' + encodeURIComponent(status);
    try {
      var res = await fetch(url);
      var result = await res.json();
      if (!result.ok) { container.innerHTML = '<div class="fw-finance__error">Failed to load bills</div>'; return; }
      var bills = result.data || [];
      if (bills.length === 0) { container.innerHTML = '<div class="fw-finance__empty-state">No bills found.</div>'; return; }
      var html = '<table class="fw-finance__table"><thead><tr><th>Invoice #</th><th>Supplier</th><th>Issue Date</th><th>Due Date</th><th>Status</th><th style="text-align:right">Total</th><th style="text-align:right">Paid</th><th style="text-align:right">Balance</th><th>Action</th></tr></thead><tbody>';
      bills.forEach(function(b) {
        html += '<tr><td>' + E(b.vendor_invoice_number) + '</td><td>' + E(b.supplier_name) + '</td><td>' + E(b.issue_date) + '</td><td>' + E(b.due_date) + '</td><td>' + E(b.status) + '</td><td style="text-align:right">' + parseFloat(b.total).toFixed(2) + '</td><td style="text-align:right">' + parseFloat(b.paid).toFixed(2) + '</td><td style="text-align:right">' + parseFloat(b.balance).toFixed(2) + '</td><td><a href="/finances/ap/bill_view.php?id=' + parseInt(b.id) + '">View</a></td></tr>';
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    } catch (err) {
      container.innerHTML = '<div class="fw-finance__error">' + E(err.message) + '</div>';
    }
  }

  var billStatusFilter = document.getElementById('billStatusFilter');
  if (billStatusFilter) {
    billStatusFilter.addEventListener('change', function() { loaded.bills = false; loadBillsTab(this.value); loaded.bills = true; });
  }
  var billSearchInput = document.getElementById('billSearchInput');
  if (billSearchInput) {
    billSearchInput.addEventListener('input', debounce(function() {
      var query = this.value.toLowerCase();
      var rows = document.querySelectorAll('#billsListContent tbody tr');
      rows.forEach(function(row) { row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none'; });
    }, 300));
  }

  // ============================================================
  // Payments Tab
  // ============================================================
  async function loadPaymentsTab(supplierId) {
    var container = document.getElementById('paymentsListContent');
    if (!container) return;
    container.innerHTML = '<div class="fw-finance__loading">Loading payments...</div>';
    var url = '/finances/ap/api/payment_list.php';
    if (supplierId) url += '?supplier_id=' + encodeURIComponent(supplierId);
    try {
      var res = await fetch(url);
      var result = await res.json();
      if (!result.ok) { container.innerHTML = '<div class="fw-finance__error">Failed to load payments</div>'; return; }
      var rows = result.data || [];
      if (rows.length === 0) { container.innerHTML = '<div class="fw-finance__empty-state">No payments found.</div>'; return; }
      var html = '<table class="fw-finance__table"><thead><tr><th>Date</th><th>Supplier</th><th>Method</th><th>Reference</th><th style="text-align:right">Amount</th></tr></thead><tbody>';
      rows.forEach(function(p) {
        html += '<tr><td>' + E(p.payment_date) + '</td><td>' + E(p.supplier_name) + '</td><td>' + E(p.method) + '</td><td>' + E(p.reference) + '</td><td style="text-align:right">' + parseFloat(p.amount).toFixed(2) + '</td></tr>';
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    } catch (err) {
      container.innerHTML = '<div class="fw-finance__error">' + E(err.message) + '</div>';
    }
  }

  var paymentSupplierFilter = document.getElementById('paymentSupplierFilter');
  if (paymentSupplierFilter) {
    paymentSupplierFilter.addEventListener('change', function() { loadPaymentsTab(this.value); });
  }

  // ============================================================
  // Vendor Credits Tab
  // ============================================================
  async function loadCreditsTab(status) {
    var container = document.getElementById('creditsListContent');
    if (!container) return;
    container.innerHTML = '<div class="fw-finance__loading">Loading vendor credits...</div>';
    var url = '/finances/ap/api/vendor_credit_list.php';
    if (status) url += '?status=' + encodeURIComponent(status);
    try {
      var res = await fetch(url);
      var result = await res.json();
      if (!result.ok) { container.innerHTML = '<div class="fw-finance__error">Failed to load credits</div>'; return; }
      var rows = result.data || [];
      if (rows.length === 0) { container.innerHTML = '<div class="fw-finance__empty-state">No vendor credits found.</div>'; return; }
      var html = '<table class="fw-finance__table"><thead><tr><th>Credit #</th><th>Supplier</th><th>Issue Date</th><th>Status</th><th style="text-align:right">Total</th><th style="text-align:right">Allocated</th><th style="text-align:right">Remaining</th><th>Action</th></tr></thead><tbody>';
      rows.forEach(function(c) {
        html += '<tr><td>' + E(c.credit_number) + '</td><td>' + E(c.supplier_name) + '</td><td>' + E(c.issue_date) + '</td><td>' + E(c.status) + '</td><td style="text-align:right">' + parseFloat(c.total).toFixed(2) + '</td><td style="text-align:right">' + parseFloat(c.allocated).toFixed(2) + '</td><td style="text-align:right">' + parseFloat(c.remaining).toFixed(2) + '</td><td><a href="/finances/ap/vendor_credit_view.php?id=' + parseInt(c.id) + '">View</a></td></tr>';
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    } catch (err) {
      container.innerHTML = '<div class="fw-finance__error">' + E(err.message) + '</div>';
    }
  }

  var creditStatusFilter = document.getElementById('creditStatusFilter');
  if (creditStatusFilter) {
    creditStatusFilter.addEventListener('change', function() { loadCreditsTab(this.value); });
  }

  // ============================================================
  // Aging Tab
  // ============================================================
  async function loadAgingTab() {
    var container = document.getElementById('agingContent');
    if (!container) return;
    container.innerHTML = '<div class="fw-finance__loading">Generating aging report...</div>';
    try {
      var res = await fetch('/finances/ap/api/ap_aging.php');
      var result = await res.json();
      if (!result.ok) { container.innerHTML = '<div class="fw-finance__error">Failed to generate report</div>'; return; }
      var rows = result.data || [];
      if (rows.length === 0) { container.innerHTML = '<div class="fw-finance__empty-state">No outstanding balances.</div>'; return; }
      var html = '<table class="fw-finance__table"><thead><tr><th>Supplier</th><th style="text-align:right">Current</th><th style="text-align:right">1-30</th><th style="text-align:right">31-60</th><th style="text-align:right">61-90</th><th style="text-align:right">90+</th><th style="text-align:right">Total</th></tr></thead><tbody>';
      var totals = { current: 0, d30: 0, d60: 0, d90: 0, d90p: 0, total: 0 };
      rows.forEach(function(r) {
        var cur = parseFloat(r.current), d30 = parseFloat(r.days_1_30), d60 = parseFloat(r.days_31_60), d90 = parseFloat(r.days_61_90), d90p = parseFloat(r.days_90_plus), tot = parseFloat(r.total);
        totals.current += cur; totals.d30 += d30; totals.d60 += d60; totals.d90 += d90; totals.d90p += d90p; totals.total += tot;
        html += '<tr><td>' + E(r.supplier_name) + '</td><td style="text-align:right">' + cur.toFixed(2) + '</td><td style="text-align:right">' + d30.toFixed(2) + '</td><td style="text-align:right">' + d60.toFixed(2) + '</td><td style="text-align:right">' + d90.toFixed(2) + '</td><td style="text-align:right">' + d90p.toFixed(2) + '</td><td style="text-align:right"><strong>' + tot.toFixed(2) + '</strong></td></tr>';
      });
      html += '<tr style="border-top:2px solid var(--fw-border);font-weight:bold;"><td>TOTAL</td><td style="text-align:right">' + totals.current.toFixed(2) + '</td><td style="text-align:right">' + totals.d30.toFixed(2) + '</td><td style="text-align:right">' + totals.d60.toFixed(2) + '</td><td style="text-align:right">' + totals.d90.toFixed(2) + '</td><td style="text-align:right">' + totals.d90p.toFixed(2) + '</td><td style="text-align:right">' + totals.total.toFixed(2) + '</td></tr>';
      html += '</tbody></table>';
      container.innerHTML = html;
    } catch (err) {
      container.innerHTML = '<div class="fw-finance__error">' + E(err.message) + '</div>';
    }
  }

  // ============================================================
  // Statements Tab
  // ============================================================
  var stmtGenerateBtn = document.getElementById('stmtGenerateBtn');
  if (stmtGenerateBtn) {
    stmtGenerateBtn.addEventListener('click', async function() {
      var supId = document.getElementById('stmtSupplierSelect').value;
      var start = document.getElementById('stmtStartDate').value;
      var end = document.getElementById('stmtEndDate').value;
      var container = document.getElementById('statementsContent');
      if (!supId) { alert('Please select a supplier'); return; }
      container.innerHTML = '<div class="fw-finance__loading">Generating statement...</div>';
      var params = new URLSearchParams();
      params.append('supplier_id', supId);
      if (start) params.append('start_date', start);
      if (end) params.append('end_date', end);
      try {
        var res = await fetch('/finances/ap/api/ap_statement.php?' + params.toString());
        var data = await res.json();
        if (!data.ok) { container.innerHTML = '<div class="fw-finance__error">' + E(data.error || 'Unknown error') + '</div>'; return; }
        var opening = parseFloat(data.opening_balance || 0);
        var lines = data.data || [];
        var html = '<div style="margin-bottom:1rem;"><strong>Opening Balance:</strong> R ' + opening.toFixed(2) + '</div>';
        if (lines.length === 0) {
          html += '<div class="fw-finance__empty-state">No transactions found for this period.</div>';
        } else {
          html += '<table class="fw-finance__table"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Description</th><th style="text-align:right">Debit</th><th style="text-align:right">Credit</th><th style="text-align:right">Balance</th></tr></thead><tbody>';
          lines.forEach(function(row) {
            html += '<tr><td>' + E(row.date) + '</td><td>' + E(row.type) + '</td><td>' + E(row.reference) + '</td><td>' + E(row.description) + '</td><td style="text-align:right;color:#b91c1c;">' + (row.debit ? parseFloat(row.debit).toFixed(2) : '') + '</td><td style="text-align:right;color:#166534;">' + (row.credit ? parseFloat(row.credit).toFixed(2) : '') + '</td><td style="text-align:right">' + parseFloat(row.balance).toFixed(2) + '</td></tr>';
          });
          html += '</tbody></table>';
        }
        container.innerHTML = html;
      } catch (err) {
        container.innerHTML = '<div class="fw-finance__error">Network error: ' + E(err.message) + '</div>';
      }
    });
  }

  // ============================================================
  // 3-Way Match Tab
  // ============================================================
  var twLoadBtn = document.getElementById('twLoadBtn');
  if (twLoadBtn) {
    var twPendingMatches = [];
    var twPoLines = [], twGrnLines = [], twBillLines = [];

    twLoadBtn.addEventListener('click', async function() {
      var supId = document.getElementById('twSupplierSelect').value;
      var container = document.getElementById('threewayContent');
      if (!supId) { alert('Please select a supplier'); return; }
      container.innerHTML = '<div class="fw-finance__loading">Loading PO/GRN/Bill lines...</div>';
      twPendingMatches = [];
      try {
        var res = await fetch('/finances/ap/api/three_way_get.php?supplier_id=' + supId);
        var data = await res.json();
        if (data.error) { container.innerHTML = '<div class="fw-finance__error">' + E(data.error) + '</div>'; return; }
        twPoLines = data.po_lines || [];
        twGrnLines = data.grn_lines || [];
        twBillLines = data.bill_lines || [];
        renderThreeWay(container);
      } catch (err) {
        container.innerHTML = '<div class="fw-finance__error">' + E(err.message) + '</div>';
      }
    });

    function renderThreeWay(container) {
      var html = '';
      // PO Lines
      html += '<h3 style="margin-top:1rem;">Purchase Order Lines</h3>';
      if (twPoLines.length === 0) { html += '<div class="fw-finance__empty-state">No PO lines.</div>'; }
      else {
        html += '<table class="fw-finance__table"><thead><tr><th>PO #</th><th>Description</th><th style="text-align:right">Ordered</th><th style="text-align:right">Matched</th><th style="text-align:right">Available</th></tr></thead><tbody>';
        twPoLines.forEach(function(l) { html += '<tr><td>' + E(l.po_number) + '</td><td>' + E(l.description) + '</td><td style="text-align:right">' + parseFloat(l.qty_ordered).toFixed(3) + '</td><td style="text-align:right">' + parseFloat(l.qty_matched).toFixed(3) + '</td><td style="text-align:right">' + parseFloat(l.qty_available).toFixed(3) + '</td></tr>'; });
        html += '</tbody></table>';
      }
      // GRN Lines
      html += '<h3 style="margin-top:1rem;">Goods Received Note Lines</h3>';
      if (twGrnLines.length === 0) { html += '<div class="fw-finance__empty-state">No GRN lines.</div>'; }
      else {
        html += '<table class="fw-finance__table"><thead><tr><th>GRN #</th><th>Description</th><th style="text-align:right">Received</th><th style="text-align:right">Matched</th><th style="text-align:right">Available</th></tr></thead><tbody>';
        twGrnLines.forEach(function(l) { html += '<tr><td>' + E(l.grn_number) + '</td><td>' + E(l.po_description) + '</td><td style="text-align:right">' + parseFloat(l.qty_received).toFixed(3) + '</td><td style="text-align:right">' + parseFloat(l.qty_matched).toFixed(3) + '</td><td style="text-align:right">' + parseFloat(l.qty_available).toFixed(3) + '</td></tr>'; });
        html += '</tbody></table>';
      }
      // Bill Lines
      html += '<h3 style="margin-top:1rem;">Bill Lines</h3>';
      if (twBillLines.length === 0) { html += '<div class="fw-finance__empty-state">No bill lines.</div>'; }
      else {
        html += '<table class="fw-finance__table"><thead><tr><th>Invoice #</th><th>Description</th><th style="text-align:right">Qty</th><th style="text-align:right">Matched</th><th style="text-align:right">Available</th></tr></thead><tbody>';
        twBillLines.forEach(function(l) { html += '<tr><td>' + E(l.invoice_number) + '</td><td>' + E(l.description) + '</td><td style="text-align:right">' + parseFloat(l.qty).toFixed(3) + '</td><td style="text-align:right">' + parseFloat(l.qty_matched).toFixed(3) + '</td><td style="text-align:right">' + parseFloat(l.qty_available).toFixed(3) + '</td></tr>'; });
        html += '</tbody></table>';
      }
      // Match form
      html += '<h3 style="margin-top:1rem;">Create Match</h3>';
      html += '<div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:flex-end;">';
      html += '<label style="flex:1;min-width:160px;">PO Line<br><select id="twPoSelect" class="fw-finance__input"><option value="">--none--</option>';
      twPoLines.filter(function(l){ return l.qty_available > 0; }).forEach(function(l) { html += '<option value="' + l.id + '">' + E(l.po_number) + ' - ' + E(l.description) + ' (' + parseFloat(l.qty_available).toFixed(3) + ')</option>'; });
      html += '</select></label>';
      html += '<label style="flex:1;min-width:160px;">GRN Line<br><select id="twGrnSelect" class="fw-finance__input"><option value="">--none--</option>';
      twGrnLines.filter(function(l){ return l.qty_available > 0; }).forEach(function(l) { html += '<option value="' + l.id + '">' + E(l.grn_number) + ' - ' + E(l.po_description) + ' (' + parseFloat(l.qty_available).toFixed(3) + ')</option>'; });
      html += '</select></label>';
      html += '<label style="flex:1;min-width:160px;">Bill Line<br><select id="twBillSelect" class="fw-finance__input"><option value="">--none--</option>';
      twBillLines.filter(function(l){ return l.qty_available > 0; }).forEach(function(l) { html += '<option value="' + l.id + '">' + E(l.invoice_number) + ' - ' + E(l.description) + ' (' + parseFloat(l.qty_available).toFixed(3) + ')</option>'; });
      html += '</select></label>';
      html += '<label style="min-width:100px;">Qty<br><input type="number" id="twQtyInput" class="fw-finance__input" step="0.001" min="0"></label>';
      html += '<button type="button" id="twAddMatchBtn" class="fw-finance__btn">Add</button>';
      html += '</div>';
      // Pending matches
      html += '<h3 style="margin-top:1rem;">Pending Matches</h3>';
      html += '<div id="twPendingList"></div>';
      html += '<button type="button" id="twApplyBtn" class="fw-finance__btn fw-finance__btn--primary" style="margin-top:0.5rem;">Apply Matches</button>';
      container.innerHTML = html;

      // Wire up add/apply buttons
      document.getElementById('twAddMatchBtn').addEventListener('click', function() {
        var poId = document.getElementById('twPoSelect').value;
        var grnId = document.getElementById('twGrnSelect').value;
        var billId = document.getElementById('twBillSelect').value;
        var qty = parseFloat(document.getElementById('twQtyInput').value);
        if (!poId && !grnId && !billId) { alert('Select at least one line'); return; }
        if (!qty || qty <= 0) { alert('Enter a valid quantity'); return; }
        twPendingMatches.push({ po_line_id: poId ? parseInt(poId) : null, grn_line_id: grnId ? parseInt(grnId) : null, bill_line_id: billId ? parseInt(billId) : null, qty: qty });
        document.getElementById('twQtyInput').value = '';
        renderPendingMatches();
      });

      document.getElementById('twApplyBtn').addEventListener('click', async function() {
        if (twPendingMatches.length === 0) { alert('No matches to apply'); return; }
        this.disabled = true;
        try {
          var csrfMeta = document.querySelector('meta[name="csrf-token"]');
          var headers = { 'Content-Type': 'application/json' };
          if (csrfMeta) headers['X-CSRF-Token'] = csrfMeta.content;
          var res = await fetch('/finances/ap/api/three_way_apply.php', { method: 'POST', headers: headers, body: JSON.stringify({ matches: twPendingMatches }) });
          var result = await res.json();
          if (result.ok) {
            alert('Matches applied successfully');
            twPendingMatches = [];
            twLoadBtn.click(); // reload
          } else {
            alert('Error: ' + (result.error || 'Failed'));
          }
        } catch (err) { alert('Error: ' + err.message); }
        this.disabled = false;
      });

      renderPendingMatches();
    }

    function renderPendingMatches() {
      var list = document.getElementById('twPendingList');
      if (!list) return;
      if (twPendingMatches.length === 0) { list.innerHTML = '<div class="fw-finance__empty-state">No pending matches.</div>'; return; }
      var html = '<table class="fw-finance__table"><thead><tr><th>PO Line</th><th>GRN Line</th><th>Bill Line</th><th>Qty</th><th></th></tr></thead><tbody>';
      twPendingMatches.forEach(function(m, i) {
        html += '<tr><td>' + (m.po_line_id || '-') + '</td><td>' + (m.grn_line_id || '-') + '</td><td>' + (m.bill_line_id || '-') + '</td><td>' + m.qty.toFixed(3) + '</td><td><button class="tw-remove-btn" data-idx="' + i + '">Remove</button></td></tr>';
      });
      html += '</tbody></table>';
      list.innerHTML = html;
      list.querySelectorAll('.tw-remove-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
          twPendingMatches.splice(parseInt(this.dataset.idx), 1);
          renderPendingMatches();
        });
      });
    }
  }

  // ============================================================
  // Init: load the default active tab
  // ============================================================
  document.addEventListener('DOMContentLoaded', function() {
    loadTab('manual');
  });

})();
