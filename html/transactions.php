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
$rawAccountFilter = $_GET['account_id'] ?? null;
$hasAccountFilter = array_key_exists('account_id', $_GET);
$accountIds = [];
if (is_array($rawAccountFilter)) {
    foreach ($rawAccountFilter as $value) {
        if ($value === '' || $value === null) { continue; }
        if (is_numeric($value)) {
            $id = (int)$value;
            if ($id > 0) { $accountIds[] = $id; }
        }
    }
} elseif ($rawAccountFilter !== null && $rawAccountFilter !== '') {
    if (is_numeric($rawAccountFilter)) {
        $id = (int)$rawAccountFilter;
        if ($id > 0) { $accountIds[] = $id; }
    }
}
$accountIds = array_values(array_unique($accountIds));

$filters = [
    'account_id' => $accountIds,
    'start_date' => (string)($_GET['start_date'] ?? ''),
    'end_date' => (string)($_GET['end_date'] ?? ''),
    'q' => trim((string)($_GET['q'] ?? '')),
    'exclude' => trim((string)($_GET['exclude'] ?? '')),
    'min_amount' => trim((string)($_GET['min_amount'] ?? '')),
    'max_amount' => trim((string)($_GET['max_amount'] ?? '')),
    'status' => ($_GET['status'] ?? '') !== '' ? (string)$_GET['status'] : '', // '' = all, '0','1','2'
];

