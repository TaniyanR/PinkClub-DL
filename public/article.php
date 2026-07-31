<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$siteTitle = APP_NAME;

$articleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM articles WHERE id = :id');
$stmt->execute([':id' => $articleId]);
$article = $stmt->fetch();

if (!$article) {
    require __DIR__ . '/404.php';
}

$pageTitle = $siteTitle . ' | ' . $article['title'];

require __DIR__ . '/partials/header.php';
?>
<article>
        <h1><?php echo e($article['title']); ?></h1>
        <div class="meta">発売日: <?php echo e($article['release_date'] ?? '未設定'); ?></div>
        <?php if (!empty($article['image_url'])): ?>
            <p><img src="<?php echo e($article['image_url']); ?>" alt="<?php echo e($article['title']); ?>"></p>
        <?php endif; ?>
        <?php if (!empty($article['description'])): ?>
            <p><?php echo nl2br(e($article['description'])); ?></p>
        <?php endif; ?>
        <?php if (!empty($article['price'])): ?>
            <p>価格: <?php echo e((string) $article['price']); ?>円</p>
        <?php endif; ?>
        <p><a href="<?php echo e($article['affiliate_url']); ?>" target="_blank" rel="noopener">DUGA商品ページへ</a></p>
</article>
<?php
require __DIR__ . '/partials/footer.php';
