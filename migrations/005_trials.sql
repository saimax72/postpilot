-- PostPilot migration 005 - free trials
-- Run once in phpMyAdmin (SQL tab) against u779448677_postpilot.

ALTER TABLE users
  ADD COLUMN trial_ends_at DATETIME NULL AFTER plan;

-- Accounts that already exist start their seven days from today rather than
-- from their signup date. Backdating would expire everyone the moment this
-- runs, which is not a fair way to introduce a limit that did not exist when
-- they registered.
UPDATE users
   SET trial_ends_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY)
 WHERE trial_ends_at IS NULL;

-- New signups default to the trial; existing ones that were never on a plan
-- move onto it too. Administrators are never limited regardless of plan.
UPDATE users SET plan = 'trial' WHERE plan IS NULL OR plan = '' OR plan = 'free';
