<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';
require_once __DIR__ . '/../lib/light_directory_cache.php';

$directoryTypes = [
    'actress' => ['title' => '女優一覧', 'table' => 'item_actresses', 'column' => 'actress_name'],
    'genre' => ['title' => 'ジャンル一覧', 'table' => 'item_genres', 'column' => 'genre_name'],
    'maker' => ['title' => 'メーカー一覧', 'table' => 'item_makers', 'column' => 'maker_name'],
    'label' => ['title' => 'レーベル一覧', 'table' => 'item_labels', 'column' => 'label_name'],
    'series' => ['title' => 'シリーズ一覧', 'table' => 'item_series', 'column' => 'series_name'],
];

$type = trim((string)($_GET['type'] ?? 'actress'));
if (!isset($directoryTypes[$type])) {
    http_response_code(404);
    $type = 'actress';
}

$directory = $directoryTypes[$type];
$groups = light_directory_groups($type, $directory['table'], $directory['column']);
$availableGroups = array_filter($groups, 'light_directory_group_has_names');

$renderGroup = static function (array $group) use ($type): void {
    if (isset($group['letters']) && is_array($group['letters'])) {
        foreach ($group['letters'] as $letter => $names) {
            echo '<div class="pcf-list-card__meta pcf-chip-list"><strong>' . e((string)$letter) . '</strong>';
            foreach ($names as $name) {
                $url = public_url('search.php') . '?' . http_build_query(['q' => $name, 'type' => $type]);
                echo '<a class="pcf-chip" href="' . e($url) . '">' . e((string)$name) . '</a>';
            }
            echo '</div>';
        }
        return;
    }
    echo '<div class="pcf-list-card__meta pcf-chip-list">';
    foreach (($group['names'] ?? []) as $name) {
        $url = public_url('search.php') . '?' . http_build_query(['q' => $name, 'type' => $type]);
        echo '<a class="pcf-chip" href="' . e($url) . '">' . e((string)$name) . '</a>';
    }
    echo '</div>';
};

$fragmentGroup = trim((string)($_GET['group'] ?? ''));
$showAll = isset($_GET['all']);
if (isset($_GET['fragment'])) {
    header('Content-Type: text/html; charset=UTF-8');
    if (isset($availableGroups[$fragmentGroup])) {
        $renderGroup($availableGroups[$fragmentGroup]);
    }
    exit;
}

$title = $directory['title'];
$pageDescription = $directory['title'] . 'です。名前を選ぶと該当商品の検索結果を表示します。';
$canonicalUrl = public_url('directory.php') . '?' . http_build_query(['type' => $type]);

require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_hero($directory['title'], '名前を選ぶと商品の検索結果を表示します。'); ?>

<?php if ($availableGroups === []): ?>
  <?php pcf_render_empty('表示できるデータがありません。商品APIの同期後に自動で追加されます。'); ?>
<?php else: ?>
  <nav class="pcf-index-nav" aria-label="一覧内メニュー">
    <?php foreach ($availableGroups as $groupKey => $group): ?>
      <a class="pcf-index-nav__item" href="#index-<?= e($groupKey) ?>"><?= e((string)$group['title']) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="pcf-kana-directory">
    <?php foreach ($availableGroups as $groupKey => $group): ?>
      <section class="pcf-index-block<?= $showAll ? '' : ' pcf-directory-lazy-group' ?>" id="index-<?= e($groupKey) ?>"<?= $showAll ? '' : ' data-group="' . e($groupKey) . '" aria-busy="true"' ?> style="content-visibility:auto;contain-intrinsic-size:300px;<?= $showAll ? '' : 'min-height:260px;' ?>">
        <h2 class="pcf-section-title"><?= e((string)$group['title']) ?></h2>
        <?php if ($showAll): ?>
          <?php $renderGroup($group); ?>
        <?php else: ?>
          <div class="pcf-directory-lazy-content"><p>読み込み中...</p></div>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>
  <noscript><p><a href="<?= e($canonicalUrl . '&all=1') ?>">一覧をすべて表示する</a></p></noscript>
  <script>
  (() => {
    const sections = [...document.querySelectorAll('.pcf-directory-lazy-group')];
    const load = async (section) => {
      if (section.dataset.loaded === '1') return;
      section.dataset.loaded = '1';
      const target = section.querySelector('.pcf-directory-lazy-content');
      const url = new URL(<?= json_encode(public_url('directory.php'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>, location.href);
      url.searchParams.set('type', <?= json_encode($type, JSON_UNESCAPED_UNICODE) ?>);
      url.searchParams.set('group', section.dataset.group || '');
      url.searchParams.set('fragment', '1');
      try {
        const response = await fetch(url, {credentials: 'same-origin'});
        if (!response.ok) throw new Error('load failed');
        target.innerHTML = await response.text();
        section.setAttribute('aria-busy', 'false');
      } catch (error) {
        section.dataset.loaded = '0';
        target.textContent = '読み込めませんでした。ページを再読み込みしてください。';
      }
    };
    if (!('IntersectionObserver' in window)) {
      sections.forEach(load);
      return;
    }
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        observer.unobserve(entry.target);
        load(entry.target);
      });
    }, {rootMargin: '600px 0px'});
    sections.forEach((section) => observer.observe(section));
  })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
