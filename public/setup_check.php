<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/local_config_writer.php';

$dbConfigError = null;
$dbConfigNotice = null;

function setup_normalize_local_db(array $db): array
{
    if (!isset($db['dbname']) && isset($db['name'])) {
        $db['dbname'] = $db['name'];
    }
    if (!isset($db['pass']) && isset($db['password'])) {
        $db['pass'] = $db['password'];
    }

    return $db;
}

function setup_local_config_status(): array
{
    $path = local_config_path();
    $status = [
        'path' => $path,
        'exists' => is_file($path),
        'readable' => false,
        'writable' => false,
        'loaded' => false,
        'error' => $GLOBALS['config_local_error'] ?? null,
        'db' => [],
        'has_password' => false,
    ];
    $status['writable'] = $status['exists'] ? is_writable($path) : is_writable(dirname($path));
    if (!$status['exists']) {
        return $status;
    }
    $status['readable'] = is_readable($path);
    if (!$status['readable']) {
        $status['error'] = 'config.local.php を読み込めません。ファイル権限を確認してください。';
        return $status;
    }

    try {
        $local = local_config_load();
        $db = isset($local['db']) && is_array($local['db']) ? setup_normalize_local_db($local['db']) : [];
        $status['loaded'] = true;
        $status['db'] = $db;
        $status['has_password'] = (string)($db['pass'] ?? '') !== '';
    } catch (Throwable $exception) {
        $status['error'] = $exception->getMessage();
    }

    return $status;
}

function setup_safe_db_error(string $stage, Throwable $exception): string
{
    $message = $exception->getMessage();
    if (!extension_loaded('pdo_mysql')) {
        return $stage . 'に失敗しました。PDO MySQL拡張が有効ではない可能性があります。';
    }
    if (str_contains($message, 'Unknown database')) {
        return $stage . 'に失敗しました。DBサーバーには接続できましたが、対象DBへ接続できません。DB名が存在しない可能性があります。';
    }
    if (str_contains($message, 'Access denied')) {
        return $stage . 'に失敗しました。ユーザー名またはパスワードが違う、またはDBユーザーが対象DBに追加されていない可能性があります。';
    }
    if (str_contains($message, 'Connection refused') || str_contains($message, 'No such file or directory') || str_contains($message, 'timed out')) {
        return $stage . 'に失敗しました。MySQLサーバーへ接続できません。DBホスト名、DBポート、MySQLサーバーの稼働状況を確認してください。';
    }

    return $stage . 'に失敗しました。DBホスト名、DBポート、データベース、ユーザー名、パスワードを確認してください。';
}

function setup_test_db_config(array $db): void
{
    $host = (string)($db['host'] ?? '');
    $port = (int)($db['port'] ?? 3306);
    $dbname = (string)($db['dbname'] ?? '');
    $user = (string)($db['user'] ?? '');
    $pass = (string)($db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    try {
        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
        new PDO($serverDsn, $user, $pass, db_options());
    } catch (Throwable $exception) {
        throw new RuntimeException(setup_safe_db_error('DBサーバー接続テスト', $exception), 0, $exception);
    }

    try {
        $dbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbname, $charset);
        new PDO($dbDsn, $user, $pass, db_options());
    } catch (Throwable $exception) {
        throw new RuntimeException(setup_safe_db_error('対象DB接続テスト', $exception), 0, $exception);
    }
}

$localConfigStatus = setup_local_config_status();
$csrfFailed = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify(post('_csrf'))) {
    unset($_SESSION['_csrf']);
    $csrfFailed = true;
    $dbConfigError = 'セットアップ確認画面の有効期限が切れました。もう一度操作してください。';
}

if (!$csrfFailed && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)post('action', '') === 'run_installer') {
    try {
        $setupResult = installer_run();
        if (($setupResult['success'] ?? false) === true) {
            app_redirect(LOGIN_PATH);
        }
        $dbConfigError = (string)($setupResult['error'] ?? 'セットアップに失敗しました。install.log を確認してください。');
    } catch (Throwable $exception) {
        $dbConfigError = 'セットアップに失敗しました。MySQL情報と logs/install.log を確認してください。';
    }
}

