<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'サイト設定';
$message = null;
$error = null;
$recommendedTagline = 'DUGAの新作・人気動画を、サンプル動画・画像を見ながらカテゴリやメーカーから手軽に探せる作品情報サイトです。';
$recommendedKeywords = 'PinkClub-DL,DUGA,新作動画,人気動画,アダルト動画,作品情報,サンプル動画,サンプル画像,出演者,カテゴリ,メーカー,シリーズ';

$normalizePinkClubName = static function (string $value): string {
    $value = trim($value);
    $normalized = preg_replace('/^PinkClub\s*[-‐‑‒–—]\s*/u', 'PinkClub ', $value);
    return is_string($normalized) ? trim($normalized) : $value;
};

$inspectUpload = static function (
    array $file,
    int $minW,
    int $maxW,
    int $minH,
    int $maxH,
    bool $squareOnly,
    array $allowedMimes,
    array $allowedExts,
    int $maxBytes,
    string $label
): array {
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . 'のファイルサイズが上限を超えています。',
            UPLOAD_ERR_PARTIAL => $label . 'のアップロードが途中で中断されました。',
            default => $label . 'のアップロードに失敗しました。',
        };
        return ['ok' => false, 'message' => $message];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => $label . 'のファイルが不正です。'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        return ['ok' => false, 'message' => sprintf('%sは %.1fMB 以下の画像を指定してください。', $label, $maxBytes / 1048576)];
    }

    $originalName = str_replace('\\', '/', (string)($file['name'] ?? ''));
    $name = basename($originalName);
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0")) {
        return ['ok' => false, 'message' => $label . 'のファイル名を確認してください。'];
    }
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'message' => $label . 'の拡張子に対応していません。'];
    }

    $info = @getimagesize($tmp);
    if (!is_array($info)) {
        return ['ok' => false, 'message' => $label . 'は画像ファイルを指定してください。'];
    }
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    $mime = strtolower((string)($info['mime'] ?? ''));
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'message' => $label . 'の画像形式に対応していません。'];
    }
    if ($w < $minW || $w > $maxW || $h < $minH || $h > $maxH) {
        if ($squareOnly) {
            return ['ok' => false, 'message' => sprintf('%sは正方形で %d〜%dpx の範囲で指定してください。', $label, $minW, $maxW)];
        }
        return ['ok' => false, 'message' => sprintf('%sは横 %d〜%dpx / 高さ %d〜%dpx の範囲で指定してください。', $label, $minW, $maxW, $minH, $maxH)];
    }
    if ($squareOnly && $w !== $h) {
        return ['ok' => false, 'message' => $label . 'は正方形のみ対応です。'];
    }

    $bytes = @file_get_contents($tmp);
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) !== $size) {
        return ['ok' => false, 'message' => $label . 'の読み込みに失敗しました。'];
    }

    return [
        'ok' => true,
        'file_name' => $name,
        'mime_type' => $mime,
        'width' => $w,
        'height' => $h,
        'bytes' => $bytes,
    ];
};

// Run schema creation before any explicit transaction. MySQL DDL can commit a
// transaction implicitly, so it must never be triggered from inside the save.
$siteMediaSchemaReady = site_media_ensure_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $startYear = filter_var(post('site_start_year', (string)site_start_year()), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1900, 'max_range'=>(int)date('Y')]]);
    $siteName = $normalizePinkClubName((string)post('site_name', ''));
    $siteUrl = trim((string)post('site_url', ''));
    $tagline = trim((string)post('site_tagline', ''));
    $keywords = trim((string)post('site_keywords', ''));

    if (!$siteMediaSchemaReady) {
        $error = '画像保存用DBテーブルを準備できませんでした。サーバーのDB権限を確認してください。';
    } elseif ($startYear === false) {
        $error = '開設年は1900年から今年までの西暦で入力してください。';
    } elseif ($siteName === '') {
        $error = 'サイト名を入力してください。';
    } elseif ($siteUrl === '' || filter_var($siteUrl, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($siteUrl), 'https://')) {
        $error = 'URLは https:// から始まる正しいURLを入力してください。';
    }

    $pendingMedia = [];
    $uploadSpecs = [
        'site_logo' => [
            'key' => 'logo', 'label' => 'タイトルロゴ',
            'min_w' => 250, 'max_w' => 400, 'min_h' => 50, 'max_h' => 100,
            'square' => false, 'max_bytes' => 2097152,
            'mimes' => ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
            'exts' => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
        ],
        'site_favicon' => [
            'key' => 'favicon', 'label' => 'ファビコン',
            'min_w' => 48, 'max_w' => 512, 'min_h' => 48, 'max_h' => 512,
            'square' => true, 'max_bytes' => 1048576,
            'mimes' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
            'exts' => ['png', 'ico'],
        ],
        'site_ogp' => [
            'key' => 'ogp', 'label' => 'OGP画像',
            'min_w' => 600, 'max_w' => 3000, 'min_h' => 315, 'max_h' => 2000,
            'square' => false, 'max_bytes' => 5242880,
            'mimes' => ['image/png', 'image/jpeg', 'image/webp'],
            'exts' => ['png', 'jpg', 'jpeg', 'webp'],
        ],
    ];

    if ($error === null) {
        foreach ($uploadSpecs as $field => $spec) {
            if (!isset($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $result = $inspectUpload(
                (array)$_FILES[$field],
                (int)$spec['min_w'], (int)$spec['max_w'],
                (int)$spec['min_h'], (int)$spec['max_h'],
                (bool)$spec['square'], (array)$spec['mimes'], (array)$spec['exts'],
                (int)$spec['max_bytes'], (string)$spec['label']
            );
            if (($result['ok'] ?? false) !== true) {
                $error = (string)($result['message'] ?? ((string)$spec['label'] . 'の検証に失敗しました。'));
                break;
            }
            $pendingMedia[(string)$spec['key']] = $result;
        }
    }

    if ($error === null) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            foreach ($pendingMedia as $mediaKey => $media) {
                site_media_put(
                    $mediaKey,
                    (string)$media['file_name'],
                    (string)$media['mime_type'],
                    (int)$media['width'],
                    (int)$media['height'],
                    (string)$media['bytes']
                );
            }

            $updates = [
                'site.start_year' => (string)$startYear,
                'site.title' => $siteName,
                'site.name' => $siteName,
                'site.url' => rtrim($siteUrl, '/'),
                'site.tagline' => $tagline,
                'site.keywords' => $keywords,
            ];
            if (isset($pendingMedia['logo'])) {
                $updates['site.logo_path'] = '';
            }
            if (isset($pendingMedia['favicon'])) {
                $updates['site.favicon_path'] = '';
            }
            site_setting_set_many($updates);
            $pdo->commit();
            $message = 'サイト設定を保存しました。';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'サイト設定の保存に失敗しました。既存設定は変更されていません。';
            if (function_exists('app_log_error')) {
                app_log_error('site settings save failed', $e);
            } else {
                error_log('[site_settings] save failed: ' . $e->getMessage());
            }
        }
    }
}

