<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/util.php';

// Dev-only webhook receiver for testing Privacy deliveries.
$allowedHost = 'budget.lillard.dev';
$notificationEmail = 'jr@lillard.org';
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

$logDir = dirname(__DIR__) . '/sessions/privacy-webhooks';
if (!is_dir($logDir) && !mkdir($logDir, 0770, true) && !is_dir($logDir)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Unable to create log directory'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

if ($method === 'GET' || $method === 'HEAD') {
    $rawFile = basename((string)($_GET['raw'] ?? ''));
    if ($rawFile !== '') {
        $path = $logDir . '/' . $rawFile;
        if (!is_file($path)) {
            $respond(['ok' => false, 'error' => 'Log entry not found'], 404);
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            $respond(['ok' => false, 'error' => 'Unable to read log entry'], 500);
        }
        header('Content-Type: application/json; charset=UTF-8');
        if ($method !== 'HEAD') {
            echo $contents;
        }
        exit;
    }

    $files = glob($logDir . '/*.json') ?: [];
    rsort($files, SORT_STRING);
    $entries = [];
    foreach (array_slice($files, 0, 20) as $file) {
        $data = json_decode((string)file_get_contents($file), true);
        $bodyJson = is_array($data['body_json'] ?? null) ? $data['body_json'] : [];
        $merchantDescriptor = trim((string)($bodyJson['merchant']['descriptor'] ?? ''));
        $amountMinor = isset($bodyJson['amount']) && is_numeric($bodyJson['amount']) ? (int)$bodyJson['amount'] : null;
        $displayEventType = $data['import_transaction']['event_type']
            ?? $bodyJson['type']
            ?? null;
        $entries[] = [
            'file' => basename($file),
            'received_at' => $data['received_at'] ?? null,
            'method' => $data['method'] ?? null,
            'content_type' => $data['content_type'] ?? null,
            'body_bytes' => $data['body_bytes'] ?? null,
            'event_type' => $displayEventType,
            'event_token' => $bodyJson['token'] ?? null,
            'merchant' => $merchantDescriptor !== '' ? $merchantDescriptor : null,
            'amount' => $amountMinor !== null ? number_format($amountMinor / 100, 2, '.', '') : null,
            'result' => $bodyJson['result'] ?? null,
            'email_ok' => $data['email_notification']['ok'] ?? null,
            'import_action' => $data['import_transaction']['action'] ?? null,
            'transaction_id' => $data['import_transaction']['transaction_id'] ?? null,
            'raw_url' => 'https://' . $allowedHost . $scriptPath . '?raw=' . rawurlencode(basename($file)),
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
$decodedJson = null;
$contentType = (string)($headers['Content-Type'] ?? $headers['content-type'] ?? '');
if ($body !== '' && stripos($contentType, 'application/json') !== false) {
    $decodedJson = json_decode($body, true);
}

$now = gmdate('Y-m-d\TH:i:s\Z');
$fileBase = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
$logPath = $logDir . '/' . $fileBase;
$payload = [
    'received_at' => $now,
    'host' => $host,
    'method' => $method,
    'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
    'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'content_type' => $contentType,
    'body_bytes' => strlen($body),
    'headers' => $headers,
    'body_json' => $decodedJson,
    'body_text' => preg_match('//u', $body) === 1 ? $body : null,
    'body_base64' => base64_encode($body),
];

$encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encoded === false || file_put_contents($logPath, $encoded . PHP_EOL, LOCK_EX) === false) {
    $respond(['ok' => false, 'error' => 'Unable to persist webhook payload'], 500);
}

$rawUrl = 'https://' . $allowedHost . $scriptPath . '?raw=' . rawurlencode(basename($logPath));
$latestEvent = null;
if (is_array($decodedJson) && isset($decodedJson['events']) && is_array($decodedJson['events'])) {
    $eventValues = array_values(array_filter($decodedJson['events'], 'is_array'));
    if ($eventValues !== []) {
        $latestEvent = $eventValues[count($eventValues) - 1];
    }
}
$eventType = is_array($latestEvent) ? strtoupper(trim((string)($latestEvent['type'] ?? ''))) : '';
$eventToken = is_array($decodedJson) ? trim((string)($decodedJson['token'] ?? '')) : '';
$importSummary = [
    'attempted' => false,
    'ok' => null,
    'action' => 'ignored',
    'reason' => null,
    'event_type' => $eventType !== '' ? $eventType : null,
    'transaction_token' => $eventToken !== '' ? $eventToken : null,
    'transaction_id' => null,
];
if (is_array($decodedJson) && $eventToken !== '') {
    $importSummary['attempted'] = true;
    try {
        $pdo = get_mysql_connection();
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
                $importSummary['action'] = 'ignored';
                $importSummary['reason'] = 'VOID received for unknown transaction token';
            }
        } elseif (strtoupper(trim((string)($decodedJson['result'] ?? ''))) !== 'APPROVED') {
            $importSummary['ok'] = true;
            $importSummary['action'] = 'ignored';
            $importSummary['reason'] = 'Only APPROVED transactions are imported';
        } elseif (!in_array($eventType, ['AUTHORIZATION', 'AUTH_ADVICE', 'CLEARING', 'RETURN'], true)) {
            $importSummary['ok'] = true;
            $importSummary['action'] = 'ignored';
            $importSummary['reason'] = 'Unsupported event type';
        } else {
            $createdAt = (string)($decodedJson['created'] ?? '');
            $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt) === 1 ? substr($createdAt, 0, 10) : gmdate('Y-m-d');
            $descriptor = trim((string)($decodedJson['merchant']['descriptor'] ?? ''));
            $description = $descriptor !== '' ? $descriptor : 'Privacy Card Transaction';
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
            } else {
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
            }
        }
    } catch (Throwable $e) {
        $importSummary['ok'] = false;
        $importSummary['action'] = 'error';
        $importSummary['reason'] = $e->getMessage();
    }
}
$bodyPreview = $decodedJson !== null
    ? (string)json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    : ($payload['body_text'] ?? '[binary body; inspect saved raw log]');
