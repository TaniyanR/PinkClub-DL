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
$cronCommand = $cronPhpCli . ' ' . $cronTargetFile;
$cronCommandWithLog = $cronCommand . ' >> ' . $cronLogFile . ' 2>&1';
$cronScheduleExample = '*/10 * * * * ' . $cronCommandWithLog;
$cronTargetExists = is_file($cronTargetFile);

require __DIR__ . '/includes/header.php';
?>
<section class="card">
  <h1>cron設定</h1>
  <p class="admin-form-note">PinkClub DUGAの商品などを自動更新するための設定です。複数サイトを同じサーバーで運用する場合は、実行分を重ねないでください。</p>

  <?php if (!$cronTargetExists): ?>
    <p class="admin-form-note"><strong>警告:</strong> 実行対象ファイル <code><?= e($cronTargetFile) ?></code> が見つかりません。設置ファイルを確認してください。</p>
  <?php endif; ?>

  <div style="margin:24px 0; padding:20px; border:2px solid #2b7bbb; border-radius:8px; background:#f3f9ff;">
    <h2 style="margin-top:0;">サーバーパネルでの設定方法</h2>
    <ol style="line-height:1.9; margin-bottom:0;">
      <li>サーバーパネルの「cron設定」を開きます。</li>
      <li>実行間隔を<strong>10分ごと</strong>にします。</li>
      <li>下記のコマンドを<strong>どちらか1つだけ</strong>コピーして登録します。</li>
    </ol>
    <p class="admin-form-note" style="margin-bottom:0;"><strong>2つを同時に登録しないでください。</strong>同じ自動更新が二重に動いてしまいます。</p>
    <p class="admin-form-note" style="margin-bottom:0;">このサイトは毎時3分・33分など、他サイトと15分以上ずらした実行時刻を推奨します。</p>
  </div>

  <h2>1. 正式な実行コマンド</h2>
  <p class="admin-form-note">通常運用で使用する基本のコマンドです。実行ログはファイルへ保存しません。</p>
  <div style="padding:16px; border:1px solid #d7dce1; border-radius:8px; background:#fff;">
    <input id="cron-command" type="text" value="<?= e($cronCommand) ?>" readonly style="width:calc(100% - 130px); overflow-x:auto;">
    <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('cron-command').value);">コピー</button>
  </div>

  <h2 style="margin-top:28px;">2. ログ付き実行コマンド</h2>
  <p class="admin-form-note">cronが動いているか確認したい場合はこちらを使用します。正常な結果とエラーの両方をログへ追記します。</p>
  <div style="padding:16px; border:2px solid #2b7bbb; border-radius:8px; background:#f3f9ff;">
    <p style="margin-top:0;"><strong>初回設定や不具合調査では、こちらがおすすめです。</strong></p>
    <input id="cron-command-log" type="text" value="<?= e($cronCommandWithLog) ?>" readonly style="width:calc(100% - 130px); overflow-x:auto;">
    <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('cron-command-log').value);">コピー</button>
    <p class="admin-form-note" style="margin-bottom:0;">ログの保存先：<code><?= e($cronLogFile) ?></code></p>
  </div>

  <div style="margin-top:24px; padding:16px; border-left:5px solid #e6a700; background:#fff8df;">
    <strong>どちらを使えばよいですか？</strong>
    <p style="margin-bottom:0;">最初は「ログ付き実行コマンド」を登録してください。正常に更新されることを確認できたら、そのまま使い続けても、正式な実行コマンドへ入れ替えても構いません。</p>
  </div>

  <details style="margin-top:28px;">
    <summary style="cursor:pointer; font-weight:700; font-size:1.1rem;">詳しい情報を確認する</summary>
    <div style="margin-top:16px;">
      <p class="admin-form-note">通常は変更する必要はありません。設定確認やエラー調査のための情報です。</p>
      <table class="admin-table">
        <tr><th>実行対象ファイル</th><td><code><?= e($cronTargetFile) ?></code></td></tr>
        <tr><th>PHP CLI</th><td><code><?= e($cronPhpCli) ?></code><br><small>このサーバーではPHP 8.3の絶対パスを使用します。</small></td></tr>
        <tr><th>ログファイル</th><td><code><?= e($cronLogFile) ?></code></td></tr>
        <tr>
          <th>crontabへ直接登録する場合</th>
          <td>
            <input id="cron-example" type="text" value="<?= e($cronScheduleExample) ?>" readonly style="width:calc(100% - 130px); overflow-x:auto;">
            <button type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('cron-example').value);">コピー</button>
            <br><small>サーバーパネルで「10分ごと」を選べる場合、この設定例は使用しません。</small>
          </td>
        </tr>
      </table>
    </div>
  </details>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
