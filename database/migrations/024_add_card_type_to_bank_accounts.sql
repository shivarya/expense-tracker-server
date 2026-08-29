-- Migration 024: Card product identity on bank_accounts
--
-- Two credit cards from the same bank (e.g. ICICI Amazon Pay vs ICICI Rubyx)
-- were indistinguishable in the app because account_name was always derived
-- from `bank` alone. Statement text already reveals the card product for
-- most issuers (ICICI: Rubyx/Amazon Pay/Coral/Platinum; RBL: Play) -- this
-- column lets ingestion store it so account_name can say "ICICI Amazon Pay"
-- instead of "ICICI Card".

ALTER TABLE bank_accounts
  ADD COLUMN card_type VARCHAR(50) NULL COMMENT 'Card product name detected from statement text, e.g. Rubyx, Amazon Pay, Play' AFTER card_last_four;
