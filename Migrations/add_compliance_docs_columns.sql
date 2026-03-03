-- Migration: Add missing columns to crm_compliance_docs
-- Date: 2026-03-03
-- Issue: compliance_doc_save.php references 'notes' and 'created_by' columns
--        that do not exist in the table, causing INSERT/UPDATE failures.

ALTER TABLE crm_compliance_docs
  ADD COLUMN notes TEXT DEFAULT NULL AFTER status,
  ADD COLUMN created_by INT(10) UNSIGNED DEFAULT NULL AFTER notes;
