<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function rss_trade_candidate_http_url(string $value): string
{
    if (function_exists('rss_http_url')) {
        return rss_http_url($value);
    }

    $url = trim($value);
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")) {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        return '';
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true)) {
        return '';
    }

    if (
        filter_var($host, FILTER_VALIDATE_IP) !== false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
    ) {
        return '';
    }

    return $url;
}

function rss_trade_disable_stale_sources(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        db()->exec(
            'UPDATE rss_sources rs '
            . 'INNER JOIN partner_rss pr ON pr.id = rs.source_ref_id '
            . 'SET rs.is_enabled = 0, rs.updated_at = NOW() '
            . 'WHERE rs.source_type = "partner_link" '
            . 'AND rs.is_enabled = 1 '
            . 'AND ('
            . 'TRIM(COALESCE(pr.feed_url, "")) = "" '
            . 'OR COALESCE(pr.show_rss, pr.is_enabled, 1) <> 1 '
            . 'OR rs.feed_url <> pr.feed_url '
            . 'OR EXISTS ('
            . 'SELECT 1 FROM partner_rss newer '
            . 'WHERE newer.partner_site_id = pr.partner_site_id '
            . 'AND COALESCE(newer.show_rss, newer.is_enabled, 1) = 1 '
            . 'AND TRIM(COALESCE(newer.feed_url, "")) <> "" '
            . 'AND (newer.updated_at > pr.updated_at OR (newer.updated_at = pr.updated_at AND newer.id > pr.id))'
            . ')'
            . ')'
        );
    } catch (Throwable) {
        error_log('[rss] stale partner source cleanup skipped');
    }
}

function rss_trade_candidate_pool(int $perSiteLimit = 40, bool $requireImage = false, int $days = 14): array
{
    $perSiteLimit = max(1, min(200, $perSiteLimit));
    $days = max(1, min(365, $days));
    rss_trade_disable_stale_sources();

    try {
        $rows = db()->query(
            'SELECT ps.id AS partner_site_id, pr.id AS partner_rss_id, pr.feed_url, pr.updated_at '
            . 'FROM partner_sites ps '
            . 'INNER JOIN partner_rss pr ON pr.partner_site_id = ps.id '
            . 'WHERE ps.is_enabled = 1 '
            . 'AND COALESCE(pr.show_rss, pr.is_enabled, 1) = 1 '
            . 'AND TRIM(COALESCE(pr.feed_url, "")) <> "" '
            . 'ORDER BY ps.id ASC, pr.updated_at DESC, pr.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        error_log('[rss] canonical partner RSS list failed');
        return [];
    }

    $feedBySite = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $siteId = (int)($row['partner_site_id'] ?? 0);
        if ($siteId <= 0 || isset($feedBySite[$siteId])) continue;
        $feedUrl = rss_trade_candidate_http_url((string)($row['feed_url'] ?? ''));
        $rssId = (int)($row['partner_rss_id'] ?? 0);
        if ($feedUrl === '' || $rssId <= 0) continue;
        $feedBySite[$siteId] = ['rss_id' => $rssId, 'feed_url' => $feedUrl];
    }
    if ($feedBySite === []) return [];

    $siteIds = array_keys($feedBySite);
    if (count($siteIds) > 1) shuffle($siteIds);

    $all = [];
    $seen = [];
    $seenTitles = [];

    foreach ($siteIds as $partnerSiteId) {
        $feed = $feedBySite[$partnerSiteId] ?? null;
        if (!is_array($feed)) continue;
        $rssId = (int)($feed['rss_id'] ?? 0);
        $feedUrl = rss_trade_candidate_http_url((string)($feed['feed_url'] ?? ''));
        if ($rssId <= 0 || $feedUrl === '') continue;

        try {
            $sourceStmt = db()->prepare(
                'SELECT id FROM rss_sources '
                . 'WHERE source_type = "partner_link" '
                . 'AND source_ref_id = :rss_id '
                . 'AND feed_url = :feed_url '
                . 'AND is_enabled = 1 '
                . 'ORDER BY id DESC LIMIT 1'
            );
            $sourceStmt->execute([':rss_id' => $rssId, ':feed_url' => $feedUrl]);
            $sourceId = (int)($sourceStmt->fetchColumn() ?: 0);
            if ($sourceId <= 0) continue;

            $imageClause = $requireImage ? " AND COALESCE(NULLIF(TRIM(ri.image_url), ''), '') <> ''" : '';
            $sql = 'SELECT ri.source_id, rs.name AS source_name, ri.title, ri.url, ri.guid, ri.published_at, ri.image_url '
                . 'FROM rss_items ri INNER JOIN rss_sources rs ON rs.id = ri.source_id '
                . 'WHERE ri.source_id = :source_id '
                . 'AND ri.published_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)'
                . $imageClause
                . ' ORDER BY ri.published_at DESC, ri.id DESC LIMIT ' . $perSiteLimit;
            $stmt = db()->prepare($sql);
            $stmt->execute([':source_id' => $sourceId]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                if (!is_array($row)) continue;
                $url = rss_trade_candidate_http_url((string)($row['url'] ?? ''));
                if ($url === '') continue;

                $imageUrl = rss_trade_candidate_http_url((string)($row['image_url'] ?? ''));
                if ($requireImage && $imageUrl === '') continue;

                $title = trim((string)($row['title'] ?? ''));
                if ($title === '') continue;
                $guid = trim((string)($row['guid'] ?? ''));

                $dedupe = function_exists('rss_normalize_url') ? rss_normalize_url($url) : mb_strtolower($url);
                if ($dedupe === '') $dedupe = 'url|' . mb_strtolower($url);
                if (isset($seen[$dedupe])) continue;

                $titleKey = mb_strtolower(preg_replace('/\s+/u', ' ', $title) ?? '');
                if ($titleKey !== '' && isset($seenTitles[$titleKey])) continue;

                $seen[$dedupe] = true;
                if ($titleKey !== '') $seenTitles[$titleKey] = true;

                $all[] = [
                    'title' => $title,
                    'link' => $url,
                    'guid' => $guid,
                    'published_at' => (string)($row['published_at'] ?? ''),
                    'image_url' => $imageUrl,
                    'source_id' => $sourceId,
                    'source_name' => (string)($row['source_name'] ?? ''),
                    'partner_site_id' => (int)$partnerSiteId,
                ];
            }
        } catch (Throwable) {
            error_log('[rss] canonical candidate fetch failed');
        }
    }

    if (count($all) > 1) shuffle($all);
    return $all;
}
