-- Migration 032: Scan & Pay
-- The Android app can now start a UPI payment itself: it scans the QR, opens the
-- chosen UPI app with a upi://pay intent and posts the result to POST /parse/upi-intent.
-- Those rows get their own source. The bank SMS and the payment app's notification for
-- the same payment merge into that row (utils/crossSourceMerger.php, family intent:<package>).

ALTER TABLE transactions
MODIFY COLUMN source ENUM('sms', 'email', 'web_scrape', 'manual', 'sms_webhook', 'statement_pdf', 'app_notification', 'upi_intent') NOT NULL;
