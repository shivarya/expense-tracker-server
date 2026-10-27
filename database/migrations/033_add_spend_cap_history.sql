-- Migration 033: remember what each month's spend cap was
-- goals.target_amount / excluded_category_ids are overwritten on edit, so a past
-- month could only be judged against today's cap. A row here means "these terms
-- apply from effective_month until a later row"; editing a cap mid-month rewrites
-- that month's row, so a month keeps the cap that was in force when it closed.
-- Months before a goal's first row use that first row (utils/spendCapHistory.php).
-- Run BEFORE pulling the code that reads it (GET /widget/summary, /goals/{id}/months).

CREATE TABLE IF NOT EXISTS spend_cap_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    goal_id INT NOT NULL,
    user_id INT NOT NULL,
    effective_month DATE NOT NULL COMMENT 'First day of the month these terms start applying',
    target_amount DECIMAL(15, 2) NOT NULL,
    excluded_category_ids JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_goal_month (goal_id, effective_month),
    FOREIGN KEY (goal_id) REFERENCES goals(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing caps: today's terms, from the month each goal was created.
INSERT IGNORE INTO spend_cap_history (goal_id, user_id, effective_month, target_amount, excluded_category_ids)
SELECT id, user_id, DATE_FORMAT(created_at, '%Y-%m-01'), target_amount, excluded_category_ids
FROM goals
WHERE goal_type = 'spend_cap' AND target_amount IS NOT NULL;
