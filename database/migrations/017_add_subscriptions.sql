-- Migration 017: premium subscriptions (Google Play Billing).
--
-- One row per verified purchase token. The app sends a purchaseToken after a
-- Play purchase; the server verifies it with the Google Play Developer API and
-- upserts here. SubscriptionService::isPremium() reads the latest active row.

CREATE TABLE IF NOT EXISTS subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    product_id VARCHAR(100) NOT NULL COMMENT 'Play subscription product id, e.g. premium_monthly',
    purchase_token VARCHAR(512) NOT NULL,
    status ENUM('active', 'in_grace', 'on_hold', 'paused', 'canceled', 'expired', 'pending') NOT NULL DEFAULT 'pending',
    payment_state TINYINT NULL COMMENT 'Play paymentState: 0 pending, 1 received, 2 free trial, 3 deferred',
    auto_renewing BOOLEAN DEFAULT FALSE,
    expiry_time TIMESTAMP NULL,
    raw JSON NULL COMMENT 'Last verification payload (non-PII)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_purchase_token (purchase_token),
    INDEX idx_sub_user_expiry (user_id, expiry_time),
    INDEX idx_sub_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
