<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

$pageType = function_exists('ad_current_page_type') ? ad_current_page_type() : 'home';
$safeTextSetting = static function (string $key, string $default = ''): string {
    if (function_exists('front_safe_text_setting')) {
        return front_safe_text_setting($key, $default);
    }

    try {
        if (function_exists('setting')) {
            $value = setting($key, $default);
            return is_string($value) ? $value : $default;
        }
        if (function_exists('app_setting_get')) {
            $value = app_setting_get($key, $default);
            return is_string($value) ? $value : $default;
        }
    } catch (Throwable $e) {
        if (function_exists('app_log_error')) {
            app_log_error('header safe text setting fallback failed: ' . $key, $e);
        }
    }

    return $default;
};

$siteName = trim($safeTextSetting('site_name', ''));
if ($siteName === '') {
    $siteName = trim($safeTextSetting('site.title', ''));
}
if ($siteName === '') {
    $siteName = 'PinkClub-DL';
}

$tagline = trim($safeTextSetting('site.tagline', ''));
$keywords = trim($safeTextSetting('site.keywords', ''));
$logoPath = trim($safeTextSetting('site.logo_path', ''));
$faviconPath = trim($safeTextSetting('site.favicon_path', ''));

$headerAdHtml = trim($safeTextSetting('header_ad_html', ''));
$customHeadCode = trim($safeTextSetting('site.custom_head_code', ''));
$customBodyOpenCode = trim($safeTextSetting('site.custom_body_open_code', ''));
$titleText = (string)($title ?? $pageTitle ?? $siteName);
$titleBaseText = trim($titleText);
$isHomeTitle = $titleBaseText === '' || $titleBaseText === 'トップ' || $titleBaseText === $siteName;
$titleText = $isHomeTitle ? ($tagline !== '' ? $siteName . ' - ' . $tagline : $siteName) : $titleBaseText . ' | ' . $siteName;
$logoUrl = $logoPath !== '' ? public_url($logoPath) : '';
$faviconUrl = $faviconPath !== '' ? public_url($faviconPath) : '';
$faviconExt = strtolower((string)pathinfo($faviconPath, PATHINFO_EXTENSION));
$faviconType = $faviconExt === 'png' ? 'image/png' : 'image/x-icon';
$canRenderAd = function_exists('render_ad');
$descriptionText = (string)($pageDescription ?? '');
if ($descriptionText === '') {
    $descriptionText = $tagline;
}
$canonicalHref = isset($canonicalUrl) && is_string($canonicalUrl) && $canonicalUrl !== '' ? $canonicalUrl : '';
$ogUrl = isset($ogUrl) && is_string($ogUrl) && $ogUrl !== '' ? $ogUrl : ($canonicalHref !== '' ? $canonicalHref : public_url(basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'))));
$ogType = isset($ogType) && is_string($ogType) && $ogType !== '' ? $ogType : 'website';
$ogImage = isset($ogImage) && is_string($ogImage) ? trim($ogImage) : '';
if ($ogImage === '' && $logoPath !== '') {
    $ogImage = $logoUrl;
}
if ($ogImage !== '' && !str_starts_with($ogImage, 'http://') && !str_starts_with($ogImage, 'https://') && !str_starts_with($ogImage, '/')) {
    $ogImage = asset_url($ogImage);
}
$jsonLdText = isset($jsonLd) && is_string($jsonLd) && $jsonLd !== '' ? $jsonLd : '';
$relPrevHref = isset($relPrev) && is_string($relPrev) && $relPrev !== '' ? $relPrev : '';
$relNextHref = isset($relNext) && is_string($relNext) && $relNext !== '' ? $relNext : '';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($titleText) ?></title>
  <?php if ($descriptionText !== ''): ?><meta name="description" content="<?= e($descriptionText) ?>"><?php endif; ?>
  <?php if (isset($robotsMeta) && is_string($robotsMeta) && trim($robotsMeta) !== ''): ?><meta name="robots" content="<?= e(trim($robotsMeta)) ?>"><?php endif; ?>
  <?php if ($canonicalHref !== ''): ?><link rel="canonical" href="<?= e($canonicalHref) ?>"><?php endif; ?>
  <?php if ($relPrevHref !== ''): ?><link rel="prev" href="<?= e($relPrevHref) ?>"><?php endif; ?>
  <?php if ($relNextHref !== ''): ?><link rel="next" href="<?= e($relNextHref) ?>"><?php endif; ?>
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= e($keywords) ?>"><?php endif; ?>
  <meta property="og:type" content="<?= e($ogType) ?>">
  <meta property="og:title" content="<?= e($titleText) ?>">
  <?php if ($descriptionText !== ''): ?><meta property="og:description" content="<?= e($descriptionText) ?>"><?php endif; ?>
  <meta property="og:url" content="<?= e($ogUrl) ?>">
  <?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
  <meta property="og:site_name" content="<?= e($siteName) ?>">
  <meta property="og:locale" content="ja_JP">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($titleText) ?>">
  <?php if ($descriptionText !== ''): ?><meta name="twitter:description" content="<?= e($descriptionText) ?>"><?php endif; ?>
  <?php if ($ogImage !== ''): ?><meta name="twitter:image" content="<?= e($ogImage) ?>"><?php endif; ?>
  <?php if ($jsonLdText !== ''): ?><script type="application/ld+json"><?= $jsonLdText ?></script><?php endif; ?>
  <?php if ($customHeadCode !== ''): ?>
<?= $customHeadCode ?>
  <?php endif; ?>
  <?php if ($faviconUrl !== ''): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>" sizes="any" type="<?= e($faviconType) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset_url('css/public-ui.css')) ?>">
  <script src="<?= e(asset_url('js/item-detail-fixes.js')) ?>" defer></script>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const vrPattern = /(?:【|\[|［)?\s*VR\s*(?:】|\]|］)?/i;

    const itemIdFromLink = (link) => {
      try {
        const url = new URL(link.href, window.location.href);
        if (!/\/item\.php$/i.test(url.pathname)) return '';
        const id = url.searchParams.get('id') || '';
        return /^\d+$/.test(id) ? id : '';
      } catch (_) {
        return '';
      }
    };

    const convertVrCards = (root = document) => {
      root.querySelectorAll('a[href*="item.php"]').forEach((itemLink) => {
        const itemId = itemIdFromLink(itemLink);
        if (!itemId) return;

        const card = itemLink.closest('.pcf-dm-card, .rail-card, article');
        if (!card || card.dataset.vrAffiliateReady === '1') return;

        const titleNode = card.querySelector('.pcf-dm-card__title, .rail-card__title, h2, h3, h4');
        const title = (titleNode?.textContent || '').trim();
        if (!vrPattern.test(title)) return;

        const movieControl = Array.from(card.querySelectorAll('button, span, a'))
          .find((node) => (node.textContent || '').trim() === 'サンプル動画');
        if (!movieControl) return;

        const link = document.createElement('a');
        link.className = movieControl.className
          .replace(/\bis-disabled\b/g, '')
          .replace(/\bsample-button--disabled\b/g, '')
          .trim();
        link.classList.add('sample-button--enabled');
        link.href = `<?= e(public_url('vr_affiliate.php')) ?>?id=${encodeURIComponent(itemId)}`;
        link.target = '_blank';
        link.rel = 'noopener noreferrer sponsored';
        link.textContent = '元サイトで見る';
        link.setAttribute('aria-label', `${title}をDUGAで見る`);
        link.style.display = 'flex';
        link.style.alignItems = 'center';
        link.style.justifyContent = 'center';
        link.style.textDecoration = 'none';
        movieControl.replaceWith(link);
        card.dataset.vrAffiliateReady = '1';
      });
    };

    const replaceVrNoMovieWithPackage = () => {
      if (!/\/item\.php$/i.test(window.location.pathname)) return;
      const title = (document.querySelector('h1')?.textContent || document.title || '').trim();
      if (!vrPattern.test(title)) return;

      const movieArea = document.querySelector('.pcf-item-sample-movie');
      const packageImage = document.querySelector('img[data-package-image="1"]');
      if (!movieArea || !packageImage) return;

      const image = packageImage.cloneNode(true);
      image.removeAttribute('data-package-image');
      image.removeAttribute('loading');
      image.style.width = '100%';
      image.style.height = '100%';
      image.style.objectFit = 'contain';
      image.style.display = 'block';
      movieArea.replaceChildren(image);
      movieArea.style.background = '#fff';
      movieArea.style.color = '';
    };

    convertVrCards();
    replaceVrNoMovieWithPackage();

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (!(node instanceof Element)) return;
          if (node.matches('a[href*="item.php"]')) {
            convertVrCards(node.parentElement || node);
            return;
          }
          convertVrCards(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  });
  </script>
