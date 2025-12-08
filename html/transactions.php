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

$pageTitle = 'All Transactions';
$error = '';
$rows = [];
$totalRows = 0;
$limit = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$filters = [
    'account_id' => isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0,
    'start_date' => (string)($_GET['start_date'] ?? ''),
    'end_date' => (string)($_GET['end_date'] ?? ''),
    'q' => trim((string)($_GET['q'] ?? '')),
    'min_amount' => trim((string)($_GET['min_amount'] ?? '')),
    'max_amount' => trim((string)($_GET['max_amount'] ?? '')),
    'status' => ($_GET['status'] ?? '') !== '' ? (string)$_GET['status'] : '', // '' = all, '0','1','2'
];

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    $owner = $currentUser;

    // Accounts for filter dropdown
    $accounts = $pdo->query('SELECT id, name FROM accounts ORDER BY name ASC')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $where = ['t.owner = ?'];
    $params = [$owner];
    if ($filters['account_id'] > 0) { $where[] = 't.account_id = ?'; $params[] = $filters['account_id']; }
    if ($filters['start_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['start_date'])) { $where[] = 't.`date` >= ?'; $params[] = $filters['start_date']; }
    if ($filters['end_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['end_date'])) { $where[] = 't.`date` <= ?'; $params[] = $filters['end_date']; }
    if ($filters['q'] !== '') {
        $where[] = 't.description LIKE ?';
        $params[] = '%' . $filters['q'] . '%';
    }
    if ($filters['min_amount'] !== '' && is_numeric($filters['min_amount'])) { $where[] = 't.amount >= ?'; $params[] = (float)$filters['min_amount']; }
    if ($filters['max_amount'] !== '' && is_numeric($filters['max_amount'])) { $where[] = 't.amount <= ?'; $params[] = (float)$filters['max_amount']; }
    if ($filters['status'] !== '' && in_array($filters['status'], ['0','1','2'], true)) { $where[] = 'COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) = ?'; $params[] = (int)$filters['status']; }
    $whereSql = implode(' AND ', $where);

    // Total count
    $countSql = "SELECT COUNT(*) FROM transactions t WHERE $whereSql";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    // Page data
    $sql = "SELECT t.id, t.`date`, t.amount, t.description, t.check_no, t.posted, t.status, t.updated_at_source,
                   t.account_id, a.name AS account_name
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            WHERE $whereSql
            ORDER BY t.`date` DESC, t.id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Pagination helpers
$totalPages = max(1, (int)ceil($totalRows / $limit));
$hasPrev = $page > 1;
$hasNext = $page < $totalPages;

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  </head>
  <body>
    <nav class="navbar bg-body-tertiary sticky-top">
      <div class="container-fluid">
        <div class="d-flex align-items-center gap-2">
          <a class="btn btn-outline-secondary btn-sm" href="index.php">← Dashboard</a>
          <span class="navbar-brand mb-0 h1"><?= h($pageTitle) ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="text-body-secondary small d-none d-sm-inline"><?= h($currentUser) ?></span>
          <a class="btn btn-outline-secondary btn-sm" href="index.php?logout=1">Logout</a>
        </div>
      </div>
    </nav>

    <main class="container my-4">
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div class="text-body-secondary small">Page <?= $page ?> of <?= $totalPages ?> • <?= $totalRows ?> transaction<?= $totalRows === 1 ? '' : 's' ?></div>
        <div class="d-flex align-items-center gap-2">
          <button type="submit" form="txFilterForm" class="btn btn-primary btn-sm">Apply filters</button>
          <a class="btn btn-outline-secondary btn-sm" href="transactions.php">Reset</a>
          <div class="btn-group" role="group" aria-label="Pagination">
            <a class="btn btn-outline-secondary btn-sm<?= $hasPrev ? '' : ' disabled' ?>" href="<?= $hasPrev ? h('transactions.php?' . http_build_query(array_merge($filters, ['page' => $page - 1]))) : '#' ?>">« Prev</a>
            <a class="btn btn-outline-secondary btn-sm<?= $hasNext ? '' : ' disabled' ?>" href="<?= $hasNext ? h('transactions.php?' . http_build_query(array_merge($filters, ['page' => $page + 1]))) : '#' ?>">Next »</a>
          </div>
        </div>
      </div>

      <form id="txFilterForm" method="get" class="table-responsive">
        <input type="hidden" name="page" value="1">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th scope="col">Date</th>
              <th scope="col">Account</th>
              <th scope="col">Description</th>
              <th scope="col" class="text-end">Amount</th>
              <th scope="col" class="text-center">Status</th>
            </tr>
            <tr class="align-middle bg-body">
              <th>
                <div class="d-flex flex-column gap-1">
                  <input type="date" class="form-control form-control-sm" name="start_date" value="<?= h($filters['start_date']) ?>" placeholder="From">
                  <input type="date" class="form-control form-control-sm" name="end_date" value="<?= h($filters['end_date']) ?>" placeholder="To">
                </div>
              </th>
              <th>
                <select class="form-select form-select-sm" name="account_id">
                  <option value="0">All</option>
                  <?php foreach ($accounts as $id => $name): ?>
                    <option value="<?= (int)$id ?>" <?= $filters['account_id'] === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
                  <?php endforeach; ?>
                </select>
              </th>
              <th>
                <input type="text" class="form-control form-control-sm" name="q" value="<?= h($filters['q']) ?>" placeholder="Description contains">
              </th>
              <th>
                <div class="d-flex flex-column gap-1">
                  <input type="text" inputmode="decimal" pattern="^-?\d*(?:\.\d{0,2})?$" class="form-control form-control-sm" name="min_amount" value="<?= h($filters['min_amount']) ?>" placeholder="Min">
                  <input type="text" inputmode="decimal" pattern="^-?\d*(?:\.\d{0,2})?$" class="form-control form-control-sm" name="max_amount" value="<?= h($filters['max_amount']) ?>" placeholder="Max">
                </div>
              </th>
              <th class="text-center">
                <select class="form-select form-select-sm" name="status">
                  <option value="">All</option>
                  <option value="0" <?= $filters['status'] === '0' ? 'selected' : '' ?>>Scheduled</option>
                  <option value="1" <?= $filters['status'] === '1' ? 'selected' : '' ?>>Pending</option>
                  <option value="2" <?= $filters['status'] === '2' ? 'selected' : '' ?>>Posted</option>
                </select>
              </th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="5" class="text-center text-body-secondary py-4">No transactions found.</td></tr>
            <?php else: foreach ($rows as $r):
              $amt = $r['amount'];
              $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
              $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : h((string)$amt);
              $statusVal = (int)($r['status'] ?? ($r['posted'] ? 2 : 1));
              $statusLabel = ['Scheduled', 'Pending', 'Posted'][$statusVal] ?? 'Pending';
            ?>
              <tr>
                <td><?= h((string)$r['date']) ?></td>
                <td><?= h((string)($r['account_name'] ?? '')) ?></td>
                <td><?= h((string)$r['description']) ?></td>
                <td class="text-end <?= $amtClass ?>">$<?= $amtFmt ?></td>
                <td class="text-center"><?= h($statusLabel) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </form>

      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="text-body-secondary small">Page <?= $page ?> of <?= $totalPages ?></div>
        <div class="btn-group" role="group" aria-label="Pagination">
          <a class="btn btn-outline-secondary<?= $hasPrev ? '' : ' disabled' ?>" href="<?= $hasPrev ? h('transactions.php?' . http_build_query(array_merge($filters, ['page' => $page - 1]))) : '#' ?>">« Prev</a>
          <a class="btn btn-outline-secondary<?= $hasNext ? '' : ' disabled' ?>" href="<?= $hasNext ? h('transactions.php?' . http_build_query(array_merge($filters, ['page' => $page + 1]))) : '#' ?>">Next »</a>
        </div>
      </div>
    </main>
  </body>
</html>
