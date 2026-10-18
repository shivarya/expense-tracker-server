-- Migration 030: Add Clothing category
-- Covers: clothes, ethnic wear, footwear, fashion stores/apps (Myntra, AJIO, Zudio,
-- Westside...), school uniforms, tailoring/alterations, laundry/dry cleaning
--
-- Plain INSERT on purpose: unlike 025, no ON DUPLICATE KEY UPDATE. User-created and
-- rogue categories draw ids from the same AUTO_INCREMENT, so id 58 may already belong
-- to someone's custom category; an upsert would silently rename it. If this fails with
-- "Duplicate entry '58'", run `SELECT * FROM categories WHERE id = 58` — a row named
-- Clothing with user_id NULL means it is already applied.
--
-- is_system = TRUE (52-57 were added without it): updateCategory() stores per-user
-- budgets only for system rows, and deleteCategory() refuses to remove them.

INSERT INTO categories (id, name, icon, color, type, is_system, display_order, user_id)
VALUES (58, 'Clothing', 'shirt-outline', '#7E57C2', 'expense', TRUE, 58, NULL);
