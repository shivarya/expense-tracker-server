-- Migration 008: Add Donation category
-- Covers: Charity donations, NGO contributions, relief funds, donation drives

INSERT INTO categories (id, name, icon, color, type, display_order, user_id)
VALUES (55, 'Donation', 'heart-outline', '#E74C3C', 'expense', 55, NULL)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  icon = VALUES(icon),
  color = VALUES(color),
  type = VALUES(type),
  display_order = VALUES(display_order);
