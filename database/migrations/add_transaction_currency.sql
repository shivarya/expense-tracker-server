-- Migration: Add multi-currency support to transactions
-- Date: 2026-06-11
-- Why: foreign-currency spend (e.g. Malaysia MYR) was stored/shown as INR.
--      `amount` stays the INR home value; original_amount/original_currency
--      preserve the foreign figure for display. fx_rates caches daily rates.

USE expense_tracker;

-- 1) transactions.currency  (currency of `amount`; always INR in practice)
SET @has_currency := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'currency'
);
SET @sql := IF(@has_currency = 0,
  "ALTER TABLE transactions ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'INR' AFTER amount",
  'SELECT "transactions.currency already exists"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) transactions.original_amount  (foreign-currency figure, e.g. 30.00)
SET @has_orig_amt := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'original_amount'
);
SET @sql := IF(@has_orig_amt = 0,
  'ALTER TABLE transactions ADD COLUMN original_amount DECIMAL(15,2) NULL AFTER currency',
  'SELECT "transactions.original_amount already exists"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) transactions.original_currency  (ISO code of the original; NULL = domestic)
SET @has_orig_cur := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'original_currency'
);
SET @sql := IF(@has_orig_cur = 0,
  'ALTER TABLE transactions ADD COLUMN original_currency CHAR(3) NULL AFTER original_amount',
  'SELECT "transactions.original_currency already exists"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) Index to find foreign transactions quickly
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND INDEX_NAME = 'idx_original_currency'
);
SET @sql := IF(@has_idx = 0,
  'ALTER TABLE transactions ADD INDEX idx_original_currency (original_currency)',
  'SELECT "idx_original_currency already exists"');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) fx_rates cache (one row per base/quote/date)
CREATE TABLE IF NOT EXISTS fx_rates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    base CHAR(3) NOT NULL,
    quote CHAR(3) NOT NULL,
    rate_date DATE NOT NULL,
    rate DECIMAL(18, 8) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'frankfurter',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_base_quote_date (base, quote, rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
