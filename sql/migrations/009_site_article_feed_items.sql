CREATE TABLE IF NOT EXISTS site_article_feed_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  feed_key VARCHAR(32) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  content_id VARCHAR(128) NULL,
  published_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_site_article_feed_item (feed_key, item_id),
  INDEX idx_site_article_feed_published (feed_key, published_at),
  INDEX idx_site_article_feed_item_date (feed_key, item_id, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
