-- Finance SARS remediation, WS9 (wear-and-tear register)
--
-- gl_fixed_assets.asset_id is the primary key but was created WITHOUT
-- AUTO_INCREMENT. finances/fa/api/asset_create.php inserts without asset_id
-- and reads lastInsertId(): under strict SQL mode the insert fails outright
-- ("Field 'asset_id' doesn't have a default value"); under permissive mode
-- the first row gets asset_id = 0 and every later insert dies on a duplicate
-- key. Make the key auto-increment so the create endpoint works.

-- NO_AUTO_VALUE_ON_ZERO: permissive-mode production may already hold a row
-- with asset_id = 0 (the exact state described above). Without this mode the
-- MODIFY silently renumbers that row to the next sequence value while its
-- children (fa_depreciation_lines.asset_id = 0, fa_disposals) keep pointing
-- at 0 — orphaning the asset's history with no error.
SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO');

ALTER TABLE gl_fixed_assets
  MODIFY asset_id BIGINT(20) NOT NULL AUTO_INCREMENT;
