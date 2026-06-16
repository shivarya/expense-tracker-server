-- Migration 015: per-user pool of candidate statement PDF passwords.
--
-- Users can store a list of passwords they commonly use for protected bank/
-- investment statement PDFs. The server tries each (in addition to any
-- card-specific password in statement_passwords) when decrypting an uploaded or
-- Gmail-fetched statement. Passwords are encrypted at rest with the same
-- AES-256-GCM vault as statement_passwords; password_hash is an HMAC used only
-- for de-duplication, never for decryption.

CREATE TABLE IF NOT EXISTS statement_password_candidates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    label VARCHAR(100) NULL COMMENT 'Optional user-facing hint, e.g. "DOB" or "PAN"',
    password_hash CHAR(64) NOT NULL COMMENT 'HMAC-SHA256(password) for dedupe only',
    encrypted_password TEXT NOT NULL,
    iv VARCHAR(64) NOT NULL,
    auth_tag VARCHAR(64) NOT NULL,
    encryption_version TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_password (user_id, password_hash),
    INDEX idx_candidate_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
