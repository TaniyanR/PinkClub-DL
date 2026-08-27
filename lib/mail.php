<?php

declare(strict_types=1);

function pcf_mail_diagnostic_reset(): void { $GLOBALS['pcf_mail_diagnostic'] = ['attempts' => []]; }
function pcf_mail_diagnostic_add(string $transport, bool $success, string $detail = ''): void
{
    $safeDetail = match (true) {
        $success => 'accepted',
        $detail === 'not configured' => 'not configured',
        $detail === 'sender unavailable' => 'sender unavailable',
        $detail === 'function unavailable' => 'function unavailable',
        $detail === 'invalid destination' => 'invalid destination',
        default => 'delivery failed',
    };
    $GLOBALS['pcf_mail_diagnostic']['attempts'][] = [
        'transport' => preg_replace('/[^a-z0-9_-]/i', '', $transport) ?: 'unknown',
        'success' => $success,
        'detail' => $safeDetail,
    ];
}
function pcf_mail_last_error(): string
{
    return json_encode($GLOBALS['pcf_mail_diagnostic'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'mail delivery failed';
}
function pcf_mail_encode_text_body(string $body, array &$headers): string
{
    $isUtf8Text = false; $hasTransferEncoding = false;
    foreach ($headers as $header) {
        $normalized = strtolower(trim((string)$header));
        if (str_starts_with($normalized, 'content-type:') && str_contains($normalized, 'text/plain') && str_contains($normalized, 'utf-8')) $isUtf8Text = true;
        if (str_starts_with($normalized, 'content-transfer-encoding:')) $hasTransferEncoding = true;
    }
    if (!$isUtf8Text || $hasTransferEncoding) return $body;
    $headers[] = 'Content-Transfer-Encoding: base64';
    return rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
}
function pcf_mail_sender(): string
{
    $configured = trim((string)(getenv('MAIL_FROM_EMAIL') ?: (app_config()['mail']['from_email'] ?? '')));
    if ($configured === '') $configured = trim((string)site_setting_get('mail.from_email', ''));
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) return $configured;
    $host = strtolower((string)(parse_url(app_url(), PHP_URL_HOST) ?: ''));
    $host = preg_replace('/^www\./i', '', $host) ?: '';
    $sender = 'noreply@' . $host;
    return filter_var($sender, FILTER_VALIDATE_EMAIL) !== false ? $sender : '';
}
function pcf_smtp_config(): array
{
    $config = app_config()['mail'] ?? []; $config = is_array($config) ? $config : [];
    return [
        'host' => trim((string)(getenv('SMTP_HOST') ?: ($config['host'] ?? ''))),
        'port' => (int)(getenv('SMTP_PORT') ?: ($config['port'] ?? 587)),
        'encryption' => strtolower(trim((string)(getenv('SMTP_ENCRYPTION') ?: ($config['encryption'] ?? 'tls')))),
        'username' => trim((string)(getenv('SMTP_USERNAME') ?: ($config['username'] ?? ''))),
        'password' => (string)(getenv('SMTP_PASSWORD') ?: ($config['password'] ?? '')),
        'timeout' => max(3, min(30, (int)($config['timeout'] ?? 10))),
    ];
}
function pcf_smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 4096)) !== false) { $response .= $line; if (strlen($line) >= 4 && $line[3] === ' ') break; }
    return $response;
}
function pcf_smtp_command($socket, string $command, array $accepted): bool
{
    if ($command !== '' && fwrite($socket, $command . "\r\n") === false) return false;
    return in_array((int)substr(pcf_smtp_read($socket), 0, 3), $accepted, true);
}
function pcf_smtp_send(string $to, string $subject, string $body, array $headers, string $sender): bool
{
    $config = pcf_smtp_config();
    if ($config['host'] === '' || $sender === '') { pcf_mail_diagnostic_add('smtp', false, $config['host'] === '' ? 'not configured' : 'sender unavailable'); return false; }
    $transport = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client($transport . $config['host'] . ':' . $config['port'], $errorNumber, $errorMessage, $config['timeout']);
    if (!is_resource($socket)) { pcf_mail_diagnostic_add('smtp', false, 'connect failed'); error_log('smtp_delivery status=connect_failed'); return false; }
    stream_set_timeout($socket, $config['timeout']);
    $hostname = (string)(parse_url(app_url(), PHP_URL_HOST) ?: 'localhost');
    $ok = pcf_smtp_command($socket, '', [220]) && pcf_smtp_command($socket, 'EHLO ' . $hostname, [250]);
    if ($ok && $config['encryption'] === 'tls') $ok = pcf_smtp_command($socket, 'STARTTLS', [220]) && @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) && pcf_smtp_command($socket, 'EHLO ' . $hostname, [250]);
    if ($ok && $config['username'] !== '') $ok = pcf_smtp_command($socket, 'AUTH LOGIN', [334]) && pcf_smtp_command($socket, base64_encode($config['username']), [334]) && pcf_smtp_command($socket, base64_encode($config['password']), [235]);
    $recipients = array_filter(array_map('trim', explode(',', $to)));
    $ok = $ok && pcf_smtp_command($socket, 'MAIL FROM:<' . $sender . '>', [250]);
    foreach ($recipients as $recipient) { if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || !pcf_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251])) { $ok = false; break; } }
    $ok = $ok && pcf_smtp_command($socket, 'DATA', [354]);
    if ($ok) {
        $messageHeaders = array_map(static fn($header): string => str_replace(["\r", "\n"], '', (string)$header), array_merge(['To: ' . $to, 'Subject: ' . $subject], $headers));
        $message = implode("\n", $messageHeaders) . "\n\n" . str_replace(["\r\n", "\r"], "\n", $body);
        $message = preg_replace('/^\./m', '..', $message) ?? $message;
        $ok = pcf_smtp_command($socket, str_replace("\n", "\r\n", $message) . "\r\n.", [250]);
    }
    pcf_smtp_command($socket, 'QUIT', [221]); fclose($socket);
    if (!$ok) error_log('smtp_delivery status=failed');
    pcf_mail_diagnostic_add('smtp', $ok, $ok ? 'accepted' : 'protocol failed');
    return $ok;
}
function pcf_mail_send(string $to, string $subject, string $body, array $headers = []): bool
{
    pcf_mail_diagnostic_reset();
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) { pcf_mail_diagnostic_add('validation', false, 'invalid destination'); return false; }
    $sender = pcf_mail_sender();
    if ($sender !== '') {
        $hasFrom = false; foreach ($headers as $header) if (str_starts_with(strtolower(trim((string)$header)), 'from:')) { $hasFrom = true; break; }
        if (!$hasFrom) $headers[] = 'From: ' . APP_NAME . ' <' . $sender . '>';
    }
    $body = pcf_mail_encode_text_body($body, $headers); $headerText = implode("\r\n", $headers);
    if (pcf_smtp_send($to, $subject, $body, $headers, $sender)) return true;
    if (function_exists('mb_send_mail')) { $sent = @mb_send_mail($to, $subject, $body, $headerText); pcf_mail_diagnostic_add('mb_send_mail', $sent, $sent ? 'accepted' : 'delivery failed'); if ($sent) return true; }
    else pcf_mail_diagnostic_add('mb_send_mail', false, 'function unavailable');
    if ($sender !== '') { $sent = @mail($to, $subject, $body, $headerText, '-f' . escapeshellarg($sender)); pcf_mail_diagnostic_add('mail-envelope', $sent, $sent ? 'accepted' : 'delivery failed'); if ($sent) return true; }
    else pcf_mail_diagnostic_add('mail-envelope', false, 'sender unavailable');
    $sent = @mail($to, $subject, $body, $headerText); pcf_mail_diagnostic_add('mail-default', $sent, $sent ? 'accepted' : 'delivery failed'); return $sent;
}
