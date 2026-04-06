-- Add deep branding customization columns to companies table
-- Run this migration to enable Level 2 customization for quotes & invoices

ALTER TABLE `companies`
  ADD COLUMN `qi_heading_color` VARCHAR(7) DEFAULT NULL AFTER `secondary_color`,
  ADD COLUMN `qi_text_color` VARCHAR(7) DEFAULT NULL AFTER `qi_heading_color`,
  ADD COLUMN `qi_table_header_text` VARCHAR(7) DEFAULT '#ffffff' AFTER `qi_text_color`,
  ADD COLUMN `qi_bg_color` VARCHAR(7) DEFAULT NULL AFTER `qi_table_header_text`,
  ADD COLUMN `qi_border_radius` TINYINT UNSIGNED DEFAULT 8 AFTER `qi_bg_color`,
  ADD COLUMN `qi_logo_size` SMALLINT UNSIGNED DEFAULT 200 AFTER `qi_border_radius`,
  ADD COLUMN `qi_logo_position` ENUM('left','center','right') DEFAULT 'left' AFTER `qi_logo_size`,
  ADD COLUMN `qi_quote_title` VARCHAR(50) DEFAULT 'QUOTATION' AFTER `qi_logo_position`,
  ADD COLUMN `qi_invoice_title` VARCHAR(50) DEFAULT 'INVOICE' AFTER `qi_quote_title`,
  ADD COLUMN `qi_show_payment_details` TINYINT(1) DEFAULT 1 AFTER `qi_show_reg_number`,
  ADD COLUMN `qi_custom_css` TEXT DEFAULT NULL AFTER `qi_show_payment_details`;
