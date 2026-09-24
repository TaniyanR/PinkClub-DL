<?php
declare(strict_types=1);

/**
 * Keep descriptions useful for search results without changing the page UI.
 */
function pcf_meta_description(string $description, string $title, string $script, string $site): string
{
    $clean = static fn(string $value): string => trim(
        preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''
    );
    $text = $clean($description);
    $subject = $clean($title);

    if (mb_strlen($text, 'UTF-8') < 70) {
        if ($text === '') {
            $text = $subject !== '' ? $subject . '。' : '';
        }
        if ($script === 'item.php') {
            $text .= '作品情報や出演者、ジャンル、提供されているサンプルを確認できます。購入・視聴の詳細はリンク先のDUGAでご確認ください。';
        } elseif (in_array($script, ['index.php', 'items.php', 'directory.php', 'search.php', 'posts.php', 'post.php', 'article.php'], true)) {
            $text .= ($subject !== '' ? $subject : '掲載作品') . 'の情報を確認し、作品名や出演者、ジャンルから気になる作品を探せます。各作品ページでは作品情報と提供されているサンプルをご案内しています。';
        }
        if (mb_strlen($text, 'UTF-8') < 70) {
            $text .= '掲載内容と関連情報を分かりやすく案内し、目的の作品を探しやすくまとめています。';
        }
        $text .= $site . 'は18歳以上向けのDUGA作品紹介サイトです。';
    }

    if (mb_strlen($text, 'UTF-8') > 160) {
        $text = mb_substr($text, 0, 159, 'UTF-8') . '…';
    }
    return $text;
}

function pcf_video_object(string $title, string $description, string $thumbnail, string $embed, string $uploadedAt): ?array
{
    // A product release date is not a sample-video upload date. Omit invalid data.
    if ($uploadedAt === '' || !filter_var($thumbnail, FILTER_VALIDATE_URL) || !filter_var($embed, FILTER_VALIDATE_URL)) {
        return null;
    }
    if (!in_array(parse_url($thumbnail, PHP_URL_SCHEME), ['http', 'https'], true)
        || !in_array(parse_url($embed, PHP_URL_SCHEME), ['http', 'https'], true)
        || !preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D', $uploadedAt)
    ) {
        return null;
    }
    try {
        $date = new DateTimeImmutable($uploadedAt);
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
            return null;
        }
    } catch (Throwable) {
        return null;
    }
    return [
        '@type' => 'VideoObject',
        'name' => $title . ' サンプル動画',
        'description' => $description,
        'thumbnailUrl' => [$thumbnail],
        'uploadDate' => $date->format(DATE_ATOM),
        'embedUrl' => $embed,
    ];
}
