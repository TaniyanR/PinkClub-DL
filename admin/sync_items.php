<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail(post('_csrf'));
    try {
        $count = duga_sync_service()->syncItems();
        flash_set('success', "商品同期: {$count}件");
    } catch (Throwable $e) {
        flash_set('error', '商品同期失敗: ' . $e->getMessage());
    }
    app_redirect('admin/sync_items.php');
}

$title = 'Items';
$logs = db()->query("SELECT * FROM sync_logs WHERE sync_type IN ('item','items') ORDER BY id DESC LIMIT 30")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>DUGA商品同期</h1>
  <form method="post" id="duga-manual-sync-form">
    <?= csrf_input() ?>
    <button type="submit">最新商品を同期</button>
  </form>
  <div id="duga-manual-sync-progress" class="admin-notice admin-notice--success" style="display:none;margin-top:12px;" role="status" aria-live="polite">
    <p style="margin-bottom:8px;">DUGA APIから取得中です。1秒に1回の制限を守っているため、そのままお待ちください。</p>
    <progress style="width:100%;"></progress>
  </div>
</section>

<section class="admin-card">
  <h2>同期履歴</h2>
  <table class="admin-table">
    <tr><th>時刻</th><th>結果</th><th>件数</th><th>メッセージ</th></tr>
    <?php foreach ($logs as $l): ?>
      <tr><td><?= e($l['created_at']) ?></td><td><?= $l['is_success'] ? 'OK' : 'NG' ?></td><td><?= e($l['synced_count']) ?></td><td><?= e($l['message']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<script>
(() => {
  const form = document.getElementById('duga-manual-sync-form');
  const progress = document.getElementById('duga-manual-sync-progress');
  if (!form || !progress) return;
  form.addEventListener('submit', () => {
    progress.style.display = 'block';
    const button = form.querySelector('button[type="submit"]');
    if (button) {
      button.disabled = true;
      button.textContent = '取得中…';
    }
  });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

