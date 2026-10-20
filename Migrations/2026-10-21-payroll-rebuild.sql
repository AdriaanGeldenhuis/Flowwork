-- Payroll rebuild (2026-10-21): new columns and the official SARS tax tables.
-- Mirrors payroll/lib/PaySchema.php, which applies the same changes on first
-- use, so running this file by hand is optional. Safe to run more than once.
--
-- Sources for the tax tables (sars.gov.za):
--   Rates of tax for individuals  — brackets and rebates for the 2025, 2026 and 2027 tax years
--   Medical tax credit rates      — R364/R246 (2025, 2026), R376/R254 (2027) a month
--   UIF ceiling                   — R17 712 a month (R212 544 a year) since 1 June 2021
-- The earlier rows held placeholder figures (wrong brackets and rebates, and a
-- UIF cap of R177 122 a year instead of R212 544), so PAYE and UIF were wrong.

ALTER TABLE `employees`
  ADD COLUMN IF NOT EXISTS `job_title` varchar(120) DEFAULT NULL AFTER `last_name`,
  ADD COLUMN IF NOT EXISTS `birth_date` date DEFAULT NULL AFTER `id_number`,
  ADD COLUMN IF NOT EXISTS `medical_members` tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER `sdl_included`,
  ADD COLUMN IF NOT EXISTS `bank_account_type` varchar(20) DEFAULT NULL AFTER `bank_account_no`;

ALTER TABLE `payitems`
  ADD COLUMN IF NOT EXISTS `pre_tax` tinyint(1) NOT NULL DEFAULT 0 AFTER `sdl_subject`,
  ADD COLUMN IF NOT EXISTS `irregular` tinyint(1) NOT NULL DEFAULT 0 AFTER `pre_tax`;

ALTER TABLE `payroll_settings`
  ADD COLUMN IF NOT EXISTS `sdl_liable` tinyint(1) NOT NULL DEFAULT 1 AFTER `auto_post_to_finance`,
  ADD COLUMN IF NOT EXISTS `hours_per_week` decimal(4,1) NOT NULL DEFAULT 45.0 AFTER `sdl_liable`,
  ADD COLUMN IF NOT EXISTS `paye_reference` varchar(20) DEFAULT NULL AFTER `hours_per_week`;

-- 2025 tax year (2024-03-01 – 2025-02-28)
UPDATE `tax_tables_za` SET `effective_to` = '2025-02-28', `bracket_json` = '[{"threshold_cents":0,"rate_bp":1800,"cumulative_cents":0},{"threshold_cents":23710000,"rate_bp":2600,"cumulative_cents":4267800},{"threshold_cents":37050000,"rate_bp":3100,"cumulative_cents":7736200},{"threshold_cents":51280000,"rate_bp":3600,"cumulative_cents":12147500},{"threshold_cents":67300000,"rate_bp":3900,"cumulative_cents":17914700},{"threshold_cents":85790000,"rate_bp":4100,"cumulative_cents":25125800},{"threshold_cents":181700000,"rate_bp":4500,"cumulative_cents":64448900}]',
    `rebate_primary_cents` = 1723500, `rebate_secondary_cents` = 944400, `rebate_tertiary_cents` = 314500,
    `uif_cap_annual_cents` = 21254400, `medical_credit_main_cents` = 36400, `medical_credit_dep_cents` = 24600
  WHERE `company_id` IS NULL AND `effective_from` = '2024-03-01';
INSERT INTO `tax_tables_za` (`company_id`, `effective_from`, `effective_to`, `bracket_json`, `rebate_primary_cents`, `rebate_secondary_cents`, `rebate_tertiary_cents`, `uif_cap_annual_cents`, `medical_credit_main_cents`, `medical_credit_dep_cents`)
  SELECT NULL, '2024-03-01', '2025-02-28', '[{"threshold_cents":0,"rate_bp":1800,"cumulative_cents":0},{"threshold_cents":23710000,"rate_bp":2600,"cumulative_cents":4267800},{"threshold_cents":37050000,"rate_bp":3100,"cumulative_cents":7736200},{"threshold_cents":51280000,"rate_bp":3600,"cumulative_cents":12147500},{"threshold_cents":67300000,"rate_bp":3900,"cumulative_cents":17914700},{"threshold_cents":85790000,"rate_bp":4100,"cumulative_cents":25125800},{"threshold_cents":181700000,"rate_bp":4500,"cumulative_cents":64448900}]', 1723500, 944400, 314500, 21254400, 36400, 24600 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `tax_tables_za` WHERE `company_id` IS NULL AND `effective_from` = '2024-03-01');

