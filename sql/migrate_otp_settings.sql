-- =============================================================================
-- OTP send log (settings themselves live in schools.settings JSON under key "otp")
-- File: sql/migrate_otp_settings.sql
-- Safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `otp_send_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `channel` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `to_addr` varchar(191) NOT NULL DEFAULT '',
  `message` varchar(500) DEFAULT NULL,
  `http_code` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_school_created` (`school_id`, `created_at`),
  KEY `idx_channel` (`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
