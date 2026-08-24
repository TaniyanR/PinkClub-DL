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
    pcf_session_start();
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
    pcf_session_start();
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

function about_access_ranking_html(): string
{
    try {
        if (!db_table_exists('in_logs')) {
            return '<p class="pcf-reverse-ranking__empty">アクセスランキングのデータがありません。</p>';
        }

        $stmt = db()->query('SELECT COALESCE(NULLIF(ps.name, ""), NULLIF(in_logs.referer_host, ""), NULLIF(in_logs.ref_code, "")) AS site_name, MAX(NULLIF(ps.url, "")) AS site_url, COUNT(*) AS in_count FROM in_logs LEFT JOIN partner_sites ps ON ps.ref_code = in_logs.ref_code GROUP BY site_name ORDER BY in_count DESC, site_name ASC LIMIT 10');
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable) {
        $rows = [];
    }

    if ($rows === []) {
        return '<p class="pcf-reverse-ranking__empty">アクセスランキングのデータがありません。</p>';
    }

    $html = '<ol class="pcf-reverse-ranking" aria-label="逆アクセスランキング">';
    foreach ($rows as $index => $row) {
        $siteName = trim((string)($row['site_name'] ?? ''));
        if ($siteName === '') {
            $siteName = '不明';
        }
        $siteUrl = trim((string)($row['site_url'] ?? ''));
        $siteLink = e($siteName);
        if (filter_var($siteUrl, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string)parse_url($siteUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            $siteLink = '<a href="' . e($siteUrl) . '" target="_blank" rel="noopener noreferrer nofollow">' . e($siteName) . '</a>';
        }
        $html .= '<li class="pcf-reverse-ranking__row">'
            . '<span class="pcf-reverse-ranking__position">' . e((string)($index + 1)) . '</span>'
            . '<span class="pcf-reverse-ranking__site">' . $siteLink . '</span>'
            . '<span class="pcf-reverse-ranking__count"><strong>' . e((string)((int)($row['in_count'] ?? 0))) . '</strong> access</span>'
            . '</li>';
    }

    return $html . '</ol>';
}

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '' || $slug === CONTACT_PAGE_OLD_SLUG) {
    include __DIR__ . '/404.php';
    exit;
}