-- 2026 tax year (2025-03-01 – 2026-02-28)
UPDATE `tax_tables_za` SET `effective_to` = '2026-02-28', `bracket_json` = '[{"threshold_cents":0,"rate_bp":1800,"cumulative_cents":0},{"threshold_cents":23710000,"rate_bp":2600,"cumulative_cents":4267800},{"threshold_cents":37050000,"rate_bp":3100,"cumulative_cents":7736200},{"threshold_cents":51280000,"rate_bp":3600,"cumulative_cents":12147500},{"threshold_cents":67300000,"rate_bp":3900,"cumulative_cents":17914700},{"threshold_cents":85790000,"rate_bp":4100,"cumulative_cents":25125800},{"threshold_cents":181700000,"rate_bp":4500,"cumulative_cents":64448900}]',
    `rebate_primary_cents` = 1723500, `rebate_secondary_cents` = 944400, `rebate_tertiary_cents` = 314500,
    `uif_cap_annual_cents` = 21254400, `medical_credit_main_cents` = 36400, `medical_credit_dep_cents` = 24600
  WHERE `company_id` IS NULL AND `effective_from` = '2025-03-01';
INSERT INTO `tax_tables_za` (`company_id`, `effective_from`, `effective_to`, `bracket_json`, `rebate_primary_cents`, `rebate_secondary_cents`, `rebate_tertiary_cents`, `uif_cap_annual_cents`, `medical_credit_main_cents`, `medical_credit_dep_cents`)
  SELECT NULL, '2025-03-01', '2026-02-28', '[{"threshold_cents":0,"rate_bp":1800,"cumulative_cents":0},{"threshold_cents":23710000,"rate_bp":2600,"cumulative_cents":4267800},{"threshold_cents":37050000,"rate_bp":3100,"cumulative_cents":7736200},{"threshold_cents":51280000,"rate_bp":3600,"cumulative_cents":12147500},{"threshold_cents":67300000,"rate_bp":3900,"cumulative_cents":17914700},{"threshold_cents":85790000,"rate_bp":4100,"cumulative_cents":25125800},{"threshold_cents":181700000,"rate_bp":4500,"cumulative_cents":64448900}]', 1723500, 944400, 314500, 21254400, 36400, 24600 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `tax_tables_za` WHERE `company_id` IS NULL AND `effective_from` = '2025-03-01');

-- 2027 tax year (2026-03-01 – 2027-02-28)
UPDATE `tax_tables_za` SET `effective_to` = '2027-02-28', `bracket_json` = '[{"threshold_cents":0,"rate_bp":1800,"cumulative_cents":0},{"threshold_cents":24510000,"rate_bp":2600,"cumulative_cents":4411800},{"threshold_cents":38310000,"rate_bp":3100,"cumulative_cents":7999800},{"threshold_cents":53020000,"rate_bp":3600,"cumulative_cents":12559900},{"threshold_cents":69580000,"rate_bp":3900,"cumulative_cents":18521500},{"threshold_cents":88700000,"rate_bp":4100,"cumulative_cents":25978300},{"threshold_cents":187860000,"rate_bp":4500,"cumulative_cents":66633900}]',
    `rebate_primary_cents` = 1782000, `rebate_secondary_cents` = 976500, `rebate_tertiary_cents` = 324900,
    `uif_cap_annual_cents` = 21254400, `medical_credit_main_cents` = 37600, `medical_credit_dep_cents` = 25400
  WHERE `company_id` IS NULL AND `effective_from` = '2026-03-01';
INSERT INTO `tax_tables_za` (`company_id`, `effective_from`, `effective_to`, `bracket_json`, `rebate_primary_cents`, `rebate_secondary_cents`, `rebate_tertiary_cents`, `uif_cap_annual_cents`, `medical_credit_main_cents`, `medical_credit_dep_cents`)
  SELECT NULL, '2026-03-01', '2027-02-28', '[{"threshold_cents":0,"rate_bp":1800,"cumulative_cents":0},{"threshold_cents":24510000,"rate_bp":2600,"cumulative_cents":4411800},{"threshold_cents":38310000,"rate_bp":3100,"cumulative_cents":7999800},{"threshold_cents":53020000,"rate_bp":3600,"cumulative_cents":12559900},{"threshold_cents":69580000,"rate_bp":3900,"cumulative_cents":18521500},{"threshold_cents":88700000,"rate_bp":4100,"cumulative_cents":25978300},{"threshold_cents":187860000,"rate_bp":4500,"cumulative_cents":66633900}]', 1782000, 976500, 324900, 21254400, 37600, 25400 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM `tax_tables_za` WHERE `company_id` IS NULL AND `effective_from` = '2026-03-01');

