SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS rss_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  feed_url VARCHAR(1000) NOT NULL,
  source_type VARCHAR(32) NOT NULL DEFAULT 'general',
  source_ref_id BIGINT UNSIGNED NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_fetched_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rss_source_feed (feed_url),
  INDEX idx_rss_sources_type_ref (source_type, source_ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rss_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL,
  published_at DATETIME NULL,
  summary TEXT NULL,
  guid VARCHAR(500) NOT NULL,
  image_url TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rss_guid (source_id, guid),
  INDEX idx_rss_pub (published_at),
  CONSTRAINT fk_rss_items_source FOREIGN KEY (source_id) REFERENCES rss_sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE partner_rss ADD COLUMN IF NOT EXISTS show_rss TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE rss_sources ADD COLUMN IF NOT EXISTS source_type VARCHAR(32) NOT NULL DEFAULT 'general' AFTER feed_url;
ALTER TABLE rss_sources ADD COLUMN IF NOT EXISTS source_ref_id BIGINT UNSIGNED NULL AFTER source_type;

SET @idx_rss_sources_type_ref_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rss_sources' AND INDEX_NAME = 'idx_rss_sources_type_ref'
);
SET @sql := IF(
  @idx_rss_sources_type_ref_exists = 0,
  'CREATE INDEX idx_rss_sources_type_ref ON rss_sources(source_type, source_ref_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE rss_sources rs
INNER JOIN partner_rss pr ON pr.feed_url = rs.feed_url
SET rs.source_type = 'partner_link',
    rs.source_ref_id = pr.id,
    rs.is_enabled = COALESCE(pr.show_rss, pr.is_enabled, 1),
    rs.updated_at = NOW();
