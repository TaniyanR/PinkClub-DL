<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * ここでSQLに埋め込む文字列は必ず許可リストで制限する。
 */
function normalize_order(string $value, array $allowed, string $default): string
{
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * テーブル名を受け取る系は必ず許可リストで制限（SQLインジェクション防止）
 */
function normalize_table(string $table, array $allowed): string
{
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Invalid table name');
    }
    return $table;
}

function normalize_int(int $value, int $min, int $max): int
{
    return max($min, min($max, $value));
}

function normalize_content_id(string $contentId): string
{
    $contentId = trim($contentId);

    if ($contentId === '' || strlen($contentId) > 64) {
        return '';
    }

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $contentId)) {
        return '';
    }

    return $contentId;
}

function backfill_master_from_relation(string $masterTable, string $relationTable, string $nameColumn): void
{
    static $backfilled = [];

    $masterTable  = normalize_table($masterTable,  ['actresses', 'genres', 'makers', 'series_master', 'authors']);
    $relationTable = normalize_table($relationTable, ['item_actresses', 'item_genres', 'item_makers', 'item_series', 'item_authors']);
    $nameColumn   = normalize_order($nameColumn, ['actress_name', 'genre_name', 'maker_name', 'series_name', 'author_name'], $nameColumn);
    $cacheKey = $masterTable . ':' . $relationTable . ':' . $nameColumn;
    if (isset($backfilled[$cacheKey])) {
        return;
    }
    $backfilled[$cacheKey] = true;

    $sql = "INSERT INTO {$masterTable}(duga_id,name,created_at,updated_at)
             SELECT
               CASE
                 WHEN TRIM(COALESCE(r.{$nameColumn}, '')) = '' THEN NULL
                 WHEN TRIM(COALESCE(r.duga_id, '')) <> '' THEN TRIM(r.duga_id)
                 ELSE CONCAT('name:', SHA1(LOWER(TRIM(r.{$nameColumn}))))
               END AS mapped_duga_id,
               TRIM(r.{$nameColumn}) AS mapped_name,
               NOW(), NOW()
             FROM {$relationTable} r
             WHERE TRIM(COALESCE(r.{$nameColumn}, '')) <> ''
             ON DUPLICATE KEY UPDATE name=VALUES(name), updated_at=NOW();";

    try {
        db()->exec($sql);
    } catch (Throwable) {
    }
}

function fetch_items(string $orderBy = 'date_published_desc', int $limit = 10, int $offset = 0): array
{
    $allowedOrders = [
        'date_published_desc' => 'release_date DESC, id DESC',
        'date_published_asc'  => 'release_date ASC, id ASC',
        'price_min_desc'      => 'price_min DESC',
        'price_min_asc'       => 'price_min ASC',
        'popularity_desc'     => 'view_count DESC, id DESC',
        'random'              => 'RAND()',
    ];
    if (array_key_exists($orderBy, $allowedOrders)) {
        $orderBySql = $allowedOrders[$orderBy];
    } else {
        $orderBySql = normalize_order($orderBy, array_values($allowedOrders), $allowedOrders['date_published_desc']);
    }

    $limit  = normalize_int($limit, 1, 100);
    $offset = max(0, $offset);

    $sourceWhere = items_product_source_where();
    $whereSql = $sourceWhere !== '' ? ' WHERE ' . $sourceWhere : '';
    $stmt = db()->prepare("SELECT * FROM items{$whereSql} ORDER BY {$orderBySql} LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

function fetch_item_by_content_id(string $contentId): ?array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT * FROM items
         WHERE content_id = :cid
           AND ' . items_front_release_where() . '
         ORDER BY
           CASE
             WHEN title LIKE "%お問い合わせ%" OR title LIKE "%問合せ%" OR title = "Privacy Policy" OR title = "サイトについて" THEN 1
             ELSE 0
           END ASC,
           id DESC
         LIMIT 1'
    );
    $stmt->execute([':cid' => $cid]);
    $item = $stmt->fetch();
    return $item ?: null;
}

function fetch_item_by_cid(string $cid): ?array
{
    return fetch_item_by_content_id($cid);
}

function items_table_exists(string $table): bool
{
    static $cache = [];

    if (!in_array($table, ['items', 'rss_items', 'rss_sources'], true)) {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare('SHOW TABLES LIKE :table_name');
        $stmt->execute([':table_name' => $table]);
        $cache[$table] = (bool)$stmt->fetch(PDO::FETCH_NUM);
        return $cache[$table];
    } catch (Throwable) {
        $cache[$table] = false;
        return false;
    }
}

