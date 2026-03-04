-- Migration 007: Add category_learning_rules table for user feedback learning
-- Learns merchant/description pattern to category mapping from manual recategorization

CREATE TABLE IF NOT EXISTS category_learning_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  merchant_pattern VARCHAR(255) NOT NULL COMMENT 'Normalized merchant/description pattern',
  category_id INT NOT NULL,
  category_name_snapshot VARCHAR(100) NOT NULL,
  source_transaction_id INT NULL COMMENT 'Transaction that taught this mapping',
  learned_count INT NOT NULL DEFAULT 1,
  last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  FOREIGN KEY (source_transaction_id) REFERENCES transactions(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_pattern (user_id, merchant_pattern),
  INDEX idx_user_category (user_id, category_id),
  INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
