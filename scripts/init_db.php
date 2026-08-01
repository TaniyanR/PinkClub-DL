<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';

function init_db(): array
{
    $pdo = db();
    db_ensure_initialized($pdo);

    $schemaStatements = db_sql_split_statements((string)file_get_contents(db_schema_file_path()));
    $migrationFiles = glob(__DIR__ . '/../sql/migrations/*.sql');
    $migrationCount = is_array($migrationFiles) ? count($migrationFiles) : 0;

    return [
        'source' => 'schema.sql + migrations',
        'count' => count($schemaStatements) + $migrationCount,
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = init_db();
        fwrite(STDOUT, sprintf("DB初期化が完了しました。（%s: %d）\n", $result['source'], $result['count']));
    } catch (Throwable $e) {
        fwrite(STDERR, "DB初期化に失敗しました: " . $e->getMessage() . "\n");
        throw $e;
    }
}
