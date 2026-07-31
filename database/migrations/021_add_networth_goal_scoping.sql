-- Migration 022: Scope net_worth goals to specific assets
--
-- net_worth previously always summed the WHOLE portfolio (getPortfolioTotals).
-- Some net_worth goals are purpose-specific (e.g. a child's corpus) and should
-- only count a chosen subset -- e.g. Sukanya + a few growth mutual funds, not
-- retirement EPF/NPS/PPF or debt/gold funds held for other reasons.
-- NULL on both columns preserves old behavior (whole-portfolio tracking).

ALTER TABLE goals
ADD COLUMN linked_mutual_fund_ids JSON NULL COMMENT 'net_worth only; NULL = not scoped to specific funds',
ADD COLUMN linked_long_term_fund_ids JSON NULL COMMENT 'net_worth only; NULL = not scoped to specific long-term funds';
