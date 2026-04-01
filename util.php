<?php
declare(strict_types=1);

/**
 * Load configuration and provide a PDO connection to MySQL.
 */
function budget_get_config(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $loaded = require __DIR__ . '/config.php';
    if (!is_array($loaded)) {
        throw new RuntimeException('Application configuration missing.');
    }

    $config = $loaded;
    return $config;
}

function get_mysql_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $config = budget_get_config();
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

/**
 * Look up a user's phone number by username/email (canonical) with local-part fallback.
 */
function budget_lookup_phone(PDO $pdo, string $username): string
{
    $canon = budget_canonical_user($username);
    $local = $canon;
    if (str_contains($canon, '@')) {
        [$local] = explode('@', $canon, 2);
        $local = budget_canonical_user($local);
    }
    $stmt = $pdo->prepare('SELECT phone FROM users WHERE username IN (?, ?) ORDER BY (username = ?) DESC LIMIT 1');
    $stmt->execute([$canon, $local, $canon]);
    return (string)($stmt->fetchColumn() ?? '');
}

/**
 * Normalize a phone number to E.164-ish for SMS.
 */
function budget_normalize_phone_for_sms(string $num): string
{
    $n = preg_replace('/[^0-9+]/', '', $num);
    if ($n === '') {
        return '';
    }
    if ($n[0] === '+') {
        return preg_match('/^\\+[0-9]{6,15}$/', $n) ? $n : '';
    }
    if (preg_match('/^[0-9]{10}$/', $n)) { // assume US
        return '+1' . $n;
    }
    if (preg_match('/^1[0-9]{10}$/', $n)) {
        return '+' . $n;
    }
    if (preg_match('/^[0-9]{6,15}$/', $n)) {
        return '+' . $n;
    }
    return '';
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

function budget_ensure_description_rules_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS description_rules (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                owner VARCHAR(190) NOT NULL,
                source VARCHAR(32) NOT NULL DEFAULT "privacy",
                match_type VARCHAR(16) NOT NULL DEFAULT "exact",
                match_value VARCHAR(255) NOT NULL,
                replacement_value VARCHAR(255) NOT NULL,
                learned_from_transaction_id INT UNSIGNED DEFAULT NULL,
                learned_from_fm_pk VARCHAR(64) DEFAULT NULL,
                times_applied INT UNSIGNED NOT NULL DEFAULT 0,
                last_applied_at DATETIME DEFAULT NULL,
                created_at_source DATETIME DEFAULT NULL,
                updated_at_source DATETIME DEFAULT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_owner_source_match (owner, source, match_type, match_value),
                KEY idx_owner_source_replacement (owner, source, replacement_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // best-effort only
    }
}

function budget_lookup_privacy_raw_descriptor(PDO $pdo, string $transactionToken): ?string
{
    $transactionToken = trim($transactionToken);
    if ($transactionToken === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT merchant_descriptor
             FROM privacy_webhooks
             WHERE transaction_token = :transaction_token
               AND merchant_descriptor IS NOT NULL
               AND merchant_descriptor <> ""
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':transaction_token' => $transactionToken]);
        $value = trim((string)($stmt->fetchColumn() ?? ''));
        return $value !== '' ? $value : null;
    } catch (Throwable $e) {
        return null;
    }
}

