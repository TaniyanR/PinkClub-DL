<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'コード設定';
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    try {
        site_setting_set_many([
            'site.custom_head_code' => (string)post('site_custom_head_code', ''),
            'site.custom_body_open_code' => (string)post('site_custom_body_open_code', ''),
        ]);
        $message = 'コード設定を保存しました。';
    } catch (Throwable $e) {
        $error = 'コード設定の保存に失敗しました。';
    }
}

$customHeadCode = site_setting_get('site.custom_head_code', '');
$customBodyOpenCode = site_setting_get('site.custom_body_open_code', '');

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>コード設定</h1>
  <p class="admin-form-note">head内に出力するメタタグなどのコードと、body直下に出力するコードを設定できます。</p>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <?= csrf_input() ?>
    <label>head内コード（metaタグなど）
      <textarea name="site_custom_head_code" rows="8" placeholder="例: &lt;meta name=&quot;example&quot; content=&quot;...&quot;&gt;"><?= e($customHeadCode) ?></textarea>
    </label>
    <label>body直下コード
      <textarea name="site_custom_body_open_code" rows="8" placeholder="bodyタグの直後に出力するコードを貼り付けてください"><?= e($customBodyOpenCode) ?></textarea>
    </label>
    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
