<?php
/**
 * PDO database connection (singleton).
 * All database access in the project must go through db().
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbPort = getenv('TC_DB_PORT') ?: '3306';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $dbPort . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        // Optional, environment-only: lets a strict local MySQL behave like the
        // production MariaDB (e.g. TC_DB_SQL_MODE=NO_ENGINE_SUBSTITUTION). Unset on live.
        $sqlMode = getenv('TC_DB_SQL_MODE');
        if (is_string($sqlMode) && $sqlMode !== '') {
            $pdo->prepare('SET SESSION sql_mode = ?')->execute([$sqlMode]);
        }
    } catch (PDOException $e) {
        error_log('[tayyaba] DB connection failed: ' . $e->getMessage());
        http_response_code(500);
        require __DIR__ . '/../500.php';
        exit;
    }

    return $pdo;
}