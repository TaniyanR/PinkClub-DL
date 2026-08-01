SET NAMES utf8mb4;

-- 公開ページのデザイン・表示件数・並び順・機能を変えず、
-- 商品詳細／商品一覧／女優・ジャンル・メーカー・シリーズ・作者一覧で使う検索を高速化する。
-- 同じ列構成のインデックスが存在する場合は追加しない。

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND COLUMN_NAME IN ('item_source','release_date','id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'item_source,release_date,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 3 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_items_source_release_id ON items(item_source, release_date, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses' AND COLUMN_NAME IN ('name','id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'actresses'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'name,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_actresses_name_id ON actresses(name, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres' AND COLUMN_NAME IN ('name','id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'genres'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'name,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_genres_name_id ON genres(name, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers' AND COLUMN_NAME IN ('name','id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'makers'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'name,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_makers_name_id ON makers(name, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_master');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_master');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_master' AND COLUMN_NAME IN ('name','id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'series_master'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'name,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_series_master_name_id ON series_master(name, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authors');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authors');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authors' AND COLUMN_NAME IN ('name','id'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'authors'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'name,id');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_authors_name_id ON authors(name, id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses' AND COLUMN_NAME IN ('content_id','actress_name'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_actresses'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id,actress_name');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_item_actresses_content_name ON item_actresses(content_id, actress_name)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres' AND COLUMN_NAME IN ('content_id','genre_name'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_genres'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id,genre_name');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_item_genres_content_name ON item_genres(content_id, genre_name)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers' AND COLUMN_NAME IN ('content_id','maker_name'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_makers'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id,maker_name');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_item_makers_content_name ON item_makers(content_id, maker_name)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series' AND COLUMN_NAME IN ('content_id','series_name'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_series'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id,series_name');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_item_series_content_name ON item_series(content_id, series_name)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_authors');
SET @key_count := (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_authors');
SET @columns_exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_authors' AND COLUMN_NAME IN ('content_id','author_name'));
SET @same_index_exists := (SELECT COUNT(*) FROM (
  SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_authors'
  GROUP BY INDEX_NAME
) s WHERE s.cols = 'content_id,author_name');
SET @sql := IF(@table_exists > 0 AND @columns_exist = 2 AND @same_index_exists = 0 AND @key_count < 64,
  'CREATE INDEX idx_item_authors_content_name ON item_authors(content_id, author_name)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
