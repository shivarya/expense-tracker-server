-- Migration 020: Monthly financial-plan inputs
--
-- Two numbers the goals feature needs but can't derive from existing data:
-- take-home income, and recurring fixed commitments that are neither an
-- EMI (already in `emis`) nor the discretionary spend-cap goal (already in
-- `goals`) -- e.g. Sukanya/NPS contributions, house help, kids' activity
-- fees. Everything else needed for the monthly action-plan calculation
-- (active EMI burden, spend-cap target, per-goal required contributions)
-- is already computed live from existing tables.

ALTER TABLE users
ADD COLUMN monthly_income DECIMAL(15, 2) NULL COMMENT 'Take-home monthly income; NULL = monthly plan not yet configured',
ADD COLUMN monthly_other_commitments DECIMAL(15, 2) NOT NULL DEFAULT 0 COMMENT 'Recurring fixed monthly costs not covered by emis or a spend_cap goal (SIPs, insurance, house help, etc)';
