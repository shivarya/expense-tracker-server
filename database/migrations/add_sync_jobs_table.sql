-- Add sync jobs table for background processing
-- Migration: Add sync_jobs table
-- Date: 2026-01-27

USE expense_tracker;

-- Create sync jobs table for tracking background sync operations
CREATE TABLE IF NOT EXISTS sync_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('sms', 'gmail', 'stocks', 'mutual_funds', 'all') NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    progress INT DEFAULT 0 COMMENT 'Progress percentage (0-100)',
    total_items INT DEFAULT 0,
    processed_items INT DEFAULT 0,
    saved_items INT DEFAULT 0,
    skipped_items INT DEFAULT 0,
    error_message TEXT,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_status (user_id, status),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
