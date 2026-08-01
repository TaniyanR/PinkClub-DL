<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = '個人設定';
$message = null;
$error = null;
$admin = auth_user();
$adminId = is_array($admin) ? (int)($admin['id'] ?? 0) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));

    $email = trim((string)post('email', ''));
    $password = (string)post('password', '');
    $passwordConfirm = (string)post('password_confirm', '');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'メールアドレスの形式が正しくありません。';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください。';
    } elseif ($password !== $passwordConfirm) {
        $error = '確認用パスワードが一致しません。';
    } elseif ($adminId <= 0) {
        $error = '管理者情報を確認できません。';
    } else {
        if ($email !== '') {
            $stmt = db()->prepare('SELECT id FROM admins WHERE username=:username AND id<>:id LIMIT 1');
            $stmt->execute([
                ':username' => $email,
                ':id' => $adminId,
            ]);
            if ($stmt->fetchColumn() !== false) {
                $error = 'このメールアドレスはログインユーザー名として使用できません。';
            }
        }

        if ($error === null) {
            site_setting_set('site.admin_email', $email);

            if ($email !== '') {
                db()->prepare('UPDATE admins SET username=:username, updated_at=NOW() WHERE id=:id LIMIT 1')
                    ->execute([
                        ':username' => $email,
                        ':id' => $adminId,
                    ]);
                $_SESSION['admin']['username'] = $email;
            }

            if ($password !== '') {
                db()->prepare('UPDATE admins SET password_hash=:password_hash, updated_at=NOW() WHERE id=:id LIMIT 1')
                    ->execute([
                        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        ':id' => $adminId,
                    ]);
            }

            $message = '個人設定を保存しました。';
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>個人設定</h1>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>メールアドレス
      <input type="email" name="email" value="<?= e(setting_admin_email('')) ?>">
    </label>
    <label>新しいパスワード
      <input type="password" name="password" minlength="8" autocomplete="new-password">
    </label>
    <label>新しいパスワード（確認）
      <input type="password" name="password_confirm" minlength="8" autocomplete="new-password">
    </label>
    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
