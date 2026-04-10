-- Migration: 2026-04-10-vat-production-ready.sql
-- Purpose: Add missing columns to gl_vat_periods table required by VAT module endpoints.
--          Columns: created_by, prepared_by, prepared_at
-- Safe to re-run: uses IF NOT EXISTS via information_schema check.

DELIMITER //

DROP PROCEDURE IF EXISTS _vat_add_col_if_missing//
CREATE PROCEDURE _vat_add_col_if_missing(
    IN tbl VARCHAR(64),
    IN col VARCHAR(64),
    IN col_def VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = tbl AND column_name = col
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', col_def);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

-- Add created_by column (user who created the period)
CALL _vat_add_col_if_missing('gl_vat_periods', 'created_by', 'INT UNSIGNED DEFAULT NULL AFTER `notes`');

-- Add prepared_by column (user who prepared the return)
CALL _vat_add_col_if_missing('gl_vat_periods', 'prepared_by', 'INT UNSIGNED DEFAULT NULL AFTER `created_by`');

-- Add prepared_at column (timestamp when return was prepared)
CALL _vat_add_col_if_missing('gl_vat_periods', 'prepared_at', 'DATETIME DEFAULT NULL AFTER `prepared_by`');

-- Cleanup
DROP PROCEDURE IF EXISTS _vat_add_col_if_missing;
