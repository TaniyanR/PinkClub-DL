-- Ensure mutual_links has required columns/indexes for reciprocal-link workflow (MySQL/MariaDB idempotent)

SET @table_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links'
);

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND COLUMN_NAME = 'status'
        ),
        'SELECT 1',
        'ALTER TABLE mutual_links ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''pending'''
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND COLUMN_NAME = 'is_enabled'
        ),
        'SELECT 1',
        'ALTER TABLE mutual_links ADD COLUMN is_enabled TINYINT(1) NOT NULL DEFAULT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND COLUMN_NAME = 'display_order'
        ),
        'SELECT 1',
        'ALTER TABLE mutual_links ADD COLUMN display_order INT NOT NULL DEFAULT 100'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND COLUMN_NAME = 'approved_at'
        ),
        'SELECT 1',
        'ALTER TABLE mutual_links ADD COLUMN approved_at DATETIME NULL'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND COLUMN_NAME = 'created_at'
        ),
        'SELECT 1',
        'ALTER TABLE mutual_links ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND COLUMN_NAME = 'updated_at'
        ),
        'SELECT 1',
        'ALTER TABLE mutual_links ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND INDEX_NAME = 'idx_mutual_links_status'
        ),
        'SELECT 1',
        'CREATE INDEX idx_mutual_links_status ON mutual_links(status)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @table_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mutual_links' AND INDEX_NAME = 'idx_mutual_links_status_enabled_order'
        ),
        'SELECT 1',
        'CREATE INDEX idx_mutual_links_status_enabled_order ON mutual_links(status, is_enabled, display_order, id)'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
