SET NAMES utf8mb4;

-- 公開ディレクトリキャッシュの更新検知で MAX(updated_at) を高速に取得する。

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres');
SET @column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres' AND COLUMN_NAME = 'updated_at');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'updated_at');
SET @sql := IF(@table_exists > 0 AND @column_exists = 1 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_genres_updated_at ON genres(updated_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers');
SET @column_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers' AND COLUMN_NAME = 'updated_at');
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'updated_at');
SET @sql := IF(@table_exists > 0 AND @column_exists = 1 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_makers_updated_at ON makers(updated_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
