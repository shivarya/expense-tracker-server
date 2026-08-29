-- Migration 025: Add Gift category
-- Covers: gift items, gift cards/vouchers, presents for birthdays/weddings/festivals

INSERT INTO categories (id, name, icon, color, type, display_order, user_id)
VALUES (57, 'Gift', 'gift-outline', '#EC407A', 'expense', 57, NULL)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  icon = VALUES(icon),
  color = VALUES(color),
  type = VALUES(type),
  display_order = VALUES(display_order);
