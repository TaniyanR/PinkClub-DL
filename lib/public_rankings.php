<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/site_settings.php';

function pcf_public_ranking_period_start(string $period): string
{
    return match ($period) {
        'weekly' => date('Y-m-d 00:00:00', strtotime('-6 days')),
        'monthly' => date('Y-m-d 00:00:00', strtotime('-1 month')),
        'yearly' => date('Y-m-d 00:00:00', strtotime('-1 year')),
        default => date('Y-m-d 00:00:00'),
    };
}

function pcf_public_weighted_ranking(string $type, string $period, int $limit = 200, bool $forceRefresh = false): array
{
    $allowedTypes = ['items'];
    if (!in_array($type, $allowedTypes, true)) {
        return [];
    }
    if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        $period = 'daily';
    }

    $limit = max(1, min(200, $limit));
    $cacheKey = 'public.weighted_ranking.v2.' . $type . '.' . $period;
    $cacheTtl = 30 * 60;

    try {
        $cached = json_decode((string)(setting_get($cacheKey, '') ?? ''), true);
        if (is_array($cached) && is_array($cached['rows'] ?? null)) {
            if (!$forceRefresh && (int)($cached['cached_at'] ?? 0) >= time() - $cacheTtl) {
                return $cached['rows'];
            }
            if (!$forceRefresh) {
                $GLOBALS['__pcf_public_ranking_refresh'][$type . '|' . $period] = [
                    'type' => $type,
                    'period' => $period,
                ];
                return $cached['rows'];
            }
        }
    } catch (Throwable) {
    }

    $rows = [];
    try {
        $scoreSql = pcf_public_ranking_item_score_sql();
        $sql = pcf_public_ranking_sql($type, $scoreSql, $limit);
        $stmt = db()->prepare($sql);
        $periodFrom = pcf_public_ranking_period_start($period);
        $stmt->execute([
            ':page_view_from' => $periodFrom,
            ':out_click_from' => $periodFrom,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('weighted ranking failed: ' . $type . ': ' . $e->getMessage());
        $rows = [];
    }

    try {
        setting_set($cacheKey, json_encode([
            'cached_at' => time(),
            'rows' => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    } catch (Throwable $e) {
        error_log('weighted ranking cache write failed: ' . $type . ': ' . $e->getMessage());
    }

    return $rows;
}

function pcf_public_ranking_refresh_queue(): array
{
    $queue = $GLOBALS['__pcf_public_ranking_refresh'] ?? [];
    return is_array($queue) ? array_values($queue) : [];
}

function pcf_public_ranking_item_score_sql(): string
{
    return 'SELECT i.id,
                   i.content_id,
                   i.title,
                   COALESCE(pv.page_view_count, 0) AS page_view_count,
                   COALESCE(oc.out_click_count, 0) AS out_click_count,
                   COALESCE(pv.page_view_count, 0) + (COALESCE(oc.out_click_count, 0) * 3) AS access_count
            FROM items i
            LEFT JOIN (
              SELECT item_id, COUNT(*) AS page_view_count
              FROM page_views
              WHERE viewed_at >= :page_view_from
              GROUP BY item_id
            ) pv ON pv.item_id = i.id
            LEFT JOIN (
              SELECT item_id, COUNT(*) AS out_click_count
              FROM item_out_click_daily
              WHERE clicked_at >= :out_click_from
              GROUP BY item_id
            ) oc ON oc.item_id = i.id
            WHERE (COALESCE(pv.page_view_count, 0) > 0 OR COALESCE(oc.out_click_count, 0) > 0)
              AND ' . items_product_source_where('i');
}

function pcf_public_ranking_sql(string $type, string $scoreSql, int $limit): string
{
    if ($type !== 'items') {
        throw new InvalidArgumentException('PinkClub-FD supports item rankings only.');
    }

    return 'SELECT scores.id, scores.content_id, scores.title,
                   scores.page_view_count, scores.out_click_count, scores.access_count
            FROM (' . $scoreSql . ') scores
            ORDER BY scores.access_count DESC, scores.out_click_count DESC, scores.id DESC
            LIMIT ' . $limit;
}
