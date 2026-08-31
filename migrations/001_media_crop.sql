-- PostPilot migration 001 - image ratio + crop support
-- Run once in phpMyAdmin (SQL tab) against u779448677_postpilot.
-- Safe to run on a database that already has posts; existing rows get NULLs.

ALTER TABLE posts
  ADD COLUMN media_original VARCHAR(255) NULL AFTER media_path,
  ADD COLUMN media_ratio    VARCHAR(12)  NULL AFTER media_original,
  ADD COLUMN crop_box       VARCHAR(64)  NULL AFTER media_ratio,
  ADD COLUMN alt_text       VARCHAR(400) NULL AFTER crop_box,
  ADD COLUMN first_comment  VARCHAR(600) NULL AFTER alt_text;
