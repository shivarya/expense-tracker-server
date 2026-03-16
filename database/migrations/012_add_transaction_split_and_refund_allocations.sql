-- Migration 012: Transaction split lines and refund allocations overlays
-- Purpose:
-- 1) Allow users to split one debit transaction across multiple categories.
-- 2) Allow users to allocate a credit refund partially to a specific expense transaction.
--
-- Notes:
-- - Core transactions remain immutable for sync/re-sync compatibility.
-- - These tables are overlays and are ignored by ingestion duplicate logic.

CREATE TABLE IF NOT EXISTS transaction_splits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    parent_transaction_id INT NOT NULL,
    category_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    INDEX idx_split_user_parent (user_id, parent_transaction_id),
    INDEX idx_split_parent_deleted (parent_transaction_id, deleted_at),
    INDEX idx_split_category (category_id),
    INDEX idx_split_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_refund_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    refund_transaction_id INT NOT NULL,
    expense_transaction_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    notes TEXT NULL,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (refund_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (expense_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    INDEX idx_refund_user_refund (user_id, refund_transaction_id),
    INDEX idx_refund_user_expense (user_id, expense_transaction_id),
    INDEX idx_refund_refund_deleted (refund_transaction_id, deleted_at),
    INDEX idx_refund_expense_deleted (expense_transaction_id, deleted_at),
    INDEX idx_refund_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;