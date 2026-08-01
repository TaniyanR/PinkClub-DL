<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'cron設定';
$publicRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$siteRoot = realpath(dirname($publicRoot)) ?: dirname($publicRoot);
$cronTargetFile = $publicRoot . '/scripts/auto_import.php';
$cronPhpCli = '/usr/bin/php8.3';
$cronLogFile = $siteRoot . '/cron_auto_import.log';
$cronCommandExample = $cronPhpCli . ' ' . $cronTargetFile . ' >> ' . $cronLogFile . ' 2>&1';
$cronScheduleExample = '*/10 * * * * ' . $cronCommandExample;
$cronTargetExists = is_file($cronTargetFile);

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>cron設定</h1>
  <p class="admin-form-note">商品などを自動更新するための設定です。<strong>サーバーへ登録するcronは1つだけです。</strong></p>
  <?php if (!$cronTargetExists): ?>
    <p class="admin-form-note"><strong>警告:</strong> 実行対象ファイル <code><?= e($cronTargetFile) ?></code> が見つかりません。設置ファイルを確認してください。</p>
  <?php endif; ?>

  <div style="margin:24px 0; padding:20px; border:2px solid #2b7bbb; border-radius:8px; background:#f3f9ff;">
    <h2 style="margin-top:0;">サーバーに登録する内容</h2>
    <p><strong>登録するのは、下記の1件だけです。</strong></p>
    <table class="admin-table">
      <tr>
        <th style="width:180px;">実行間隔</th>
        <td><strong>10分ごと</strong></td>
      </tr>
      <tr>
        <th>実行コマンド</th>
        <td>
          <input id="cron-command" type="text" value="<?= e($cronCommandExample) ?>" readonly style="width:100%; overflow-x:auto;">
          <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('cron-command').value);">コマンドをコピー</button>
        </td>
      </tr>
    </table>
    <p class="admin-form-note">サーバーのcron設定画面で「10分ごと」を選び、コピーしたコマンドを貼り付けて保存してください。</p>
  </div>

  <details>
    <summary style="cursor:pointer; font-weight:700; font-size:1.1rem;">詳しい情報を確認する</summary>
    <div style="margin-top:16px;">
      <p class="admin-form-note">通常は変更する必要はありません。設定確認やエラー調査のための情報です。</p>
  <table class="admin-table">
    <tr><th>実行対象ファイル</th><td><code><?= e($cronTargetFile) ?></code></td></tr>
    <tr><th>PHP CLI</th><td><code><?= e($cronPhpCli) ?></code><br><small>このサーバーではPHP 8.3の絶対パスを使用します。</small></td></tr>
    <tr><th>ログファイル</th><td><code><?= e($cronLogFile) ?></code></td></tr>
    <tr><th>crontabへ直接登録する場合</th><td><code style="overflow-wrap:anywhere;"><?= e($cronScheduleExample) ?></code><br><small>サーバーパネルではなく、crontabを直接編集する場合だけ使用します。</small></td></tr>
  </table>
    </div>
  </details>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
