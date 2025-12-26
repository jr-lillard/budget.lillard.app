-- Add user_preferences table for persisted UI state (2025-12-26)
-- Target DB: budget_lillard_app

USE `budget_lillard_app`;

CREATE TABLE IF NOT EXISTS `user_preferences` (
  `username` VARCHAR(190) NOT NULL,
  `tx_filters` LONGTEXT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
