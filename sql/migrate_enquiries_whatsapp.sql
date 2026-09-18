-- Optional extras for enquiries. Safe-ish to re-run (ignore duplicate column/index errors).
-- Core columns already exist: name, phone, source, message, status (new/contacted/converted/closed).

ALTER TABLE `enquiries` ADD COLUMN `age_group` varchar(16) DEFAULT NULL AFTER `name`;
ALTER TABLE `enquiries` ADD INDEX `idx_enquiries_phone` (`phone`);
ALTER TABLE `enquiries` ADD INDEX `idx_enquiries_source` (`source`);
