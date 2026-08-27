<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function rss_balance_items_by_partner_site(array $items): array
{
    if (count($items) <= 1) return $items;
    $sourceIds = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $sourceId = (int)($item['source_id'] ?? 0);
        if ($sourceId > 0) $sourceIds[$sourceId] = true;
    }
    if ($sourceIds === []) {
        shuffle($items);
        return $items;
    }
    $partnerBySource = [];
    try {
        $ids = array_keys($sourceIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = db()->prepare('SELECT rs.id AS source_id, pr.partner_site_id FROM rss_sources rs LEFT JOIN partner_rss pr ON pr.id = rs.source_ref_id WHERE rs.id IN (' . $placeholders . ')');
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $sourceId = (int)($row['source_id'] ?? 0);
            if ($sourceId > 0) $partnerBySource[$sourceId] = (int)($row['partner_site_id'] ?? 0);
        }
    } catch (Throwable $e) {
        error_log('[rss] partner-site balancing lookup failed: ' . $e->getMessage());
        shuffle($items);
        return $items;
    }
    $buckets = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $sourceId = (int)($item['source_id'] ?? 0);
        $partnerSiteId = (int)($partnerBySource[$sourceId] ?? 0);
        $key = $partnerSiteId > 0 ? 'partner:' . $partnerSiteId : 'source:' . $sourceId;
        $item['partner_site_id'] = $partnerSiteId;
        $buckets[$key][] = $item;
    }
    if ($buckets === []) return [];
    $order = array_keys($buckets);
    shuffle($order);
    foreach ($buckets as &$bucket) if (count($bucket) > 1) shuffle($bucket);
    unset($bucket);
    $balanced = [];
    while (true) {
        $added = false;
        foreach ($order as $key) {
            if (($buckets[$key] ?? []) === []) continue;
            $item = array_shift($buckets[$key]);
            if (is_array($item)) {
                $balanced[] = $item;
                $added = true;
            }
        }
        if (!$added) break;
    }
    return $balanced;
}

function rss_partner_display_source_key(array $item): string
{
    $partnerSiteId = (int)($item['partner_site_id'] ?? 0);
    if ($partnerSiteId > 0) return 'partner:' . $partnerSiteId;
    $sourceId = (int)($item['source_id'] ?? 0);
    if ($sourceId > 0) return 'source:' . $sourceId;
    return 'name:' . mb_strtolower(trim((string)($item['source_name'] ?? '')));
}

function rss_spread_items_by_partner_site(array $items): array
{
    if (count($items) <= 2) return $items;
    $buckets = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $key = rss_partner_display_source_key($item);
        if ($key === '') $key = 'unknown';
        $buckets[$key][] = $item;
    }
    if (count($buckets) <= 1) return $items;
    foreach ($buckets as &$bucket) if (count($bucket) > 1) shuffle($bucket);
    unset($bucket);
    $result = [];
    $lastKey = null;
    while ($buckets !== []) {
        $eligible = [];
        foreach ($buckets as $key => $bucket) {
            if ($bucket === []) {
                unset($buckets[$key]);
                continue;
            }
            if ($key !== $lastKey) $eligible[$key] = count($bucket);
        }
        if ($eligible === []) {
            $remaining = [];
            foreach ($buckets as $bucket) foreach ($bucket as $item) $remaining[] = $item;
            if (count($remaining) > 1) shuffle($remaining);
            array_push($result, ...$remaining);
            break;
        }
        $maxCount = max($eligible);
        $candidates = array_keys(array_filter($eligible, static fn(int $count): bool => $count === $maxCount));
        $chosenKey = $candidates[random_int(0, count($candidates) - 1)];
        $item = array_shift($buckets[$chosenKey]);
        if (is_array($item)) {
            $result[] = $item;
            $lastKey = $chosenKey;
        }
        if ($buckets[$chosenKey] === []) unset($buckets[$chosenKey]);
    }
    return $result;
}
