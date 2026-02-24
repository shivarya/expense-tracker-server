-- Remove duplicate rows from stocks table while keeping the latest record per logical key.
-- Logical key: (user_id, platform, isin) when ISIN exists, otherwise (user_id, platform, symbol)
--
-- Safe rollout:
-- 1) Run this once on production DB
-- 2) Validate with the verification query at the bottom

USE expense_tracker;

-- Backup table for deleted rows (structure matches stocks)
CREATE TABLE IF NOT EXISTS stocks_dedup_backup LIKE stocks;

-- Snapshot duplicate rows before deletion
INSERT INTO stocks_dedup_backup
SELECT s.*
FROM stocks s
INNER JOIN (
    SELECT
        user_id,
        platform,
        COALESCE(NULLIF(TRIM(isin), ''), CONCAT('SYM:', UPPER(TRIM(symbol)))) AS dedupe_key,
        MAX(id) AS keep_id,
        COUNT(*) AS cnt
    FROM stocks
    GROUP BY user_id, platform, dedupe_key
    HAVING COUNT(*) > 1
) d
    ON s.user_id = d.user_id
    AND s.platform = d.platform
    AND COALESCE(NULLIF(TRIM(s.isin), ''), CONCAT('SYM:', UPPER(TRIM(s.symbol)))) = d.dedupe_key
WHERE s.id <> d.keep_id;

-- Delete duplicate rows (keep latest id)
DELETE s
FROM stocks s
INNER JOIN (
    SELECT
        user_id,
        platform,
        COALESCE(NULLIF(TRIM(isin), ''), CONCAT('SYM:', UPPER(TRIM(symbol)))) AS dedupe_key,
        MAX(id) AS keep_id,
        COUNT(*) AS cnt
    FROM stocks
    GROUP BY user_id, platform, dedupe_key
    HAVING COUNT(*) > 1
) d
    ON s.user_id = d.user_id
    AND s.platform = d.platform
    AND COALESCE(NULLIF(TRIM(s.isin), ''), CONCAT('SYM:', UPPER(TRIM(s.symbol)))) = d.dedupe_key
WHERE s.id <> d.keep_id;

-- Add generated dedupe key column if missing
SET @has_dedupe_key := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stocks'
      AND COLUMN_NAME = 'dedupe_key'
);

SET @sql_add_dedupe_key := IF(
    @has_dedupe_key = 0,
    "ALTER TABLE stocks ADD COLUMN dedupe_key VARCHAR(80) GENERATED ALWAYS AS (COALESCE(NULLIF(TRIM(isin), ''), CONCAT('SYM:', UPPER(TRIM(symbol))))) STORED",
    "SELECT 'dedupe_key already exists'"
);
PREPARE stmt FROM @sql_add_dedupe_key;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unique key to prevent future duplicates
SET @has_unique_stock_identity := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stocks'
      AND INDEX_NAME = 'unique_stock_identity'
);

SET @sql_add_unique_stock_identity := IF(
    @has_unique_stock_identity = 0,
    "ALTER TABLE stocks ADD UNIQUE KEY unique_stock_identity (user_id, platform, dedupe_key)",
    "SELECT 'unique_stock_identity already exists'"
);
PREPARE stmt FROM @sql_add_unique_stock_identity;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verification: should return 0 rows after cleanup
SELECT
    user_id,
    platform,
    COALESCE(NULLIF(TRIM(isin), ''), CONCAT('SYM:', UPPER(TRIM(symbol)))) AS dedupe_key,
    COUNT(*) AS cnt
FROM stocks
GROUP BY user_id, platform, dedupe_key
HAVING COUNT(*) > 1;
