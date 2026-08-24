<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/rate_limit.php';

function deletion_request_destination_email(): string
{
    $settingsEmail = setting_admin_email('');
    if ($settingsEmail !== '' && filter_var($settingsEmail, FILTER_VALIDATE_EMAIL)) {
        return $settingsEmail;
    }

    return '';
}

function deletion_request_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function deletion_request_send_mail(
    string $to,
    string $replyTo,
    string $receipt,
    string $textBody,
    string $tmpPath,
    string $mime,
    string $extension
): bool {
    $fileData = @file_get_contents($tmpPath);
    if (!is_string($fileData) || $fileData === '') {
        return false;
    }

    $boundary = '=_PCF_' . bin2hex(random_bytes(16));
    $subjectText = '【削除依頼】受付番号 ' . $receipt;
    $subject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subjectText, 'UTF-8', 'B', "\r\n")
        : $subjectText;
    $safeReplyTo = str_replace(["\r", "\n"], '', $replyTo);
    $attachmentName = 'identity-document-' . preg_replace('/[^A-Za-z0-9_-]/', '', $receipt) . '.' . $extension;

    $headers = [
        'MIME-Version: 1.0',
        'Reply-To: ' . $safeReplyTo,
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($textBody), 76, "\r\n") . "\r\n"
        . '--' . $boundary . "\r\n"
        . 'Content-Type: ' . $mime . '; name="' . $attachmentName . '"' . "\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . $attachmentName . '"' . "\r\n\r\n"
        . chunk_split(base64_encode($fileData), 76, "\r\n")
        . '--' . $boundary . "--\r\n";

    return @mail($to, $subject, $body, implode("\r\n", $headers));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$backUrl = public_url('page.php?slug=que&type=deletion');

try {
    if (!csrf_verify((string)($_POST['_token'] ?? ''))) {
        throw new RuntimeException('リクエストが無効です。');
    }
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        header('Location: ' . $backUrl . '&submitted=1');
        exit;
    }

    $name = trim((string)($_POST['deletion_name'] ?? ''));
    $email = trim((string)($_POST['deletion_email'] ?? ''));
    $phone = trim((string)($_POST['deletion_phone'] ?? ''));
    $pageUrls = trim((string)($_POST['deletion_urls'] ?? ''));
    $reason = trim((string)($_POST['deletion_reason'] ?? ''));
    $consent = (string)($_POST['deletion_consent'] ?? '') === '1';

    if ($name === '' || $email === '' || $pageUrls === '' || $reason === '') {
        throw new RuntimeException('必須項目を入力してください。');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_contains($email, "\r") || str_contains($email, "\n")) {
        throw new RuntimeException('メールアドレスの形式が正しくありません。');
    }
    if (!$consent) {
        throw new RuntimeException('プライバシーポリシーへの同意が必要です。');
    }
    if (deletion_request_length($name) > 100
        || deletion_request_length($email) > 254
        || deletion_request_length($phone) > 30
        || deletion_request_length($pageUrls) > 5000
        || deletion_request_length($reason) > 5000
    ) {
        throw new RuntimeException('入力内容が長すぎます。');
    }

    $urls = preg_split('/\R/u', $pageUrls) ?: [];
    $validUrlFound = false;
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url === '') {
            continue;
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('該当ページURLの形式が正しくありません。');
        }
        $validUrlFound = true;
    }
    if (!$validUrlFound) {
        throw new RuntimeException('該当ページURLを入力してください。');
    }

    $upload = $_FILES['identity_document'] ?? null;
    if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('本人確認書類を選択してください。');
    }
    $size = (int)($upload['size'] ?? 0);
    if ($size < 1 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('本人確認書類は5MB以内にしてください。');
    }
    $tmp = (string)($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('アップロードされたファイルを確認できません。');
    }
    if (!class_exists('finfo')) {
        throw new RuntimeException('本人確認書類を検証できません。');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($tmp);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('本人確認書類はJPEG・PNG・PDFのみ対応しています。');
    }
    if (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false) {
        throw new RuntimeException('画像ファイルを読み取れません。');
    }

    $toEmail = deletion_request_destination_email();
    if ($toEmail === '') {
        throw new RuntimeException('送信先メールアドレスが設定されていません。');
    }
    if (!rate_limit_allow('deletion_request', 5, 300)) {
        throw new RuntimeException('5分以内の送信回数が上限（5回）に達しました。5分後に再度お試しください。');
    }

    $receipt = 'DEL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $attachmentName = 'identity-document-' . preg_replace('/[^A-Za-z0-9_-]/', '', $receipt) . '.' . $extensions[$mime];
    $attachmentSize = number_format($size / 1024, 1) . ' KB';
    $mailBody = "受付番号: {$receipt}\n"
        . "対象サイト: PinkClub-DL\n"
        . "お名前（本名）: {$name}\n"
        . "メールアドレス: {$email}\n"
        . "電話番号: " . ($phone !== '' ? $phone : '未入力') . "\n\n"
        . "該当ページURL:\n{$pageUrls}\n\n"
        . "申請理由:\n{$reason}\n\n"
        . "本人確認書類（必須）：添付済み\n"
        . "添付ファイル：{$attachmentName} ({$attachmentSize})\n"
        . "本人確認書類はサーバーには保存していません。";

    if (!deletion_request_send_mail($toEmail, $email, $receipt, $mailBody, $tmp, $mime, $extensions[$mime])) {
        throw new RuntimeException('削除依頼メールの送信に失敗しました。時間をおいて再度お試しください。');
    }

    header('Location: ' . $backUrl . '&receipt=' . rawurlencode($receipt));
    exit;
} catch (Throwable $e) {
    $message = function_exists('mb_substr')
        ? mb_substr($e->getMessage(), 0, 200, 'UTF-8')
        : substr($e->getMessage(), 0, 200);
    header('Location: ' . $backUrl . '&deletion_error=' . rawurlencode($message));
    exit;
}
