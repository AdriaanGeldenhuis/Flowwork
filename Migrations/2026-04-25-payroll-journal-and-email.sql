-- Two payroll fixes:
--
-- 1. PostingService.postPayrollRun() (finances/lib/PostingService.php)
--    SELECTs and UPDATEs `pay_runs.journal_id` so re-posting a run can
--    delete and recreate its journal entry. The column was never
--    added, causing "Column not found: 1054 Unknown column 'pr.journal_id'".
--
-- 2. Payslips are now emailed to employees when a run is locked/posted.
--    Track per-employee delivery so the UI can show what's been sent
--    and a re-send button only re-sends what failed.

ALTER TABLE `pay_runs`
  ADD COLUMN `journal_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `posted_at`,
  ADD KEY `idx_journal_id` (`journal_id`);

ALTER TABLE `pay_run_employees`
  ADD COLUMN `payslip_emailed_at` datetime DEFAULT NULL AFTER `payslip_generated_at`,
  ADD COLUMN `payslip_email_error` varchar(255) DEFAULT NULL AFTER `payslip_emailed_at`;
