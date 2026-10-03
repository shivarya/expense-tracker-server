-- Migration 028: Payment-app notification capture
-- The Android app now forwards payment notifications (PhonePe, Google Pay, Paytm,
-- BHIM, Amazon Pay, CRED, bank apps) to POST /parse/notification. Those rows get
-- their own source, and every path stores the normalized 12-digit UPI RRN/UTR in
-- upi_ref so a notification and the bank SMS for the same payment can be merged
-- into one row (utils/crossSourceMerger.php).

ALTER TABLE transactions
MODIFY COLUMN source ENUM('sms', 'email', 'web_scrape', 'manual', 'sms_webhook', 'statement_pdf', 'app_notification') NOT NULL;

ALTER TABLE transactions
ADD COLUMN upi_ref VARCHAR(20) NULL COMMENT 'Normalized 12-digit UPI RRN/UTR, any source' AFTER reference_number,
ADD INDEX idx_user_upi_ref (user_id, upi_ref);

-- Backfill recent rows so notifications arriving after deploy can match SMS already stored.
UPDATE transactions
SET upi_ref = REGEXP_SUBSTR(reference_number, '[0-9]{12}')
WHERE upi_ref IS NULL
  AND reference_number REGEXP '[0-9]{12}'
  AND transaction_date >= NOW() - INTERVAL 60 DAY;
