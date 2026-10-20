-- Shopping rebuild (2026-10-23): lists get a date, a project, a store, a note,
-- "done" and "deleted" (restorable); items get an aisle, prices (expected and
-- paid), where / when / by whom they were bought, and the purchase order they
-- went on; the price book keeps names and stores; preferences for the shop;
-- share links can be stopped.
-- Mirrors shopping/lib/ShSchema.php, which applies the same changes on first
-- use, so running this file by hand is optional. Safe to run more than once.

ALTER TABLE `shopping_lists`
  ADD COLUMN IF NOT EXISTS `needed_by` date DEFAULT NULL AFTER `budget_cents`,
  ADD COLUMN IF NOT EXISTS `project_id` int(11) DEFAULT NULL AFTER `needed_by`,
  ADD COLUMN IF NOT EXISTS `notes` varchar(500) DEFAULT NULL AFTER `project_id`,
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `template_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `store_id`,
  ADD COLUMN IF NOT EXISTS `done_at` datetime DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `deleted_at` datetime DEFAULT NULL AFTER `done_at`;
ALTER TABLE `shopping_lists`
  ADD INDEX IF NOT EXISTS `idx_company_status` (`company_id`, `deleted_at`, `status`);

ALTER TABLE `shopping_items`
  ADD COLUMN IF NOT EXISTS `category` varchar(20) DEFAULT NULL AFTER `category_id`,
  ADD COLUMN IF NOT EXISTS `est_price_cents` bigint(20) DEFAULT NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `paid_cents` bigint(20) DEFAULT NULL AFTER `est_price_cents`,
  ADD COLUMN IF NOT EXISTS `store_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `paid_cents`,
  ADD COLUMN IF NOT EXISTS `bought_at` datetime DEFAULT NULL AFTER `store_id`,
  ADD COLUMN IF NOT EXISTS `bought_by` int(11) DEFAULT NULL AFTER `bought_at`,
  ADD COLUMN IF NOT EXISTS `bought_name` varchar(80) DEFAULT NULL AFTER `bought_by`,
  ADD COLUMN IF NOT EXISTS `added_by` int(11) DEFAULT NULL AFTER `bought_name`,
  ADD COLUMN IF NOT EXISTS `po_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `added_by`;
ALTER TABLE `shopping_items`
  ADD INDEX IF NOT EXISTS `idx_bought` (`list_id`, `status`, `bought_at`);

ALTER TABLE `shopping_price_history`
  ADD COLUMN IF NOT EXISTS `item_name` varchar(255) DEFAULT NULL AFTER `item_fingerprint`,
  ADD COLUMN IF NOT EXISTS `unit` varchar(20) DEFAULT NULL AFTER `item_name`,
  ADD COLUMN IF NOT EXISTS `qty` decimal(15,3) DEFAULT NULL AFTER `unit`,
  ADD COLUMN IF NOT EXISTS `store_name` varchar(255) DEFAULT NULL AFTER `store_id`,
  ADD COLUMN IF NOT EXISTS `list_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `store_name`,
  ADD COLUMN IF NOT EXISTS `item_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `list_id`,
  ADD COLUMN IF NOT EXISTS `user_id` int(11) DEFAULT NULL AFTER `item_id`;
ALTER TABLE `shopping_price_history`
  ADD INDEX IF NOT EXISTS `idx_item` (`item_id`);

ALTER TABLE `shopping_stores`
  ADD COLUMN IF NOT EXISTS `created_by` int(11) DEFAULT NULL AFTER `delivery_options_json`;

ALTER TABLE `shopping_preferences`
  ADD COLUMN IF NOT EXISTS `price_prompt` tinyint(1) NOT NULL DEFAULT 1 AFTER `route_mode`,
  ADD COLUMN IF NOT EXISTS `keep_awake` tinyint(1) NOT NULL DEFAULT 1 AFTER `price_prompt`,
  ADD COLUMN IF NOT EXISTS `group_by` varchar(10) NOT NULL DEFAULT 'aisle' AFTER `keep_awake`,
  ADD COLUMN IF NOT EXISTS `default_purpose` varchar(20) NOT NULL DEFAULT 'general' AFTER `group_by`,
  ADD COLUMN IF NOT EXISTS `home_store_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `default_purpose`;

ALTER TABLE `shopping_share_tokens`
  ADD COLUMN IF NOT EXISTS `revoked_at` datetime DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `last_used_at` datetime DEFAULT NULL AFTER `revoked_at`;

-- Lists shared "by link" in the old module were shown to the whole company: keep that as a team list.
UPDATE `shopping_lists` SET `shared_mode` = 'team' WHERE `shared_mode` = 'token';
