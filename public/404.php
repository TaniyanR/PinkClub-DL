<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/partials/public_ui.php';

http_response_code(404);

$title = 'お探しのページは見つかりませんでした';
$pageDescription = 'URLが間違っているか、ページが削除・移動された可能性があります。';
$robotsMeta = 'noindex,follow';
$ogType = 'website';

require __DIR__ . '/partials/header.php';
?>
<?php pcf_render_breadcrumbs([
    ['label' => 'トップ', 'url' => public_url('index.php')],
    ['label' => '404 Not Found'],
]); ?>

<section class="pcf-hero pcf-hero--compact">
  <div class="pcf-hero__body">
    <p class="pcf-hero__eyebrow">404 Not Found</p>
    <h1 class="pcf-hero__title">お探しのページは見つかりませんでした</h1>
    <p class="pcf-hero__lead">URLが間違っているか、ページが削除・移動された可能性があります。</p>
  </div>
</section>

<section class="pcf-panel" style="margin-top:20px;">
  <h2 class="pcf-section-title">ページを探す</h2>
  <p>トップページや商品一覧へ移動するか、キーワードで検索してください。</p>
  <div class="pcf-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin:16px 0;">
    <a class="pcf-button" href="<?= e(public_url('index.php')) ?>">トップページへ戻る</a>
    <a class="pcf-button pcf-button--secondary" href="<?= e(public_url('items.php')) ?>">商品一覧へ移動</a>
    <a class="pcf-button pcf-button--secondary" href="<?= e(public_url('search.php')) ?>">検索ページへ移動</a>
    <a class="pcf-button pcf-button--secondary" href="javascript:history.back()">前のページへ戻る</a>
  </div>
  <form class="pcf-search-form" action="<?= e(public_url('search.php')) ?>" method="get" role="search">
    <label for="not-found-search">キーワード検索</label>
    <div style="display:flex;gap:8px;max-width:640px;">
      <input id="not-found-search" type="search" name="q" value="" placeholder="作品名・女優名・ジャンルなど" style="flex:1;">
      <button class="pcf-button" type="submit">検索</button>
    </div>
  </form>
</section>

<?php
require __DIR__ . '/partials/footer.php';
exit;
