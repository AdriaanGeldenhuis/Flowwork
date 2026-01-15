// /finances/assets/reports.js
// Financial Reports Module

(function() {
  'use strict';

  let currentReport = 'trial-balance';
  let reportData = null;
  let accounts = [];
  let projects = [];

  // DOM Elements
  const reportTypeBtns = document.querySelectorAll('.fw-finance__report-type-btn');
  const reportDate = document.getElementById('reportDate');
  const accountFilter = document.getElementById('accountFilter');
  const projectFilter = document.getElementById('projectFilter');
  const accountFilterGroup = document.getElementById('accountFilterGroup');
  const projectFilterGroup = document.getElementById('projectFilterGroup');
  const runReportBtn = document.getElementById('runReportBtn');
  const exportBtn = document.getElementById('exportBtn');
  const reportContent = document.getElementById('reportContent');
  const reportInfo = document.getElementById('reportInfo');

  // Exports elements
  const exportsContent = document.getElementById('exportsContent');
  const exportsDate    = document.getElementById('exportsDate');
  // Export links for AR/AP aging
  const arCsvLink      = document.getElementById('arCsv');
  const arPdfLink      = document.getElementById('arPdf');
  const apCsvLink      = document.getElementById('apCsv');
  const apPdfLink      = document.getElementById('apPdf');

  // Update export links with selected date for aging reports
  function updateExportLinks() {
    if (!exportsDate) return;
    const d = exportsDate.value;
    // Build URLs with or without date parameter
    if (arCsvLink) {
      arCsvLink.href = d ? `/export/csv/ar_aging.php?date=${encodeURIComponent(d)}` : '/export/csv/ar_aging.php';
    }
    if (arPdfLink) {
      arPdfLink.href = d ? `/export/pdf/ar_aging.php?date=${encodeURIComponent(d)}` : '/export/pdf/ar_aging.php';
    }
    if (apCsvLink) {
      apCsvLink.href = d ? `/export/csv/ap_aging.php?date=${encodeURIComponent(d)}` : '/export/csv/ap_aging.php';
    }
    if (apPdfLink) {
      apPdfLink.href = d ? `/export/pdf/ap_aging.php?date=${encodeURIComponent(d)}` : '/export/pdf/ap_aging.php';
    }
  }

  // Bind export date change handler
  if (exportsDate) {
    exportsDate.addEventListener('change', updateExportLinks);
    // Initialize export links on load
    updateExportLinks();
  }

  // Load Accounts and Projects
  async function loadFilters() {
    // Load accounts
    const accResult = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (accResult.ok) {
      accounts = accResult.data;
      populateAccountFilter();
    }

    // Load projects
    try {
      const projResult = await FinanceAPI.request('/projects/api.php?action=list');
      if (projResult.ok) {
        projects = projResult.data || [];
        populateProjectFilter();
      }
    } catch (e) {
      // Projects module may not exist
    }
  }

  // Populate Account Filter
  function populateAccountFilter() {
    let html = '<option value="">All Accounts</option>';
    accounts.forEach(acc => {
      html += `<option value="${acc.account_id}">${acc.account_code} - ${acc.account_name}</option>`;
    });
    accountFilter.innerHTML = html;
  }

  // Populate Project Filter
  function populateProjectFilter() {
    let html = '<option value="">All Projects</option>';
    projects.forEach(proj => {
      html += `<option value="${proj.project_id}">${proj.name}</option>`;
    });
    projectFilter.innerHTML = html;
  }

  // Report Type Selection
  reportTypeBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      reportTypeBtns.forEach(b => b.classList.remove('fw-finance__report-type-btn--active'));
      btn.classList.add('fw-finance__report-type-btn--active');
      currentReport = btn.dataset.report;
      
      // Show/hide filters based on report type
      if (currentReport === 'gl-detail') {
        accountFilterGroup.style.display = 'block';
        projectFilterGroup.style.display = 'block';
      } else {
        accountFilterGroup.style.display = 'none';
        projectFilterGroup.style.display = 'none';
      }

      // Special handling for Exports tab
      if (currentReport === 'exports') {
        // Hide standard report UI and show exports content
        if (runReportBtn) runReportBtn.style.display = 'none';
        if (exportBtn) exportBtn.style.display = 'none';
        reportContent.style.display = 'none';
        if (exportsContent) exportsContent.style.display = 'block';
        // Initialize export links with current date
        updateExportLinks();
        // Clear any report info text
        reportInfo.textContent = '';
        return;
      } else {
        // Restore standard report UI
        if (runReportBtn) runReportBtn.style.display = '';
        if (exportBtn) exportBtn.style.display = '';
        reportContent.style.display = '';
        if (exportsContent) exportsContent.style.display = 'none';
      }

      // Clear previous report
      reportContent.innerHTML = '<div class="fw-finance__empty-state">Click "Run Report" to generate</div>';
      exportBtn.disabled = true;
      reportInfo.textContent = '';
    });
  });

  // Run Report
  async function runReport() {
    const date = reportDate.value;
    const accountId = accountFilter.value;
    const projectId = projectFilter.value;

    if (!date) {
      alert('Please select a date');
      return;
    }

    reportContent.innerHTML = '<div class="fw-finance__loading"><div class="fw-finance__spinner"></div> Generating report...</div>';
    exportBtn.disabled = true;

    let endpoint = '';
    let params = new URLSearchParams({ date });

    switch (currentReport) {
      case 'trial-balance':
        endpoint = '/finances/ajax/report_trial_balance.php';
        break;
      case 'pl':
        endpoint = '/finances/ajax/report_pl.php';
        break;
      case 'balance-sheet':
        endpoint = '/finances/ajax/report_balance_sheet.php';
        break;
      case 'gl-detail':
        endpoint = '/finances/ajax/report_gl_detail.php';
        if (accountId) params.append('account_id', accountId);
        if (projectId) params.append('project_id', projectId);
        break;
    }

    const result = await FinanceAPI.request(`${endpoint}?${params}`);

    if (result.ok) {
      reportData = result.data;
      renderReport();
      exportBtn.disabled = false;
      reportInfo.textContent = `Generated: ${new Date().toLocaleString()}`;
    } else {
      reportContent.innerHTML = `<div class="fw-finance__empty-state">Error: ${result.error || 'Failed to generate report'}</div>`;
    }
  }

  // Render Report
  function renderReport() {
    switch (currentReport) {
      case 'trial-balance':
        renderTrialBalance();
        break;
      case 'pl':
        renderProfitLoss();
        break;
      case 'balance-sheet':
        renderBalanceSheet();
        break;
      case 'gl-detail':
        renderGLDetail();
        break;
    }
  }

  // Render Trial Balance
  function renderTrialBalance() {
    const { company_name, date, accounts } = reportData;

    let html = `
      <div class="fw-finance__report-header">
        <h1 class="fw-finance__report-title">${company_name}</h1>
        <h2 class="fw-finance__report-subtitle">Trial Balance</h2>
        <p class="fw-finance__report-date">As at ${formatDate(date)}</p>
      </div>

      <table class="fw-finance__report-table">
        <thead>
          <tr>
            <th>Account Code</th>
            <th>Account Name</th>
            <th class="fw-finance__report-table-number">Debit</th>
            <th class="fw-finance__report-table-number">Credit</th>
          </tr>
        </thead>
        <tbody>
    `;

    let totalDebit = 0;
    let totalCredit = 0;

    accounts.forEach(acc => {
      const debitCents = parseInt(acc.debit_cents) || 0;
      const creditCents = parseInt(acc.credit_cents) || 0;
      
      totalDebit += debitCents;
      totalCredit += creditCents;

      html += `
        <tr>
          <td>${acc.account_code}</td>
          <td>${acc.account_name}</td>
          <td class="fw-finance__report-table-number">${debitCents > 0 ? formatCurrency(debitCents) : '-'}</td>
          <td class="fw-finance__report-table-number">${creditCents > 0 ? formatCurrency(creditCents) : '-'}</td>
        </tr>
      `;
    });

    html += `
        </tbody>
        <tfoot>
          <tr class="fw-finance__report-table-total">
            <td colspan="2"><strong>Total</strong></td>
            <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalDebit)}</strong></td>
            <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalCredit)}</strong></td>
          </tr>
        </tfoot>
      </table>

      ${totalDebit !== totalCredit ? `
        <div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">
          ⚠️ Trial Balance is OUT OF BALANCE by ${formatCurrency(Math.abs(totalDebit - totalCredit))}
        </div>
      ` : `
        <div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">
          ✓ Trial Balance is IN BALANCE
        </div>
      `}
    `;

    reportContent.innerHTML = html;
  }

  // Render Profit & Loss
  function renderProfitLoss() {
    const { company_name, date, revenue, expenses, net_income_cents } = reportData;

    let html = `
      <div class="fw-finance__report-header">
        <h1 class="fw-finance__report-title">${company_name}</h1>
        <h2 class="fw-finance__report-subtitle">Profit & Loss Statement</h2>
        <p class="fw-finance__report-date">For period ending ${formatDate(date)}</p>
      </div>

      <table class="fw-finance__report-table">
        <tbody>
          <tr class="fw-finance__report-section-header">
            <td colspan="2"><strong>REVENUE</strong></td>
          </tr>
    `;

    let totalRevenue = 0;
    revenue.forEach(acc => {
      const balance = parseInt(acc.balance_cents) || 0;
      totalRevenue += balance;
      html += `
        <tr>
          <td style="padding-left: 2rem;">${acc.account_name}</td>
          <td class="fw-finance__report-table-number">${formatCurrency(balance)}</td>
        </tr>
      `;
    });

    html += `
      <tr class="fw-finance__report-subtotal">
        <td><strong>Total Revenue</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalRevenue)}</strong></td>
      </tr>
      <tr class="fw-finance__report-section-header">
        <td colspan="2"><strong>EXPENSES</strong></td>
      </tr>
    `;

    let totalExpenses = 0;
    expenses.forEach(acc => {
      const balance = parseInt(acc.balance_cents) || 0;
      totalExpenses += balance;
      html += `
        <tr>
          <td style="padding-left: 2rem;">${acc.account_name}</td>
          <td class="fw-finance__report-table-number">${formatCurrency(balance)}</td>
        </tr>
      `;
    });

    html += `
      <tr class="fw-finance__report-subtotal">
        <td><strong>Total Expenses</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalExpenses)}</strong></td>
      </tr>
      <tr class="fw-finance__report-table-total">
        <td><strong>NET INCOME</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(net_income_cents)}</strong></td>
      </tr>
        </tbody>
      </table>
    `;

    reportContent.innerHTML = html;
  }

  // Render Balance Sheet
  function renderBalanceSheet() {
    const { company_name, date, assets, liabilities, equity, total_assets_cents, total_liabilities_equity_cents } = reportData;

    let html = `
      <div class="fw-finance__report-header">
        <h1 class="fw-finance__report-title">${company_name}</h1>
        <h2 class="fw-finance__report-subtitle">Balance Sheet</h2>
        <p class="fw-finance__report-date">As at ${formatDate(date)}</p>
      </div>

      <table class="fw-finance__report-table">
        <tbody>
          <tr class="fw-finance__report-section-header">
            <td colspan="2"><strong>ASSETS</strong></td>
          </tr>
    `;

    let totalAssets = 0;
    assets.forEach(acc => {
      const balance = parseInt(acc.balance_cents) || 0;
      totalAssets += balance;
      html += `
        <tr>
          <td style="padding-left: 2rem;">${acc.account_name}</td>
          <td class="fw-finance__report-table-number">${formatCurrency(balance)}</td>
        </tr>
      `;
    });

    html += `
      <tr class="fw-finance__report-subtotal">
        <td><strong>Total Assets</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalAssets)}</strong></td>
      </tr>
      <tr class="fw-finance__report-section-header">
        <td colspan="2"><strong>LIABILITIES</strong></td>
      </tr>
    `;

    let totalLiabilities = 0;
    liabilities.forEach(acc => {
      const balance = parseInt(acc.balance_cents) || 0;
      totalLiabilities += balance;
      html += `
        <tr>
          <td style="padding-left: 2rem;">${acc.account_name}</td>
          <td class="fw-finance__report-table-number">${formatCurrency(balance)}</td>
        </tr>
      `;
    });

    html += `
      <tr class="fw-finance__report-subtotal">
        <td><strong>Total Liabilities</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalLiabilities)}</strong></td>
      </tr>
      <tr class="fw-finance__report-section-header">
        <td colspan="2"><strong>EQUITY</strong></td>
      </tr>
    `;

    let totalEquity = 0;
    equity.forEach(acc => {
      const balance = parseInt(acc.balance_cents) || 0;
      totalEquity += balance;
      html += `
        <tr>
          <td style="padding-left: 2rem;">${acc.account_name}</td>
          <td class="fw-finance__report-table-number">${formatCurrency(balance)}</td>
        </tr>
      `;
    });

    html += `
      <tr class="fw-finance__report-subtotal">
        <td><strong>Total Equity</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalEquity)}</strong></td>
      </tr>
      <tr class="fw-finance__report-table-total">
        <td><strong>TOTAL LIABILITIES & EQUITY</strong></td>
        <td class="fw-finance__report-table-number"><strong>${formatCurrency(totalLiabilities + totalEquity)}</strong></td>
      </tr>
        </tbody>
      </table>

      ${totalAssets !== (totalLiabilities + totalEquity) ? `
        <div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">
          ⚠️ Balance Sheet is OUT OF BALANCE by ${formatCurrency(Math.abs(totalAssets - (totalLiabilities + totalEquity)))}
        </div>
      ` : `
        <div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">
          ✓ Balance Sheet is IN BALANCE
        </div>
      `}
    `;

    reportContent.innerHTML = html;
  }

  // Render GL Detail
  function renderGLDetail() {
    const { company_name, date, account, transactions, opening_balance_cents, closing_balance_cents } = reportData;

    let html = `
      <div class="fw-finance__report-header">
        <h1 class="fw-finance__report-title">${company_name}</h1>
        <h2 class="fw-finance__report-subtitle">General Ledger Detail</h2>
        <p class="fw-finance__report-date">Account: ${account.account_code} - ${account.account_name}</p>
        <p class="fw-finance__report-date">Period ending ${formatDate(date)}</p>
      </div>

      <div class="fw-finance__report-balance-row">
        <span>Opening Balance:</span>
        <span class="fw-finance__report-table-number"><strong>${formatCurrency(opening_balance_cents)}</strong></span>
      </div>

      <table class="fw-finance__report-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Description</th>
            <th>Reference</th>
            <th class="fw-finance__report-table-number">Debit</th>
            <th class="fw-finance__report-table-number">Credit</th>
            <th class="fw-finance__report-table-number">Balance</th>
          </tr>
        </thead>
        <tbody>
    `;

    let runningBalance = parseInt(opening_balance_cents);

    transactions.forEach(tx => {
      const debitCents = parseInt(tx.debit_cents) || 0;
      const creditCents = parseInt(tx.credit_cents) || 0;
      runningBalance += (debitCents - creditCents);

      html += `
        <tr>
          <td>${formatDate(tx.entry_date)}</td>
          <td>${tx.description || tx.memo || '-'}</td>
          <td>${tx.reference || '-'}</td>
          <td class="fw-finance__report-table-number">${debitCents > 0 ? formatCurrency(debitCents) : '-'}</td>
          <td class="fw-finance__report-table-number">${creditCents > 0 ? formatCurrency(creditCents) : '-'}</td>
          <td class="fw-finance__report-table-number">${formatCurrency(runningBalance)}</td>
        </tr>
      `;
    });

    html += `
        </tbody>
      </table>

      <div class="fw-finance__report-balance-row" style="margin-top: 1rem;">
        <span>Closing Balance:</span>
        <span class="fw-finance__report-table-number"><strong>${formatCurrency(closing_balance_cents)}</strong></span>
      </div>
    `;

    reportContent.innerHTML = html;
  }

  // Export to CSV
  function exportToCSV() {
    if (!reportData) return;

    let csv = '';
    let filename = `${currentReport}-${reportDate.value}.csv`;

    switch (currentReport) {
      case 'trial-balance':
        csv = 'Account Code,Account Name,Debit,Credit\n';
        reportData.accounts.forEach(acc => {
          csv += `"${acc.account_code}","${acc.account_name}",${acc.debit_cents/100},${acc.credit_cents/100}\n`;
        });
        break;

      case 'pl':
        csv = 'Account,Amount\n';
        csv += 'REVENUE\n';
        reportData.revenue.forEach(acc => {
          csv += `"${acc.account_name}",${acc.balance_cents/100}\n`;
        });
        csv += 'EXPENSES\n';
        reportData.expenses.forEach(acc => {
          csv += `"${acc.account_name}",${acc.balance_cents/100}\n`;
        });
        csv += `NET INCOME,${reportData.net_income_cents/100}\n`;
        break;

      case 'balance-sheet':
        csv = 'Account,Amount\n';
        csv += 'ASSETS\n';
        reportData.assets.forEach(acc => {
          csv += `"${acc.account_name}",${acc.balance_cents/100}\n`;
        });
        csv += 'LIABILITIES\n';
        reportData.liabilities.forEach(acc => {
          csv += `"${acc.account_name}",${acc.balance_cents/100}\n`;
        });
        csv += 'EQUITY\n';
        reportData.equity.forEach(acc => {
          csv += `"${acc.account_name}",${acc.balance_cents/100}\n`;
        });
        break;

      case 'gl-detail':
        csv = 'Date,Description,Reference,Debit,Credit,Balance\n';
        let balance = parseInt(reportData.opening_balance_cents);
        reportData.transactions.forEach(tx => {
          const debit = parseInt(tx.debit_cents) || 0;
          const credit = parseInt(tx.credit_cents) || 0;
          balance += (debit - credit);
          csv += `"${tx.entry_date}","${tx.description || tx.memo}","${tx.reference || ''}",${debit/100},${credit/100},${balance/100}\n`;
        });
        break;
    }

    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
  }

  // Event Listeners
  if (runReportBtn) {
    runReportBtn.addEventListener('click', runReport);
  }

  if (exportBtn) {
    exportBtn.addEventListener('click', exportToCSV);
  }

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadFilters();
  });

})();