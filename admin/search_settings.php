<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
require_once __DIR__ . '/../lib/search_lifecycle.php';

$title = 'SEO・IndexNow';
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    try {
        $action = (string)post('action', '');
        if ($action === 'install') {
            $result = installer_run();
            if (empty($result['success'])) {
                throw new RuntimeException('DB更新に失敗しました。セットアップ画面のログを確認してください。');
            }
            $message = '既存データを保持してDBを更新しました。';
        } elseif ($action === 'enable') {
            if (!db_table_exists('indexnow_queue')) {
                throw new RuntimeException('先にDB更新を実行してください。');
            }
            if ((string)post('confirmed_origin', '') !== pcf_indexnow_origin()) {
                throw new RuntimeException('送信対象サイトを確認してください。');
            }
            if (pcf_indexnow_key() === '') {
                setting_set('indexnow.key', bin2hex(random_bytes(16)));
            }
            setting_set('indexnow.origin', pcf_indexnow_origin());
            setting_set('indexnow.enabled', '1');
            $message = 'このサイトのIndexNowを有効にしました。更新されたURLを順次送信します。';
        } elseif ($action === 'disable') {
            setting_set('indexnow.enabled', '0');
            $message = '自動送信を停止しました。送信待ちURLは保持しています。';
        } elseif ($action === 'backfill') {
            $message = pcf_indexnow_backfill() . '件を送信待ちに追加しました。0件なら既存作品の登録は完了です。';
        } elseif ($action === 'send') {
            $result = pcf_indexnow_dispatch();
            $message = '送信結果: ' . $result['status'] . ' / ' . $result['count'] . '件 / HTTP ' . ($result['http'] ?? '-');
        } elseif ($action === 'gone') {
            pcf_item_mark_gone((int)post('item_id', 0), (string)post('reason', ''));
            $message = '掲載終了（410）に設定しました。元の商品データは保持しています。';
        } elseif ($action === 'restore') {
            pcf_item_restore((int)post('item_id', 0));
            $message = '掲載終了設定を解除しました。';
        }
    } catch (Throwable $e) {
        error_log('Search settings action failed: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !$e instanceof PDOException)
            ? $e->getMessage()
            : '処理に失敗しました。DB更新状況とサーバーログを確認してください。';
    }
}

