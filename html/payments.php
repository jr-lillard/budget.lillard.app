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

$pageTitle = 'Payments';
$error = '';
$rows = [];
$totalAmount = 0.0;
$totalCount = 0;
$postedTotalAmount = 0.0;
$postedTotalCount = 0;

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    budget_ensure_transaction_date_columns($pdo);
    $owner = budget_canonical_user((string)$_SESSION['username']);
    $limit = 100; // show more by default for payments
    $filterAccountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

    // Month selection (YYYY-MM), defaults to current month
    $rawMonth = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
    $monthParam = preg_match('/^\d{4}-\d{2}$/', $rawMonth) ? $rawMonth : date('Y-m');
    $startDate = $monthParam . '-01';
    $startObj = date_create_immutable($startDate) ?: new DateTimeImmutable(date('Y-m-01'));
    $monthLabel = $startObj->format('F Y');
    $prevMonth = $startObj->modify('first day of previous month')->format('Y-m');
    $nextMonth = $startObj->modify('first day of next month')->format('Y-m');
    // Current month and year helpers
    $currentMonthParam = date('Y-m');
    $isCurrentMonth = ($monthParam === $currentMonthParam);
    $selectedYear = (int)$startObj->format('Y');
    $currentYear = (int)date('Y');
    $yearStart = sprintf('%04d-01-01', $selectedYear);
    if ($selectedYear < $currentYear) {
        $yearEnd = sprintf('%04d-01-01', $selectedYear + 1);
        $monthsInScope = 12;
    } else {
        $yearEnd = date('Y-m-01', strtotime('first day of next month'));
        $monthsInScope = (int)date('n');
    }
    // Previous year window (always full year)
    $prevYearNum = $selectedYear - 1;
    $prevYearStart = sprintf('%04d-01-01', $prevYearNum);
    $prevYearEnd = sprintf('%04d-01-01', $prevYearNum + 1);
    $prevMonthsInScope = 12;

    // Base query: only transactions where description exactly 'Payment' within selected month
    $sql = 'SELECT t.id, t.`date`, t.amount, t.description, t.check_no, t.posted, t.status, t.updated_at_source,
                   t.account_id, t.initiated_date, t.mailed_date, t.settled_date, a.name AS account_name
            FROM transactions t
            LEFT JOIN accounts a ON a.id = t.account_id
            WHERE t.owner = ?
              AND t.description = ?
              AND t.`date` >= ? AND t.`date` < DATE_ADD(?, INTERVAL 1 MONTH)';
    $params = [$owner, 'Payment', $startDate, $startDate];
    if ($filterAccountId > 0) { $sql .= ' AND t.account_id = ?'; $params[] = $filterAccountId; }
    $sql .= ' ORDER BY t.posted ASC, t.`date` DESC, t.updated_at DESC LIMIT ?';
    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Totals (not limited) for payments in selected month
    $sumSql = 'SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
               FROM transactions t
               WHERE t.owner = ?
                 AND t.description = ?
                 AND t.`date` >= ? AND t.`date` < DATE_ADD(?, INTERVAL 1 MONTH)';
    $sumParams = [$owner, 'Payment', $startDate, $startDate];
    if ($filterAccountId > 0) { $sumSql .= ' AND t.account_id = ?'; $sumParams[] = $filterAccountId; }
    $sumStmt = $pdo->prepare($sumSql);
    $sumStmt->execute($sumParams);
    $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
    $totalAmount = (float)($sumRow['total'] ?? 0);
    $totalCount = (int)($sumRow['cnt'] ?? 0);

    // Posted-only totals for selected month
    $postedSql = 'SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
                  FROM transactions t
                  WHERE t.owner = ? AND t.description = ? AND t.posted = 1
                    AND t.`date` >= ? AND t.`date` < DATE_ADD(?, INTERVAL 1 MONTH)';
    $postedParams = [$owner, 'Payment', $startDate, $startDate];
    if ($filterAccountId > 0) { $postedSql .= ' AND t.account_id = ?'; $postedParams[] = $filterAccountId; }
    $postedStmt = $pdo->prepare($postedSql);
    $postedStmt->execute($postedParams);
    $postedRow = $postedStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
    $postedTotalAmount = (float)($postedRow['total'] ?? 0);
    $postedTotalCount = (int)($postedRow['cnt'] ?? 0);

    // Year totals and monthly average for the selected year (current year is YTD)
    $yearSql = 'SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
                FROM transactions t
                WHERE t.owner = ? AND t.description = ? AND t.`date` >= ? AND t.`date` < ?';
    $yearParams = [$owner, 'Payment', $yearStart, $yearEnd];
    if ($filterAccountId > 0) { $yearSql .= ' AND t.account_id = ?'; $yearParams[] = $filterAccountId; }
    $yearStmt = $pdo->prepare($yearSql);
    $yearStmt->execute($yearParams);
    $yearRow = $yearStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
    $yearTotalAmount = (float)($yearRow['total'] ?? 0);
    $yearTotalCount = (int)($yearRow['cnt'] ?? 0);
    $yearMonthlyAvg = $monthsInScope > 0 ? ($yearTotalAmount / $monthsInScope) : 0.0;

    // Previous year totals and average
    $prevYearSql = 'SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt
                    FROM transactions t
                    WHERE t.owner = ? AND t.description = ? AND t.`date` >= ? AND t.`date` < ?';
    $prevYearParams = [$owner, 'Payment', $prevYearStart, $prevYearEnd];
    if ($filterAccountId > 0) { $prevYearSql .= ' AND t.account_id = ?'; $prevYearParams[] = $filterAccountId; }
    $prevYearStmt = $pdo->prepare($prevYearSql);
    $prevYearStmt->execute($prevYearParams);
    $prevYearRow = $prevYearStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
    $prevYearTotalAmount = (float)($prevYearRow['total'] ?? 0);
    $prevYearTotalCount = (int)($prevYearRow['cnt'] ?? 0);
    $prevYearMonthlyAvg = $prevMonthsInScope > 0 ? ($prevYearTotalAmount / $prevMonthsInScope) : 0.0;

    // Accounts for filter (last 6 months of activity to be generous)
    $accSql = "SELECT DISTINCT a.id, a.name
               FROM accounts a
               JOIN transactions t ON t.account_id = a.id
               WHERE t.owner = :owner
                 AND t.`date` >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
               ORDER BY a.name ASC";
    $accStmt = $pdo->prepare($accSql);
    $accStmt->execute([':owner' => $owner]);
    $accPairs = $accStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    if ($filterAccountId > 0 && !isset($accPairs[$filterAccountId])) {
        $nm = $pdo->prepare('SELECT name FROM accounts WHERE id = ?');
        $nm->execute([$filterAccountId]);
        $name = $nm->fetchColumn();
        if ($name !== false) { $accPairs[$filterAccountId] = (string)$name; }
    }
    // Names list for modal account select
    $accounts = array_values($accPairs);

    // Descriptions from last 6 months for autocomplete
    $descSql = "SELECT DISTINCT description FROM transactions
                WHERE owner = ?
                  AND description IS NOT NULL AND description <> ''
                  AND `date` >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                ORDER BY description ASC LIMIT 200";
    $descStmt = $pdo->prepare($descSql);
    $descStmt->execute([$owner]);
    $descriptions = $descStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $error = 'Unable to load payments.';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  </head>
  <body>
    <nav class="navbar bg-body-tertiary sticky-top">
      <div class="container-fluid position-relative">
        <a class="btn btn-outline-secondary btn-sm" href="index.php">← Transactions</a>
        <span class="navbar-brand mx-auto">Payments</span>
        <div class="position-absolute end-0 top-50 translate-middle-y d-flex align-items-center gap-2">
          <button type="button" class="btn btn-sm btn-success" id="addTxBtn">+ Add Payment</button>
          <a class="btn btn-sm btn-outline-secondary" href="reminders.php">Reminders</a>
          <button class="btn btn-sm border-0 text-secondary" type="button" data-bs-toggle="offcanvas" data-bs-target="#userMenu" aria-controls="userMenu" aria-label="Account menu">
            <i class="bi bi-list fs-4"></i>
          </button>
        </div>
      </div>
    </nav>
    <div class="offcanvas offcanvas-end" tabindex="-1" id="userMenu" aria-labelledby="userMenuLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="userMenuLabel">Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body">
        <p class="text-body-secondary small mb-3">Signed in as<br><strong><?= htmlspecialchars($currentUser) ?></strong></p>
        <a class="btn btn-outline-secondary w-100" href="index.php?logout=1">Logout</a>
      </div>
    </div>

    <main class="container my-4">
      <div class="d-flex align-items-center justify-content-between mb-2 gap-2 flex-wrap">
        <?php
          $prevUrl = 'payments.php?month=' . urlencode($prevMonth) . ($filterAccountId ? ('&account_id=' . (int)$filterAccountId) : '');
          $nextUrl = 'payments.php?month=' . urlencode($nextMonth) . ($filterAccountId ? ('&account_id=' . (int)$filterAccountId) : '');
          $currentUrl = 'payments.php?month=' . urlencode($currentMonthParam) . ($filterAccountId ? ('&account_id=' . (int)$filterAccountId) : '');
          $allowNext = ($monthParam < $currentMonthParam);
        ?>
        <div class="d-flex align-items-center gap-2">
          <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($prevUrl) ?>" title="Previous month">←</a>
          <span class="text-body-secondary">Payments for</span>
          <strong><?= htmlspecialchars($monthLabel) ?></strong>
          <?php if ($allowNext): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($nextUrl) ?>" title="Next month">→</a>
          <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Already at current month">→</button>
          <?php endif; ?>
          <?php if (!$isCurrentMonth): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($currentUrl) ?>" title="Jump to current month">Current Month</a>
          <?php endif; ?>
        </div>
        <form class="d-flex align-items-center gap-2" method="get" action="">
          <input type="hidden" name="month" value="<?= htmlspecialchars($monthParam) ?>">
          <label for="filterAccount" class="form-label mb-0">Account</label>
          <select id="filterAccount" name="account_id" class="form-select form-select-sm" style="min-width: 240px;">
            <option value="">All accounts</option>
            <?php if (!empty($accPairs)) foreach ($accPairs as $aid => $aname): ?>
              <option value="<?= (int)$aid ?>" <?= (isset($filterAccountId) && (int)$filterAccountId === (int)$aid) ? 'selected' : '' ?>><?= htmlspecialchars((string)$aname) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-primary">Filter</button>
          <?php if (!empty($_GET)): ?>
            <a class="btn btn-sm btn-outline-secondary" href="payments.php?month=<?= htmlspecialchars(date('Y-m')) ?>">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="mb-3">
        <?php
          $sumClass = $totalAmount < 0 ? 'text-danger' : 'text-success';
          $sumFmt = number_format($totalAmount, 2);
          $postedClass = $postedTotalAmount < 0 ? 'text-danger' : 'text-success';
          $postedFmt = number_format($postedTotalAmount, 2);
          $yearClass = $yearTotalAmount < 0 ? 'text-danger' : 'text-success';
          $yearFmt = number_format($yearTotalAmount, 2);
          $avgClass = $yearMonthlyAvg < 0 ? 'text-danger' : 'text-success';
          $avgFmt = number_format($yearMonthlyAvg, 2);
          $prevYearClass = $prevYearTotalAmount < 0 ? 'text-danger' : 'text-success';
          $prevYearFmt = number_format($prevYearTotalAmount, 2);
          $prevAvgClass = $prevYearMonthlyAvg < 0 ? 'text-danger' : 'text-success';
          $prevAvgFmt = number_format($prevYearMonthlyAvg, 2);
        ?>
        <span class="text-body-secondary">Month total<?= isset($filterAccountId) && $filterAccountId ? ' (filtered by account)' : '' ?>:</span>
        <strong class="<?= $sumClass ?>">$<?= $sumFmt ?></strong>
        <span class="text-body-secondary ms-2">(<?= (int)$totalCount ?> transactions)</span>
        <span class="text-body-secondary ms-3">Posted total:</span>
        <strong class="<?= $postedClass ?>">$<?= $postedFmt ?></strong>
        <span class="text-body-secondary ms-2">(<?= (int)$postedTotalCount ?> posted)</span>
        <br>
        <span class="text-body-secondary">Year total (<?= (int)$selectedYear ?>):</span>
        <strong class="<?= $yearClass ?>">$<?= $yearFmt ?></strong>
        <span class="text-body-secondary ms-3">Monthly average:</span>
        <strong class="<?= $avgClass ?>">$<?= $avgFmt ?></strong>
        <br>
        <span class="text-body-secondary">Previous year total (<?= (int)$prevYearNum ?>):</span>
        <strong class="<?= $prevYearClass ?>">$<?= $prevYearFmt ?></strong>
        <span class="text-body-secondary ms-3">Monthly average:</span>
        <strong class="<?= $prevAvgClass ?>">$<?= $prevAvgFmt ?></strong>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php elseif (empty($rows)): ?>
        <div class="text-body-secondary">No payments to display.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover table-sm align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">Account</th>
                <th scope="col">Description</th>
                <th scope="col" class="text-end">Amount</th>
                <th scope="col">Posted</th>
                <th scope="col" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php $pendingHeaderShown = false; foreach ($rows as $row): ?>
                <?php
                  $date = $row['date'] ?? '';
                  $acct = $row['account_name'] ?? '';
                  $desc = $row['description'] ?? '';
                  $amt = $row['amount'];
                  $posted = $row['posted'] ?? '';
                  $postedBool = (string)$posted === '1' || $posted === 1;
                  $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                  $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                  $txId = (int)($row['id'] ?? 0);
                  $dateCell = $date !== '' ? htmlspecialchars((string)$date) : '';
                  if (!$postedBool && !$pendingHeaderShown) {
                    echo '<tr class="table-active"><td colspan="6">Pending</td></tr>';
                    $pendingHeaderShown = true;
                  }
                ?>
                <tr data-tx-id="<?= $txId ?>">
                  <td><?= $dateCell ?></td>
                  <td><?= htmlspecialchars((string)$acct) ?></td>
                  <td class="text-truncate" style="max-width: 480px;"><?= htmlspecialchars((string)$desc) ?></td>
                  <td class="text-end <?= $amtClass ?>"><?= $amtFmt ?></td>
                  <td class="text-center">
                    <?php if ($postedBool): ?>
                      <span class="text-success fs-4" title="Posted" aria-label="Posted">✓</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-tx"
                            data-id="<?= $txId ?>"
                            data-date="<?= htmlspecialchars((string)$date) ?>"
                            data-amount="<?= htmlspecialchars((string)$row['amount']) ?>"
                            data-account="<?= htmlspecialchars((string)$acct) ?>"
                            data-description="<?= htmlspecialchars((string)$desc) ?>"
                            data-check="<?= htmlspecialchars((string)($row['check_no'] ?? '')) ?>"
                            data-posted="<?= htmlspecialchars((string)$posted) ?>"
                            data-status="<?= htmlspecialchars((string)($row['status'] ?? '')) ?>"
                            data-initiated="<?= htmlspecialchars((string)($row['initiated_date'] ?? '')) ?>"
                            data-mailed="<?= htmlspecialchars((string)($row['mailed_date'] ?? '')) ?>"
                            data-settled="<?= htmlspecialchars((string)($row['settled_date'] ?? '')) ?>"
                            data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                      Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-tx"
                            data-id="<?= $txId ?>"
                            data-date="<?= htmlspecialchars((string)$date) ?>"
                            data-amount="<?= htmlspecialchars((string)$row['amount']) ?>"
                            data-description="<?= htmlspecialchars((string)$desc) ?>">
                      Delete
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </main>

    <!-- Edit Transaction Modal (reused) -->
    <div class="modal fade" id="editTxModal" tabindex="-1" aria-hidden="true" aria-labelledby="editTxLabel">
      <div class="modal-dialog">
        <form class="modal-content" id="editTxForm">
          <div class="modal-header">
            <h5 class="modal-title" id="editTxLabel">Edit Transaction</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="id" id="txId">
            <input type="hidden" name="account_keep" id="txAccountKeep">
            <div class="row g-2 mb-2 d-none" id="txCheckDates">
              <div class="col-6">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" id="txDate">
              </div>
              <div class="col-6">
                <label class="form-label">Amount</label>
                <input type="text" class="form-control" name="amount" id="txAmount" placeholder="0.00">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Account</label>
              <select class="form-select" name="account_select" id="txAccountSelect">
                <?php if (!empty($accounts)) foreach ($accounts as $a): ?>
                  <option value="<?= htmlspecialchars((string)$a) ?>"><?= htmlspecialchars((string)$a) ?></option>
                <?php endforeach; ?>
                <option value="__new__">Add new account…</option>
              </select>
              <input type="text" class="form-control mt-2 d-none" name="account_name_new" id="txAccountNew" placeholder="New account name">
            </div>
            <div class="mb-2">
              <label class="form-label">Description</label>
              <input type="text" class="form-control" name="description" id="txDescription" list="descriptionsList" autocomplete="off">
              <datalist id="descriptionsList">
                <?php if (!empty($descriptions)) foreach ($descriptions as $d): ?>
                  <option value="<?= htmlspecialchars((string)$d) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-4">
                <label class="form-label">Check #</label>
                <input type="text" class="form-control" name="check_no" id="txCheck">
              </div>
              <div class="col-4 d-flex align-items-end">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch" id="txPosted" name="posted">
                  <label class="form-check-label" for="txPosted">Posted</label>
                </div>
              </div>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-4">
                <label class="form-label">Initiated</label>
                <input type="date" class="form-control" name="initiated_date" id="txInitiated">
              </div>
              <div class="col-4">
                <label class="form-label">Mailed</label>
                <input type="date" class="form-control" name="mailed_date" id="txMailed">
              </div>
              <div class="col-4">
                <label class="form-label">Settled</label>
                <input type="date" class="form-control" name="settled_date" id="txSettled">
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>

    <!-- API error modal -->
    <div class="modal fade" id="apiErrorModal" tabindex="-1" aria-hidden="true" aria-labelledby="apiErrorModalLabel">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="apiErrorModalLabel">API Request Error</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="apiErrorSummary" class="mb-2"></div>
            <pre class="small bg-body-tertiary p-2 rounded overflow-auto" id="apiErrorDetails"></pre>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script src="api_error.js"></script>
    <script>
    (() => {
      const modalEl = document.getElementById('editTxModal');
      const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
      const form = document.getElementById('editTxForm');
      const g = (id) => document.getElementById(id);
      const setv = (id, v) => { const el = g(id); if (el) el.value = v || ''; };
      const checkDatesWrap = g('txCheckDates');
      const checkInput = g('txCheck');
      const postedToggle = g('txPosted');
      const dateInput = g('txDate');
      const toggleCheckDates = () => {
        if (!checkDatesWrap) return;
        const hasCheck = !!(checkInput && checkInput.value.trim());
        if (hasCheck) {
          checkDatesWrap.classList.remove('d-none');
          const initInput = g('txInitiated');
          if (initInput && !initInput.value && dateInput && dateInput.value) initInput.value = dateInput.value;
          if (postedToggle && postedToggle.checked) {
            const settledInput = g('txSettled');
            if (settledInput && !settledInput.value && dateInput && dateInput.value) settledInput.value = dateInput.value;
          }
        } else {
          checkDatesWrap.classList.add('d-none');
          setv('txInitiated','');
          setv('txMailed','');
          setv('txSettled','');
        }
      };
      checkInput && checkInput.addEventListener('input', toggleCheckDates);

      // Edit existing transaction
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-edit-tx');
        if (!btn) return;
        e.preventDefault();
        setv('txId', btn.dataset.id);
        setv('txDate', btn.dataset.date);
        setv('txAmount', btn.dataset.amount);
        // Account select / new
        const sel = g('txAccountSelect');
        const newInput = g('txAccountNew');
        if (sel) {
          const acct = btn.dataset.account || '';
          const acctId = btn.dataset.accountId || '';
          const keep = g('txAccountKeep'); if (keep) keep.value = acctId || '';
          let found = false;
          if (acct !== '') {
            for (const opt of sel.options) { if (opt.value === acct) { sel.value = acct; found = true; break; } }
          }
          if (!found) {
            if (acct !== '') {
              const opt = document.createElement('option');
              opt.value = '__current__'; opt.textContent = `Current: ${acct}`; opt.disabled = true; opt.selected = true;
              sel.insertBefore(opt, sel.firstChild);
              if (newInput) { newInput.classList.add('d-none'); newInput.value = ''; }
            } else {
              sel.value = '__new__';
              if (newInput) { newInput.classList.remove('d-none'); newInput.value = ''; }
            }
          } else if (newInput) {
            newInput.classList.add('d-none'); newInput.value = '';
          }
        }
        setv('txDescription', btn.dataset.description);
        setv('txCheck', btn.dataset.check);
        const postedEl = g('txPosted');
        if (postedEl) postedEl.checked = (btn.dataset.posted === '1');
        setv('txInitiated', btn.dataset.initiated || btn.dataset.date || '');
        setv('txMailed', btn.dataset.mailed || '');
        setv('txSettled', btn.dataset.settled || '');
        toggleCheckDates();
        if (postedEl && postedEl.checked && (!btn.dataset.settled || btn.dataset.settled === '')) {
          const fallback = btn.dataset.date || btn.dataset.initiated || '';
          if (fallback) setv('txSettled', fallback);
        }
        modal && modal.show();
      });

      // Add new payment: prefill description
      const addBtn = document.getElementById('addTxBtn');
      addBtn && addBtn.addEventListener('click', (e) => {
        e.preventDefault();
        setv('txId','');
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth()+1).padStart(2,'0');
        const dd = String(today.getDate()).padStart(2,'0');
        setv('txDate', `${yyyy}-${mm}-${dd}`);
        setv('txAmount','');
        const sel2 = g('txAccountSelect');
        const newInput2 = g('txAccountNew');
        const keep2 = g('txAccountKeep'); if (keep2) keep2.value = '';
        if (sel2) { sel2.value=''; newInput2 && (newInput2.classList.add('d-none'), newInput2.value=''); }
        setv('txDescription','Payment');
        setv('txCheck','');
        const postedEl2 = g('txPosted'); if (postedEl2) postedEl2.checked = false;
        setv('txInitiated','');
        setv('txMailed','');
        setv('txSettled','');
        toggleCheckDates();
        modal && modal.show();
      });

      // Toggle new account input
      const sel = g('txAccountSelect');
      sel && sel.addEventListener('change', () => {
        const newInput = g('txAccountNew');
        if (!newInput) return;
        if (sel.value === '__new__') newInput.classList.remove('d-none'); else { newInput.classList.add('d-none'); newInput.value=''; }
      });

      postedToggle && postedToggle.addEventListener('change', () => {
        const settled = g('txSettled');
        const dateInput = g('txDate');
        if (!settled) return;
        if (postedToggle.checked) {
          if (checkInput && checkInput.value.trim()) {
            settled.value = dateInput ? (dateInput.value || '') : settled.value;
          }
        } else {
          settled.value = '';
        }
      });

      dateInput && dateInput.addEventListener('change', () => {
        const initInput = g('txInitiated');
        const idInput = g('txId');
        if (initInput && dateInput.value && checkInput && checkInput.value.trim() && (!idInput || !idInput.value) && !initInput.value) {
          initInput.value = dateInput.value;
        }
        if (postedToggle && postedToggle.checked && checkInput && checkInput.value.trim()) {
          const settled = g('txSettled');
          if (settled) settled.value = dateInput.value || '';
        }
      });

      toggleCheckDates();

      // Save
      form && form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(form);
        const res = await fetch('transaction_save.php', { method:'POST', body: fd });
        if (!res.ok) return; // api_error.js will show modal
        try { await res.json(); } catch {}
        modal && modal.hide();
        window.location.reload();
      });

      // Delete
      document.addEventListener('click', async (e) => {
        const del = e.target.closest('.btn-delete-tx');
        if (!del) return;
        e.preventDefault();
        const id = del.dataset.id;
        if (!id) return;
        const desc = del.dataset.description || '';
        const amt = del.dataset.amount || '';
        const date = del.dataset.date || '';
        const detail = [date, desc].filter(Boolean).join(' — ');
        const msg = `Delete this transaction${detail ? `: \"${detail}\"` : ''}${amt ? ` (amount: ${amt})` : ''}?`;
        const ok = window.confirm(msg);
        if (!ok) return;
        const fd = new FormData();
        fd.append('id', id);
        const res = await fetch('transaction_delete.php', { method: 'POST', body: fd });
        if (!res.ok) return; // api_error.js will handle
        try { await res.json(); } catch {}
        window.location.reload();
      });
    })();
    </script>
  </body>
  </html>
