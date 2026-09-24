<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/rate_limit.php';

function deletion_request_destination_email(): string
{
    $settingsEmail = setting_admin_email('');
    return $settingsEmail !== '' && filter_var($settingsEmail, FILTER_VALIDATE_EMAIL) ? $settingsEmail : '';
}

function deletion_request_send_mail(string $to, string $replyTo, string $receipt, string $textBody, string $tmpPath, string $mime, string $extension): bool
{
    $fileData = @file_get_contents($tmpPath);
    if (!is_string($fileData) || $fileData === '') {
        return false;
    }

    $boundary = '=_PCF_' . bin2hex(random_bytes(16));
    $subjectText = '【削除依頼】受付番号 ' . $receipt;
    $subject = function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($subjectText, 'UTF-8', 'B', "\r\n") : $subjectText;
    $safeReplyTo = str_replace(["\r", "\n"], '', $replyTo);
    $attachmentName = 'identity-document-' . preg_replace('/[^A-Za-z0-9_-]/', '', $receipt) . '.' . $extension;
    $headers = [
        'MIME-Version: 1.0',
        'Reply-To: ' . $safeReplyTo,
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
    ];
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody . "\r\n\r\n"
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
    if (!rate_limit_allow('deletion_request', 2, 900)) {
        throw new RuntimeException('短時間に複数回送信されています。時間をおいて再度お試しください。');
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('メールアドレスの形式が正しくありません。');
    }
    if (!$consent) {
        throw new RuntimeException('プライバシーポリシーへの同意が必要です。');
    }
    if (mb_strlen($name) > 100 || mb_strlen($email) > 254 || mb_strlen($phone) > 30 || mb_strlen($pageUrls) > 5000 || mb_strlen($reason) > 5000) {
        throw new RuntimeException('入力内容が長すぎます。');
    }

    $urls = preg_split('/\R/u', $pageUrls) ?: [];
    $validUrlFound = false;
    foreach ($urls as $url) {
        $url = trim((string)$url);
        if ($url === '') continue;
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
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
        . "本人確認書類（必須）: 添付済み\n"
        . "添付ファイル: {$attachmentName} ({$attachmentSize})\n"
        . "本人確認書類はサーバーには保存していません。";

    if (!deletion_request_send_mail($toEmail, $email, $receipt, $mailBody, $tmp, $mime, $extensions[$mime])) {
        throw new RuntimeException('削除依頼メールの送信に失敗しました。時間をおいて再度お試しください。');
    }

    header('Location: ' . $backUrl . '&receipt=' . rawurlencode($receipt));
    exit;
} catch (RuntimeException $e) {
    header('Location: ' . $backUrl . '&deletion_error=' . rawurlencode(mb_substr($e->getMessage(), 0, 200)));
    exit;
} catch (Throwable $e) {
    error_log('deletion request failed: ' . $e->getMessage());
    header('Location: ' . $backUrl . '&deletion_error=' . rawurlencode('処理中にエラーが発生しました。時間をおいて再度お試しください。'));
    exit;
}
