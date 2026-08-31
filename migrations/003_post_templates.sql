-- PostPilot migration 003 - reusable post templates
-- Run once in phpMyAdmin (SQL tab) against u779448677_postpilot.

CREATE TABLE IF NOT EXISTS post_templates (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  content       TEXT         NULL,
  link_url      VARCHAR(500) NULL,
  media_ratio   VARCHAR(12)  NULL,
  alt_text      VARCHAR(400) NULL,
  first_comment VARCHAR(600) NULL,
  account_ids   VARCHAR(255) NULL,   -- comma separated social_accounts.id
  use_count     INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_tpl_name (user_id, name),
  KEY idx_tpl_user (user_id),
  CONSTRAINT fk_tpl_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
