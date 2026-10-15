<?php
// finances/lib/CoaSeed.php
//
// The SARS chart of accounts as PHP data, so the app can provision a
// company's chart itself — a newly registered company, or a single account a
// finance_* mapping role needs — without depending on the
// seed_sars_chart_of_accounts() stored procedure being installed on the
// server. Rows mirror that procedure in
// Migrations/2026-04-08-coa-sars-upgrade.sql exactly (asserted by
// tests/finance/test_finance_bootstrap.php), plus 5150 Cost of Goods Sold,
// which Migrations/2026-07-06-finance-sars-02-coa.sql adds for every company.

class CoaSeed
{
    /**
     * [code, name, description, type, normal_balance, subtype, parent_code,
     *  tax_code, is_system, is_control, is_locked, allow_manual_journal,
     *  afs_line_code]
     */
    public const ACCOUNTS = [
        // ASSETS
        ['1000', 'Current Assets', null, 'asset', 'debit', 'current_asset', null, null, 1, 0, 0, 0, 'BS-CA'],
        ['1500', 'Non-Current Assets', null, 'asset', 'debit', 'non_current_asset', null, null, 1, 0, 0, 0, 'BS-NCA'],
        ['1010', 'Bank Control', 'Aggregated bank control', 'asset', 'debit', 'bank', '1000', null, 1, 1, 1, 0, 'BS-CA-Cash'],
        ['1020', 'Primary Bank Account', null, 'asset', 'debit', 'bank', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1030', 'Secondary Bank Account', null, 'asset', 'debit', 'bank', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1040', 'Foreign Currency Account', null, 'asset', 'debit', 'bank', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1050', 'Credit Card Clearing', null, 'asset', 'debit', 'bank', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1060', 'Petty Cash', null, 'asset', 'debit', 'cash', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1070', 'Cash Float', null, 'asset', 'debit', 'cash', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1080', 'Cash on Deposit', null, 'asset', 'debit', 'cash', '1000', null, 1, 0, 0, 1, 'BS-CA-Cash'],
        ['1100', 'Accounts Receivable Control', 'Trade debtors control', 'asset', 'debit', 'accounts_receivable', '1000', null, 1, 1, 1, 0, 'BS-CA-Trade'],
        ['1110', 'Allowance for Doubtful Debts', 'Contra-asset', 'asset', 'credit', 'accounts_receivable', '1000', null, 1, 0, 0, 1, 'BS-CA-Trade'],
        ['1120', 'Sundry Debtors', null, 'asset', 'debit', 'current_asset', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1130', 'Staff Loans & Advances', null, 'asset', 'debit', 'current_asset', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1140', 'Director Loan Account — Debit', null, 'asset', 'debit', 'current_asset', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1200', 'Stock on Hand', null, 'asset', 'debit', 'inventory', '1000', null, 1, 0, 0, 1, 'BS-CA-Inv'],
        ['1210', 'Stock in Transit', null, 'asset', 'debit', 'inventory', '1000', null, 1, 0, 0, 1, 'BS-CA-Inv'],
        ['1220', 'Work in Progress', null, 'asset', 'debit', 'inventory', '1000', null, 1, 0, 0, 1, 'BS-CA-Inv'],
        ['1230', 'Raw Materials', null, 'asset', 'debit', 'inventory', '1000', null, 1, 0, 0, 1, 'BS-CA-Inv'],
        ['1240', 'Finished Goods', null, 'asset', 'debit', 'inventory', '1000', null, 1, 0, 0, 1, 'BS-CA-Inv'],
        ['1300', 'Prepaid Expenses', null, 'asset', 'debit', 'prepayment', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1310', 'Deposits Paid', null, 'asset', 'debit', 'prepayment', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1320', 'VAT Input Pending', null, 'asset', 'debit', 'current_asset', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1330', 'SARS Refund Receivable', null, 'asset', 'debit', 'current_asset', '1000', null, 1, 0, 0, 1, 'BS-CA-Other'],
        ['1510', 'Land & Buildings — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1520', 'Plant & Machinery — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1530', 'Motor Vehicles — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1540', 'Office Equipment — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1550', 'Furniture & Fittings — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1560', 'Computer Equipment — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1570', 'Leasehold Improvements — Cost', null, 'asset', 'debit', 'fixed_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1610', 'Accum. Dep. — Buildings', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1620', 'Accum. Dep. — Plant & Machinery', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1630', 'Accum. Dep. — Motor Vehicles', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1640', 'Accum. Dep. — Office Equipment', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1650', 'Accum. Dep. — Furniture', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1660', 'Accum. Dep. — Computer Equipment', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1670', 'Accum. Dep. — Leasehold', null, 'asset', 'credit', 'accumulated_depreciation', '1500', null, 1, 0, 0, 1, 'BS-NCA-PPE'],
        ['1710', 'Goodwill', null, 'asset', 'debit', 'intangible_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-Intang'],
        ['1720', 'Software & Licenses — Cost', null, 'asset', 'debit', 'intangible_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-Intang'],
        ['1730', 'Accum. Amortisation — Software', null, 'asset', 'credit', 'intangible_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-Intang'],
        ['1740', 'Long-term Investments', null, 'asset', 'debit', 'investment', '1500', null, 1, 0, 0, 1, 'BS-NCA-Inv'],
        ['1750', 'Loans Receivable — Long-term', null, 'asset', 'debit', 'non_current_asset', '1500', null, 1, 0, 0, 1, 'BS-NCA-Other'],
        // LIABILITIES
        ['2000', 'Current Liabilities', null, 'liability', 'credit', 'current_liability', null, null, 1, 0, 0, 0, 'BS-CL'],
        ['2500', 'Non-Current Liabilities', null, 'liability', 'credit', 'non_current_liability', null, null, 1, 0, 0, 0, 'BS-NCL'],
        ['2010', 'Accounts Payable Control', 'Trade creditors', 'liability', 'credit', 'accounts_payable', '2000', null, 1, 1, 1, 0, 'BS-CL-Trade'],
        ['2020', 'Sundry Creditors', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Other'],
        ['2030', 'Accrued Expenses', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Other'],
        ['2040', 'Customer Deposits Received', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Other'],
        ['2050', 'Director Loan — Credit', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Other'],
        ['2060', 'Shareholders Loan — Current', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Other'],
        ['2100', 'VAT Control', 'Net VAT payable to SARS', 'liability', 'credit', 'vat_control', '2000', null, 1, 1, 1, 0, 'BS-CL-Tax'],
        ['2110', 'VAT Output', 'Output VAT on sales', 'liability', 'credit', 'vat_control', '2000', 'STD', 1, 1, 1, 0, 'BS-CL-Tax'],
        ['2120', 'VAT Input', 'Input VAT on purchases', 'liability', 'debit', 'vat_control', '2000', 'INPUT', 1, 1, 1, 0, 'BS-CL-Tax'],
        ['2130', 'PAYE Payable', 'Employees tax — SARS', 'liability', 'credit', 'paye_liability', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2140', 'UIF Payable', 'UIF contributions', 'liability', 'credit', 'uif_liability', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2150', 'SDL Payable', 'Skills Development Levy', 'liability', 'credit', 'sdl_liability', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2160', 'Income Tax Payable', 'Corporate tax owing', 'liability', 'credit', 'income_tax_payable', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2170', 'Provisional Tax Paid', 'IRP6 payments', 'liability', 'debit', 'provisional_tax', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2180', 'Dividends Tax Withheld', 'DWT on declared dividends', 'liability', 'credit', 'dividends_tax_payable', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2190', 'WCA / COIDA Payable', 'Workers compensation', 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 1, 0, 'BS-CL-Tax'],
        ['2210', 'Salaries & Wages Payable', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Payroll'],
        ['2220', 'Pension / Provident Payable', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Payroll'],
        ['2230', 'Medical Aid Payable', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Payroll'],
        ['2240', 'Garnishee Orders Payable', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Payroll'],
        ['2250', 'Union Dues Payable', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Payroll'],
        ['2300', 'Bank Overdraft', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Borrow'],
        ['2310', 'Short-term Loan', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Borrow'],
        ['2320', 'Current Portion of Long-term Loan', null, 'liability', 'credit', 'current_liability', '2000', null, 1, 0, 0, 1, 'BS-CL-Borrow'],
        ['2510', 'Long-term Loan', null, 'liability', 'credit', 'loan', '2500', null, 1, 0, 0, 1, 'BS-NCL-Loan'],
        ['2520', 'Mortgage Bond', null, 'liability', 'credit', 'loan', '2500', null, 1, 0, 0, 1, 'BS-NCL-Loan'],
        ['2530', 'Instalment Sale Liability', null, 'liability', 'credit', 'loan', '2500', null, 1, 0, 0, 1, 'BS-NCL-Loan'],
        ['2540', 'Shareholders Loan — Long-term', null, 'liability', 'credit', 'non_current_liability', '2500', null, 1, 0, 0, 1, 'BS-NCL-Other'],
        ['2550', 'Deferred Tax Liability', null, 'liability', 'credit', 'non_current_liability', '2500', null, 1, 0, 1, 0, 'BS-NCL-Tax'],
        // EQUITY
        ['3000', 'Equity', null, 'equity', 'credit', 'share_capital', null, null, 1, 0, 0, 0, 'BS-EQ'],
        ['3010', 'Share Capital', null, 'equity', 'credit', 'share_capital', '3000', null, 1, 0, 1, 0, 'BS-EQ-Cap'],
        ['3020', 'Share Premium', null, 'equity', 'credit', 'share_capital', '3000', null, 1, 0, 1, 0, 'BS-EQ-Cap'],
        ['3030', 'Members Contributions', 'CC / partnerships', 'equity', 'credit', 'share_capital', '3000', null, 1, 0, 0, 1, 'BS-EQ-Cap'],
        ['3100', 'Retained Earnings', null, 'equity', 'credit', 'retained_earnings', '3000', null, 1, 0, 1, 0, 'BS-EQ-RE'],
        ['3110', 'Current Year Earnings', 'Auto P&L close', 'equity', 'credit', 'current_year_earnings', '3000', null, 1, 0, 1, 0, 'BS-EQ-RE'],
        ['3200', 'Dividends Declared', null, 'equity', 'debit', 'retained_earnings', '3000', null, 1, 0, 0, 1, 'BS-EQ-Div'],
        ['3300', 'Owners Drawings', null, 'equity', 'debit', 'drawings', '3000', null, 1, 0, 0, 1, 'BS-EQ-Draw'],
        ['3400', 'Revaluation Reserve', null, 'equity', 'credit', 'share_capital', '3000', null, 1, 0, 0, 1, 'BS-EQ-Res'],
        // REVENUE
        ['4000', 'Operating Revenue', null, 'revenue', 'credit', 'operating_revenue', null, null, 1, 0, 0, 0, 'IS-Rev'],
        ['4010', 'Sales — Standard Rated', '15% VAT', 'revenue', 'credit', 'operating_revenue', '4000', 'STD', 1, 0, 0, 1, 'IS-Rev'],
        ['4020', 'Sales — Zero Rated', 'Exports, basic foods', 'revenue', 'credit', 'operating_revenue', '4000', 'ZERO', 1, 0, 0, 1, 'IS-Rev'],
        ['4030', 'Sales — Exempt', null, 'revenue', 'credit', 'operating_revenue', '4000', 'EXEMPT', 1, 0, 0, 1, 'IS-Rev'],
        ['4040', 'Sales — Export', null, 'revenue', 'credit', 'operating_revenue', '4000', 'ZERO', 1, 0, 0, 1, 'IS-Rev'],
        ['4100', 'Service Income', null, 'revenue', 'credit', 'operating_revenue', '4000', 'STD', 1, 0, 0, 1, 'IS-Rev'],
        ['4110', 'Consulting Fees', null, 'revenue', 'credit', 'operating_revenue', '4000', 'STD', 1, 0, 0, 1, 'IS-Rev'],
        ['4200', 'Sales Returns & Allowances', 'Contra', 'revenue', 'debit', 'operating_revenue', '4000', null, 1, 0, 0, 1, 'IS-Rev'],
        ['4210', 'Discounts Allowed', 'Contra', 'revenue', 'debit', 'operating_revenue', '4000', null, 1, 0, 0, 1, 'IS-Rev'],
        ['4900', 'Other Income', null, 'revenue', 'credit', 'other_income', null, null, 1, 0, 0, 0, 'IS-OtherInc'],
        ['4910', 'Interest Received', null, 'revenue', 'credit', 'interest_income', '4900', null, 1, 0, 0, 1, 'IS-OtherInc'],
        ['4920', 'Dividend Received', null, 'revenue', 'credit', 'dividend_income', '4900', null, 1, 0, 0, 1, 'IS-OtherInc'],
        ['4930', 'Rental Income', null, 'revenue', 'credit', 'other_income', '4900', 'STD', 1, 0, 0, 1, 'IS-OtherInc'],
        ['4940', 'Commission Earned', null, 'revenue', 'credit', 'other_income', '4900', 'STD', 1, 0, 0, 1, 'IS-OtherInc'],
        ['4950', 'Forex Gain — Realised', null, 'revenue', 'credit', 'forex_gain', '4900', null, 1, 0, 0, 1, 'IS-OtherInc'],
        ['4960', 'Profit on Disposal of Asset', null, 'revenue', 'credit', 'other_income', '4900', null, 1, 0, 0, 1, 'IS-OtherInc'],
        ['4970', 'Sundry Income', null, 'revenue', 'credit', 'other_income', '4900', null, 1, 0, 0, 1, 'IS-OtherInc'],
        // COST OF SALES
        ['5000', 'Cost of Sales', null, 'expense', 'debit', 'cost_of_sales', null, null, 1, 0, 0, 0, 'IS-COS'],
        ['5010', 'Opening Stock', null, 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        ['5020', 'Purchases', null, 'expense', 'debit', 'cost_of_sales', '5000', 'INPUT', 1, 0, 0, 1, 'IS-COS'],
        ['5030', 'Carriage Inwards', null, 'expense', 'debit', 'cost_of_sales', '5000', 'INPUT', 1, 0, 0, 1, 'IS-COS'],
        ['5040', 'Customs Duty & Clearing', null, 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        ['5050', 'Direct Labour', null, 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        ['5060', 'Subcontractors', null, 'expense', 'debit', 'cost_of_sales', '5000', 'INPUT', 1, 0, 0, 1, 'IS-COS'],
        ['5090', 'Closing Stock', 'Contra — reduces COS', 'expense', 'credit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        ['5100', 'Cost of Services Rendered', null, 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        // 5150 is not in the stored procedure — Migrations/2026-07-06-finance-sars-02-coa.sql
        // adds it for every company (perpetual-inventory COGS, mapping default).
        ['5150', 'Cost of Goods Sold', 'Perpetual inventory cost of sales', 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        ['5500', 'Stock Write-off', null, 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        ['5510', 'Stock Adjustments', null, 'expense', 'debit', 'cost_of_sales', '5000', null, 1, 0, 0, 1, 'IS-COS'],
        // OPERATING EXPENSES
        ['6000', 'Operating Expenses', null, 'expense', 'debit', 'operating_expense', null, null, 1, 0, 0, 0, 'IS-OpEx'],
        ['6010', 'Salaries & Wages', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6020', 'Directors Remuneration', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6030', 'Bonuses & Commissions', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6040', 'Leave Pay', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6050', 'UIF — Employer', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6060', 'SDL — Employer', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6070', 'Pension / Provident — Employer', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6080', 'Medical Aid — Employer', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6085', 'WCA / COIDA Levy', null, 'expense', 'debit', 'payroll_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6090', 'Staff Welfare & Training', null, 'expense', 'debit', 'payroll_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6100', 'Rent — Premises', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6110', 'Rates & Municipal', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6120', 'Electricity & Water', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6130', 'Cleaning & Hygiene', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6140', 'Repairs & Maintenance', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6150', 'Security Services', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6200', 'Fuel & Oil', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6210', 'Motor Vehicle Repairs', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6220', 'Vehicle Licences', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6230', 'Vehicle Insurance', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6240', 'Vehicle Tracking', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6300', 'Stationery & Printing', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6310', 'Postage & Courier', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6320', 'Telephone & Internet', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6330', 'Software Subscriptions', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6340', 'Office Supplies', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6350', 'Subscriptions & Memberships', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6400', 'Advertising', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6410', 'Website & Digital Marketing', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6420', 'Promotions & Sponsorships', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6430', 'Entertainment — Non-deductible', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6500', 'Accounting & Bookkeeping', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6510', 'Audit Fees', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6520', 'Legal Fees', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6530', 'Consulting Fees', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6540', 'Secretarial & CIPC', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6600', 'Business Insurance', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6610', 'Public Liability Insurance', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6620', 'Asset Insurance', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6700', 'Travel — Local', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6710', 'Travel — International', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6720', 'Accommodation', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6730', 'Conferences & Seminars', null, 'expense', 'debit', 'operating_expense', '6000', 'INPUT', 1, 0, 0, 1, 'IS-OpEx'],
        ['6800', 'Bad Debts Written Off', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6810', 'Doubtful Debt Provision', null, 'expense', 'debit', 'operating_expense', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6900', 'Depreciation — Buildings', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6910', 'Depreciation — Plant & Machinery', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6920', 'Depreciation — Motor Vehicles', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6930', 'Depreciation — Office Equipment', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6940', 'Depreciation — Furniture', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6950', 'Depreciation — Computers', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        ['6960', 'Amortisation — Intangibles', null, 'expense', 'debit', 'depreciation', '6000', null, 1, 0, 0, 1, 'IS-OpEx'],
        // FINANCE COSTS & TAX
        ['8000', 'Finance Costs', null, 'expense', 'debit', 'finance_cost', null, null, 1, 0, 0, 0, 'IS-Fin'],
        ['8010', 'Interest Paid — Bank', null, 'expense', 'debit', 'finance_cost', '8000', null, 1, 0, 0, 1, 'IS-Fin'],
        ['8020', 'Interest Paid — Loans', null, 'expense', 'debit', 'finance_cost', '8000', null, 1, 0, 0, 1, 'IS-Fin'],
        ['8030', 'Interest Paid — SARS', null, 'expense', 'debit', 'finance_cost', '8000', null, 1, 0, 0, 1, 'IS-Fin'],
        ['8040', 'Bank Charges', null, 'expense', 'debit', 'finance_cost', '8000', null, 1, 0, 0, 1, 'IS-Fin'],
        ['8050', 'Forex Loss — Realised', null, 'expense', 'debit', 'forex_loss', '8000', null, 1, 0, 0, 1, 'IS-Fin'],
        ['8060', 'Loss on Disposal of Asset', null, 'expense', 'debit', 'finance_cost', '8000', null, 1, 0, 0, 1, 'IS-Fin'],
        ['9000', 'Taxation', null, 'expense', 'debit', 'tax_expense', null, null, 1, 0, 1, 0, 'IS-Tax'],
        ['9010', 'Income Tax — Current', null, 'expense', 'debit', 'tax_expense', '9000', null, 1, 0, 1, 0, 'IS-Tax'],
        ['9020', 'Income Tax — Deferred', null, 'expense', 'debit', 'tax_expense', '9000', null, 1, 0, 1, 0, 'IS-Tax'],
        ['9030', 'SARS Penalties', 'Non-deductible', 'expense', 'debit', 'tax_expense', '9000', null, 1, 0, 1, 0, 'IS-Tax'],
        ['9040', 'SARS Interest', 'Non-deductible', 'expense', 'debit', 'tax_expense', '9000', null, 1, 0, 1, 0, 'IS-Tax'],
    ];

    /** Codes in ACCOUNTS that the stored procedure does not seed. */
    public const EXTRA_CODES = ['5150'];

    /** Seed definition for an account code as an associative array, or null. */
    public static function definition(string $code): ?array
    {
        foreach (self::ACCOUNTS as $row) {
            if ($row[0] === $code) {
                return [
                    'account_code'         => $row[0],
                    'account_name'         => $row[1],
                    'description'          => $row[2],
                    'account_type'         => $row[3],
                    'normal_balance'       => $row[4],
                    'account_subtype'      => $row[5],
                    'parent_code'          => $row[6],
                    'tax_code'             => $row[7],
                    'is_system'            => $row[8],
                    'is_control'           => $row[9],
                    'is_locked'            => $row[10],
                    'allow_manual_journal' => $row[11],
                    'afs_line_code'        => $row[12],
                ];
            }
        }
        return null;
    }

    /**
     * Seed the full chart for a company. Existing (company_id, account_code)
     * rows are left untouched; parents are wired only on rows inserted here.
     * Tax codes must already exist (FinanceBootstrap::ensureTaxCodes) so the
     * default tax_code_id links resolve.
     *
     * @return int number of accounts inserted
     */
    public static function seedCompany(PDO $db, int $companyId): int
    {
        $existing = self::codeMap($db, $companyId);
        $taxIds = self::taxCodeMap($db, $companyId);
        $inserted = [];
        foreach (self::ACCOUNTS as $row) {
            if (isset($existing[$row[0]])) {
                continue;
            }
            $def = self::definition($row[0]);
            $id = self::insertAccount($db, $companyId, $def, $row[0], null, $taxIds);
            if ($id) {
                $inserted[$row[0]] = $id;
            }
        }

        // Wire parents once every seeded code exists.
        $codes = self::codeMap($db, $companyId);
        $stmt = $db->prepare("UPDATE gl_accounts SET parent_id = ? WHERE account_id = ? AND parent_id IS NULL");
        foreach ($inserted as $code => $id) {
            $parentCode = self::definition($code)['parent_code'];
            if ($parentCode !== null && isset($codes[$parentCode])) {
                $stmt->execute([$codes[$parentCode], $id]);
            }
        }
        return count($inserted);
    }

    /**
     * Insert one account from a seed definition under $code (which may differ
     * from the definition's own code when that code is already taken).
     * Returns the new account_id, or null when the code already exists.
     */
    public static function insertAccount(PDO $db, int $companyId, array $def, string $code, ?int $parentId = null, ?array $taxIds = null): ?int
    {
        $taxIds = $taxIds ?? self::taxCodeMap($db, $companyId);
        $taxCodeId = $def['tax_code'] !== null ? ($taxIds[strtoupper($def['tax_code'])] ?? null) : null;
        $stmt = $db->prepare(
            "INSERT IGNORE INTO gl_accounts
                (company_id, account_code, account_name, description, account_type,
                 normal_balance, account_subtype, parent_id, tax_code_id,
                 is_system, is_control, is_locked, allow_manual_journal,
                 afs_line_code, currency, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ZAR', 1, NOW(), NOW())"
        );
        $stmt->execute([
            $companyId, $code, $def['account_name'], $def['description'], $def['account_type'],
            $def['normal_balance'], $def['account_subtype'], $parentId, $taxCodeId,
            $def['is_system'], $def['is_control'], $def['is_locked'], $def['allow_manual_journal'],
            $def['afs_line_code'],
        ]);
        return $stmt->rowCount() > 0 ? (int)$db->lastInsertId() : null;
    }

    /** account_code => account_id for a company. */
    private static function codeMap(PDO $db, int $companyId): array
    {
        $stmt = $db->prepare("SELECT account_code, account_id FROM gl_accounts WHERE company_id = ?");
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$code, $id]) {
            $map[(string)$code] = (int)$id;
        }
        return $map;
    }

    /** UPPER(code) => tax_code_id of a company's active tax codes. */
    private static function taxCodeMap(PDO $db, int $companyId): array
    {
        $stmt = $db->prepare("SELECT code, tax_code_id FROM gl_tax_codes WHERE company_id = ? AND is_active = 1");
        $stmt->execute([$companyId]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$code, $id]) {
            $map[strtoupper((string)$code)] = $map[strtoupper((string)$code)] ?? (int)$id;
        }
        return $map;
    }
}
