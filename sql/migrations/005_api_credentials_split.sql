SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS api_credentials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  api_type VARCHAR(32) NOT NULL,
  api_id VARCHAR(255) NOT NULL DEFAULT '',
  affiliate_id VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_api_credentials_type (api_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO api_credentials (api_type, api_id, affiliate_id, created_at, updated_at)
SELECT 'items',
       COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'duga_api_id' LIMIT 1), ''),
       COALESCE((SELECT setting_value FROM settings WHERE setting_key = 'duga_affiliate_id' LIMIT 1), ''),
       NOW(), NOW()
ON DUPLICATE KEY UPDATE updated_at = updated_at;
