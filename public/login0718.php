<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/rate_limit.php';

if (db_validate_config(app_config()['db'] ?? [], true) !== []) {
    app_redirect('/public/setup_check.php');
}

$autoSetup = installer_auto_run_if_needed();
if (($autoSetup['success'] ?? false) !== true) {
    app_redirect('/public/setup_check.php');
}

if (auth_user()) {
    app_redirect(ADMIN_HOME_PATH);
}

$error = null;
$setupMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(post('_csrf'))) {
        unset($_SESSION['_csrf']);
        $error = 'ログイン画面の有効期限が切れました。もう一度ログインしてください。';
    } else {
        rate_limit_check('admin_login');

        $username = trim((string) post('username', ''));
        $password = (string) post('password', '');

        if (auth_attempt($username, $password)) {
            flash_set('success', 'ログインしました。');
            app_redirect(ADMIN_HOME_PATH);
        }

        if (auth_last_error() === 'db_error') {
            $setupMessage = 'データベースの準備が完了していない可能性があります。セットアップ確認ページをご確認ください。';
        } else {
            $error = 'ログインに失敗しました。';
        }
    }
}
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
  <title><?= e(APP_NAME) ?> 管理ログイン</title>
  <?php if ($faviconUrl !== ''): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>" sizes="any" type="<?= e($faviconType) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>">
</head>
<body class="login-page">
  <main class="login-wrap">
    <section class="login-card">
      <h1 class="login-title"><?= e(APP_NAME) ?></h1>
      <p class="login-subtitle">管理画面ログイン</p>

      <?php if ($setupMessage !== null): ?>
        <div class="alert alert-warning" role="alert">
          <?= e($setupMessage) ?>
          <div class="alert-link-wrap">
            <a href="<?= e(public_url('setup_check.php')) ?>">セットアップ状態を確認する</a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($error !== null): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="login-form">
        <?= csrf_input() ?>
        <label class="login-label">
          ユーザー名
          <input class="login-input" name="username" autocomplete="username" required>
        </label>
        <label class="login-label">
          パスワード
          <input class="login-input" type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="login-button" type="submit">ログイン</button>
      </form>

      <p class="login-note"><a href="<?= e(public_url('forgot_password.php')) ?>">パスワードが分からない場合はコチラ</a></p>
    </section>
  </main>
</body>
</html>
