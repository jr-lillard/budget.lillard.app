-- Align production schema with current development schema (2025-12-21)
-- Target DB: budget_lillard_app
-- NOTE: Drops initiated/mailed/settled date columns from transactions.

USE `budget_lillard_app`;

SET @schema := DATABASE();
SET @default_owner := 'jr@lillard.org';
SET @login_user := 'jr@lillard.org';
SET @login_phone := '+16202666114';
SET @login_hash := '$2y$10$QyMj7wPvcDodE6bXmzcb1OcbJJ9cyaRRNhwTGzeR4blkuGUzrQcBe';

-- accounts.is_client
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `accounts` ADD COLUMN `is_client` TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'is_client'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.phone
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(32) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure login user exists with SMS phone
INSERT INTO `users` (`username`, `password_hash`, `phone`)
VALUES (@login_user, @login_hash, @login_phone)
ON DUPLICATE KEY UPDATE `phone` = VALUES(`phone`);

-- reminders.owner
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `reminders` ADD COLUMN `owner` VARCHAR(190) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'owner'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'SELECT 1',
    CONCAT('UPDATE `reminders` SET `owner` = ', QUOTE(@default_owner), ' WHERE `owner` IS NULL OR `owner` = ''''')
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'reminders' AND COLUMN_NAME = 'owner'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- transactions.owner
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE `transactions` ADD COLUMN `owner` VARCHAR(190) NULL',
    'SELECT 1'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'owner'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'SELECT 1',
    CONCAT('UPDATE `transactions` SET `owner` = ', QUOTE(@default_owner), ' WHERE `owner` IS NULL OR `owner` = ''''')
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'owner'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop unused lifecycle columns from transactions
SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'SELECT 1',
    'ALTER TABLE `transactions` DROP COLUMN `initiated_date`'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'initiated_date'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'SELECT 1',
    'ALTER TABLE `transactions` DROP COLUMN `mailed_date`'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'mailed_date'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    COUNT(*) = 0,
    'SELECT 1',
    'ALTER TABLE `transactions` DROP COLUMN `settled_date`'
  )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @schema AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'settled_date'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
