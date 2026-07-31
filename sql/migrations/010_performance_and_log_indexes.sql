SET NAMES utf8mb4;
SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('release_date','id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND INDEX_NAME = 'idx_items_release_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0, 'CREATE INDEX idx_items_release_id ON items(release_date, id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('date_published','id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND INDEX_NAME = 'idx_items_published_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0, 'CREATE INDEX idx_items_published_id ON items(date_published, id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'in_logs');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'in_logs' AND COLUMN_NAME IN ('created_at'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'in_logs' AND INDEX_NAME = 'idx_in_logs_created_at');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0, 'CREATE INDEX idx_in_logs_created_at ON in_logs(created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events' AND COLUMN_NAME IN ('event_type','session_id_hash','created_at'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events' AND INDEX_NAME = 'idx_site_events_type_session_date');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 3 AND @index_exists = 0, 'CREATE INDEX idx_site_events_type_session_date ON site_events(event_type, session_id_hash, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs' AND COLUMN_NAME IN ('created_at','target_url'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs' AND INDEX_NAME = 'idx_out_logs_date_target');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0, 'CREATE INDEX idx_out_logs_date_target ON out_logs(created_at, target_url(160))', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views' AND COLUMN_NAME IN ('viewed_at'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views' AND INDEX_NAME = 'idx_page_views_viewed_at');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0, 'CREATE INDEX idx_page_views_viewed_at ON page_views(viewed_at)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_sessions');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_sessions' AND COLUMN_NAME IN ('stat_date'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visit_sessions' AND INDEX_NAME = 'idx_visit_sessions_stat_date');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0, 'CREATE INDEX idx_visit_sessions_stat_date ON visit_sessions(stat_date)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
