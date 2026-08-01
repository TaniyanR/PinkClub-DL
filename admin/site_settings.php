<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'サイト設定';
$message = null;
$error = null;

$uploadDir = __DIR__ . '/../public/uploads/site_settings';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$saveImage = static function (array $file, string $prefix, int $minW, int $maxW, int $minH, int $maxH, bool $squareOnly, array $allowedMimes) use ($uploadDir): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'アップロードに失敗しました。'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => '不正なファイルです。'];
    }

    $info = @getimagesize($tmp);
    if (!is_array($info)) {
        return ['ok' => false, 'message' => '画像ファイルを指定してください。'];
    }

    [$w, $h] = $info;
    $mime = (string)($info['mime'] ?? '');
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'message' => '対応していない画像形式です。'];
    }

    if ($w < $minW || $w > $maxW || $h < $minH || $h > $maxH) {
        if ($squareOnly) {
            return ['ok' => false, 'message' => sprintf('画像サイズは正方形で %d〜%dpx の範囲で指定してください。', $minW, $maxW)];
        }
        return ['ok' => false, 'message' => sprintf('画像サイズは %d-%dpx x %d-%dpx の範囲で指定してください。', $minW, $maxW, $minH, $maxH)];
    }

    if ($squareOnly && $w !== $h) {
        return ['ok' => false, 'message' => 'ファビコンは正方形のみ対応です。'];
    }

    if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        return ['ok' => false, 'message' => '保存先ディレクトリを作成できませんでした。'];
    }

    $originalName = str_replace('\\', '/', (string)($file['name'] ?? ''));
    $name = basename($originalName);
    if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0")) {
        return ['ok' => false, 'message' => 'ファイル名を確認してください。'];
    }

    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $allowedExts = $squareOnly ? ['png', 'ico'] : ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    if (!in_array($ext, $allowedExts, true)) {
        return ['ok' => false, 'message' => '対応していない拡張子です。'];
    }

    $dest = $uploadDir . '/' . $name;

    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'message' => '画像の保存に失敗しました。'];
    }

    return ['ok' => true, 'path' => 'uploads/site_settings/' . $name];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $siteName = trim((string)post('site_name', ''));
    $siteUrl = trim((string)post('site_url', ''));
    $tagline = trim((string)post('site_tagline', ''));
    $keywords = trim((string)post('site_keywords', ''));

    $updates = [
        'site.title' => $siteName,
        'site.name' => $siteName,
        'site.url' => $siteUrl,
        'site.tagline' => $tagline,
        'site.keywords' => $keywords,
    ];

    if (isset($_FILES['site_logo']) && (int)($_FILES['site_logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $logoResult = $saveImage((array)$_FILES['site_logo'], 'logo', 250, 400, 50, 100, false, ['image/png', 'image/jpeg', 'image/webp', 'image/gif']);
        if (($logoResult['ok'] ?? false) === true) {
            $updates['site.logo_path'] = (string)$logoResult['path'];
        } else {
            $error = (string)($logoResult['message'] ?? 'ロゴ画像の保存に失敗しました。');
        }
    }

    if ($error === null && isset($_FILES['site_favicon']) && (int)($_FILES['site_favicon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $faviconResult = $saveImage((array)$_FILES['site_favicon'], 'favicon', 48, 512, 48, 512, true, ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']);
        if (($faviconResult['ok'] ?? false) === true) {
            $updates['site.favicon_path'] = (string)$faviconResult['path'];
        } else {
            $error = (string)($faviconResult['message'] ?? 'ファビコンの保存に失敗しました。');
        }
    }

    if ($error === null) {
        site_setting_set_many($updates);
        $message = 'サイト設定を保存しました。';
    }
}

$logoPath = trim(site_setting_get('site.logo_path', ''));
$faviconPath = trim(site_setting_get('site.favicon_path', ''));

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>サイト設定</h1>
  <?php if ($message !== null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error !== null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>サイト名
      <input type="text" name="site_name" value="<?= e(site_setting_get('site.title', site_setting_get('site.name', APP_NAME))) ?>">
    </label>
    <label>URL
      <input type="url" name="site_url" value="<?= e(site_setting_get('site.url', app_url())) ?>">
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
      <input type="text" name="site_tagline" value="<?= e(site_setting_get('site.tagline', '')) ?>">
    </label>
    <label>キーワード（meta keywords）
      <input type="text" name="site_keywords" value="<?= e(site_setting_get('site.keywords', '')) ?>" placeholder="例: DUGA,動画,アフィリエイト">
    </label>

    <label>タイトルロゴ（横250〜400px / 高さ50〜100px）
      <input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif">
      <?php if ($logoPath !== ''): ?><small>現在: <?= e($logoPath) ?></small><?php endif; ?>
    </label>

    <label>ファビコン（正方形 48〜512px、PNG/ICO）
      <input type="file" name="site_favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico">
      <?php if ($faviconPath !== ''): ?><small>現在: <?= e($faviconPath) ?></small><?php endif; ?>
    </label>

    <div class="admin-actions">
      <button type="submit">保存</button>
    </div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
