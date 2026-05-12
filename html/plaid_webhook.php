<?php
declare(strict_types=1);
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../plaid.php';

header('Content-Type: application/json');

try {
    $payloadRaw = (string)file_get_contents('php://input');
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON payload.');
    }

    $pdo = get_mysql_connection();
    plaid_ensure_tables($pdo);
    $itemId = trim((string)($payload['item_id'] ?? ''));
    $webhookType = trim((string)($payload['webhook_type'] ?? ''));
    $webhookCode = trim((string)($payload['webhook_code'] ?? ''));

    $insert = $pdo->prepare(
        'INSERT INTO plaid_webhooks
            (environment, received_at, item_id, webhook_type, webhook_code, payload_json, processing_status)
         VALUES
            ("production", UTC_TIMESTAMP(), :item_id, :webhook_type, :webhook_code, :payload_json, "received")'
    );
    $insert->execute([
        ':item_id' => $itemId !== '' ? $itemId : null,
        ':webhook_type' => $webhookType !== '' ? $webhookType : null,
        ':webhook_code' => $webhookCode !== '' ? $webhookCode : null,
        ':payload_json' => $payloadRaw,
    ]);
    $webhookId = (int)$pdo->lastInsertId();

    $processed = 0;
    $message = '';
    if ($itemId !== '' && $webhookType === 'TRANSACTIONS') {
        $stmt = $pdo->prepare('SELECT * FROM plaid_items WHERE item_id = ? AND sync_status <> "disabled"');
        $stmt->execute([$itemId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
            try {
                plaid_sync_item($pdo, $item);
                $processed++;
            } catch (Throwable $e) {
                $message = $e->getMessage();
            }
        }
    }

    $status = $message === '' ? 'processed' : 'error';
    $update = $pdo->prepare('UPDATE plaid_webhooks SET processing_status = ?, processing_message = ? WHERE id = ?');
    $update->execute([$status, $message !== '' ? $message : null, $webhookId]);

    echo json_encode(['ok' => true, 'processed' => $processed]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
