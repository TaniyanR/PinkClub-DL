<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = '個人設定';
$message = null;
$error = null;
$admin = auth_user();
$adminId = is_array($admin) ? (int)($admin['id'] ?? 0) : 0;
$currentLoginId = is_array($admin) ? trim((string)($admin['username'] ?? '')) : '';
$credentialsPersonalized = auth_credentials_are_personalized();
$requiresCredentialReplacement = !$credentialsPersonalized || strcasecmp($currentLoginId, 'admin') === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));

    $loginId = trim((string)post('login_id', ''));
    $email = trim((string)post('email', ''));
    $currentPassword = (string)post('current_password', '');
    $password = (string)post('password', '');
    $passwordConfirm = (string)post('password_confirm', '');

    $loginIdError = auth_login_id_validation_error($loginId);
    $passwordError = $password !== '' ? auth_password_validation_error($password, $loginId) : null;

    if ($loginIdError !== null) {
        $error = $loginIdError;
    } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'メールアドレスの形式が正しくありません。';
    } elseif (!auth_verify_password_for_user($adminId, $currentPassword)) {
        $error = '現在のパスワードが正しくありません。';
    } elseif (($requiresCredentialReplacement || strcasecmp($currentPassword, 'password') === 0) && $password === '') {
        $error = '初回の認証設定では、新しいパスワードも設定してください。';
    } elseif ($passwordError !== null) {
        $error = $passwordError;
    } elseif ($password !== $passwordConfirm) {
        $error = '確認用パスワードが一致しません。';
    } elseif ($adminId <= 0) {
        $error = '管理者情報を確認できません。';
    } else {
        $stmt = db()->prepare('SELECT id FROM admins WHERE username=:username AND id<>:id LIMIT 1');
        $stmt->execute([
            ':username' => $loginId,
            ':id' => $adminId,
        ]);
        if ($stmt->fetchColumn() !== false) {
            $error = 'このログインIDは使用できません。';
        }

        if ($error === null) {
            $pdo = db();
            $saved = false;
            try {
                $pdo->beginTransaction();
                site_setting_set('site.admin_email', $email);
                site_setting_set('auth.credentials_personalized', '1');
                site_setting_set('auth.admin_user_id', (string)$adminId);
                site_setting_set('auth.login_id', $loginId);

                $updateSql = 'UPDATE admins SET username=:username, updated_at=NOW()';
                $updateParams = [':username' => $loginId, ':id' => $adminId];
                if ($password !== '') {
                    $updateSql .= ', password_hash=:password_hash';
                    $updateParams[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $updateSql .= ' WHERE id=:id LIMIT 1';
                $pdo->prepare($updateSql)->execute($updateParams);

                // 旧版で初期 admin が重複して残った環境でも、個人設定完了後は再利用させない。
                $pdo->prepare("DELETE FROM admins WHERE username = 'admin' AND id <> :id")
                    ->execute([':id' => $adminId]);
                $pdo->commit();
                $saved = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = '認証設定を保存できませんでした。時間をおいてもう一度お試しください。';
            }

            if ($saved) {
                $_SESSION['admin']['username'] = $loginId;
                $currentLoginId = $loginId;
                $credentialsPersonalized = true;
                $requiresCredentialReplacement = false;
                $message = 'ログインID、再設定用メールアドレス、パスワード設定を保存しました。';
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>個人設定</h1>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <?php if ($requiresCredentialReplacement): ?>
    <p class="flash error">初期認証のままです。ログインID・再設定用メールアドレス・新しいパスワードを設定してください。</p>
  <?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>ログインID
      <input type="text" name="login_id" value="<?= e($currentLoginId) ?>" minlength="4" maxlength="50" autocomplete="username" required>
      <small>管理画面へのログインに使用します。「admin」は使用できません。</small>
    </label>
    <label>再設定用メールアドレス
      <input type="email" name="email" value="<?= e(setting_admin_email('')) ?>" autocomplete="email" required>
      <small>パスワードを忘れた場合の再設定メール送信先です。</small>
    </label>
    <label>現在のパスワード
      <input type="password" name="current_password" autocomplete="current-password" required>
      <small>認証情報を変更するために必要です。</small>
    </label>
    <label>新しいパスワード
      <input type="password" name="password" minlength="12" autocomplete="new-password"<?= $requiresCredentialReplacement ? ' required' : '' ?>>
      <small>12文字以上。「password」およびログインIDと同じ文字列は使用できません。</small>
    </label>
    <label>新しいパスワード（確認）
      <input type="password" name="password_confirm" minlength="12" autocomplete="new-password"<?= $requiresCredentialReplacement ? ' required' : '' ?>>
    </label>
    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
