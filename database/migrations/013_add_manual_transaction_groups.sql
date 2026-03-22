-- Migration 013: Manual transaction groups (explicit transaction collections)

CREATE TABLE IF NOT EXISTS manual_transaction_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(60) DEFAULT 'flag-outline',
    color VARCHAR(20) DEFAULT '#FF7043',
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_manual_group_name (user_id, name),
    INDEX idx_manual_groups_user_deleted (user_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_transaction_group_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    manual_group_id INT NOT NULL,
    transaction_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (manual_group_id) REFERENCES manual_transaction_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_manual_group_txn (manual_group_id, transaction_id),
    INDEX idx_manual_group_txn_user_txn (user_id, transaction_id),
    INDEX idx_manual_group_txn_user_group (user_id, manual_group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
