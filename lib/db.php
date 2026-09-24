<?php

declare(strict_types=1);

function db_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}
function db_validate_config(array $cfg, bool $requireDbName): array
{
    $errors = [];
    if (trim((string)($cfg['host'] ?? '')) === '') {
        $errors[] = 'host が空です';
    }
    if ((int)($cfg['port'] ?? 0) <= 0 || (int)$cfg['port'] > 65535) {
        $errors[] = 'port が不正です';
    }
    if (trim((string)($cfg['user'] ?? '')) === '') {
        $errors[] = 'user が空です';
    }
    if (trim((string)($cfg['charset'] ?? '')) === '') {
        $errors[] = 'charset が空です';
    }
    if ($requireDbName && trim((string)($cfg['dbname'] ?? '')) === '') {
        $errors[] = 'dbname が空です';
    }

    return $errors;
}

function db_log_connection_error(array $cfg, string $dsn, Throwable $e, array $errors = []): void
{
    $payload = [
        'host' => (string)($cfg['host'] ?? ''),
        'port' => (int)($cfg['port'] ?? 0),
        'dbname' => (string)($cfg['dbname'] ?? ''),
        'user' => (string)($cfg['user'] ?? ''),
        'dsn' => $dsn,
        'error' => $e->getMessage(),
        'config_errors' => $errors,
    ];

    error_log('db connection failed: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Safe diagnostics shared by setup and the installer; never echo driver messages. */
function db_connection_error_message(Throwable $exception): string
{
    if (!extension_loaded('pdo_mysql')) {
        return 'PHPのPDO MySQL拡張が有効ではありません。';
    }
    while ($exception->getPrevious() !== null) {
        $exception = $exception->getPrevious();
    }
    $code = $exception instanceof PDOException
        ? (int)($exception->errorInfo[1] ?? 0)
        : (int)$exception->getCode();
    return match ($code) {
        1045 => 'MySQLの認証が拒否されました（1045）。DBユーザー名・パスワード・接続元ホストの許可を確認してください。',
        1044 => '対象DBへのアクセス権がありません（1044）。サーバーパネルでDBユーザーを対象DBへ追加し、権限を確認してください。',
        1049 => '指定したデータベースが存在しません（1049）。サーバーパネルのDB名を確認してください。',
        2002, 2003, 2005, 2006, 2013 => 'MySQLサーバーへ接続できません。DBホスト名・ポート・稼働状況を確認してください。',
        default => 'DB接続情報とサーバーのMySQL設定を確認してください。',
    };
}

function db_server_pdo(): PDO
{
    if (isset($GLOBALS['__db_server_pdo']) && $GLOBALS['__db_server_pdo'] instanceof PDO) {
        return $GLOBALS['__db_server_pdo'];
    }

    $cfg = app_config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $cfg['host'], (int)$cfg['port'], $cfg['charset']);
    $configErrors = db_validate_config($cfg, false);

    if ($configErrors !== []) {
        $e = new RuntimeException('DB 設定不足: ' . implode(', ', $configErrors));
        db_log_connection_error($cfg, $dsn, $e, $configErrors);
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。', 0, $e);
    }

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], db_options());
    } catch (Throwable $e) {
        db_log_connection_error($cfg, $dsn, $e);
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。', 0, $e);
    }

    $GLOBALS['__db_server_pdo'] = $pdo;
    return $pdo;
}

function db_pdo(): PDO
{
    if (isset($GLOBALS['__db_pdo']) && $GLOBALS['__db_pdo'] instanceof PDO) {
        return $GLOBALS['__db_pdo'];
    }

    $cfg = app_config()['db'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $cfg['host'], (int)$cfg['port'], $cfg['dbname'], $cfg['charset']);
    $configErrors = db_validate_config($cfg, true);

    if ($configErrors !== []) {
        $e = new RuntimeException('DB 設定不足: ' . implode(', ', $configErrors));
        db_log_connection_error($cfg, $dsn, $e, $configErrors);
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。', 0, $e);
    }

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], db_options());
    } catch (Throwable $e) {
        db_log_connection_error($cfg, $dsn, $e);
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。', 0, $e);
    }

    $GLOBALS['__db_pdo'] = $pdo;
    return $pdo;
}

function db_reset_connections(): void
{
    unset($GLOBALS['__db_server_pdo'], $GLOBALS['__db_pdo']);
    db_reset_schema_cache();
    unset($GLOBALS['__site_settings_cache']);
}

function db_reset_schema_cache(): void
{
    unset($GLOBALS['__db_table_exists'], $GLOBALS['__db_column_exists'], $GLOBALS['__site_settings_columns']);
}

function db(): PDO
{
    return db_pdo();
}

function db_can_connect(): bool
{
    try {
        db();
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param PDO|string $pdoOrTable
 */
function db_table_exists($pdoOrTable, ?string $table = null): bool
{
    $cache = &$GLOBALS['__db_table_exists'];
    if (!is_array($cache)) {
        $cache = [];
    }

    try {
        $pdo = $pdoOrTable instanceof PDO ? $pdoOrTable : db();
        $tableName = $pdoOrTable instanceof PDO ? (string)$table : (string)$pdoOrTable;
        if ($tableName === '') {
            return false;
        }

        $cfg = app_config()['db'];
        $cacheKey = spl_object_id($pdo) . '.' . (string)$cfg['dbname'] . '.' . $tableName;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'schema' => (string)$cfg['dbname'],
            'table' => $tableName,
        ]);
        $cache[$cacheKey] = (int)$stmt->fetchColumn() > 0;
        return $cache[$cacheKey];
    } catch (Throwable) {
        return false;
    }
}

function db_column_exists(string $table, string $column): bool
{
    $cache = &$GLOBALS['__db_column_exists'];
    if (!is_array($cache)) {
        $cache = [];
    }

    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $column)) {
        return false;
    }

    try {
        $cfg = app_config()['db'];
        $cacheKey = spl_object_id(db()) . '.' . (string)$cfg['dbname'] . '.' . $table . '.' . $column;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = :schema AND table_name = :table AND column_name = :column
             LIMIT 1'
        );
        $stmt->execute([
            'schema' => (string)$cfg['dbname'],
            'table' => $table,
            'column' => $column,
        ]);
        $cache[$cacheKey] = (int)$stmt->fetchColumn() > 0;
        return $cache[$cacheKey];
    } catch (Throwable) {
        return false;
    }
}
