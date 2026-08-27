<?php

declare(strict_types=1);

function installer_logs_dir(): string { return __DIR__ . '/../logs'; }
function installer_log_file_path(): string { return installer_logs_dir() . '/install.log'; }
function installer_last_error_file_path(): string { return installer_logs_dir() . '/install_last_error.json'; }

function installer_log(string $message): void
{
    if (!is_dir(installer_logs_dir())) { @mkdir(installer_logs_dir(), 0755, true); }
    @file_put_contents(installer_log_file_path(), sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message), FILE_APPEND | LOCK_EX);
}

function installer_log_exception(string $step, Throwable $exception, ?string $sql = null): void
{
    installer_log(sprintf('step=%s exception=%s status=failed', $step, get_class($exception)));
}

function installer_clear_last_error(): void
{
    if (is_file(installer_last_error_file_path())) { @unlink(installer_last_error_file_path()); }
}

function installer_record_error_summary(string $step, Throwable $exception, ?string $failedSql = null): void
{
    $payload = [
        'time' => date('c'),
        'step' => $step,
        'class' => get_class($exception),
        'message' => installer_user_error_message($exception),
    ];
    if (!is_dir(installer_logs_dir())) { @mkdir(installer_logs_dir(), 0755, true); }
    @file_put_contents(installer_last_error_file_path(), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}

function installer_last_error_summary(): ?array
{
    if (!is_file(installer_last_error_file_path())) { return null; }
    $decoded = json_decode((string)file_get_contents(installer_last_error_file_path()), true);
    if (!is_array($decoded)) { return null; }
    return [
        'time' => (string)($decoded['time'] ?? ''),
        'step' => (string)($decoded['step'] ?? ''),
        'class' => (string)($decoded['class'] ?? ''),
        'message' => isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== ''
            ? 'セットアップ処理でエラーが発生しました。入力したDB情報とサーバー設定を確認してください。'
            : '',
    ];
}

function installer_safe_log_line(string $line): string
{
    $time = '';
    if (preg_match('/^\[[^\]]+\]/', $line, $m) === 1) $time = $m[0] . ' ';
    $parts = [];
    foreach (['step', 'status', 'exception', 'migration_applied', 'failed_keys', 'mysqli_errno', 'admin_exists', 'admin_created', 'settings_row_upserted', 'settings_table_normalized', 'already_completed', 'auto_run_blocked'] as $key) {
        if (preg_match('/(?:^|\s)' . preg_quote($key, '/') . '=([^\s]+)/', $line, $m) === 1) {
            $value = preg_replace('/[^A-Za-z0-9_.:,\-]/', '', (string)$m[1]) ?? '';
            if ($value !== '') $parts[] = $key . '=' . mb_substr($value, 0, 120);
        }
    }
    return $parts === [] ? $time . 'diagnostic=redacted' : $time . implode(' ', $parts);
}

function installer_log_tail(int $maxLines = 20): array
{
    if (!is_file(installer_log_file_path())) return ['lines' => [], 'error' => 'install.log が存在しません。'];
    $lines = @file(installer_log_file_path(), FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return ['lines' => [], 'error' => 'install.log の読み取りに失敗しました。'];
    $tail = array_slice($lines, -max(1, min(100, $maxLines)));
    return ['lines' => array_map(static fn(string $line): string => installer_safe_log_line($line), $tail), 'error' => null];
}

function installer_user_error_message(Throwable $exception): string
{
    $message = $exception->getMessage();
    if (str_contains($message, 'SQLSTATE[HY000] [2002]')) return 'MySQLサーバーへ接続できません。DBホスト名・DBポート・ユーザー名・パスワードを確認してください。';
    if (str_contains($message, 'Access denied')) return 'DBユーザー認証に失敗しました。DB設定を確認してください。';
    return 'セットアップ中にエラーが発生しました。サーバー設定を確認してください。';
}

function installer_request_host(): string
{
    $h = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return $h === '' ? '' : explode(':', $h, 2)[0];
}
function installer_request_remote_addr(): string { return strtolower(trim((string)($_SERVER['REMOTE_ADDR'] ?? ''))); }
function installer_is_local_request(): bool { return in_array(installer_request_remote_addr(), ['127.0.0.1', '::1'], true); }
function installer_can_auto_run(): bool { return installer_is_local_request(); }

function installer_auto_run_if_needed(): array
{
    installer_log('step=auto_check');
    $status = installer_status();
    if (($status['completed'] ?? false) === true) {
        installer_log('step=auto_check already_completed=true');
        return ['attempted' => false, 'success' => true, 'blocked' => false, 'result' => null];
    }
    if (!installer_can_auto_run()) {
        installer_log('step=server_connection auto_run_blocked=true');
        return ['attempted' => false, 'success' => false, 'blocked' => true, 'message' => '自動セットアップは localhost / 127.0.0.1 / ::1 でのみ実行できます。', 'result' => null];
    }
    $result = installer_run();
    return ['attempted' => true, 'success' => (bool)($result['success'] ?? false), 'blocked' => false, 'result' => $result];
}

function installer_can_connect_server(): bool
{
    try { db_server_pdo(); return true; }
    catch (Throwable $e) { installer_log_exception('server_connection', $e); return false; }
}

function installer_ensure_database_exists(): void
{
    $cfg = app_config()['db'];
    $dbname = (string)$cfg['dbname'];
    $stmt = db_server_pdo()->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :dbname LIMIT 1');
    $stmt->execute([':dbname' => $dbname]);
    if ($stmt->fetchColumn() !== false) { db_reset_connections(); return; }
    db_server_pdo()->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', str_replace('`', '``', $dbname)));
    db_reset_connections();
}

function installer_read_sql_file(string $path): string
{
    if (!is_file($path)) throw new RuntimeException('必要なSQLファイルが見つかりません。');
    return (string)file_get_contents($path);
}

function installer_apply_sql_file_mysqli_multi(mysqli $mysqli, string $path, string $step): int
{
    $sql = installer_read_sql_file($path);
    if (!$mysqli->multi_query($sql)) {
        installer_log('step=' . $step . ' mysqli_errno=' . (int)$mysqli->errno);
        throw new RuntimeException('SQL実行に失敗しました。');
    }
    $count = 0;
    do {
        $count++;
        $result = $mysqli->store_result();
        if ($result instanceof mysqli_result) $result->free();
    } while ($mysqli->more_results() && $mysqli->next_result());
    if ($mysqli->errno !== 0) {
        installer_log('step=' . $step . ' mysqli_errno=' . (int)$mysqli->errno);
        throw new RuntimeException('SQL実行に失敗しました。');
    }
    return $count;
}

function installer_execute_sql_file(string $path, string $step): int
{
    $cfg = app_config()['db'];
    $mysqli = mysqli_init();
    if ($mysqli === false) throw new RuntimeException('mysqli初期化に失敗しました。');
    if (!$mysqli->real_connect((string)$cfg['host'], (string)$cfg['user'], (string)$cfg['pass'], (string)$cfg['dbname'], (int)$cfg['port'])) throw new RuntimeException('DB接続に失敗しました。');
    if (!$mysqli->set_charset((string)$cfg['charset'])) throw new RuntimeException('文字コード設定に失敗しました。');
    try { return installer_apply_sql_file_mysqli_multi($mysqli, $path, $step); }
    catch (Throwable $e) { installer_log_exception($step, $e); throw $e; }
    finally { $mysqli->close(); }
}

function installer_ensure_migrations_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration_name VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function installer_apply_migrations(string $dir, string $step): int
{
    $pdo = db();
    installer_ensure_migrations_table($pdo);
    $files = glob(rtrim($dir, '/\\') . '/*.sql');
    if (!is_array($files)) return 0;
    sort($files, SORT_STRING);
    $count = 0;
    foreach ($files as $path) {
        $name = basename((string)$path);
        $stmt = $pdo->prepare('SELECT 1 FROM migrations WHERE migration_name = ? LIMIT 1');
        $stmt->execute([$name]);
        if ($stmt->fetchColumn() !== false) continue;
        installer_execute_sql_file($path, $step . ':' . $name);
        $pdo->prepare('INSERT INTO migrations (migration_name, applied_at) VALUES (?, NOW())')->execute([$name]);
        installer_log('step=' . $step . ' migration_applied=' . $name);
        $count++;
    }
    return $count;
}

function installer_normalize_settings_table(PDO $pdo, string $stepLabel): void
{
    if (!db_table_exists('settings')) return;
    $rows = $pdo->query('SHOW COLUMNS FROM settings')->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $rows);
    if (in_array('setting_key', $columns, true) && in_array('setting_value', $columns, true)) return;

    $tmpTable = 'settings_kv_tmp';
    $pdo->exec('DROP TABLE IF EXISTS `' . $tmpTable . '`');
    $pdo->exec('CREATE TABLE `' . $tmpTable . '` (setting_key VARCHAR(191) PRIMARY KEY, setting_value LONGTEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    foreach ([['setting_key','setting_value'],['key_name','value_text'],['setting_name','setting_text'],['name','value']] as [$keyCol, $valueCol]) {
        if (!in_array($keyCol, $columns, true) || !in_array($valueCol, $columns, true)) continue;
        $pdo->exec(sprintf('INSERT INTO `%s`(setting_key, setting_value, created_at, updated_at) SELECT CAST(`%s` AS CHAR(191)), CAST(`%s` AS CHAR), NOW(), NOW() FROM settings WHERE `%s` IS NOT NULL AND `%s` <> "" ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()', $tmpTable, $keyCol, $valueCol, $keyCol, $keyCol));
    }
    if (in_array('api_id', $columns, true)) {
        $orderBy = in_array('id', $columns, true) ? ' ORDER BY id ASC ' : '';
        $pdo->exec('INSERT INTO `' . $tmpTable . '`(setting_key, setting_value, created_at, updated_at) SELECT "duga_api_id", COALESCE(CAST(api_id AS CHAR), ""), NOW(), NOW() FROM settings' . $orderBy . 'LIMIT 1 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
    }
    if (in_array('affiliate_id', $columns, true)) {
        $orderBy = in_array('id', $columns, true) ? ' ORDER BY id ASC ' : '';
        $pdo->exec('INSERT INTO `' . $tmpTable . '`(setting_key, setting_value, created_at, updated_at) SELECT "duga_affiliate_id", COALESCE(CAST(affiliate_id AS CHAR), ""), NOW(), NOW() FROM settings' . $orderBy . 'LIMIT 1 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()');
    }
    $backup = 'settings_legacy_backup';
    $pdo->exec('DROP TABLE IF EXISTS `' . $backup . '`');
    $pdo->exec('RENAME TABLE settings TO `' . $backup . '`, `' . $tmpTable . '` TO settings');
    installer_log('step=' . $stepLabel . ' settings_table_normalized=true');
}

function installer_ensure_admin_user(PDO $pdo, string $stepLabel): bool
{
    $stmt = $pdo->query('SELECT 1 FROM admins ORDER BY id ASC LIMIT 1');
    if ($stmt !== false && $stmt->fetchColumn() !== false) {
        installer_log('step=' . $stepLabel . ' admin_exists=true');
        return false;
    }
    $initialPassword = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(18))), 0, 18);
    $insert = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)');
    $insert->execute(['username' => 'admin', 'password_hash' => password_hash($initialPassword, PASSWORD_DEFAULT)]);
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['installer_initial_password'] = $initialPassword;
    installer_log('step=' . $stepLabel . ' admin_created=true');
    return true;
}

function installer_ensure_settings_row(PDO $pdo, string $stepLabel): bool
{
    require_once __DIR__ . '/site_settings.php';
    try {
        site_setting_set('installer.ready', '1');
        installer_log('step=' . $stepLabel . ' settings_row_upserted=true');
        return true;
    } catch (Throwable $e) {
        installer_log_exception($stepLabel, $e);
        throw $e;
    }
}

function installer_status(): array
{
    $status = ['server_connection'=>false,'db_connection'=>false,'admins_table'=>false,'settings_table'=>false,'admin_user'=>false,'settings_row'=>false,'completed'=>false];
    $status['server_connection'] = installer_can_connect_server();
    if (!$status['server_connection']) return $status;
    $status['db_connection'] = db_can_connect();
    if (!$status['db_connection']) return $status;
    $status['admins_table'] = db_table_exists('admins');
    $status['settings_table'] = db_table_exists('settings');
    if ($status['admins_table']) {
        try {
            $stmt = db()->query('SELECT 1 FROM admins ORDER BY id ASC LIMIT 1');
            $status['admin_user'] = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable) { $status['admin_user'] = false; }
    }
    if ($status['settings_table']) {
        try {
            $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
            $stmt->execute([':key' => 'installer.ready']);
            $status['settings_row'] = (string)($stmt->fetchColumn() ?: '') === '1';
        } catch (Throwable) { $status['settings_row'] = false; }
    }
    $status['completed'] = $status['server_connection'] && $status['db_connection'] && $status['admins_table'] && $status['settings_table'] && $status['admin_user'] && $status['settings_row'];
    return $status;
}

function installer_run(): array
{
    installer_log('step=start');
    $result = ['success'=>false,'steps'=>[],'error'=>null,'error_detail'=>null,'failed_sql'=>null,'error_summary'=>null,'log_tail'=>null];
    $currentStep = 'server_connection';
    $step = static function (string $id, bool $ok, string $message = '') use (&$result): void {
        $result['steps'][] = ['id'=>$id,'status'=>$ok?'ok':'ng','message'=>$message];
    };
    try {
        installer_clear_last_error();
        if (!installer_can_connect_server()) throw new RuntimeException('MySQLサーバーに接続できません。');
        $step('server_connection', true);
        $currentStep = 'create_database'; installer_ensure_database_exists(); $step('create_database', true);
        $currentStep = 'create_tables'; $tableCount = installer_execute_sql_file(__DIR__ . '/../sql/schema.sql', 'create_tables'); $step('create_tables', true, 'results=' . $tableCount);
        $currentStep = 'apply_migrations'; $migrationCount = installer_apply_migrations(__DIR__ . '/../sql/migrations', 'apply_migrations'); $step('apply_migrations', true, 'count=' . $migrationCount);
        $currentStep = 'normalize_settings'; installer_normalize_settings_table(db(), 'normalize_settings'); $step('normalize_settings', true);
        $currentStep = 'seed_data';
        $seedPath = __DIR__ . '/../sql/seed.sql';
        if (is_file($seedPath)) installer_execute_sql_file($seedPath, 'seed_data');
        installer_ensure_admin_user(db(), 'seed_data');
        installer_ensure_settings_row(db(), 'seed_data');
        $step('seed_data', true);
        $currentStep = 'completion_check';
        installer_ensure_admin_user(db(), 'completion_check_retry');
        installer_ensure_settings_row(db(), 'completion_check_retry');
        $status = installer_status();
        if (($status['completed'] ?? false) !== true) {
            $requiredKeys = ['server_connection','db_connection','admins_table','settings_table','admin_user','settings_row'];
            $failedKeys = array_values(array_filter($requiredKeys, static fn(string $key): bool => ($status[$key] ?? false) !== true));
            installer_log('step=completion_check failed_keys=' . implode(',', $failedKeys));
            throw new RuntimeException('セットアップ完了条件を満たせませんでした。');
        }
        $step('completion_check', true);
        installer_log('step=completed status=ok');
        $result['success'] = true;
    } catch (Throwable $e) {
        installer_log_exception($currentStep, $e);
        installer_record_error_summary($currentStep, $e);
        $result['error'] = installer_user_error_message($e);
        $step($currentStep, false, $result['error']);
    }
    $result['error_summary'] = installer_last_error_summary();
    $result['log_tail'] = installer_log_tail(30);
    return $result;
}
