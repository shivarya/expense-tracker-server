-- Migration 022: Merchant subscription detection
--
-- Distinct from `subscriptions` (017), which is Google Play Billing premium
-- gating for the app itself. This tracks the user's OWN recurring merchant
-- charges (Netflix, Spotify, gym, etc.) detected from their transaction
-- history -- shape mirrors `emis` (status/due-date semantics) crossed with
-- `category_learning_rules` (unique per-user merchant pattern, upsert-and-bump).

CREATE TABLE IF NOT EXISTS merchant_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    merchant_pattern VARCHAR(255) NOT NULL COMMENT 'Normalized pattern, same shape as category_learning_rules.merchant_pattern',
    display_name VARCHAR(255) NOT NULL COMMENT 'Most recent raw merchant text, for UI display',
    category_id INT NULL COMMENT 'Snapshot of latest contributing transaction category, for icon/badge only -- not authoritative',
    billing_cycle ENUM('weekly','monthly','quarterly','annual') NOT NULL DEFAULT 'monthly',
    average_amount DECIMAL(15, 2) NOT NULL,
    last_amount DECIMAL(15, 2) NOT NULL,
    amount_variance_percent DECIMAL(6, 2) NOT NULL DEFAULT 0 COMMENT 'Coefficient of variation across observed amounts x100',
    occurrence_count INT NOT NULL DEFAULT 0,
    first_transaction_date DATE NOT NULL,
    last_transaction_date DATE NOT NULL,
    next_expected_date DATE NULL COMMENT 'Informational projection only, not a hard due date',
    status ENUM('active', 'deactivated', 'dismissed') NOT NULL DEFAULT 'active',
    detection_source ENUM('bulk_scan', 'incremental') NOT NULL DEFAULT 'bulk_scan',
    cancel_url VARCHAR(1000) NULL COMMENT 'User-editable cancellation link; seeded from CancelUrlMap',
    notes VARCHAR(500) NULL,
    dismissed_at TIMESTAMP NULL,
    deactivated_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_user_merchant_pattern (user_id, merchant_pattern),
    INDEX idx_merchant_subscriptions_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE transactions
  ADD COLUMN is_subscription BOOLEAN DEFAULT FALSE AFTER emi_id,
  ADD COLUMN merchant_subscription_id INT NULL COMMENT 'Link to merchant_subscriptions if this transaction belongs to a detected subscription' AFTER is_subscription,
  ADD COLUMN merchant_pattern VARCHAR(255) NULL COMMENT 'Normalized merchant pattern, backfilled lazily on write' AFTER merchant_subscription_id;

ALTER TABLE transactions ADD INDEX idx_merchant_subscription (merchant_subscription_id);
ALTER TABLE transactions ADD INDEX idx_merchant_pattern (user_id, merchant_pattern);