$logoPath = trim(site_setting_get('site.logo_path', ''));
$faviconPath = trim(site_setting_get('site.favicon_path', ''));
$logoUrl = site_media_url_or_legacy('logo', $logoPath);
$faviconUrl = site_media_url_or_legacy('favicon', $faviconPath);
$ogpUrl = site_media_public_url('ogp');
$taglineValue = trim(site_setting_get('site.tagline', ''));
$keywordsValue = trim(site_setting_get('site.keywords', ''));
if ($taglineValue === '') {
    $taglineValue = $recommendedTagline;
}
if ($keywordsValue === '') {
    $keywordsValue = $recommendedKeywords;
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form admin-card--accent-pink">
  <h1>サイト設定</h1>
  <p class="admin-form-note">サイト名・SEO向け基本情報・共通画像を設定します。画像はDBへ保存されます。</p>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>サイト名
      <input type="text" name="site_name" value="<?= e($normalizePinkClubName(site_setting_get('site.title', site_setting_get('site.name', APP_NAME)))) ?>" required>
    </label>
    <label>開設年（西暦）
      <input type="number" name="site_start_year" min="1900" max="<?= (int)date('Y') ?>" value="<?= site_start_year() ?>" required>
    </label>
    <p class="admin-form-note">フッターの開始年に使います。未設定時は初期管理者の作成年を表示するため、実際の開設年を入力してください。</p>
    <label>URL
      <input type="url" name="site_url" value="<?= e(site_setting_get('site.url', app_url())) ?>" required inputmode="url">
    </label>
    <label>総合RSS（10分間隔）
      <input type="url" value="<?= e(public_url('feed-10.php')) ?>" readonly>
    </label>
    <label>総合RSS（1時間間隔）
      <input type="url" value="<?= e(public_url('feed-60.php')) ?>" readonly>
    </label>
    <label>ランダムRSS（10分間隔）
      <input type="url" value="<?= e(public_url('feed-free-10.php')) ?>" readonly>
    </label>
    <label>ランダムRSS（1時間間隔）
      <input type="url" value="<?= e(public_url('feed-free-60.php')) ?>" readonly>
    </label>
    <label>サイトマップ URL
      <input type="url" value="<?= e(public_url('sitemap.php')) ?>" readonly>
    </label>
    <label>キャッチフレーズ（検索結果説明用）
      <input type="text" name="site_tagline" value="<?= e($taglineValue) ?>">
    </label>
    <label>キーワード（meta keywords）
      <input type="text" name="site_keywords" value="<?= e($keywordsValue) ?>">
    </label>

    <label>タイトルロゴ（横250〜400px / 高さ50〜100px）
      <span>
        <input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif">
        <?php if ($logoUrl !== ''): ?><small class="admin-media-current">現在の画像（DB優先）</small><img class="admin-media-preview admin-media-preview--logo" src="<?= e($logoUrl) ?>" alt="現在のタイトルロゴ"><?php endif; ?>
      </span>
    </label>

    <label>ファビコン（正方形 48〜512px、PNG/ICO）
      <span>
        <input type="file" name="site_favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico">
        <?php if ($faviconUrl !== ''): ?><small class="admin-media-current">現在の画像（DB優先）</small><img class="admin-media-preview admin-media-preview--favicon" src="<?= e($faviconUrl) ?>" alt="現在のファビコン"><?php endif; ?>
      </span>
    </label>

    <label>OGP画像（推奨 1200×630px）
      <span>
        <input type="file" name="site_ogp" accept="image/png,image/jpeg,image/webp">
        <small>共通OGP/Xカード用。商品詳細は商品画像を優先します。600px以上、5MB以下。</small>
        <?php if ($ogpUrl !== ''): ?><small class="admin-media-current">現在のOGP画像</small><img class="admin-media-preview admin-media-preview--ogp" src="<?= e($ogpUrl) ?>" alt="現在のOGP画像"><?php endif; ?>
      </span>
    </label>

    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
