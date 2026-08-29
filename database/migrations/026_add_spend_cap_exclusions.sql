-- Migration 026: Let the user exclude specific categories or individual
-- transactions from the Monthly Discretionary Cap, without affecting the
-- overall "Spent" figures shown elsewhere (Dashboard/Widget/Analytics).

ALTER TABLE goals
  ADD COLUMN excluded_category_ids JSON NULL AFTER linked_category_ids;

ALTER TABLE transactions
  ADD COLUMN exclude_from_cap TINYINT(1) NOT NULL DEFAULT 0 AFTER is_subscription;
