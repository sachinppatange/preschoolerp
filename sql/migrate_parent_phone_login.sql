-- =============================================================================
-- Migration: parent login by 10-digit mobile (siblings share one Parent Portal login)
-- File: sql/migrate_parent_phone_login.sql
-- Run once on live + local in phpMyAdmin (or mysql client).
-- Safe-ish to re-run: ADD COLUMN / ADD INDEX are guarded with procedure checks.
-- =============================================================================

START TRANSACTION;

-- 1) Last-10 mobile on users (used to match 9876543210 and 919876543210)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone_last10'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `phone_last10` CHAR(10) NULL DEFAULT NULL AFTER `phone`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `users`
SET `phone_last10` = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(`phone`, ''), '+', ''), ' ', ''), '-', ''), CHAR(9), ''), 10)
WHERE `phone` IS NOT NULL AND `phone` <> '';

UPDATE `users`
SET `phone_last10` = NULL
WHERE `phone_last10` IS NULL OR CHAR_LENGTH(`phone_last10`) <> 10 OR `phone_last10` NOT REGEXP '^[0-9]{10}$';

-- 2) Merge duplicate parent accounts that share the same last-10 digits (keep lowest id)
DROP TEMPORARY TABLE IF EXISTS `tmp_keep_parent`;
CREATE TEMPORARY TABLE `tmp_keep_parent` (
  `phone_last10` CHAR(10) NOT NULL PRIMARY KEY,
  `keep_id` INT UNSIGNED NOT NULL
) ENGINE=Memory;

INSERT INTO `tmp_keep_parent` (`phone_last10`, `keep_id`)
SELECT `phone_last10`, MIN(`id`)
FROM `users`
WHERE `role` = 'parent' AND `phone_last10` IS NOT NULL
GROUP BY `phone_last10`
HAVING COUNT(*) > 1;

UPDATE `students` s
INNER JOIN `users` u ON u.`id` = s.`parent_id` AND u.`role` = 'parent'
INNER JOIN `tmp_keep_parent` k ON k.`phone_last10` = u.`phone_last10`
SET s.`parent_id` = k.`keep_id`
WHERE s.`parent_id` <> k.`keep_id`;

UPDATE `parents_children` pc
INNER JOIN `users` u ON u.`id` = pc.`parent_user_id` AND u.`role` = 'parent'
INNER JOIN `tmp_keep_parent` k ON k.`phone_last10` = u.`phone_last10`
SET pc.`parent_user_id` = k.`keep_id`
WHERE pc.`parent_user_id` <> k.`keep_id`;

DELETE pc1 FROM `parents_children` pc1
INNER JOIN `parents_children` pc2
  ON pc1.`parent_user_id` = pc2.`parent_user_id`
 AND pc1.`child_student_id` = pc2.`child_student_id`
 AND pc1.`id` > pc2.`id`;

DELETE u FROM `users` u
INNER JOIN `tmp_keep_parent` k ON k.`phone_last10` = u.`phone_last10`
WHERE u.`role` = 'parent' AND u.`id` <> k.`keep_id`;

DROP TEMPORARY TABLE IF EXISTS `tmp_keep_parent`;

-- 3) Backfill parents_children from students.parent_id
INSERT INTO `parents_children` (`parent_user_id`, `child_student_id`, `relation`, `created_at`)
SELECT s.`parent_id`, s.`id`, 'parent', NOW()
FROM `students` s
WHERE s.`parent_id` IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `parents_children` pc
    WHERE pc.`parent_user_id` = s.`parent_id` AND pc.`child_student_id` = s.`id`
  );

-- 4) Unique: one parent login per last-10 mobile
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_role_phone10'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE `users` ADD UNIQUE KEY `uq_users_role_phone10` (`role`, `phone_last10`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
