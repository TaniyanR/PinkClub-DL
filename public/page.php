<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/rate_limit.php';
require_once __DIR__ . '/../lib/contact_page_slug.php';
require_once __DIR__ . '/partials/_helpers.php';

function contact_destination_email(): array
{
    $settingsEmail = setting_admin_email('');
    if ($settingsEmail !== '' && filter_var($settingsEmail, FILTER_VALIDATE_EMAIL)) {
        return [$settingsEmail, ''];
    }

    return ['', 'admin email not found'];
}


function contact_form_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function contact_spam_log(string $reason): void
{
    error_log('contact_form_spam reason=' . $reason . ' at=' . date('c') . ' ip_hash=' . hash('sha256', rate_limit_client_ip()));
}

function contact_form_issue_id(): string
{
    $now = time();
    $forms = $_SESSION['contact_forms'] ?? [];
    $forms = is_array($forms) ? $forms : [];
    foreach ($forms as $id => $createdAt) {
        if ((int)$createdAt < $now - 7200) {
            unset($forms[$id]);
        }
    }
    while (count($forms) >= 10) {
        array_shift($forms);
    }
    try {
        $id = bin2hex(random_bytes(16));
    } catch (Throwable) {
        $id = hash('sha256', uniqid('', true) . '|' . $now . '|' . session_id());
    }
    $forms[$id] = $now;
    $_SESSION['contact_forms'] = $forms;
    return $id;
}

function contact_form_consume_age(string $id): ?int
{
    $forms = $_SESSION['contact_forms'] ?? [];
    if (!is_array($forms) || $id === '' || !isset($forms[$id])) {
        return null;
    }
    $createdAt = (int)$forms[$id];
    unset($forms[$id]);
    $_SESSION['contact_forms'] = $forms;
    return time() - $createdAt;
}

function contact_url_count(string $text): int
{
    $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', ' ', $text) ?? $text;
    preg_match_all('~\b(?:https?://|www\.)\S+|\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:com|net|org|jp|co\.jp|info|biz|io|me|tv|xyz|site|online|club|tokyo|shop|app)(?:/\S*)?~iu', $text, $matches);
    return count($matches[0] ?? []);
}

function contact_spam_keywords(): array
{
    $lines = preg_split('/\R/u', site_setting_get('contact.spam_keywords', '')) ?: [];
    $keywords = [];
    foreach ($lines as $line) {
        $keyword = trim((string)$line);
        if ($keyword !== '') {
            $keywords[] = $keyword;
        }
    }
    return $keywords;
}