function budget_apply_privacy_description_rule(PDO $pdo, string $owner, string $rawDescription): string
{
    $owner = budget_canonical_user($owner);
    $rawDescription = trim($rawDescription);
    if ($owner === '' || $rawDescription === '') {
        return $rawDescription;
    }

    budget_ensure_description_rules_table($pdo);

    try {
        $stmt = $pdo->prepare(
            'SELECT id, replacement_value
             FROM description_rules
             WHERE owner = :owner
               AND source = "privacy"
               AND match_type = "exact"
               AND match_value = :match_value
             LIMIT 1'
        );
        $stmt->execute([
            ':owner' => $owner,
            ':match_value' => $rawDescription,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return $rawDescription;
        }

        $replacement = trim((string)($row['replacement_value'] ?? ''));
        $ruleId = (int)($row['id'] ?? 0);
        if ($ruleId > 0) {
            try {
                $touch = $pdo->prepare(
                    'UPDATE description_rules
                     SET times_applied = times_applied + 1,
                         last_applied_at = NOW(),
                         updated_at_source = NOW()
                     WHERE id = :id'
                );
                $touch->execute([':id' => $ruleId]);
            } catch (Throwable $e) {
                // best-effort only
            }
        }

        return $replacement !== '' ? $replacement : $rawDescription;
    } catch (Throwable $e) {
        return $rawDescription;
    }
}

function budget_learn_privacy_description_rule_from_transaction(PDO $pdo, string $owner, int $transactionId, string $newDescription): void
{
    $owner = budget_canonical_user($owner);
    $newDescription = trim($newDescription);
    if ($owner === '' || $transactionId <= 0 || $newDescription === '') {
        return;
    }

    $txStmt = $pdo->prepare('SELECT fm_pk FROM transactions WHERE id = :id AND owner = :owner LIMIT 1');
    $txStmt->execute([
        ':id' => $transactionId,
        ':owner' => $owner,
    ]);
    $tx = $txStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $transactionToken = trim((string)($tx['fm_pk'] ?? ''));
    if ($transactionToken === '') {
        return;
    }

    $rawDescription = budget_lookup_privacy_raw_descriptor($pdo, $transactionToken);
    $rawDescription = trim((string)$rawDescription);
    if ($rawDescription === '') {
        return;
    }

    budget_ensure_description_rules_table($pdo);

    if ($rawDescription === $newDescription) {
        $deleteStmt = $pdo->prepare(
            'DELETE FROM description_rules
             WHERE owner = :owner
               AND source = "privacy"
               AND match_type = "exact"
               AND match_value = :match_value'
        );
        $deleteStmt->execute([
            ':owner' => $owner,
            ':match_value' => $rawDescription,
        ]);
        return;
    }

    $upsertStmt = $pdo->prepare(
        'INSERT INTO description_rules (
            owner, source, match_type, match_value, replacement_value,
            learned_from_transaction_id, learned_from_fm_pk,
            created_at_source, updated_at_source
        ) VALUES (
            :owner, "privacy", "exact", :match_value, :replacement_value,
            :learned_from_transaction_id, :learned_from_fm_pk,
            NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            replacement_value = VALUES(replacement_value),
            learned_from_transaction_id = VALUES(learned_from_transaction_id),
            learned_from_fm_pk = VALUES(learned_from_fm_pk),
            updated_at_source = NOW()'
    );
    $upsertStmt->execute([
        ':owner' => $owner,
        ':match_value' => $rawDescription,
        ':replacement_value' => $newDescription,
        ':learned_from_transaction_id' => $transactionId,
        ':learned_from_fm_pk' => $transactionToken,
    ]);
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

/**
 * Send an SMS using SMTP2GO's SMS API.
 * Returns [bool ok, ?string error].
 */
function send_sms_via_smtp2go(string|array $to, string $content, ?string $sender = null): array
{
    $config = require __DIR__ . '/config.php';
    $mail = $config['mail'] ?? [];
    $apiKey = (string)($mail['sms_api_key'] ?? $mail['api_key'] ?? '');
    $sender = $sender ?? (string)($mail['sms_sender'] ?? '');
    if ($apiKey === '') {
        return [false, 'SMTP2GO api_key not configured'];
    }

    $destinations = is_array($to) ? $to : [$to];
    $destinations = array_values(array_filter(array_map(static function ($num) {
        return budget_normalize_phone_for_sms((string)$num);
    }, $destinations)));
    if (empty($destinations)) {
        return [false, 'No valid destination numbers'];
    }

    $payload = [
        'api_key' => $apiKey,
        'destination' => $destinations,
        'content' => $content,
    ];
    if ($sender !== null && $sender !== '') {
        $payload['sender'] = $sender;
    }

    $ch = curl_init('https://api.smtp2go.com/v3/sms/send');
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
    // Success heuristic: HTTP 2xx and either data/request_id present or no error key
    if ($code >= 200 && $code < 300) {
        if (is_array($data)) {
            if (!empty($data['error'])) {
                return [false, (string)$data['error']];
            }
            if (isset($data['data']) || isset($data['request_id']) || isset($data['results'])) {
                return [true, null];
            }
        }
        // If parse failed but HTTP was OK, treat as success but note uncertainty
        if ($data === null) {
            return [true, null];
        }
    }
    return [false, 'HTTP ' . (string)$code . ' response: ' . substr($resp, 0, 200)];
}
