<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/_bootstrap.php';
auth_require_admin();

$title = 'サイト設定';
$message = null;
$error = null;
$recommendedTagline = 'DUGAの新着・人気作品を、サンプル動画・画像を見ながら出演者やジャンルから手軽に探せる作品情報サイトです。';
$recommendedKeywords = 'PinkClub-DL,DUGA,新着動画,人気動画,アダルト動画,サンプル動画,サンプル画像,出演者,ジャンル,メーカー,シリーズ';

$uploadDir = __DIR__ . '/../public/uploads/site_settings';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

$saveImage = static function(array $file,string $prefix,int $minW,int $maxW,int $minH,int $maxH,bool $squareOnly,array $allowedMimes) use($uploadDir):array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)return['ok'=>false,'message'=>'アップロードに失敗しました。'];
    $size=(int)($file['size']??0);if($size<1||$size>5*1024*1024)return['ok'=>false,'message'=>'画像ファイルは5MB以内で指定してください。'];
    $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))return['ok'=>false,'message'=>'不正なファイルです。'];
    $info=@getimagesize($tmp);if(!is_array($info))return['ok'=>false,'message'=>'画像ファイルを指定してください。'];[$w,$h]=$info;$mime=strtolower((string)($info['mime']??''));if(!in_array($mime,$allowedMimes,true))return['ok'=>false,'message'=>'対応していない画像形式です。'];
    if($w<$minW||$w>$maxW||$h<$minH||$h>$maxH)return['ok'=>false,'message'=>$squareOnly?sprintf('画像サイズは正方形で %d〜%dpx の範囲で指定してください。',$minW,$maxW):sprintf('画像サイズは %d-%dpx x %d-%dpx の範囲で指定してください。',$minW,$maxW,$minH,$maxH)];
    if($squareOnly&&$w!==$h)return['ok'=>false,'message'=>'ファビコンは正方形のみ対応です。'];if(!is_dir($uploadDir)||!is_writable($uploadDir))return['ok'=>false,'message'=>'保存先ディレクトリを作成できませんでした。'];
    $map=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif','image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'];$ext=$map[$mime]??'';if($ext==='')return['ok'=>false,'message'=>'画像形式を判定できませんでした。'];
    try{$suffix=bin2hex(random_bytes(10));}catch(Throwable){$suffix=str_replace('.','',uniqid('',true));}$safePrefix=preg_replace('/[^a-z0-9_-]/i','',$prefix)?:'image';$name=$safePrefix.'-'.$suffix.'.'.$ext;$dest=$uploadDir.'/'.$name;
    if(!move_uploaded_file($tmp,$dest))return['ok'=>false,'message'=>'画像の保存に失敗しました。'];@chmod($dest,0644);return['ok'=>true,'path'=>'uploads/site_settings/'.$name];
};

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_validate_or_fail((string)post('_csrf',''));$siteName=trim((string)post('site_name',''));$siteUrl=rtrim(trim((string)post('site_url','')),'/');$tagline=trim((string)post('site_tagline',''));$keywords=trim((string)post('site_keywords',''));$adminEmail=trim((string)post('site_admin_email',''));
    $parts=$siteUrl!==''?parse_url($siteUrl):false;if($siteUrl===''||filter_var($siteUrl,FILTER_VALIDATE_URL)===false||!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||trim((string)($parts['host']??''))===''||isset($parts['user'])||isset($parts['pass']))$error='サイトURLは http:// または https:// から始まる正しいURLを入力してください。';
    if($error===null&&($adminEmail===''||filter_var($adminEmail,FILTER_VALIDATE_EMAIL)===false))$error='お問い合わせ受信メールアドレスを正しく入力してください。';
    $updates=['site.title'=>$siteName,'site.name'=>$siteName,'site.url'=>$siteUrl,'site.tagline'=>$tagline,'site.keywords'=>$keywords,'site.admin_email'=>$adminEmail];
    if($error===null&&isset($_FILES['site_logo'])&&(int)($_FILES['site_logo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$r=$saveImage((array)$_FILES['site_logo'],'logo',250,400,50,100,false,['image/png','image/jpeg','image/webp','image/gif']);if(($r['ok']??false)===true)$updates['site.logo_path']=(string)$r['path'];else$error=(string)($r['message']??'ロゴ画像の保存に失敗しました。');}
    if($error===null&&isset($_FILES['site_favicon'])&&(int)($_FILES['site_favicon']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$r=$saveImage((array)$_FILES['site_favicon'],'favicon',48,512,48,512,true,['image/png','image/x-icon','image/vnd.microsoft.icon']);if(($r['ok']??false)===true)$updates['site.favicon_path']=(string)$r['path'];else$error=(string)($r['message']??'ファビコン画像の保存に失敗しました。');}
    if($error===null){site_setting_set_many($updates);$message='サイト設定を保存しました。';}
}
$logoPath=trim(site_setting_get('site.logo_path',''));$faviconPath=trim(site_setting_get('site.favicon_path',''));$taglineValue=trim(site_setting_get('site.tagline',''));$taglineNeedsSave=$taglineValue==='';if($taglineNeedsSave)$taglineValue=$recommendedTagline;$keywordsValue=trim(site_setting_get('site.keywords',''));$keywordsNeedSave=$keywordsValue==='';if($keywordsNeedSave)$keywordsValue=$recommendedKeywords;$adminEmailValue=setting_admin_email('');
require __DIR__ . '/includes/header.php';
?>
<section class="admin-card admin-card--form">
  <h1>サイト設定</h1>
  <?php if($message!==null): ?><p class="flash success"><?= e($message) ?></p><?php endif; ?>
  <?php if($error!==null): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" style="max-width:760px;">
    <?= csrf_input() ?>
    <label>サイト名<input type="text" name="site_name" value="<?= e(site_setting_get('site.title',site_setting_get('site.name',APP_NAME))) ?>"></label>
    <label>URL<input type="url" name="site_url" value="<?= e(site_setting_get('site.url',app_url())) ?>"></label>
    <label>お問い合わせ受信メールアドレス<input type="email" name="site_admin_email" value="<?= e($adminEmailValue) ?>" required autocomplete="email"><small>一般のお問い合わせ・掲載削除依頼・パスワード再設定メールの受信先として使用します。</small></label>
    <label>総合RSS（10分間隔）<input type="url" value="<?= e(public_url('feed-10.php')) ?>" readonly></label>
    <label>総合RSS（1時間間隔）<input type="url" value="<?= e(public_url('feed-60.php')) ?>" readonly></label>
    <label>ランダムRSS（10分間隔）<input type="url" value="<?= e(public_url('feed-free-10.php')) ?>" readonly></label>
    <label>ランダムRSS（1時間間隔）<input type="url" value="<?= e(public_url('feed-free-60.php')) ?>" readonly></label>
    <label>サイトマップ URL<input type="url" value="<?= e(public_url('sitemap.php')) ?>" readonly></label>
    <label>キャッチフレーズ（検索結果説明用）<input type="text" name="site_tagline" value="<?= e($taglineValue) ?>"><?php if($taglineNeedsSave): ?><small>PinkClub-DL向けの初期値を入力しています。内容を確認して保存してください。</small><?php endif; ?></label>
    <label>キーワード（meta keywords）<input type="text" name="site_keywords" value="<?= e($keywordsValue) ?>"><?php if($keywordsNeedSave): ?><small>PinkClub-DL向けの初期値を入力しています。保存ボタンを押すと反映されます。</small><?php endif; ?></label>
    <label>タイトルロゴ（横250〜400px / 高さ50〜100px）<input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp,image/gif"><?php if($logoPath!==''): ?><small>現在: <?= e($logoPath) ?></small><?php endif; ?></label>
    <label>ファビコン（正方形 48〜512px、PNG/ICO）<input type="file" name="site_favicon" accept="image/png,image/x-icon,image/vnd.microsoft.icon,.ico"><?php if($faviconPath!==''): ?><small>現在: <?= e($faviconPath) ?></small><?php endif; ?></label>
    <div class="admin-actions"><button type="submit">保存</button></div>
  </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
