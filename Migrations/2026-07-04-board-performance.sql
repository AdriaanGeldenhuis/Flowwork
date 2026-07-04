-- Board performance & data integrity
-- Run against the production database. Review each statement: MySQL has no
-- "ADD INDEX IF NOT EXISTS", so skip any statement whose index already exists
-- (check with SHOW INDEX FROM <table>).

-- 1) board_item_values must have one row per (item, column).
--    Deduplicate first (keeps the newest row), then add the UNIQUE key that
--    api/cell/update.php and api/_formula.php rely on for their upserts.
DELETE t1 FROM board_item_values t1
JOIN board_item_values t2
  ON t1.item_id = t2.item_id
 AND t1.column_id = t2.column_id
 AND t1.id < t2.id;

ALTER TABLE board_item_values
  ADD UNIQUE KEY uq_item_column (item_id, column_id);

-- 2) Hot-path indexes. Every board load filters items by
--    (board_id, company_id, archived) and orders by (group_id, position).
ALTER TABLE board_items
  ADD INDEX idx_board_company_archived (board_id, company_id, archived),
  ADD INDEX idx_group_position (group_id, position);

ALTER TABLE board_item_attachments
  ADD INDEX idx_item (item_id);

ALTER TABLE board_item_comments
  ADD INDEX idx_item (item_id);

ALTER TABLE board_columns
  ADD INDEX idx_board_position (board_id, position);

ALTER TABLE board_groups
  ADD INDEX idx_board_position (board_id, position);

ALTER TABLE board_subitems
  ADD INDEX idx_parent (parent_item_id);

-- The change-feed cursor (api/board.changes.php) reads
-- WHERE board_id = ? AND id > ? ORDER BY id.
ALTER TABLE board_audit_log
  ADD INDEX idx_board_id (board_id, id);

-- 3) The legacy board_activity table (previously CREATE'd at request time by
--    api/activity/list.php) is superseded by board_audit_log. Drop it once
--    you have confirmed nothing else reads it:
-- DROP TABLE IF EXISTS board_activity;
