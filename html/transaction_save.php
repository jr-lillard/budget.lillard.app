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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $pdo = get_mysql_connection();
    // Ensure the 3-state status column exists (0=scheduled, 1=pending, 2=posted)
    try {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN status TINYINT NULL');
    } catch (Throwable $e) {
        // ignore if exists
    }
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    // Ensure legacy fm_pk is nullable so we can stop using it
    try { $pdo->exec("ALTER TABLE transactions MODIFY fm_pk VARCHAR(64) NULL"); } catch (Throwable $e) { /* ignore */ }
    $id = (int)($_POST['id'] ?? 0);
    // New vs update
    $isInsert = ($id <= 0);
    $owner = budget_canonical_user((string)$_SESSION['username']);

    $date = trim((string)($_POST['date'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $checkNo = trim((string)($_POST['check_no'] ?? ''));
    // Accept either explicit 3-state status or fallback to posted checkbox
    $rawStatus = $_POST['status'] ?? null;
    $postedInt = isset($_POST['posted']) ? 1 : 0;
    // Accept either selection or a new name
    $accountSelect = trim((string)($_POST['account_select'] ?? ''));
    $accountNew = trim((string)($_POST['account_name_new'] ?? ''));
    $accountKeep = (int)($_POST['account_keep'] ?? 0);
    $accountName = $accountSelect === '__new__' ? $accountNew : ($accountSelect ?: $accountNew);

    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Date must be YYYY-MM-DD');
    }
    if ($amount !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('Amount format invalid');
    }

    // Resolve account id
    $accountId = null;
    if ($accountSelect === '__current__' && $accountKeep > 0) {
        $accountId = $accountKeep;
    } elseif ($accountSelect === '__new__' && $accountNew !== '') {
        $ins = $pdo->prepare('INSERT INTO accounts (name) VALUES (?) ON DUPLICATE KEY UPDATE updated_at = updated_at');
        $ins->execute([$accountNew]);
        $sel = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
        $sel->execute([$accountNew]);
        $accountId = (int)$sel->fetchColumn();
    } elseif ($accountSelect !== '' && $accountSelect !== '__new__' && $accountSelect !== '__current__') {
        $sel = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
        $sel->execute([$accountSelect]);
        $accountId = (int)$sel->fetchColumn();
    }

    // Normalize status value: 0=scheduled, 1=pending, 2=posted
    $statusVal = null;
    if ($rawStatus !== null && $rawStatus !== '') {
        $map = [
            'scheduled' => 0,
            'pending' => 1,
            'posted' => 2,
        ];
        if (is_string($rawStatus) && isset($map[strtolower($rawStatus)])) {
            $statusVal = $map[strtolower((string)$rawStatus)];
        } else {
            $statusVal = (int)$rawStatus;
        }
        if ($statusVal < 0 || $statusVal > 2) { $statusVal = 1; }
    } else {
        // Fallback from posted checkbox
        $statusVal = $postedInt === 1 ? 2 : 1;
    }
    // Keep legacy posted column in sync with 3-state status
    $postedInt = ($statusVal === 2) ? 1 : 0;

    if ($isInsert) {
        $sql = 'INSERT INTO transactions (account_id, `date`, amount, description, check_no, posted, status, owner, created_at_source, updated_at_source)
                VALUES (:account_id, :date, :amount, :description, :check_no, :posted, :status, :owner, NOW(), NOW())';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':account_id' => $accountId ?: null,
            ':date' => $date !== '' ? $date : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':description' => $description !== '' ? $description : null,
            ':check_no' => $checkNo !== '' ? $checkNo : null,
            ':posted' => $postedInt,
            ':status' => $statusVal,
            ':owner' => $owner,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $newId]);
    } else {
        $sql = 'UPDATE transactions SET account_id = :account_id, `date` = :date, amount = :amount, description = :description, check_no = :check_no, posted = :posted, status = :status, updated_at_source = NOW()
                WHERE id = :id AND owner = :owner';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':account_id' => $accountId ?: null,
            ':date' => $date !== '' ? $date : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':description' => $description !== '' ? $description : null,
            ':check_no' => $checkNo !== '' ? $checkNo : null,
            ':posted' => $postedInt,
            ':status' => $statusVal,
            ':id' => $id,
            ':owner' => $owner,
        ]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            return;
        }
        echo json_encode(['ok' => true, 'id' => $id]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
