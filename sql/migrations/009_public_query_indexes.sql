SET NAMES utf8mb4;
SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs' AND COLUMN_NAME IN ('created_at'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs' AND INDEX_NAME = 'idx_out_logs_created_at');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'out_logs'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'created_at');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_out_logs_created_at ON out_logs(created_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views' AND COLUMN_NAME IN ('item_id', 'ip_hash', 'viewed_at'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views' AND INDEX_NAME = 'idx_page_views_item_ip_date');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'item_id,ip_hash,viewed_at');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 3 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_page_views_item_ip_date ON page_views(item_id, ip_hash, viewed_at)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('view_count', 'id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND INDEX_NAME = 'idx_items_view_count');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'view_count,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_items_view_count ON items(view_count, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND COLUMN_NAME IN ('actress_id', 'content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND INDEX_NAME = 'idx_item_actresses_actress_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'actress_id,content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_actresses_actress_content ON item_actresses(actress_id, content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND COLUMN_NAME IN ('content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND INDEX_NAME = 'idx_item_actresses_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_actresses_content ON item_actresses(content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND COLUMN_NAME IN ('duga_id', 'item_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND INDEX_NAME = 'idx_item_actresses_duga_item');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'duga_id,item_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_actresses_duga_item ON item_actresses(duga_id, item_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND COLUMN_NAME IN ('genre_id', 'content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND INDEX_NAME = 'idx_item_genres_genre_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'genre_id,content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_genres_genre_content ON item_genres(genre_id, content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND COLUMN_NAME IN ('content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND INDEX_NAME = 'idx_item_genres_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_genres_content ON item_genres(content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND COLUMN_NAME IN ('duga_id', 'item_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND INDEX_NAME = 'idx_item_genres_duga_item');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'duga_id,item_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_genres_duga_item ON item_genres(duga_id, item_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND COLUMN_NAME IN ('maker_id', 'content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND INDEX_NAME = 'idx_item_makers_maker_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'maker_id,content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_makers_maker_content ON item_makers(maker_id, content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND COLUMN_NAME IN ('content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND INDEX_NAME = 'idx_item_makers_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_makers_content ON item_makers(content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND COLUMN_NAME IN ('duga_id', 'item_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND INDEX_NAME = 'idx_item_makers_duga_item');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'duga_id,item_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_makers_duga_item ON item_makers(duga_id, item_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND COLUMN_NAME IN ('series_id', 'content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND INDEX_NAME = 'idx_item_series_series_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'series_id,content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_series_series_content ON item_series(series_id, content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND COLUMN_NAME IN ('content_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND INDEX_NAME = 'idx_item_series_content');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_series_content ON item_series(content_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND COLUMN_NAME IN ('duga_id', 'item_id'));
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND INDEX_NAME = 'idx_item_series_duga_item');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'duga_id,item_id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @index_exists = 0 AND @same_index_exists = 0,
  'CREATE INDEX idx_item_series_duga_item ON item_series(duga_id, item_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
