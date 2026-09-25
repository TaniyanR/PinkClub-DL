SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS item_tombstones (
  item_id INT UNSIGNED PRIMARY KEY,
  content_id VARCHAR(255) NOT NULL,
  reason VARCHAR(500) NOT NULL,
  removed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tombstone_content (content_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS indexnow_queue (
  url_hash CHAR(64) PRIMARY KEY,
  url TEXT NOT NULL,
  origin VARCHAR(255) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_status INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_indexnow_due (origin, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS indexnow_item_state (
  item_id INT UNSIGNED PRIMARY KEY,
  fingerprint CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='page_views' AND INDEX_NAME='idx_page_views_date_item');
SET @idx_sql := IF(@idx_exists=0, 'CREATE INDEX idx_page_views_date_item ON page_views(viewed_at,item_id)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='item_out_click_daily' AND INDEX_NAME='idx_item_out_clicked_item');
SET @idx_sql := IF(@idx_exists=0, 'CREATE INDEX idx_item_out_clicked_item ON item_out_click_daily(clicked_at,item_id)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql;
EXECUTE idx_stmt;
DEALLOCATE PREPARE idx_stmt;
