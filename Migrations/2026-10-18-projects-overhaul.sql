-- 2026-10-18 Projects overhaul
--
-- Same result as projects/lib/ProjectSchema.php, which applies this
-- automatically the first time a Projects page or API runs (and then fills
-- the new fields from the existing boards). Run it by hand only if you want
-- the schema in place before anyone opens Projects. Safe to run more than
-- once (MariaDB 10.5+).
--
-- 1. New tables: board_item_people (every person on an item, for My work,
--    the Calendar, workload and reminders) and project_user_state (starred
--    and recently opened projects and boards, per-board preferences).
-- 2. New columns: project colour and visibility (members / whole company),
--    who created a board and what its rows are called, column descriptions,
--    the day an item's due reminder went out, replies and edits on updates,
--    files per column, saved-view settings.
-- 3. Column types: rating, created and updated join the list.
-- 4. Indexes for the hot paths and one value row per item and column (the
--    newest duplicate is kept).
--
-- The backfill (status / people / dates copied into the items' own fields,
-- formulas recomputed, board_item_people filled) runs from PHP, once per
-- schema version: open any Projects page after running this.

CREATE TABLE IF NOT EXISTS `board_item_people` (
  `item_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `board_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`,`user_id`),
  KEY `idx_user` (`company_id`,`user_id`),
  KEY `idx_board` (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `project_user_state` (
  `company_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `kind` enum('project','board') NOT NULL,
  `ref_id` int(11) NOT NULL,
  `starred` tinyint(1) NOT NULL DEFAULT 0,
  `last_opened_at` datetime DEFAULT NULL,
  `prefs` text DEFAULT NULL,
  PRIMARY KEY (`user_id`,`kind`,`ref_id`),
  KEY `idx_company_user` (`company_id`,`user_id`,`last_opened_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `color` varchar(7) DEFAULT NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `visibility` enum('members','company') NOT NULL DEFAULT 'members' AFTER `status`;

ALTER TABLE `project_boards`
  ADD COLUMN IF NOT EXISTS `created_by` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `item_noun` varchar(40) DEFAULT NULL;

ALTER TABLE `board_columns`
  ADD COLUMN IF NOT EXISTS `description` varchar(255) DEFAULT NULL,
  MODIFY `type` enum('status','people','date','timeline','text','longtext','number','dropdown','checkbox',
                     'tags','link','email','phone','formula','progress','priority','supplier','files',
                     'rating','created','updated') NOT NULL DEFAULT 'text';

ALTER TABLE `board_items`
  ADD COLUMN IF NOT EXISTS `reminded_on` date DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_company_assigned` (`company_id`, `assigned_to`),
  ADD INDEX IF NOT EXISTS `idx_company_due` (`company_id`, `due_date`),
  ADD INDEX IF NOT EXISTS `idx_board_company_archived` (`board_id`, `company_id`, `archived`);

ALTER TABLE `board_item_comments`
  ADD COLUMN IF NOT EXISTS `parent_id` int(11) DEFAULT NULL AFTER `item_id`,
  ADD COLUMN IF NOT EXISTS `edited_at` datetime DEFAULT NULL,
  ADD INDEX IF NOT EXISTS `idx_item` (`item_id`);

ALTER TABLE `board_item_attachments`
  ADD COLUMN IF NOT EXISTS `column_id` int(11) DEFAULT NULL AFTER `item_id`,
  ADD INDEX IF NOT EXISTS `idx_item` (`item_id`);

ALTER TABLE `board_saved_views`
  ADD COLUMN IF NOT EXISTS `config` longtext DEFAULT NULL;

ALTER TABLE `board_subitems` ADD INDEX IF NOT EXISTS `idx_parent` (`parent_item_id`);
ALTER TABLE `board_audit_log` ADD INDEX IF NOT EXISTS `idx_board_id` (`board_id`, `id`);
ALTER TABLE `board_groups` ADD INDEX IF NOT EXISTS `idx_board_position` (`board_id`, `position`);

-- One value per item and column: keep the newest of any duplicates, then add
-- the key — unless a unique key on (item_id, column_id) already exists under
-- another name (many installs have `unique_item_column`).
DELETE t1 FROM `board_item_values` t1
  JOIN `board_item_values` t2 ON t1.item_id = t2.item_id AND t1.column_id = t2.column_id AND t1.id < t2.id;
SET @fw_has_uniq := (SELECT COUNT(*) FROM (
    SELECT index_name FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'board_item_values' AND non_unique = 0
  GROUP BY index_name HAVING GROUP_CONCAT(column_name ORDER BY seq_in_index) = 'item_id,column_id') x);
SET @fw_sql := IF(@fw_has_uniq = 0, 'ALTER TABLE `board_item_values` ADD UNIQUE INDEX `uniq_item_col` (`item_id`, `column_id`)', 'DO 0');
PREPARE fw_stmt FROM @fw_sql;
EXECUTE fw_stmt;
DEALLOCATE PREPARE fw_stmt;
