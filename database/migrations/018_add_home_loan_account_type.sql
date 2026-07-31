-- Migration 018: add 'loan' account type for home-loan liability tracking.
--
-- The emis table requires a bank_accounts row via account_id (FK, ON DELETE
-- RESTRICT). Home loans have no natural savings/current/credit_card row to
-- anchor to, so each loan gets one synthetic bank_accounts row of this new
-- type, used purely as the required FK target.

ALTER TABLE bank_accounts
MODIFY COLUMN account_type ENUM('savings', 'current', 'credit_card', 'loan') NOT NULL;
