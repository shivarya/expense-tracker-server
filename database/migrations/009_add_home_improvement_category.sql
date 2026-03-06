-- Migration 009: Add Home Improvement category
-- Covers: House repairs, renovation, home maintenance, decor and furnishing expenses

INSERT INTO categories (id, name, icon, color, type, display_order, user_id)
VALUES (56, 'Home Improvement', 'hammer-outline', '#8D6E63', 'expense', 56, NULL)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  icon = VALUES(icon),
  color = VALUES(color),
  type = VALUES(type),
  display_order = VALUES(display_order);