function items_column_exists(string $column, string $table = 'items'): bool
{
    static $cache = [];

    if (!in_array($table, ['items', 'rss_sources'], true)) {
        return false;
    }
    $cacheKey = $table . '.' . $column;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = db()->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :column');
        $stmt->execute([':column' => $column]);
        $cache[$cacheKey] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $cache[$cacheKey];
    } catch (Throwable) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function ensure_items_item_source_column(): void
{
    if (!items_column_exists('item_source')) {
        db()->exec('ALTER TABLE items ADD COLUMN item_source VARCHAR(32) NOT NULL DEFAULT "unknown" AFTER product_id');
    }
}


function items_front_release_where(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : 'items.';
    return '(' . $prefix . 'release_date IS NULL OR ' . $prefix . 'release_date = "" OR ' . $prefix . 'release_date <= CURDATE())';
}

function items_product_source_where(string $alias = ''): string
{
    static $cache = [];

    if (array_key_exists($alias, $cache)) {
        return $cache[$alias];
    }

    $outerPrefix = $alias !== '' ? $alias : 'items';
    $where = [];

    if (items_column_exists('item_source')) {
        $where[] = $outerPrefix . '.item_source = "duga_product"';
    }

    $where[] = items_front_release_where($outerPrefix);

    if (items_table_exists('rss_items') && items_table_exists('rss_sources') && items_column_exists('source_type', 'rss_sources')) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM rss_items ri INNER JOIN rss_sources rs ON rs.id = ri.source_id WHERE rs.source_type = "partner_link" AND (ri.title = ' . $outerPrefix . '.title OR ri.url = ' . $outerPrefix . '.url OR ri.url = ' . $outerPrefix . '.affiliate_url))';
    }

    $cache[$alias] = implode(' AND ', $where);
    return $cache[$alias];
}

