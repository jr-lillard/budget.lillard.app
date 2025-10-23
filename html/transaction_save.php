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
    budget_ensure_transaction_date_columns($pdo);
    $id = (int)($_POST['id'] ?? 0);
    // New vs update
    $isInsert = ($id <= 0);

    $date = trim((string)($_POST['date'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $checkNo = trim((string)($_POST['check_no'] ?? ''));
    $hasCheck = ($checkNo !== '');
    $initiatedDate = trim((string)($_POST['initiated_date'] ?? ''));
    $mailedDate = trim((string)($_POST['mailed_date'] ?? ''));
    $settledDate = trim((string)($_POST['settled_date'] ?? ''));
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
    foreach ([['Initiated date', $initiatedDate], ['Mailed date', $mailedDate], ['Settled date', $settledDate]] as [$label, $val]) {
        if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            throw new RuntimeException($label . ' must be YYYY-MM-DD');
        }
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

    if (!$hasCheck) {
        $initiatedDate = '';
        $mailedDate = '';
        $settledDate = '';
    } else {
        if ($initiatedDate === '' && $date !== '') {
            $initiatedDate = $date;
        }
        if ($statusVal === 2) {
            if ($settledDate === '' && $date !== '') {
                $settledDate = $date;
            }
        } else {
            $settledDate = '';
        }
    }

    $initiatedParam = ($hasCheck && $initiatedDate !== '') ? $initiatedDate : null;
    $mailedParam = ($hasCheck && $mailedDate !== '') ? $mailedDate : null;
    $settledParam = ($hasCheck && $settledDate !== '') ? $settledDate : null;

    if ($isInsert) {
        // Generate a GUID-like fm_pk
        $bytes = random_bytes(16);
        $hex = strtoupper(bin2hex($bytes));
        $fm_pk = substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20);
        $sql = 'INSERT INTO transactions (fm_pk, account_id, `date`, amount, description, check_no, initiated_date, mailed_date, settled_date, posted, status, created_at_source, updated_at_source)
                VALUES (:fm_pk, :account_id, :date, :amount, :description, :check_no, :initiated_date, :mailed_date, :settled_date, :posted, :status, NOW(), NOW())';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':fm_pk' => $fm_pk,
            ':account_id' => $accountId ?: null,
            ':date' => $date !== '' ? $date : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':description' => $description !== '' ? $description : null,
            ':check_no' => $checkNo !== '' ? $checkNo : null,
            ':initiated_date' => $initiatedParam,
            ':mailed_date' => $mailedParam,
            ':settled_date' => $settledParam,
            ':posted' => $postedInt,
            ':status' => $statusVal,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $newId]);
    } else {
        $sql = 'UPDATE transactions SET account_id = :account_id, `date` = :date, amount = :amount, description = :description, check_no = :check_no, initiated_date = :initiated_date, mailed_date = :mailed_date, settled_date = :settled_date, posted = :posted, status = :status WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':account_id' => $accountId ?: null,
            ':date' => $date !== '' ? $date : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':description' => $description !== '' ? $description : null,
            ':check_no' => $checkNo !== '' ? $checkNo : null,
            ':initiated_date' => $initiatedParam,
            ':mailed_date' => $mailedParam,
            ':settled_date' => $settledParam,
            ':posted' => $postedInt,
            ':status' => $statusVal,
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
