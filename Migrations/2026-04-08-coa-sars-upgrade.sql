-- /Migrations/2026-04-08-coa-sars-upgrade.sql
-- SARS bookkeeping-grade Chart of Accounts upgrade.
-- Idempotent: safe to run multiple times.

-- ============================================================================
-- 1. Extend gl_accounts schema
-- ============================================================================

-- Helper: add column only if missing (safe for re-runs)
DROP PROCEDURE IF EXISTS _add_col_if_missing;
DELIMITER $$
CREATE PROCEDURE _add_col_if_missing(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ddl    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name   = p_table
          AND column_name  = p_column
    ) THEN
        SET @sql := CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE s FROM @sql;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;
END$$
DELIMITER ;

CALL _add_col_if_missing('gl_accounts','normal_balance',
  "`normal_balance` ENUM('debit','credit') NOT NULL DEFAULT 'debit' AFTER `account_type`");
CALL _add_col_if_missing('gl_accounts','account_subtype',
  "`account_subtype` VARCHAR(40) NOT NULL DEFAULT '' AFTER `normal_balance`");
CALL _add_col_if_missing('gl_accounts','description',
  "`description` VARCHAR(500) NULL AFTER `account_name`");
CALL _add_col_if_missing('gl_accounts','is_control',
  "`is_control` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_system`");
CALL _add_col_if_missing('gl_accounts','is_locked',
  "`is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_control`");
CALL _add_col_if_missing('gl_accounts','allow_manual_journal',
  "`allow_manual_journal` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_locked`");
CALL _add_col_if_missing('gl_accounts','afs_line_code',
  "`afs_line_code` VARCHAR(20) NULL AFTER `allow_manual_journal`");
CALL _add_col_if_missing('gl_accounts','currency',
  "`currency` CHAR(3) NOT NULL DEFAULT 'ZAR' AFTER `afs_line_code`");
CALL _add_col_if_missing('gl_accounts','created_by',
  "`created_by` INT UNSIGNED NULL");
CALL _add_col_if_missing('gl_accounts','updated_by',
  "`updated_by` INT UNSIGNED NULL");

DROP PROCEDURE IF EXISTS _add_col_if_missing;

-- Helper: add index only if missing
DROP PROCEDURE IF EXISTS _add_idx_if_missing;
DELIMITER $$
CREATE PROCEDURE _add_idx_if_missing(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_ddl   TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name   = p_table
          AND index_name   = p_index
    ) THEN
        SET @sql := CONCAT('ALTER TABLE `', p_table, '` ADD KEY `', p_index, '` ', p_ddl);
        PREPARE s FROM @sql;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;
END$$
DELIMITER ;

CALL _add_idx_if_missing('gl_accounts','idx_company_subtype',
  '(`company_id`, `account_type`, `account_subtype`)');
CALL _add_idx_if_missing('gl_accounts','idx_company_active_locked',
  '(`company_id`, `is_active`, `is_locked`)');
CALL _add_idx_if_missing('gl_accounts','idx_afs_line',
  '(`company_id`, `afs_line_code`)');

DROP PROCEDURE IF EXISTS _add_idx_if_missing;

-- ============================================================================
-- 2. Back-fill normal_balance for pre-existing rows
-- ============================================================================
UPDATE `gl_accounts`
   SET `normal_balance` = 'credit'
 WHERE `account_type` IN ('liability','equity','revenue')
   AND `normal_balance` = 'debit';

