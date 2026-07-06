-- Finance SARS remediation, WS9 (wear-and-tear register)
--
-- gl_fixed_assets.asset_id is the primary key but was created WITHOUT
-- AUTO_INCREMENT. finances/fa/api/asset_create.php inserts without asset_id
-- and reads lastInsertId(): under strict SQL mode the insert fails outright
-- ("Field 'asset_id' doesn't have a default value"); under permissive mode
-- the first row gets asset_id = 0 and every later insert dies on a duplicate
-- key. Make the key auto-increment so the create endpoint works.

ALTER TABLE gl_fixed_assets
  MODIFY asset_id BIGINT(20) NOT NULL AUTO_INCREMENT;
