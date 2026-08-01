<?php
declare(strict_types=1);

if (!function_exists('e') || !function_exists('asset_url')) {
    require_once __DIR__ . '/../../public/_bootstrap.php';
}

$currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$menuGroups = [
    ['label' => 'ダッシュボード', 'file' => 'index.php'],
    ['label' => '設定', 'children' => [
        ['label' => 'サイト設定', 'file' => 'site_settings.php'],
        ['label' => '個人設定', 'file' => 'personal_settings.php'],
        ['label' => '広告コード', 'file' => 'ads_code.php'],
        ['label' => 'コード設定', 'file' => 'code_settings.php'],
        ['label' => 'cron設定', 'file' => 'cron_settings.php'],
    ]],
    ['label' => 'リンク設定', 'children' => [
        ['label' => '相互リンク管理', 'file' => 'links.php'],
        ['label' => '相互リンク表示設定', 'file' => 'link_rss_display.php'],
    ]],
    ['label' => 'API設定', 'children' => [
        ['label' => '商品情報API設定', 'file' => 'api_items.php'],
        ['label' => '自動設定', 'file' => 'api_auto.php'],
    ]],
    ['label' => 'アクセス解析', 'children' => [
        ['label' => 'グラフ', 'file' => 'analytics.php?tab=graph'],
        ['label' => 'リンク元', 'file' => 'analytics.php?tab=referrer'],
        ['label' => 'クリック先', 'file' => 'analytics.php?tab=destination'],
        ['label' => '検索エンジン', 'file' => 'analytics.php?tab=engine'],
        ['label' => '検索ワード', 'file' => 'analytics.php?tab=keyword'],
        ['label' => '滞在時間', 'file' => 'analytics.php?tab=duration'],
    ]],
    ['label' => '固定ページ', 'children' => [
        ['label' => '新規', 'file' => 'pages_new.php'],
        ['label' => '固定ページ一覧', 'file' => 'pages.php'],
    ]],
];

$flash = function_exists('flash_get') ? flash_get() : null;
$titleText = (string)($title ?? APP_NAME);
$faviconPath = trim(site_setting_get('site.favicon_path', ''));
$faviconUrl = $faviconPath !== '' ? public_url($faviconPath) : '';
$faviconType = strtolower((string)pathinfo($faviconPath, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/x-icon';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($titleText) ?></title>
  <?php if ($faviconUrl !== ''): ?>
    <link rel="icon" href="<?= e($faviconUrl) ?>" sizes="any" type="<?= e($faviconType) ?>">
    <link rel="shortcut icon" href="<?= e($faviconUrl) ?>" type="<?= e($faviconType) ?>">
    <link rel="apple-touch-icon" href="<?= e($faviconUrl) ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= e(asset_url('css/style.css')) ?>">
</head>
<body class="admin-page">
<input class="admin-menu-toggle" type="checkbox" id="admin-menu-toggle" hidden>
<header class="admin-topbar">
  <label class="admin-menu-toggle__button" for="admin-menu-toggle" aria-label="管理メニューを開閉">☰</label>
  <div class="admin-topbar__brand"><a href="<?= e(admin_url('index.php')) ?>">PinkClub-DL 管理</a></div>
  <div class="admin-topbar__right">
    <a href="<?= e(public_url('')) ?>" target="_blank" rel="noopener noreferrer">フロント表示</a>
    <span class="admin-topbar__separator" aria-hidden="true"> | </span>
    <form method="post" action="<?= e(admin_url('logout.php')) ?>" style="display:inline;margin:0;">
      <?= csrf_input() ?>
      <button type="submit" style="appearance:none;border:0;background:none;color:inherit;font:inherit;font-weight:700;padding:0;cursor:pointer;">ログアウト</button>
    </form>
  </div>
</header>
<div class="admin-shell">
  <aside class="admin-sidebar" aria-label="管理メニュー">
    <nav>
      <ul class="admin-sidebar__list">
        <?php foreach ($menuGroups as $group): ?>
          <?php if (isset($group['children']) && is_array($group['children'])): ?>
            <?php
            $isGroupActive = false;
            foreach ($group['children'] as $item) {
                if ($currentScript === basename((string)$item['file'])) {
                    $isGroupActive = true;
                    break;
                }
            }
            ?>
            <li>
              <details <?= $isGroupActive ? 'open' : '' ?>>
                <summary class="admin-menu__link"><?= e((string)$group['label']) ?></summary>
                <ul class="admin-sidebar__list admin-menu__child">
                  <?php foreach ($group['children'] as $item): $isActive = ($currentScript === basename((string)$item['file'])); ?>
                    <li><a class="admin-menu__link <?= $isActive ? 'is-active' : '' ?>" href="<?= e(admin_url((string)$item['file'])) ?>"><?= e((string)$item['label']) ?></a></li>
                  <?php endforeach; ?>
                </ul>
              </details>
            </li>
          <?php else: $isActive = ($currentScript === basename((string)$group['file'])); ?>
            <li><a class="admin-menu__link <?= $isActive ? 'is-active' : '' ?>" href="<?= e(admin_url((string)$group['file'])) ?>"><?= e((string)$group['label']) ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </nav>
  </aside>
  <main class="admin-main">
    <?php if (is_array($flash) && isset($flash['message'])): ?>
      <div class="admin-notice <?= ($flash['type'] ?? '') === 'success' ? 'admin-notice--success' : 'admin-notice--error' ?>"><p><?= e((string)$flash['message']) ?></p></div>
    <?php endif; ?>