function budget_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    $owner = $currentUser;

    // Accounts for filter dropdown
    $accounts = $pdo->query('SELECT id, name FROM accounts ORDER BY name ASC')->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

    $where = ['t.owner = ?'];
    $params = [$owner];
    if ($hasAccountFilter) {
        if (empty($accountIds)) {
            $where[] = '1=0';
        } else {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $where[] = "t.account_id IN ($placeholders)";
            foreach ($accountIds as $id) { $params[] = $id; }
        }
    }
    if ($filters['start_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['start_date'])) { $where[] = 't.`date` >= ?'; $params[] = $filters['start_date']; }
    if ($filters['end_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['end_date'])) { $where[] = 't.`date` <= ?'; $params[] = $filters['end_date']; }
    if ($filters['q'] !== '') {
        $where[] = 't.description LIKE ?';
        $params[] = '%' . $filters['q'] . '%';
    }
    if ($filters['exclude'] !== '') {
        $excludeExact = [];
        $excludeContains = [];
        foreach (preg_split('/\r\n|\r|\n/', $filters['exclude']) as $line) {
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
        if (!empty($excludeExact)) {
            $excludeExact = array_values(array_unique($excludeExact));
            $placeholders = implode(',', array_fill(0, count($excludeExact), '?'));
            $where[] = "COALESCE(t.description,'') NOT IN ($placeholders)";
            foreach ($excludeExact as $value) { $params[] = $value; }
        }
        if (!empty($excludeContains)) {
            $excludeContains = array_values(array_unique($excludeContains));
            foreach ($excludeContains as $value) {
                $where[] = "COALESCE(t.description,'') NOT LIKE ? ESCAPE '\\\\'";
                $params[] = '%' . budget_escape_like($value) . '%';
            }
        }
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" crossorigin="anonymous">
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
              <th scope="col"></th>
            </tr>
            <tr class="align-middle bg-body">
              <th>
                <div class="d-flex flex-column gap-1">
                  <input type="text" inputmode="numeric" pattern="\\d{4}-\\d{2}-\\d{2}" maxlength="10" class="form-control form-control-sm" name="start_date" value="<?= h($filters['start_date']) ?>" placeholder="YYYY-MM-DD" title="YYYY-MM-DD">
                  <input type="text" inputmode="numeric" pattern="\\d{4}-\\d{2}-\\d{2}" maxlength="10" class="form-control form-control-sm" name="end_date" value="<?= h($filters['end_date']) ?>" placeholder="YYYY-MM-DD" title="YYYY-MM-DD">
                </div>
              </th>
              <th>
                <select class="form-select form-select-sm" name="account_id[]" multiple size="6">
                  <?php foreach ($accounts as $id => $name):
                    $selected = $hasAccountFilter ? in_array((int)$id, $accountIds, true) : true;
                  ?>
                    <option value="<?= (int)$id ?>" <?= $selected ? 'selected' : '' ?>><?= h($name) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="d-flex gap-2 align-items-center mt-1">
                  <a href="#" class="small text-decoration-none js-select-all-accounts">Select all</a>
                  <span class="text-body-secondary small">All accounts selected by default. Deselect to filter.</span>
                </div>
              </th>
              <th>
                <div class="d-flex flex-column gap-1">
                  <input type="text" class="form-control form-control-sm" name="q" value="<?= h($filters['q']) ?>" placeholder="Description contains">
                  <textarea class="form-control form-control-sm" name="exclude" rows="3" placeholder="Exclude descriptions (one per line)"><?= h($filters['exclude']) ?></textarea>
                  <div class="form-text small">Prefix exact matches with = or exact:; otherwise treated as contains.</div>
                </div>
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
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="text-center text-body-secondary py-4">No transactions found.</td></tr>
            <?php else: foreach ($rows as $r):
              $amt = $r['amount'];
              $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
              $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : h((string)$amt);
              $statusVal = (int)($r['status'] ?? ($r['posted'] ? 2 : 1));
              $statusLabel = ['Scheduled', 'Pending', 'Posted'][$statusVal] ?? 'Pending';
              $descRaw = (string)($r['description'] ?? '');
              $descAttr = trim(preg_replace('/\s+/', ' ', $descRaw));
              $descAttrSafe = h($descAttr);
            ?>
              <tr>
                <td><?= h((string)$r['date']) ?></td>
                <td><?= h((string)($r['account_name'] ?? '')) ?></td>
                <td><?= h($descRaw) ?></td>
                <td class="text-end <?= $amtClass ?>">$<?= $amtFmt ?></td>
                <td class="text-center"><?= h($statusLabel) ?></td>
                <td class="text-end">
                  <div class="dropdown">
                    <button class="btn btn-sm border-0 text-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Row actions" <?= $descAttr === '' ? 'disabled' : '' ?>>
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li>
                        <a class="dropdown-item tx-exclude-action" href="#" data-mode="exact" data-desc="<?= $descAttrSafe ?>">Exclude exact description</a>
                      </li>
                      <li>
                        <a class="dropdown-item tx-exclude-action" href="#" data-mode="contains" data-desc="<?= $descAttrSafe ?>">Exclude descriptions containing this</a>
                      </li>
                    </ul>
                  </div>
                </td>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
      (() => {
        const form = document.getElementById('txFilterForm');
        const excludeField = form?.querySelector('[name="exclude"]');
        const accountSelect = form?.querySelector('select[name="account_id[]"]');
        const selectAllLink = form?.querySelector('.js-select-all-accounts');
        if (!form || !excludeField) return;

        const normalize = (value) => value.replace(/\s+/g, ' ').trim();

        document.addEventListener('click', (event) => {
          const action = event.target.closest('.tx-exclude-action');
          if (!action) return;
          event.preventDefault();
          const raw = action.dataset.desc || '';
          const desc = normalize(raw);
          if (!desc) return;
          const mode = action.dataset.mode === 'exact' ? 'exact' : 'contains';
          const entry = mode === 'exact' ? `=${desc}` : desc;

          const existing = new Set(
            excludeField.value
              .split(/\r?\n/)
              .map((line) => line.trim())
              .filter(Boolean)
          );
          if (!existing.has(entry)) {
            existing.add(entry);
          }
          excludeField.value = Array.from(existing).join('\n');
          form.submit();
        });

        selectAllLink && selectAllLink.addEventListener('click', (event) => {
          event.preventDefault();
          if (!accountSelect) return;
          for (const option of accountSelect.options) {
            option.selected = true;
          }
        });
      })();
    </script>
  </body>
</html>
