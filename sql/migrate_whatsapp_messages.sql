-- WhatsApp Cloud API inbox (incoming / outgoing / autobot)
-- Safe to re-run. Also created automatically by includes/whatsapp_inbox.php.

CREATE TABLE IF NOT EXISTS `whatsapp_messages` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_id` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `direction` enum('in','out') NOT NULL,
  `phone` varchar(32) NOT NULL,
  `wa_message_id` varchar(128) DEFAULT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'text',
  `body` text DEFAULT NULL,
  `status` varchar(32) DEFAULT NULL,
  `is_bot` tinyint(1) NOT NULL DEFAULT 0,
  `raw_json` mediumtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_message_id` (`wa_message_id`),
  KEY `idx_wa_phone` (`phone`,`created_at`),
  KEY `idx_wa_dir` (`direction`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
