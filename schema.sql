-- PostPilot database schema (MySQL 5.7+ / MariaDB 10.3+)
-- Import via hPanel -> Databases -> phpMyAdmin -> Import

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name            VARCHAR(120)  NOT NULL,
  email           VARCHAR(190)  NOT NULL,
  password_hash   VARCHAR(255)  NOT NULL,
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
  timezone        VARCHAR(64)   NOT NULL DEFAULT 'UTC',
  plan            VARCHAR(40)   NOT NULL DEFAULT 'free',
  avatar_color    VARCHAR(7)    NOT NULL DEFAULT '#6366f1',
  last_login_at   DATETIME      NULL,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS social_accounts (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          INT UNSIGNED NOT NULL,
  platform         VARCHAR(32)  NOT NULL,
  display_name     VARCHAR(190) NOT NULL,
  handle           VARCHAR(190) NULL,
  external_id      VARCHAR(190) NULL,
  access_token     TEXT         NULL,
  refresh_token    TEXT         NULL,
  token_expires_at DATETIME     NULL,
  status           ENUM('connected','needs_reauth','disconnected') NOT NULL DEFAULT 'connected',
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_accounts_user (user_id),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS posts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  content       TEXT         NOT NULL,
  media_path    VARCHAR(255) NULL,          -- the cropped file that actually posts
  media_original VARCHAR(255) NULL,         -- untouched upload, kept so it can be re-cropped
  media_ratio   VARCHAR(12)  NULL,          -- square | portrait | landscape | story
  crop_box      VARCHAR(64)  NULL,          -- "fx,fy,fw,fh" as fractions of the original
  alt_text      VARCHAR(400) NULL,          -- accessibility description sent with the image
  first_comment VARCHAR(600) NULL,          -- posted as a comment right after publishing
  link_url      VARCHAR(500) NULL,
  scheduled_at  DATETIME     NOT NULL,          -- always stored in UTC
  status        ENUM('draft','scheduled','publishing','published','failed') NOT NULL DEFAULT 'scheduled',
  published_at  DATETIME     NULL,
  attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    VARCHAR(500) NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_posts_user_time (user_id, scheduled_at),
  KEY idx_posts_due (status, scheduled_at),
  CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS post_targets (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id           INT UNSIGNED NOT NULL,
  social_account_id INT UNSIGNED NOT NULL,
  platform          VARCHAR(32)  NOT NULL,
  status            ENUM('pending','published','failed') NOT NULL DEFAULT 'pending',
  remote_post_id    VARCHAR(190) NULL,
  remote_url        VARCHAR(500) NULL,
  error             VARCHAR(500) NULL,
  published_at      DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_targets_post (post_id),
  CONSTRAINT fk_targets_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_targets_account FOREIGN KEY (social_account_id) REFERENCES social_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  action     VARCHAR(64)  NOT NULL,
  detail     VARCHAR(400) NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_user (user_id),
  KEY idx_log_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempt (email, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hashtag_sets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  tags       TEXT         NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_set_name (user_id, name),
  KEY idx_sets_user (user_id),
  CONSTRAINT fk_sets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
