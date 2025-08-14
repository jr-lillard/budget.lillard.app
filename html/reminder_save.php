<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
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
    $id = (int)($_POST['id'] ?? 0);
    $due = trim((string)($_POST['due'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $freq = trim((string)($_POST['frequency'] ?? ''));
    $accountSelect = trim((string)($_POST['account_select'] ?? ''));
    $accountNew = trim((string)($_POST['account_name_new'] ?? ''));
    $accountKeep = (int)($_POST['account_keep'] ?? 0);

    if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        throw new RuntimeException('Due must be YYYY-MM-DD');
    }
    if ($amount !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('Amount format invalid');
    }

    // Resolve account id/name
    $accountId = null; $accountNameOut = null;
    if ($accountSelect === '__current__' && $accountKeep > 0) {
        $accountId = $accountKeep;
        $nm = $pdo->prepare('SELECT name FROM accounts WHERE id = ?');
        $nm->execute([$accountKeep]);
        $accountNameOut = ($nm->fetchColumn() ?: null);
    } elseif ($accountSelect === '__new__' && $accountNew !== '') {
        $ins = $pdo->prepare('INSERT INTO accounts (name) VALUES (?) ON DUPLICATE KEY UPDATE updated_at = updated_at');
        $ins->execute([$accountNew]);
        $sel = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
        $sel->execute([$accountNew]);
        $accountId = (int)$sel->fetchColumn();
        $accountNameOut = $accountNew;
    } elseif ($accountSelect !== '' && $accountSelect !== '__new__' && $accountSelect !== '__current__') {
        $sel = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
        $sel->execute([$accountSelect]);
        $accountId = (int)$sel->fetchColumn();
        $accountNameOut = $accountSelect;
    }

    if ($id <= 0) {
        // Insert new reminder
        $bytes = random_bytes(16);
        $hex = strtoupper(bin2hex($bytes));
        $fm_pk = substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20);
        $stmt = $pdo->prepare('INSERT INTO reminders (fm_pk, account_id, account_name, description, amount, due, frequency, created_at_source, updated_at_source) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([
            $fm_pk,
            $accountId ?: null,
            $accountNameOut,
            $desc !== '' ? $desc : null,
            $amount !== '' ? $amount : null,
            $due !== '' ? $due : null,
            $freq !== '' ? $freq : null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $newId]);
    } else {
        $stmt = $pdo->prepare('UPDATE reminders SET account_id = :account_id, account_name = :account_name, description = :description, amount = :amount, due = :due, frequency = :frequency WHERE id = :id');
        $stmt->execute([
            ':account_id' => $accountId ?: null,
            ':account_name' => $accountNameOut,
            ':description' => $desc !== '' ? $desc : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':due' => $due !== '' ? $due : null,
            ':frequency' => $freq !== '' ? $freq : null,
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

