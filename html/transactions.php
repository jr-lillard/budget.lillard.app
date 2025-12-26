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
function budget_extract_filters(array $input): array
{
    $filters = [
        'account_id' => [],
        'start_date' => (string)($input['start_date'] ?? ''),
        'end_date' => (string)($input['end_date'] ?? ''),
        'q' => trim((string)($input['q'] ?? '')),
        'exclude' => trim((string)($input['exclude'] ?? '')),
        'min_amount' => trim((string)($input['min_amount'] ?? '')),
        'max_amount' => trim((string)($input['max_amount'] ?? '')),
        'status' => ($input['status'] ?? '') !== '' ? (string)($input['status'] ?? '') : '', // '' = all, '0','1','2'
    ];
    $accountFilterActive = array_key_exists('account_id', $input);
    $accountIds = [];
    if ($accountFilterActive) {
        $raw = $input['account_id'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $value) {
                if ($value === '' || $value === null) { continue; }
                if (is_numeric($value)) {
                    $id = (int)$value;
                    if ($id > 0) { $accountIds[] = $id; }
                }
            }
        } elseif ($raw !== null && $raw !== '') {
            if (is_numeric($raw)) {
                $id = (int)$raw;
                if ($id > 0) { $accountIds[] = $id; }
            }
        }
    }
    $filters['account_id'] = array_values(array_unique($accountIds));
    return [
        'filters' => $filters,
        'account_filter_active' => $accountFilterActive,
    ];
}

$filterDefaults = [
    'account_id' => [],
    'start_date' => '',
    'end_date' => '',
    'q' => '',
    'exclude' => '',
    'min_amount' => '',
    'max_amount' => '',
    'status' => '',
];

function budget_normalize_filters(array $filters, array $defaults): array
{
    $merged = array_merge($defaults, $filters);
    $ids = [];
    foreach ((array)($merged['account_id'] ?? []) as $value) {
        if ($value === '' || $value === null) { continue; }
        if (is_numeric($value)) {
            $id = (int)$value;
            if ($id > 0) { $ids[] = $id; }
        }
    }
    $merged['account_id'] = array_values(array_unique($ids));
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
        $active = (bool)($data['account_filter_active'] ?? false);
        return [
            'filters' => budget_normalize_filters($filters, $defaults),
            'account_filter_active' => $active,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function budget_save_tx_filters(PDO $pdo, string $username, array $filters, bool $active, array $defaults): void
{
    $payload = [
        'filters' => budget_normalize_filters($filters, $defaults),
        'account_filter_active' => $active,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return; }
    try {
        $stmt = $pdo->prepare('INSERT INTO user_preferences (username, tx_filters) VALUES (?, ?) ON DUPLICATE KEY UPDATE tx_filters = VALUES(tx_filters), updated_at = CURRENT_TIMESTAMP');
        $stmt->execute([$username, $json]);
    } catch (Throwable $e) {
        // ignore persistence errors
    }
}

function budget_clear_tx_filters(PDO $pdo, string $username): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM user_preferences WHERE username = ?');
        $stmt->execute([$username]);
    } catch (Throwable $e) {
        // ignore persistence errors
    }
}

$filterKeys = ['account_id', 'start_date', 'end_date', 'q', 'exclude', 'min_amount', 'max_amount', 'status'];
$hasGetFilters = false;
foreach ($filterKeys as $key) {
    if (array_key_exists($key, $_GET)) { $hasGetFilters = true; break; }
}

