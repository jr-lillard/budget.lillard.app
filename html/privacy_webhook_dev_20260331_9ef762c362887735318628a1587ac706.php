<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/util.php';

function privacy_json_encode(mixed $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode JSON payload.');
    }
    return $json;
}

function privacy_db_datetime_to_iso(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }
    return $dt->format('Y-m-d\TH:i:s\Z');
}

function privacy_amount_string(?int $amountMinor): ?string
{
    if ($amountMinor === null) {
        return null;
    }
    return number_format($amountMinor / 100, 2, '.', '');
}

function privacy_finish_response(string $json): void
{
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    @ob_flush();
    flush();
}

function privacy_extract_latest_event(?array $decodedJson): ?array
{
    if (!is_array($decodedJson) || !isset($decodedJson['events']) || !is_array($decodedJson['events'])) {
        return null;
    }
    $eventValues = array_values(array_filter($decodedJson['events'], 'is_array'));
    if ($eventValues === []) {
        return null;
    }
    return $eventValues[count($eventValues) - 1];
}

function privacy_extract_meta(?array $decodedJson): array
{
    $latestEvent = privacy_extract_latest_event($decodedJson);
    $eventType = is_array($latestEvent) ? strtoupper(trim((string)($latestEvent['type'] ?? ''))) : '';
    $eventToken = is_array($decodedJson) ? trim((string)($decodedJson['token'] ?? '')) : '';
    $merchantDescriptor = is_array($decodedJson)
        ? trim((string)($decodedJson['merchant']['descriptor'] ?? ''))
        : '';
    $amountMinor = (is_array($decodedJson) && isset($decodedJson['amount']) && is_numeric($decodedJson['amount']))
        ? (int)round((float)$decodedJson['amount'])
        : null;
    $result = is_array($decodedJson) ? strtoupper(trim((string)($decodedJson['result'] ?? ''))) : '';

    return [
        'latest_event' => $latestEvent,
        'event_type' => $eventType !== '' ? $eventType : null,
        'transaction_token' => $eventToken !== '' ? $eventToken : null,
        'merchant_descriptor' => $merchantDescriptor !== '' ? $merchantDescriptor : null,
        'amount_minor' => $amountMinor,
        'result' => $result !== '' ? $result : null,
    ];
}

