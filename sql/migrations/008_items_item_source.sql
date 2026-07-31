SET NAMES utf8mb4;

ALTER TABLE items ADD COLUMN IF NOT EXISTS item_source VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER product_id;

SET @idx_items_item_source_release_exists := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'items' AND INDEX_NAME = 'idx_items_item_source_release'
);
SET @sql := IF(
  @idx_items_item_source_release_exists = 0,
  'CREATE INDEX idx_items_item_source_release ON items(item_source, release_date, id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE items
SET item_source = 'duga_product'
WHERE item_source <> 'duga_product'
  AND (
    TRIM(COALESCE(affiliate_url, '')) <> ''
    OR TRIM(COALESCE(service_code, '')) <> ''
    OR TRIM(COALESCE(floor_code, '')) <> ''
    OR TRIM(COALESCE(sample_movie_url_476, '')) <> ''
    OR TRIM(COALESCE(sample_movie_url_560, '')) <> ''
    OR TRIM(COALESCE(sample_movie_url_644, '')) <> ''
    OR TRIM(COALESCE(sample_movie_url_720, '')) <> ''
    OR raw_json LIKE '%"affiliateURL"%'
    OR raw_json LIKE '%"service_code"%'
    OR raw_json LIKE '%"floor_code"%'
    OR raw_json LIKE '%"sampleMovieURL"%'
  );

UPDATE items i
INNER JOIN rss_items ri ON ri.title = i.title OR ri.url = i.url OR ri.url = i.affiliate_url
INNER JOIN rss_sources rs ON rs.id = ri.source_id AND rs.source_type = 'partner_link'
SET i.item_source = 'partner_rss_item'
WHERE i.item_source <> 'partner_rss_item';
