<?php

declare(strict_types=1);

function image_fallback_url(): string
{
    return asset_url('img/no-image.png');
}

function item_sample_image_urls(array $item): array
{
    $raw = $item['raw_json'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $sample = $decoded['sampleImageURL']['sample_s']['image'] ?? [];
    if (!is_array($sample)) {
        return [];
    }
    return array_values(array_filter($sample, static fn($v): bool => is_string($v) && $v !== ''));
}

function item_sample_movie_url(array $item): ?string
{
    $raw = $item['raw_json'] ?? null;
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $movie = $decoded['sampleMovieURL']['size_720_480'] ?? $decoded['sampleMovieURL']['size_644_414'] ?? null;
    return is_string($movie) && $movie !== '' ? $movie : null;
}
