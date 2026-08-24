SET NAMES utf8mb4;

-- If an older installer recreated admin/password after the real administrator
-- had been renamed, keep the personalized account and remove the default one.
DELETE a
FROM admins a
INNER JOIN (
  SELECT MIN(id) AS personalized_id
  FROM admins
  WHERE username <> 'admin'
) personalized ON personalized.personalized_id IS NOT NULL
WHERE a.username = 'admin';

SET @table_exists := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events');
SET @index_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'site_events' AND INDEX_NAME = 'idx_site_events_pv_dedupe');
SET @sql := IF(@table_exists > 0 AND @index_exists = 0,
  'CREATE INDEX idx_site_events_pv_dedupe ON site_events(event_type, session_id_hash, ip_hash, path(160), created_at)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
