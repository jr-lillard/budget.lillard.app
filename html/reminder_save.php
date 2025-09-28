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
    // Ensure structured frequency columns exist
    try { $pdo->exec('ALTER TABLE reminders ADD COLUMN frequency_every INT NULL'); } catch (Throwable $e) { /* ignore if exists */ }
    try { $pdo->exec("ALTER TABLE reminders ADD COLUMN frequency_unit VARCHAR(32) NULL"); } catch (Throwable $e) { /* ignore if exists */ }
    $id = (int)($_POST['id'] ?? 0);
    $due = trim((string)($_POST['due'] ?? ''));
    $amount = trim((string)($_POST['amount'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    // Legacy freeform frequency (kept but unused)
    $freq = trim((string)($_POST['frequency'] ?? ''));
    // New exact frequency fields
    $freqEveryIn = isset($_POST['frequency_every']) ? (string)$_POST['frequency_every'] : '';
    $freqUnitIn = isset($_POST['frequency_unit']) ? (string)$_POST['frequency_unit'] : '';
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

    // Determine exact frequency to save: prefer explicit inputs; fallback to parsing legacy freeform
    $parsedEvery = null; $parsedUnit = null;
    $unitNormalized = strtolower(trim($freqUnitIn));
    $hasUnit = ($unitNormalized !== '');
    $hasEvery = ($freqEveryIn !== '');
    if ($hasUnit && ($hasEvery || $unitNormalized === 'semi-monthly')) {
        // Accept missing "every" only for semi-monthly; default to 1 in that case
        $n = $hasEvery ? (int)$freqEveryIn : 1;
        if ($n < 1) $n = 1;
        $allowed = ['days','weeks','months','years','semi-monthly'];
        if (!in_array($unitNormalized, $allowed, true)) {
            throw new RuntimeException('Invalid frequency unit');
        }
        $parsedEvery = ($unitNormalized === 'semi-monthly') ? 1 : $n;
        $parsedUnit = $unitNormalized;
    } else {
        // Fallback: parse legacy free-form frequency
        $f = strtolower(trim($freq));
        if ($f !== '') {
            if (strpos($f, 'semi') !== false && strpos($f, 'month') !== false) { $parsedEvery = 1; $parsedUnit = 'semi-monthly'; }
            elseif (strpos($f, 'biweek') !== false || strpos($f, 'every 2 week') !== false) { $parsedEvery = 2; $parsedUnit = 'weeks'; }
            elseif (strpos($f, 'quarter') !== false) { $parsedEvery = 3; $parsedUnit = 'months'; }
            elseif (preg_match('/(\d+)\s*year/', $f, $m)) { $parsedEvery = max(1, (int)$m[1]); $parsedUnit = 'years'; }
            elseif (strpos($f, 'annual') !== false || strpos($f, 'year') !== false) { $parsedEvery = 1; $parsedUnit = 'years'; }
            elseif (preg_match('/(\d+)\s*day/', $f, $m)) { $parsedEvery = max(1, (int)$m[1]); $parsedUnit = 'days'; }
            elseif (strpos($f, 'daily') !== false || strpos($f, 'day') !== false) { $parsedEvery = 1; $parsedUnit = 'days'; }
            elseif (preg_match('/every\s*(\d+)\s*(day|week|month|year)/', $f, $m)) { $parsedEvery = max(1,(int)$m[1]); $parsedUnit = $m[2] . 's'; }
            elseif (preg_match('/(\d+)\s*(day|week|month|year)s?\b/', $f, $m)) { $parsedEvery = max(1,(int)$m[1]); $parsedUnit = $m[2] . 's'; }
            elseif (strpos($f, 'week') !== false) { $parsedEvery = 1; $parsedUnit = 'weeks'; }
            elseif (strpos($f, 'month') !== false) { $parsedEvery = 1; $parsedUnit = 'months'; }
        }
    }

    if ($id <= 0) {
        // Insert new reminder
        $bytes = random_bytes(16);
        $hex = strtoupper(bin2hex($bytes));
        $fm_pk = substr($hex,0,8) . '-' . substr($hex,8,4) . '-' . substr($hex,12,4) . '-' . substr($hex,16,4) . '-' . substr($hex,20);
        $stmt = $pdo->prepare('INSERT INTO reminders (fm_pk, account_id, account_name, description, amount, due, frequency, frequency_every, frequency_unit, created_at_source, updated_at_source) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $stmt->execute([
            $fm_pk,
            $accountId ?: null,
            $accountNameOut,
            $desc !== '' ? $desc : null,
            $amount !== '' ? $amount : null,
            $due !== '' ? $due : null,
            $freq !== '' ? $freq : null,
            $parsedEvery,
            $parsedUnit,
        ]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['ok' => true, 'id' => $newId]);
    } else {
        $stmt = $pdo->prepare('UPDATE reminders SET account_id = :account_id, account_name = :account_name, description = :description, amount = :amount, due = :due, frequency = :frequency, frequency_every = :frequency_every, frequency_unit = :frequency_unit WHERE id = :id');
        $stmt->execute([
            ':account_id' => $accountId ?: null,
            ':account_name' => $accountNameOut,
            ':description' => $desc !== '' ? $desc : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':due' => $due !== '' ? $due : null,
            ':frequency' => $freq !== '' ? $freq : null,
            ':frequency_every' => $parsedEvery,
            ':frequency_unit' => $parsedUnit,
            ':id' => $id,
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
