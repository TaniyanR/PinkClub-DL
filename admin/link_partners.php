<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
require_once __DIR__ . '/../lib/rss_access_trade.php';
require_once __DIR__ . '/../lib/rss_access_trade_host.php';
auth_require_admin();
analytics_ensure_tables();
$title = '相互リンク管理';
$message = null;

// 既存DBでもこの画面を開くだけで新しい設定を利用できるよう互換追加する。
$partnerNofollowSupported = db_column_exists('partner_sites', 'rel_nofollow');
if (!$partnerNofollowSupported) {
    try {
        db()->exec('ALTER TABLE partner_sites ADD COLUMN rel_nofollow TINYINT(1) NOT NULL DEFAULT 0 AFTER show_link');
        $partnerNofollowSupported = true;
    } catch (Throwable $e) {
        error_log('[partner-links] rel_nofollow column setup failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_fail((string)post('_csrf', ''));
    $action = (string)post('action', 'create');
    if ($action === 'create') {
        $name = trim((string)post('name', ''));
        $url = trim((string)post('url', ''));
        $rssUrl = trim((string)post('rss_url', ''));
        $refCode = 'partner_' . substr(sha1($name . '|' . $url . '|' . microtime(true)), 0, 16);
        $showLink = post('show_link', '0') === '1' ? 1 : 0;
        $relNofollow = post('rel_nofollow', '0') === '1' ? 1 : 0;

        if ($partnerNofollowSupported) {
            db()->prepare('INSERT INTO partner_sites(name,ref_code,url,is_enabled,show_link,rel_nofollow,created_at,updated_at) VALUES(:name,:ref,:url,1,:show_link,:rel_nofollow,NOW(),NOW())')
                ->execute([
                    ':name' => $name,
                    ':ref' => $refCode,
                    ':url' => $url,
                    ':show_link' => $showLink,
                    ':rel_nofollow' => $relNofollow,
                ]);
        } else {
            db()->prepare('INSERT INTO partner_sites(name,ref_code,url,is_enabled,show_link,created_at,updated_at) VALUES(:name,:ref,:url,1,:show_link,NOW(),NOW())')
                ->execute([
                    ':name' => $name,
                    ':ref' => $refCode,
                    ':url' => $url,
                    ':show_link' => $showLink,
                ]);
        }

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
    } elseif ($action === 'toggle_nofollow' && $partnerNofollowSupported) {
        db()->prepare('UPDATE partner_sites SET rel_nofollow = :nofollow, updated_at = NOW() WHERE id = :id')
            ->execute([':nofollow' => post('rel_nofollow', '0') === '1' ? 1 : 0, ':id' => (int)post('id', 0)]);
        $message = 'rel="nofollow"設定を更新しました。';
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
$rows = db()->query(
    'SELECT ps.*, pr.id AS rss_id, pr.feed_url, COALESCE(pr.show_rss, pr.is_enabled, 0) AS show_rss '
    . 'FROM partner_sites ps '
    . 'LEFT JOIN partner_rss pr ON pr.id = ( '
    . 'SELECT pr2.id FROM partner_rss pr2 '
    . 'WHERE pr2.partner_site_id = ps.id '
    . 'ORDER BY pr2.updated_at DESC, pr2.id DESC LIMIT 1 '
    . ') '
    . 'ORDER BY ps.id DESC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$metricItems = [];
foreach ($rows as $r) {
    $metricItems[] = [
        'partner_ref_code' => trim((string)($r['ref_code'] ?? '')),
        'partner_site_url' => trim((string)($r['url'] ?? '')),
        'link' => trim((string)($r['url'] ?? '')),
    ];
}
$tradeMetrics = rss_trade_metrics_host_aware($metricItems, 30);

require __DIR__ . '/includes/header.php';
?>
<style>
.partner-link-options {
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:10px;
  margin:12px 0 14px;
}
body.admin-page .admin-card--form form > .partner-link-options > .partner-link-option {
  display:flex;
  align-items:center;
  gap:9px;
  min-height:46px;
  margin:0;
  padding:0 14px;
  border:1px solid #d7dce1;
  border-radius:6px;
  background:#f7f8f9;
  box-sizing:border-box;
  font-weight:600;
  white-space:nowrap;
}
body.admin-page .admin-card--form form > .partner-link-options > .partner-link-option input[type="checkbox"] {
  width:auto;
  max-width:none;
  margin:0;
  flex:0 0 auto;
}
.partner-link-table-toggle label {
  display:flex;
  align-items:center;
  justify-content:center;
  margin:0;
}
.partner-link-table-toggle input[type="checkbox"] {
  width:auto;
  max-width:none;
  margin:0;
}
@media (max-width: 900px) {
  .partner-link-options { grid-template-columns:1fr; }
  body.admin-page .admin-card--form form > .partner-link-options > .partner-link-option { white-space:normal; }
}
</style>
<section class="admin-card admin-card--form">
  <h1>相互リンク管理</h1>
  <?php if ($message): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <form method="post" style="max-width:760px;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="create">
    <label>サイト名<input name="name" required></label>
    <label>URL<input name="url" type="url" required></label>
    <label>RSS URL<input name="rss_url" type="url"></label>
    <div class="partner-link-options">
      <label class="partner-link-option"><input type="checkbox" name="show_link" value="1" checked> 相互リンクを表示する</label>
      <label class="partner-link-option"><input type="checkbox" name="rel_nofollow" value="1" <?= !$partnerNofollowSupported ? 'disabled' : '' ?>> rel="nofollow"</label>
      <label class="partner-link-option"><input type="checkbox" name="show_rss" value="1" checked> RSSを表示する</label>
    </div>
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
  <p style="margin-top:0;">アクセストレードは直近30日のIN/OUTを使用します。「返還不足」は IN − OUT、配分ウェイトは現在のRSS優先度計算値です。</p>
  <div style="overflow-x:auto;">
  <table class="admin-table">
    <tr><th>ID</th><th style="white-space:nowrap;">サイト名</th><th>URL</th><th style="white-space:nowrap;">30日IN</th><th style="white-space:nowrap;">30日OUT</th><th style="white-space:nowrap;">返還不足</th><th style="white-space:nowrap;">配分ウェイト</th><th style="width:1%;white-space:nowrap;text-align:center;">相互リンク表示</th><th style="width:1%;white-space:nowrap;text-align:center;">rel="nofollow"</th><th style="width:1%;white-space:nowrap;text-align:center;">RSS表示</th><th>編集</th><th>削除</th></tr>
    <?php foreach ($rows as $r): ?>
      <?php
        $ref = trim((string)($r['ref_code'] ?? ''));
        $metric = $tradeMetrics[$ref] ?? ['in' => 0, 'out' => 0];
        $inCount = max(0, (int)($metric['in'] ?? 0));
        $outCount = max(0, (int)($metric['out'] ?? 0));
        $debt = $inCount - $outCount;
        $weight = rss_trade_weight($inCount, $outCount);
      ?>
      <tr>
        <td><?= e((string)$r['id']) ?></td><td style="white-space:nowrap;"><?= e((string)$r['name']) ?></td><td><?= e((string)$r['url']) ?></td>
        <td><?= e((string)$inCount) ?></td>
        <td><?= e((string)$outCount) ?></td>
        <td><?= e(($debt > 0 ? '+' : '') . (string)$debt) ?></td>
        <td><?= e(number_format($weight, 2, '.', '')) ?></td>
        <td class="partner-link-table-toggle" style="width:1%;white-space:nowrap;text-align:center;">
          <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle_link"><input type="hidden" name="id" value="<?= e((string)$r['id']) ?>">
            <label><input type="checkbox" name="show_link" value="1" <?= ((int)($r['show_link'] ?? 1) === 1) ? 'checked' : '' ?> onchange="this.form.submit()"></label>
          </form>
        </td>
        <td class="partner-link-table-toggle" style="width:1%;white-space:nowrap;text-align:center;">
          <?php if ($partnerNofollowSupported): ?>
          <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="toggle_nofollow"><input type="hidden" name="id" value="<?= e((string)$r['id']) ?>">
            <label><input type="checkbox" name="rel_nofollow" value="1" <?= ((int)($r['rel_nofollow'] ?? 0) === 1) ? 'checked' : '' ?> onchange="this.form.submit()"></label>
          </form>
          <?php endif; ?>
        </td>
        <td class="partner-link-table-toggle" style="width:1%;white-space:nowrap;text-align:center;">
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
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