function contact_contains_spam_keyword(array $values): bool
{
    $haystack = implode("\n", $values);
    foreach (contact_spam_keywords() as $keyword) {
        if (function_exists('mb_stripos')) {
            if (mb_stripos($haystack, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        } elseif (stripos($haystack, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function contact_duplicate_hash(string $email, string $subject, string $message): string
{
    $normalized = rate_limit_client_ip() . "\n" . strtolower(trim($email)) . "\n" . preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r"], "\n", $subject))) . "\n" . preg_replace('/\s+/u', ' ', trim(str_replace(["\r\n", "\r"], "\n", $message)));
    return hash('sha256', $normalized);
}

function contact_duplicate_file(string $hash): string
{
    return rate_limit_dir() . DIRECTORY_SEPARATOR . 'contact_duplicate_' . $hash . '.json';
}

function contact_duplicate_cleanup(): void
{
    try {
        if (random_int(1, 100) !== 1) {
            return;
        }
    } catch (Throwable) {
        return;
    }

    $files = glob(rate_limit_dir() . DIRECTORY_SEPARATOR . 'contact_duplicate_*.json');
    if (!is_array($files)) {
        return;
    }

    $expiresBefore = time() - 3600;
    foreach ($files as $file) {
        if (!is_string($file) || is_link($file) || !is_file($file)) {
            continue;
        }
        $base = basename($file);
        if (!preg_match('/\Acontact_duplicate_[a-f0-9]{64}\.json\z/', $base)) {
            continue;
        }
        $mtime = @filemtime($file);
        if (is_int($mtime) && $mtime < $expiresBefore) {
            @unlink($file);
        }
    }
}

function contact_duplicate_is_recent(string $email, string $subject, string $message): bool
{
    $file = contact_duplicate_file(contact_duplicate_hash($email, $subject, $message));
    if (is_link($file) || !is_file($file)) {
        return false;
    }

    $now = time();
    $handle = @fopen($file, 'r');
    if ($handle === false) {
        return false;
    }
    $duplicate = false;
    if (@flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $last = is_array($decoded) ? (int)($decoded['last'] ?? 0) : 0;
        $duplicate = $last > $now - 600;
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);
    return $duplicate;
}

function contact_duplicate_mark(string $email, string $subject, string $message): void
{
    contact_duplicate_cleanup();
    $hash = contact_duplicate_hash($email, $subject, $message);
    $handle = @fopen(contact_duplicate_file($hash), 'c+');
    if ($handle === false) {
        return;
    }
    if (@flock($handle, LOCK_EX)) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['hash' => $hash, 'last' => time()]));
        fflush($handle);
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);
}

function contact_has_newline(string $value): bool
{
    return str_contains($value, "\r") || str_contains($value, "\n");
}

function about_access_ranking_text(): string
{
    try {
        if (!db_table_exists('in_logs')) {
            return 'アクセスランキングのデータがありません。';
        }

        $stmt = db()->query('SELECT COALESCE(NULLIF(ps.name, ""), NULLIF(in_logs.referer_host, ""), NULLIF(in_logs.ref_code, "")) AS site_name, COUNT(*) AS in_count FROM in_logs LEFT JOIN partner_sites ps ON ps.ref_code = in_logs.ref_code GROUP BY site_name ORDER BY in_count DESC, site_name ASC LIMIT 10');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        $rows = [];
    }

    if ($rows === []) {
        return 'アクセスランキングのデータがありません。';
    }

    $lines = [];
    foreach ($rows as $index => $row) {
        $siteName = trim((string)($row['site_name'] ?? ''));
        if ($siteName === '') {
            $siteName = '不明';
        }
        $lines[] = (string)($index + 1) . '. ' . $siteName . '：' . (string)((int)($row['in_count'] ?? 0));
    }

    return implode("\n", $lines);
}

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '' || $slug === CONTACT_PAGE_OLD_SLUG) {
    include __DIR__ . '/404.php';
    exit;
}

$oldAboutBody = "このサイトについての説明ページです。\n内容は管理画面から編集できます。";
$oldPrivacyPolicyBody = "プライバシーポリシーの初期ページです。\n内容は管理画面から編集できます。";
$privacyPolicyBody = "【 [サイト名]について 】\n「[サイト名]」(以下当サイト)にお越しくださってありがとうございます。\n※当サイトはアフィリエイトを使用しております。\n\n【 リンクについて 】\n当サイトはリンクフリーです。\nどのページのどの記事にリンクを貼って頂いてもかまいません。\nただし画像は元サイト様のものでお借りしているだけなので二次使用やダウンロードはご遠慮ください。\n\n【 当サイト情報 】\nサイト名：[サイト名]\nURL：[サイトURL] \nRSS：[サイトRSS] \n\n【 お問い合わせ 】\n何か問題等があればこちら「お問い合わせ」よりご連絡ください。\n※ただし、広告は募集しておりません。\n\n【 個人情報の利用目的 】\n当サイトでは、メールでの【お問い合わせ】にて「名前（ハンドルネーム）」「メールアドレス」等の個人情報をご登録いただく場合がございます。\nこれらの個人情報は必要な情報を電子メールなどをでご連絡する場合に利用させていただくものであり、個人情報をご提供いただく際の目的以外では利用いたしません。\n尚、動画などを購入して頂いた場合は、購入していただいたサイトに個人情報を登録して頂きますが、その場合は登録したサイトの「個人情報保護方針」の順じます。\n\n【 個人情報の第三者への開示 】\n当サイトでは、個人情報は適切に管理し、以下に該当する場合を除いて第三者に開示することはありません。\n※ただし「本人の承諾があった場合」「法令に基づく場合」「人の生命」「身体又は財産の保護」「公衆衛生・児童の健全育成上特に必要」な場合は除きます。\n\n【 個人情報の開示、訂正、追加、削除、利用停止 】\nご本人様からの個人データの開示、訂正、追加、削除、利用停止のご希望の場合には、ご本人様であることを確認させていただいた上、速やかに対応させていただきます。\n\n【 Googleアナリティクスについて 】\n当サイトでは、サイトの利用動向を分析する目的で「Googleアナリティクス」を使用しています。\nGoogleアナリティクスはトラフィックデータの収集のためにCookieを使用しています。\nこのトラフィックデータは匿名で収集されており、個人を特定するものではありません。\nまた、当サイトを経由してGoogle Analyticsにより収集されたデータはGoogle社のプライバシーポリシーに基づいて管理されており、当サイトはGoogle Analyticsのサービス利用による一切の損害について責任を負わないものとします。\nGoogleアナリティクスのプライバシーポリシーはこちらのページでご確認いただけます。\nこの機能はCookieを無効にすることで収集を拒否することが出来ますので、お使いのブラウザの設定をご確認ください。\n\n【 広告配信に関して 】\n当サイトが掲載している広告は電気通信事業者等の広告主と直接契約を結んで実施しているものと、広告代理店アフィリエイトサービスプロバイダーを通じて実施しているものがあります。\n当サイトでは成果報酬型広告の効果測定のため、利⽤者の⽅のアクセス情報を外部事業者に送信しております。\n個⼈を特定する情報ではございません。　また当該の情報が⽬的外利⽤される事は⼀切ございません。\n当サイトの広告は閲覧者の閲覧履歴、個⼈データ等を取得し、追跡などをするものではありません。\n\n■ 送信される情報の内容\n・ 閲覧したサイトのURL\n・ 成果報酬型広告の表⽰⽇時\n・ 成果報酬型広告のクリック⽇時\n・ 成果報酬型広告の計測に必要なクッキー情報\n・ 成果報酬型広告表⽰時及び広告クリック時のIPア ドレス\n・ 成果報酬型広告表⽰時及び広告クリック時に使⽤されたインターネット端末およびインターネットブラウザ−の種類\n\n■ 利⽤⽬的\n・ 成果報酬型広告の効果測定および不正防⽌のため\nまた、第三者配信事業者は、ユーザーの興味に応じた広告を表示するためにCookie（クッキー）を使用することがあります。\n「https://optout.aboutads.info/」にアクセスすることで Cookie を無効にできます。\n\n【 免責事項 】\n当サイトからリンクやバナーなどによって他のサイトに移動された場合、移動先サイトで提供される情報、サービス等について一切の責任を負いません。\n当サイトのコンテンツ・情報につきまして、可能な限り正確な情報を掲載するよう努めておりますが、誤情報が入り込んだり、情報が古くなっていることもございます。\n当サイトに掲載された内容によって生じた損害等の一切の責任を負いかねますのでご了承ください。\n\n【 プライバシーポリシーの変更について 】\n当サイトは、個人情報に関して適用される日本の法令を遵守するとともに、本ポリシーの内容を適宜見直しその改善に努めます。\n修正された最新のプライバシーポリシーは常に本ページにて開示されます。";
$aboutBody = "【 [サイト名]紹介 】\n[サイト名]([サイトURL])は、ASP「アプリケーションサービスプロバイダ（Application Service Provider）」というアフィリエイトの会社の広告を配信して運営しているウェブサイトです。 \n完全なるアダルトサイトで、18歳未満には提供できません。\n\n【 当サイト情報 】\nサイト名：[サイト名]\nURL：[サイトURL] \nRSS：[サイトRSS] \n\n【 リンクについて 】\n当サイトはリンクフリーです。\nどのページのどの記事にリンクを貼って頂いてもかまいません。\nただし画像は元サイト様のもので、お借りしているだけなので二次使用やダウンロードはご遠慮ください。\n\n【 相互リンクについて 】\n当サイトは相互リンクを募集していません。 \n申し訳ございません\n\n【 逆アクセスランキング 】 \n[アクセスランキング]\n\n【 お問い合わせ 】\n商品購入などについて購入したサイト様にご確認ください。\nもし当サイトについて何かあれば下記の「お問い合わせ」よりご連絡下さい。\n※ただし、広告は募集しておりません。\n\n<h3>個人情報保護方針</h3>\n個人情報保護方針(プライバシーポリシー)については下記のページをご覧下さい。\n・ [Privacy Policy(URL付き)]ページ\n\n【 「検索」について 】\nあなたの好きなジャンルを探す為にあるこの「検索」をぜひご活用ください。 ";
$p = null;
if (db_table_exists('fixed_pages')) {
    migrate_contact_page_slug();
    $st = db()->prepare('SELECT * FROM fixed_pages WHERE slug=:slug AND is_published=1 LIMIT 1');
    $st->execute([':slug' => $slug]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($p) && $slug === 'about' && ((string)($p['body'] ?? '') === $oldAboutBody || str_contains((string)($p['body'] ?? ''), '【 ■について 】'))) {
        $currentAboutBody = (string)($p['body'] ?? '');
        $p['body'] = $aboutBody;
        db()->prepare('UPDATE fixed_pages SET body=:body, updated_at=NOW() WHERE slug="about" AND body=:old_body')->execute([
            ':body' => $aboutBody,
            ':old_body' => $currentAboutBody,
        ]);
    }
    if (is_array($p) && $slug === 'privacy-policy' && (string)($p['body'] ?? '') === $oldPrivacyPolicyBody) {
        $p['body'] = $privacyPolicyBody;
        db()->prepare('UPDATE fixed_pages SET body=:body, updated_at=NOW() WHERE slug="privacy-policy" AND body=:old_body')->execute([
            ':body' => $privacyPolicyBody,
            ':old_body' => $oldPrivacyPolicyBody,
        ]);
    }
}

if (!is_array($p)) {
    $defaultPages = [
        'about' => ['title' => 'サイトについて', 'body' => $aboutBody, 'seo_title' => '', 'seo_description' => ''],
        'privacy-policy' => ['title' => 'Privacy Policy', 'body' => $privacyPolicyBody, 'seo_title' => '', 'seo_description' => ''],
        CONTACT_PAGE_SLUG => ['title' => 'お問い合わせ', 'body' => 'お問い合わせは下記フォームよりご連絡ください。', 'seo_title' => '', 'seo_description' => ''],
    ];
    $p = $defaultPages[$slug] ?? null;
}

if (!is_array($p)) {
    include __DIR__ . '/404.php';
    exit;
}

$formErrors = [];
$contactSuccess = false;
$contactForm = [
    'subject' => '',
    'message' => '',
    'name' => '',
    'email' => '',
];

$isContactPage = $slug === CONTACT_PAGE_SLUG;

if ($isContactPage && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    $rateLimitAllowed = rate_limit_allow('contact_form', 3, 300);
    $spamReason = null;

    $contactForm['subject'] = trim((string)($_POST['subject'] ?? ''));
    $contactForm['message'] = trim((string)($_POST['message'] ?? ''));
    $contactForm['name'] = trim((string)($_POST['name'] ?? ''));
    $contactForm['email'] = trim((string)($_POST['email'] ?? ''));
    $formAge = contact_form_consume_age((string)($_POST['contact_form_id'] ?? ''));

    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        $formErrors[] = 'リクエストが無効です。';
    }
    if ($contactForm['name'] === '') {
        $formErrors[] = '氏名は必須です。';
    }
    if ($contactForm['email'] === '') {
        $formErrors[] = 'メールアドレスは必須です。';
    }
    if ($contactForm['subject'] === '') {
        $formErrors[] = '題名は必須です。';
    }
    if ($contactForm['message'] === '') {
        $formErrors[] = '内容は必須です。';
    }
    if (contact_form_strlen($contactForm['name']) > 100) {
        $formErrors[] = '氏名は100文字以内で入力してください。';
    }
    if (contact_form_strlen($contactForm['email']) > 254) {
        $formErrors[] = 'メールアドレスは254文字以内で入力してください。';
    }
    if (contact_form_strlen($contactForm['subject']) > 200) {
        $formErrors[] = '題名は200文字以内で入力してください。';
    }
    if (contact_form_strlen($contactForm['message']) > 5000) {
        $formErrors[] = '内容は5000文字以内で入力してください。';
    }
    if ($contactForm['email'] !== '' && !filter_var($contactForm['email'], FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'メールアドレスの形式が正しくありません。';
    }
    if (contact_has_newline($contactForm['name']) || contact_has_newline($contactForm['email']) || contact_has_newline($contactForm['subject'])) {
        $spamReason = 'header_injection';
    }
    if ($formAge === null || $formAge > 7200) {
        $formErrors[] = 'フォームの有効期限が切れました。再度お試しください。';
    }

    if ($formErrors === []) {
        if (trim((string)($_POST['website'] ?? '')) !== '') {
            $spamReason = 'honeypot';
        } elseif ($formAge < 3) {
            $spamReason = 'too_fast';
        } elseif (contact_url_count($contactForm['subject'] . "\n" . $contactForm['message']) > 3) {
            $spamReason = 'too_many_urls';
        } elseif (contact_contains_spam_keyword([$contactForm['name'], $contactForm['email'], $contactForm['subject'], $contactForm['message']])) {
            $spamReason = 'keyword';
        } elseif (contact_duplicate_is_recent($contactForm['email'], $contactForm['subject'], $contactForm['message'])) {
            $spamReason = 'duplicate';
        } elseif (!$rateLimitAllowed) {
            $spamReason = 'rate_limit';
        }
    }

    if ($formErrors === [] && $spamReason !== null) {
        contact_spam_log($spamReason);
        $contactSuccess = true;
        $contactForm = ['subject' => '', 'message' => '', 'name' => '', 'email' => ''];
    } elseif ($formErrors === []) {
        [$toEmail, $toEmailError] = contact_destination_email();

        $logId = null;
        if (db_table_exists('mail_logs')) {
            $insert = db()->prepare('INSERT INTO mail_logs(direction,from_name,from_email,to_email,subject,body,status,last_error,created_at,updated_at) VALUES ("in",:from_name,:from_email,:to_email,:subject,:body,"received",NULL,NOW(),NOW())');
            $insert->execute([
                ':from_name' => $contactForm['name'] !== '' ? $contactForm['name'] : null,
                ':from_email' => $contactForm['email'] !== '' ? $contactForm['email'] : null,
                ':to_email' => $toEmail !== '' ? $toEmail : null,
                ':subject' => mb_substr($contactForm['subject'], 0, 255),
                ':body' => $contactForm['message'],
            ]);
            $logId = (int)db()->lastInsertId();
        }

        $status = 'failed';
        $lastError = $toEmailError;
        $safeSubject = str_replace(["\r", "\n"], ' ', $contactForm['subject']);
        $mailSubject = '【お問い合わせ】' . $safeSubject;
        $mailBody = "相手の名前: " . $contactForm['name'] . "\n"
            . "相手のメールアドレス: " . $contactForm['email'] . "\n"
            . "題名: " . $contactForm['subject'] . "\n"
            . "内容:\n" . $contactForm['message'];

        if ($toEmail !== '') {
            $headers = ['Reply-To: ' . $contactForm['email']];
            $ok = @mail($toEmail, $mailSubject, $mailBody, implode("\r\n", $headers));
            $status = $ok ? 'sent' : 'failed';
            $lastError = $ok ? null : 'mail() failed';
        }

        if ($logId !== null) {
            db()->prepare('UPDATE mail_logs SET status=:status,last_error=:last_error,updated_at=NOW() WHERE id=:id')
                ->execute([
                    ':status' => $status,
                    ':last_error' => $lastError,
                    ':id' => $logId,
                ]);
        }

        if ($status === 'sent') {
            contact_duplicate_mark($contactForm['email'], $contactForm['subject'], $contactForm['message']);
            $contactSuccess = true;
            $contactForm = ['subject' => '', 'message' => '', 'name' => '', 'email' => ''];
        } else {
            $formErrors[] = '送信に失敗しました。時間をおいて再度お試しください。';
        }
    }
}

$contactFormId = $isContactPage && !$contactSuccess ? contact_form_issue_id() : '';


if ($slug === 'about' || $slug === 'privacy-policy') {
    $p['body'] = str_replace(
        ['[サイト名]', '[サイトURL]', '[サイトRSS]', '[アクセスランキング]', '[Privacy Policy(URL付き)]'],
        [site_setting_get('site.title', site_setting_get('site.name', APP_NAME)), site_setting_get('site.url', app_url()), public_url('feed.php'), about_access_ranking_text(), 'Privacy Policy（' . public_url('page.php?slug=privacy-policy') . '）'],
        (string)$p['body']
    );
}

$pageTitle = (string)((($p['seo_title'] ?? '') !== '') ? $p['seo_title'] : $p['title']);
$pageDescription = (string)($p['seo_description'] ?? '');
$canonicalUrl = public_url('page.php?slug=' . rawurlencode($slug));
$ogUrl = $canonicalUrl;
$pageBodyHtml = nl2br(e((string)$p['body']));
if ($slug === 'about') {
    $pageBodyHtml = str_replace(['&lt;h3&gt;', '&lt;/h3&gt;'], ['<h3>', '</h3>'], $pageBodyHtml);
}

include __DIR__ . '/partials/header.php';
?>
        <section class="block">
            <h1 class="section-title"><?php echo e((string)$p['title']); ?></h1>
            <?php echo $pageBodyHtml; ?>
        </section>

        <?php if ($isContactPage) : ?>
            <section class="block">
                <h2 class="section-title">お問い合わせフォーム</h2>
                <?php if ($contactSuccess) : ?>
                    <p>送信しました</p>
                <?php else : ?>
                    <?php foreach ($formErrors as $error) : ?>
                        <p><?php echo e((string)$error); ?></p>
                    <?php endforeach; ?>
                    <form class="contact-form" method="post" action="<?php echo e((string)($_SERVER['REQUEST_URI'] ?? '/page.php?slug=que')); ?>">
                        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="contact_form_id" value="<?php echo e($contactFormId); ?>">
                        <input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="display:none">

                        <label for="contact-name">氏名</label>
                        <input id="contact-name" name="name" value="<?php echo e($contactForm['name']); ?>" maxlength="100" required>

                        <label for="contact-email">メールアドレス</label>
                        <input id="contact-email" name="email" type="email" value="<?php echo e($contactForm['email']); ?>" maxlength="254" required>

                        <label for="contact-subject">題名</label>
                        <input id="contact-subject" name="subject" value="<?php echo e($contactForm['subject']); ?>" maxlength="200" required>

                        <label for="contact-message">内容</label>
                        <textarea id="contact-message" name="message" rows="10" maxlength="5000" required><?php echo e($contactForm['message']); ?></textarea>

                        <button type="submit">送信</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
