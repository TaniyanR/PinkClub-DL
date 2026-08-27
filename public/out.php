<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: private, no-store, max-age=0');

$to = trim((string)($_GET['to'] ?? ''));
$ref = trim((string)($_GET['ref'] ?? ''));
$path = (string)($_SERVER['REQUEST_URI'] ?? '/out.php');
$resolvedMutualLink = false;
$resolvedPartnerRss = false;

// Backward compatibility: ?id=nnn
$id = (int)($_GET['id'] ?? 0);
if ($to === '' && $id > 0) {
    try {
        $st = db()->prepare(
            'SELECT link_url, site_url, ref_code '
            . 'FROM mutual_links '
            . 'WHERE id = :id AND status = "approved" LIMIT 1'
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $to = trim((string)($row['link_url'] ?? $row['site_url'] ?? ''));
            $resolvedMutualLink = $to !== '';
            if ($ref === '') {
                $ref = trim((string)($row['ref_code'] ?? ''));
            }
        }
    } catch (Throwable) {
        error_log('[out] mutual-link redirect lookup failed');
    }
}

// Access-trade RSS links are allowed only when the target host belongs to the
// registered partner site or to one of that partner's registered RSS hosts.
$partnerId = (int)($_GET['partner'] ?? 0);
if ($to !== '' && $partnerId > 0) {
    try {
        $st = db()->prepare(
            'SELECT ps.url AS site_url, ps.ref_code, pr.feed_url '
            . 'FROM partner_sites ps '
            . 'LEFT JOIN partner_rss pr ON pr.partner_site_id = ps.id '
            . 'WHERE ps.id = :id AND ps.is_enabled = 1'
        );
        $st->execute([':id' => $partnerId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows !== []) {
            $registeredRef = trim((string)($rows[0]['ref_code'] ?? ''));
            $targetHost = strtolower((string)(parse_url($to, PHP_URL_HOST) ?: ''));
            $targetHost = preg_replace('/^www\./', '', rtrim($targetHost, '.')) ?? $targetHost;
            $allowedHosts = [];

            foreach ($rows as $row) {
                foreach (['site_url', 'feed_url'] as $field) {
                    $allowedHost = strtolower((string)(parse_url((string)($row[$field] ?? ''), PHP_URL_HOST) ?: ''));
                    $allowedHost = preg_replace('/^www\./', '', rtrim($allowedHost, '.')) ?? $allowedHost;
                    if ($allowedHost !== '') {
                        $allowedHosts[$allowedHost] = true;
                    }
                }
            }

            foreach (array_keys($allowedHosts) as $allowedHost) {
                if ($targetHost === $allowedHost || str_ends_with($targetHost, '.' . $allowedHost)) {
                    $resolvedPartnerRss = true;
                    $ref = $registeredRef;
                    break;
                }
            }
        }
    } catch (Throwable) {
        error_log('[out] partner RSS redirect validation failed');
    }
}

$valid = filter_var($to, FILTER_VALIDATE_URL) !== false;
$parts = $valid ? parse_url($to) : false;
$scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
$host = is_array($parts) ? strtolower((string)($parts['host'] ?? '')) : '';
$port = is_array($parts) && isset($parts['port'])
    ? (int)$parts['port']
    : ($scheme === 'https' ? 443 : 80);
$hasUserInfo = is_array($parts) && (isset($parts['user']) || isset($parts['pass']));

$isDugaDestination = $host === 'duga.jp'
    || $host === 'duga.co.jp'
    || $host === 'duga.com'
    || str_ends_with($host, '.duga.jp')
    || str_ends_with($host, '.duga.co.jp')
    || str_ends_with($host, '.duga.com');

if (
    !$valid
    || !in_array($scheme, ['http', 'https'], true)
    || !in_array($port, [80, 443], true)
    || $hasUserInfo
    || (!$resolvedMutualLink && !$resolvedPartnerRss && !$isDugaDestination)
) {
    header('Location: ' . app_url('/'), true, 302);
    exit;
}

try {
    analytics_log_out($to, $ref, $path);
} catch (Throwable) {
    error_log('[out] tracking failed');
}

header('Location: ' . $to, true, 302);
exit;
