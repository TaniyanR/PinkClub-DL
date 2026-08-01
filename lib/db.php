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
    if ((int)($cfg['port'] ?? 0) <= 0) {
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
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。');
    }

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], db_options());
    } catch (Throwable $e) {
        db_log_connection_error($cfg, $dsn, $e);
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。');
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
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。');
    }

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], db_options());
    } catch (Throwable $e) {
        db_log_connection_error($cfg, $dsn, $e);
        throw new RuntimeException('DB接続に失敗しました（設定を確認してください）。');
    }

    $GLOBALS['__db_pdo'] = $pdo;
    return $pdo;
}

function db_reset_connections(): void
{
    unset($GLOBALS['__db_server_pdo'], $GLOBALS['__db_pdo']);
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
    static $cache = [];

    try {
        $pdo = $pdoOrTable instanceof PDO ? $pdoOrTable : db();
        $tableName = $pdoOrTable instanceof PDO ? (string)$table : (string)$pdoOrTable;
        if ($tableName === '') {
            return false;
        }

        $cfg = app_config()['db'];
        $cacheKey = (string)$cfg['dbname'] . '.' . $tableName;
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
    static $cache = [];

    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', $table) || !preg_match('/\A[a-zA-Z0-9_]+\z/', $column)) {
        return false;
    }

    try {
        $cfg = app_config()['db'];
        $cacheKey = (string)$cfg['dbname'] . '.' . $table . '.' . $column;
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