if (!$csrfFailed && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)post('action', '') === 'save_db_config') {
    $host = trim((string)post('db_host', ''));
    $port = (int)post('db_port', 3306);
    $dbname = trim((string)post('db_name', ''));
    $user = trim((string)post('db_user', ''));
    $pass = (string)post('db_pass', '');
    if ($host === '' || $port <= 0 || $dbname === '' || $user === '') {
        $dbConfigError = 'DBホスト名、DBポート、データベース、ユーザー名を入力してください。';
    } elseif ($host !== 'localhost' && str_contains($dbname, '_') && $host === strtok($dbname, '_')) {
        $dbConfigError = 'DBホスト名にサーバーIDが入力されています。DBホスト名は通常 localhost です。';
    } else {
        try {
            $local = local_config_load();
            $localDb = isset($local['db']) && is_array($local['db']) ? setup_normalize_local_db($local['db']) : [];
            if ($pass === '' && isset($localDb['pass'])) {
                $pass = (string)$localDb['pass'];
            }
            if ($pass === '') {
                $dbConfigError = '初回保存時はMySQLユーザーのパスワードを入力してください。';
                throw new RuntimeException('db password required');
            }
            $newDbConfig = [
                'host' => $host,
                'port' => $port,
                'dbname' => $dbname,
                'user' => $user,
                'pass' => $pass,
                'charset' => 'utf8mb4',
            ];

            setup_test_db_config($newDbConfig);

            $local['db'] = $newDbConfig;
            local_config_write($local);
            $saved = local_config_load();
            $savedDb = isset($saved['db']) && is_array($saved['db']) ? setup_normalize_local_db($saved['db']) : null;
            if ($savedDb !== $newDbConfig) {
                throw new RuntimeException('設定ファイルの保存内容を確認できませんでした。');
            }
            $GLOBALS['app_config']['db'] = $newDbConfig;
            db_reset_connections();
            $dbConfigNotice = 'DB接続テストに成功したため、DB接続設定を保存しました。';
        } catch (Throwable $exception) {
            if ($dbConfigError === null) {
                $dbConfigError = str_starts_with($exception->getMessage(), 'DB')
                    ? $exception->getMessage()
                    : 'DB接続設定の保存に失敗しました: ' . $exception->getMessage();
            }
        }
    }
}

$localConfigStatus = setup_local_config_status();
$currentDbConfig = $localConfigStatus['loaded'] && is_array($localConfigStatus['db']) && $localConfigStatus['db'] !== []
    ? array_replace(app_config()['db'] ?? [], array_intersect_key($localConfigStatus['db'], app_config()['db'] ?? []))
    : (app_config()['db'] ?? []);
if (($localConfigStatus['error'] ?? null) !== null) {
    $dbConfigError = 'config.local.php の読み込みに失敗しました: ' . (string)$localConfigStatus['error'];
}

if (!$csrfFailed && $dbConfigError !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentDbConfig = array_replace($currentDbConfig, [
        'host' => trim((string)post('db_host', '')),
        'port' => (int)post('db_port', 3306),
        'dbname' => trim((string)post('db_name', '')),
        'user' => trim((string)post('db_user', '')),
    ]);
}
$configErrors = db_validate_config($currentDbConfig, true);
if ($configErrors === []) {
    $status = installer_status();
    if (($status['completed'] ?? false) === true) {
        app_redirect(LOGIN_PATH);
    }
} else {
    $status = ['server_connection'=>false,'db_connection'=>false,'admins_table'=>false,'settings_table'=>false,'admin_user'=>false,'settings_row'=>false,'completed'=>false];
    $dbConfigNotice = 'DB設定が未入力です。MySQL情報を入力して保存してください。';
}

$checks = [
    'MySQLサーバー接続' => $status['server_connection'] ?? false,
    '対象DB接続' => $status['db_connection'] ?? false,
    'admins テーブル' => $status['admins_table'] ?? false,
    'settings テーブル' => $status['settings_table'] ?? false,
    '初期管理者 admin' => $status['admin_user'] ?? false,
    'settings(installer.ready=1)' => $status['settings_row'] ?? false,
];

