<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('X-Robots-Tag: noindex, nofollow', true);
// out.php is the controlled external redirect used by DUGA/partner links.
// Keep its redirect response aligned with the public unsafe-url policy so the
// destination can receive the detailed referral needed for reciprocal-link
// attribution. Public URLs must therefore never contain secrets.
header('Referrer-Policy: unsafe-url', true);

$to = trim((string)($_GET['to'] ?? ''));
$ref = trim((string)($_GET['ref'] ?? ''));
$path = (string)($_SERVER['REQUEST_URI'] ?? '/out.php');
$resolvedMutualLink = false;
$resolvedPartnerRss = false;

// backward compatibility: ?id=nnn
$id = (int)($_GET['id'] ?? 0);
if ($to === '' && $id > 0) {
    $st = db()->prepare('SELECT link_url, site_url, ref_code FROM mutual_links WHERE id = :id AND status = "approved" LIMIT 1');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $to = (string)($row['link_url'] ?? $row['site_url'] ?? '');
        $resolvedMutualLink = true;
        if ($ref === '') {
            $ref = (string)($row['ref_code'] ?? '');
        }
    }
}

// Partner RSS links are allowed only when the destination belongs to the
// registered partner-site host or one of that partner's registered RSS hosts.
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
            $targetHost = preg_replace('/^www\./', '', $targetHost) ?? $targetHost;
            $allowedHosts = [];
            foreach ($rows as $row) {
                foreach (['site_url', 'feed_url'] as $field) {
                    $host = strtolower((string)(parse_url((string)($row[$field] ?? ''), PHP_URL_HOST) ?: ''));
                    $host = preg_replace('/^www\./', '', $host) ?? $host;
                    if ($host !== '') {
                        $allowedHosts[$host] = true;
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
    } catch (Throwable $e) {
        error_log('partner RSS redirect validation failed: ' . $e->getMessage());
    }
}

$valid = filter_var($to, FILTER_VALIDATE_URL) !== false;
$scheme = strtolower((string)parse_url($to, PHP_URL_SCHEME));
$host = strtolower((string)parse_url($to, PHP_URL_HOST));
$isDugaDestination = $host === 'duga.jp' || str_ends_with($host, '.duga.jp');
if (!$valid || !in_array($scheme, ['http', 'https'], true) || (!$resolvedMutualLink && !$resolvedPartnerRss && !$isDugaDestination)) {
    header('Location: ' . app_url('/'), true, 302);
    exit;
}

try {
    analytics_log_out($to, $ref, $path);
} catch (Throwable $e) {
    error_log('out.php tracking error: ' . $e->getMessage());
}

header('Location: ' . $to, true, 302);
exit;
