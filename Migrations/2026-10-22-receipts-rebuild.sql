-- Receipts rebuild (2026-10-22): new columns, one reading per receipt, and
-- the readings the old engine made up are cleared.
-- Mirrors receipts/lib/RcSchema.php, which applies the same changes on first
-- use, so running this file by hand is optional. Safe to run more than once.

ALTER TABLE `receipt_file`
  ADD COLUMN IF NOT EXISTS `original_name` varchar(255) DEFAULT NULL AFTER `path`,
  ADD COLUMN IF NOT EXISTS `sha1` char(40) DEFAULT NULL AFTER `size_bytes`,
  ADD COLUMN IF NOT EXISTS `note` varchar(500) DEFAULT NULL AFTER `ocr_status`,
  ADD COLUMN IF NOT EXISTS `read_engine` varchar(20) DEFAULT NULL AFTER `note`,
  ADD COLUMN IF NOT EXISTS `read_error` varchar(255) DEFAULT NULL AFTER `read_engine`,
  ADD COLUMN IF NOT EXISTS `read_at` datetime DEFAULT NULL AFTER `read_error`,
  ADD COLUMN IF NOT EXISTS `thumb_path` varchar(255) DEFAULT NULL AFTER `read_at`,
  ADD COLUMN IF NOT EXISTS `po_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `thumb_path`,
  ADD COLUMN IF NOT EXISTS `paid_method` varchar(10) DEFAULT NULL AFTER `po_id`,
  ADD COLUMN IF NOT EXISTS `paid_date` date DEFAULT NULL AFTER `paid_method`,
  ADD COLUMN IF NOT EXISTS `paid_bank_account_id` int(10) UNSIGNED DEFAULT NULL AFTER `paid_date`,
  ADD COLUMN IF NOT EXISTS `dismissed_at` datetime DEFAULT NULL AFTER `paid_bank_account_id`,
  ADD COLUMN IF NOT EXISTS `dismissed_by` int(11) DEFAULT NULL AFTER `dismissed_at`,
  ADD COLUMN IF NOT EXISTS `dismiss_reason` varchar(255) DEFAULT NULL AFTER `dismissed_by`,
  ADD COLUMN IF NOT EXISTS `form_json` mediumtext DEFAULT NULL AFTER `dismiss_reason`;

ALTER TABLE `receipt_file`
  ADD INDEX IF NOT EXISTS `idx_company_sha1` (`company_id`, `sha1`),
  ADD INDEX IF NOT EXISTS `idx_bill` (`bill_id`);

ALTER TABLE `receipt_settings`
  ADD COLUMN IF NOT EXISTS `ai_model` varchar(40) DEFAULT 'gpt-4o-mini' AFTER `ocr_provider`,
  ADD COLUMN IF NOT EXISTS `default_due_days` smallint(5) UNSIGNED DEFAULT 30 AFTER `auto_split_lines`;

-- One reading per receipt: keep the newest, then a re-read replaces it.
DELETE o1 FROM `receipt_ocr` o1 JOIN `receipt_ocr` o2 ON o1.file_id = o2.file_id AND o1.ocr_id < o2.ocr_id;
ALTER TABLE `receipt_ocr` ADD UNIQUE KEY IF NOT EXISTS `uq_file` (`file_id`);

-- The old engine made up "Unknown Supplier", an invoice number and amounts
-- (confidence 0) whenever it could not read a slip. Clear those readings on
-- receipts not yet made into a bill, so nobody books invented figures.
UPDATE `receipt_file` rf JOIN `receipt_ocr` ro ON ro.file_id = rf.file_id
   SET rf.ocr_status = 'failed', rf.read_error = 'The old reader could not read this slip — type the details in.'
 WHERE ro.vendor_name = 'Unknown Supplier' AND COALESCE(ro.confidence_score, 0) = 0 AND rf.bill_id IS NULL;
UPDATE `receipt_ocr` ro JOIN `receipt_file` rf ON rf.file_id = ro.file_id
   SET ro.vendor_name = NULL, ro.vendor_vat = NULL, ro.invoice_number = NULL, ro.invoice_date = NULL,
       ro.subtotal = NULL, ro.tax = NULL, ro.total = NULL, ro.line_items_json = '[]'
 WHERE ro.vendor_name = 'Unknown Supplier' AND COALESCE(ro.confidence_score, 0) = 0 AND rf.bill_id IS NULL;