if ($bodyPreview === '') {
    $bodyPreview = '[empty body]';
}
if (strlen($bodyPreview) > 4000) {
    $bodyPreview = substr($bodyPreview, 0, 4000) . "\n...[truncated]";
}
$subject = 'Privacy webhook received on budget.lillard.dev';
if ($eventType !== '') {
    $subject .= ' [' . $eventType . ']';
}
$html = '<p>A Privacy webhook was received on <strong>budget.lillard.dev</strong>.</p>'
    . '<ul>'
    . '<li><strong>Time:</strong> ' . htmlspecialchars($now, ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Method:</strong> ' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Content-Type:</strong> ' . htmlspecialchars($contentType, ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Bytes:</strong> ' . (int)strlen($body) . '</li>'
    . '<li><strong>Event Type:</strong> ' . htmlspecialchars($eventType !== '' ? $eventType : '(none)', ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Event Token:</strong> ' . htmlspecialchars($eventToken !== '' ? $eventToken : '(none)', ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Import Action:</strong> ' . htmlspecialchars((string)$importSummary['action'], ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Budget Transaction ID:</strong> ' . htmlspecialchars($importSummary['transaction_id'] !== null ? (string)$importSummary['transaction_id'] : '(none)', ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Import Note:</strong> ' . htmlspecialchars((string)($importSummary['reason'] ?? '(none)'), ENT_QUOTES, 'UTF-8') . '</li>'
    . '<li><strong>Saved Log:</strong> <a href="' . htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(basename($logPath), ENT_QUOTES, 'UTF-8') . '</a></li>'
    . '</ul>'
    . '<p><strong>Body preview</strong></p>'
    . '<pre style="white-space: pre-wrap; word-break: break-word;">' . htmlspecialchars($bodyPreview, ENT_QUOTES, 'UTF-8') . '</pre>';
$text = "A Privacy webhook was received on budget.lillard.dev.\n"
    . "Time: {$now}\n"
    . "Method: {$method}\n"
    . "Content-Type: {$contentType}\n"
    . "Bytes: " . strlen($body) . "\n"
    . 'Event Type: ' . ($eventType !== '' ? $eventType : '(none)') . "\n"
    . 'Event Token: ' . ($eventToken !== '' ? $eventToken : '(none)') . "\n"
    . 'Import Action: ' . (string)$importSummary['action'] . "\n"
    . 'Budget Transaction ID: ' . ($importSummary['transaction_id'] !== null ? (string)$importSummary['transaction_id'] : '(none)') . "\n"
    . 'Import Note: ' . (string)($importSummary['reason'] ?? '(none)') . "\n"
    . 'Saved Log: ' . $rawUrl . "\n\n"
    . "Body preview:\n{$bodyPreview}\n";
[$mailOk, $mailErr] = send_mail_via_smtp2go($notificationEmail, $subject, $html, $text);
$payload['import_transaction'] = $importSummary;
$payload['email_notification'] = [
    'to' => $notificationEmail,
    'ok' => $mailOk,
    'error' => $mailErr,
    'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
];
$encodedWithMail = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($encodedWithMail !== false) {
    file_put_contents($logPath, $encodedWithMail . PHP_EOL, LOCK_EX);
}

$respond([
    'ok' => true,
    'message' => 'Webhook received on dev',
    'saved' => basename($logPath),
    'received_at' => $now,
    'body_bytes' => strlen($body),
    'content_type' => $contentType,
    'import_action' => $importSummary['action'],
    'import_ok' => $importSummary['ok'],
    'transaction_id' => $importSummary['transaction_id'],
    'import_reason' => $importSummary['reason'],
    'email_ok' => $mailOk,
    'email_error' => $mailErr,
]);
