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

$currentUser = budget_canonical_user((string)($_SESSION['username'] ?? ''));
if ($currentUser !== ($_SESSION['username'] ?? '')) {
    $_SESSION['username'] = $currentUser;
}

$filterDefaults = [
    'start_date' => '',
    'end_date' => '',
    'account_q' => '',
    'account_exclude' => '',
    'q' => '',
    'exclude' => '',
    'min_amount' => '',
    'max_amount' => '',
    'status' => '',
    'sort' => 'date',
    'dir' => 'desc',
];

$allowedColumns = [
    'date' => 'Date',
    'account' => 'Account',
    'description' => 'Description',
    'amount' => 'Amount',
    'status' => 'Status',
];
$selectedColumns = $_POST['columns'] ?? ($_GET['columns'] ?? []);
if (!is_array($selectedColumns)) {
    $selectedColumns = [];
}
$selectedColumns = array_values(array_intersect($selectedColumns, array_keys($allowedColumns)));
if (empty($selectedColumns)) {
    $selectedColumns = array_keys($allowedColumns);
}

function budget_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function budget_parse_exclusions(string $raw): array
{
    $excludeExact = [];
    $excludeContains = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        if (str_starts_with($line, '=')) {
            $value = trim(substr($line, 1));
            if ($value !== '') { $excludeExact[] = $value; }
            continue;
        }
        if (strncasecmp($line, 'exact:', 6) === 0) {
            $value = trim(substr($line, 6));
            if ($value !== '') { $excludeExact[] = $value; }
            continue;
        }
        if (strncasecmp($line, 'contains:', 9) === 0) {
            $value = trim(substr($line, 9));
            if ($value !== '') { $excludeContains[] = $value; }
            continue;
        }
        $excludeContains[] = $line;
    }
    return [
        array_values(array_unique($excludeExact)),
        array_values(array_unique($excludeContains)),
    ];
}

function budget_normalize_filters(array $filters, array $defaults): array
{
    $merged = array_merge($defaults, $filters);
    $merged['account_q'] = trim((string)($merged['account_q'] ?? ''));
    $merged['account_exclude'] = trim((string)($merged['account_exclude'] ?? ''));
    $merged['exclude'] = trim((string)($merged['exclude'] ?? ''));
    $normalizeMultiline = static function (string $value): string {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if (strpos($value, '\\') !== false) {
            $value = str_replace(['\\r\\n', '\\n', '\\r'], "\n", $value);
        }
        return $value;
    };
    $merged['account_exclude'] = $normalizeMultiline($merged['account_exclude']);
    $merged['exclude'] = $normalizeMultiline($merged['exclude']);
    $allowedSort = ['date', 'account', 'description', 'amount', 'status'];
    if (!in_array((string)$merged['sort'], $allowedSort, true)) {
        $merged['sort'] = $defaults['sort'] ?? 'date';
    }
    $dir = strtolower((string)($merged['dir'] ?? ''));
    if (!in_array($dir, ['asc', 'desc'], true)) {
        $dir = $defaults['dir'] ?? 'desc';
    }
    $merged['dir'] = $dir;
    return $merged;
}

