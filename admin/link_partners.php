<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();
analytics_ensure_tables();
$title = '相互リンク管理';
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $action = (string)post('action', 'create');
    if ($action === 'create') {
        $name = trim((string)post('name', ''));
        $url = trim((string)post('url', ''));
        $rssUrl = trim((string)post('rss_url', ''));
        $refCode = 'partner_' . substr(sha1($name . '|' . $url . '|' . microtime(true)), 0, 16);

        db()->prepare('INSERT INTO partner_sites(name,ref_code,url,is_enabled,show_link,created_at,updated_at) VALUES(:name,:ref,:url,1,:show_link,NOW(),NOW())')
            ->execute([
                ':name' => $name,
                ':ref' => $refCode,
                ':url' => $url,
                ':show_link' => post('show_link', '0') === '1' ? 1 : 0,
            ]);

        $siteId = (int)db()->lastInsertId();
        if ($siteId > 0 && $rssUrl !== '') {
            db()->prepare('INSERT INTO partner_rss(partner_site_id,feed_url,is_enabled,show_rss,created_at,updated_at) VALUES(:sid,:url,1,:show_rss,NOW(),NOW())')
                ->execute([
                    ':sid' => $siteId,
                    ':url' => $rssUrl,
                    ':show_rss' => post('show_rss', '0') === '1' ? 1 : 0,
                ]);
        }

        site_setting_set('link.sort_mode', post('sort_mode', 'registered') === 'kana' ? 'kana' : 'registered');
        $message = '相互リンクを追加しました。';
    } elseif ($action === 'toggle_link') {
        db()->prepare('UPDATE partner_sites SET show_link = :show, updated_at = NOW() WHERE id = :id')
            ->execute([':show' => post('show_link', '0') === '1' ? 1 : 0, ':id' => (int)post('id', 0)]);
        $message = '相互リンク表示を更新しました。';
    } elseif ($action === 'toggle_rss') {
        db()->prepare('UPDATE partner_rss SET show_rss = :show, updated_at = NOW() WHERE id = :id')
            ->execute([':show' => post('show_rss', '0') === '1' ? 1 : 0, ':id' => (int)post('rss_id', 0)]);
        $message = 'RSS表示を更新しました。';
    } elseif ($action === 'sort_mode') {
        site_setting_set('link.sort_mode', post('sort_mode', 'registered') === 'kana' ? 'kana' : 'registered');
        $message = '表示順設定を更新しました。';
    } elseif ($action === 'delete') {
        $id = (int)post('id', 0);
        if ($id > 0) {
            db()->prepare('DELETE FROM partner_rss WHERE partner_site_id = :id')->execute([':id' => $id]);
            db()->prepare('DELETE FROM partner_sites WHERE id = :id')->execute([':id' => $id]);
            $message = '相互リンクを削除しました。';
        }
    }
}

$sortMode = site_setting_get('link.sort_mode', 'registered');
$rows = db()->query('SELECT ps.*, pr.id AS rss_id, pr.feed_url, COALESCE(pr.show_rss, pr.is_enabled, 0) AS show_rss FROM partner_sites ps LEFT JOIN partner_rss pr ON pr.partner_site_id = ps.id ORDER BY ps.id DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>相互リンク管理</h1>
  <?php if ($message): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create">
    <label>サイト名<input name="name" required></label>
    <label>URL<input name="url" type="url" required></label>
    <label>RSS URL<input name="rss_url" type="url"></label>
    <label><input type="checkbox" name="show_link" value="1" checked> 相互リンクを表示する</label>
    <label><input type="checkbox" name="show_rss" value="1" checked> RSSを表示する</label>
    <fieldset>
      <legend>表示順</legend>
      <label><input type="radio" name="sort_mode" value="registered" <?= $sortMode !== 'kana' ? 'checked' : '' ?>> 登録順</label>
      <label><input type="radio" name="sort_mode" value="kana" <?= $sortMode === 'kana' ? 'checked' : '' ?>> あいうえお順</label>
    </fieldset>
    <div class="admin-actions">
      <button type="submit">追加</button>
    </div>
  </form>
</section>

<section class="admin-card">
  <table class="admin-table">
    <tr><th>ID</th><th style="white-space:nowrap;">サイト名</th><th>URL</th><th style="width:1%;white-space:nowrap;text-align:center;">相互リンク表示</th><th style="width:1%;white-space:nowrap;text-align:center;">RSS表示</th><th>編集</th><th>削除</th></tr>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e((string)$r['id']) ?></td><td style="white-space:nowrap;"><?= e((string)$r['name']) ?></td><td><?= e((string)$r['url']) ?></td>
        <td style="width:1%;white-space:nowrap;text-align:center;">
          <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle_link"><input type="hidden" name="id" value="<?= e((string)$r['id']) ?>">
            <label><input type="checkbox" name="show_link" value="1" <?= ((int)($r['show_link'] ?? 1) === 1) ? 'checked' : '' ?> onchange="this.form.submit()"></label>
          </form>
        </td>
        <td style="width:1%;white-space:nowrap;text-align:center;">
          <?php if ((int)($r['rss_id'] ?? 0) > 0): ?>
          <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle_rss"><input type="hidden" name="rss_id" value="<?= e((string)$r['rss_id']) ?>">
            <label><input type="checkbox" name="show_rss" value="1" <?= ((int)($r['show_rss'] ?? 0) === 1) ? 'checked' : '' ?> onchange="this.form.submit()"></label>
          </form>
          <?php endif; ?>
        </td>
        <td><a class="button-secondary" href="<?= e(admin_url('link_partner_edit.php?id=' . (string)$r['id'])) ?>">編集</a></td>
        <td>
          <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e((string)$r['id']) ?>">
            <button type="submit" class="button-secondary">削除</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
