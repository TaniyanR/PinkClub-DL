SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS item_out_click_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  click_date DATE NOT NULL,
  visitor_hash CHAR(64) NOT NULL,
  clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_item_out_click_daily (item_id, click_date, visitor_hash),
  INDEX idx_item_out_click_daily_date_item (click_date, item_id),
  CONSTRAINT fk_item_out_click_daily_item
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
