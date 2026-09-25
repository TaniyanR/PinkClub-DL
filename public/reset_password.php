<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/partials/_helpers.php';

$error = '';
$resetCode = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $resetCode = trim((string)($_POST['reset_code'] ?? ''));
    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $error = '画面の有効期限が切れました。もう一度お試しください。';
    } elseif (preg_match('/^[a-f0-9]{64}$/', $resetCode) !== 1 || !db_table_exists('admin_password_resets')) {
        $error = '再設定コードが正しくないか、有効期限が切れています。';
    } else {
        $resetStmt = db()->prepare('SELECT * FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
        $resetStmt->execute([':h' => hash('sha256', $resetCode)]);
        $reset = $resetStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($reset)) {
            $error = '再設定コードが正しくないか、すでに使用されたか、有効期限が切れています。';
        } else {
            $password = (string)($_POST['password'] ?? '');
            $passwordConfirm = (string)($_POST['password_confirm'] ?? '');
            $adminUserId = (int)($reset['admin_user_id'] ?? 0);
            $adminStmt = db()->prepare('SELECT username FROM admins WHERE id=:id LIMIT 1');
            $adminStmt->execute([':id' => $adminUserId]);
            $loginId = (string)($adminStmt->fetchColumn() ?: '');
            $passwordError = auth_password_validation_error($password, $loginId);
            if ($passwordError !== null) {
                $error = $passwordError;
            } elseif ($password !== $passwordConfirm) {
                $error = '確認用パスワードが一致しません。';
            } else {
                $pdo = db();
                try {
                    $pdo->beginTransaction();
                    $lockedStmt = $pdo->prepare('SELECT id,admin_user_id FROM admin_password_resets WHERE token_hash=:h AND used_at IS NULL AND expires_at >= NOW() LIMIT 1 FOR UPDATE');
                    $lockedStmt->execute([':h' => hash('sha256', $resetCode)]);
                    $lockedReset = $lockedStmt->fetch(PDO::FETCH_ASSOC);
                    if (!is_array($lockedReset) || (int)$lockedReset['admin_user_id'] !== $adminUserId) throw new RuntimeException('reset code already used or expired');
                    $pdo->prepare('UPDATE admins SET password_hash=:h, updated_at=NOW() WHERE id=:id LIMIT 1')->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $adminUserId]);
                    $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:admin_user_id AND used_at IS NULL')->execute([':admin_user_id' => $adminUserId]);
                    $pdo->commit();
                    $_SESSION['forgot_password_success'] = 'パスワードを再設定しました。新しいパスワードでログインしてください。';
                    app_redirect(login_url());
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'この再設定コードはすでに使用されたか、期限が切れました。新しい再設定メールを発行してください。';
                }
            }
        }
    }
}

$pageTitle = '新しいパスワードを設定';
$hideLoginHeaderBrand = true;
include __DIR__ . '/partials/login_header.php';
?>
<div class="login-wrap login-wrap--reset">
  <section class="login-card login-card--reset" aria-labelledby="reset-password-title">
    <div class="login-brand-mark" aria-hidden="true">鍵</div>
    <p class="login-eyebrow">パスワード再設定</p>
    <h1 id="reset-password-title" class="login-title">新しいパスワードを設定</h1>
    <p class="login-subtitle">再設定メールに記載されたコードと、新しいパスワードを入力してください。コードは1時間・一度限り有効です。</p>
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form class="login-form" method="post" action="<?= e(public_url('reset_password.php')) ?>">
      <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
      <label class="login-label">再設定コード<input class="login-input" type="text" name="reset_code" value="<?= e($resetCode) ?>" minlength="64" maxlength="64" pattern="[a-f0-9]{64}" autocomplete="one-time-code" spellcheck="false" required></label>
      <label class="login-label">新しいパスワード<input class="login-input" type="password" name="password" minlength="12" autocomplete="new-password" required></label>
      <label class="login-label">新しいパスワード（確認）<input class="login-input" type="password" name="password_confirm" minlength="12" autocomplete="new-password" required></label>
      <button class="login-button" type="submit">新しいパスワードを保存</button>
    </form>
    <p class="login-note">保存後、この再設定コードは直ちに無効になります。</p>
    <p class="login-back"><a href="<?= e(public_url('forgot_password.php')) ?>">新しい再設定メールを送る</a></p>
  </section>
</div>
<?php include __DIR__ . '/partials/login_footer.php';
