-- Migration 023: allow manually-added subscriptions
--
-- Interval-based detection can't identify a subscription from a single
-- occurrence (an annual/quarterly charge needs at least 2 real payments in
-- history before a pattern is inferable). This lets the user add one they
-- already know about instead of waiting for a second payment to land.

ALTER TABLE merchant_subscriptions
  MODIFY COLUMN detection_source ENUM('bulk_scan', 'incremental', 'manual') NOT NULL DEFAULT 'bulk_scan';
