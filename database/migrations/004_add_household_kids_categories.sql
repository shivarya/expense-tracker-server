-- Migration 004: Add Household Help and Kids Activities categories
-- Run via phpMyAdmin or db-fix.ps1

INSERT INTO categories (id, name, icon, color, type, display_order, user_id)
VALUES
  (52, 'Household Help',  'people-outline',  '#8D6E63', 'expense', 52, NULL),
  (53, 'Kids Activities', 'trophy-outline',  '#FF7043', 'expense', 53, NULL)
ON DUPLICATE KEY UPDATE
  name          = VALUES(name),
  icon          = VALUES(icon),
  color         = VALUES(color),
  type          = VALUES(type),
  display_order = VALUES(display_order);
