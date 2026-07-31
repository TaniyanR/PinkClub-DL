<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = '相互リンク表示設定';
$message = null;

$settings = [
    'link.rss_display.pc_image' => ['name' => 'link_rss_display_pc_image', 'label' => 'PC：画像RSS'],
    'link.rss_display.pc_text_sidebar' => ['name' => 'link_rss_display_pc_text_sidebar', 'label' => 'PC：テキストRSS'],
    'link.rss_display.pc_text_bottom' => ['name' => 'link_rss_display_pc_text_bottom', 'label' => 'PC：テキストRSS(本文下)'],
    'link.rss_display.pc_partner_links' => ['name' => 'link_rss_display_pc_partner_links', 'label' => 'PC：相互リンク'],
    'link.rss_display.sp_header_below' => ['name' => 'link_rss_display_sp_header_below', 'label' => 'スマホ：ヘッダー下'],
    'link.rss_display.sp_footer_above' => ['name' => 'link_rss_display_sp_footer_above', 'label' => 'スマホ：フッター上'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $updates = [];
    foreach ($settings as $key => $setting) {
        $updates[$key] = post((string)$setting['name'], '0') === '1' ? '1' : '0';
    }
    site_setting_set_many($updates);
    $message = '相互リンク表示設定を更新しました。';
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>相互リンク表示設定</h1>
  <?php if ($message): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <?php foreach ($settings as $key => $setting): ?>
      <label><input type="checkbox" name="<?= e((string)$setting['name']) ?>" value="1" <?= site_setting_get($key, '1') === '1' ? 'checked' : '' ?>> <?= e((string)$setting['label']) ?>を表示する</label>
    <?php endforeach; ?>
    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
