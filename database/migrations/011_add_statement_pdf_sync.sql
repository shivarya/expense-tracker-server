-- Migration 011: Statement PDF sync support
-- Adds secure password vault table, statement upload audit table,
-- and extends transaction source enum for statement uploads.

ALTER TABLE transactions
MODIFY COLUMN source ENUM('sms', 'email', 'web_scrape', 'manual', 'sms_webhook', 'statement_pdf') NOT NULL;

CREATE TABLE IF NOT EXISTS statement_passwords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank ENUM('hdfc', 'sbi', 'icici', 'idfc', 'rbl', 'axis', 'kotak', 'other') NOT NULL,
    account_type ENUM('savings', 'current', 'credit_card') NOT NULL DEFAULT 'credit_card',
    card_last_four CHAR(4) NULL,
    encrypted_password TEXT NOT NULL,
    iv VARCHAR(64) NOT NULL,
    auth_tag VARCHAR(64) NOT NULL,
    encryption_version TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_statement_password (user_id, bank, account_type, card_last_four),
    INDEX idx_statement_password_lookup (user_id, bank, account_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS statement_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bank ENUM('hdfc', 'sbi', 'icici', 'idfc', 'rbl', 'axis', 'kotak', 'other') NOT NULL,
    account_type ENUM('savings', 'current', 'credit_card') NOT NULL DEFAULT 'credit_card',
    card_last_four CHAR(4) NULL,
    file_name VARCHAR(255) NOT NULL,
    file_hash CHAR(64) NOT NULL,
    status ENUM('processing', 'success', 'failed', 'duplicate_upload') NOT NULL DEFAULT 'processing',
    extracted_count INT NOT NULL DEFAULT 0,
    saved_count INT NOT NULL DEFAULT 0,
    skipped_high_confidence INT NOT NULL DEFAULT 0,
    flagged_possible_duplicates INT NOT NULL DEFAULT 0,
    ai_checked_transactions INT NOT NULL DEFAULT 0,
    duplicate_fallback_used INT NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_statement_upload_user_created (user_id, created_at),
    INDEX idx_statement_upload_hash (user_id, file_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
