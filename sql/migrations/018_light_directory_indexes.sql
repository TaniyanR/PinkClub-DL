SET NAMES utf8mb4;

-- PinkClub-DLの各名称一覧を、商品API由来の関連テーブルから軽量に作るための索引。

SET @table_name := 'item_actresses';
SET @index_name := 'idx_light_actress_name_item';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(actress_name(100), item_id)'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_genres';
SET @index_name := 'idx_light_genre_name_item';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(genre_name(100), item_id)'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_makers';
SET @index_name := 'idx_light_maker_name_item';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(maker_name(100), item_id)'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_labels';
SET @index_name := 'idx_light_label_name_item';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(label_name(100), item_id)'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @table_name := 'item_series';
SET @index_name := 'idx_light_series_name_item';
SET @exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @table_name AND INDEX_NAME = @index_name);
SET @sql := IF(@exists = 0, CONCAT('CREATE INDEX ', @index_name, ' ON ', @table_name, '(series_name(100), item_id)'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
