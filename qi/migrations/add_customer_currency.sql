-- Per-customer billing currency for CRM accounts.
-- NULL = use the company default currency (qi_settings.default_currency).
-- When a customer with a currency is picked on a quote/invoice, the form
-- switches to that currency automatically.
--
-- Run AFTER qi/migrations/add_multi_currency.sql.

ALTER TABLE `crm_accounts`
    ADD COLUMN `currency` CHAR(3) DEFAULT NULL COMMENT 'Preferred billing currency (NULL = company default)' AFTER `region_id`;
