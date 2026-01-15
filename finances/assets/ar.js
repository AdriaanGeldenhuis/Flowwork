// /finances/assets/ar.js
// Accounts Receivable Module

(function() {
  'use strict';

  let invoices = [];
  let currentTab = 'invoices';

  // DOM Elements
  const tabButtons = document.querySelectorAll('.fw-finance__tab');
  const invoiceList = document.getElementById('invoiceList');
  const agingReport = document.getElementById('agingReport');
  const searchInput = document.getElementById('searchInput');
  const filterStatus = document.getElementById('filterStatus');
  const syncAllBtn = document.getElementById('syncAllBtn');
  const invoiceCount = document.getElementById('invoiceCount');

  // Load Invoices
  async function loadInvoices() {
    invoiceList.innerHTML = '<div class="fw-finance__loading">Loading invoices...</div>';

    const result = await FinanceAPI.request('/finances/ajax/ar_invoice_list.php');
    
    if (result.ok) {
      invoices = result.data;
      renderInvoices();
      updateInvoiceCount();
    } else {
      invoiceList.innerHTML = '<div class="fw-finance__empty-state">Failed to load invoices</div>';
    }
  }

  // Render Invoices
  function renderInvoices() {
    if (invoices.length === 0) {
      invoiceList.innerHTML = '<div class="fw-finance__empty-state">No invoices found</div>';
      return;
    }

    const html = invoices.map(inv => `
      <div class="fw-finance__ar-card">
        <div class="fw-finance__ar-card-header">
          <div>
            <strong>${inv.invoice_number}</strong>
            <span class="fw-finance__badge fw-finance__badge--${inv.status}">${inv.status}</span>
          </div>
          <div class="fw-finance__ar-card-amount">R ${parseFloat(inv.total).toFixed(2)}</div>
        </div>
        <div class="fw-finance__ar-card-body">
          <div>Customer: ${inv.customer_name || 'Unknown'}</div>
          <div>Date: ${formatDate(inv.issue_date)} | Due: ${formatDate(inv.due_date)}</div>
          <div>Balance: R ${parseFloat(inv.balance_due).toFixed(2)}</div>
        </div>
        <div class="fw-finance__ar-card-actions">
          ${inv.journal_id ? `
            <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--secondary" disabled>
              ✓ Synced to GL
            </button>
          ` : `
            <button class="fw-finance__btn fw-finance__btn--small fw-finance__btn--primary sync-invoice" data-id="${inv.id}">
              Sync to GL
            </button>
          `}
          <a href="/quotes-invoices/invoice_view.php?id=${inv.id}" class="fw-finance__btn fw-finance__btn--small fw-finance__btn--secondary" target="_blank">
            View Invoice
          </a>
        </div>
      </div>
    `).join('');

    invoiceList.innerHTML = html;
    attachInvoiceEvents();
  }

  // Attach Events
  function attachInvoiceEvents() {
    document.querySelectorAll('.sync-invoice').forEach(btn => {
      btn.addEventListener('click', async () => {
        const invoiceId = btn.dataset.id;
        await syncInvoiceToGL(invoiceId);
      });
    });
  }

  // Sync Invoice to GL
  async function syncInvoiceToGL(invoiceId) {
    if (!confirm('Post this invoice to the General Ledger?')) return;

    const result = await FinanceAPI.request('/finances/ajax/ar_sync_invoice.php', 'POST', { invoice_id: invoiceId });

    if (result.ok) {
      alert('Invoice synced to GL successfully');
      loadInvoices();
    } else {
      alert('Error: ' + (result.error || 'Failed to sync invoice'));
    }
  }

  // Sync All Invoices
  async function syncAllInvoices() {
    if (!confirm('Sync ALL unsynced invoices to the General Ledger?')) return;

    const unsyncedIds = invoices.filter(inv => !inv.journal_id).map(inv => inv.id);

    if (unsyncedIds.length === 0) {
      alert('No unsynced invoices found');
      return;
    }

    syncAllBtn.disabled = true;
    syncAllBtn.innerHTML = '⏳ Syncing...';

    let successCount = 0;
    for (const id of unsyncedIds) {
      const result = await FinanceAPI.request('/finances/ajax/ar_sync_invoice.php', 'POST', { invoice_id: id });
      if (result.ok) successCount++;
    }

    syncAllBtn.disabled = false;
    syncAllBtn.innerHTML = '🔄 Sync All to GL';

    alert(`Synced ${successCount} of ${unsyncedIds.length} invoices`);
    loadInvoices();
  }

  // Load Aging Report
  async function loadAgingReport() {
    agingReport.innerHTML = '<div class="fw-finance__loading">Loading aging report...</div>';

    const result = await FinanceAPI.request('/finances/ajax/ar_aging.php');

    if (result.ok) {
      renderAgingReport(result.data);
    } else {
      agingReport.innerHTML = '<div class="fw-finance__empty-state">Failed to load aging report</div>';
    }
  }

  // Render Aging Report
  function renderAgingReport(data) {
    let html = `
      <div class="fw-finance__report-header">
        <h2 class="fw-finance__report-subtitle">Accounts Receivable Aging</h2>
        <p class="fw-finance__report-date">As at ${formatDate(new Date().toISOString().split('T')[0])}</p>
      </div>

      <table class="fw-finance__report-table">
        <thead>
          <tr>
            <th>Customer</th>
            <th class="fw-finance__report-table-number">Current</th>
            <th class="fw-finance__report-table-number">1-30 Days</th>
            <th class="fw-finance__report-table-number">31-60 Days</th>
            <th class="fw-finance__report-table-number">61-90 Days</th>
            <th class="fw-finance__report-table-number">90+ Days</th>
            <th class="fw-finance__report-table-number">Total</th>
          </tr>
        </thead>
        <tbody>
    `;

    let totals = {
      current: 0,
      days_1_30: 0,
      days_31_60: 0,
      days_61_90: 0,
      days_90_plus: 0,
      total: 0
    };

    data.forEach(row => {
      totals.current += parseFloat(row.current || 0);
      totals.days_1_30 += parseFloat(row.days_1_30 || 0);
      totals.days_31_60 += parseFloat(row.days_31_60 || 0);
      totals.days_61_90 += parseFloat(row.days_61_90 || 0);
      totals.days_90_plus += parseFloat(row.days_90_plus || 0);
      totals.total += parseFloat(row.total || 0);

      html += `
        <tr>
          <td>${row.customer_name}</td>
          <td class="fw-finance__report-table-number">R ${parseFloat(row.current || 0).toFixed(2)}</td>
          <td class="fw-finance__report-table-number">R ${parseFloat(row.days_1_30 || 0).toFixed(2)}</td>
          <td class="fw-finance__report-table-number">R ${parseFloat(row.days_31_60 || 0).toFixed(2)}</td>
          <td class="fw-finance__report-table-number">R ${parseFloat(row.days_61_90 || 0).toFixed(2)}</td>
          <td class="fw-finance__report-table-number">R ${parseFloat(row.days_90_plus || 0).toFixed(2)}</td>
          <td class="fw-finance__report-table-number"><strong>R ${parseFloat(row.total || 0).toFixed(2)}</strong></td>
        </tr>
      `;
    });

    html += `
        </tbody>
        <tfoot>
          <tr class="fw-finance__report-table-total">
            <td><strong>Total</strong></td>
            <td class="fw-finance__report-table-number"><strong>R ${totals.current.toFixed(2)}</strong></td>
            <td class="fw-finance__report-table-number"><strong>R ${totals.days_1_30.toFixed(2)}</strong></td>
            <td class="fw-finance__report-table-number"><strong>R ${totals.days_31_60.toFixed(2)}</strong></td>
            <td class="fw-finance__report-table-number"><strong>R ${totals.days_61_90.toFixed(2)}</strong></td>
            <td class="fw-finance__report-table-number"><strong>R ${totals.days_90_plus.toFixed(2)}</strong></td>
            <td class="fw-finance__report-table-number"><strong>R ${totals.total.toFixed(2)}</strong></td>
          </tr>
        </tfoot>
      </table>
    `;

    agingReport.innerHTML = html;
  }

  // Update Invoice Count
  function updateInvoiceCount() {
    invoiceCount.textContent = `${invoices.length} invoice${invoices.length !== 1 ? 's' : ''}`;
  }

  // Tab Switching
  tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.dataset.tab;
      currentTab = tab;

      tabButtons.forEach(b => b.classList.remove('fw-finance__tab--active'));
      btn.classList.add('fw-finance__tab--active');

      document.querySelectorAll('.fw-finance__tab-panel').forEach(panel => {
        panel.classList.remove('fw-finance__tab-panel--active');
      });

      if (tab === 'invoices') {
        document.getElementById('invoicesPanel').classList.add('fw-finance__tab-panel--active');
      } else if (tab === 'aging') {
        document.getElementById('agingPanel').classList.add('fw-finance__tab-panel--active');
        loadAgingReport();
      }
    });
  });

  // Event Listeners
  if (syncAllBtn) {
    syncAllBtn.addEventListener('click', syncAllInvoices);
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadInvoices();
  });

})();