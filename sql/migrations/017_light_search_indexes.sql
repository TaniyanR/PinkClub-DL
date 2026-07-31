SET NAMES utf8mb4;

-- PinkClub-FDの共通検索は商品IDから関連名を照合する。
-- 各関連テーブルに item_id 先頭の索引を用意し、商品ごとのEXISTS検索を軽量化する。

SET @table_name := 'item_actresses';
SET @index_name := 'idx_light_actress_item_name';
SET @column_name := 'actress_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_genres';
SET @index_name := 'idx_light_genre_item_name';
SET @column_name := 'genre_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_makers';
SET @index_name := 'idx_light_maker_item_name';
SET @column_name := 'maker_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_labels';
SET @index_name := 'idx_light_label_item_name';
SET @column_name := 'label_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_series';
SET @index_name := 'idx_light_series_item_name';
SET @column_name := 'series_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_directors';
SET @index_name := 'idx_light_director_item_name';
SET @column_name := 'director_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_authors';
SET @index_name := 'idx_light_author_item_name';
SET @column_name := 'author_name';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(item_id, ', @column_name, '(100))'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
