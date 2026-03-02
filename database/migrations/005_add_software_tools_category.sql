-- Migration 005: Add Software & Tools category
-- Covers: SaaS subscriptions, domains, hosting, developer tools, GitHub, Figma, Notion etc.

INSERT INTO categories (id, name, icon, color, type, display_order, user_id)
VALUES (54, 'Software & Tools', 'laptop-outline', '#5C6BC0', 'expense', 54, NULL)
ON DUPLICATE KEY UPDATE
  name=VALUES(name), icon=VALUES(icon), color=VALUES(color), type=VALUES(type), display_order=VALUES(display_order);
