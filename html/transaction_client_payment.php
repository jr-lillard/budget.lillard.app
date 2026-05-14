<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';

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
    $clientAccountId = (int)($_POST['client_payment_account_id'] ?? 0);
    $clientPaymentAmount = trim((string)($_POST['client_payment_amount'] ?? ''));

    if ($id <= 0) {
        throw new RuntimeException('Invalid transaction.');
    }
    if ($clientAccountId <= 0) {
        throw new RuntimeException('Select a client account.');
    }

    $txStmt = $pdo->prepare(
        'SELECT t.id, t.account_id, t.`date`, t.amount, t.posted, t.status, IFNULL(a.is_client, 0) AS account_is_client
         FROM transactions t
         LEFT JOIN accounts a ON a.id = t.account_id
         WHERE t.id = :id AND t.owner = :owner
         LIMIT 1'
    );
    $txStmt->execute([':id' => $id, ':owner' => $owner]);
    $tx = $txStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Transaction not found']);
        exit;
    }

    $sourceAmount = is_numeric($tx['amount'] ?? null) ? (float)$tx['amount'] : 0.0;
    if ($sourceAmount <= 0.0) {
        throw new RuntimeException('Client payments can only be recorded for deposits.');
    }
    if ((int)($tx['account_is_client'] ?? 0) === 1) {
        throw new RuntimeException('Client payment source must be a deposit account, not a client account.');
    }

    $clientStmt = $pdo->prepare('SELECT name FROM accounts WHERE id = ? AND IFNULL(is_client, 0) = 1 LIMIT 1');
    $clientStmt->execute([$clientAccountId]);
    $clientName = trim((string)($clientStmt->fetchColumn() ?: ''));
    if ($clientName === '') {
        throw new RuntimeException('Select a client account.');
    }

    $status = $tx['status'];
    if ($status === null || $status === '') {
        $status = (int)($tx['posted'] ?? 0) === 1 ? 2 : 1;
    } else {
        $status = (int)$status;
    }
    if ($status < 0 || $status > 2) {
        $status = 1;
    }
    $paymentAmount = $clientPaymentAmount !== '' ? $clientPaymentAmount : (string)$tx['amount'];
    $date = trim((string)($tx['date'] ?? ''));

    $pdo->beginTransaction();
    $update = $pdo->prepare(
        'UPDATE transactions
         SET description = :description, updated_at_source = NOW()
         WHERE id = :id AND owner = :owner'
    );
    $update->execute([
        ':description' => $clientName,
        ':id' => $id,
        ':owner' => $owner,
    ]);
    $clientPaymentId = budget_create_client_payment(
        $pdo,
        $owner,
        $clientAccountId,
        $date !== '' ? $date : null,
        $paymentAmount,
        $status
    );
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'id' => $id,
        'client_payment_id' => $clientPaymentId,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