$ready = db_table_exists('indexnow_queue') && db_table_exists('item_tombstones');
$pending = 0;
$gone = [];
if ($ready) {
    $stmt = db()->prepare('SELECT COUNT(*) FROM indexnow_queue WHERE origin=?');
    $stmt->execute([pcf_indexnow_origin()]);
    $pending = (int)$stmt->fetchColumn();
    $gone = db()->query('SELECT item_id,content_id,reason,removed_at FROM item_tombstones ORDER BY removed_at DESC LIMIT 50')
        ->fetchAll(PDO::FETCH_ASSOC);
}
$last = json_decode((string)setting_get('indexnow.last_result', ''), true);
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form admin-card--accent-pink admin-search-settings">
  <h1>SEO・IndexNow</h1>
  <?php if ($message !== ''): ?><p class="flash success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="flash error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if (!$ready): ?>
    <p class="admin-form-note">この機能の初回利用時はDB更新が必要です。商品や管理者のデータを保持して追加テーブルを作成します。</p>
    <form method="post" class="admin-actions"><?= csrf_input() ?><button name="action" value="install">DBを更新する</button></form>
  <?php else: ?>
    <h2>IndexNow</h2>
    <div class="admin-status-grid">
      <div class="admin-status-card"><strong>送信対象</strong><p class="admin-search-origin"><?= e(pcf_indexnow_origin()) ?></p></div>
      <div class="admin-status-card"><strong>自動送信</strong><p><?= pcf_indexnow_enabled() ? '有効' : '停止中' ?></p></div>
      <div class="admin-status-card"><strong>送信待ち</strong><p><?= number_format($pending) ?>件</p></div>
    </div>
    <p class="admin-form-note">上の送信対象URLを確認し、このサイトの更新を検索エンジンへ通知する場合は有効にしてください。サイトのドメインが変わった場合は自動送信を停止するため、移転先で再度有効にしてください。</p>
    <form method="post" class="admin-search-enable">
      <?= csrf_input() ?>
      <label class="admin-search-confirm"><input type="checkbox" name="confirmed_origin" value="<?= e(pcf_indexnow_origin()) ?>" required><span>このサイトのURLを検索エンジンへ通知する</span></label>
      <div class="admin-actions"><button name="action" value="enable">このサイトで有効にする</button></div>
    </form>
    <form method="post" class="admin-actions">
      <?= csrf_input() ?>
      <button class="button-secondary" name="action" value="disable">送信を停止</button>
      <button class="button-secondary" name="action" value="send" aria-describedby="indexnow-pending-help">送信待ちを処理（最大1,000件）</button>
    </form>
    <p class="admin-form-note" id="indexnow-pending-help">現在の送信待ち：<strong><?= number_format($pending) ?>件</strong>。<?= $pending === 0 ? '送信待ちはありません。今は処理ボタンを押す必要はありません。' : '上部の「送信待ち」欄でも確認できます。有効にすると自動で順次送信されます。手動で処理する場合は上のボタンを押してください。' ?> 最新の件数はページを再読み込みして確認できます。</p>
    <p class="admin-form-note">通常は既存のcronによる自動更新時に送信します。送信失敗時は間隔を空けて再送します。200は受信済み、202はキー検証待ちで、検索への掲載を保証するものではありません。</p>
    <?php if (pcf_indexnow_enabled()): ?><p class="admin-form-note"><a href="<?= e(pcf_indexnow_origin() . '/indexnow-key.php') ?>" target="_blank" rel="noopener">所有権確認ファイルを開く</a>（文字列だけが表示されれば正常です）</p><?php endif; ?>
    <?php if (is_array($last)): ?><p class="admin-form-note">最終送信：<?= e((string)($last['at'] ?? '')) ?> / HTTP <?= (int)($last['http'] ?? 0) ?> / <?= (int)($last['count'] ?? 0) ?>件</p><?php endif; ?>
    <div class="admin-search-section">
      <h2>既存作品の通知</h2>
      <p class="admin-form-note">導入前の作品も通知したい場合に使います。繰り返すと続きから処理し、全件を一度に送信しません。</p>
      <form method="post" class="admin-actions"><?= csrf_input() ?><button class="button-secondary" name="action" value="backfill">既存作品の次の1,000件を送信待ちに追加</button></form>
    </div>
  <?php endif; ?>
</section>
<?php if ($ready): ?>
<section class="admin-card admin-card--form admin-card--accent-pink admin-search-settings">
  <h2>確認済みの配信終了・掲載終了</h2>
  <p class="admin-form-note">提供元の配信終了、または当サイトから恒久的に掲載を終了すると確認できた作品だけ登録してください。APIの取得失敗や未登録という理由では登録しません。</p>
  <form method="post">
    <?= csrf_input() ?>
    <label>商品ID <input name="item_id" type="number" min="1" required></label>
    <label>確認した理由 <input name="reason" maxlength="500" required></label>
    <div class="admin-actions"><button name="action" value="gone">掲載終了にする（410）</button></div>
  </form>
  <div class="admin-search-section">
    <h3>掲載終了に設定した作品</h3>
    <?php if ($gone === []): ?>
      <p class="admin-form-note">掲載終了に設定した作品はありません。</p>
    <?php else: ?>
      <div class="admin-search-table-wrap" role="region" aria-label="掲載終了に設定した作品" tabindex="0">
        <table class="admin-table">
          <thead><tr><th scope="col">商品ID</th><th scope="col">コンテンツID</th><th scope="col">確認した理由</th><th scope="col">操作</th></tr></thead>
          <tbody><?php foreach ($gone as $row): ?>
            <tr>
              <td><?= (int)$row['item_id'] ?></td><td><?= e($row['content_id']) ?></td><td><?= e($row['reason']) ?></td>
              <td><form method="post"><?= csrf_input() ?><input type="hidden" name="item_id" value="<?= (int)$row['item_id'] ?>"><button class="button-secondary" name="action" value="restore">掲載終了を解除</button></form></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
