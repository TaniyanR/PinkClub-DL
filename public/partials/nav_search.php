<?php
declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/contact_page_slug.php';

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$searchQuery = trim((string)($_GET['q'] ?? ''));

$navItems = [
    ['href' => public_url(''), 'label' => 'TOP'],
    ['href' => public_url('items.php'), 'label' => '商品一覧'],
    ['href' => public_url('directory.php?type=actress'), 'label' => '女優一覧'],
    ['href' => public_url('directory.php?type=genre'), 'label' => 'ジャンル一覧'],
    ['href' => public_url('directory.php?type=maker'), 'label' => 'メーカー一覧'],
    ['href' => public_url('directory.php?type=label'), 'label' => 'レーベル一覧'],
    ['href' => public_url('directory.php?type=series'), 'label' => 'シリーズ一覧'],
];
$mobileMainItems = $navItems;
$mobileInfoItems = [
    ['href' => public_url('page.php?slug=about'), 'label' => 'サイトについて'],
    ['href' => public_url('page.php?slug=privacy-policy'), 'label' => 'Privacy Policy'],
    ['href' => public_url('page.php?slug=que'), 'label' => 'お問い合わせ'],
];
$sitePostCount = null;
$sitePostCount = null;
try {
    $sitePostCount = (int)db()->query('SELECT COUNT(*) FROM items')->fetchColumn();
} catch (Throwable) {
}

try {
    $stmt = db()->query('SELECT slug,title FROM fixed_pages WHERE is_published = 1 ORDER BY id ASC');
    $fixedPages = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $excludedSlugs = ['about', 'privacy-policy', CONTACT_PAGE_OLD_SLUG, CONTACT_PAGE_SLUG];
    $excludedTitles = ['サイトについて', 'Privacy Policy', 'お問い合わせ'];
    foreach ($fixedPages as $page) {
        $slug = trim((string)($page['slug'] ?? ''));
        $title = trim((string)($page['title'] ?? ''));
        if ($slug === '' || $title === '') {
            continue;
        }
        if (in_array($slug, $excludedSlugs, true) || in_array($title, $excludedTitles, true)) {
            continue;
        }
        $navItems[] = ['href' => public_url('page.php?slug=' . $slug), 'label' => $title];
    }
} catch (Throwable $e) {
}
?>
<details class="site-mobile-menu only-sp">
    <summary class="site-mobile-menu__summary">メニュー</summary>
    <div class="site-mobile-menu__body">
        <div class="site-mobile-menu__group">
            <?php foreach ($mobileMainItems as $item) : ?>
                <a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="site-mobile-menu__group">
            <?php if ($sitePostCount !== null): ?><a style="color:#000;">投稿数：<strong><?= e(number_format($sitePostCount)) ?></strong></a><?php endif; ?>
            <?php foreach ($mobileInfoItems as $item) : ?>
                <a href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </div>
        <form class="site-mobile-menu__search" method="get" action="<?= e(public_url('search.php')) ?>">
            <input class="site-search__input" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="商品検索" aria-label="商品検索">
            <button class="site-search__button" type="submit">検索</button>
        </form>
    </div>
</details>
<nav class="site-nav" aria-label="グローバルナビゲーション">
    <?php foreach ($navItems as $index => $item) : ?>
        <?php
        $itemPath = (string)parse_url($item['href'], PHP_URL_PATH);
        $itemQuery = [];
        parse_str((string)(parse_url($item['href'], PHP_URL_QUERY) ?? ''), $itemQuery);
        $isActive = $path === $itemPath
            && (!isset($itemQuery['type']) || (string)($_GET['type'] ?? '') === (string)$itemQuery['type']);
        ?>
        <?php if ($index > 0): ?><span class="site-nav__sep" aria-hidden="true"> | </span><?php endif; ?>
        <a class="<?= $isActive ? 'is-active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
    <?php endforeach; ?>
    <form class="site-search" method="get" action="<?= e(public_url('search.php')) ?>">
        <input class="site-search__input" type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="商品検索" aria-label="商品検索">
        <button class="site-search__button" type="submit">検索</button>
    </form>
</nav>
