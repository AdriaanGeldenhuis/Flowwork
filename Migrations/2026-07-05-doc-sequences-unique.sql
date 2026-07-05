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
-- Idempotence guard: MySQL has no ADD UNIQUE KEY IF NOT EXISTS. If this
-- migration was already applied, the ALTER below fails with
-- "Duplicate key name 'uq_doc_sequences_scope'" — that error is safe to
-- ignore / the migration can be skipped.
--
-- Pre-check before applying (must return no rows; if it does, merge the
-- duplicates keeping MAX(last_number) before adding the key):
--   SELECT company_id, doc_type, period_key, COUNT(*)
--   FROM doc_sequences
--   GROUP BY company_id, doc_type, period_key
--   HAVING COUNT(*) > 1;

ALTER TABLE `doc_sequences`
  ADD UNIQUE KEY `uq_doc_sequences_scope` (`company_id`, `doc_type`, `period_key`);
