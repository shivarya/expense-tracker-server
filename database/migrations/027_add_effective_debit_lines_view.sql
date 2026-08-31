-- Migration 027: v_effective_debit_lines view
--
-- Every "Spent" calculation in this app (Dashboard, Widget, Monthly Cap,
-- Transactions screen) summed transactions.amount grouped by
-- transactions.category_id directly -- correct for an unsplit transaction,
-- but wrong for a split one (e.g. a 5000 cash withdrawal split 3000 to
-- Household Help / 2000 to Miscellaneous): only expenseAnalyticsController's
-- Expense Summary screen actually resolved transaction_splits, so a split
-- transaction's money stayed attributed to its original single category
-- everywhere else.
--
-- This view produces one row per "effective spend line": the whole
-- transaction when it has no splits, or one row per split line when it
-- does -- so every caller can just SUM/GROUP BY this view's category_id
-- instead of re-deriving split resolution itself. Carries the parent
-- transaction's own user_id/transaction_date/exclude_from_cap/deleted_at
-- through unchanged since callers filter on those.

CREATE OR REPLACE VIEW v_effective_debit_lines AS
SELECT
    t.id AS transaction_id,
    t.user_id,
    t.category_id,
    t.amount,
    t.transaction_date,
    t.exclude_from_cap,
    t.deleted_at
FROM transactions t
WHERE t.transaction_type = 'debit'
  AND NOT EXISTS (
    SELECT 1 FROM transaction_splits s2
    WHERE s2.parent_transaction_id = t.id AND s2.deleted_at IS NULL
  )
UNION ALL
SELECT
    t.id AS transaction_id,
    t.user_id,
    s.category_id,
    s.amount,
    t.transaction_date,
    t.exclude_from_cap,
    t.deleted_at
FROM transaction_splits s
JOIN transactions t ON t.id = s.parent_transaction_id
WHERE s.deleted_at IS NULL AND t.transaction_type = 'debit';
