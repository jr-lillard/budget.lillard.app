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
    $pdo = get_mysql_connection();
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user((string)$_SESSION['username']);
    $plaidAccountId = (int)($_POST['plaid_account_id'] ?? 0);
    $budgetAccountRaw = trim((string)($_POST['budget_account_id'] ?? ''));
    $budgetAccountId = $budgetAccountRaw !== '' ? (int)$budgetAccountRaw : null;
    if ($plaidAccountId <= 0) {
        throw new RuntimeException('Missing Plaid account.');
    }

    $mapping = plaid_update_account_mapping($pdo, $owner, $plaidAccountId, $budgetAccountId);
    $rematches = [plaid_rematch_account_transactions($pdo, $owner, $plaidAccountId)];
    foreach (($mapping['cleared_account_ids'] ?? []) as $clearedAccountId) {
        $rematches[] = plaid_rematch_account_transactions($pdo, $owner, (int)$clearedAccountId);
    }

    echo json_encode([
        'ok' => true,
        'account' => $mapping['account'] ?? null,
        'rematches' => $rematches,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
