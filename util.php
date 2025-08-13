<?php
declare(strict_types=1);

/**
 * Load configuration and provide a PDO connection to MySQL.
 */
function get_mysql_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = require __DIR__ . '/config.php';
    $db = $config['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('Database configuration missing.');
    }
    $host = $db['host'] ?? 'localhost';
    $port = (int)($db['port'] ?? 3306);
    $name = $db['database'] ?? '';
    $user = $db['username'] ?? '';
    $pass = $db['password'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';
    $collation = $db['collation'] ?? 'utf8mb4_unicode_ci';
    $options = $db['options'] ?? [];

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Ensure connection collation
    $pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
    return $pdo;
}

