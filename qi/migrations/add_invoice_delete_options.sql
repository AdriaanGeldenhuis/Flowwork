-- Invoice Delete Options migration
-- Extends invoices with soft-delete, archive, write-off, reason columns
-- and expands status enum to cover new workflows.
--
-- Safe to re-run: uses IF NOT EXISTS / idempotent ALTERs where possible.

-- 1) Expand status enum
ALTER TABLE `invoices`
    MODIFY COLUMN `status` ENUM(
        'draft',
        'sent',
        'viewed',
        'part-paid',
        'paid',
        'overdue',
        'cancelled',
        'written_off',
        'uncollectible',
        'refunded'
    ) NOT NULL DEFAULT 'draft';

-- 2) Soft delete + archive + metadata columns
ALTER TABLE `invoices`
    ADD COLUMN `archived_at` DATETIME DEFAULT NULL AFTER `updated_at`,
    ADD COLUMN `archived_by` INT(10) UNSIGNED DEFAULT NULL AFTER `archived_at`,
    ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `archived_by`,
    ADD COLUMN `deleted_by` INT(10) UNSIGNED DEFAULT NULL AFTER `deleted_at`,
    ADD COLUMN `delete_reason` VARCHAR(500) DEFAULT NULL AFTER `deleted_by`,
    ADD COLUMN `write_off_amount` DECIMAL(15,2) DEFAULT NULL AFTER `delete_reason`,
    ADD COLUMN `write_off_at` DATETIME DEFAULT NULL AFTER `write_off_amount`,
    ADD COLUMN `write_off_by` INT(10) UNSIGNED DEFAULT NULL AFTER `write_off_at`,
    ADD COLUMN `cancellation_reason` VARCHAR(500) DEFAULT NULL AFTER `write_off_by`;

-- 3) Indexes to keep lists fast when filtering out deleted/archived
ALTER TABLE `invoices`
    ADD INDEX `idx_deleted_at` (`deleted_at`),
    ADD INDEX `idx_archived_at` (`archived_at`),
    ADD INDEX `idx_company_status_deleted` (`company_id`, `status`, `deleted_at`);

-- 4) Refund tracking on payments (best-effort, only if column missing)
--    Used by the refund flow so the original payment journal can be reversed.
ALTER TABLE `payments`
    ADD COLUMN `refunded_at` DATETIME DEFAULT NULL,
    ADD COLUMN `refunded_by` INT(10) UNSIGNED DEFAULT NULL,
    ADD COLUMN `refund_reference` VARCHAR(190) DEFAULT NULL;
