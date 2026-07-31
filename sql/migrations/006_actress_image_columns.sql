SET NAMES utf8mb4;
ALTER TABLE actresses ADD COLUMN IF NOT EXISTS image_small TEXT NULL AFTER image_url;
ALTER TABLE actresses ADD COLUMN IF NOT EXISTS image_large TEXT NULL AFTER image_small;
