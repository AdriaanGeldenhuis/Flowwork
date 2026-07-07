// /finances/assets/reports.js
// Financial Reports Module — SARS Compliant

(function() {
  'use strict';

  let currentReport = 'trial-balance';
  let reportData = null;
  let accounts = [];
  let projects = [];

  // DOM Elements
  const reportTypeBtns = document.querySelectorAll('.fw-finance__report-type-btn');
  const reportDate = document.getElementById('reportDate');
  const reportStartDate = document.getElementById('reportStartDate');
  const startDateFilterGroup = document.getElementById('startDateFilterGroup');
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
  const arCsvLink      = document.getElementById('arCsv');
  const arPdfLink      = document.getElementById('arPdf');
  const apCsvLink      = document.getElementById('apCsv');
  const apPdfLink      = document.getElementById('apPdf');

  // VAT export elements
  const vatCsvLink     = document.getElementById('vatCsv');
  const vatPdfLink     = document.getElementById('vatPdf');
  const exportsVatStart = document.getElementById('exportsVatStart');
  const exportsVatEnd   = document.getElementById('exportsVatEnd');

  // HTML escape utility to prevent XSS
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Render SARS-compliant report header with company info
  function renderReportHeader(meta, title, dateInfo) {
    if (!meta) return '';
    let html = '<div class="fw-finance__report-header">';
    html += '<h1 class="fw-finance__report-title">' + escapeHtml(meta.company_name) + '</h1>';

    // Company registration details
    const details = [];
    if (meta.reg_number) details.push('Reg No: ' + escapeHtml(meta.reg_number));
    if (meta.vat_number) details.push('VAT No: ' + escapeHtml(meta.vat_number));
    if (meta.tax_number) details.push('Tax No: ' + escapeHtml(meta.tax_number));
    if (details.length > 0) {
      html += '<p class="fw-finance__report-company-details">' + details.join(' &nbsp;|&nbsp; ') + '</p>';
    }

    html += '<h2 class="fw-finance__report-subtitle">' + escapeHtml(title) + '</h2>';
    html += '<p class="fw-finance__report-date">' + escapeHtml(dateInfo) + '</p>';

    // Prepared by and generation timestamp
    if (meta.prepared_by || meta.generated_at) {
      let footer = '';
      if (meta.prepared_by) footer += 'Prepared by: ' + escapeHtml(meta.prepared_by);
      if (meta.generated_at) {
        const genDate = new Date(meta.generated_at);
        if (footer) footer += ' &nbsp;|&nbsp; ';
        footer += 'Generated: ' + genDate.toLocaleString('en-ZA');
      }
      html += '<p class="fw-finance__report-prepared">' + footer + '</p>';
    }

    html += '</div>';
    return html;
  }

  // Update export links with selected date for aging reports and VAT period
  function updateExportLinks() {
    if (exportsDate) {
      const d = exportsDate.value;
      if (arCsvLink) arCsvLink.href = d ? '/export/csv/ar_aging.php?date=' + encodeURIComponent(d) : '/export/csv/ar_aging.php';
      if (arPdfLink) arPdfLink.href = d ? '/export/pdf/ar_aging.php?date=' + encodeURIComponent(d) : '/export/pdf/ar_aging.php';
      if (apCsvLink) apCsvLink.href = d ? '/export/csv/ap_aging.php?date=' + encodeURIComponent(d) : '/export/csv/ap_aging.php';
      if (apPdfLink) apPdfLink.href = d ? '/export/pdf/ap_aging.php?date=' + encodeURIComponent(d) : '/export/pdf/ap_aging.php';
    }
    // Update VAT export links with date range
    const vs = exportsVatStart ? exportsVatStart.value : '';
    const ve = exportsVatEnd ? exportsVatEnd.value : '';
    if (vatCsvLink) {
      vatCsvLink.href = (vs && ve) ? '/export/csv/vat_summary.php?start_date=' + encodeURIComponent(vs) + '&end_date=' + encodeURIComponent(ve) : '/export/csv/vat_summary.php';
    }
    if (vatPdfLink) {
      vatPdfLink.href = (vs && ve) ? '/export/pdf/vat_summary.php?start_date=' + encodeURIComponent(vs) + '&end_date=' + encodeURIComponent(ve) : '/export/pdf/vat_summary.php';
    }
  }

  if (exportsDate) exportsDate.addEventListener('change', updateExportLinks);
  if (exportsVatStart) exportsVatStart.addEventListener('change', updateExportLinks);
  if (exportsVatEnd) exportsVatEnd.addEventListener('change', updateExportLinks);
  updateExportLinks();

  // Load Accounts and Projects
  async function loadFilters() {
    const accResult = await FinanceAPI.request('/finances/ajax/account_list.php');
    if (accResult.ok) {
      accounts = accResult.data;
      populateAccountFilter();
    }

    try {
      // project.list.php responds {ok, data:{projects:[...], pagination}}
      // (the old /projects/api.php?action=list path never existed — the
      // project filter silently stayed empty).
      const projResult = await FinanceAPI.request('/projects/api/project.list.php?per=100');
      if (projResult.ok) {
        projects = (projResult.data && projResult.data.projects) || [];
        populateProjectFilter();
      }
    } catch (e) {
      // Projects module may not exist
    }
  }

  function populateAccountFilter() {
    let html = '<option value="">All Accounts</option>';
    accounts.forEach(acc => {
      html += '<option value="' + escapeHtml(acc.account_id) + '">' + escapeHtml(acc.account_code) + ' - ' + escapeHtml(acc.account_name) + '</option>';
    });
    accountFilter.innerHTML = html;
  }

  function populateProjectFilter() {
    let html = '<option value="">All Projects</option>';
    projects.forEach(proj => {
      html += '<option value="' + escapeHtml(proj.project_id) + '">' + escapeHtml(proj.name) + '</option>';
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
      const showStartDate = (currentReport === 'pl' || currentReport === 'gl-detail');
      if (startDateFilterGroup) startDateFilterGroup.style.display = showStartDate ? 'block' : 'none';
      accountFilterGroup.style.display = (currentReport === 'gl-detail') ? 'block' : 'none';
      projectFilterGroup.style.display = (currentReport === 'gl-detail') ? 'block' : 'none';

      // Special handling for Exports tab
      if (currentReport === 'exports') {
        if (runReportBtn) runReportBtn.style.display = 'none';
        if (exportBtn) exportBtn.style.display = 'none';
        reportContent.style.display = 'none';
        if (exportsContent) exportsContent.style.display = 'block';
        updateExportLinks();
        reportInfo.textContent = '';
        return;
      } else {
        if (runReportBtn) runReportBtn.style.display = '';
        if (exportBtn) exportBtn.style.display = '';
        reportContent.style.display = '';
        if (exportsContent) exportsContent.style.display = 'none';
      }

      reportContent.innerHTML = '<div class="fw-finance__empty-state">Click "Run Report" to generate</div>';
      exportBtn.disabled = true;
      reportInfo.textContent = '';
    });
  });

  // Run Report
  async function runReport() {
    const date = reportDate.value;
    const startDate = reportStartDate ? reportStartDate.value : '';
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
        if (startDate) params.append('start_date', startDate);
        break;
      case 'balance-sheet':
        endpoint = '/finances/ajax/report_balance_sheet.php';
        break;
      case 'gl-detail':
        endpoint = '/finances/ajax/report_gl_detail.php';
        if (accountId) params.append('account_id', accountId);
        if (projectId) params.append('project_id', projectId);
        if (startDate) params.append('start_date', startDate);
        break;
    }

    const result = await FinanceAPI.request(endpoint + '?' + params);

    if (result.ok) {
      reportData = result.data;
      renderReport();
      exportBtn.disabled = false;
      reportInfo.textContent = 'Generated: ' + new Date().toLocaleString();
    } else {
      reportContent.innerHTML = '<div class="fw-finance__empty-state">Error: ' + escapeHtml(result.error || 'Failed to generate report') + '</div>';
    }
  }

  // Render Report
  function renderReport() {
    switch (currentReport) {
      case 'trial-balance':  renderTrialBalance(); break;
      case 'pl':             renderProfitLoss(); break;
      case 'balance-sheet':  renderBalanceSheet(); break;
      case 'gl-detail':      renderGLDetail(); break;
    }
  }

  // Helper: render a section of accounts (null-safe)
  function renderAccountSection(accounts, sectionTitle, indentLevel) {
    if (!accounts || !accounts.length) return '';
    let html = '';
    const indent = indentLevel || 1;
    const padding = (indent * 2) + 'rem';

    if (sectionTitle) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>' + escapeHtml(sectionTitle) + '</strong></td></tr>';
    }

    accounts.forEach(acc => {
      const balance = parseInt(acc.balance_cents) || 0;
      html += '<tr>';
      html += '<td style="padding-left: ' + padding + ';">' + escapeHtml(acc.account_name) + '</td>';
      html += '<td class="fw-finance__report-table-number">' + formatCurrency(balance) + '</td>';
      html += '</tr>';
    });

    return html;
  }

  // Render Trial Balance
  function renderTrialBalance() {
    const data = reportData;
    const meta = data.report_meta;

    let html = renderReportHeader(meta, 'Trial Balance', 'As at ' + formatDate(data.date));

    html += '<table class="fw-finance__report-table"><thead><tr>';
    html += '<th>Account Code</th><th>Account Name</th>';
    html += '<th class="fw-finance__report-table-number">Debit</th>';
    html += '<th class="fw-finance__report-table-number">Credit</th>';
    html += '</tr></thead><tbody>';

    let totalDebit = 0;
    let totalCredit = 0;

    (data.accounts || []).forEach(acc => {
      const debitCents = parseInt(acc.debit_cents) || 0;
      const creditCents = parseInt(acc.credit_cents) || 0;
      totalDebit += debitCents;
      totalCredit += creditCents;

      html += '<tr>';
      html += '<td>' + escapeHtml(acc.account_code) + '</td>';
      html += '<td>' + escapeHtml(acc.account_name) + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (debitCents > 0 ? formatCurrency(debitCents) : '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (creditCents > 0 ? formatCurrency(creditCents) : '-') + '</td>';
      html += '</tr>';
    });

    html += '</tbody><tfoot>';
    html += '<tr class="fw-finance__report-table-total">';
    html += '<td colspan="2"><strong>Total</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(totalDebit) + '</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(totalCredit) + '</strong></td>';
    html += '</tr></tfoot></table>';

    if (totalDebit !== totalCredit) {
      html += '<div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">Trial Balance is OUT OF BALANCE by ' + formatCurrency(Math.abs(totalDebit - totalCredit)) + '</div>';
    } else {
      html += '<div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">Trial Balance is IN BALANCE</div>';
    }

    reportContent.innerHTML = html;
  }

  // Render SARS-Compliant Profit & Loss
  function renderProfitLoss() {
    const data = reportData;
    const meta = data.report_meta;
    const dateInfo = 'For the period ' + formatDate(data.start_date) + ' to ' + formatDate(data.end_date || data.date);

    let html = renderReportHeader(meta, 'Income Statement', dateInfo);

    html += '<table class="fw-finance__report-table"><tbody>';

    // REVENUE
    html += renderAccountSection(data.revenue, 'REVENUE');
    html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Revenue</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_revenue_cents) + '</strong></td></tr>';

    // COST OF SALES
    if (data.cost_of_sales && data.cost_of_sales.length > 0) {
      html += renderAccountSection(data.cost_of_sales, 'COST OF SALES');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Cost of Sales</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_cost_of_sales_cents) + '</strong></td></tr>';
    }

    // GROSS PROFIT
    html += '<tr class="fw-finance__report-table-total"><td><strong>GROSS PROFIT</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.gross_profit_cents) + '</strong></td></tr>';

    // OPERATING EXPENSES
    if (data.operating_expenses && data.operating_expenses.length > 0) {
      html += renderAccountSection(data.operating_expenses, 'OPERATING EXPENSES');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Operating Expenses</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_operating_expenses_cents) + '</strong></td></tr>';
    }

    // OPERATING PROFIT
    html += '<tr class="fw-finance__report-table-total"><td><strong>OPERATING PROFIT</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.operating_profit_cents) + '</strong></td></tr>';

    // OTHER INCOME
    if (data.other_income && data.other_income.length > 0) {
      html += renderAccountSection(data.other_income, 'OTHER INCOME');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Other Income</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_other_income_cents) + '</strong></td></tr>';
    }

    // FINANCE COSTS
    if (data.finance_costs && data.finance_costs.length > 0) {
      html += renderAccountSection(data.finance_costs, 'FINANCE COSTS');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Finance Costs</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_finance_costs_cents) + '</strong></td></tr>';
    }

    // PROFIT BEFORE TAX
    html += '<tr class="fw-finance__report-table-total"><td><strong>PROFIT BEFORE TAX</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.profit_before_tax_cents) + '</strong></td></tr>';

    // TAX EXPENSE
    if (data.tax_expense && data.tax_expense.length > 0) {
      html += renderAccountSection(data.tax_expense, 'TAX EXPENSE');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Tax Expense</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_tax_expense_cents) + '</strong></td></tr>';
    }

    // NET PROFIT AFTER TAX
    html += '<tr class="fw-finance__report-table-total" style="border-top: 3px double var(--fw-border);">';
    html += '<td><strong>NET PROFIT AFTER TAX</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.net_profit_after_tax_cents) + '</strong></td></tr>';

    html += '</tbody></table>';
    reportContent.innerHTML = html;
  }

  // Render IFRS/SARS-Compliant Balance Sheet
  function renderBalanceSheet() {
    const data = reportData;
    const meta = data.report_meta;

    let html = renderReportHeader(meta, 'Statement of Financial Position', 'As at ' + formatDate(data.date));

    html += '<table class="fw-finance__report-table"><tbody>';

    // ASSETS
    html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>ASSETS</strong></td></tr>';

    // Current Assets
    if (data.current_assets && data.current_assets.length > 0) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Current Assets</em></td></tr>';
      data.current_assets.forEach(acc => {
        html += '<tr><td style="padding-left: 3rem;">' + escapeHtml(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + formatCurrency(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Current Assets</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_current_assets_cents) + '</strong></td></tr>';
    }

    // Non-Current Assets
    if (data.non_current_assets && data.non_current_assets.length > 0) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Non-Current Assets</em></td></tr>';
      data.non_current_assets.forEach(acc => {
        html += '<tr><td style="padding-left: 3rem;">' + escapeHtml(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + formatCurrency(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Non-Current Assets</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_non_current_assets_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-table-total"><td><strong>TOTAL ASSETS</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_assets_cents) + '</strong></td></tr>';

    // LIABILITIES
    html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>LIABILITIES</strong></td></tr>';

    // Current Liabilities
    if (data.current_liabilities && data.current_liabilities.length > 0) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Current Liabilities</em></td></tr>';
      data.current_liabilities.forEach(acc => {
        html += '<tr><td style="padding-left: 3rem;">' + escapeHtml(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + formatCurrency(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Current Liabilities</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_current_liabilities_cents) + '</strong></td></tr>';
    }

    // Non-Current Liabilities
    if (data.non_current_liabilities && data.non_current_liabilities.length > 0) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Non-Current Liabilities</em></td></tr>';
      data.non_current_liabilities.forEach(acc => {
        html += '<tr><td style="padding-left: 3rem;">' + escapeHtml(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + formatCurrency(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Non-Current Liabilities</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_non_current_liabilities_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-subtotal"><td><strong>TOTAL LIABILITIES</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_liabilities_cents) + '</strong></td></tr>';

    // EQUITY
    html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>EQUITY</strong></td></tr>';
    (data.equity || []).forEach(acc => {
      html += '<tr><td style="padding-left: 2rem;">' + escapeHtml(acc.account_name) + '</td>';
      html += '<td class="fw-finance__report-table-number">' + formatCurrency(acc.balance_cents) + '</td></tr>';
    });
    html += '<tr class="fw-finance__report-subtotal"><td><strong>TOTAL EQUITY</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_equity_cents) + '</strong></td></tr>';

    // TOTAL L&E
    html += '<tr class="fw-finance__report-table-total" style="border-top: 3px double var(--fw-border);">';
    html += '<td><strong>TOTAL LIABILITIES &amp; EQUITY</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + formatCurrency(data.total_liabilities_equity_cents) + '</strong></td></tr>';

    html += '</tbody></table>';

    // Balance check
    if (data.total_assets_cents !== data.total_liabilities_equity_cents) {
      html += '<div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">Balance Sheet is OUT OF BALANCE by ' + formatCurrency(Math.abs(data.total_assets_cents - data.total_liabilities_equity_cents)) + '</div>';
    } else {
      html += '<div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">Balance Sheet is IN BALANCE</div>';
    }

    reportContent.innerHTML = html;
  }

  // Render GL Detail
  function renderGLDetail() {
    const data = reportData;
    const meta = data.report_meta;

    let html = renderReportHeader(meta, 'General Ledger Detail',
      'Account: ' + (data.account ? data.account.account_code + ' - ' + data.account.account_name : '') +
      ' | Period ending ' + formatDate(data.date));

    html += '<div class="fw-finance__report-balance-row">';
    html += '<span>Opening Balance:</span>';
    html += '<span class="fw-finance__report-table-number"><strong>' + formatCurrency(data.opening_balance_cents) + '</strong></span>';
    html += '</div>';

    html += '<table class="fw-finance__report-table"><thead><tr>';
    html += '<th>Date</th><th>Description</th><th>Reference</th>';
    html += '<th class="fw-finance__report-table-number">Debit</th>';
    html += '<th class="fw-finance__report-table-number">Credit</th>';
    html += '<th class="fw-finance__report-table-number">Balance</th>';
    html += '</tr></thead><tbody>';

    let runningBalance = parseInt(data.opening_balance_cents) || 0;

    (data.transactions || []).forEach(tx => {
      const debitCents = parseInt(tx.debit_cents) || 0;
      const creditCents = parseInt(tx.credit_cents) || 0;
      runningBalance += (debitCents - creditCents);

      html += '<tr>';
      // Drill-through: link the date into the source journal when we have its id.
      const dateCell = escapeHtml(formatDate(tx.entry_date));
      html += '<td>' + (tx.journal_id
        ? '<a href="/finances/gl/journal_view.php?id=' + encodeURIComponent(tx.journal_id) + '">' + dateCell + '</a>'
        : dateCell) + '</td>';
      html += '<td>' + escapeHtml(tx.description || tx.memo || '-') + '</td>';
      html += '<td>' + escapeHtml(tx.reference || '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (debitCents > 0 ? formatCurrency(debitCents) : '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (creditCents > 0 ? formatCurrency(creditCents) : '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + formatCurrency(runningBalance) + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';

    html += '<div class="fw-finance__report-balance-row" style="margin-top: 1rem;">';
    html += '<span>Closing Balance:</span>';
    html += '<span class="fw-finance__report-table-number"><strong>' + formatCurrency(data.closing_balance_cents) + '</strong></span>';
    html += '</div>';

    reportContent.innerHTML = html;
  }

  // Guard against CSV formula injection: prefix a single quote to any cell
  // starting with =, +, -, @, tab or CR so spreadsheet apps treat it as text.
  // Plain numeric values (e.g. "-123.45") are left intact.
  function csvSafe(value) {
    const s = String(value === null || value === undefined ? '' : value);
    if (/^[=+\-@\t\r]/.test(s) && !/^[-+]?\d+(\.\d+)?$/.test(s)) {
      return "'" + s;
    }
    return s;
  }

  // Export to CSV
  function exportToCSV() {
    if (!reportData) return;

    let csv = '';
    let filename = currentReport + '-' + reportDate.value + '.csv';

    switch (currentReport) {
      case 'trial-balance':
        csv = 'Account Code,Account Name,Debit,Credit\n';
        (reportData.accounts || []).forEach(acc => {
          csv += '"' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.debit_cents / 100).toFixed(2) + ',' + (acc.credit_cents / 100).toFixed(2) + '\n';
        });
        break;

      case 'pl':
        csv = 'Section,Account Code,Account Name,Amount\n';
        (reportData.revenue || []).forEach(acc => {
          csv += '"Revenue","' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","","Total Revenue",' + (reportData.total_revenue_cents / 100).toFixed(2) + '\n';
        (reportData.cost_of_sales || []).forEach(acc => {
          csv += '"Cost of Sales","' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","","Gross Profit",' + (reportData.gross_profit_cents / 100).toFixed(2) + '\n';
        (reportData.operating_expenses || []).forEach(acc => {
          csv += '"Operating Expenses","' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","","Operating Profit",' + (reportData.operating_profit_cents / 100).toFixed(2) + '\n';
        (reportData.other_income || []).forEach(acc => {
          csv += '"Other Income","' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        (reportData.finance_costs || []).forEach(acc => {
          csv += '"Finance Costs","' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","","Profit Before Tax",' + (reportData.profit_before_tax_cents / 100).toFixed(2) + '\n';
        (reportData.tax_expense || []).forEach(acc => {
          csv += '"Tax Expense","' + csvSafe(acc.account_code || '').replace(/"/g, '""') + '","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","","Net Profit After Tax",' + (reportData.net_profit_after_tax_cents / 100).toFixed(2) + '\n';
        break;

      case 'balance-sheet':
        csv = 'Section,Account Name,Amount\n';
        (reportData.current_assets || []).forEach(acc => {
          csv += '"Current Assets","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","Total Current Assets",' + ((reportData.total_current_assets_cents || 0) / 100).toFixed(2) + '\n';
        (reportData.non_current_assets || []).forEach(acc => {
          csv += '"Non-Current Assets","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","Total Non-Current Assets",' + ((reportData.total_non_current_assets_cents || 0) / 100).toFixed(2) + '\n';
        csv += '"","TOTAL ASSETS",' + ((reportData.total_assets_cents || 0) / 100).toFixed(2) + '\n';
        (reportData.current_liabilities || []).forEach(acc => {
          csv += '"Current Liabilities","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","Total Current Liabilities",' + ((reportData.total_current_liabilities_cents || 0) / 100).toFixed(2) + '\n';
        (reportData.non_current_liabilities || []).forEach(acc => {
          csv += '"Non-Current Liabilities","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","Total Non-Current Liabilities",' + ((reportData.total_non_current_liabilities_cents || 0) / 100).toFixed(2) + '\n';
        (reportData.equity || []).forEach(acc => {
          csv += '"Equity","' + csvSafe(acc.account_name || '').replace(/"/g, '""') + '",' + (acc.balance_cents / 100).toFixed(2) + '\n';
        });
        csv += '"","TOTAL EQUITY",' + ((reportData.total_equity_cents || 0) / 100).toFixed(2) + '\n';
        break;

      case 'gl-detail':
        csv = 'Date,Description,Reference,Debit,Credit,Balance\n';
        let balance = parseInt(reportData.opening_balance_cents) || 0;
        (reportData.transactions || []).forEach(tx => {
          const debit = parseInt(tx.debit_cents) || 0;
          const credit = parseInt(tx.credit_cents) || 0;
          balance += (debit - credit);
          csv += '"' + (tx.entry_date || '') + '","' + csvSafe(tx.description || tx.memo || '').replace(/"/g, '""') + '","' + csvSafe(tx.reference || '').replace(/"/g, '""') + '",' + (debit / 100).toFixed(2) + ',' + (credit / 100).toFixed(2) + ',' + (balance / 100).toFixed(2) + '\n';
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
  if (runReportBtn) runReportBtn.addEventListener('click', runReport);
  if (exportBtn) exportBtn.addEventListener('click', exportToCSV);

  // Initialize
  document.addEventListener('DOMContentLoaded', () => {
    loadFilters();
  });

})();
