-- CRM settings key reconciliation (2026-07-04)
--
-- The CRM settings page historically wrote keys that the application code
-- never read (settings.php wrote crm_require_vat / crm_default_status while
-- init.php and account_save read crm_require_vat_number and
-- crm_default_supplier_status / crm_default_customer_status). Copy any
-- existing values over to the canonical keys, then drop the orphaned ones.
--
-- company_settings has a unique key on (company_id, setting_key), so
-- INSERT IGNORE keeps any value already present under the canonical key.

-- Note: the old crm_require_vat flag is intentionally NOT copied to
-- crm_require_vat_number. The old checkbox was never enforced anywhere, so
-- carrying it over would suddenly block account creation for tenants who
-- ticked it years ago. VAT enforcement is a fresh opt-in on the settings page.

INSERT IGNORE INTO company_settings (company_id, setting_key, setting_value, updated_at)
SELECT company_id, 'crm_default_supplier_status', setting_value, NOW()
FROM company_settings
WHERE setting_key = 'crm_default_status';

INSERT IGNORE INTO company_settings (company_id, setting_key, setting_value, updated_at)
SELECT company_id, 'crm_default_customer_status', setting_value, NOW()
FROM company_settings
WHERE setting_key = 'crm_default_status';

DELETE FROM company_settings
WHERE setting_key IN ('crm_require_vat', 'crm_default_status');
