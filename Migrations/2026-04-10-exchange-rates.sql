-- Exchange rates table for multi-currency support.
-- Stores daily exchange rates to ZAR for foreign currency transactions.
-- SARS requires foreign currency translations at the exchange rate on the
-- date of the transaction (Section 25D of Income Tax Act).

CREATE TABLE IF NOT EXISTS `gl_exchange_rates` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL,
  `currency_code` char(3) NOT NULL COMMENT 'ISO 4217 code (e.g. USD, EUR, GBP)',
  `rate_date` date NOT NULL COMMENT 'Date the rate applies to',
  `rate_to_zar` decimal(12,6) NOT NULL COMMENT 'Exchange rate: 1 unit of currency = X ZAR',
  `source` varchar(50) DEFAULT 'manual' COMMENT 'manual, sarb, api',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_currency_date` (`company_id`, `currency_code`, `rate_date`),
  KEY `idx_rate_lookup` (`company_id`, `currency_code`, `rate_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
