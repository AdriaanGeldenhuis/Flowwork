// /finances/assets/ar.js
// Accounts Receivable Module

(function() {
  'use strict';

  let invoices = [];
  let filteredInvoices = [];
  let currentTab = 'invoices';
  let currentPage = 1;
  const pageSize = 25;

  // DOM Elements
  const tabButtons = document.querySelectorAll('.fw-finance__tab');
  const invoiceList = document.getElementById('invoiceList');
  const agingReport = document.getElementById('agingReport');
  const searchInput = document.getElementById('searchInput');
  const filterStatus = document.getElementById('filterStatus');
  const syncAllBtn = document.getElementById('syncAllBtn');
  const invoiceCount = document.getElementById('invoiceCount');
  const pagination = document.getElementById('pagination');

  // ─── Load Invoices ────────────────────────────────────────────────

  async function loadInvoices() {
    invoiceList.innerHTML = '<div class="fw-finance__loading">Loading invoices...</div>';

    const result = await FinanceAPI.request('/finances/ajax/ar_invoice_list.php');

    if (result.ok) {
      invoices = result.data;
      applyFilters();
    } else {
      invoiceList.innerHTML = '<div class="fw-finance__empty-state">Failed to load invoices</div>';
    }
  }

  // ─── Filter & Search ──────────────────────────────────────────────

  function applyFilters() {
    const searchTerm = (searchInput ? searchInput.value : '').toLowerCase().trim();
    const statusFilter = filterStatus ? filterStatus.value : '';

    filteredInvoices = invoices.filter(function(inv) {
      if (statusFilter && inv.status !== statusFilter) return false;
      if (searchTerm) {
        const haystack = [
          inv.invoice_number,
          inv.customer_name
        ].join(' ').toLowerCase();
        if (haystack.indexOf(searchTerm) === -1) return false;
      }
      return true;
    });

    currentPage = 1;
    renderInvoices();
    renderPagination();
    updateInvoiceCount();
  }

  // ─── Render Invoice Cards ─────────────────────────────────────────

  function renderInvoices() {
    if (filteredInvoices.length === 0) {
      invoiceList.innerHTML = '<div class="fw-finance__empty-state">' +
        '<div style="font-size:32px;margin-bottom:12px">' +
          '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.4">' +
            '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
            '<polyline points="14 2 14 8 20 8"/>' +
            '<line x1="16" y1="13" x2="8" y2="13"/>' +
            '<line x1="16" y1="17" x2="8" y2="17"/>' +
          '</svg>' +
        '</div>' +
        'No invoices found' +
      '</div>';
      return;
    }

    var start = (currentPage - 1) * pageSize;
    var end = start + pageSize;
    var pageInvoices = filteredInvoices.slice(start, end);

    var html = pageInvoices.map(function(inv) {
      // Only issued/live invoices may be synced to the GL. Draft, cancelled and
      // the terminal write-off/refund statuses must not be posted from here
      // (the server rejects them too — see ar_sync_invoice.php).
      var SYNCABLE = ['sent', 'viewed', 'part-paid', 'paid', 'overdue'];
      var syncBtn = '';
      if (inv.journal_id) {
        syncBtn = '<button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--secondary" disabled>' +
          'Synced to GL</button>';
      } else if (SYNCABLE.indexOf(inv.status) !== -1) {
        syncBtn = '<button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--primary sync-invoice" data-id="' + inv.id + '">' +
          'Sync to GL</button>';
      }

      return '<div class="fw-finance__ar-card">' +
        '<div class="fw-finance__ar-card-header">' +
          '<div class="fw-finance__ar-card-header-left">' +
            '<span class="fw-finance__ar-card-number">' + escapeHtml(inv.invoice_number) + '</span>' +
            '<span class="fw-finance__badge fw-finance__badge--' + escapeHtml(inv.status) + '">' + escapeHtml(inv.status) + '</span>' +
          '</div>' +
          '<div class="fw-finance__ar-card-amount">R ' + parseFloat(inv.total).toFixed(2) + '</div>' +
        '</div>' +
        '<div class="fw-finance__ar-card-body">' +
          '<div class="fw-finance__ar-card-customer">' + escapeHtml(inv.customer_name || 'Unknown') + '</div>' +
          '<div class="fw-finance__ar-card-dates">' +
            'Issued: ' + formatDate(inv.issue_date) + ' &middot; Due: ' + formatDate(inv.due_date) +
          '</div>' +
          '<div class="fw-finance__ar-card-balance">Balance: R ' + parseFloat(inv.balance_due).toFixed(2) + '</div>' +
        '</div>' +
        '<div class="fw-finance__ar-card-actions">' +
          syncBtn +
          '<a href="/finances/ar/invoice_view.php?id=' + inv.id + '" class="fw-finance__btn fw-finance__btn--small fw-finance__btn--secondary">' +
            'View Invoice</a>' +
        '</div>' +
      '</div>';
    }).join('');

    invoiceList.innerHTML = html;
    attachInvoiceEvents();
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  // ─── Pagination ───────────────────────────────────────────────────

  function renderPagination() {
    if (!pagination) return;

    var totalPages = Math.ceil(filteredInvoices.length / pageSize);
    if (totalPages <= 1) {
      pagination.innerHTML = '';
      return;
    }

    var html = '';
    html += '<button class="fw-finance__page-btn" ' + (currentPage <= 1 ? 'disabled' : '') + ' data-page="' + (currentPage - 1) + '">&laquo; Prev</button>';
    html += '<span class="fw-finance__page-info">Page ' + currentPage + ' of ' + totalPages + '</span>';
    html += '<button class="fw-finance__page-btn" ' + (currentPage >= totalPages ? 'disabled' : '') + ' data-page="' + (currentPage + 1) + '">Next &raquo;</button>';

    pagination.innerHTML = html;

    pagination.querySelectorAll('.fw-finance__page-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        var page = parseInt(btn.dataset.page, 10);
        var totalP = Math.ceil(filteredInvoices.length / pageSize);
        if (page >= 1 && page <= totalP) {
          currentPage = page;
          renderInvoices();
          renderPagination();
        }
      });
    });
  }

  // ─── Attach Events ────────────────────────────────────────────────

  function attachInvoiceEvents() {
    document.querySelectorAll('.sync-invoice').forEach(function(btn) {
      btn.addEventListener('click', async function() {
        var invoiceId = btn.dataset.id;
        await syncInvoiceToGL(invoiceId);
      });
    });
  }

  // ─── Sync Invoice to GL ───────────────────────────────────────────

  async function syncInvoiceToGL(invoiceId) {
    if (!confirm('Post this invoice to the General Ledger?')) return;

    var result = await FinanceAPI.request('/finances/ajax/ar_sync_invoice.php', 'POST', { invoice_id: invoiceId });

    if (result.ok) {
      showMessage('invoiceList', 'Invoice synced to GL successfully', 'success');
      loadInvoices();
    } else {
      alert('Error: ' + (result.error || 'Failed to sync invoice'));
    }
  }

  // ─── Sync All Invoices ────────────────────────────────────────────

  async function syncAllInvoices() {
    if (!confirm('Sync ALL unsynced invoices to the General Ledger?')) return;

    // Mirror the per-row gate: only issued/live invoices are syncable. The
    // server rejects the rest, but filtering here keeps the count honest and
    // avoids firing doomed requests for drafts/cancelled invoices.
    var SYNCABLE = ['sent', 'viewed', 'part-paid', 'paid', 'overdue'];
    var unsyncedIds = invoices.filter(function(inv) {
      return !inv.journal_id && SYNCABLE.indexOf(inv.status) !== -1;
    }).map(function(inv) { return inv.id; });

    if (unsyncedIds.length === 0) {
      alert('No unsynced invoices found');
      return;
    }

    syncAllBtn.disabled = true;
    syncAllBtn.textContent = 'Syncing...';

    var successCount = 0;
    for (var i = 0; i < unsyncedIds.length; i++) {
      var result = await FinanceAPI.request('/finances/ajax/ar_sync_invoice.php', 'POST', { invoice_id: unsyncedIds[i] });
      if (result.ok) successCount++;
    }

    syncAllBtn.disabled = false;
    syncAllBtn.textContent = 'Sync All to GL';

    alert('Synced ' + successCount + ' of ' + unsyncedIds.length + ' invoices');
    loadInvoices();
  }

  // ─── Update Invoice Count ─────────────────────────────────────────

  function updateInvoiceCount() {
    if (invoiceCount) {
      invoiceCount.textContent = filteredInvoices.length + ' invoice' + (filteredInvoices.length !== 1 ? 's' : '');
    }
  }

  // ─── Load Aging Report ────────────────────────────────────────────

  async function loadAgingReport() {
    agingReport.innerHTML = '<div class="fw-finance__loading">Loading aging report...</div>';

    var result = await FinanceAPI.request('/finances/ajax/ar_aging.php');

    if (result.ok) {
      renderAgingReport(result.data);
    } else {
      agingReport.innerHTML = '<div class="fw-finance__empty-state">Failed to load aging report</div>';
    }
  }

  function renderAgingReport(data) {
    var html = '' +
      '<div class="fw-finance__report-header">' +
        '<h2 class="fw-finance__report-subtitle">Accounts Receivable Aging</h2>' +
        '<p class="fw-finance__report-date">As at ' + formatDate(new Date().toISOString().split('T')[0]) + '</p>' +
      '</div>' +
      '<table class="fw-finance__report-table">' +
        '<thead><tr>' +
          '<th>Customer</th>' +
          '<th class="fw-finance__report-table-number">Current</th>' +
          '<th class="fw-finance__report-table-number">1-30 Days</th>' +
          '<th class="fw-finance__report-table-number">31-60 Days</th>' +
          '<th class="fw-finance__report-table-number">61-90 Days</th>' +
          '<th class="fw-finance__report-table-number">90+ Days</th>' +
          '<th class="fw-finance__report-table-number">Total</th>' +
        '</tr></thead>' +
        '<tbody>';

    var totals = { current: 0, days_1_30: 0, days_31_60: 0, days_61_90: 0, days_90_plus: 0, total: 0 };

    data.forEach(function(row) {
      totals.current += parseFloat(row.current || 0);
      totals.days_1_30 += parseFloat(row.days_1_30 || 0);
      totals.days_31_60 += parseFloat(row.days_31_60 || 0);
      totals.days_61_90 += parseFloat(row.days_61_90 || 0);
      totals.days_90_plus += parseFloat(row.days_90_plus || 0);
      totals.total += parseFloat(row.total || 0);

      html += '<tr>' +
        '<td>' + escapeHtml(row.customer_name) + '</td>' +
        '<td class="fw-finance__report-table-number">R ' + parseFloat(row.current || 0).toFixed(2) + '</td>' +
        '<td class="fw-finance__report-table-number">R ' + parseFloat(row.days_1_30 || 0).toFixed(2) + '</td>' +
        '<td class="fw-finance__report-table-number">R ' + parseFloat(row.days_31_60 || 0).toFixed(2) + '</td>' +
        '<td class="fw-finance__report-table-number">R ' + parseFloat(row.days_61_90 || 0).toFixed(2) + '</td>' +
        '<td class="fw-finance__report-table-number">R ' + parseFloat(row.days_90_plus || 0).toFixed(2) + '</td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + parseFloat(row.total || 0).toFixed(2) + '</strong></td>' +
      '</tr>';
    });

    html += '</tbody>' +
      '<tfoot><tr class="fw-finance__report-table-total">' +
        '<td><strong>Total</strong></td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + totals.current.toFixed(2) + '</strong></td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + totals.days_1_30.toFixed(2) + '</strong></td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + totals.days_31_60.toFixed(2) + '</strong></td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + totals.days_61_90.toFixed(2) + '</strong></td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + totals.days_90_plus.toFixed(2) + '</strong></td>' +
        '<td class="fw-finance__report-table-number"><strong>R ' + totals.total.toFixed(2) + '</strong></td>' +
      '</tr></tfoot></table>';

    agingReport.innerHTML = html;
  }

  // ─── Tab Switching ────────────────────────────────────────────────

  tabButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var tab = btn.dataset.tab;
      currentTab = tab;

      tabButtons.forEach(function(b) { b.classList.remove('fw-finance__tab--active'); });
      btn.classList.add('fw-finance__tab--active');

      document.querySelectorAll('.fw-finance__tab-panel').forEach(function(panel) {
        panel.classList.remove('fw-finance__tab-panel--active');
      });

      if (tab === 'invoices') {
        document.getElementById('invoicesPanel').classList.add('fw-finance__tab-panel--active');
      } else if (tab === 'aging') {
        document.getElementById('agingPanel').classList.add('fw-finance__tab-panel--active');
        loadAgingReport();
      } else if (tab === 'statements') {
        document.getElementById('statementsPanel').classList.add('fw-finance__tab-panel--active');
      } else if (tab === 'reminders') {
        document.getElementById('remindersPanel').classList.add('fw-finance__tab-panel--active');
        loadRemindersList();
      }
    });
  });

  // Reminders tab: list overdue invoices ready for follow-up.
  async function loadRemindersList() {
    const box = document.getElementById('remindersList');
    if (!box) return;
    box.innerHTML = '<div class="fw-finance__empty-state">Loading overdue invoices…</div>';
    try {
      const result = await FinanceAPI.request('/finances/ajax/ar_invoices_list.php?status=overdue&limit=100', 'GET');
      const rows = (result.data && (result.data.invoices || result.data.rows || result.data)) || [];
      if (!Array.isArray(rows) || rows.length === 0) {
        box.innerHTML = '<div class="fw-finance__empty-state">No overdue invoices — nothing to chase. 🎉</div>';
        return;
      }
      let html = '<table class="fw-finance__table"><thead><tr>' +
        '<th>Invoice</th><th>Customer</th><th>Due Date</th><th style="text-align:right;">Balance Due</th><th></th>' +
        '</tr></thead><tbody>';
      rows.forEach(function (inv) {
        html += '<tr>' +
          '<td>' + escapeHtml(inv.invoice_number || '') + '</td>' +
          '<td>' + escapeHtml(inv.customer_name || '') + '</td>' +
          '<td>' + escapeHtml(inv.due_date || '') + '</td>' +
          '<td style="text-align:right;">' + formatCurrency(Math.round(parseFloat(inv.balance_due || 0) * 100)) + '</td>' +
          '<td><a class="fw-finance__btn fw-finance__btn--secondary" href="/qi/invoice_view.php?id=' + encodeURIComponent(inv.id) + '">Open</a></td>' +
          '</tr>';
      });
      html += '</tbody></table>';
      box.innerHTML = html;
    } catch (err) {
      box.innerHTML = '<div class="fw-finance__empty-state">Could not load overdue invoices.</div>';
    }
  }

  // ─── Event Listeners ──────────────────────────────────────────────

  if (syncAllBtn) {
    syncAllBtn.addEventListener('click', syncAllInvoices);
  }

  if (searchInput) {
    searchInput.addEventListener('input', debounce(function() {
      applyFilters();
    }, 300));
  }

  if (filterStatus) {
    filterStatus.addEventListener('change', function() {
      applyFilters();
    });
  }

  // ─── Initialize ───────────────────────────────────────────────────

  document.addEventListener('DOMContentLoaded', function() {
    loadInvoices();
  });

})();
