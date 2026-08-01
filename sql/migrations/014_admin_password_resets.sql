SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_password_resets_lookup (token_hash, used_at, expires_at),
  CONSTRAINT fk_admin_password_resets_admin
    FOREIGN KEY (admin_user_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