function privacy_ensure_webhooks_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS privacy_webhooks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL DEFAULT "privacy",
            environment VARCHAR(32) NOT NULL DEFAULT "dev",
            received_at DATETIME NOT NULL,
            process_started_at DATETIME NULL,
            processed_at DATETIME NULL,
            host VARCHAR(255) NOT NULL,
            method VARCHAR(16) NOT NULL,
            request_uri VARCHAR(255) NOT NULL,
            remote_addr VARCHAR(64) DEFAULT NULL,
            content_type VARCHAR(255) DEFAULT NULL,
            body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            transaction_token VARCHAR(64) DEFAULT NULL,
            event_type VARCHAR(64) DEFAULT NULL,
            result VARCHAR(64) DEFAULT NULL,
            merchant_descriptor VARCHAR(255) DEFAULT NULL,
            amount_minor INT DEFAULT NULL,
            processing_status VARCHAR(32) NOT NULL DEFAULT "received",
            processing_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            processing_error TEXT DEFAULT NULL,
            import_action VARCHAR(64) DEFAULT NULL,
            transaction_id INT UNSIGNED DEFAULT NULL,
            import_summary_json LONGTEXT DEFAULT NULL,
            email_ok TINYINT(1) DEFAULT NULL,
            email_error TEXT DEFAULT NULL,
            email_sent_at DATETIME DEFAULT NULL,
            headers_json LONGTEXT DEFAULT NULL,
            body_json LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            body_base64 LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_received_at (received_at),
            KEY idx_processing_status (processing_status),
            KEY idx_transaction_token (transaction_token),
            KEY idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function privacy_fetch_webhook(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM privacy_webhooks WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function privacy_render_webhook_row(array $row, string $allowedHost, string $scriptPath): array
{
    $headers = json_decode((string)($row['headers_json'] ?? ''), true);
    $bodyJson = json_decode((string)($row['body_json'] ?? ''), true);
    $importSummary = json_decode((string)($row['import_summary_json'] ?? ''), true);
    if (is_array($importSummary) && array_key_exists('email_notification', $importSummary)) {
        unset($importSummary['email_notification']);
    }
    $id = (int)($row['id'] ?? 0);

    return [
        'id' => $id,
        'provider' => $row['provider'] ?? 'privacy',
        'environment' => $row['environment'] ?? 'dev',
        'received_at' => privacy_db_datetime_to_iso($row['received_at'] ?? null),
        'process_started_at' => privacy_db_datetime_to_iso($row['process_started_at'] ?? null),
        'processed_at' => privacy_db_datetime_to_iso($row['processed_at'] ?? null),
        'processing_status' => $row['processing_status'] ?? null,
        'processing_attempts' => isset($row['processing_attempts']) ? (int)$row['processing_attempts'] : null,
        'processing_error' => $row['processing_error'] ?? null,
        'host' => $row['host'] ?? null,
        'method' => $row['method'] ?? null,
        'request_uri' => $row['request_uri'] ?? null,
        'remote_addr' => $row['remote_addr'] ?? null,
        'content_type' => $row['content_type'] ?? null,
        'body_bytes' => isset($row['body_bytes']) ? (int)$row['body_bytes'] : null,
        'event_type' => $row['event_type'] ?? null,
        'event_token' => $row['transaction_token'] ?? null,
        'merchant' => $row['merchant_descriptor'] ?? null,
        'amount' => isset($row['amount_minor']) ? privacy_amount_string((int)$row['amount_minor']) : null,
        'result' => $row['result'] ?? null,
        'import_action' => $row['import_action'] ?? null,
        'transaction_id' => isset($row['transaction_id']) ? (int)$row['transaction_id'] : null,
        'headers' => is_array($headers) ? $headers : null,
        'body_json' => is_array($bodyJson) ? $bodyJson : null,
        'body_text' => $row['body_text'] ?? null,
        'body_base64' => $row['body_base64'] ?? null,
        'import_summary' => is_array($importSummary) ? $importSummary : null,
        'record_url' => 'https://' . $allowedHost . $scriptPath . '?id=' . $id,
    ];
}

function privacy_process_transaction_import(PDO $pdo, ?array $decodedJson, string $importOwner, int $importAccountId): array
{
    $meta = privacy_extract_meta($decodedJson);
    $eventType = (string)($meta['event_type'] ?? '');
    $eventToken = (string)($meta['transaction_token'] ?? '');

    $importSummary = [
        'attempted' => false,
        'ok' => null,
        'action' => 'ignored',
        'reason' => null,
        'event_type' => $eventType !== '' ? $eventType : null,
        'transaction_token' => $eventToken !== '' ? $eventToken : null,
        'transaction_id' => null,
    ];

    if (!is_array($decodedJson) || $eventToken === '') {
        $importSummary['ok'] = true;
        $importSummary['reason'] = 'Missing transaction payload or token';
        return $importSummary;
    }

    $importSummary['attempted'] = true;

    try {
        try { $pdo->exec('ALTER TABLE transactions ADD COLUMN status TINYINT NULL'); } catch (Throwable $e) { /* ignore */ }
        budget_ensure_owner_column($pdo, 'transactions', 'owner', budget_default_owner());
        try { $pdo->exec("ALTER TABLE transactions MODIFY fm_pk VARCHAR(64) NULL"); } catch (Throwable $e) { /* ignore */ }

        $owner = budget_canonical_user($importOwner);
        $existingStmt = $pdo->prepare('SELECT id, status FROM transactions WHERE fm_pk = :fm_pk LIMIT 1');
        $existingStmt->execute([':fm_pk' => $eventToken]);
        $existingTx = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingId = (int)($existingTx['id'] ?? 0);
        $existingStatus = (int)($existingTx['status'] ?? 0);

        if ($eventType === 'VOID') {
            if ($existingId > 0 && $existingStatus !== 2) {
                $deleteStmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id LIMIT 1');
                $deleteStmt->execute([':id' => $existingId]);
                $importSummary['ok'] = true;
                $importSummary['action'] = 'deleted';
                $importSummary['transaction_id'] = $existingId;
            } elseif ($existingId > 0) {
                $importSummary['ok'] = true;
                $importSummary['action'] = 'preserved_posted';
                $importSummary['reason'] = 'Existing row already posted; leaving it unchanged';
                $importSummary['transaction_id'] = $existingId;
            } else {
                $importSummary['ok'] = true;
                $importSummary['reason'] = 'VOID received for unknown transaction token';
            }
            return $importSummary;
        }

        if (strtoupper(trim((string)($decodedJson['result'] ?? ''))) !== 'APPROVED') {
            $importSummary['ok'] = true;
            $importSummary['reason'] = 'Only APPROVED transactions are imported';
            return $importSummary;
        }

        if (!in_array($eventType, ['AUTHORIZATION', 'AUTH_ADVICE', 'CLEARING', 'RETURN'], true)) {
            $importSummary['ok'] = true;
            $importSummary['reason'] = 'Unsupported event type';
            return $importSummary;
        }

        $createdAt = (string)($decodedJson['created'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt) === 1 ? substr($createdAt, 0, 10) : gmdate('Y-m-d');
        $descriptor = trim((string)($decodedJson['merchant']['descriptor'] ?? ''));
        $description = $descriptor !== ''
            ? budget_apply_privacy_description_rule($pdo, $owner, $descriptor)
            : 'Privacy Card Transaction';
        $amountMinor = abs((int)round((float)($decodedJson['amount'] ?? 0)));
        $sign = ($eventType === 'RETURN') ? 1 : -1;
        $amount = number_format(($sign * $amountMinor) / 100, 2, '.', '');

        if ($existingId > 0) {
            if ($existingStatus === 2) {
                $importSummary['ok'] = true;
                $importSummary['action'] = 'preserved_posted';
                $importSummary['reason'] = 'Existing row already posted; leaving it unchanged';
                $importSummary['transaction_id'] = $existingId;
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE transactions
                     SET account_id = :account_id,
                         `date` = :date,
                         amount = :amount,
                         description = :description,
                         updated_at_source = NOW()
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':account_id' => $importAccountId,
                    ':date' => $date,
                    ':amount' => $amount,
                    ':description' => $description,
                    ':id' => $existingId,
                ]);
                $importSummary['ok'] = true;
                $importSummary['action'] = 'updated';
                $importSummary['transaction_id'] = $existingId;
            }
            return $importSummary;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO transactions (fm_pk, account_id, `date`, amount, description, check_no, posted, status, owner, created_at_source, updated_at_source)
             VALUES (:fm_pk, :account_id, :date, :amount, :description, NULL, 0, 0, :owner, NOW(), NOW())'
        );
        $insertStmt->execute([
            ':fm_pk' => $eventToken,
            ':account_id' => $importAccountId,
            ':date' => $date,
            ':amount' => $amount,
            ':description' => $description,
            ':owner' => $owner,
        ]);
        $importSummary['ok'] = true;
        $importSummary['action'] = 'inserted';
        $importSummary['transaction_id'] = (int)$pdo->lastInsertId();
        return $importSummary;
    } catch (Throwable $e) {
        $importSummary['ok'] = false;
        $importSummary['action'] = 'error';
        $importSummary['reason'] = $e->getMessage();
        return $importSummary;
    }
}

