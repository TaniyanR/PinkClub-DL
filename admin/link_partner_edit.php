<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
analytics_ensure_tables();

$title = '相互リンク編集';
$message = null;
$id = (int)get('id', 0);
$partnerNofollowSupported = db_column_exists('partner_sites', 'rel_nofollow');

if ($id <= 0) {
    app_redirect('admin/links.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $name = trim((string)post('name', ''));
    $url = trim((string)post('url', ''));
    $rssUrl = trim((string)post('rss_url', ''));

    if ($partnerNofollowSupported) {
        db()->prepare('UPDATE partner_sites SET name = :name, url = :url, rel_nofollow = :nofollow, updated_at = NOW() WHERE id = :id')
            ->execute([
                ':name' => $name,
                ':url' => $url,
                ':nofollow' => post('rel_nofollow', '0') === '1' ? 1 : 0,
                ':id' => $id,
            ]);
    } else {
        db()->prepare('UPDATE partner_sites SET name = :name, url = :url, updated_at = NOW() WHERE id = :id')
            ->execute([':name' => $name, ':url' => $url, ':id' => $id]);
    }

    $rssId = (int)post('rss_id', 0);
    if ($rssId > 0) {
        db()->prepare('UPDATE partner_rss SET feed_url = :url, updated_at = NOW() WHERE id = :id')
            ->execute([':url' => $rssUrl, ':id' => $rssId]);
    } elseif ($rssUrl !== '') {
        db()->prepare('INSERT INTO partner_rss(partner_site_id,feed_url,is_enabled,show_rss,created_at,updated_at) VALUES(:sid,:url,1,1,NOW(),NOW())')
            ->execute([':sid' => $id, ':url' => $rssUrl]);
    }
    $message = '相互リンク情報を更新しました。';
}

$stmt = db()->prepare('SELECT ps.*, pr.id AS rss_id, pr.feed_url FROM partner_sites ps LEFT JOIN partner_rss pr ON pr.partner_site_id = ps.id WHERE ps.id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    app_redirect('admin/links.php');
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>相互リンク編集</h1>
  <?php if ($message): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <input type="hidden" name="rss_id" value="<?= e((string)($row['rss_id'] ?? 0)) ?>">
    <label>サイト名<input name="name" required value="<?= e((string)$row['name']) ?>"></label>
    <label>URL<input name="url" type="url" required value="<?= e((string)$row['url']) ?>"></label>
    <label>RSS URL<input name="rss_url" type="url" value="<?= e((string)($row['feed_url'] ?? '')) ?>"></label>
    <?php if ($partnerNofollowSupported): ?>
      <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="rel_nofollow" value="1" style="width:auto;margin:0;" <?= ((int)($row['rel_nofollow'] ?? 0) === 1) ? 'checked' : '' ?>> rel="nofollow"</label>
    <?php endif; ?>
    <div class="admin-actions">
      <button type="submit">更新</button>
      <a class="button-secondary" href="<?= e(admin_url('links.php')) ?>">一覧へ戻る</a>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
