-- Add capital goods VAT tracking flag to journal lines.
-- SARS VAT201 Box 7 requires separate reporting of input VAT on capital goods.
-- This flag enables explicit marking rather than relying on heuristic detection.

ALTER TABLE `journal_lines`
  ADD COLUMN IF NOT EXISTS `is_capital_goods` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tax_code_id`;
