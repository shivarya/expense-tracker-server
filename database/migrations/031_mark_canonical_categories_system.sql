-- Migration 031: Make every canonical category a shared system row
--
-- CategoryResolver::CANONICAL (utils/categoryResolver.php) lists 1-18 and 51-58, but on
-- prod only 1-18 and 58 were is_system = 1:
--  * 52-57 (Household Help ... Gift, migrations 004/005/008/009/025) were inserted with
--    user_id NULL but no is_system. updateCategory() stores per-user budgets only for
--    system rows; for anything else it runs `UPDATE ... WHERE id = ? AND user_id = ?`,
--    which never matches a NULL user_id, so a budget set on these was silently dropped.
--  * 51 (Miscellaneous) belonged to user 1. getCategories() returns
--    `user_id IS NULL OR user_id = ?`, so no other user saw it, even though the AI and
--    CategoryResolver assign 51 as the debit fallback for everyone, and recategorizing a
--    transaction to 51 was rejected for them (transactionController checks the same
--    filter).
--
-- After this, 51-57 behave like 1-18: visible to every user, budgets go to
-- category_budgets per user, and the clients hide Delete for them.
--
-- Checked on prod before writing (2026-10-18): row 51 has monthly_budget 0, and no
-- category_budgets, category_learning_rules or goals.excluded_category_ids entry depends
-- on 51 being user 1's row; every query that reads categories filters with
-- `user_id IS NULL OR user_id = ?`, so clearing user_id only widens who sees it.
--
-- Safe to re-run. The id guards skip a row that is not the canonical one (user-created
-- categories share the same AUTO_INCREMENT, so a fresh DB could hold anything at 51-57).

START TRANSACTION;

-- Row 51 is about to be shared: move any budget its owner set on the row itself into
-- category_budgets first, otherwise it would become every user's default.
INSERT INTO category_budgets (user_id, category_id, monthly_budget)
SELECT user_id, id, monthly_budget
FROM categories
WHERE id = 51 AND name = 'Miscellaneous' AND user_id IS NOT NULL AND monthly_budget > 0;

UPDATE categories
SET user_id = NULL, monthly_budget = 0, is_system = 1
WHERE id = 51 AND name = 'Miscellaneous' AND user_id IS NOT NULL;

UPDATE categories
SET is_system = 1
WHERE id IN (51, 52, 53, 54, 55, 56, 57) AND user_id IS NULL AND is_system = 0;

COMMIT;

-- Expect 1-18 and 51-58 with user_id NULL and is_system 1.
SELECT id, user_id, name, is_system, monthly_budget
FROM categories
WHERE id BETWEEN 1 AND 18 OR id BETWEEN 51 AND 58
ORDER BY id;
