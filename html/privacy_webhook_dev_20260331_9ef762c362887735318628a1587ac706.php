<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/privacy.php';

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

$scriptPath = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? basename(__FILE__));
$scriptPath = '/' . basename($scriptPath);

$receiverConfigs = [
    'budget.lillard.dev' => [
        'environment' => 'dev',
        'receiver' => 'privacy webhook dev',
    ],
    'budget.lillard.app' => [
        'environment' => 'prod',
        'receiver' => 'privacy webhook prod',
    ],
];

$importOwner = 'jr@lillard.org';
$importAccountId = 1; // Meritrust Credit Union Personal Checking

$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$host = preg_replace('/:\d+$/', '', $host);
$receiverConfig = $receiverConfigs[$host] ?? null;
if (!is_array($receiverConfig)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$environment = privacy_normalize_environment((string)($receiverConfig['environment'] ?? 'dev'));
$receiverName = (string)($receiverConfig['receiver'] ?? ('privacy webhook ' . $environment));
$postUrl = 'https://' . $host . $scriptPath;

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
    privacy_ensure_sync_table($pdo);
} catch (Throwable $e) {
    $respond(['ok' => false, 'error' => 'Unable to connect to MySQL webhook store', 'detail' => $e->getMessage()], 500);
}

if ($method === 'GET' || $method === 'HEAD') {
    $recordId = (int)($_GET['id'] ?? 0);
    if ($recordId > 0) {
        $row = privacy_fetch_webhook($pdo, $recordId, $environment);
        if ($row === null) {
            $respond(['ok' => false, 'error' => 'Webhook record not found'], 404);
        }
        $respond([
            'ok' => true,
            'host' => $host,
            'receiver' => $receiverName,
            'record' => privacy_render_webhook_row($row, $host, $scriptPath),
        ]);
    }

    $listStmt = $pdo->prepare(
        'SELECT id, received_at, processed_at, processing_status, processing_attempts, content_type, body_bytes,
                transaction_token, event_type, merchant_descriptor, amount_minor, result,
                import_action, transaction_id
         FROM privacy_webhooks
         WHERE environment = :environment
         ORDER BY id DESC
         LIMIT 50'
    );
    $listStmt->execute([':environment' => $environment]);
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
            'record_url' => $postUrl . '?id=' . $webhookId,
        ];
    }

    $respond([
        'ok' => true,
        'host' => $host,
        'receiver' => $receiverName,
        'post_url' => $postUrl,
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
            "privacy", :environment, :received_at, :host, :method, :request_uri, :remote_addr, :content_type, :body_bytes,
            :transaction_token, :event_type, :result, :merchant_descriptor, :amount_minor,
            :headers_json, :body_json, :body_text, :body_base64
        )'
    );
    $insertStmt->execute([
        ':environment' => $environment,
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

$recordUrl = $postUrl . '?id=' . $webhookId;
$ackPayload = [
    'ok' => true,
    'message' => 'Webhook recorded on ' . $environment,
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

    $importSummary = [
        'attempted' => false,
        'ok' => null,
        'action' => 'ignored',
        'reason' => null,
        'transaction_id' => null,
    ];

    try {
        $importSummary = privacy_process_transaction_import($pdo, is_array($decodedJson) ? $decodedJson : null, $importOwner, $importAccountId);
        privacy_record_sync_result(
            $pdo,
            is_array($decodedJson) ? $decodedJson : null,
            $importOwner,
            $importAccountId,
            $importSummary,
            $webhookId,
            $environment
        );
    } catch (Throwable $importError) {
        $importSummary = [
            'attempted' => true,
            'ok' => false,
            'action' => 'error',
            'reason' => $importError->getMessage(),
            'transaction_id' => null,
            'transaction_token' => $meta['transaction_token'],
            'privacy_status' => $meta['status'],
        ];
        privacy_upsert_sync_record(
            $pdo,
            is_array($decodedJson) ? $decodedJson : null,
            $importOwner,
            $importAccountId,
            null,
            $webhookId,
            'active',
            $importError->getMessage(),
            $environment
        );
    }

    $processingNotes = [];
    if ($importSummary['ok'] === false && !empty($importSummary['reason'])) {
        $processingNotes[] = 'Import: ' . (string)$importSummary['reason'];
    }
    $processingStatus = ($importSummary['ok'] === false)
        ? 'error'
        : (($processingNotes !== []) ? 'warning' : 'processed');
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
        privacy_upsert_sync_record(
            $pdo,
            is_array($decodedJson) ? $decodedJson : null,
            $importOwner,
            $importAccountId,
            null,
            $webhookId,
            'active',
            $e->getMessage(),
            $environment
        );
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
