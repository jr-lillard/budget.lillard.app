<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
// Attempt cookie-based auto-login
try { $pdo = get_mysql_connection(); auth_login_from_cookie($pdo); } catch (Throwable $e) { /* ignore */ }

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    header('Location: index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$name = trim((string)($_POST['account_name'] ?? ''));
$flag = ($_POST['is_client'] ?? '') === '1' ? 1 : 0;

if ($name === '') {
    header('Location: index.php');
    exit;
}

try {
    $pdo = get_mysql_connection();
    // ensure column exists
    try { $pdo->exec('ALTER TABLE accounts ADD COLUMN is_client TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e) { /* ignore */ }
    $upd = $pdo->prepare('UPDATE accounts SET is_client = :flag, updated_at = CURRENT_TIMESTAMP WHERE name = :name');
    $upd->execute([':flag' => $flag, ':name' => $name]);
} catch (Throwable $e) {
    // swallow errors; redirect back
}

header('Location: index.php');
exit;
