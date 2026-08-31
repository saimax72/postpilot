-- PostPilot migration 002 - saved hashtag sets
-- Run once in phpMyAdmin (SQL tab) against u779448677_postpilot.

CREATE TABLE IF NOT EXISTS hashtag_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  tags       TEXT         NOT NULL,   -- space separated, each already carrying its #
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_set_name (user_id, name),
  KEY idx_sets_user (user_id),
  CONSTRAINT fk_sets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
