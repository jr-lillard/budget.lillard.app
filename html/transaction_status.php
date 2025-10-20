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
    // Ensure status column exists
    try { $pdo->exec('ALTER TABLE transactions ADD COLUMN status TINYINT NULL'); } catch (Throwable $e) { /* ignore */ }
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    $owner = budget_canonical_user((string)$_SESSION['username']);

    $id = (int)($_POST['id'] ?? 0);
    $rawStatus = $_POST['status'] ?? null;
    if ($id <= 0 || $rawStatus === null || $rawStatus === '') {
        throw new RuntimeException('Invalid parameters');
    }
    $map = [ 'scheduled' => 0, 'pending' => 1, 'posted' => 2 ];
    if (is_string($rawStatus) && isset($map[strtolower((string)$rawStatus)])) {
        $status = $map[strtolower((string)$rawStatus)];
    } else {
        $status = (int)$rawStatus;
    }
    if ($status < 0 || $status > 2) { throw new RuntimeException('Invalid status value'); }

    $posted = ($status === 2) ? 1 : 0;
    if ($status === 2) {
        // Marking as posted: set the date to the newest posted date
        // for the same account (fallback: keep existing date, then today).
        $txStmt = $pdo->prepare('SELECT account_id, `date` FROM transactions WHERE id = :id AND owner = :owner');
        $txStmt->execute([':id' => $id, ':owner' => $owner]);
        $txRow = $txStmt->fetch(PDO::FETCH_ASSOC);
        if (!$txRow) { throw new RuntimeException('Transaction not found'); }
        $accountId = (int)($txRow['account_id'] ?? 0);
        $existingDate = (string)($txRow['date'] ?? '');

        $maxDate = null;
        if ($accountId > 0) {
            $md = $pdo->prepare('SELECT MAX(`date`) FROM transactions WHERE posted = 1 AND account_id = :acct AND owner = :owner');
            $md->execute([':acct' => $accountId, ':owner' => $owner]);
            $maxDate = $md->fetchColumn();
        } else {
            $md = $pdo->prepare('SELECT MAX(`date`) FROM transactions WHERE posted = 1 AND owner = :owner');
            $md->execute([':owner' => $owner]);
            $maxDate = $md->fetchColumn();
        }
        $newDate = $maxDate ?: ($existingDate !== '' ? $existingDate : date('Y-m-d'));

        $stmt = $pdo->prepare('UPDATE transactions SET status = :status, posted = :posted, `date` = :newDate, updated_at_source = NOW()\n+                               WHERE id = :id AND owner = :owner');
        $stmt->execute([':status' => $status, ':posted' => $posted, ':newDate' => $newDate, ':id' => $id, ':owner' => $owner]);
    } elseif ($status === 1) {
        // Marking as pending: keep existing behavior (set to today)
        $today = date('Y-m-d');
        $stmt = $pdo->prepare('UPDATE transactions SET status = :status, posted = :posted, `date` = :today, updated_at_source = NOW()
                               WHERE id = :id AND owner = :owner');
        $stmt->execute([':status' => $status, ':posted' => $posted, ':today' => $today, ':id' => $id, ':owner' => $owner]);
    } else {
        // Scheduled: do not touch date
        $stmt = $pdo->prepare('UPDATE transactions SET status = :status, posted = :posted, updated_at_source = NOW()\n+                               WHERE id = :id AND owner = :owner');
        $stmt->execute([':status' => $status, ':posted' => $posted, ':id' => $id, ':owner' => $owner]);
    }
    if ($stmt->rowCount() < 1) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    echo json_encode(['ok' => true, 'id' => $id, 'status' => $status]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
