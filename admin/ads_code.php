<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = '広告コード';
$message = null;
$error = null;

$positions = [
    'header_left_728x90' => 'PCヘッダー（728x90）',
    'sidebar_bottom' => 'サイド広告１（300x250）',
    'content_bottom' => 'サイド広告２（300x250）',
    'sp_header_below' => 'SPヘッダー下',
    'sp_footer_above' => 'SPフッター上',
];

try {
    db()->exec('CREATE TABLE IF NOT EXISTS code_snippets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slot_key VARCHAR(100) NOT NULL UNIQUE,
        snippet_html MEDIUMTEXT NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {
    $error = '広告コード保存用テーブルの初期化に失敗しました。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO code_snippets(slot_key,snippet_html,is_enabled,created_at,updated_at) VALUES(:slot,:html,:enabled,NOW(),NOW()) ON DUPLICATE KEY UPDATE snippet_html=VALUES(snippet_html),is_enabled=VALUES(is_enabled),updated_at=NOW()');
        foreach ($positions as $slot => $label) {
            $html = trim((string)post('code_' . $slot, ''));
            $enabled = post('enabled_' . $slot, '0') === '1' ? 1 : 0;
            $stmt->execute([
                ':slot' => $slot,
                ':html' => $html,
                ':enabled' => $enabled,
            ]);
        }
        $pdo->commit();
        $message = '広告コードを保存しました。';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = '広告コードの保存に失敗しました。';
    }
}

$rows = [];
if ($error === null) {
    try {
        $stmt = db()->query('SELECT slot_key,snippet_html,is_enabled FROM code_snippets');
        $list = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($list as $row) {
            $key = (string)($row['slot_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $rows[$key] = $row;
        }
    } catch (Throwable $e) {
        $error = '広告コードの読み込みに失敗しました。';
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>広告コード</h1>
  <p class="admin-form-note">各枠に広告タグを設定できます。不要な枠は無効のままにしてください。</p>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <?= csrf_input() ?>
    <?php foreach ($positions as $slot => $label):
      $current = $rows[$slot] ?? ['snippet_html' => '', 'is_enabled' => 0];
    ?>
      <label><?= e($label) ?>
        <textarea name="code_<?= e($slot) ?>" rows="4" placeholder="広告コードを貼り付けてください"><?= e((string)($current['snippet_html'] ?? '')) ?></textarea>
      </label>
      <label>表示設定
        <select name="enabled_<?= e($slot) ?>">
          <option value="1" <?= ((int)($current['is_enabled'] ?? 0) === 1) ? 'selected' : '' ?>>有効</option>
          <option value="0" <?= ((int)($current['is_enabled'] ?? 0) !== 1) ? 'selected' : '' ?>>無効</option>
        </select>
      </label>
      <hr>
    <?php endforeach; ?>
    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
