-- Add user_saved_filters table for named transaction filters (2025-12-26)
-- Target DB: budget_lillard_app

USE `budget_lillard_app`;

CREATE TABLE IF NOT EXISTS `user_saved_filters` (
  `username` VARCHAR(190) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `filters` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`username`, `name`),
  KEY `idx_user_saved_filters_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
