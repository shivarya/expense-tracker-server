-- ============================================
-- TRANSACTION GROUPS (user-defined grouping rules)
-- ============================================

CREATE TABLE IF NOT EXISTS transaction_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(60) DEFAULT 'layers-outline',
    color VARCHAR(20) DEFAULT '#5B5FEF',
    is_preset BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_group_name (user_id, name),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_group_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    rule_type ENUM(
        'category_id',
        'account_id',
        'account_type',
        'payment_method_keyword',
        'merchant_keyword',
        'transaction_type'
    ) NOT NULL,
    rule_value VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES transaction_groups(id) ON DELETE CASCADE,
    INDEX idx_group_id (group_id),
    INDEX idx_rule_type_value (rule_type, rule_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