// Dev-only webhook receiver for testing Privacy deliveries.
$allowedHost = 'budget.lillard.dev';
$importOwner = 'jr@lillard.org';
$importAccountId = 1; // Meritrust Credit Union Personal Checking

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($host !== $allowedHost) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, POST, HEAD, OPTIONS');
    exit;
}
if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, HEAD, OPTIONS');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? basename(__FILE__));
$scriptPath = '/' . basename($scriptPath);

$headers = [];
if (function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $name => $value) {
        $headers[(string)$name] = is_array($value) ? implode(', ', $value) : (string)$value;
    }
} else {
    foreach ($_SERVER as $key => $value) {
        if (strncmp($key, 'HTTP_', 5) === 0) {
            $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$headerName] = (string)$value;
        }
    }
}
ksort($headers);

$respond = static function (array $payload, int $status = 200) use ($method): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    if ($method !== 'HEAD') {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    exit;
};

try {
    $pdo = get_mysql_connection();
    privacy_ensure_webhooks_table($pdo);
} catch (Throwable $e) {
    $respond(['ok' => false, 'error' => 'Unable to connect to MySQL webhook store', 'detail' => $e->getMessage()], 500);
}

if ($method === 'GET' || $method === 'HEAD') {
    $recordId = (int)($_GET['id'] ?? 0);
    if ($recordId > 0) {
        $row = privacy_fetch_webhook($pdo, $recordId);
        if ($row === null) {
            $respond(['ok' => false, 'error' => 'Webhook record not found'], 404);
        }
        $respond([
            'ok' => true,
            'host' => $allowedHost,
            'receiver' => 'privacy webhook dev',
            'record' => privacy_render_webhook_row($row, $allowedHost, $scriptPath),
        ]);
    }

    $listStmt = $pdo->query(
        'SELECT id, received_at, processed_at, processing_status, processing_attempts, content_type, body_bytes,
                transaction_token, event_type, merchant_descriptor, amount_minor, result,
                import_action, transaction_id
         FROM privacy_webhooks
         ORDER BY id DESC
         LIMIT 50'
    );
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $entries = [];
    foreach ($rows as $row) {
        $webhookId = (int)($row['id'] ?? 0);
        $entries[] = [
            'id' => $webhookId,
            'received_at' => privacy_db_datetime_to_iso($row['received_at'] ?? null),
            'processed_at' => privacy_db_datetime_to_iso($row['processed_at'] ?? null),
            'processing_status' => $row['processing_status'] ?? null,
            'processing_attempts' => isset($row['processing_attempts']) ? (int)$row['processing_attempts'] : null,
            'content_type' => $row['content_type'] ?? null,
            'body_bytes' => isset($row['body_bytes']) ? (int)$row['body_bytes'] : null,
            'event_type' => $row['event_type'] ?? null,
            'event_token' => $row['transaction_token'] ?? null,
            'merchant' => $row['merchant_descriptor'] ?? null,
            'amount' => isset($row['amount_minor']) ? privacy_amount_string((int)$row['amount_minor']) : null,
            'result' => $row['result'] ?? null,
            'import_action' => $row['import_action'] ?? null,
            'transaction_id' => isset($row['transaction_id']) ? (int)$row['transaction_id'] : null,
            'record_url' => 'https://' . $allowedHost . $scriptPath . '?id=' . $webhookId,
        ];
    }

    $respond([
        'ok' => true,
        'host' => $allowedHost,
        'receiver' => 'privacy webhook dev',
        'post_url' => 'https://' . $allowedHost . $scriptPath,
        'entries' => $entries,
    ]);
}