function budget_escape_like(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    $owner = $currentUser;

    if (isset($_GET['reset'])) {
        unset($_SESSION['tx_filters']);
        budget_clear_tx_filters($pdo, $owner);
        header('Location: transactions.php');
        exit;
    }

    $savedFilters = budget_load_tx_filters($pdo, $owner, $filterDefaults);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $parsed = budget_extract_filters($_POST);
        $parsed['filters'] = budget_normalize_filters($parsed['filters'] ?? [], $filterDefaults);
        $_SESSION['tx_filters'] = $parsed;
        budget_save_tx_filters($pdo, $owner, $parsed['filters'], (bool)($parsed['account_filter_active'] ?? false), $filterDefaults);
        $targetPage = max(1, (int)($_POST['page'] ?? 1));
        $scrollY = (int)($_POST['scroll_y'] ?? 0);
        $query = ['page' => $targetPage];
        if ($scrollY > 0) { $query['scroll_y'] = $scrollY; }
        header('Location: transactions.php?' . http_build_query($query));
        exit;
    }

    if ($hasGetFilters) {
        $parsed = budget_extract_filters($_GET);
        $parsed['filters'] = budget_normalize_filters($parsed['filters'] ?? [], $filterDefaults);
        $_SESSION['tx_filters'] = $parsed;
        budget_save_tx_filters($pdo, $owner, $parsed['filters'], (bool)($parsed['account_filter_active'] ?? false), $filterDefaults);
    } elseif (!empty($_SESSION['tx_filters'])) {
        $parsed = $_SESSION['tx_filters'];
    } elseif (!empty($savedFilters)) {
        $parsed = $savedFilters;
        $_SESSION['tx_filters'] = $parsed;
    } else {
        $parsed = ['filters' => $filterDefaults, 'account_filter_active' => false];
    }

    $filters = budget_normalize_filters($parsed['filters'] ?? [], $filterDefaults);
    $hasAccountFilter = (bool)($parsed['account_filter_active'] ?? false);
    $accountIds = $filters['account_id'] ?? [];

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
          <a class="btn btn-outline-secondary btn-sm" href="transactions.php?reset=1">Reset</a>
          <div class="btn-group" role="group" aria-label="Pagination">
            <a class="btn btn-outline-secondary btn-sm<?= $hasPrev ? '' : ' disabled' ?>" href="<?= $hasPrev ? h('transactions.php?' . http_build_query(['page' => $page - 1])) : '#' ?>">« Prev</a>
            <a class="btn btn-outline-secondary btn-sm<?= $hasNext ? '' : ' disabled' ?>" href="<?= $hasNext ? h('transactions.php?' . http_build_query(['page' => $page + 1])) : '#' ?>">Next »</a>
          </div>
        </div>
      </div>

      <form id="txFilterForm" method="post" class="table-responsive" data-page="<?= (int)$page ?>">
        <input type="hidden" name="page" value="<?= (int)$page ?>">
        <input type="hidden" name="scroll_y" value="<?= h((string)($_GET['scroll_y'] ?? '')) ?>">
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
                  <input type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" maxlength="10" class="form-control form-control-sm" name="start_date" value="<?= h($filters['start_date']) ?>" placeholder="YYYY-MM-DD" title="YYYY-MM-DD">
                  <input type="text" inputmode="numeric" pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}" maxlength="10" class="form-control form-control-sm" name="end_date" value="<?= h($filters['end_date']) ?>" placeholder="YYYY-MM-DD" title="YYYY-MM-DD">
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
              $accountId = (int)($r['account_id'] ?? 0);
            ?>
              <tr>
                <td><?= h((string)$r['date']) ?></td>
                <td><?= h((string)($r['account_name'] ?? '')) ?></td>
                <td><?= h($descRaw) ?></td>
                <td class="text-end <?= $amtClass ?>">$<?= $amtFmt ?></td>
                <td class="text-center"><?= h($statusLabel) ?></td>
                <td class="text-end">
                  <div class="dropdown">
                    <button class="btn btn-sm border-0 text-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Row actions">
                      <i class="bi bi-three-dots-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li>
                        <a class="dropdown-item tx-exclude-action<?= $descAttr === '' ? ' disabled' : '' ?>" href="#" data-mode="exact" data-desc="<?= $descAttrSafe ?>" <?= $descAttr === '' ? 'aria-disabled="true"' : '' ?>>Exclude exact description</a>
                      </li>
                      <li>
                        <a class="dropdown-item tx-exclude-action<?= $descAttr === '' ? ' disabled' : '' ?>" href="#" data-mode="contains" data-desc="<?= $descAttrSafe ?>" <?= $descAttr === '' ? 'aria-disabled="true"' : '' ?>>Exclude descriptions containing this</a>
                      </li>
                      <li><hr class="dropdown-divider"></li>
                      <li>
                        <a class="dropdown-item tx-account-deselect" href="#" data-account-id="<?= $accountId ?>">Deselect this account</a>
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
          <a class="btn btn-outline-secondary<?= $hasPrev ? '' : ' disabled' ?>" href="<?= $hasPrev ? h('transactions.php?' . http_build_query(['page' => $page - 1])) : '#' ?>">« Prev</a>
          <a class="btn btn-outline-secondary<?= $hasNext ? '' : ' disabled' ?>" href="<?= $hasNext ? h('transactions.php?' . http_build_query(['page' => $page + 1])) : '#' ?>">Next »</a>
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
        const scrollField = form?.querySelector('[name="scroll_y"]');
        if (!form) return;

        const normalize = (value) => value.replace(/\s+/g, ' ').trim();

        const scrollParam = new URLSearchParams(window.location.search).get('scroll_y');
        const scrollTarget = scrollParam ? parseInt(scrollParam, 10) : 0;
        if (scrollTarget > 0) {
          requestAnimationFrame(() => {
            window.scrollTo(0, scrollTarget);
          });
        }

        form.addEventListener('submit', () => {
          if (scrollField) scrollField.value = String(window.scrollY || 0);
        });

        if (window.bootstrap?.Dropdown) {
          document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach((toggle) => {
            window.bootstrap.Dropdown.getOrCreateInstance(toggle);
          });
        } else {
          document.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-bs-toggle="dropdown"]');
            const openMenus = document.querySelectorAll('.dropdown-menu.show');
            if (!toggle) {
              openMenus.forEach((menu) => menu.classList.remove('show'));
              return;
            }
            event.preventDefault();
            const parent = toggle.closest('.dropdown');
            const menu = parent ? parent.querySelector('.dropdown-menu') : null;
            if (!menu) return;
            const shouldOpen = !menu.classList.contains('show');
            openMenus.forEach((m) => m.classList.remove('show'));
            if (shouldOpen) menu.classList.add('show');
          });
        }

        document.addEventListener('click', (event) => {
          const action = event.target.closest('.tx-exclude-action');
          if (!action) return;
          if (!excludeField) return;
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
          excludeField.value = Array.from(existing)
            .sort((a, b) => a.localeCompare(b))
            .join('\n');
          if (scrollField) scrollField.value = String(window.scrollY || 0);
          form.submit();
        });

        document.addEventListener('click', (event) => {
          const action = event.target.closest('.tx-account-deselect');
          if (!action) return;
          event.preventDefault();
          if (!accountSelect) return;
          const id = action.dataset.accountId;
          if (!id) return;
          for (const option of accountSelect.options) {
            if (option.value === id) {
              option.selected = false;
              break;
            }
          }
          if (scrollField) scrollField.value = String(window.scrollY || 0);
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