</head>
<body>
<?php if ($customBodyOpenCode !== ''): ?>
<?= $customBodyOpenCode ?>
<?php endif; ?>
<header class="site-header">
  <div class="site-header__top">
    <div class="header-left site-header__left">
      <?php if ($logoPath !== ''): ?>
        <div class="site-logo-wrap">
          <a href="<?= e(public_url('')) ?>" class="site-title-link"><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>" class="site-logo"></a>
        </div>
      <?php else: ?>
        <div class="site-title"><a href="<?= e(public_url('')) ?>" class="site-title-link"><?= e($siteName) ?></a></div>
      <?php endif; ?>
      <div class="site-disclaimer"><strong>当サイトはアフィリエイト広告を利用しています。</strong></div>
    </div>
    <div class="header-right site-header__right">
      <?php if ($headerAdHtml !== '') : ?>
        <div class="site-ad"><?= $headerAdHtml ?></div>
      <?php elseif ($canRenderAd && (!function_exists('should_show_ad') || should_show_ad('header_left_728x90', $pageType, 'pc'))) : ?>
        <div class="site-ad"><?php render_ad('header_left_728x90', $pageType, 'pc'); ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php require __DIR__ . '/nav_search.php'; ?>
</header>
<?php if ($canRenderAd && (!function_exists('should_show_ad') || should_show_ad('sp_header_below', $pageType, 'sp'))): ?>
<div class="only-sp site-ad"><?php render_ad('sp_header_below', $pageType, 'sp'); ?></div>
<?php endif; ?>
<?php if (site_setting_get('link.rss_display.sp_header_below', '1') === '1'): ?>
<div class="site-main__rss only-sp">
  <?php render_shared_mobile_rss_widget(); ?>
</div>
<?php endif; ?>
<div class="layout site-layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <main class="content site-main site-main--legacy">
    <?php $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')); ?>
    <?php $autoBreadcrumbSkip = ['item.php']; ?>
    <?php if ($scriptName !== 'index.php' && !in_array($scriptName, $autoBreadcrumbSkip, true)): ?>
      <nav class="pcf-breadcrumb" aria-label="パンくず">
        <span class="pcf-breadcrumb__item"><a href="<?= e(public_url('')) ?>">ホーム</a></span>
        <span class="pcf-breadcrumb__item"><?= e($titleText) ?></span>
      </nav>
    <?php endif; ?>
    <div class="site-main__body">
