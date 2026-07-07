-- 2026-07-05 — Pin legacy account-mapping defaults for companies with history.
--
-- WHY: The code's fallback GL codes (used only when a company has no explicit
-- finance_* account setting) were wrong relative to the seeded SARS chart and
-- have now been corrected:
--
--   setting key                     old fallback              new fallback
--   finance_vat_output_account_id   2120 (= VAT Input!)       2110 (VAT Output)
--   finance_vat_input_account_id    2130 (= PAYE Payable!)    2120 (VAT Input)
--   finance_ar_account_id           1200 (= Stock on Hand!)   1100 (AR Control)
--   finance_ap_account_id           2110 (= VAT Output!)      2010 (AP Control)
--
-- Companies that already posted journals under the OLD fallbacks have real
-- balances sitting on the old codes. Silently switching the fallback would
-- split their control accounts across two codes and corrupt VAT201 output
-- (readers would look at 2110/2120 while history sits on 2120/2130).
--
-- FIX: for each affected company — one that (a) has NO explicit, non-empty
-- setting for the key and (b) HAS posted, pipeline-generated journal lines on
-- the old fallback code — pin an explicit setting to the old code's account,
-- preserving their current, internally consistent behaviour. Companies with
-- no such history (new books, or configured companies) are untouched and get
-- the corrected defaults.
--
-- setting_value stores the gl_accounts.account_id (AccountsMap resolves
-- numeric values as account ids).
--
-- Manual journals (module = 'manual') are ignored when detecting history:
-- they reflect deliberate account choices, not the code's defaults.
--
-- VERIFY AFTERWARDS (should return no rows — no company both pinned and
-- missing the pinned account):
--   SELECT s.company_id, s.setting_key, s.setting_value
--   FROM company_settings s
--   LEFT JOIN gl_accounts a ON a.account_id = s.setting_value AND a.company_id = s.company_id
--   WHERE s.setting_key IN ('finance_vat_output_account_id','finance_vat_input_account_id',
--                           'finance_ar_account_id','finance_ap_account_id')
--     AND a.account_id IS NULL AND s.setting_value <> '';

-- ---------- finance_vat_output_account_id -> legacy code 2120 ----------
UPDATE company_settings s
JOIN gl_accounts a ON a.company_id = s.company_id AND a.account_code = '2120'
SET s.setting_value = a.account_id, s.updated_at = NOW()
WHERE s.setting_key = 'finance_vat_output_account_id'
  AND (s.setting_value IS NULL OR s.setting_value = '')
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = s.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND jl.account_code = '2120' AND jl.credit > 0
  );

INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
SELECT a.company_id, 'finance_vat_output_account_id', a.account_id, NOW()
FROM gl_accounts a
WHERE a.account_code = '2120'
  AND NOT EXISTS (
      SELECT 1 FROM company_settings s
      WHERE s.company_id = a.company_id AND s.setting_key = 'finance_vat_output_account_id'
  )
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = a.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND jl.account_code = '2120' AND jl.credit > 0
  );

-- ---------- finance_vat_input_account_id -> legacy code 2130 ----------
UPDATE company_settings s
JOIN gl_accounts a ON a.company_id = s.company_id AND a.account_code = '2130'
SET s.setting_value = a.account_id, s.updated_at = NOW()
WHERE s.setting_key = 'finance_vat_input_account_id'
  AND (s.setting_value IS NULL OR s.setting_value = '')
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = s.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND jl.account_code = '2130' AND jl.debit > 0
  );

INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
SELECT a.company_id, 'finance_vat_input_account_id', a.account_id, NOW()
FROM gl_accounts a
WHERE a.account_code = '2130'
  AND NOT EXISTS (
      SELECT 1 FROM company_settings s
      WHERE s.company_id = a.company_id AND s.setting_key = 'finance_vat_input_account_id'
  )
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = a.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND jl.account_code = '2130' AND jl.debit > 0
  );

-- ---------- finance_ar_account_id -> legacy code 1200 ----------
UPDATE company_settings s
JOIN gl_accounts a ON a.company_id = s.company_id AND a.account_code = '1200'
SET s.setting_value = a.account_id, s.updated_at = NOW()
WHERE s.setting_key = 'finance_ar_account_id'
  AND (s.setting_value IS NULL OR s.setting_value = '')
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = s.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND jl.account_code = '1200'
  );

INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
SELECT a.company_id, 'finance_ar_account_id', a.account_id, NOW()
FROM gl_accounts a
WHERE a.account_code = '1200'
  AND NOT EXISTS (
      SELECT 1 FROM company_settings s
      WHERE s.company_id = a.company_id AND s.setting_key = 'finance_ar_account_id'
  )
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = a.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND jl.account_code = '1200'
  );

-- ---------- finance_ap_account_id -> legacy code 2110 ----------
-- History detection is credit-side only: AP is credit-normal and the old
-- fallback collision was postApBill crediting 2110.
UPDATE company_settings s
JOIN gl_accounts a ON a.company_id = s.company_id AND a.account_code = '2110'
SET s.setting_value = a.account_id, s.updated_at = NOW()
WHERE s.setting_key = 'finance_ap_account_id'
  AND (s.setting_value IS NULL OR s.setting_value = '')
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = s.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND je.ref_type IN ('ap_bill', 'ap_payment', 'vendor_credit')
        AND jl.account_code = '2110'
  );

INSERT INTO company_settings (company_id, setting_key, setting_value, updated_at)
SELECT a.company_id, 'finance_ap_account_id', a.account_id, NOW()
FROM gl_accounts a
WHERE a.account_code = '2110'
  AND NOT EXISTS (
      SELECT 1 FROM company_settings s
      WHERE s.company_id = a.company_id AND s.setting_key = 'finance_ap_account_id'
  )
  AND EXISTS (
      SELECT 1 FROM journal_entries je
      JOIN journal_lines jl ON jl.journal_id = je.id
      WHERE je.company_id = a.company_id AND je.status = 'posted'
        AND (je.module IS NULL OR je.module <> 'manual')
        AND je.ref_type IN ('ap_bill', 'ap_payment', 'vendor_credit')
        AND jl.account_code = '2110'
  );
