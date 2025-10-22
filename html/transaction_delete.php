<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
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
    budget_ensure_transaction_date_columns($pdo);
    $owner = budget_canonical_user((string)$_SESSION['username']);
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('Invalid id');
    }
    $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id AND owner = :owner');
    $stmt->execute([':id' => $id, ':owner' => $owner]);
    if ($stmt->rowCount() < 1) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
