-- Add SARS-required reason codes to credit notes and vendor credits.
-- SARS requires credit notes to be classified by reason for audit purposes.

ALTER TABLE `credit_notes`
  ADD COLUMN IF NOT EXISTS `reason_code` ENUM(
    'return',           -- Goods returned by customer
    'discount',         -- Discount or rebate adjustment
    'correction',       -- Invoice correction (pricing/quantity error)
    'damaged',          -- Damaged or defective goods
    'cancellation',     -- Order/service cancellation
    'vat_adjustment',   -- VAT rate or VAT-only correction
    'other'             -- Other (details in reason text field)
  ) DEFAULT NULL AFTER `reason`;

ALTER TABLE `vendor_credits`
  ADD COLUMN IF NOT EXISTS `reason_code` ENUM(
    'return',           -- Goods returned to supplier
    'discount',         -- Discount or rebate from supplier
    'correction',       -- Bill correction (pricing/quantity error)
    'damaged',          -- Damaged or defective goods received
    'cancellation',     -- Order/service cancellation
    'vat_adjustment',   -- VAT rate or VAT-only correction
    'other'             -- Other (details in notes field)
  ) DEFAULT NULL AFTER `notes`;
