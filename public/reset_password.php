<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/_helpers.php';

$token = trim((string)($_GET['token'] ?? ''));
$reset = false;
if (db_table_exists('admin_password_resets')) {
    $stmt = db()->prepare('SELECT * FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute([':h' => hash('sha256', $token)]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!is_array($reset)) {
    http_response_code(403);
    $pageTitle = '403 Forbidden';
    include __DIR__ . '/partials/login_header.php';
    echo '<div class="login-page"><section class="admin-card login-card"><h1>403 Forbidden</h1><p>リセットトークンが無効または期限切れです。</p><p><a href="' . e(public_url('forgot_password.php')) . '">再発行へ戻る</a></p></section></div>';
    include __DIR__ . '/partials/login_footer.php';
    exit;
}
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $error = '不正なリクエストです。';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
        if (strlen($password) < 8) {
            $error = '新しいパスワードは8文字以上で入力してください。';
        } elseif ($password !== $passwordConfirm) {
            $error = '確認用パスワードが一致しません。';
        } else {
            $adminUserId = (int)$reset['admin_user_id'];
            db()->prepare('UPDATE admins SET password_hash=:h, updated_at=NOW() WHERE id=:id')
                ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $adminUserId]);
            db()->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE id=:id')->execute([':id' => (int)$reset['id']]);
            $_SESSION['forgot_password_success'] = 'パスワードを再設定しました。新しいパスワードでログインしてください。';
            app_redirect(login_url());
        }
    }
}

$pageTitle = 'パスワード再設定';
include __DIR__ . '/partials/login_header.php';
?>
<div class="login-page">
    <div class="login-headline"><span class="login-headline__item">PinkClub-DL</span><span class="login-headline__item">パスワード再設定</span></div>
    <?php if ($error !== '') : ?><div class="admin-card login-alert"><p><?php echo e($error); ?></p></div><?php endif; ?>
    <form class="admin-card login-card" method="post" action="<?php echo e(public_url('reset_password.php') . '?token=' . rawurlencode($token)); ?>">
        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
        <label>新しいパスワード</label><input type="password" name="password" minlength="8" required>
        <label>新しいパスワード（確認）</label><input type="password" name="password_confirm" minlength="8" required>
        <button type="submit">再設定する</button>
    </form>
</div>
<?php include __DIR__ . '/partials/login_footer.php';
