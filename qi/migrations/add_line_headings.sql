-- Section headings for quote/invoice line items (imported from project board
-- groups, or added by hand on the editor). Kept OUT of quote_lines /
-- invoice_lines on purpose: the GL posting engine, VAT201 calculator and the
-- SARS audit file all consume those tables and must never see zero-amount
-- pseudo-lines. Headings share the lines' sort_order sequence so the two sets
-- interleave into one ordered document.
--
-- The app also auto-creates this table on first use (qi/lib/LineHeadings.php),
-- so running this migration is optional but recommended.

CREATE TABLE IF NOT EXISTS qi_line_headings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT NOT NULL,
  parent_type VARCHAR(10) NOT NULL,
  parent_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qlh_parent (parent_type, parent_id, sort_order),
  KEY idx_qlh_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
