-- 管理者アカウントは lib/installer.php が安全なランダム初期パスワードで作成する。
-- 固定の admin / password はここでは作成しない。

INSERT INTO settings (setting_key, setting_value)
VALUES
  ('site.tagline', 'DUGAの新着・人気作品を、サンプル動画・画像を見ながら出演者やジャンルから手軽に探せる作品情報サイトです。'),
  ('site.keywords', 'PinkClub-DL,DUGA,新着動画,人気動画,アダルト動画,サンプル動画,サンプル画像,出演者,ジャンル,メーカー,シリーズ')
ON DUPLICATE KEY UPDATE
  setting_value = IF(setting_value IS NULL OR setting_value = '', VALUES(setting_value), setting_value);
