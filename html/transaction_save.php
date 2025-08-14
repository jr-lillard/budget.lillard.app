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
    if ($id <= 0) throw new RuntimeException('Invalid id');

    $date = trim((string)($_POST['date'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $checkNo = trim((string)($_POST['check_no'] ?? ''));
    $posted = trim((string)($_POST['posted'] ?? ''));
    $category = trim((string)($_POST['category'] ?? ''));
    $tags = trim((string)($_POST['tags'] ?? ''));
    $accountName = trim((string)($_POST['account_name'] ?? ''));

    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Date must be YYYY-MM-DD');
    }
    if ($amount !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('Amount format invalid');
    }

    // Resolve account id
    $accountId = null;
    if ($accountName !== '') {
        $ins = $pdo->prepare('INSERT INTO accounts (name) VALUES (?) ON DUPLICATE KEY UPDATE updated_at = updated_at');
        $ins->execute([$accountName]);
        $sel = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
        $sel->execute([$accountName]);
        $accountId = (int)$sel->fetchColumn();
    }

    $sql = 'UPDATE transactions SET account_id = :account_id, `date` = :date, amount = :amount, description = :description, check_no = :check_no, posted = :posted, category = :category, tags = :tags WHERE id = :id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':account_id' => $accountId ?: null,
        ':date' => $date !== '' ? $date : null,
        ':amount' => $amount !== '' ? $amount : null,
        ':description' => $description !== '' ? $description : null,
        ':check_no' => $checkNo !== '' ? $checkNo : null,
        ':posted' => $posted !== '' ? $posted : null,
        ':category' => $category !== '' ? $category : null,
        ':tags' => $tags !== '' ? $tags : null,
        ':id' => $id,
    ]);

    echo json_encode(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

