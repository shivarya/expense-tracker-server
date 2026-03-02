-- Migration 006: Add trusted_contacts table for self-transfer detection
-- Trusted contacts are the user's own accounts/people so transfers auto-categorize as Transfer (17)

CREATE TABLE IF NOT EXISTS trusted_contacts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  upi_id VARCHAR(255) DEFAULT NULL,
  notes VARCHAR(500) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_id (user_id)
);