$body = file_get_contents('php://input');
if ($body === false) {
    $body = '';
}
$contentType = (string)($headers['Content-Type'] ?? $headers['content-type'] ?? '');
$decodedJson = null;
if ($body !== '' && stripos($contentType, 'application/json') !== false) {
    $decodedJson = json_decode($body, true);
}
$bodyText = preg_match('//u', $body) === 1 ? $body : null;

$receivedAtDb = gmdate('Y-m-d H:i:s');
$receivedAtIso = gmdate('Y-m-d\TH:i:s\Z');
$meta = privacy_extract_meta(is_array($decodedJson) ? $decodedJson : null);

try {
    $insertStmt = $pdo->prepare(
        'INSERT INTO privacy_webhooks (
            provider, environment, received_at, host, method, request_uri, remote_addr, content_type, body_bytes,
            transaction_token, event_type, result, merchant_descriptor, amount_minor,
            headers_json, body_json, body_text, body_base64
        ) VALUES (
            "privacy", "dev", :received_at, :host, :method, :request_uri, :remote_addr, :content_type, :body_bytes,
            :transaction_token, :event_type, :result, :merchant_descriptor, :amount_minor,
            :headers_json, :body_json, :body_text, :body_base64
        )'
    );
    $insertStmt->execute([
        ':received_at' => $receivedAtDb,
        ':host' => $host,
        ':method' => $method,
        ':request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
        ':remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        ':content_type' => $contentType !== '' ? $contentType : null,
        ':body_bytes' => strlen($body),
        ':transaction_token' => $meta['transaction_token'],
        ':event_type' => $meta['event_type'],
        ':result' => $meta['result'],
        ':merchant_descriptor' => $meta['merchant_descriptor'],
        ':amount_minor' => $meta['amount_minor'],
        ':headers_json' => privacy_json_encode($headers),
        ':body_json' => is_array($decodedJson) ? privacy_json_encode($decodedJson) : null,
        ':body_text' => $bodyText,
        ':body_base64' => base64_encode($body),
    ]);
    $webhookId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
    $respond(['ok' => false, 'error' => 'Unable to persist webhook payload to MySQL', 'detail' => $e->getMessage()], 500);
}

