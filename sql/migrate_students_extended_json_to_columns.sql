-- =============================================================================
-- Migration: copy students.extended_json into real table columns
-- File: sql/migrate_students_extended_json_to_columns.sql
-- Run once on live + local in phpMyAdmin (or mysql client).
-- Safe to re-run: ADD COLUMN is guarded; UPDATE only fills empty columns.
-- =============================================================================

START TRANSACTION;

-- 1) Gender (only stored in JSON today)
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'gender'
);
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE `students` ADD COLUMN `gender` VARCHAR(20) NULL DEFAULT NULL AFTER `dob`',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helper: JSON text, treat JSON null / empty as NULL
-- MariaDB: JSON_UNQUOTE(JSON_EXTRACT(col, path))

UPDATE `students`
SET
  `gender` = COALESCE(NULLIF(TRIM(IFNULL(`gender`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.student.gender')), '')), ''), `gender`),
  `form_no` = COALESCE(NULLIF(TRIM(IFNULL(`form_no`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.form_no')), '')), ''), `form_no`),
  `location` = COALESCE(NULLIF(TRIM(IFNULL(`location`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.location')), '')), ''), `location`),
  `academic_year` = COALESCE(NULLIF(TRIM(IFNULL(`academic_year`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.academic_year')), '')), ''), `academic_year`),
  `middle_name` = COALESCE(NULLIF(TRIM(IFNULL(`middle_name`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.student.middle')), '')), ''), `middle_name`),
  `place_of_birth` = COALESCE(NULLIF(TRIM(IFNULL(`place_of_birth`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.place_of_birth')), '')), ''), `place_of_birth`),
  `nationality` = COALESCE(NULLIF(TRIM(IFNULL(`nationality`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.nationality')), '')), ''), `nationality`),
  `caste` = COALESCE(NULLIF(TRIM(IFNULL(`caste`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.caste')), '')), ''), `caste`),
  `languages` = COALESCE(NULLIF(TRIM(IFNULL(`languages`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.languages')), '')), ''), `languages`),
  `address` = COALESCE(NULLIF(TRIM(IFNULL(`address`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.address.address')), '')), ''), `address`),
  `city` = COALESCE(NULLIF(TRIM(IFNULL(`city`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.address.city')), '')), ''), `city`),
  `state` = COALESCE(NULLIF(TRIM(IFNULL(`state`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.address.state')), '')), ''), `state`),
  `country` = COALESCE(NULLIF(TRIM(IFNULL(`country`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.address.country')), '')), ''), `country`),
  `pin` = COALESCE(NULLIF(TRIM(IFNULL(`pin`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.address.pin')), '')), ''), `pin`),
  `father_first` = COALESCE(NULLIF(TRIM(IFNULL(`father_first`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.first')), '')), ''), `father_first`),
  `father_middle` = COALESCE(NULLIF(TRIM(IFNULL(`father_middle`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.middle')), '')), ''), `father_middle`),
  `father_last` = COALESCE(NULLIF(TRIM(IFNULL(`father_last`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.last')), '')), ''), `father_last`),
  `father_email` = COALESCE(NULLIF(TRIM(IFNULL(`father_email`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.email')), '')), ''), `father_email`),
  `father_phone` = COALESCE(NULLIF(TRIM(IFNULL(`father_phone`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.phone')), '')), ''), `father_phone`),
  `father_edu` = COALESCE(NULLIF(TRIM(IFNULL(`father_edu`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.edu')), '')), ''), `father_edu`),
  `father_prof` = COALESCE(NULLIF(TRIM(IFNULL(`father_prof`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.prof')), '')), ''), `father_prof`),
  `father_designation` = COALESCE(NULLIF(TRIM(IFNULL(`father_designation`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.father.designation')), '')), ''), `father_designation`),
  `mother_first` = COALESCE(NULLIF(TRIM(IFNULL(`mother_first`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.first')), '')), ''), `mother_first`),
  `mother_middle` = COALESCE(NULLIF(TRIM(IFNULL(`mother_middle`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.middle')), '')), ''), `mother_middle`),
  `mother_last` = COALESCE(NULLIF(TRIM(IFNULL(`mother_last`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.last')), '')), ''), `mother_last`),
  `mother_email` = COALESCE(NULLIF(TRIM(IFNULL(`mother_email`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.email')), '')), ''), `mother_email`),
  `mother_phone` = COALESCE(NULLIF(TRIM(IFNULL(`mother_phone`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.phone')), '')), ''), `mother_phone`),
  `mother_edu` = COALESCE(NULLIF(TRIM(IFNULL(`mother_edu`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.edu')), '')), ''), `mother_edu`),
  `mother_prof` = COALESCE(NULLIF(TRIM(IFNULL(`mother_prof`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.prof')), '')), ''), `mother_prof`),
  `mother_designation` = COALESCE(NULLIF(TRIM(IFNULL(`mother_designation`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.mother.designation')), '')), ''), `mother_designation`),
  `guardian_name` = COALESCE(NULLIF(TRIM(IFNULL(`guardian_name`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.guardian.name')), '')), ''), `guardian_name`),
  `guardian_email` = COALESCE(NULLIF(TRIM(IFNULL(`guardian_email`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.guardian.email')), '')), ''), `guardian_email`),
  `guardian_relation` = COALESCE(NULLIF(TRIM(IFNULL(`guardian_relation`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.guardian.relation')), '')), ''), `guardian_relation`),
  `guardian_phone` = COALESCE(NULLIF(TRIM(IFNULL(`guardian_phone`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.guardian.phone')), '')), ''), `guardian_phone`),
  `previous_school` = COALESCE(NULLIF(TRIM(IFNULL(`previous_school`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.previous_school')), '')), ''), `previous_school`),
  `allergies` = COALESCE(NULLIF(TRIM(IFNULL(`allergies`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.medical.allergies')), '')), ''), `allergies`),
  `health_conditions` = COALESCE(NULLIF(TRIM(IFNULL(`health_conditions`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.medical.health_conditions')), '')), ''), `health_conditions`),
  `current_medications` = COALESCE(NULLIF(TRIM(IFNULL(`current_medications`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.medical.current_medications')), '')), ''), `current_medications`),
  `immunization_records` = COALESCE(NULLIF(TRIM(IFNULL(`immunization_records`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.medical.immunization_records')), '')), ''), `immunization_records`),
  `sibling1` = COALESCE(NULLIF(TRIM(IFNULL(`sibling1`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.siblings."1"')), '')), ''), `sibling1`),
  `sibling2` = COALESCE(NULLIF(TRIM(IFNULL(`sibling2`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.siblings."2"')), '')), ''), `sibling2`),
  `additional_info` = COALESCE(NULLIF(TRIM(IFNULL(`additional_info`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.additional_info')), '')), ''), `additional_info`),
  `parent_signature` = COALESCE(NULLIF(TRIM(IFNULL(`parent_signature`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.parent_signature')), '')), ''), `parent_signature`),
  `total_fees` = COALESCE(`total_fees`, CAST(NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.fees.total')), '')), '') AS DECIMAL(10,2))),
  `installment1` = COALESCE(`installment1`, CAST(NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.fees.installments[0]')), '')), '') AS DECIMAL(10,2))),
  `installment2` = COALESCE(`installment2`, CAST(NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.fees.installments[1]')), '')), '') AS DECIMAL(10,2))),
  `installment3` = COALESCE(`installment3`, CAST(NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.fees.installments[2]')), '')), '') AS DECIMAL(10,2))),
  `remark` = COALESCE(NULLIF(TRIM(IFNULL(`remark`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.fees.remark')), '')), ''), `remark`),
  `stamp` = COALESCE(NULLIF(TRIM(IFNULL(`stamp`, '')), ''), NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.fees.stamp')), '')), ''), `stamp`)
WHERE `extended_json` IS NOT NULL
  AND TRIM(`extended_json`) <> ''
  AND JSON_VALID(`extended_json`);

-- Fill class_id from admission_seeking_in when class_id is empty
UPDATE `students`
SET `class_id` = CAST(NULLIF(TRIM(IFNULL(JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.admission_seeking_in')), '')), '') AS UNSIGNED)
WHERE (`class_id` IS NULL OR `class_id` = 0)
  AND `extended_json` IS NOT NULL
  AND JSON_VALID(`extended_json`)
  AND JSON_UNQUOTE(JSON_EXTRACT(`extended_json`, '$.admission_seeking_in')) REGEXP '^[0-9]+$';

COMMIT;
