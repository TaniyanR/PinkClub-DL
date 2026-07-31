<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
$title = '固定ページ新規作成';
$message = null;
$errors = [];
$values = [
    'slug' => '',
    'title' => '',
    'body' => '',
    'seo_title' => '',
    'seo_description' => '',
    'is_published' => '1',
];

try {
    db()->exec('CREATE TABLE IF NOT EXISTS fixed_pages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(120) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        body LONGTEXT NOT NULL,
        seo_title VARCHAR(255) NULL,
        seo_description TEXT NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
} catch (Throwable $e) {
    $message = '固定ページテーブルの準備に失敗しました: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $values = [
        'slug' => trim((string)post('slug', '')),
        'title' => trim((string)post('title', '')),
        'body' => trim((string)post('body', '')),
        'seo_title' => trim((string)post('seo_title', '')),
        'seo_description' => trim((string)post('seo_description', '')),
        'is_published' => post('is_published', '0') === '1' ? '1' : '0',
    ];

    if ($values['slug'] === '') {
        $errors[] = 'スラッグを入力してください。';
    } elseif (!preg_match('/^[A-Za-z0-9_-]{1,120}$/', $values['slug'])) {
        $errors[] = 'スラッグは半角英数字、ハイフン、アンダースコアのみ120文字以内で入力してください。';
    }
    if ($values['title'] === '') {
        $errors[] = 'タイトルを入力してください。';
    }
    if ($values['body'] === '') {
        $errors[] = '本文を入力してください。';
    }

    if ($message === null && $errors === []) {
        try {
            $exists = db()->prepare('SELECT id FROM fixed_pages WHERE slug=:slug LIMIT 1');
            $exists->execute([':slug' => $values['slug']]);
            if ($exists->fetchColumn() !== false) {
                $errors[] = '同じスラッグの固定ページが既に存在します。';
            } else {
                $stmt = db()->prepare('INSERT INTO fixed_pages(slug,title,body,seo_title,seo_description,is_published,created_at,updated_at) VALUES(:slug,:title,:body,:seo_title,:seo_description,:is_published,NOW(),NOW())');
                $stmt->execute([
                    ':slug' => $values['slug'],
                    ':title' => $values['title'],
                    ':body' => $values['body'],
                    ':seo_title' => $values['seo_title'],
                    ':seo_description' => $values['seo_description'],
                    ':is_published' => $values['is_published'] === '1' ? 1 : 0,
                ]);
                flash_set('success', '固定ページを作成しました。');
                app_redirect(admin_url('pages.php?edit=' . (string)db()->lastInsertId()));
            }
        } catch (Throwable $e) {
            $message = '固定ページの作成に失敗しました: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>固定ページ新規作成</h1>
  <?php if ($message !== null): ?><p><?= e($message) ?></p><?php endif; ?>
  <?php if ($errors !== []): ?>
    <ul>
      <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <form method="post">
    <?= csrf_input() ?>
    <label>タイトル<input name="title" value="<?= e($values['title']) ?>" required></label>
    <label>本文<textarea name="body" rows="12" required style="background:#fff;"><?= e($values['body']) ?></textarea></label>
    <label>スラッグ<input name="slug" value="<?= e($values['slug']) ?>" required></label>
    <label>SEOタイトル<input name="seo_title" value="<?= e($values['seo_title']) ?>"></label>
    <label>SEO説明<textarea name="seo_description" rows="3" style="background:#fff;"><?= e($values['seo_description']) ?></textarea></label>
    <label><input type="checkbox" name="is_published" value="1" <?= $values['is_published'] === '1' ? 'checked' : '' ?>> 公開する</label>
    <div class="admin-actions"><button type="submit">作成</button><a class="button-secondary" href="<?= e(admin_url('pages.php')) ?>">一覧へ戻る</a></div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
