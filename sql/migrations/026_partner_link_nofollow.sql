SET NAMES utf8mb4;

SET @partner_sites_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'partner_sites'
);

SET @rel_nofollow_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'partner_sites'
    AND COLUMN_NAME = 'rel_nofollow'
);

SET @sql := IF(
  @partner_sites_exists > 0 AND @rel_nofollow_exists = 0,
  'ALTER TABLE partner_sites ADD COLUMN rel_nofollow TINYINT(1) NOT NULL DEFAULT 0 AFTER show_link',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

