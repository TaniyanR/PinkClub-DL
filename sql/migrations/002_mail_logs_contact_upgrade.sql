SET @mail_logs_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs'
);

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'direction'
        ),
        'SELECT 1',
        'ALTER TABLE mail_logs ADD COLUMN direction ENUM(''in'',''out'') NOT NULL DEFAULT ''in'' AFTER id'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'from_name'
        ),
        'SELECT 1',
        'ALTER TABLE mail_logs ADD COLUMN from_name VARCHAR(255) DEFAULT NULL AFTER direction'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'to_email'
        ),
        'SELECT 1',
        'ALTER TABLE mail_logs ADD COLUMN to_email VARCHAR(255) DEFAULT NULL AFTER from_email'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'status'
        ),
        'SELECT 1',
        'ALTER TABLE mail_logs ADD COLUMN status ENUM(''received'',''sent'',''failed'') NOT NULL DEFAULT ''received'' AFTER body'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'last_error'
        ),
        'SELECT 1',
        'ALTER TABLE mail_logs ADD COLUMN last_error TEXT DEFAULT NULL AFTER status'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'updated_at'
        ),
        'SELECT 1',
        'ALTER TABLE mail_logs ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'from_email'
        ),
        'ALTER TABLE mail_logs MODIFY COLUMN from_email VARCHAR(255) NULL',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @mail_logs_exists = 0,
    'SELECT 1',
    IF(
        EXISTS(
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mail_logs' AND COLUMN_NAME = 'sent_ok'
        ),
        'UPDATE mail_logs SET status = CASE WHEN sent_ok = 1 THEN ''sent'' ELSE ''failed'' END, last_error = error_message WHERE (status IS NULL OR status = ''received'') AND (sent_ok IS NOT NULL OR error_message IS NOT NULL)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
