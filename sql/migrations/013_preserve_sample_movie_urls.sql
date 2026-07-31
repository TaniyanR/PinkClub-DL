-- 商品再同期時にDUGA APIが一時的に動画URLを返さなかった場合でも、
-- 既に保存済みのサンプル動画URLを消さない。
-- 新しい有効なURLが返された場合は従来どおり更新する。

DROP TRIGGER IF EXISTS trg_items_preserve_sample_movie_urls;

CREATE TRIGGER trg_items_preserve_sample_movie_urls
BEFORE UPDATE ON items
FOR EACH ROW
SET
    NEW.sample_movie_url_476 = CASE
        WHEN NULLIF(TRIM(COALESCE(NEW.sample_movie_url_476, '')), '') IS NULL
            THEN OLD.sample_movie_url_476
        ELSE NEW.sample_movie_url_476
    END,
    NEW.sample_movie_url_560 = CASE
        WHEN NULLIF(TRIM(COALESCE(NEW.sample_movie_url_560, '')), '') IS NULL
            THEN OLD.sample_movie_url_560
        ELSE NEW.sample_movie_url_560
    END,
    NEW.sample_movie_url_644 = CASE
        WHEN NULLIF(TRIM(COALESCE(NEW.sample_movie_url_644, '')), '') IS NULL
            THEN OLD.sample_movie_url_644
        ELSE NEW.sample_movie_url_644
    END,
    NEW.sample_movie_url_720 = CASE
        WHEN NULLIF(TRIM(COALESCE(NEW.sample_movie_url_720, '')), '') IS NULL
            THEN OLD.sample_movie_url_720
        ELSE NEW.sample_movie_url_720
    END;
