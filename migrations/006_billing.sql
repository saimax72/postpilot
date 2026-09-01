-- PostPilot migration 006 - billing
-- Run once in phpMyAdmin (SQL tab) against u779448677_postpilot.

-- Admin-editable configuration. Secrets are encrypted with APP_KEY before they
-- are written, so this table is useless on its own if the database leaks.
CREATE TABLE IF NOT EXISTS app_settings (
  name       VARCHAR(64)  NOT NULL,
  value      TEXT         NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What a paying user is attached to at the provider, so a cancellation webhook
-- can find them again and a support question can be answered.
ALTER TABLE users
  ADD COLUMN billing_provider    VARCHAR(16)  NULL AFTER trial_ends_at,
  ADD COLUMN billing_customer_id VARCHAR(190) NULL AFTER billing_provider,
  ADD COLUMN billing_sub_id      VARCHAR(190) NULL AFTER billing_customer_id,
  ADD COLUMN plan_since          DATETIME     NULL AFTER billing_sub_id;

-- A record of what the provider told us, for reconciling against their
-- dashboard when a payment is disputed months later.
CREATE TABLE IF NOT EXISTS billing_events (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  provider   VARCHAR(16)  NOT NULL,
  event_type VARCHAR(64)  NOT NULL,
  event_id   VARCHAR(190) NULL,
  amount     VARCHAR(32)  NULL,
  detail     TEXT         NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_event (provider, event_id),
  KEY idx_billing_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
