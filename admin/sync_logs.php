<?php

declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'Logs';
$logs = db()->query('SELECT * FROM sync_logs ORDER BY id DESC LIMIT 200')->fetchAll();

function sync_log_hint(array $log): string
{
    if (!empty($log['is_success'])) {
        return '正常に完了しています。';
    }

    $message = (string)($log['message'] ?? '');
    if ($message === '') {
        return 'エラー内容が空です。直前の操作内容とサーバー側のPHPエラーログを確認してください。';
    }
    if (strpos($message, 'cURL') !== false || strpos($message, 'HTTPリクエスト') !== false) {
        return 'APIへの通信に失敗しています。API設定、サーバーの外部通信、ネットワーク状態を確認してください。';
    }
    if (strpos($message, 'HTTPステータス') !== false || strpos($message, 'status') !== false) {
        return 'APIから正常以外の応答が返っています。API ID、アフィリエイトID、リクエスト条件を確認してください。';
    }
    if (strpos($message, 'JSON') !== false || strpos($message, 'パース') !== false) {
        return 'API応答の読み取りに失敗しています。応答抜粋がある場合は内容を確認してください。';
    }
    if (strpos($message, 'テーブル') !== false || strpos($message, 'SQL') !== false || strpos($message, 'column') !== false) {
        return 'DBまたはテーブル定義に関するエラーです。セットアップ状態とDBのテーブルを確認してください。';
    }
    if (strpos($message, 'ロック') !== false) {
        return '同じ同期処理が実行中の可能性があります。しばらく待ってから再確認してください。';
    }

    return 'メッセージ欄の内容を確認してください。判断できない場合は、この行のID・種別・時刻を控えて確認してください。';
}
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card">
  <h1>Logs</h1>
  <p class="admin-form-note">直近200件の同期ログです。「確認ポイント」はエラー時に最初に見る場所の目安です。</p>
  <table class="admin-table">
    <tr><th>ID</th><th>種別</th><th>成否</th><th>件数</th><th>確認ポイント</th><th>メッセージ</th><th>時刻</th></tr>
    <?php foreach ($logs as $log): ?>
      <tr>
        <td><?= e($log['id']) ?></td>
        <td><?= e($log['sync_type']) ?></td>
        <td><?= $log['is_success'] ? 'OK（成功）' : 'NG（失敗）' ?></td>
        <td><?= e($log['synced_count']) ?></td>
        <td><?= e(sync_log_hint($log)) ?></td>
        <td><?= e($log['message']) ?></td>
        <td><?= e($log['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