function fetch_actresses(int $limit = 50, int $offset = 0, string $order = 'name'): array
{
    $orderBy = normalize_order($order, ['name', 'created_at', 'updated_at'], 'name');
    $limit   = normalize_int($limit, 1, 200);
    $offset  = max(0, $offset);

    try {
        $stmt = db()->prepare("SELECT * FROM actresses ORDER BY {$orderBy} ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable) {
    }

    backfill_master_from_relation('actresses', 'item_actresses', 'actress_name');

    try {
        $stmt = db()->prepare("SELECT * FROM actresses ORDER BY {$orderBy} ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_public_actresses(int $limit = 10000, int $offset = 0, string $order = 'name'): array
{
    $orderBy = normalize_order($order, ['name', 'created_at', 'updated_at'], 'name');
    $limit = normalize_int($limit, 1, 10000);
    $offset = max(0, $offset);

    try {
        $stmt = db()->prepare(
            "SELECT actresses.*
             FROM actresses
             WHERE EXISTS (
               SELECT 1
               FROM item_actresses
               INNER JOIN items ON items.id = item_actresses.item_id
               WHERE item_actresses.duga_id = actresses.duga_id
                 AND " . items_product_source_where('items') . "
             )
             ORDER BY actresses.{$orderBy} ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_actress(int $id): ?array
{
    $id   = max(1, $id);
    $stmt = db()->prepare('SELECT * FROM actresses WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $actress = $stmt->fetch();
    return $actress ?: null;
}

function fetch_related_series_by_actress(int $actressId, int $limit = 50): array
{
    $actressId = max(1, $actressId);
    $limit     = normalize_int($limit, 1, 200);

    try {
        $stmt = db()->prepare(
            'SELECT DISTINCT series_master.id, series_master.name, series_master.ruby
             FROM actresses
             INNER JOIN item_actresses ON item_actresses.duga_id = actresses.duga_id
             INNER JOIN item_series    ON item_series.item_id   = item_actresses.item_id
             INNER JOIN series_master  ON series_master.duga_id  = item_series.duga_id
             WHERE actresses.id = :id
             ORDER BY series_master.name ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':id',    $actressId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit,     PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_related_makers_by_actress(int $actressId, int $limit = 50): array
{
    $actressId = max(1, $actressId);
    $limit     = normalize_int($limit, 1, 200);

    try {
        $sql = db_column_exists('item_makers', 'item_id')
            ? 'SELECT DISTINCT makers.id, makers.name, makers.ruby
               FROM actresses
               INNER JOIN item_actresses ON item_actresses.duga_id = actresses.duga_id
               INNER JOIN item_makers    ON item_makers.item_id   = item_actresses.item_id
               INNER JOIN makers         ON makers.duga_id         = item_makers.duga_id
               WHERE actresses.id = :id
               ORDER BY makers.name ASC
               LIMIT :limit'
            : 'SELECT DISTINCT makers.*
               FROM makers
               INNER JOIN item_makers    ON makers.id = item_makers.maker_id
               INNER JOIN item_actresses ON item_makers.content_id = item_actresses.content_id
               INNER JOIN items          ON items.content_id = item_makers.content_id
               WHERE item_actresses.actress_id = :id
                 AND ' . items_front_release_where('items') . '
               ORDER BY makers.name ASC
               LIMIT :limit';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':id',    $actressId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit,     PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_genre(int $genreId): ?array
{
    $genreId = max(1, $genreId);
    $stmt    = db()->prepare('SELECT * FROM genres WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $genreId]);
    $genre = $stmt->fetch();
    return $genre ?: null;
}

function fetch_maker(int $makerId): ?array
{
    $makerId = max(1, $makerId);
    $stmt    = db()->prepare('SELECT * FROM makers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $makerId]);
    $maker = $stmt->fetch();
    return $maker ?: null;
}

function fetch_series_one(int $seriesId): ?array
{
    $seriesId = max(1, $seriesId);

    try {
        $stmt = db()->prepare('SELECT * FROM series_master WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $seriesId]);
        $series = $stmt->fetch();
        if ($series) {
            return $series;
        }
    } catch (Throwable) {
    }

    try {
        $stmt = db()->prepare('SELECT * FROM series WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $seriesId]);
        $series = $stmt->fetch();
        return $series ?: null;
    } catch (Throwable) {
        return null;
    }
}

function fetch_genres(int $limit = 50, int $offset = 0, string $order = 'name'): array
{
    $orderBy = normalize_order($order, ['name', 'created_at', 'updated_at'], 'name');
    $limit   = normalize_int($limit, 1, 200);
    $offset  = max(0, $offset);

    try {
        $stmt = db()->prepare("SELECT * FROM genres ORDER BY {$orderBy} ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable) {
    }

    backfill_master_from_relation('genres', 'item_genres', 'genre_name');

    try {
        $stmt = db()->prepare("SELECT * FROM genres ORDER BY {$orderBy} ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_makers(int $limit = 50, int $offset = 0, string $order = 'name'): array
{
    $orderBy = normalize_order($order, ['name', 'created_at', 'updated_at'], 'name');
    $limit   = normalize_int($limit, 1, 200);
    $offset  = max(0, $offset);

    try {
        $stmt = db()->prepare("SELECT * FROM makers ORDER BY {$orderBy} ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable) {
    }

    backfill_master_from_relation('makers', 'item_makers', 'maker_name');

    try {
        $stmt = db()->prepare("SELECT * FROM makers ORDER BY {$orderBy} ASC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_series(int $limit = 50, int $offset = 0, string $order = 'name'): array
{
    $orderBy = normalize_order($order, ['name', 'created_at', 'updated_at'], 'name');
    $limit   = normalize_int($limit, 1, 200);
    $offset  = max(0, $offset);

    try {
        $stmt = db()->prepare(
            "SELECT series_master.*
             FROM series_master
             WHERE EXISTS (
               SELECT 1
               FROM item_series
               INNER JOIN items ON items.id = item_series.item_id
               WHERE item_series.duga_id = series_master.duga_id
                 AND " . items_product_source_where('items') . "
             )
             ORDER BY series_master.{$orderBy} ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable) {
    }

    try {
        $stmt = db()->prepare(
            "SELECT series.*
             FROM series
             WHERE EXISTS (
               SELECT 1
               FROM item_series
               INNER JOIN items ON items.content_id = item_series.content_id
               WHERE item_series.series_id = series.id
                 AND " . items_product_source_where('items') . "
             )
             ORDER BY series.{$orderBy} ASC
             LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable) {
    }

    return [];
}

function fetch_labels(int $limit = 50, int $offset = 0): array
{
    $limit  = normalize_int($limit, 1, 200);
    $offset = max(0, $offset);

    $sql = db_column_exists('item_labels', 'item_id')
        ? 'SELECT COALESCE(NULLIF(duga_id, ""), label_name) AS id, label_name AS name, "" AS ruby, COUNT(*) AS item_count
           FROM item_labels
           INNER JOIN items ON items.id = item_labels.item_id
           WHERE ' . items_product_source_where('items') . '
           GROUP BY COALESCE(NULLIF(duga_id, ""), label_name), label_name
           ORDER BY label_name ASC
           LIMIT :limit OFFSET :offset'
        : 'SELECT label_id AS id, label_name AS name, MAX(label_ruby) AS ruby, COUNT(*) AS item_count
           FROM item_labels
           INNER JOIN items ON items.content_id = item_labels.content_id
           WHERE ' . items_product_source_where('items') . '
           GROUP BY label_id, label_name
           ORDER BY label_name ASC
           LIMIT :limit OFFSET :offset';
    try {
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_label(string $labelId, string $labelName = ''): ?array
{
    $labelId = trim($labelId);
    $labelName = trim($labelName);
    if ($labelId === '' && $labelName === '') {
        return null;
    }

    $usesItemId = db_column_exists('item_labels', 'item_id');
    $queries = $usesItemId
        ? [
            ['sql' => 'SELECT COALESCE(NULLIF(duga_id, ""), label_name) AS id, label_name AS name, "" AS ruby, COUNT(*) AS item_count FROM item_labels WHERE duga_id = :label_id OR label_name = :label_id GROUP BY COALESCE(NULLIF(duga_id, ""), label_name), label_name ORDER BY item_count DESC LIMIT 1', 'params' => [':label_id' => $labelId]],
            ['sql' => 'SELECT COALESCE(NULLIF(duga_id, ""), label_name) AS id, label_name AS name, "" AS ruby, COUNT(*) AS item_count FROM item_labels WHERE label_name = :label_name GROUP BY COALESCE(NULLIF(duga_id, ""), label_name), label_name ORDER BY item_count DESC LIMIT 1', 'params' => [':label_name' => $labelName]],
        ]
        : [
            ['sql' => 'SELECT label_id AS id, label_name AS name, MAX(label_ruby) AS ruby, COUNT(*) AS item_count FROM item_labels WHERE label_id = :label_id GROUP BY label_id, label_name ORDER BY item_count DESC LIMIT 1', 'params' => [':label_id' => $labelId]],
            ['sql' => 'SELECT label_id AS id, label_name AS name, MAX(label_ruby) AS ruby, COUNT(*) AS item_count FROM item_labels WHERE label_name = :label_name GROUP BY label_id, label_name ORDER BY item_count DESC LIMIT 1', 'params' => [':label_name' => $labelName]],
        ];
    foreach ($queries as $query) {
        if (in_array('', $query['params'], true)) {
            continue;
        }
        try {
            $stmt = db()->prepare($query['sql']);
            $stmt->execute($query['params']);
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        } catch (Throwable) {
        }
    }

    return null;
}

function fetch_items_by_label_name(string $labelName, int $limit, int $offset = 0): array
{
    $labelName = trim($labelName);
    if ($labelName === '') {
        return [];
    }

    $limit  = normalize_int($limit, 1, 100);
    $offset = max(0, $offset);

    $sql = db_column_exists('item_labels', 'item_id')
        ? 'SELECT DISTINCT items.*
           FROM items
           INNER JOIN item_labels ON items.id = item_labels.item_id
           WHERE item_labels.label_name = :label_name
             AND ' . items_product_source_where('items') . '
           ORDER BY items.release_date DESC, items.id DESC
           LIMIT :limit OFFSET :offset'
        : 'SELECT DISTINCT items.*
           FROM items
           INNER JOIN item_labels ON items.content_id = item_labels.content_id
           WHERE item_labels.label_name = :label_name
             AND ' . items_product_source_where('items') . '
           ORDER BY items.date_published DESC
           LIMIT :limit OFFSET :offset';
    try {
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':label_name', $labelName, PDO::PARAM_STR);
        $stmt->bindValue(':limit',      $limit,     PDO::PARAM_INT);
        $stmt->bindValue(':offset',     $offset,    PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_items_by_actress(int $actressId, int $limit, int $offset = 0): array
{
    $actressId = max(1, $actressId);
    $limit     = normalize_int($limit, 1, 100);
    $offset    = max(0, $offset);

    try {
        $sql = db_column_exists('item_actresses', 'item_id')
            ? 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN actresses      ON actresses.id           = :id
               INNER JOIN item_actresses ON item_actresses.duga_id  = actresses.duga_id
               WHERE items.id = item_actresses.item_id
                 AND ' . items_product_source_where('items') . '
               ORDER BY items.release_date DESC, items.id DESC
               LIMIT :limit OFFSET :offset'
            : 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN item_actresses ON items.content_id = item_actresses.content_id
               WHERE item_actresses.actress_id = :id
                 AND ' . items_product_source_where('items') . '
               ORDER BY date_published DESC
               LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':id',     $actressId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,     PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,    PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_items_by_genre(int $genreId, int $limit, int $offset = 0): array
{
    $genreId = max(1, $genreId);
    $limit   = normalize_int($limit, 1, 100);
    $offset  = max(0, $offset);

    try {
        $sql = db_column_exists('item_genres', 'item_id')
            ? 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN genres      ON genres.id          = :id
               INNER JOIN item_genres ON item_genres.duga_id = genres.duga_id
               WHERE items.id = item_genres.item_id
                 AND ' . items_product_source_where('items') . '
               ORDER BY items.release_date DESC, items.id DESC
               LIMIT :limit OFFSET :offset'
            : 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN item_genres ON items.content_id = item_genres.content_id
               WHERE item_genres.genre_id = :id
                 AND ' . items_product_source_where('items') . '
               ORDER BY date_published DESC
               LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':id',     $genreId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,   PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_items_by_maker(int $makerId, int $limit, int $offset = 0): array
{
    $makerId = max(1, $makerId);
    $limit   = normalize_int($limit, 1, 100);
    $offset  = max(0, $offset);

    try {
        $sql = db_column_exists('item_makers', 'item_id')
            ? 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN makers      ON makers.id          = :id
               INNER JOIN item_makers ON item_makers.duga_id = makers.duga_id
               WHERE items.id = item_makers.item_id
                 AND ' . items_product_source_where('items') . '
               ORDER BY items.release_date DESC, items.id DESC
               LIMIT :limit OFFSET :offset'
            : 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN item_makers ON items.content_id = item_makers.content_id
               WHERE item_makers.maker_id = :id
                 AND ' . items_product_source_where('items') . '
               ORDER BY date_published DESC
               LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':id',     $makerId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,   PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function count_items_by_series(int $seriesId): int
{
    $seriesId = max(1, $seriesId);

    try {
        $sql = db_column_exists('item_series', 'item_id')
            ? 'SELECT COUNT(DISTINCT items.id)
               FROM items
               INNER JOIN series_master ON series_master.id   = :id
               INNER JOIN item_series   ON item_series.duga_id = series_master.duga_id
               WHERE items.id = item_series.item_id AND ' . items_product_source_where('items')
            : 'SELECT COUNT(DISTINCT items.id)
               FROM items
               INNER JOIN item_series ON items.content_id = item_series.content_id
               WHERE item_series.series_id = :id AND ' . items_product_source_where('items');
        $stmt = db()->prepare($sql);
        $stmt->execute([':id' => $seriesId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function fetch_items_by_series(int $seriesId, int $limit, int $offset = 0): array
{
    $seriesId = max(1, $seriesId);
    $limit    = normalize_int($limit, 1, 100);
    $offset   = max(0, $offset);

    try {
        $sql = db_column_exists('item_series', 'item_id')
            ? 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN series_master ON series_master.id   = :id
               INNER JOIN item_series   ON item_series.duga_id = series_master.duga_id
               WHERE items.id = item_series.item_id
                 AND ' . items_product_source_where('items') . '
               ORDER BY items.release_date DESC, items.id DESC
               LIMIT :limit OFFSET :offset'
            : 'SELECT DISTINCT items.*
               FROM items
               INNER JOIN item_series ON items.content_id = item_series.content_id
               WHERE item_series.series_id = :id
                 AND ' . items_product_source_where('items') . '
               ORDER BY date_published DESC
               LIMIT :limit OFFSET :offset';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':id',     $seriesId, PDO::PARAM_INT);
        $stmt->bindValue(':limit',  $limit,    PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}


function update_items_view_count(): void
{
    try {
        db()->exec('UPDATE items i SET i.view_count = (SELECT COUNT(*) FROM page_views pv WHERE pv.item_id = i.id)');
    } catch (Throwable) {
    }
}

function fetch_related_items(string $contentId, int $limit = 12): array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return [];
    }

    $limit = normalize_int($limit, 1, 50);

    try {
        $sql = db_column_exists('item_genres', 'item_id')
            ? 'SELECT i2.*
               FROM items i1
               INNER JOIN item_genres ig1 ON ig1.item_id = i1.id
               INNER JOIN item_genres ig2 ON ig2.duga_id = ig1.duga_id
               INNER JOIN items i2 ON i2.id = ig2.item_id
               WHERE i1.content_id = :cid AND i2.content_id <> :cid
                 AND ' . items_front_release_where('i2') . '
               GROUP BY i2.id
               ORDER BY i2.release_date DESC, i2.id DESC
               LIMIT :limit'
            : 'SELECT i2.*
               FROM items i1
               INNER JOIN item_genres ig1 ON ig1.content_id = i1.content_id
               INNER JOIN item_genres ig2 ON ig2.genre_id = ig1.genre_id
               INNER JOIN items i2 ON i2.content_id = ig2.content_id
               WHERE i1.content_id = :cid AND i2.content_id <> :cid
                 AND ' . items_front_release_where('i2') . '
               GROUP BY i2.id
               ORDER BY i2.release_date DESC, i2.id DESC
               LIMIT :limit';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':cid', $cid, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
    } catch (Throwable) {
    }

    try {
        $stmt = db()->prepare(
            'SELECT * FROM items WHERE content_id <> :cid AND ' . items_front_release_where() . ' ORDER BY release_date DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue(':cid', $cid, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_item_actresses(string $contentId): array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return [];
    }

    try {
        $sql = db_column_exists('item_actresses', 'item_id')
            ? 'SELECT DISTINCT 0 AS id, item_actresses.actress_name AS name
               FROM items
               INNER JOIN item_actresses ON items.id = item_actresses.item_id
               WHERE items.content_id = :cid
               ORDER BY item_actresses.actress_name ASC'
            : 'SELECT actresses.*
               FROM actresses
               INNER JOIN item_actresses ON actresses.id = item_actresses.actress_id
               WHERE item_actresses.content_id = :cid
               ORDER BY actresses.name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([':cid' => $cid]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_item_genres(string $contentId): array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return [];
    }

    try {
        $sql = db_column_exists('item_genres', 'item_id')
            ? 'SELECT DISTINCT genres.id, genres.name, genres.ruby
               FROM items
               INNER JOIN item_genres ON items.id      = item_genres.item_id
               INNER JOIN genres      ON genres.duga_id = item_genres.duga_id
               WHERE items.content_id = :cid
               ORDER BY genres.name ASC'
            : 'SELECT genres.*
               FROM genres
               INNER JOIN item_genres ON genres.id = item_genres.genre_id
               WHERE item_genres.content_id = :cid
               ORDER BY genres.name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([':cid' => $cid]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_item_makers(string $contentId): array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return [];
    }

    try {
        $sql = db_column_exists('item_makers', 'item_id')
            ? 'SELECT DISTINCT makers.id, makers.name, makers.ruby
               FROM items
               INNER JOIN item_makers ON items.id      = item_makers.item_id
               INNER JOIN makers      ON makers.duga_id = item_makers.duga_id
               WHERE items.content_id = :cid
               ORDER BY makers.name ASC'
            : 'SELECT makers.*
               FROM makers
               INNER JOIN item_makers ON makers.id = item_makers.maker_id
               WHERE item_makers.content_id = :cid
               ORDER BY makers.name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([':cid' => $cid]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_item_series(string $contentId): array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return [];
    }

    try {
        $sql = db_column_exists('item_series', 'item_id')
            ? 'SELECT DISTINCT series_master.id, series_master.name, series_master.ruby
               FROM items
               INNER JOIN item_series   ON items.id             = item_series.item_id
               INNER JOIN series_master ON series_master.duga_id = item_series.duga_id
               WHERE items.content_id = :cid
               ORDER BY series_master.name ASC'
            : 'SELECT series.*
               FROM series
               INNER JOIN item_series ON series.id = item_series.series_id
               WHERE item_series.content_id = :cid
               ORDER BY series.name ASC';
        $stmt = db()->prepare($sql);
        $stmt->execute([':cid' => $cid]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable) {
        return [];
    }
}

function fetch_item_labels(string $contentId): array
{
    $cid = normalize_content_id($contentId);
    if ($cid === '') {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT label_id, label_name, label_ruby
         FROM item_labels
         WHERE content_id = :cid
         ORDER BY label_name ASC'
    );
    $stmt->execute([':cid' => $cid]);
    return $stmt->fetchAll() ?: [];
}

function fetch_taxonomy_by_id(string $table, string $idField, int $id): ?array
{
    $table   = normalize_table($table, ['genres', 'makers', 'series']);
    $idField = normalize_order($idField, ['id'], 'id');
    $id      = max(1, $id);

    $stmt = db()->prepare("SELECT * FROM {
        $table} WHERE {
        $idField} = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $data = $stmt->fetch();
    return $data ?: null;
}

function upsert_item(array $item): array
{
    $pdo = db();
    $now = now();

    $contentId = normalize_content_id((string)($item['content_id'] ?? ''));
    if ($contentId === '') {
        throw new InvalidArgumentException('content_id is required');
    }

    $payload = [
        'content_id'           => $contentId,
        'product_id'           => (string)($item['product_id'] ?? ''),
        'title'                => (string)($item['title'] ?? ''),
        'item_source'          => 'duga_product',
        'url'                  => (string)($item['url'] ?? ''),
        'affiliate_url'        => (string)($item['affiliate_url'] ?? ''),
        'image_list'           => (string)($item['image_list'] ?? ''),
        'image_small'          => (string)($item['image_small'] ?? ''),
        'image_large'          => (string)($item['image_large'] ?? ''),
        'sample_movie_url_476' => (string)($item['sample_movie_url_476'] ?? ''),
        'sample_movie_url_560' => (string)($item['sample_movie_url_560'] ?? ''),
        'sample_movie_url_644' => (string)($item['sample_movie_url_644'] ?? ''),
        'sample_movie_url_720' => (string)($item['sample_movie_url_720'] ?? ''),
        'raw_json'             => is_string($item['raw_json'] ?? null) ? (string)$item['raw_json'] : null,
        'date_published'       => $item['date_published'] ?? null,
        'service_code'         => (string)($item['service_code'] ?? ''),
        'floor_code'           => (string)($item['floor_code'] ?? ''),
        'category_name'        => (string)($item['category_name'] ?? ''),
        'price_min'            => (isset($item['price_min']) && is_numeric($item['price_min'])) ? (int)$item['price_min'] : null,
    ];

    ensure_items_item_source_column();

    $stmt = $pdo->prepare('SELECT id FROM items WHERE content_id = :content_id');
    $stmt->execute([':content_id' => $payload['content_id']]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        $sql = 'UPDATE items
                SET product_id           = :product_id,
                    item_source          = :item_source,
                    title                = :title,
                    url                  = :url,
                    affiliate_url        = :affiliate_url,
                    image_list           = :image_list,
                    image_small          = :image_small,
                    image_large          = :image_large,
                    sample_movie_url_476 = :sample_movie_url_476,
                    sample_movie_url_560 = :sample_movie_url_560,
                    sample_movie_url_644 = :sample_movie_url_644,
                    sample_movie_url_720 = :sample_movie_url_720,
                    raw_json             = :raw_json,
                    date_published       = :date_published,
                    service_code         = :service_code,
                    floor_code           = :floor_code,
                    category_name        = :category_name,
                    price_min            = :price_min,
                    updated_at           = :updated_at
                WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':product_id'           => $payload['product_id'],
            ':item_source'          => $payload['item_source'],
            ':title'                => $payload['title'],
            ':url'                  => $payload['url'],
            ':affiliate_url'        => $payload['affiliate_url'],
            ':image_list'           => $payload['image_list'],
            ':image_small'          => $payload['image_small'],
            ':image_large'          => $payload['image_large'],
            ':sample_movie_url_476' => $payload['sample_movie_url_476'],
            ':sample_movie_url_560' => $payload['sample_movie_url_560'],
            ':sample_movie_url_644' => $payload['sample_movie_url_644'],
            ':sample_movie_url_720' => $payload['sample_movie_url_720'],
            ':raw_json'             => $payload['raw_json'],
            ':date_published'       => $payload['date_published'],
            ':service_code'         => $payload['service_code'],
            ':floor_code'           => $payload['floor_code'],
            ':category_name'        => $payload['category_name'],
            ':price_min'            => $payload['price_min'],
            ':updated_at'           => $now,
            ':id'                   => (int)$existingId,
        ]);

        return ['id' => (int)$existingId, 'status' => 'updated'];
    }

    $sql = 'INSERT INTO items
            (content_id, product_id, item_source, title, url, affiliate_url, image_list, image_small, image_large,
             sample_movie_url_476, sample_movie_url_560, sample_movie_url_644, sample_movie_url_720,
             raw_json, date_published, service_code, floor_code, category_name, price_min, created_at, updated_at)
            VALUES
            (:content_id, :product_id, :item_source, :title, :url, :affiliate_url, :image_list, :image_small, :image_large,
             :sample_movie_url_476, :sample_movie_url_560, :sample_movie_url_644, :sample_movie_url_720,
             :raw_json, :date_published, :service_code, :floor_code, :category_name, :price_min, :created_at, :updated_at)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':content_id'           => $payload['content_id'],
        ':product_id'           => $payload['product_id'],
        ':item_source'          => $payload['item_source'],
        ':title'                => $payload['title'],
        ':url'                  => $payload['url'],
        ':affiliate_url'        => $payload['affiliate_url'],
        ':image_list'           => $payload['image_list'],
        ':image_small'          => $payload['image_small'],
        ':image_large'          => $payload['image_large'],
        ':sample_movie_url_476' => $payload['sample_movie_url_476'],
        ':sample_movie_url_560' => $payload['sample_movie_url_560'],
        ':sample_movie_url_644' => $payload['sample_movie_url_644'],
        ':sample_movie_url_720' => $payload['sample_movie_url_720'],
        ':raw_json'             => $payload['raw_json'],
        ':date_published'       => $payload['date_published'],
        ':service_code'         => $payload['service_code'],
        ':floor_code'           => $payload['floor_code'],
        ':category_name'        => $payload['category_name'],
        ':price_min'            => $payload['price_min'],
        ':created_at'           => $now,
        ':updated_at'           => $now,
    ]);

    return ['id' => (int)$pdo->lastInsertId(), 'status' => 'inserted'];
}

function upsert_actress(array $actress): string
{
    $pdo = db();
    $now = now();

    $duga_id = (string)($actress['duga_id'] ?? '');
    $name   = (string)($actress['name']   ?? '');
    if ($duga_id === '' || $name === '') {
        throw new InvalidArgumentException('actress duga_id/name required');
    }

    $stmt = $pdo->prepare('SELECT id FROM actresses WHERE duga_id = :duga_id');
    $stmt->execute([':duga_id' => $duga_id]);
    $exists = $stmt->fetchColumn();

    $payload = [
        ':duga_id'      => $duga_id,
        ':name'        => $name,
        ':ruby'        => $actress['ruby']        ?? null,
        ':birthday'    => $actress['birthday']    ?? null,
        ':prefectures' => $actress['prefectures'] ?? null,
        ':image_url'   => $actress['image_url']   ?? null,
        ':image_small' => $actress['image_small'] ?? null,
        ':image_large' => $actress['image_large'] ?? null,
        ':updated_at'  => $now,
    ];

    if ($exists) {
        $sql = 'UPDATE actresses
                SET name        = :name,
                    ruby        = :ruby,
                    birthday    = :birthday,
                    prefectures = :prefectures,
                    image_url   = :image_url,
                    image_small = :image_small,
                    image_large = :image_large,
                    updated_at  = :updated_at
                WHERE duga_id = :duga_id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($payload);
        return 'updated';
    }

    $sql = 'INSERT INTO actresses
            (duga_id, name, ruby, birthday, prefectures,
             image_url, image_small, image_large, created_at, updated_at)
            VALUES
            (:duga_id, :name, :ruby, :birthday, :prefectures,
             :image_url, :image_small, :image_large, :created_at, :updated_at)';
    $stmt = $pdo->prepare($sql);
    $payload[':created_at'] = $now;
    $payload[':updated_at'] = $now;
    $stmt->execute($payload);
    return 'inserted';
}

function upsert_taxonomy(string $table, string $idField, array $data): string
{
    $table = normalize_table($table, ['genres', 'makers', 'series']);
    if ($idField !== 'id') {
        throw new InvalidArgumentException('Invalid id field');
    }

    $pdo = db();
    $now = now();

    $id   = (int)($data['id'] ?? 0);
    $name = (string)($data['name'] ?? '');
    if ($id <= 0 || $name === '') {
        throw new InvalidArgumentException('taxonomy id/name required');
    }

    $stmt = $pdo->prepare("SELECT {$idField} FROM {
        $table} WHERE {
        $idField} = :id");
    $stmt->execute([':id' => $id]);
    $exists = $stmt->fetchColumn();

    $payload = [
        ':id'           => $id,
        ':name'         => $name,
        ':ruby'         => $data['ruby']         ?? null,
        ':list_url'     => $data['list_url']     ?? null,
        ':site_code'    => $data['site_code']    ?? null,
        ':service_code' => $data['service_code'] ?? null,
        ':floor_id'     => $data['floor_id']     ?? null,
        ':floor_code'   => $data['floor_code']   ?? null,
        ':updated_at'   => $now,
    ];

    if ($exists) {
        $sql = "UPDATE {
            $table}
                SET name         = :name,
                    ruby         = :ruby,
                    list_url     = :list_url,
                    site_code    = :site_code,
                    service_code = :service_code,
                    floor_id     = :floor_id,
                    floor_code   = :floor_code,
                    updated_at   = :updated_at
                WHERE {
            $idField} = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($payload);
        return 'updated';
    }

    $sql = "INSERT INTO {
        $table}
            ({$idField}, name, ruby, list_url, site_code, service_code, floor_id, floor_code, created_at, updated_at)
            VALUES
            (:id, :name, :ruby, :list_url, :site_code, :service_code, :floor_id, :floor_code, :created_at, :updated_at)";
    $stmt = $pdo->prepare($sql);
    $payload[':created_at'] = $now;
    $payload[':updated_at'] = $now;
    $stmt->execute($payload);
    return 'inserted';
}

function replace_item_relations(string $contentId, array $relationIds, string $table, string $column): void
{
    $contentId = normalize_content_id($contentId);
    if ($contentId === '') {
        return;
    }

    $table  = normalize_table($table, ['item_actresses', 'item_genres', 'item_makers', 'item_series']);
    $column = normalize_order($column, ['actress_id', 'genre_id', 'maker_id', 'series_id'], $column);

    $pdo = db();

    $delete = $pdo->prepare("DELETE FROM {
        $table} WHERE content_id = :content_id");
    $delete->execute([':content_id' => $contentId]);

    if (!$relationIds) {
        return;
    }

    $sql  = "INSERT INTO {
        $table} (content_id, {$column}) VALUES (:content_id, :rel_id)";
    $stmt = $pdo->prepare($sql);

    foreach ($relationIds as $id) {
        $id = (int)$id;
        if ($id <= 0) {
            continue;
        }
        $stmt->execute([':content_id' => $contentId, ':rel_id' => $id]);
    }
}

function replace_item_labels(string $contentId, array $labels): void
{
    $contentId = normalize_content_id($contentId);
    if ($contentId === '') {
        return;
    }

    $pdo = db();

    $delete = $pdo->prepare('DELETE FROM item_labels WHERE content_id = :content_id');
    $delete->execute([':content_id' => $contentId]);

    if (!$labels) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO item_labels (content_id, label_id, label_name, label_ruby)
         VALUES (:content_id, :label_id, :label_name, :label_ruby)'
    );

    foreach ($labels as $label) {
        if (!is_array($label)) {
            continue;
        }
        $name = trim((string)($label['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $labelId = $label['id'] ?? null;
        $labelId = is_numeric($labelId) ? (int)$labelId : null;

        $stmt->execute([
            ':content_id' => $contentId,
            ':label_id'   => $labelId,
            ':label_name' => $name,
            ':label_ruby' => ($label['ruby'] ?? null),
        ]);
    }
}
