<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'お問い合わせ設定';
$message = null;
$error = null;
$settingKey = 'contact.spam_keywords';
$spamKeywords = site_setting_get($settingKey, '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawKeywords = (string)post('spam_keywords', '');
    $spamKeywords = $rawKeywords;

    if (!csrf_verify((string)post('_csrf', ''))) {
        $error = '画面の有効期限が切れました。もう一度操作してください。';
    } else {
        $lines = preg_split('/\R/u', $rawKeywords) ?: [];
        $keywords = [];
        $seen = [];
        foreach ($lines as $line) {
            $keyword = trim((string)$line);
            if ($keyword === '') {
                continue;
            }
            $length = function_exists('mb_strlen') ? mb_strlen($keyword, 'UTF-8') : strlen($keyword);
            if ($length > 100) {
                $error = 'スパムキーワードは1件100文字以内で入力してください。';
                break;
            }
            $dedupeKey = function_exists('mb_strtolower') ? mb_strtolower($keyword, 'UTF-8') : strtolower($keyword);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $keywords[] = $keyword;
        }

        if ($error === null && count($keywords) > 100) {
            $error = 'スパムキーワードは最大100件まで入力できます。';
        }

        if ($error === null) {
            try {
                $spamKeywords = implode("\n", $keywords);
                site_setting_set($settingKey, $spamKeywords);
                $message = 'お問い合わせ設定を保存しました。';
            } catch (Throwable $e) {
                $error = 'お問い合わせ設定の保存に失敗しました。';
                $spamKeywords = $rawKeywords;
            }
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>お問い合わせ設定</h1>
  <p class="admin-form-note">お問い合わせフォームのスパム対策を設定できます。</p>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>

  <form method="post">
    <?= csrf_input() ?>
    <label>お問い合わせスパムキーワード
      <textarea name="spam_keywords" rows="8" maxlength="10100" placeholder="1行に1語ずつ入力してください。\n氏名・メールアドレス・題名・本文のいずれかに含まれる場合、メール送信せず送信完了として処理します。\n"><?= e($spamKeywords) ?></textarea>
    </label>
    <p class="admin-form-note">1行に1語ずつ入力してください。<br>氏名・メールアドレス・題名・本文のいずれかに含まれる場合、メール送信せず送信完了として処理します。</p>
    <div class="admin-actions">
      <button type="submit">設定を保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