function budget_load_tx_filters(PDO $pdo, string $username, array $defaults): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT tx_filters FROM user_preferences WHERE username = ?');
        $stmt->execute([$username]);
        $raw = $stmt->fetchColumn();
        if (!$raw) { return null; }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) { return null; }
        $filters = isset($data['filters']) && is_array($data['filters']) ? $data['filters'] : [];
        return budget_normalize_filters($filters, $defaults);
    } catch (Throwable $e) {
        return null;
    }
}

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    $owner = $currentUser;

    $filters = $filterDefaults;
    if (!empty($_SESSION['tx_filters']['filters']) && is_array($_SESSION['tx_filters']['filters'])) {
        $filters = budget_normalize_filters($_SESSION['tx_filters']['filters'], $filterDefaults);
    } else {
        $saved = budget_load_tx_filters($pdo, $owner, $filterDefaults);
        if (is_array($saved)) {
            $filters = $saved;
        }
    }

    $where = ['t.owner = ?'];
    $params = [$owner];
    if ($filters['account_q'] !== '') {
        $where[] = 'COALESCE(a.name, \'\') LIKE ?';
        $params[] = '%' . budget_escape_like($filters['account_q']) . '%';
    }
    if ($filters['account_exclude'] !== '') {
        [$acctExact, $acctContains] = budget_parse_exclusions($filters['account_exclude']);
        if (!empty($acctExact)) {
            $placeholders = implode(',', array_fill(0, count($acctExact), '?'));
            $where[] = "COALESCE(a.name, '') NOT IN ($placeholders)";
            foreach ($acctExact as $value) { $params[] = $value; }
        }
        if (!empty($acctContains)) {
            foreach ($acctContains as $value) {
                $where[] = "COALESCE(a.name, '') NOT LIKE ? ESCAPE '\\\\'";
                $params[] = '%' . budget_escape_like($value) . '%';
            }
        }
    }
    if ($filters['start_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['start_date'])) { $where[] = 't.`date` >= ?'; $params[] = $filters['start_date']; }
    if ($filters['end_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['end_date'])) { $where[] = 't.`date` <= ?'; $params[] = $filters['end_date']; }
    if ($filters['q'] !== '') {
        $where[] = 't.description LIKE ?';
        $params[] = '%' . $filters['q'] . '%';
    }
    if ($filters['exclude'] !== '') {
        [$excludeExact, $excludeContains] = budget_parse_exclusions($filters['exclude']);
        if (!empty($excludeExact)) {
            $placeholders = implode(',', array_fill(0, count($excludeExact), '?'));
            $where[] = "COALESCE(t.description,'') NOT IN ($placeholders)";
            foreach ($excludeExact as $value) { $params[] = $value; }
        }
        if (!empty($excludeContains)) {
            foreach ($excludeContains as $value) {
                $where[] = "COALESCE(t.description,'') NOT LIKE ? ESCAPE '\\\\'";
                $params[] = '%' . budget_escape_like($value) . '%';
            }
        }
    }
    if ($filters['min_amount'] !== '' && is_numeric($filters['min_amount'])) { $where[] = 't.amount >= ?'; $params[] = (float)$filters['min_amount']; }
    if ($filters['max_amount'] !== '' && is_numeric($filters['max_amount'])) { $where[] = 't.amount <= ?'; $params[] = (float)$filters['max_amount']; }
    if ($filters['status'] !== '' && in_array($filters['status'], ['0','1','2'], true)) { $where[] = 'COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) = ?'; $params[] = (int)$filters['status']; }

    $sortCol = (string)($filters['sort'] ?? 'date');
    $sortDir = (string)($filters['dir'] ?? 'desc');
    $dirSql = $sortDir === 'asc' ? 'ASC' : 'DESC';
    switch ($sortCol) {
        case 'account':
            $orderBy = "a.name {$dirSql}, t.`date` DESC, t.id DESC";
            break;
        case 'description':
            $orderBy = "t.description {$dirSql}, t.`date` DESC, t.id DESC";
            break;
        case 'amount':
            $orderBy = "t.amount {$dirSql}, t.`date` DESC, t.id DESC";
            break;
        case 'status':
            $orderBy = "COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) {$dirSql}, t.`date` DESC, t.id DESC";
            break;
        case 'date':
        default:
            $orderBy = "t.`date` {$dirSql}, t.id DESC";
            break;
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT t.`date`, a.name AS account_name, t.description, t.amount, t.status, t.posted
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            WHERE $whereSql
            ORDER BY $orderBy";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $filename = 'transactions-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    $header = [];
    foreach ($selectedColumns as $column) {
        $header[] = $allowedColumns[$column];
    }
    fputcsv($out, $header);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $amt = $row['amount'];
        $statusVal = (int)($row['status'] ?? ($row['posted'] ? 2 : 1));
        $statusLabel = ['Scheduled', 'Pending', 'Posted'][$statusVal] ?? 'Pending';
        $amtValue = is_numeric($amt) ? number_format((float)$amt, 2) : (string)$amt;
        $line = [];
        foreach ($selectedColumns as $column) {
            switch ($column) {
                case 'date':
                    $line[] = (string)($row['date'] ?? '');
                    break;
                case 'account':
                    $line[] = (string)($row['account_name'] ?? '');
                    break;
                case 'description':
                    $line[] = (string)($row['description'] ?? '');
                    break;
                case 'amount':
                    $line[] = $amtValue;
                    break;
                case 'status':
                    $line[] = $statusLabel;
                    break;
            }
        }
        fputcsv($out, $line);
    }
    fclose($out);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Export failed.';
}
