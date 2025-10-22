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

function budget_ensure_transaction_date_columns(PDO $pdo): void
{
    try { $pdo->exec('ALTER TABLE transactions ADD COLUMN initiated_date DATE NULL'); } catch (Throwable $e) { /* ignore if exists */ }
    try { $pdo->exec('ALTER TABLE transactions ADD COLUMN mailed_date DATE NULL'); } catch (Throwable $e) { /* ignore if exists */ }
    try { $pdo->exec('ALTER TABLE transactions ADD COLUMN settled_date DATE NULL'); } catch (Throwable $e) { /* ignore if exists */ }
    try {
        $pdo->exec("UPDATE transactions SET initiated_date = COALESCE(initiated_date, `date`) WHERE initiated_date IS NULL AND `date` IS NOT NULL");
    } catch (Throwable $e) { /* best effort */ }
    try {
        $pdo->exec("UPDATE transactions SET settled_date = `date` WHERE posted = 1 AND settled_date IS NULL AND `date` IS NOT NULL");
    } catch (Throwable $e) { /* best effort */ }
}

/**
 * Persistent login ("remember me") helpers.
 * Implements a selector/validator token stored server-side and in a secure cookie.
 */
function auth_cookie_name(): string { return 'remember_me'; }

function auth_is_https(): bool {
    return (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );
}

function auth_ensure_tokens_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS auth_tokens (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(190) NOT NULL,
                selector VARCHAR(64) NOT NULL UNIQUE,
                validator_hash CHAR(64) NOT NULL,
                user_agent_hash CHAR(64) DEFAULT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                INDEX (username),
                INDEX (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        // best-effort; do not block
    }
}

function auth_issue_remember_cookie(PDO $pdo, string $username): void
{
    auth_ensure_tokens_table($pdo);
    $selector = bin2hex(random_bytes(16)); // 32 hex
    $validator = bin2hex(random_bytes(32)); // 64 hex
    $validatorHash = hash('sha256', $validator);
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $uaHash = $ua !== '' ? hash('sha256', $ua) : null;
    // Long-lived expiration; refreshed on each cookie login
    $expires = (new DateTimeImmutable('+365 days'))->format('Y-m-d H:i:s');
    try {
        $ins = $pdo->prepare('INSERT INTO auth_tokens (username, selector, validator_hash, user_agent_hash, expires_at) VALUES (?,?,?,?,?)');
        $ins->execute([$username, $selector, $validatorHash, $uaHash, $expires]);
    } catch (Throwable $e) {
        // ignore and continue without cookie
        return;
    }

    $cookieValue = $selector . ':' . $validator;
    $params = [
        'expires' => time() + 365*24*60*60, // 1 year
        'path' => '/',
        'domain' => '', // default
        'secure' => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(auth_cookie_name(), $cookieValue, $params);
}

function auth_clear_remember_cookie(PDO $pdo): void
{
    $cookie = (string)($_COOKIE[auth_cookie_name()] ?? '');
    if ($cookie !== '' && strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        try {
            $del = $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?');
            $del->execute([$selector]);
        } catch (Throwable $e) {
            // ignore
        }
    }
    // Expire cookie on client
    $params = [
        'expires' => time() - 3600,
        'path' => '/',
        'domain' => '',
        'secure' => auth_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    setcookie(auth_cookie_name(), '', $params);
}

/**
 * Attempt to authenticate the user from the persistent cookie.
 * If valid, sets $_SESSION['username'] and rotates the validator.
 */
function auth_login_from_cookie(PDO $pdo): void
{
    if (isset($_SESSION['username']) && $_SESSION['username'] !== '') {
        return; // already logged in
    }
    $cookie = (string)($_COOKIE[auth_cookie_name()] ?? '');
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return;
    }
    [$selector, $validator] = explode(':', $cookie, 2);
    if ($selector === '' || $validator === '') {
        return;
    }
    auth_ensure_tokens_table($pdo);
    try {
        $stmt = $pdo->prepare('SELECT username, validator_hash, user_agent_hash, expires_at FROM auth_tokens WHERE selector = ?');
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return; // unknown selector
        // Expired?
        $expiresAt = (string)($row['expires_at'] ?? '');
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            // cleanup expired token
            $del = $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?');
            $del->execute([$selector]);
            return;
        }
        $calc = hash('sha256', $validator);
        if (!hash_equals((string)$row['validator_hash'], $calc)) {
            // possible theft; delete
            $del = $pdo->prepare('DELETE FROM auth_tokens WHERE selector = ?');
            $del->execute([$selector]);
            return;
        }
        // Optional: bind to user-agent
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uaHash = $ua !== '' ? hash('sha256', $ua) : null;
        if ($row['user_agent_hash'] !== null && $uaHash !== null && !hash_equals((string)$row['user_agent_hash'], (string)$uaHash)) {
            return; // mismatch; ignore
        }
        // Success: log in user and rotate validator
        $_SESSION['username'] = (string)$row['username'];

        $newValidator = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newValidator);
        $newExpires = (new DateTimeImmutable('+365 days'))->format('Y-m-d H:i:s');
        $upd = $pdo->prepare('UPDATE auth_tokens SET validator_hash = :vh, expires_at = :exp, last_used_at = NOW() WHERE selector = :sel');
        $upd->execute([':vh' => $newHash, ':exp' => $newExpires, ':sel' => $selector]);
        $cookieValue = $selector . ':' . $newValidator;
        $params = [
            'expires' => time() + 365*24*60*60,
            'path' => '/',
            'domain' => '',
            'secure' => auth_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie(auth_cookie_name(), $cookieValue, $params);
    } catch (Throwable $e) {
        // ignore
    }
}
