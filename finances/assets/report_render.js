// /finances/assets/report_render.js
// Shared rendering + CSV helpers for the standalone (printable) finance report
// pages: Trial Balance, Balance Sheet, Income Statement and GL Detail.
//
// These pages fetch the SAME canonical AJAX endpoints used by the in-page
// reports (/finances/reports.php), so their output is identical to the
// on-screen reports — a single source of truth for the numbers. The render
// functions below are ports of the equivalents in reports.js.
//
// Depends on the global helpers from finance.js: formatCurrency, formatDate,
// escapeHtml. Loaded after finance.js.

(function () {
  'use strict';

  var esc = window.escapeHtml;
  var money = window.formatCurrency;
  var fdate = window.formatDate;

  // SARS-compliant report header (company details + title + prepared-by).
  function reportHeader(meta, title, dateInfo) {
    if (!meta) return '';
    var html = '<div class="fw-finance__report-header">';
    html += '<h1 class="fw-finance__report-title">' + esc(meta.company_name) + '</h1>';

    var details = [];
    if (meta.reg_number) details.push('Reg No: ' + esc(meta.reg_number));
    if (meta.vat_number) details.push('VAT No: ' + esc(meta.vat_number));
    if (meta.tax_number) details.push('Tax No: ' + esc(meta.tax_number));
    if (details.length) {
      html += '<p class="fw-finance__report-company-details">' + details.join(' &nbsp;|&nbsp; ') + '</p>';
    }

    html += '<h2 class="fw-finance__report-subtitle">' + esc(title) + '</h2>';
    html += '<p class="fw-finance__report-date">' + esc(dateInfo) + '</p>';

    if (meta.prepared_by || meta.generated_at) {
      var footer = '';
      if (meta.prepared_by) footer += 'Prepared by: ' + esc(meta.prepared_by);
      if (meta.generated_at) {
        var genDate = new Date(meta.generated_at);
        if (footer) footer += ' &nbsp;|&nbsp; ';
        footer += 'Generated: ' + genDate.toLocaleString('en-ZA');
      }
      html += '<p class="fw-finance__report-prepared">' + footer + '</p>';
    }

    html += '</div>';
    return html;
  }

  // Render a labelled section of accounts (null-safe).
  function accountSection(accounts, sectionTitle, indentLevel) {
    if (!accounts || !accounts.length) return '';
    var html = '';
    var indent = indentLevel || 1;
    var padding = (indent * 2) + 'rem';

    if (sectionTitle) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>' + esc(sectionTitle) + '</strong></td></tr>';
    }

    accounts.forEach(function (acc) {
      var balance = parseInt(acc.balance_cents, 10) || 0;
      html += '<tr>';
      html += '<td style="padding-left: ' + padding + ';">' + esc(acc.account_name) + '</td>';
      html += '<td class="fw-finance__report-table-number">' + money(balance) + '</td>';
      html += '</tr>';
    });

    return html;
  }

  // ---------- Trial Balance ----------
  function trialBalance(data) {
    var meta = data.report_meta;
    var html = reportHeader(meta, 'Trial Balance', 'As at ' + fdate(data.date));

    html += '<table class="fw-finance__report-table"><thead><tr>';
    html += '<th>Account Code</th><th>Account Name</th>';
    html += '<th class="fw-finance__report-table-number">Debit</th>';
    html += '<th class="fw-finance__report-table-number">Credit</th>';
    html += '</tr></thead><tbody>';

    var totalDebit = 0;
    var totalCredit = 0;

    (data.accounts || []).forEach(function (acc) {
      var debitCents = parseInt(acc.debit_cents, 10) || 0;
      var creditCents = parseInt(acc.credit_cents, 10) || 0;
      totalDebit += debitCents;
      totalCredit += creditCents;

      html += '<tr>';
      html += '<td>' + esc(acc.account_code) + '</td>';
      html += '<td>' + esc(acc.account_name) + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (debitCents > 0 ? money(debitCents) : '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (creditCents > 0 ? money(creditCents) : '-') + '</td>';
      html += '</tr>';
    });

    html += '</tbody><tfoot>';
    html += '<tr class="fw-finance__report-table-total">';
    html += '<td colspan="2"><strong>Total</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(totalDebit) + '</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(totalCredit) + '</strong></td>';
    html += '</tr></tfoot></table>';

    if (totalDebit !== totalCredit) {
      html += '<div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">Trial Balance is OUT OF BALANCE by ' + money(Math.abs(totalDebit - totalCredit)) + '</div>';
    } else {
      html += '<div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">Trial Balance is IN BALANCE</div>';
    }

    return html;
  }

  // ---------- Income Statement (Profit & Loss) ----------
  function incomeStatement(data) {
    var meta = data.report_meta;
    var dateInfo = 'For the period ' + fdate(data.start_date) + ' to ' + fdate(data.end_date || data.date);
    var html = reportHeader(meta, 'Income Statement', dateInfo);

    html += '<table class="fw-finance__report-table"><tbody>';

    html += accountSection(data.revenue, 'REVENUE');
    html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Revenue</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_revenue_cents) + '</strong></td></tr>';

    if (data.cost_of_sales && data.cost_of_sales.length) {
      html += accountSection(data.cost_of_sales, 'COST OF SALES');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Cost of Sales</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_cost_of_sales_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-table-total"><td><strong>GROSS PROFIT</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.gross_profit_cents) + '</strong></td></tr>';

    if (data.operating_expenses && data.operating_expenses.length) {
      html += accountSection(data.operating_expenses, 'OPERATING EXPENSES');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Operating Expenses</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_operating_expenses_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-table-total"><td><strong>OPERATING PROFIT</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.operating_profit_cents) + '</strong></td></tr>';

    if (data.other_income && data.other_income.length) {
      html += accountSection(data.other_income, 'OTHER INCOME');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Other Income</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_other_income_cents) + '</strong></td></tr>';
    }

    if (data.finance_costs && data.finance_costs.length) {
      html += accountSection(data.finance_costs, 'FINANCE COSTS');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Finance Costs</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_finance_costs_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-table-total"><td><strong>PROFIT BEFORE TAX</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.profit_before_tax_cents) + '</strong></td></tr>';

    if (data.tax_expense && data.tax_expense.length) {
      html += accountSection(data.tax_expense, 'TAX EXPENSE');
      html += '<tr class="fw-finance__report-subtotal"><td><strong>Total Tax Expense</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_tax_expense_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-table-total" style="border-top: 3px double var(--fw-border);">';
    html += '<td><strong>NET PROFIT AFTER TAX</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.net_profit_after_tax_cents) + '</strong></td></tr>';

    html += '</tbody></table>';
    return html;
  }

  // ---------- Balance Sheet ----------
  function balanceSheet(data) {
    var meta = data.report_meta;
    var html = reportHeader(meta, 'Statement of Financial Position', 'As at ' + fdate(data.date));

    html += '<table class="fw-finance__report-table"><tbody>';

    html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>ASSETS</strong></td></tr>';

    if (data.current_assets && data.current_assets.length) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Current Assets</em></td></tr>';
      data.current_assets.forEach(function (acc) {
        html += '<tr><td style="padding-left: 3rem;">' + esc(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + money(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Current Assets</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_current_assets_cents) + '</strong></td></tr>';
    }

    if (data.non_current_assets && data.non_current_assets.length) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Non-Current Assets</em></td></tr>';
      data.non_current_assets.forEach(function (acc) {
        html += '<tr><td style="padding-left: 3rem;">' + esc(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + money(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Non-Current Assets</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_non_current_assets_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-table-total"><td><strong>TOTAL ASSETS</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_assets_cents) + '</strong></td></tr>';

    html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>LIABILITIES</strong></td></tr>';

    if (data.current_liabilities && data.current_liabilities.length) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Current Liabilities</em></td></tr>';
      data.current_liabilities.forEach(function (acc) {
        html += '<tr><td style="padding-left: 3rem;">' + esc(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + money(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Current Liabilities</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_current_liabilities_cents) + '</strong></td></tr>';
    }

    if (data.non_current_liabilities && data.non_current_liabilities.length) {
      html += '<tr class="fw-finance__report-section-header"><td colspan="2" style="padding-left: 1rem;"><em>Non-Current Liabilities</em></td></tr>';
      data.non_current_liabilities.forEach(function (acc) {
        html += '<tr><td style="padding-left: 3rem;">' + esc(acc.account_name) + '</td>';
        html += '<td class="fw-finance__report-table-number">' + money(acc.balance_cents) + '</td></tr>';
      });
      html += '<tr class="fw-finance__report-subtotal"><td style="padding-left: 1rem;"><strong>Total Non-Current Liabilities</strong></td>';
      html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_non_current_liabilities_cents) + '</strong></td></tr>';
    }

    html += '<tr class="fw-finance__report-subtotal"><td><strong>TOTAL LIABILITIES</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_liabilities_cents) + '</strong></td></tr>';

    html += '<tr class="fw-finance__report-section-header"><td colspan="2"><strong>EQUITY</strong></td></tr>';
    (data.equity || []).forEach(function (acc) {
      html += '<tr><td style="padding-left: 2rem;">' + esc(acc.account_name) + '</td>';
      html += '<td class="fw-finance__report-table-number">' + money(acc.balance_cents) + '</td></tr>';
    });
    html += '<tr class="fw-finance__report-subtotal"><td><strong>TOTAL EQUITY</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_equity_cents) + '</strong></td></tr>';

    html += '<tr class="fw-finance__report-table-total" style="border-top: 3px double var(--fw-border);">';
    html += '<td><strong>TOTAL LIABILITIES &amp; EQUITY</strong></td>';
    html += '<td class="fw-finance__report-table-number"><strong>' + money(data.total_liabilities_equity_cents) + '</strong></td></tr>';

    html += '</tbody></table>';

    if (data.total_assets_cents !== data.total_liabilities_equity_cents) {
      html += '<div class="fw-finance__alert fw-finance__alert--danger" style="margin-top: 1rem;">Balance Sheet is OUT OF BALANCE by ' + money(Math.abs(data.total_assets_cents - data.total_liabilities_equity_cents)) + '</div>';
    } else {
      html += '<div class="fw-finance__alert fw-finance__alert--success" style="margin-top: 1rem;">Balance Sheet is IN BALANCE</div>';
    }

    return html;
  }

  // ---------- GL Detail ----------
  function glDetail(data) {
    var meta = data.report_meta;
    var html = reportHeader(meta, 'General Ledger Detail',
      'Account: ' + (data.account ? data.account.account_code + ' - ' + data.account.account_name : '') +
      ' | Period ending ' + fdate(data.date));

    html += '<div class="fw-finance__report-balance-row">';
    html += '<span>Opening Balance:</span>';
    html += '<span class="fw-finance__report-table-number"><strong>' + money(data.opening_balance_cents) + '</strong></span>';
    html += '</div>';

    html += '<table class="fw-finance__report-table"><thead><tr>';
    html += '<th>Date</th><th>Description</th><th>Reference</th>';
    html += '<th class="fw-finance__report-table-number">Debit</th>';
    html += '<th class="fw-finance__report-table-number">Credit</th>';
    html += '<th class="fw-finance__report-table-number">Balance</th>';
    html += '</tr></thead><tbody>';

    var runningBalance = parseInt(data.opening_balance_cents, 10) || 0;

    (data.transactions || []).forEach(function (tx) {
      var debitCents = parseInt(tx.debit_cents, 10) || 0;
      var creditCents = parseInt(tx.credit_cents, 10) || 0;
      runningBalance += (debitCents - creditCents);

      html += '<tr>';
      html += '<td>' + esc(fdate(tx.entry_date)) + '</td>';
      html += '<td>' + esc(tx.description || tx.memo || '-') + '</td>';
      html += '<td>' + esc(tx.reference || '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (debitCents > 0 ? money(debitCents) : '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + (creditCents > 0 ? money(creditCents) : '-') + '</td>';
      html += '<td class="fw-finance__report-table-number">' + money(runningBalance) + '</td>';
      html += '</tr>';
    });

    html += '</tbody></table>';

    html += '<div class="fw-finance__report-balance-row" style="margin-top: 1rem;">';
    html += '<span>Closing Balance:</span>';
    html += '<span class="fw-finance__report-table-number"><strong>' + money(data.closing_balance_cents) + '</strong></span>';
    html += '</div>';

    return html;
  }

  // ---------- CSV helpers ----------
  // Guard against CSV formula injection.
  function csvSafe(value) {
    var s = String(value === null || value === undefined ? '' : value);
    if (/^[=+\-@\t\r]/.test(s) && !/^[-+]?\d+(\.\d+)?$/.test(s)) {
      return "'" + s;
    }
    return s;
  }
  function q(value) {
    return '"' + csvSafe(value).replace(/"/g, '""') + '"';
  }

  var csv = {
    trialBalance: function (data) {
      var out = 'Account Code,Account Name,Debit,Credit\n';
      (data.accounts || []).forEach(function (acc) {
        out += q(acc.account_code || '') + ',' + q(acc.account_name || '') + ',' + ((acc.debit_cents || 0) / 100).toFixed(2) + ',' + ((acc.credit_cents || 0) / 100).toFixed(2) + '\n';
      });
      return out;
    },
    incomeStatement: function (data) {
      var out = 'Section,Account Code,Account Name,Amount\n';
      function sec(name, rows) {
        (rows || []).forEach(function (acc) {
          out += q(name) + ',' + q(acc.account_code || '') + ',' + q(acc.account_name || '') + ',' + ((acc.balance_cents || 0) / 100).toFixed(2) + '\n';
        });
      }
      sec('Revenue', data.revenue);
      out += ',,Total Revenue,' + ((data.total_revenue_cents || 0) / 100).toFixed(2) + '\n';
      sec('Cost of Sales', data.cost_of_sales);
      out += ',,Gross Profit,' + ((data.gross_profit_cents || 0) / 100).toFixed(2) + '\n';
      sec('Operating Expenses', data.operating_expenses);
      out += ',,Operating Profit,' + ((data.operating_profit_cents || 0) / 100).toFixed(2) + '\n';
      sec('Other Income', data.other_income);
      sec('Finance Costs', data.finance_costs);
      out += ',,Profit Before Tax,' + ((data.profit_before_tax_cents || 0) / 100).toFixed(2) + '\n';
      sec('Tax Expense', data.tax_expense);
      out += ',,Net Profit After Tax,' + ((data.net_profit_after_tax_cents || 0) / 100).toFixed(2) + '\n';
      return out;
    },
    balanceSheet: function (data) {
      var out = 'Section,Account Name,Amount\n';
      function sec(name, rows) {
        (rows || []).forEach(function (acc) {
          out += q(name) + ',' + q(acc.account_name || '') + ',' + ((acc.balance_cents || 0) / 100).toFixed(2) + '\n';
        });
      }
      sec('Current Assets', data.current_assets);
      out += ',Total Current Assets,' + ((data.total_current_assets_cents || 0) / 100).toFixed(2) + '\n';
      sec('Non-Current Assets', data.non_current_assets);
      out += ',Total Non-Current Assets,' + ((data.total_non_current_assets_cents || 0) / 100).toFixed(2) + '\n';
      out += ',TOTAL ASSETS,' + ((data.total_assets_cents || 0) / 100).toFixed(2) + '\n';
      sec('Current Liabilities', data.current_liabilities);
      out += ',Total Current Liabilities,' + ((data.total_current_liabilities_cents || 0) / 100).toFixed(2) + '\n';
      sec('Non-Current Liabilities', data.non_current_liabilities);
      out += ',Total Non-Current Liabilities,' + ((data.total_non_current_liabilities_cents || 0) / 100).toFixed(2) + '\n';
      sec('Equity', data.equity);
      out += ',TOTAL EQUITY,' + ((data.total_equity_cents || 0) / 100).toFixed(2) + '\n';
      return out;
    },
    glDetail: function (data) {
      var out = 'Date,Description,Reference,Debit,Credit,Balance\n';
      var balance = parseInt(data.opening_balance_cents, 10) || 0;
      out += q('') + ',' + q('Opening Balance') + ',' + q('') + ',,,' + (balance / 100).toFixed(2) + '\n';
      (data.transactions || []).forEach(function (tx) {
        var debit = parseInt(tx.debit_cents, 10) || 0;
        var credit = parseInt(tx.credit_cents, 10) || 0;
        balance += (debit - credit);
        out += q(tx.entry_date || '') + ',' + q(tx.description || tx.memo || '') + ',' + q(tx.reference || '') + ',' + (debit / 100).toFixed(2) + ',' + (credit / 100).toFixed(2) + ',' + (balance / 100).toFixed(2) + '\n';
      });
      return out;
    }
  };

  // Trigger a CSV download in the browser.
  function download(filename, csvText) {
    var blob = new Blob([csvText], { type: 'text/csv' });
    var url = window.URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
  }

  window.FWReport = {
    reportHeader: reportHeader,
    trialBalance: trialBalance,
    incomeStatement: incomeStatement,
    balanceSheet: balanceSheet,
    glDetail: glDetail,
    csv: csv,
    download: download
  };
})();
