-- Migration 014: Per-user budget overrides for shared system categories
--
-- Bug fixed: updateCategory() ran `UPDATE categories SET monthly_budget = ? WHERE id = ?`
-- for system categories (categories.user_id IS NULL, is_system = 1). Because those rows
-- are shared across all users, one user's budget edit changed the budget for everyone.
--
-- Fix: store each user's budget for a category in this table. Category reads COALESCE the
-- per-user override over the system default. No endpoint writes monthly_budget onto a
-- system (user_id IS NULL) category row anymore.

CREATE TABLE IF NOT EXISTS category_budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    monthly_budget DECIMAL(15, 2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_user_category_budget (user_id, category_id),
    INDEX idx_category_budgets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No backfill needed:
--  * User-owned categories keep their budget on their own (already user-scoped) row;
--    reads COALESCE the override over that default, so cb stays NULL for them.
--  * System category rows are intentionally left as-is: their monthly_budget becomes the
--    shared default, and each user's future edit creates a per-user override row here.
