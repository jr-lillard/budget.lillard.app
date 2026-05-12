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
    $itemId = (int)($_POST['item_id'] ?? 0);
    $summaries = plaid_sync_owner_items($pdo, (string)$_SESSION['username'], $itemId > 0 ? $itemId : null);
    echo json_encode([
        'ok' => true,
        'items' => $summaries,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
