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

function budget_canonical_user(string $identifier): string
{
    return strtolower(trim($identifier));
}

function budget_default_owner(): string
{
    return 'jr@lillard.org';
}

function budget_ensure_owner_column(PDO $pdo, string $table, string $column = 'owner', ?string $assignOwnerForNull = null): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return;
    }
    $tableName = "`{$table}`";
    $columnName = "`{$column}`";
    try {
        $pdo->exec("ALTER TABLE {$tableName} ADD COLUMN {$columnName} VARCHAR(190) NULL");
    } catch (Throwable $e) {
        // ignore if column already exists
    }
    if ($assignOwnerForNull !== null && $assignOwnerForNull !== '') {
        try {
            $upd = $pdo->prepare("UPDATE {$tableName} SET {$columnName} = :owner WHERE {$columnName} IS NULL OR {$columnName} = ''");
            $upd->execute([':owner' => $assignOwnerForNull]);
        } catch (Throwable $e) {
            // best effort only
        }
    }
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

/**
 * Send an email using SMTP2GO's HTTP API.
 * Reads credentials from config.php under ['mail'].
 * Returns [bool ok, ?string error].
 */
function send_mail_via_smtp2go(string|array $to, string $subject, string $html, ?string $text = null, ?string $from = null): array
{
    $config = require __DIR__ . '/config.php';
    $mail = $config['mail'] ?? [];
    $provider = $mail['provider'] ?? 'smtp2go';
    if ($provider !== 'smtp2go') {
        return [false, 'Mail provider not configured: smtp2go'];
    }
    $apiKey = (string)($mail['api_key'] ?? '');
    $fromAddr = (string)($from ?? ($mail['from'] ?? ''));
    if ($apiKey === '' || $fromAddr === '') {
        return [false, 'SMTP2GO api_key/from not configured'];
    }

    $toList = is_array($to) ? $to : [$to];
    // Ensure non-empty body
    $textBody = $text ?? trim((string)preg_replace('/\s+/', ' ', strip_tags($html)));

    $payload = [
        'api_key' => $apiKey,
        'to' => $toList,
        'sender' => $fromAddr,
        'subject' => $subject,
        'text_body' => $textBody,
        'html_body' => $html,
    ];

    $ch = curl_init('https://api.smtp2go.com/v3/email/send');
    if ($ch === false) {
        return [false, 'curl_init failed'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return [false, 'curl_exec failed: ' . $err];
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return [false, 'Invalid SMTP2GO response (HTTP ' . (string)$code . ')'];
    }
    // SMTP2GO success status example: {"request_id":"...","data":{"succeeded":1,"failed":0}}
    $status = $data['data']['succeeded'] ?? null;
    if ((int)$status >= 1) {
        return [true, null];
    }
    $errMsg = $data['data']['failures'][0]['error'] ?? ($data['error'] ?? 'Unknown SMTP2GO error');
    return [false, (string)$errMsg];
}