$recordUrl = 'https://' . $allowedHost . $scriptPath . '?id=' . $webhookId;
$ackPayload = [
    'ok' => true,
    'message' => 'Webhook recorded on dev',
    'webhook_id' => $webhookId,
    'received_at' => $receivedAtIso,
    'body_bytes' => strlen($body),
    'content_type' => $contentType,
    'processing_status' => 'received',
    'record_url' => $recordUrl,
];
$ackJson = privacy_json_encode($ackPayload);
if ($method !== 'HEAD') {
    privacy_finish_response($ackJson);
}

try {
    $markStmt = $pdo->prepare(
        'UPDATE privacy_webhooks
         SET processing_status = "processing",
             processing_attempts = processing_attempts + 1,
             process_started_at = NOW()
         WHERE id = :id'
    );
    $markStmt->execute([':id' => $webhookId]);

    $importSummary = privacy_process_transaction_import($pdo, is_array($decodedJson) ? $decodedJson : null, $importOwner, $importAccountId);

    $processingNotes = [];
    if ($importSummary['ok'] === false && !empty($importSummary['reason'])) {
        $processingNotes[] = 'Import: ' . (string)$importSummary['reason'];
    }
    $processingStatus = ($importSummary['ok'] === false) ? 'error' : 'processed';
    $processingError = $processingNotes !== [] ? implode(' | ', $processingNotes) : null;
    $summaryJson = privacy_json_encode([
        'import_transaction' => $importSummary,
    ]);

    $finalizeStmt = $pdo->prepare(
        'UPDATE privacy_webhooks
         SET processed_at = NOW(),
             processing_status = :processing_status,
             processing_error = :processing_error,
             import_action = :import_action,
             transaction_id = :transaction_id,
             import_summary_json = :import_summary_json,
             email_ok = :email_ok,
             email_error = :email_error,
             email_sent_at = :email_sent_at
         WHERE id = :id'
    );
    $finalizeStmt->execute([
        ':processing_status' => $processingStatus,
        ':processing_error' => $processingError,
        ':import_action' => $importSummary['action'],
        ':transaction_id' => $importSummary['transaction_id'],
        ':import_summary_json' => $summaryJson,
        ':email_ok' => null,
        ':email_error' => null,
        ':email_sent_at' => null,
        ':id' => $webhookId,
    ]);
} catch (Throwable $e) {
    try {
        $failStmt = $pdo->prepare(
            'UPDATE privacy_webhooks
             SET processed_at = NOW(),
                 processing_status = "error",
                 processing_error = :processing_error
             WHERE id = :id'
        );
        $failStmt->execute([
            ':processing_error' => $e->getMessage(),
            ':id' => $webhookId,
        ]);
    } catch (Throwable $inner) {
        // Best effort only after the response has already been sent.
    }
}
