<?php

declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/repository.php';

auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail(post('_csrf'));
    $tagId = (int)post('tag_id', 0);
    if ($tagId > 0 && delete_tag($tagId)) {
        flash_set('success', 'タグを削除しました。');
    } else {
        flash_set('error', 'タグの削除に失敗しました。');
    }
    app_redirect('admin/tags.php');
}

$title = 'タグ管理';
$tags = fetch_all_tags(500, 0);
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>タグ管理</h1>
  <table class="admin-table">
    <tr><th>ID</th><th>タグ名</th><th>使用件数</th><th>操作</th></tr>
    <?php foreach ($tags as $tag): ?>
      <tr>
        <td><?= e((string)$tag['id']) ?></td>
        <td><?= e((string)$tag['name']) ?></td>
        <td><?= e((string)$tag['item_count']) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('タグを削除しますか？');">
            <?= csrf_input() ?>
            <input type="hidden" name="tag_id" value="<?= e((string)$tag['id']) ?>">
            <button type="submit">削除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
