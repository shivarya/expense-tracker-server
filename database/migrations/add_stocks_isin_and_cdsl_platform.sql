-- Migration: Add stocks.isin and support cdsl platform
-- Date: 2026-02-24

USE expense_tracker;

-- 1) Add optional ISIN column if missing
SET @has_isin := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stocks'
    AND COLUMN_NAME = 'isin'
);

SET @sql_add_isin := IF(
  @has_isin = 0,
  'ALTER TABLE stocks ADD COLUMN isin VARCHAR(20) NULL AFTER symbol',
  'SELECT "stocks.isin already exists"'
);
PREPARE stmt_add_isin FROM @sql_add_isin;
EXECUTE stmt_add_isin;
DEALLOCATE PREPARE stmt_add_isin;

-- 2) Ensure index exists on ISIN
SET @has_idx_isin := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stocks'
    AND INDEX_NAME = 'idx_isin'
);

SET @sql_add_idx_isin := IF(
  @has_idx_isin = 0,
  'ALTER TABLE stocks ADD INDEX idx_isin (isin)',
  'SELECT "idx_isin already exists"'
);
PREPARE stmt_add_idx_isin FROM @sql_add_idx_isin;
EXECUTE stmt_add_idx_isin;
DEALLOCATE PREPARE stmt_add_idx_isin;

-- 3) Expand platform enum to include cdsl (idempotent because MODIFY uses full target enum)
ALTER TABLE stocks
MODIFY COLUMN platform ENUM('zerodha', 'groww', 'cdsl', 'other') NOT NULL;

-- 4) Normalize any unexpected platform values to 'other' (safe fallback)
UPDATE stocks
SET platform = 'other'
WHERE platform NOT IN ('zerodha', 'groww', 'cdsl', 'other');
