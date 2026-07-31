SET NAMES utf8mb4;

-- トップページの表示内容・並び順を変えず、既存クエリの検索とソートだけを高速化する。
-- 同じ列構成のインデックスが既に存在する環境では何もしない。

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('release_date', 'id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'release_date,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0,
  'CREATE INDEX idx_items_release_id ON items(release_date, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('release_date', 'updated_at', 'id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'release_date,updated_at,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 3 AND @same_index_exists = 0,
  'CREATE INDEX idx_items_release_updated_id ON items(release_date, updated_at, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('date_published', 'updated_at', 'id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'date_published,updated_at,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 3 AND @same_index_exists = 0,
  'CREATE INDEX idx_items_published_updated_id ON items(date_published, updated_at, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('updated_at', 'id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'updated_at,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0,
  'CREATE INDEX idx_items_updated_id ON items(updated_at, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('view_count', 'release_date', 'id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'view_count,release_date,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 3 AND @same_index_exists = 0,
  'CREATE INDEX idx_items_view_release_id ON items(view_count, release_date, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME IN ('id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 1 AND @same_index_exists = 0,
  'CREATE INDEX idx_actresses_id ON actresses(id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
