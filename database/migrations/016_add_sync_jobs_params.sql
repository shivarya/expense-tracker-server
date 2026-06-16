-- Migration 016: store the parameters of a queued sync job (e.g. Gmail fetch
-- range + which sources) so the cron worker knows what to do when it drains it.

ALTER TABLE sync_jobs
    ADD COLUMN IF NOT EXISTS params JSON NULL COMMENT 'Job parameters, e.g. {"range":"6m","types":["mutual_funds"]}' AFTER type;
