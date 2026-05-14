<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../plaid.php';
// Attempt cookie-based auto-login
try { $pdo = get_mysql_connection(); auth_login_from_cookie($pdo); } catch (Throwable $e) { /* ignore */ }
header('Content-Type: application/json');

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    $owner = budget_canonical_user((string)$_SESSION['username']);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Invalid id');
    }

    plaid_ensure_tables($pdo);
    $pdo->beginTransaction();
    $txStmt = $pdo->prepare('SELECT id FROM transactions WHERE id = :id AND owner = :owner LIMIT 1 FOR UPDATE');
    $txStmt->execute([':id' => $id, ':owner' => $owner]);
    if (!$txStmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }

    $ignoredPlaidRows = plaid_mark_budget_transaction_deleted($pdo, $owner, $id);
    $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id AND owner = :owner');
    $stmt->execute([':id' => $id, ':owner' => $owner]);
    if ($stmt->rowCount() < 1) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    $pdo->commit();
    echo json_encode(['ok' => true, 'id' => $id, 'ignored_plaid_transactions' => $ignoredPlaidRows]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
