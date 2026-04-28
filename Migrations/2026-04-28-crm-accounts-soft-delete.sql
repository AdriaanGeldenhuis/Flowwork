-- Add soft-delete support to crm_accounts.
-- Suppliers and customers can now be marked as deleted via a tickbox
-- on the list view or the "Deleted" option inside the account edit form.
-- A NULL deleted_at means the account is active; any timestamp means it
-- was soft-deleted at that moment and should be hidden from the default
-- list views.

ALTER TABLE `crm_accounts`
    ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`,
    ADD KEY `idx_crm_accounts_deleted_at` (`deleted_at`);
