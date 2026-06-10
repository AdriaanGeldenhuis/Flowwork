-- Multi-currency support for Quotes & Invoices.
-- Quotes/invoices already carry a `currency` column; this adds the exchange
-- rate captured at document date (1 unit of document currency = X ZAR),
-- gives credit notes their own currency columns, and a per-company default
-- currency in qi_settings.
--
-- Run once. Uses gl_exchange_rates (Migrations/2026-04-10-exchange-rates.sql)
-- for rate lookups — make sure that migration has been applied first.

-- 1) Exchange rate on quotes (rate at issue date, 1 unit = X ZAR)
ALTER TABLE `quotes`
    ADD COLUMN `exchange_rate` DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER `currency`;

-- 2) Exchange rate on invoices
ALTER TABLE `invoices`
    ADD COLUMN `exchange_rate` DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER `currency`;

-- 3) Credit notes get currency + exchange rate (inherited from the linked invoice)
ALTER TABLE `credit_notes`
    ADD COLUMN `currency` CHAR(3) NOT NULL DEFAULT 'ZAR' AFTER `total`,
    ADD COLUMN `exchange_rate` DECIMAL(15,6) NOT NULL DEFAULT 1.000000 AFTER `currency`;

-- 4) Per-company default currency for new documents
ALTER TABLE `qi_settings`
    ADD COLUMN `default_currency` CHAR(3) NOT NULL DEFAULT 'ZAR' AFTER `default_tax_rate`;

-- 5) Backfill: existing documents are ZAR at rate 1 (column defaults cover this,
--    but make it explicit for any rows with a non-ZAR currency already set)
UPDATE `quotes`   SET `exchange_rate` = 1 WHERE `currency` = 'ZAR' OR `currency` IS NULL;
UPDATE `invoices` SET `exchange_rate` = 1 WHERE `currency` = 'ZAR' OR `currency` IS NULL;
