CREATE TABLE IF NOT EXISTS analytics_page_engagement (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_key CHAR(64) NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  visitor_hash CHAR(64) NOT NULL,
  path VARCHAR(255) NOT NULL,
  duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  max_scroll_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_analytics_engagement_event_key (event_key),
  KEY idx_analytics_engagement_viewed_at (viewed_at),
  KEY idx_analytics_engagement_visitor_date (visitor_hash, viewed_at),
  KEY idx_analytics_engagement_path_date (path(160), viewed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'in_logs');
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'in_logs' AND INDEX_NAME = 'idx_in_logs_created_host');
SET @sql := IF(@table_exists > 0 AND @index_exists = 0,
  'CREATE INDEX idx_in_logs_created_host ON in_logs(created_at, referer_host)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs');
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs' AND INDEX_NAME = 'idx_out_logs_created_target');
SET @sql := IF(@table_exists > 0 AND @index_exists = 0,
  'CREATE INDEX idx_out_logs_created_target ON out_logs(created_at, target_url(190))',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events');
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events' AND INDEX_NAME = 'idx_site_events_type_created_ip');
SET @sql := IF(@table_exists > 0 AND @index_exists = 0,
  'CREATE INDEX idx_site_events_type_created_ip ON site_events(event_type, created_at, ip_hash)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
