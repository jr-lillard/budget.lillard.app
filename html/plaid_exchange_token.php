<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../plaid.php';

try { $pdo = get_mysql_connection(); auth_login_from_cookie($pdo); } catch (Throwable $e) { /* ignore */ }
header('Content-Type: application/json');

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $environment = plaid_normalize_environment((string)($input['environment'] ?? 'production'));
    $publicToken = trim((string)($input['public_token'] ?? ''));
    $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
    if ($publicToken === '') {
        throw new RuntimeException('Missing Plaid public token.');
    }

    $pdo = get_mysql_connection();
    plaid_ensure_tables($pdo);
    $exchange = plaid_api_request('/item/public_token/exchange', [
        'public_token' => $publicToken,
    ], $environment);
    $itemDbId = plaid_store_item($pdo, (string)$_SESSION['username'], $environment, $exchange, $metadata);
    $item = plaid_fetch_item_row($pdo, $itemDbId, (string)$_SESSION['username']);
    if (!$item) {
        throw new RuntimeException('Unable to load saved Plaid Item.');
    }
    $accountCount = 0;
    $metadataAccounts = is_array($metadata['accounts'] ?? null) ? $metadata['accounts'] : [];
    if (!empty($metadataAccounts)) {
        $accountCount = plaid_upsert_accounts($pdo, $itemDbId, (string)($item['institution_name'] ?? ''), $metadataAccounts);
    }
    try {
        $accountCount = max($accountCount, plaid_refresh_accounts($pdo, $item));
    } catch (Throwable $e) {
        if ($accountCount <= 0) {
            throw $e;
        }
    }

    echo json_encode([
        'ok' => true,
        'item_id' => $itemDbId,
        'environment' => $environment,
        'institution_name' => $item['institution_name'] ?? '',
        'account_count' => $accountCount,
        'needs_mapping' => true,
        'request_id' => $exchange['request_id'] ?? null,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
