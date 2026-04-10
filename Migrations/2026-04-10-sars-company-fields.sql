-- Add SARS-required registration fields to companies table
-- tax_reference: SARS Income Tax Reference Number
-- These fields appear on tax invoices and VAT201 returns per SARS requirements

ALTER TABLE `companies`
  ADD COLUMN IF NOT EXISTS `tax_reference` VARCHAR(50) DEFAULT NULL AFTER `reg_number`;
