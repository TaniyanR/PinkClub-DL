<?php
declare(strict_types=1);

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/indexnow.php';

function pcf_search_cache_invalidate(): void
{
    $directory = dirname(__DIR__) . '/storage/cache';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('検索用キャッシュの保存先を作成できません。');
    }
    $path = $directory . '/search-generation';
    $tmp = $path . '.' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, bin2hex(random_bytes(16)), LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('検索用キャッシュの更新に失敗しました。保存先の権限を確認してください。');
    }
}

function pcf_item_is_gone(int $id, string $contentId = ''): bool
{
    if (!db_table_exists('item_tombstones')) {
        return false;
    }
    $stmt = db()->prepare($id > 0
        ? 'SELECT 1 FROM item_tombstones WHERE item_id = ?'
        : 'SELECT 1 FROM item_tombstones WHERE content_id = ? LIMIT 1');
    $stmt->execute([$id > 0 ? $id : $contentId]);
    return (bool)$stmt->fetchColumn();
}

// Only an authenticated, explicit removal action calls this. API misses never do.
function pcf_item_mark_gone(int $id, string $reason): void
{
    $reason = trim($reason);
    if ($id < 1 || $reason === '') {
        throw new InvalidArgumentException('商品IDと削除理由が必要です。');
    }
    $stmt = db()->prepare('SELECT content_id FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $contentId = $stmt->fetchColumn();
    if (!is_string($contentId)) {
        throw new InvalidArgumentException('登録済みの商品IDを指定してください。');
    }
    db()->prepare('INSERT INTO item_tombstones (item_id,content_id,reason) VALUES (?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),removed_at=NOW()')
        ->execute([$id, $contentId, mb_substr($reason, 0, 500)]);
    pcf_indexnow_enqueue(public_url('item.php') . '?id=' . $id);
    pcf_search_cache_invalidate();
}

function pcf_item_restore(int $id): void
{
    $stmt = db()->prepare('SELECT 1 FROM items WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('元の商品データがありません。先に商品を再取得してください。');
    }
    db()->prepare('DELETE FROM item_tombstones WHERE item_id = ?')->execute([$id]);
    pcf_indexnow_enqueue(public_url('item.php') . '?id=' . $id);
    pcf_search_cache_invalidate();
}

function pcf_search_error(int $status): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($status === 503) {
        header('Retry-After: 300');
    } else {
        header('X-Robots-Tag: noindex, follow');
    }
    $title = $status === 410 ? '配信終了・掲載終了' : '一時的にページを表示できません';
    $message = $status === 410
        ? 'この作品は配信終了、または当サイトでの掲載を終了しました。'
        : '時間をおいて再度アクセスしてください。';
    echo '<!doctype html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="rating" content="adult"><title>'
        . $title . ' | PinkClub-DL</title>';
    if ($status === 410) {
        echo '<meta name="robots" content="noindex, follow">';
    }
    echo '</head><body><main><h1>' . $title . '</h1><p>' . $message . '</p><p><a href="'
        . e(public_url('index.php')) . '">トップページへ戻る</a></p></main></body></html>';
    exit;
}
