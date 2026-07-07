-- 2026-07-05 — Serialize concurrent first issuances of document numbers.
--
-- finances/lib/Sequence.php::issue() relies on SELECT ... FOR UPDATE to
-- serialize number allocation, but FOR UPDATE on a row that does not exist
-- yet does NOT block a concurrent INSERT: two requests issuing the FIRST
-- number of a new (company, doc_type, period) could both insert a row and
-- both hand out number 0001. This UNIQUE KEY makes the losing INSERT fail
-- with a duplicate-key error, which Sequence.php catches and retries via
-- the (now serializing) locked SELECT path.
--
-- Idempotence guard: MySQL has no ADD UNIQUE KEY IF NOT EXISTS, and the
-- current base schema already ships this constraint under the name
-- `uq_company_type_period`. The dynamic-SQL guard below adds the key only
-- when NO unique key on (company_id, doc_type, period_key) exists yet
-- (under either known name), so the migration is safe to run repeatedly
-- and on schemas that already carry the constraint.
--
-- Pre-check before applying (must return no rows; if it does, merge the
-- duplicates keeping MAX(last_number) before adding the key):
--   SELECT company_id, doc_type, period_key, COUNT(*)
--   FROM doc_sequences
--   GROUP BY company_id, doc_type, period_key
--   HAVING COUNT(*) > 1;

SET @have_unique := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'doc_sequences'
    AND NON_UNIQUE   = 0
    AND INDEX_NAME  IN ('uq_doc_sequences_scope', 'uq_company_type_period')
);

SET @ddl := IF(@have_unique = 0,
  'ALTER TABLE `doc_sequences` ADD UNIQUE KEY `uq_doc_sequences_scope` (`company_id`, `doc_type`, `period_key`)',
  'SELECT ''doc_sequences unique scope key already present - skipped'' AS note');

PREPARE doc_seq_unique_stmt FROM @ddl;
EXECUTE doc_seq_unique_stmt;
DEALLOCATE PREPARE doc_seq_unique_stmt;