$errorSummary = installer_last_error_summary();
$logTail = installer_log_tail(30);
csrf_token();
$faviconPath = trim(site_setting_get('site.favicon_path', ''));
$faviconUrl = $faviconPath !== '' ? public_url($faviconPath) : '';
$faviconType = strtolower((string)pathinfo($faviconPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/x-icon';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?> セットアップ確認</title>
  <?php if ($faviconUrl !== ''): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>" sizes="any" type="<?= e($faviconType) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>">
</head>
<body>
  <main class="setup-page">
    <section class="setup-card">
      <h1><?= e(APP_NAME) ?> セットアップ確認</h1>
      <div class="alert alert-warning">セットアップ失敗時の診断ページです。DB設定保存後またはDBを空にした後は、この画面の「セットアップを実行する」から再実行できます。</div>


      <h2>DB接続設定</h2>
      <div class="alert alert-warning">サーバーパネルに表示されるMySQL情報を入力してください。接続テストに成功した場合のみ保存します。</div>
      <?php if ($dbConfigNotice !== null): ?>
        <div class="alert alert-warning"><?= e($dbConfigNotice) ?></div>
      <?php endif; ?>
      <?php if ($dbConfigError !== null): ?>
        <div class="alert alert-error"><?= e($dbConfigError) ?></div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_db_config">
        <table><tbody>
          <tr><th>DBホスト名</th><td><input name="db_host" value="<?= e((string)($currentDbConfig['host'] ?? '')) ?>" required><br><small>通常 <code>localhost</code> です。サーバーIDではありません。</small></td></tr>
          <tr><th>DBポート</th><td><input name="db_port" type="number" value="<?= e((string)($currentDbConfig['port'] ?? 3306)) ?>" required></td></tr>
          <tr><th>データベース</th><td><input name="db_name" value="<?= e((string)($currentDbConfig['dbname'] ?? '')) ?>" required></td></tr>
          <tr><th>ユーザー名</th><td><input name="db_user" value="<?= e((string)($currentDbConfig['user'] ?? '')) ?>" required><br><small>サーバーパネルのMySQL設定で、このユーザーを対象データベースに追加してください。</small></td></tr>
          <tr><th>パスワード</th><td><input name="db_pass" type="password" value="" autocomplete="new-password"><br><small>保存済みの場合、空欄のまま保存すると既存値を維持します。</small></td></tr>
        </tbody></table>
        <p><button type="submit">DB設定を保存する</button></p>
      </form>


      <?php if ($configErrors === []): ?>
        <h2>セットアップ実行</h2>
        <div class="alert alert-warning">DBを削除・空にした後は、このボタンで <code>sql/schema.sql</code> と <code>sql/migrations/*.sql</code> をファイル名順に自動適用します。</div>
        <form method="post">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="run_installer">
          <p><button type="submit">セットアップを実行する</button></p>
        </form>
      <?php endif; ?>

      <table>
        <thead><tr><th>項目</th><th>状態</th></tr></thead>
        <tbody>
          <?php foreach ($checks as $label => $ok): ?>
            <tr><td><?= e((string)$label) ?></td><td><?= $ok ? 'OK' : 'NG' ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <h2>直近エラー要約</h2>
      <?php if (is_array($errorSummary)): ?>
        <table><tbody>
          <tr><th>時刻</th><td><?= e((string)($errorSummary['time'] ?? '-')) ?></td></tr>
          <tr><th>ステップ</th><td><?= e((string)($errorSummary['step'] ?? '-')) ?></td></tr>
          <tr><th>例外クラス</th><td><?= e((string)($errorSummary['class'] ?? '-')) ?></td></tr>
          <tr><th>メッセージ</th><td><?= e((string)($errorSummary['message'] ?? '-')) ?></td></tr>
          <tr><th>発生箇所</th><td><?= e((string)($errorSummary['file'] ?? '-')) ?>:<?= e((string)($errorSummary['line'] ?? '-')) ?></td></tr>
          <tr><th>失敗SQL</th><td><pre><?= e((string)($errorSummary['failed_sql'] ?? '取得なし')) ?></pre></td></tr>
        </tbody></table>
      <?php else: ?>
        <p>直近エラー要約はありません。</p>
      <?php endif; ?>

      <h2>install.log 末尾30行</h2>
      <?php if (($logTail['error'] ?? null) !== null): ?>
        <div class="alert alert-warning"><?= e((string)$logTail['error']) ?></div>
      <?php else: ?>
        <pre><?php foreach (($logTail['lines'] ?? []) as $line): ?><?= e((string)$line) . "\n" ?><?php endforeach; ?></pre>
      <?php endif; ?>

      <p><a href="<?= e(public_url('login0718.php')) ?>">ログイン画面へ戻る</a></p>
    </section>
  </main>
</body>
</html>
