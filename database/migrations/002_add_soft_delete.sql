-- Migration 002: Add soft delete to transactions table
-- Run this on the production DB via cPanel phpMyAdmin or SSH mysql client
-- Safe to run multiple times (uses IF NOT EXISTS logic via column check)

ALTER TABLE transactions
  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL DEFAULT NULL
    COMMENT 'Soft delete timestamp; NULL = active, set = deleted';

-- Optional index for fast filtering of active transactions
ALTER TABLE transactions
  ADD INDEX IF NOT EXISTS idx_deleted_at (deleted_at);
