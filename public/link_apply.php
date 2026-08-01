<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/rate_limit.php';
require_once __DIR__ . '/partials/_helpers.php';

$msg = '';
$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $err = 'CSRFエラーです。';
    } elseif (!rate_limit_allow('link_apply', 3, 3600)) {
        $err = '短時間に複数回送信されています。時間をおいて再度お試しください。';
    } else {
        $applyType = (string)($_POST['apply_type'] ?? 'link_only');
        if (!in_array($applyType, ['link_only', 'link_rss'], true)) {
            $applyType = 'link_only';
        }

        $name = trim((string)($_POST['site_name'] ?? ''));
        $url = trim((string)($_POST['url'] ?? ''));
        $rss = trim((string)($_POST['rss_url'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $ruleText = trim((string)($_POST['rule_text'] ?? ''));

        $urlScheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $rssScheme = strtolower((string)parse_url($rss, PHP_URL_SCHEME));
        if (
            $name === ''
            || mb_strlen($name) > 200
            || mb_strlen($email) > 254
            || mb_strlen($ruleText) > 5000
            || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array($urlScheme, ['http', 'https'], true)
            || !filter_var($rss, FILTER_VALIDATE_URL)
            || !in_array($rssScheme, ['http', 'https'], true)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $err = '入力内容を確認してください。';
        } else {
            db()->prepare('INSERT INTO mutual_links(site_name,site_url,link_url,rss_url,contact_email,apply_type,rule_text,rule_json,status,is_enabled,display_position,rss_enabled,created_at,updated_at) VALUES(:n,:u,:lu,:rss,:email,:apply_type,:rule_text,:rule_json,"pending",0,"sidebar",0,NOW(),NOW())')
                ->execute([
                    ':n' => $name,
                    ':u' => $url,
                    ':lu' => $url,
                    ':rss' => $rss,
                    ':email' => $email,
                    ':apply_type' => $applyType,
                    ':rule_text' => $ruleText,
                    ':rule_json' => json_encode(['raw' => $ruleText], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            $msg = '申請を受け付けました。管理者確認後にご案内します。';
        }
    }
}

$pageTitle = '相互リンク申請';
include __DIR__ . '/partials/header.php';
?>
<section class="block">
        <h1 class="section-title">相互リンク申請</h1>
        <?php if ($msg !== '') : ?><p><?php echo e($msg); ?></p><?php endif; ?>
        <?php if ($err !== '') : ?><p><?php echo e($err); ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">

            <label>申請タイプ</label>
            <select name="apply_type" required>
                <option value="link_only">相互リンクのみ</option>
                <option value="link_rss">相互リンク＋相互RSS</option>
            </select>

            <label>サイト名</label>
            <input name="site_name" required>

            <label>サイトURL</label>
            <input name="url" type="url" required>

            <label>サイトRSS</label>
            <input name="rss_url" type="url" required>

            <label>メールアドレス</label>
            <input name="email" type="email" required>

            <label>判別ルール（自由記述）</label>
            <textarea name="rule_text" rows="5" required></textarea>

            <button>申請する</button>
        </form>
</section>
<?php include __DIR__ . '/partials/footer.php';
