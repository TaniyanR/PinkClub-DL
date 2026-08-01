<?php

declare(strict_types=1);

final class DugaNormalizer
{
    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
    }

    private static function url(mixed $value): ?string
    {
        $url = self::string($value);
        if ($url === null) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    private static function list(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [];
        }
        return array_is_list($value) ? $value : [$value];
    }

    private static function namedList(mixed $value, string $container = 'data'): array
    {
        if (is_array($value) && isset($value[$container])) {
            $value = $value[$container];
        }

        $rows = [];
        $seen = [];
        $queue = self::list($value);
        while ($queue !== []) {
            $entry = array_shift($queue);
            if (is_array($entry) && array_key_exists($container, $entry)) {
                foreach (self::list($entry[$container]) as $nested) {
                    $queue[] = $nested;
                }
                continue;
            }

            if (is_string($entry)) {
                $id = '';
                $name = trim($entry);
                $ruby = null;
            } elseif (is_array($entry)) {
                $id = trim((string)($entry['id'] ?? ''));
                $name = trim((string)($entry['name'] ?? ''));
                $ruby = self::string($entry['kana'] ?? null);
            } else {
                continue;
            }

            if ($name === '') {
                continue;
            }
            $key = $id !== '' ? 'id:' . $id : 'name:' . mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = ['id' => $id, 'name' => $name, 'ruby' => $ruby];
        }
        return $rows;
    }

    private static function itemRows(array $response): array
    {
        $items = $response['items'] ?? $response['result']['items'] ?? $response['item'] ?? [];
        if (is_array($items) && array_key_exists('item', $items)) {
            $items = $items['item'];
        }

        $rows = self::list($items);
        if (count($rows) === 1 && is_array($rows[0]) && array_key_exists('item', $rows[0])) {
            $rows = self::list($rows[0]['item']);
        }
        $unwrapped = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists('item', $row)) {
                foreach (self::list($row['item']) as $item) {
                    $unwrapped[] = $item;
                }
                continue;
            }
            $unwrapped[] = $row;
        }
        return $unwrapped;
    }

    private static function sampleImages(mixed $thumbnail): array
    {
        if (is_array($thumbnail) && array_key_exists('image', $thumbnail)) {
            $thumbnail = $thumbnail['image'];
        }
        $images = [];
        foreach (is_array($thumbnail) ? (array_is_list($thumbnail) ? $thumbnail : [$thumbnail]) : [] as $image) {
            $url = self::url(is_array($image) ? ($image['image'] ?? $image['url'] ?? $image['src'] ?? null) : $image);
            if ($url !== null) {
                $images[] = $url;
            }
        }
        return array_values(array_unique($images));
    }

    private static function imageForKey(mixed $value, string $targetKey): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $child) {
            $normalizedKey = is_string($key) ? strtolower(str_replace(['_', '-'], '', $key)) : '';
            if ($normalizedKey === $targetKey) {
                $url = self::firstUrl($child);
                if ($url !== null) {
                    return $url;
                }
            }
        }

        foreach ($value as $child) {
            $url = self::imageForKey($child, $targetKey);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function packageImage(array $row): ?string
    {
        foreach ([
            'jacketimage',
            'packageimagelarge',
            'packageimage',
            'package',
            'jacket',
            'poster',
            'posterimage',
        ] as $key) {
            $url = self::imageForKey($row, $key);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private static function firstUrl(mixed $value): ?string
    {
        $url = self::url($value);
        if ($url !== null) {
            return $url;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $child) {
            $url = self::firstUrl($child);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private static function urlForKey(mixed $value, string $targetKey): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && strtolower($key) === $targetKey) {
                $url = self::firstUrl($child);
                if ($url !== null) {
                    return $url;
                }
            }
        }
        foreach ($value as $child) {
            $url = self::urlForKey($child, $targetKey);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private static function firstMovieFileUrl(mixed $value): ?string
    {
        $url = self::url($value);
        if ($url !== null) {
            $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
            if (preg_match('/\\.(?:mp4|m3u8|webm)$/', $path) === 1) {
                return $url;
            }
        }
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $child) {
            $url = self::firstMovieFileUrl($child);
            if ($url !== null) {
                return $url;
            }
        }
        return null;
    }

    private static function sampleMovie(array $row): array
    {
        $sample = null;
        foreach ($row as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower(str_replace(['_', '-'], '', $key)) : '';
            if ($normalizedKey === 'samplemovie') {
                $sample = $value;
                break;
            }
        }

        $searchRoot = $sample ?? $row;
        $movie = self::urlForKey($searchRoot, 'movie');
        if ($movie === null) {
            $movie = self::firstMovieFileUrl($searchRoot);
        }

        return [
            'movie' => $movie,
            'capture' => self::urlForKey($searchRoot, 'capture'),
        ];
    }

    private static function releaseDate(array $row): ?string
    {
        $date = self::string($row['releasedate'] ?? null) ?? self::string($row['opendate'] ?? null);
        if ($date === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y/m/d', $date)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d', $date)
            ?: DateTimeImmutable::createFromFormat('!Ymd', $date);
        return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d') : null;
    }

    public static function normalizeItemsResponse(array $response): array
    {
        $normalized = [];
        foreach (self::itemRows($response) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = self::string($row['productid'] ?? null);
            $title = self::string($row['title'] ?? null);
            if ($productId === null || $title === null) {
                continue;
            }

            $movie = self::sampleMovie($row);
            $sampleImages = self::sampleImages($row['thumbnail'] ?? []);
            $primarySampleImage = $sampleImages[0] ?? null;
            $packageImage = self::packageImage($row);
            $performers = self::namedList($row['performer'] ?? []);
            $genres = self::namedList($row['category'] ?? []);
            $series = self::namedList($row['series'] ?? []);
            $directors = self::namedList($row['director'] ?? []);
            $labels = self::namedList($row['label'] ?? []);
            $maker = self::string($row['makername'] ?? null);
            $makers = $maker === null ? [] : [['id' => '', 'name' => $maker, 'ruby' => null]];
            $raw = $row;
            if ($packageImage !== null) {
                $raw['packageImage'] = ['large' => $packageImage, 'small' => $packageImage];
            }
            $raw['content_id'] = $productId;
            $raw['product_id'] = $productId;
            $raw['maker_product'] = self::string($row['itemno'] ?? null);
            $raw['URL'] = self::url($row['url'] ?? null);
            $raw['affiliateURL'] = self::url($row['affiliateurl'] ?? null);
            $raw['date'] = self::string($row['opendate'] ?? null);
            $raw['iteminfo'] = [
                'actress' => $performers,
                'genre' => $genres,
                'maker' => $makers,
                'label' => $labels,
                'series' => $series,
                'director' => $directors,
            ];
            $raw['sampleImageURL'] = ['sample_s' => ['image' => $sampleImages], 'sample_l' => ['image' => $sampleImages]];
            if ($movie['movie'] !== null) {
                $raw['sampleMovieURL'] = [
                    'size_720_480' => $movie['movie'],
                    'pc_flag' => 1,
                    'sp_flag' => 1,
                ];
            }

            $price = self::string($row['price'] ?? null);
            $review = is_array($row['review'] ?? null) ? $row['review'] : [];
            if (array_is_list($review)) {
                $review = is_array($review[0] ?? null) ? $review[0] : [];
            }

            $normalized[] = [
                'raw' => $raw,
                'content_id' => $productId,
                'product_id' => $productId,
                'title' => $title,
                'service_code' => 'duga',
                'service_name' => 'DUGA',
                'floor_code' => 'adult',
                'floor_name' => 'アダルト',
                'category_name' => null,
                'volume' => self::string($row['volume'] ?? null),
                'review_count' => isset($review['reviewer']) ? (int)$review['reviewer'] : null,
                'review_average' => isset($review['rating']) ? (float)$review['rating'] : null,
                'url' => self::url($row['url'] ?? null),
                'affiliate_url' => self::url($row['affiliateurl'] ?? null),
                'image_list' => $primarySampleImage,
                'image_small' => $packageImage ?? $primarySampleImage,
                'image_large' => $packageImage ?? $primarySampleImage,
                'sample_movie_url_476' => $movie['movie'],
                'sample_movie_url_560' => $movie['movie'],
                'sample_movie_url_644' => $movie['movie'],
                'sample_movie_url_720' => $movie['movie'],
                'sample_movie_pc_flag' => $movie['movie'] !== null ? 1 : 0,
                'sample_movie_sp_flag' => $movie['movie'] !== null ? 1 : 0,
                'price_min_text' => $price,
                'list_price_text' => null,
                'release_date' => self::releaseDate($row),
                'actresses' => $performers,
                'genres' => $genres,
                'makers' => $makers,
                'series' => $series,
                'authors' => [],
                'directors' => $directors,
                'labels' => $labels,
                'campaigns' => [],
                'actors' => [],
            ];
        }
        return $normalized;
    }
}