$oldAboutBody = "このサイトについての説明ページです。\n内容は管理画面から編集できます。";
$oldPrivacyPolicyBody = "プライバシーポリシーの初期ページです。\n内容は管理画面から編集できます。";
$privacyPolicyBody = "【 [サイト名]について 】\n「[サイト名]」(以下当サイト)にお越しくださってありがとうございます。\n※当サイトはアフィリエイトを使用しております。\n\n【 リンクについて 】\n当サイトはリンクフリーです。\nどのページのどの記事にリンクを貼って頂いてもかまいません。\nただし画像は元サイト様のものでお借りしているだけなので二次使用やダウンロードはご遠慮ください。\n\n【 当サイト情報 】\nサイト名：[サイト名]\nURL：[サイトURL] \nRSS：[サイトRSS] \n\n【 お問い合わせ 】\n何か問題等があればこちら「お問い合わせ」よりご連絡ください。\n※ただし、広告は募集しておりません。\n\n【 個人情報の利用目的 】\n当サイトでは、メールでの【お問い合わせ】にて「名前（ハンドルネーム）」「メールアドレス」等の個人情報をご登録いただく場合がございます。\nこれらの個人情報は必要な情報を電子メールなどをでご連絡する場合に利用させていただくものであり、個人情報をご提供いただく際の目的以外では利用いたしません。\n尚、動画などを購入して頂いた場合は、購入していただいたサイトに個人情報を登録して頂きますが、その場合は登録したサイトの「個人情報保護方針」の順じます。\n\n【 個人情報の第三者への開示 】\n当サイトでは、個人情報は適切に管理し、以下に該当する場合を除いて第三者に開示することはありません。\n※ただし「本人の承諾があった場合」「法令に基づく場合」「人の生命」「身体又は財産の保護」「公衆衛生・児童の健全育成上特に必要」な場合は除きます。\n\n【 個人情報の開示、訂正、追加、削除、利用停止 】\nご本人様からの個人データの開示、訂正、追加、削除、利用停止のご希望の場合には、ご本人様であることを確認させていただいた上、速やかに対応させていただきます。\n\n【 Googleアナリティクスについて 】\n当サイトでは、サイトの利用動向を分析する目的で「Googleアナリティクス」を使用しています。\nGoogleアナリティクスはトラフィックデータの収集のためにCookieを使用しています。\nこのトラフィックデータは匿名で収集されており、個人を特定するものではありません。\nまた、当サイトを経由してGoogle Analyticsにより収集されたデータはGoogle社のプライバシーポリシーに基づいて管理されており、当サイトはGoogle Analyticsのサービス利用による一切の損害について責任を負わないものとします。\nGoogleアナリティクスのプライバシーポリシーはこちらのページでご確認いただけます。\nこの機能はCookieを無効にすることで収集を拒否することが出来ますので、お使いのブラウザの設定をご確認ください。\n\n【 広告配信に関して 】\n当サイトが掲載している広告は電気通信事業者等の広告主と直接契約を結んで実施しているものと、広告代理店アフィリエイトサービスプロバイダーを通じて実施しているものがあります。\n当サイトでは成果報酬型広告の効果測定のため、利⽤者の⽅のアクセス情報を外部事業者に送信しております。\n個⼈を特定する情報ではございません。　また当該の情報が⽬的外利⽤される事は⼀切ございません。\n当サイトの広告は閲覧者の閲覧履歴、個⼈データ等を取得し、追跡などをするものではありません。\n\n■ 送信される情報の内容\n・ 閲覧したサイトのURL\n・ 成果報酬型広告の表⽰⽇時\n・ 成果報酬型広告のクリック⽇時\n・ 成果報酬型広告の計測に必要なクッキー情報\n・ 成果報酬型広告表⽰時及び広告クリック時のIPア ドレス\n・ 成果報酬型広告表⽰時及び広告クリック時に使⽤されたインターネット端末およびインターネットブラウザ−の種類\n\n■ 利⽤⽬的\n・ 成果報酬型広告の効果測定および不正防⽌のため\nまた、第三者配信事業者は、ユーザーの興味に応じた広告を表示するためにCookie（クッキー）を使用することがあります。\n「https://optout.aboutads.info/」にアクセスすることで Cookie を無効にできます。\n\n【 免責事項 】\n当サイトからリンクやバナーなどによって他のサイトに移動された場合、移動先サイトで提供される情報、サービス等について一切の責任を負いません。\n当サイトのコンテンツ・情報につきまして、可能な限り正確な情報を掲載するよう努めておりますが、誤情報が入り込んだり、情報が古くなっていることもございます。\n当サイトに掲載された内容によって生じた損害等の一切の責任を負いかねますのでご了承ください。\n\n【 プライバシーポリシーの変更について 】\n当サイトは、個人情報に関して適用される日本の法令を遵守するとともに、本ポリシーの内容を適宜見直しその改善に努めます。\n修正された最新のプライバシーポリシーは常に本ページにて開示されます。";
$aboutBody = "【 [サイト名]紹介 】\n[サイト名]([サイトURL])は、ASP「アプリケーションサービスプロバイダ（Application Service Provider）」というアフィリエイトの会社の広告を配信して運営しているウェブサイトです。 \n完全なるアダルトサイトで、18歳未満には提供できません。\n\n【 当サイト情報 】\nサイト名：[サイト名]\nURL：[サイトURL] \nRSS：[サイトRSS] \n\n【 リンクについて 】\n当サイトはリンクフリーです。\nどのページのどの記事にリンクを貼って頂いてもかまいません。\nただし画像は元サイト様のもので、お借りしているだけなので二次使用やダウンロードはご遠慮ください。\n\n【 相互リンクについて 】\n当サイトは相互リンクを募集していません。 \n申し訳ございません\n\n【 逆アクセスランキング 】 \n[アクセスランキング]\n\n【 お問い合わせ 】\n商品購入などについて購入したサイト様にご確認ください。\nもし当サイトについて何かあれば下記の「お問い合わせ」よりご連絡下さい。\n※ただし、広告は募集しておりません。\n\n<h3>個人情報保護方針</h3>\n個人情報保護方針(プライバシーポリシー)については下記をご覧下さい。\n・ [Privacy Policy(URL付き)]\n\n【 「検索」について 】\nあなたの好きなジャンルを探す為にあるこの「検索」をぜひご活用ください。 ";
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
$contactFormType = $isContactPage && (string)($_GET['type'] ?? '') === 'deletion' ? 'deletion' : 'contact';
$deletionReceipt = $isContactPage ? trim((string)($_GET['receipt'] ?? '')) : '';
$deletionError = $isContactPage ? trim((string)($_GET['deletion_error'] ?? '')) : '';
if ($deletionReceipt !== '' || $deletionError !== '') {
    $contactFormType = 'deletion';
}

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
        [site_setting_get('site.title', site_setting_get('site.name', APP_NAME)), site_setting_get('site.url', app_url()), public_url('feed-60.php'), '__PCF_ACCESS_RANKING__', '__PCF_PRIVACY_POLICY_LINK__'],
        (string)$p['body']
    );
    $p['body'] = str_replace(
        [public_url('feed.php'), rtrim(app_url(), '/') . '/feed.php'],
        public_url('feed-60.php'),
        (string)$p['body']
    );
}

