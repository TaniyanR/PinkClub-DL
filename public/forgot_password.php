<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/rate_limit.php';
require_once __DIR__ . '/partials/_helpers.php';

$message = '';
$messageType = 'success';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!rate_limit_allow('password_reset', 3, 900)) {
        http_response_code(429);
        $message = '短時間に複数回の申請がありました。15分ほど待ってから、もう一度お試しください。';
        $messageType = 'error';
    } elseif (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $message = '画面の有効期限が切れました。ページを再読み込みして、もう一度お試しください。';
        $messageType = 'error';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'メールアドレスの形式を確認してください。';
            $messageType = 'error';
        } else {
            $mailAttempted = false;
            $mailSent = false;
            $mailBodyForLog = '';
            $fromEmailForLog = 'noreply@pinkclub-dl.com';
            $registeredEmail = setting_admin_email('');
            if ($registeredEmail !== '' && hash_equals($registeredEmail, $email)) {
                $configuredAdminId = (int)site_setting_get('auth.admin_user_id', '0');
                $configuredLoginId = trim(site_setting_get('auth.login_id', ''));
                if ($configuredAdminId > 0) {
                    $stmt = db()->prepare('SELECT id, username FROM admins WHERE id=:id LIMIT 1');
                    $stmt->execute([':id' => $configuredAdminId]);
                } elseif ($configuredLoginId !== '') {
                    $stmt = db()->prepare('SELECT id, username FROM admins WHERE username=:username LIMIT 1');
                    $stmt->execute([':username' => $configuredLoginId]);
                } else {
                    $stmt = db()->query("SELECT id, username FROM admins ORDER BY (username = 'admin') ASC, id ASC LIMIT 1");
                }
                $admin = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
                if (is_array($admin) && db_table_exists('admin_password_resets')) {
                    $token = bin2hex(random_bytes(32));
                    $tokenStored = false;
                    $pdo = db();
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('UPDATE admin_password_resets SET used_at=NOW() WHERE admin_user_id=:admin_user_id AND used_at IS NULL')
                            ->execute([':admin_user_id' => (int)$admin['id']]);
                        $pdo->prepare('INSERT INTO admin_password_resets(admin_user_id,token_hash,expires_at) VALUES (:admin_user_id,:token_hash,DATE_ADD(NOW(), INTERVAL 1 HOUR))')
                            ->execute([
                                ':admin_user_id' => (int)$admin['id'],
                                ':token_hash' => hash('sha256', $token),
                            ]);
                        $pdo->commit();
                        $tokenStored = true;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('password reset token creation failed: ' . $e->getMessage());
                    }

                    if ($tokenStored) {
                        $resetUrl = public_url('reset_password.php') . '?token=' . rawurlencode($token);
                        $body = "管理者パスワード再設定の申請を受け付けました。\n\n"
                            . "ログインID: " . (string)$admin['username'] . "\n"
                            . "再設定URL: " . $resetUrl . "\n\n"
                            . "このURLの有効期限は1時間で、一度使用すると無効になります。\n"
                            . "申請した覚えがない場合は、このメールを破棄してください。";
                        $host = (string)(parse_url(app_url(), PHP_URL_HOST) ?: 'pinkclub-dl.com');
                        $host = preg_replace('/[^a-z0-9.-]/i', '', $host) ?: 'pinkclub-dl.com';
                        $fromEmail = 'noreply@' . preg_replace('/^www\./i', '', $host);
                        $fromEmailForLog = $fromEmail;
                        $subject = '[PinkClub-DL] パスワード再設定';
                        $encodedSubject = function_exists('mb_encode_mimeheader')
                            ? mb_encode_mimeheader($subject, 'UTF-8')
                            : $subject;
                        $headers = "From: PinkClub-DL <{$fromEmail}>\r\nContent-Type: text/plain; charset=UTF-8";
                        $mailAttempted = true;
                        $mailBodyForLog = $body;
                        $mailSent = @mail($email, $encodedSubject, $body, $headers);
                    } else {
                        $mailSent = false;
                    }
                }
            }

            if ($mailAttempted) {
                try {
                    db()->prepare('INSERT INTO mail_logs(direction,from_name,from_email,to_email,subject,body,status,last_error,created_at,updated_at) VALUES ("out",NULL,:from,:to,:subj,:body,:status,:err,NOW(),NOW())')
                        ->execute([
                            ':from' => $fromEmailForLog,
                            ':to' => $email,
                            ':subj' => 'Password Reset',
                            ':body' => preg_replace('/token=[a-f0-9]{64}/', 'token=[REDACTED]', $mailBodyForLog),
                            ':status' => $mailSent ? 'sent' : 'failed',
                            ':err' => $mailSent ? null : 'mail() returned false',
                        ]);
                } catch (Throwable) {
                }
            }

            // アカウントの存在とメール送信結果を第三者へ知らせない。
            $message = '入力情報を受け付けました。登録情報と一致する場合は、数分以内に再設定メールが届きます。';
            $messageType = 'success';
        }
    }
}

$pageTitle = 'パスワード再設定メール';
$hideLoginHeaderBrand = true;
include __DIR__ . '/partials/login_header.php';
?>
<div class="login-wrap login-wrap--reset">
  <section class="login-card login-card--reset" aria-labelledby="forgot-password-title">
    <div class="login-brand-mark" aria-hidden="true">鍵</div>
    <p class="login-eyebrow">管理画面の認証</p>
    <h1 id="forgot-password-title" class="login-title">パスワードを再設定</h1>
    <p class="login-subtitle">個人設定に登録したメールアドレスを入力してください。有効期限1時間・一度限りの再設定URLを送信します。</p>

    <?php if ($message !== ''): ?>
      <div class="alert alert-<?= e($messageType) ?>" role="<?= $messageType === 'error' ? 'alert' : 'status' ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <form method="post" class="login-form">
      <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
      <label class="login-label">
        登録メールアドレス
        <input class="login-input" name="email" type="email" autocomplete="email" placeholder="name@example.com" required>
      </label>
      <button class="login-button" type="submit">再設定メールを送信</button>
    </form>

    <ol class="login-reset-steps" aria-label="再設定の流れ">
      <li>メールを確認</li>
      <li>再設定URLを開く</li>
      <li>新しいパスワードを保存</li>
    </ol>
    <p class="login-note">メールが届かない場合は、迷惑メールフォルダと管理画面の「個人設定」に登録したアドレスをご確認ください。</p>
    <p class="login-back"><a href="<?= e(public_url('login0718.php')) ?>">ログイン画面へ戻る</a></p>
  </section>
</div>
<?php include __DIR__ . '/partials/login_footer.php';
