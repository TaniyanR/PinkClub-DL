SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analytics_page_engagement');
SET @column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analytics_page_engagement' AND COLUMN_NAME = 'event_key');
SET @sql := IF(@table_exists > 0 AND @column_exists = 0,
  'ALTER TABLE analytics_page_engagement ADD COLUMN event_key CHAR(64) NULL AFTER id',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analytics_page_engagement' AND COLUMN_NAME = 'event_key');
SET @sql := IF(@table_exists > 0 AND @column_exists > 0,
  'UPDATE analytics_page_engagement SET event_key = SHA2(CONCAT("legacy|", id, "|", visitor_hash, "|", path, "|", viewed_at), 256) WHERE event_key IS NULL OR event_key = ""',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @nullable := (SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analytics_page_engagement' AND COLUMN_NAME = 'event_key' LIMIT 1);
SET @sql := IF(@table_exists > 0 AND @column_exists > 0 AND @nullable = 'YES',
  'ALTER TABLE analytics_page_engagement MODIFY event_key CHAR(64) NOT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analytics_page_engagement' AND INDEX_NAME = 'uq_analytics_engagement_event_key');
SET @sql := IF(@table_exists > 0 AND @column_exists > 0 AND @index_exists = 0,
  'CREATE UNIQUE INDEX uq_analytics_engagement_event_key ON analytics_page_engagement(event_key)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
