// /finances/js/finance.overview.js
// Handles fetching and rendering the finance overview widgets.

(function() {
  'use strict';

  /**
   * Escape HTML entities to prevent injection when rendering dynamic data.
   * @param {string} str
   * @returns {string}
   */
  function escapeHtml(str) {
    if (!str) return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // References to filter inputs
  const periodSelect = document.getElementById('ov-period');
  const fromInput = document.getElementById('ov-from');
  const toInput = document.getElementById('ov-to');

  /**
   * Collect current filter parameters.
   * @returns {Object}
   */
  function getParams() {
    return {
      period: periodSelect ? periodSelect.value : 'mtd',
      from: fromInput && fromInput.value ? fromInput.value : '',
      to: toInput && toInput.value ? toInput.value : ''
    };
  }

  /**
   * Wrapper for API calls to the overview endpoint.
   * @param {string} widget
   */
  async function api(widget) {
    const params = getParams();
    const qs = new URLSearchParams({ ...params, w: widget });
    const res = await FinanceAPI.request('/finances/api/overview.php?' + qs.toString());
    if (!res || !res.ok) {
      throw new Error(res && res.error ? res.error : 'Unknown API error');
    }
    return res.data;
  }

  /**
   * Fetch all widgets and render the overview.
   */
  async function loadOverview() {
    try {
      const [kpis, revexp, aging, banks, vat, due, overdue, recurring, receipts] = await Promise.all([
        api('kpis'),
        api('revexp'),
        api('ar_aging'),
        api('banks'),
        api('vat'),
        api('invoices_due'),
        api('invoices_overdue'),
        api('recurring_upcoming'),
        api('receipts_unposted')
      ]);
      renderKPIs(kpis);
      drawRevExp(revexp);
      drawARAging(aging);
      renderBanks(banks);
      renderVAT(vat);
      renderTable('tbl-due', due);
      renderTable('tbl-overdue', overdue);
      renderTable('tbl-recurring', recurring);
      renderTable('tbl-receipts', receipts);
    } catch (e) {
      console.error('Finance overview load error', e);
    }
  }

  /**
   * Render KPI values and overdue badge.
   * @param {Object} data
   */
  function renderKPIs(data) {
    if (!data) return;
    const cashVal = document.getElementById('kpi-cash-value');
    const arVal = document.getElementById('kpi-ar-value');
    const arOverdue = document.getElementById('kpi-ar-overdue');
    const salesVal = document.getElementById('kpi-sales-value');
    const apVal = document.getElementById('kpi-ap-value');

    if (cashVal) cashVal.textContent = formatCurrency(data.cash_cents || 0);
    if (arVal) arVal.textContent = formatCurrency(data.ar_open_cents || 0);
    if (arOverdue) {
      const count = data.ar_overdue_count || 0;
      arOverdue.textContent = count > 0 ? `${count} overdue` : '';
      if (count > 0) {
        arOverdue.style.color = 'var(--accent-warning)';
      } else {
        arOverdue.style.color = '';
      }
    }
    if (salesVal) salesVal.textContent = formatCurrency(data.sales_cents || 0);
    if (apVal) apVal.textContent = formatCurrency(data.ap_unposted_cents || 0);
  }

  /**
   * Draw a simple bar chart for revenue vs expenses by month.
   * @param {Object} data
   */
  function drawRevExp(data) {
    const container = document.getElementById('chart-revexp');
    if (!container) return;
    container.innerHTML = '';
    if (!data || !Array.isArray(data.revenue)) {
      container.innerHTML = '<div class="fw-finance__empty-state">No data</div>';
      return;
    }
    const revenueMap = {};
    const expenseMap = {};
    let months = [];
    data.revenue.forEach(item => {
      revenueMap[item.month] = item.cents;
      months.push(item.month);
    });
    if (Array.isArray(data.expenses)) {
      data.expenses.forEach(item => {
        expenseMap[item.month] = item.cents;
        if (!months.includes(item.month)) {
          months.push(item.month);
        }
      });
    }
    months.sort();
    let maxVal = 0;
    months.forEach(m => {
      const rev = revenueMap[m] || 0;
      const exp = expenseMap[m] || 0;
      maxVal = Math.max(maxVal, rev, exp);
    });
    if (maxVal === 0) {
      container.innerHTML = '<div class="fw-finance__empty-state">No data</div>';
      return;
    }
    let html = '<div class="fw-finance-overview__chart-bar-group">';
    months.forEach(m => {
      const rev = revenueMap[m] || 0;
      const exp = expenseMap[m] || 0;
      const revHeight = ((rev / maxVal) * 100).toFixed(2);
      const expHeight = ((exp / maxVal) * 100).toFixed(2);
      const label = m.slice(5, 7) + '/' + m.slice(0, 4);
      html += `<div class="fw-finance-overview__bar-group">
        <div class="fw-finance-overview__bar fw-finance-overview__bar--rev" style="height:${revHeight}%"></div>
        <div class="fw-finance-overview__bar fw-finance-overview__bar--exp" style="height:${expHeight}%"></div>
        <div class="fw-finance-overview__bar-label">${label}</div>
      </div>`;
    });
    html += '</div>';
    container.innerHTML = html;
  }

  /**
   * Draw a simple bar chart for AR aging buckets.
   * @param {Object} data
   */
  function drawARAging(data) {
    const container = document.getElementById('chart-araging');
    if (!container) return;
    container.innerHTML = '';
    if (!data) {
      container.innerHTML = '<div class="fw-finance__empty-state">No data</div>';
      return;
    }
    const buckets = {
      '0+': data.bucket_current_cents || 0,
      '1-30': data.bucket_30_cents || 0,
      '31-60': data.bucket_60_cents || 0,
      '61-90': data.bucket_90_cents || 0,
      '>90': data.bucket_120_cents || 0
    };
    const values = Object.values(buckets);
    const maxVal = Math.max(...values);
    if (maxVal === 0) {
      container.innerHTML = '<div class="fw-finance__empty-state">No data</div>';
      return;
    }
    let html = '<div class="fw-finance-overview__chart-bar-group">';
    Object.keys(buckets).forEach(label => {
      const val = buckets[label];
      const height = ((val / maxVal) * 100).toFixed(2);
      html += `<div class="fw-finance-overview__bar-group">
        <div class="fw-finance-overview__bar fw-finance-overview__bar--aging" style="height:${height}%"></div>
        <div class="fw-finance-overview__bar-label">${label}</div>
      </div>`;
    });
    html += '</div>';
    container.innerHTML = html;
  }

  /**
   * Render the list of bank accounts.
   * @param {Array} data
   */
  function renderBanks(data) {
    const container = document.getElementById('card-banks');
    if (!container) return;
    let html = '<h3>Bank Accounts</h3>';
    if (data && data.length > 0) {
      html += '<ul class="fw-finance-overview__bank-list">';
      data.forEach(acc => {
        const bal = formatCurrency(acc.balance_cents || 0);
        const rec = acc.last_reconciled_date ? formatDate(acc.last_reconciled_date) : '—';
        html += `<li><span>${escapeHtml(acc.name)} (${escapeHtml(acc.bank_name)})</span><span>${bal}</span><span>${rec}</span></li>`;
      });
      html += '</ul>';
    } else {
      html += '<div class="fw-finance__empty-state">No bank accounts</div>';
    }
    container.innerHTML = html;
  }

  /**
   * Render VAT output, input and net values.
   * @param {Object} data
   */
  function renderVAT(data) {
    const container = document.getElementById('card-vat');
    if (!container) return;
    let html = '<h3>VAT</h3>';
    if (data) {
      const output = formatCurrency(data.output_vat_cents || 0);
      const input = formatCurrency(data.input_vat_cents || 0);
      const net = formatCurrency((data.output_vat_cents || 0) - (data.input_vat_cents || 0));
      html += '<div class="fw-finance-overview__vat-detail">';
      html += `<div><span>Output VAT</span><span>${output}</span></div>`;
      html += `<div><span>Input VAT</span><span>${input}</span></div>`;
      html += `<div><span>Net VAT</span><span>${net}</span></div>`;
      html += '</div>';
    } else {
      html += '<div class="fw-finance__empty-state">No VAT data</div>';
    }
    container.innerHTML = html;
  }

  /**
   * Render tabular worklist data.
   * @param {string} tableId
   * @param {Array} rows
   */
  function renderTable(tableId, rows) {
    const table = document.getElementById(tableId);
    if (!table) return;
    if (!rows || rows.length === 0) {
      table.innerHTML = '<tbody><tr><td colspan="4" class="fw-finance__empty-state">None</td></tr></tbody>';
      return;
    }
    let header = '';
    let body = '';
    if (tableId === 'tbl-due' || tableId === 'tbl-overdue') {
      header = '<tr><th>Invoice</th><th>Customer</th><th>Due</th><th>Balance</th></tr>';
      rows.forEach(row => {
        const url = '/finances/ar/invoice_view.php?id=' + row.id;
        body += `<tr><td><a href="${url}">${escapeHtml(row.invoice_number)}</a></td><td>${escapeHtml(row.customer)}</td><td>${formatDate(row.due_date)}</td><td>${formatCurrency(row.balance_due_cents || 0)}</td></tr>`;
      });
    } else if (tableId === 'tbl-recurring') {
      header = '<tr><th>Customer</th><th>Next Run</th></tr>';
      rows.forEach(row => {
        body += `<tr><td>${escapeHtml(row.customer)}</td><td>${formatDate(row.next_run_date)}</td></tr>`;
      });
    } else if (tableId === 'tbl-receipts') {
      header = '<tr><th>Vendor</th><th>Invoice</th><th>Date</th><th>Total</th></tr>';
      rows.forEach(row => {
        body += `<tr><td>${escapeHtml(row.vendor_name)}</td><td>${escapeHtml(row.invoice_number)}</td><td>${formatDate(row.invoice_date)}</td><td>${formatCurrency(row.total_cents || 0)}</td></tr>`;
      });
    }
    table.innerHTML = '<thead>' + header + '</thead><tbody>' + body + '</tbody>';
  }

  // Bind change events to filters
  if (periodSelect) {
    periodSelect.addEventListener('change', loadOverview);
  }
  if (fromInput) {
    fromInput.addEventListener('change', loadOverview);
  }
  if (toInput) {
    toInput.addEventListener('change', loadOverview);
  }

  // Initial load on DOM ready
  document.addEventListener('DOMContentLoaded', () => {
    loadOverview();
  });
})();