-- ============================================================================
-- 3. SARS Chart of Accounts seed procedure (~108 accounts)
-- ============================================================================
DROP PROCEDURE IF EXISTS `seed_sars_chart_of_accounts`;
DELIMITER $$
CREATE PROCEDURE `seed_sars_chart_of_accounts`(IN p_company_id INT)
BEGIN
  DROP TEMPORARY TABLE IF EXISTS `_sars_coa`;
  CREATE TEMPORARY TABLE `_sars_coa` (
    `account_code`         VARCHAR(20)  NOT NULL,
    `account_name`         VARCHAR(255) NOT NULL,
    `description`          VARCHAR(500) NULL,
    `account_type`         ENUM('asset','liability','equity','revenue','expense') NOT NULL,
    `normal_balance`       ENUM('debit','credit') NOT NULL,
    `account_subtype`      VARCHAR(40)  NOT NULL DEFAULT '',
    `parent_code`          VARCHAR(20)  NULL,
    `tax_code`             VARCHAR(50)  NULL,
    `is_system`            TINYINT(1)   NOT NULL DEFAULT 1,
    `is_control`           TINYINT(1)   NOT NULL DEFAULT 0,
    `is_locked`            TINYINT(1)   NOT NULL DEFAULT 0,
    `allow_manual_journal` TINYINT(1)   NOT NULL DEFAULT 1,
    `afs_line_code`        VARCHAR(20)  NULL,
    PRIMARY KEY (`account_code`)
  ) ENGINE=MEMORY;

  -- =========================== ASSETS ======================================
  INSERT INTO `_sars_coa` VALUES
  ('1000','Current Assets',NULL,'asset','debit','current_asset',NULL,NULL,1,0,0,0,'BS-CA'),
  ('1500','Non-Current Assets',NULL,'asset','debit','non_current_asset',NULL,NULL,1,0,0,0,'BS-NCA'),
  ('1010','Bank Control','Aggregated bank control','asset','debit','bank','1000',NULL,1,1,1,0,'BS-CA-Cash'),
  ('1020','Primary Bank Account',NULL,'asset','debit','bank','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1030','Secondary Bank Account',NULL,'asset','debit','bank','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1040','Foreign Currency Account',NULL,'asset','debit','bank','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1050','Credit Card Clearing',NULL,'asset','debit','bank','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1060','Petty Cash',NULL,'asset','debit','cash','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1070','Cash Float',NULL,'asset','debit','cash','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1080','Cash on Deposit',NULL,'asset','debit','cash','1000',NULL,1,0,0,1,'BS-CA-Cash'),
  ('1100','Accounts Receivable Control','Trade debtors control','asset','debit','accounts_receivable','1000',NULL,1,1,1,0,'BS-CA-Trade'),
  ('1110','Allowance for Doubtful Debts','Contra-asset','asset','credit','accounts_receivable','1000',NULL,1,0,0,1,'BS-CA-Trade'),
  ('1120','Sundry Debtors',NULL,'asset','debit','current_asset','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1130','Staff Loans & Advances',NULL,'asset','debit','current_asset','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1140','Director Loan Account — Debit',NULL,'asset','debit','current_asset','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1200','Stock on Hand',NULL,'asset','debit','inventory','1000',NULL,1,0,0,1,'BS-CA-Inv'),
  ('1210','Stock in Transit',NULL,'asset','debit','inventory','1000',NULL,1,0,0,1,'BS-CA-Inv'),
  ('1220','Work in Progress',NULL,'asset','debit','inventory','1000',NULL,1,0,0,1,'BS-CA-Inv'),
  ('1230','Raw Materials',NULL,'asset','debit','inventory','1000',NULL,1,0,0,1,'BS-CA-Inv'),
  ('1240','Finished Goods',NULL,'asset','debit','inventory','1000',NULL,1,0,0,1,'BS-CA-Inv'),
  ('1300','Prepaid Expenses',NULL,'asset','debit','prepayment','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1310','Deposits Paid',NULL,'asset','debit','prepayment','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1320','VAT Input Pending',NULL,'asset','debit','current_asset','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1330','SARS Refund Receivable',NULL,'asset','debit','current_asset','1000',NULL,1,0,0,1,'BS-CA-Other'),
  ('1510','Land & Buildings — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1520','Plant & Machinery — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1530','Motor Vehicles — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1540','Office Equipment — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1550','Furniture & Fittings — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1560','Computer Equipment — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1570','Leasehold Improvements — Cost',NULL,'asset','debit','fixed_asset','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1610','Accum. Dep. — Buildings',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1620','Accum. Dep. — Plant & Machinery',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1630','Accum. Dep. — Motor Vehicles',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1640','Accum. Dep. — Office Equipment',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1650','Accum. Dep. — Furniture',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1660','Accum. Dep. — Computer Equipment',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1670','Accum. Dep. — Leasehold',NULL,'asset','credit','accumulated_depreciation','1500',NULL,1,0,0,1,'BS-NCA-PPE'),
  ('1710','Goodwill',NULL,'asset','debit','intangible_asset','1500',NULL,1,0,0,1,'BS-NCA-Intang'),
  ('1720','Software & Licenses — Cost',NULL,'asset','debit','intangible_asset','1500',NULL,1,0,0,1,'BS-NCA-Intang'),
  ('1730','Accum. Amortisation — Software',NULL,'asset','credit','intangible_asset','1500',NULL,1,0,0,1,'BS-NCA-Intang'),
  ('1740','Long-term Investments',NULL,'asset','debit','investment','1500',NULL,1,0,0,1,'BS-NCA-Inv'),
  ('1750','Loans Receivable — Long-term',NULL,'asset','debit','non_current_asset','1500',NULL,1,0,0,1,'BS-NCA-Other');

  -- =========================== LIABILITIES =================================
  INSERT INTO `_sars_coa` VALUES
  ('2000','Current Liabilities',NULL,'liability','credit','current_liability',NULL,NULL,1,0,0,0,'BS-CL'),
  ('2500','Non-Current Liabilities',NULL,'liability','credit','non_current_liability',NULL,NULL,1,0,0,0,'BS-NCL'),
  ('2010','Accounts Payable Control','Trade creditors','liability','credit','accounts_payable','2000',NULL,1,1,1,0,'BS-CL-Trade'),
  ('2020','Sundry Creditors',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Other'),
  ('2030','Accrued Expenses',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Other'),
  ('2040','Customer Deposits Received',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Other'),
  ('2050','Director Loan — Credit',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Other'),
  ('2060','Shareholders Loan — Current',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Other'),
  ('2100','VAT Control','Net VAT payable to SARS','liability','credit','vat_control','2000',NULL,1,1,1,0,'BS-CL-Tax'),
  ('2110','VAT Output','Output VAT on sales','liability','credit','vat_control','2000','STD',1,1,1,0,'BS-CL-Tax'),
  ('2120','VAT Input','Input VAT on purchases','liability','debit','vat_control','2000','INPUT',1,1,1,0,'BS-CL-Tax'),
  ('2130','PAYE Payable','Employees tax — SARS','liability','credit','paye_liability','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2140','UIF Payable','UIF contributions','liability','credit','uif_liability','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2150','SDL Payable','Skills Development Levy','liability','credit','sdl_liability','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2160','Income Tax Payable','Corporate tax owing','liability','credit','income_tax_payable','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2170','Provisional Tax Paid','IRP6 payments','liability','debit','provisional_tax','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2180','Dividends Tax Withheld','DWT on declared dividends','liability','credit','dividends_tax_payable','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2190','WCA / COIDA Payable','Workers compensation','liability','credit','current_liability','2000',NULL,1,0,1,0,'BS-CL-Tax'),
  ('2210','Salaries & Wages Payable',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Payroll'),
  ('2220','Pension / Provident Payable',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Payroll'),
  ('2230','Medical Aid Payable',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Payroll'),
  ('2240','Garnishee Orders Payable',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Payroll'),
  ('2250','Union Dues Payable',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Payroll'),
  ('2300','Bank Overdraft',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Borrow'),
  ('2310','Short-term Loan',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Borrow'),
  ('2320','Current Portion of Long-term Loan',NULL,'liability','credit','current_liability','2000',NULL,1,0,0,1,'BS-CL-Borrow'),
  ('2510','Long-term Loan',NULL,'liability','credit','loan','2500',NULL,1,0,0,1,'BS-NCL-Loan'),
  ('2520','Mortgage Bond',NULL,'liability','credit','loan','2500',NULL,1,0,0,1,'BS-NCL-Loan'),
  ('2530','Instalment Sale Liability',NULL,'liability','credit','loan','2500',NULL,1,0,0,1,'BS-NCL-Loan'),
  ('2540','Shareholders Loan — Long-term',NULL,'liability','credit','non_current_liability','2500',NULL,1,0,0,1,'BS-NCL-Other'),
  ('2550','Deferred Tax Liability',NULL,'liability','credit','non_current_liability','2500',NULL,1,0,1,0,'BS-NCL-Tax');

  -- =========================== EQUITY ======================================
  INSERT INTO `_sars_coa` VALUES
  ('3000','Equity',NULL,'equity','credit','share_capital',NULL,NULL,1,0,0,0,'BS-EQ'),
  ('3010','Share Capital',NULL,'equity','credit','share_capital','3000',NULL,1,0,1,0,'BS-EQ-Cap'),
  ('3020','Share Premium',NULL,'equity','credit','share_capital','3000',NULL,1,0,1,0,'BS-EQ-Cap'),
  ('3030','Members Contributions','CC / partnerships','equity','credit','share_capital','3000',NULL,1,0,0,1,'BS-EQ-Cap'),
  ('3100','Retained Earnings',NULL,'equity','credit','retained_earnings','3000',NULL,1,0,1,0,'BS-EQ-RE'),
  ('3110','Current Year Earnings','Auto P&L close','equity','credit','current_year_earnings','3000',NULL,1,0,1,0,'BS-EQ-RE'),
  ('3200','Dividends Declared',NULL,'equity','debit','retained_earnings','3000',NULL,1,0,0,1,'BS-EQ-Div'),
  ('3300','Owners Drawings',NULL,'equity','debit','drawings','3000',NULL,1,0,0,1,'BS-EQ-Draw'),
  ('3400','Revaluation Reserve',NULL,'equity','credit','share_capital','3000',NULL,1,0,0,1,'BS-EQ-Res');

  -- =========================== REVENUE =====================================
  INSERT INTO `_sars_coa` VALUES
  ('4000','Operating Revenue',NULL,'revenue','credit','operating_revenue',NULL,NULL,1,0,0,0,'IS-Rev'),
  ('4010','Sales — Standard Rated','15% VAT','revenue','credit','operating_revenue','4000','STD',1,0,0,1,'IS-Rev'),
  ('4020','Sales — Zero Rated','Exports, basic foods','revenue','credit','operating_revenue','4000','ZERO',1,0,0,1,'IS-Rev'),
  ('4030','Sales — Exempt',NULL,'revenue','credit','operating_revenue','4000','EXEMPT',1,0,0,1,'IS-Rev'),
  ('4040','Sales — Export',NULL,'revenue','credit','operating_revenue','4000','ZERO',1,0,0,1,'IS-Rev'),
  ('4100','Service Income',NULL,'revenue','credit','operating_revenue','4000','STD',1,0,0,1,'IS-Rev'),
  ('4110','Consulting Fees',NULL,'revenue','credit','operating_revenue','4000','STD',1,0,0,1,'IS-Rev'),
  ('4200','Sales Returns & Allowances','Contra','revenue','debit','operating_revenue','4000',NULL,1,0,0,1,'IS-Rev'),
  ('4210','Discounts Allowed','Contra','revenue','debit','operating_revenue','4000',NULL,1,0,0,1,'IS-Rev'),
  ('4900','Other Income',NULL,'revenue','credit','other_income',NULL,NULL,1,0,0,0,'IS-OtherInc'),
  ('4910','Interest Received',NULL,'revenue','credit','interest_income','4900',NULL,1,0,0,1,'IS-OtherInc'),
  ('4920','Dividend Received',NULL,'revenue','credit','dividend_income','4900',NULL,1,0,0,1,'IS-OtherInc'),
  ('4930','Rental Income',NULL,'revenue','credit','other_income','4900','STD',1,0,0,1,'IS-OtherInc'),
  ('4940','Commission Earned',NULL,'revenue','credit','other_income','4900','STD',1,0,0,1,'IS-OtherInc'),
  ('4950','Forex Gain — Realised',NULL,'revenue','credit','forex_gain','4900',NULL,1,0,0,1,'IS-OtherInc'),
  ('4960','Profit on Disposal of Asset',NULL,'revenue','credit','other_income','4900',NULL,1,0,0,1,'IS-OtherInc'),
  ('4970','Sundry Income',NULL,'revenue','credit','other_income','4900',NULL,1,0,0,1,'IS-OtherInc');

  -- =========================== COST OF SALES ===============================
  INSERT INTO `_sars_coa` VALUES
  ('5000','Cost of Sales',NULL,'expense','debit','cost_of_sales',NULL,NULL,1,0,0,0,'IS-COS'),
  ('5010','Opening Stock',NULL,'expense','debit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS'),
  ('5020','Purchases',NULL,'expense','debit','cost_of_sales','5000','INPUT',1,0,0,1,'IS-COS'),
  ('5030','Carriage Inwards',NULL,'expense','debit','cost_of_sales','5000','INPUT',1,0,0,1,'IS-COS'),
  ('5040','Customs Duty & Clearing',NULL,'expense','debit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS'),
  ('5050','Direct Labour',NULL,'expense','debit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS'),
  ('5060','Subcontractors',NULL,'expense','debit','cost_of_sales','5000','INPUT',1,0,0,1,'IS-COS'),
  ('5090','Closing Stock','Contra — reduces COS','expense','credit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS'),
  ('5100','Cost of Services Rendered',NULL,'expense','debit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS'),
  ('5500','Stock Write-off',NULL,'expense','debit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS'),
  ('5510','Stock Adjustments',NULL,'expense','debit','cost_of_sales','5000',NULL,1,0,0,1,'IS-COS');

  -- =========================== OPERATING EXPENSES ==========================
  INSERT INTO `_sars_coa` VALUES
  ('6000','Operating Expenses',NULL,'expense','debit','operating_expense',NULL,NULL,1,0,0,0,'IS-OpEx'),
  ('6010','Salaries & Wages',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6020','Directors Remuneration',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6030','Bonuses & Commissions',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6040','Leave Pay',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6050','UIF — Employer',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6060','SDL — Employer',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6070','Pension / Provident — Employer',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6080','Medical Aid — Employer',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6085','WCA / COIDA Levy',NULL,'expense','debit','payroll_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6090','Staff Welfare & Training',NULL,'expense','debit','payroll_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6100','Rent — Premises',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6110','Rates & Municipal',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6120','Electricity & Water',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6130','Cleaning & Hygiene',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6140','Repairs & Maintenance',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6150','Security Services',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6200','Fuel & Oil',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6210','Motor Vehicle Repairs',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6220','Vehicle Licences',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6230','Vehicle Insurance',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6240','Vehicle Tracking',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6300','Stationery & Printing',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6310','Postage & Courier',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6320','Telephone & Internet',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6330','Software Subscriptions',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6340','Office Supplies',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6350','Subscriptions & Memberships',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6400','Advertising',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6410','Website & Digital Marketing',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6420','Promotions & Sponsorships',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6430','Entertainment — Non-deductible',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6500','Accounting & Bookkeeping',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6510','Audit Fees',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6520','Legal Fees',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6530','Consulting Fees',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6540','Secretarial & CIPC',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6600','Business Insurance',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6610','Public Liability Insurance',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6620','Asset Insurance',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6700','Travel — Local',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6710','Travel — International',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6720','Accommodation',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6730','Conferences & Seminars',NULL,'expense','debit','operating_expense','6000','INPUT',1,0,0,1,'IS-OpEx'),
  ('6800','Bad Debts Written Off',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6810','Doubtful Debt Provision',NULL,'expense','debit','operating_expense','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6900','Depreciation — Buildings',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6910','Depreciation — Plant & Machinery',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6920','Depreciation — Motor Vehicles',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6930','Depreciation — Office Equipment',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6940','Depreciation — Furniture',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6950','Depreciation — Computers',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx'),
  ('6960','Amortisation — Intangibles',NULL,'expense','debit','depreciation','6000',NULL,1,0,0,1,'IS-OpEx');

  -- =========================== FINANCE COSTS & TAX =========================
  INSERT INTO `_sars_coa` VALUES
  ('8000','Finance Costs',NULL,'expense','debit','finance_cost',NULL,NULL,1,0,0,0,'IS-Fin'),
  ('8010','Interest Paid — Bank',NULL,'expense','debit','finance_cost','8000',NULL,1,0,0,1,'IS-Fin'),
  ('8020','Interest Paid — Loans',NULL,'expense','debit','finance_cost','8000',NULL,1,0,0,1,'IS-Fin'),
  ('8030','Interest Paid — SARS',NULL,'expense','debit','finance_cost','8000',NULL,1,0,0,1,'IS-Fin'),
  ('8040','Bank Charges',NULL,'expense','debit','finance_cost','8000',NULL,1,0,0,1,'IS-Fin'),
  ('8050','Forex Loss — Realised',NULL,'expense','debit','forex_loss','8000',NULL,1,0,0,1,'IS-Fin'),
  ('8060','Loss on Disposal of Asset',NULL,'expense','debit','finance_cost','8000',NULL,1,0,0,1,'IS-Fin'),
  ('9000','Taxation',NULL,'expense','debit','tax_expense',NULL,NULL,1,0,1,0,'IS-Tax'),
  ('9010','Income Tax — Current',NULL,'expense','debit','tax_expense','9000',NULL,1,0,1,0,'IS-Tax'),
  ('9020','Income Tax — Deferred',NULL,'expense','debit','tax_expense','9000',NULL,1,0,1,0,'IS-Tax'),
  ('9030','SARS Penalties','Non-deductible','expense','debit','tax_expense','9000',NULL,1,0,1,0,'IS-Tax'),
  ('9040','SARS Interest','Non-deductible','expense','debit','tax_expense','9000',NULL,1,0,1,0,'IS-Tax');

  -- =================== INSERT INTO gl_accounts =============================
  -- Pass 1: insert all rows (parent_id NULL initially)
  INSERT IGNORE INTO `gl_accounts` (
    company_id, account_code, account_name, description,
    account_type, normal_balance, account_subtype,
    parent_id, tax_code_id,
    is_system, is_control, is_locked, allow_manual_journal,
    afs_line_code, currency, is_active, created_at, updated_at
  )
  SELECT
    p_company_id,
    s.account_code, s.account_name, s.description,
    s.account_type, s.normal_balance, s.account_subtype,
    NULL,
    (SELECT tc.tax_code_id FROM gl_tax_codes tc
       WHERE tc.company_id = p_company_id AND tc.code = s.tax_code LIMIT 1),
    s.is_system, s.is_control, s.is_locked, s.allow_manual_journal,
    s.afs_line_code, 'ZAR', 1, NOW(), NOW()
  FROM `_sars_coa` s;

  -- Pass 2: wire up parent_id
  UPDATE `gl_accounts` a
    JOIN `_sars_coa` s ON s.account_code = a.account_code AND s.parent_code IS NOT NULL
    JOIN `gl_accounts` p ON p.company_id = p_company_id AND p.account_code = s.parent_code
     SET a.parent_id = p.account_id
   WHERE a.company_id = p_company_id
     AND a.parent_id IS NULL;

  DROP TEMPORARY TABLE IF EXISTS `_sars_coa`;
END$$
DELIMITER ;

-- ============================================================================
-- 4. Back-fill metadata on legacy (old 41-account seed) rows
-- ============================================================================
UPDATE gl_accounts SET normal_balance='credit'
 WHERE account_type IN ('liability','equity','revenue') AND normal_balance='debit';

UPDATE gl_accounts SET account_subtype='accounts_receivable', is_control=1, is_locked=1, allow_manual_journal=0, afs_line_code='BS-CA-Trade'
 WHERE account_subtype='' AND account_name LIKE '%Receivable%' AND account_type='asset';

UPDATE gl_accounts SET account_subtype='accounts_payable', is_control=1, is_locked=1, allow_manual_journal=0, afs_line_code='BS-CL-Trade'
 WHERE account_subtype='' AND account_name LIKE '%Accounts Payable%';

UPDATE gl_accounts SET account_subtype='vat_control', is_locked=1, is_control=1, allow_manual_journal=0, afs_line_code='BS-CL-Tax'
 WHERE account_subtype='' AND account_name LIKE '%VAT%';

UPDATE gl_accounts SET account_subtype='paye_liability', is_locked=1, afs_line_code='BS-CL-Tax'
 WHERE account_subtype='' AND account_name LIKE '%PAYE%';

UPDATE gl_accounts SET account_subtype='uif_liability', is_locked=1, afs_line_code='BS-CL-Tax'
 WHERE account_subtype='' AND account_name LIKE '%UIF%';

UPDATE gl_accounts SET account_subtype='sdl_liability', is_locked=1, afs_line_code='BS-CL-Tax'
 WHERE account_subtype='' AND account_name LIKE '%SDL%';

UPDATE gl_accounts SET account_subtype='retained_earnings', is_locked=1, afs_line_code='BS-EQ-RE'
 WHERE account_subtype='' AND account_name LIKE '%Retained Earnings%';

-- Generic fallbacks for still-empty subtypes
UPDATE gl_accounts SET account_subtype='current_asset',     afs_line_code='BS-CA'      WHERE account_subtype='' AND account_type='asset'     AND account_code < '1500';
UPDATE gl_accounts SET account_subtype='fixed_asset',       afs_line_code='BS-NCA-PPE' WHERE account_subtype='' AND account_type='asset'     AND account_code >= '1500';
UPDATE gl_accounts SET account_subtype='current_liability', afs_line_code='BS-CL'      WHERE account_subtype='' AND account_type='liability' AND account_code < '2500';
UPDATE gl_accounts SET account_subtype='non_current_liability', afs_line_code='BS-NCL' WHERE account_subtype='' AND account_type='liability' AND account_code >= '2500';
UPDATE gl_accounts SET account_subtype='share_capital',     afs_line_code='BS-EQ'      WHERE account_subtype='' AND account_type='equity';
UPDATE gl_accounts SET account_subtype='operating_revenue', afs_line_code='IS-Rev'     WHERE account_subtype='' AND account_type='revenue'   AND account_code < '4900';
UPDATE gl_accounts SET account_subtype='other_income',      afs_line_code='IS-OtherInc' WHERE account_subtype='' AND account_type='revenue'  AND account_code >= '4900';
UPDATE gl_accounts SET account_subtype='cost_of_sales',     afs_line_code='IS-COS'     WHERE account_subtype='' AND account_type='expense'   AND account_code < '6000';
UPDATE gl_accounts SET account_subtype='operating_expense', afs_line_code='IS-OpEx'    WHERE account_subtype='' AND account_type='expense'   AND account_code BETWEEN '6000' AND '7999';
UPDATE gl_accounts SET account_subtype='finance_cost',      afs_line_code='IS-Fin'     WHERE account_subtype='' AND account_type='expense'   AND account_code BETWEEN '8000' AND '8999';
UPDATE gl_accounts SET account_subtype='tax_expense',       afs_line_code='IS-Tax'     WHERE account_subtype='' AND account_type='expense'   AND account_code >= '9000';