$displayPageTitle = $isContactPage ? 'お問い合わせ・掲載削除依頼' : (string)$p['title'];
$pageTitle = $isContactPage
    ? $displayPageTitle
    : (string)((($p['seo_title'] ?? '') !== '') ? $p['seo_title'] : $p['title']);
$pageDescription = (string)($p['seo_description'] ?? '');
$canonicalUrl = public_url('page.php?slug=' . rawurlencode($slug));
$ogUrl = $canonicalUrl;
$pageBodyHtml = nl2br(e((string)$p['body']));
if ($slug === 'about' || $slug === 'privacy-policy') {
    $contactHref = e(public_url('page.php?slug=' . rawurlencode(CONTACT_PAGE_SLUG)));
    $contactLink = '<a href="' . $contactHref . '">お問い合わせ</a>';
    $privacyHref = e(public_url('page.php?slug=privacy-policy'));
    $privacyLink = '<a href="' . $privacyHref . '">Privacy Policy</a>';
    $rankingHtml = $slug === 'about' ? about_access_ranking_html() : '';
    $pageBodyHtml = str_replace(
        ['「お問い合わせ」', '【お問い合わせ】にて', '__PCF_PRIVACY_POLICY_LINK__', '__PCF_ACCESS_RANKING__'],
        ['「' . $contactLink . '」', '【' . $contactLink . '】にて', $privacyLink, $rankingHtml],
        $pageBodyHtml
    );
    $escapedOldPrivacy = e('Privacy Policy（' . public_url('page.php?slug=privacy-policy') . '）');
    $pageBodyHtml = str_replace($escapedOldPrivacy, $privacyLink, $pageBodyHtml);
}
if ($slug === 'about') {
    $pageBodyHtml = str_replace(['&lt;h3&gt;', '&lt;/h3&gt;'], ['<h3>', '</h3>'], $pageBodyHtml);
}

