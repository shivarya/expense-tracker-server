-- Migration 029: As-of date of a long-term fund's valuation
-- The Gmail sync can read several statements of one NPS account in a single run (and a statement whose password
-- was added later arrives after newer ones were already applied). current_value now carries the statement's
-- "valuation as on" date, and an older statement never overwrites a newer one (utils/npsAccounts.php).

ALTER TABLE long_term_funds
ADD COLUMN valuation_date DATE NULL COMMENT 'As-of date of current_value (statement valuation date)' AFTER current_value;