include __DIR__ . '/partials/header.php';
?>
        <section class="block">
            <h1 class="section-title"><?php echo e($displayPageTitle); ?></h1>
            <?php if ($isContactPage) : ?>
                <p>ご用件に応じて、下記からフォームを選択してください。</p>
            <?php else : ?>
                <?php echo $pageBodyHtml; ?>
            <?php endif; ?>
        </section>

        <?php if ($isContactPage) : ?>
            <section class="block">
                <div class="contact-form-tabs" role="tablist" aria-label="お問い合わせ種別">
                    <button id="contact-tab-contact" type="button" class="contact-form-tab<?= $contactFormType === 'contact' ? ' is-active' : '' ?>" data-contact-form="contact" role="tab" aria-controls="contact-panel-contact" aria-selected="<?= $contactFormType === 'contact' ? 'true' : 'false' ?>" tabindex="<?= $contactFormType === 'contact' ? '0' : '-1' ?>">一般のお問い合わせ</button>
                    <button id="contact-tab-deletion" type="button" class="contact-form-tab<?= $contactFormType === 'deletion' ? ' is-active' : '' ?>" data-contact-form="deletion" role="tab" aria-controls="contact-panel-deletion" aria-selected="<?= $contactFormType === 'deletion' ? 'true' : 'false' ?>" tabindex="<?= $contactFormType === 'deletion' ? '0' : '-1' ?>"><span>掲載削除依頼</span><small>本人確認書類が必要</small></button>
                </div>

                <div id="contact-panel-contact" data-contact-panel="contact" role="tabpanel" aria-labelledby="contact-tab-contact"<?= $contactFormType !== 'contact' ? ' hidden' : '' ?>>
                    <h2 class="section-title">一般のお問い合わせ</h2>
                    <p>ご不明な点やご意見・ご要望などをお送りください。</p>
                    <?php if ($contactSuccess) : ?>
                        <p class="contact-form-notice is-success">お問い合わせを送信しました。</p>
                    <?php else : ?>
                        <?php foreach ($formErrors as $error) : ?>
                            <p class="contact-form-notice is-error"><?php echo e((string)$error); ?></p>
                        <?php endforeach; ?>
                        <form class="contact-form" method="post" action="<?php echo e(public_url('page.php?slug=que')); ?>">
                            <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
                            <input type="hidden" name="contact_form_id" value="<?php echo e($contactFormId); ?>">
                            <input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="display:none">

                            <label for="contact-name">氏名（必須）</label>
                            <input id="contact-name" name="name" value="<?php echo e($contactForm['name']); ?>" maxlength="100" required>

                            <label for="contact-email">メールアドレス（必須）</label>
                            <input id="contact-email" name="email" type="email" value="<?php echo e($contactForm['email']); ?>" maxlength="254" required>

                            <label for="contact-subject">題名</label>
                            <input id="contact-subject" name="subject" value="<?php echo e($contactForm['subject']); ?>" maxlength="200" required>

                            <label for="contact-message">内容</label>
                            <textarea id="contact-message" name="message" rows="10" maxlength="5000" required><?php echo e($contactForm['message']); ?></textarea>

                            <button type="submit">送信</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div id="contact-panel-deletion" data-contact-panel="deletion" role="tabpanel" aria-labelledby="contact-tab-deletion"<?= $contactFormType !== 'deletion' ? ' hidden' : '' ?>>
                    <h2 class="section-title">掲載削除依頼</h2>
                    <p>出演者ご本人、正当な代理人または権利者から受け付けます。入力内容と本人確認書類は管理画面・データベースに保存せず、受付用メールにのみ送信します。</p>
                    <?php if ($deletionReceipt !== '') : ?>
                        <p class="contact-form-notice is-success">掲載削除依頼を受け付けました。受付番号：<?php echo e($deletionReceipt); ?></p>
                    <?php elseif ($deletionError !== '') : ?>
                        <p class="contact-form-notice is-error"><?php echo e($deletionError); ?></p>
                    <?php endif; ?>
                    <form class="contact-form" method="post" action="<?php echo e(public_url('deletion_request_submit.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="_token" value="<?php echo e(csrf_token()); ?>">
                        <input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="display:none">

                        <label for="deletion-name">お名前（本名・必須）</label>
                        <input id="deletion-name" name="deletion_name" maxlength="100" placeholder="例：山田 花子" required>

                        <label for="deletion-email">連絡用メールアドレス（必須）</label>
                        <input id="deletion-email" name="deletion_email" type="email" maxlength="254" placeholder="例：example@example.com" required>

                        <label for="deletion-phone">電話番号（任意）</label>
                        <input id="deletion-phone" name="deletion_phone" maxlength="30" placeholder="例：090-1234-5678">

                        <label for="deletion-urls">該当ページURL（必須）</label>
                        <textarea id="deletion-urls" name="deletion_urls" rows="5" maxlength="5000" placeholder="複数ある場合は1行ずつ全て記載してください" required></textarea>

                        <label for="identity-document">本人確認書類（必須）</label>
                        <input id="identity-document" name="identity_document" type="file" accept="image/jpeg,image/png,application/pdf" required>
                        <small>JPEG・PNG・PDF、5MB以内。受付メールに添付して送信し、当サイトのサーバーには保存しません。</small>

                        <label for="deletion-reason">申請理由（必須）</label>
                        <textarea id="deletion-reason" name="deletion_reason" rows="8" maxlength="5000" placeholder="削除を希望する理由と経緯をご記入ください" required></textarea>

                        <div class="deletion-consent">
                            <input id="deletion-consent" type="checkbox" name="deletion_consent" value="1" required>
                            <label for="deletion-consent">プライバシーポリシーを読み、本人確認書類を提出することに同意します（提出書類は本人確認の目的以外には使用しません）。</label>
                        </div>

                        <button type="submit">掲載削除依頼を送信する</button>
                    </form>
                </div>
            </section>
            <script>
            (() => {
                const tabs = document.querySelectorAll('[data-contact-form]');
                const panels = document.querySelectorAll('[data-contact-panel]');
                tabs.forEach((tab) => tab.addEventListener('click', () => {
                    const type = tab.dataset.contactForm || 'contact';
                    tabs.forEach((button) => {
                        const active = button.dataset.contactForm === type;
                        button.classList.toggle('is-active', active);
                        button.setAttribute('aria-selected', active ? 'true' : 'false');
                        button.tabIndex = active ? 0 : -1;
                    });
                    panels.forEach((panel) => { panel.hidden = panel.dataset.contactPanel !== type; });
                }));
            })();
            </script>
        <?php endif; ?>

<?php include __DIR__ . '/partials/footer.php'; ?>
