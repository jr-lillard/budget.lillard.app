<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';

// Attempt auto-login from persistent cookie if no active session
try { $pdo = get_mysql_connection(); auth_login_from_cookie($pdo); } catch (Throwable $e) { /* ignore */ }

// Handle logout request (clears session and persistent cookie)
if (isset($_GET['logout'])) {
    try { $pdo = $pdo ?? get_mysql_connection(); auth_clear_remember_cookie($pdo); } catch (Throwable $e) { /* ignore */ }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', !empty($params['secure']), (bool)($params['httponly'] ?? true));
    }
    session_destroy();
    header('Location: ' . (string)($_SERVER['PHP_SELF'] ?? 'index.php'));
    exit;
}

$pageTitle = 'Budget';
$sessionUser = isset($_SESSION['username']) ? budget_canonical_user((string)$_SESSION['username']) : '';
if ($sessionUser !== ($_SESSION['username'] ?? '')) {
    $_SESSION['username'] = $sessionUser;
}
$loggedIn = $sessionUser !== '';
$currentUser = $sessionUser;
$recentTx = [];
$recentError = '';

/**
 * Send a one-time login code to the given email address.
 * Returns [bool ok, string message].
 */
function budget_send_login_code(string $email, string $code): array
{
    $subject = 'Budget login code';
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $html = <<<HTML
<p>Your Budget login code is <strong style="font-size: 1.25rem;">{$safeCode}</strong>.</p>
<p>Enter this code in the Budget app within 10 minutes.</p>
HTML;
    $text = "Your Budget login code is {$code}.\nEnter this code in the Budget app within 10 minutes.\n";
    return send_mail_via_smtp2go($email, $subject, $html, $text, null);
}

/**
 * Redirect immediately, then run any queued tasks after the response is flushed.
 */
function budget_redirect(string $target, array $afterResponseTasks = []): void
{
    header('Location: ' . $target);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
    ignore_user_abort(true);
    foreach ($afterResponseTasks as $task) {
        if (is_callable($task)) {
            try {
                $task();
            } catch (Throwable $e) {
                error_log('Post-redirect task failed: ' . $e->getMessage());
            }
        }
    }
    exit;
}

$loginFlash = $_SESSION['login_flash'] ?? [];
unset($_SESSION['login_flash']);

$loginFlow = $_SESSION['login_flow'] ?? null;
$loginStep = 'email';
if (is_array($loginFlow) && isset($loginFlow['email'], $loginFlow['code_hash'], $loginFlow['expires']) && (int)$loginFlow['expires'] >= time()) {
    $loginStep = 'code';
} else {
    $loginFlow = null;
    unset($_SESSION['login_flow']);
}
$afterResponseTasks = [];
$flashMessage = trim((string)($loginFlash['message'] ?? ''));
$flashType = $loginFlash['type'] ?? '';
$emailErrorMessage = ($loginStep === 'email' && $flashType === 'danger') ? $flashMessage : '';
$codeErrorMessage = ($loginStep === 'code' && $flashType === 'danger') ? $flashMessage : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loggedIn) {
    $mode = (string)($_POST['mode'] ?? '');
    $self = (string)($_SERVER['PHP_SELF'] ?? 'index.php');

    if ($mode === 'request-code') {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Enter a valid email address.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }

        $canonicalEmail = budget_canonical_user($email);

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['login_flow'] = [
            'email' => $canonicalEmail,
            'code_hash' => hash('sha256', $code),
            'expires' => time() + 600,
            'attempts' => 0,
            'last_sent' => time(),
        ];
        unset($_SESSION['login_flash']);
        $afterResponseTasks[] = static function () use ($email, $code): void {
            [$ok, $err] = budget_send_login_code($email, $code);
            if (!$ok) {
                error_log(sprintf('Login code email failed for %s: %s', $email, (string)$err));
            }
        };
        budget_redirect($self, $afterResponseTasks);
    } elseif ($mode === 'verify-code') {
        $flow = $_SESSION['login_flow'] ?? null;
        $codeDigits = preg_replace('/[^0-9]/', '', (string)($_POST['code'] ?? ''));
        if (!is_array($flow) || !isset($flow['email'], $flow['code_hash'], $flow['expires'])) {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Request a new login code to continue.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }
        if ((int)$flow['expires'] < time()) {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'That code expired. Request a new one.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }
        if (strlen($codeDigits) !== 6) {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Enter the 6-digit code from the email.'];
            budget_redirect($self);
        }
        $expected = (string)$flow['code_hash'];
        $actual = hash('sha256', $codeDigits);
        if (!hash_equals($expected, $actual)) {
            $attempts = (int)($flow['attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                unset($_SESSION['login_flow']);
                $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Too many attempts. Request a new code.'];
            } else {
                $flow['attempts'] = $attempts;
                $_SESSION['login_flow'] = $flow;
                $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Incorrect code. Try again.'];
            }
            budget_redirect($self);
        }

        unset($_SESSION['login_flow']);
        $email = budget_canonical_user((string)$flow['email']);
        $_SESSION['username'] = $email;
        try {
            $pdo = $pdo ?? get_mysql_connection();
            auth_issue_remember_cookie($pdo, $email);
        } catch (Throwable $e) {
            // ignore remember-me errors
        }
        unset($_SESSION['login_flash']);
        budget_redirect($self);
    } elseif ($mode === 'resend-code') {
        $flow = $_SESSION['login_flow'] ?? null;
        if (!is_array($flow) || empty($flow['email'])) {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Request a new login code to continue.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }
        $email = (string)$flow['email'];
        $lastSent = (int)($flow['last_sent'] ?? 0);
        if ($lastSent > time() - 30) {
            budget_redirect($self);
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['login_flow'] = [
            'email' => $email,
            'code_hash' => hash('sha256', $code),
            'expires' => time() + 600,
            'attempts' => 0,
            'last_sent' => time(),
        ];
        unset($_SESSION['login_flash']);
        $afterResponseTasks[] = static function () use ($email, $code): void {
            [$ok, $err] = budget_send_login_code($email, $code);
            if (!$ok) {
                error_log(sprintf('Login resend failed for %s: %s', $email, (string)$err));
            }
        };
        budget_redirect($self, $afterResponseTasks);
    } elseif ($mode === 'change-email') {
        unset($_SESSION['login_flow']);
        unset($_SESSION['login_flash']);
        budget_redirect($self);
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" crossorigin="anonymous">
    <style>
      /* Ensure blank separator rows are truly transparent, overriding Bootstrap table striping/hover */
      .spacer-row,
      .spacer-row > td {
        /* Neutralize Bootstrap table variables at the cell level */
        --bs-table-bg: transparent !important;
        --bs-table-accent-bg: transparent !important;
        --bs-table-hover-bg: transparent !important;
        background-color: transparent !important;
        box-shadow: none !important; /* Bootstrap uses box-shadow overlay for striping */
      }
      .spacer-row > td {
        border: 0 !important;
        padding-top: 1rem;
        padding-bottom: 1rem;
      }
    </style>
  </head>
  <body>
    <?php if ($loggedIn): ?>
      <nav class="navbar bg-body-tertiary sticky-top">
        <div class="container-fluid position-relative">
            <a class="navbar-brand mx-auto" href="#"><?= htmlspecialchars($pageTitle) ?></a>
            <div class="position-absolute end-0 top-50 translate-middle-y d-flex align-items-center gap-2">
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
    <?php endif; ?>

    <main>
      <?php if ($loggedIn): ?>
        <?php
          try {
            $pdo = get_mysql_connection();
            // Ensure transactions.status exists (0=scheduled,1=pending,2=posted)
            try { $pdo->exec('ALTER TABLE transactions ADD COLUMN status TINYINT NULL'); } catch (Throwable $e) { /* ignore if exists */ }
            $limit = 50; // recent rows to show
            $defaultOwner = budget_default_owner();
            budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
            budget_ensure_owner_column($pdo, 'reminders', 'owner', $defaultOwner);
            budget_ensure_transaction_date_columns($pdo);
            $owner = $currentUser;
            // Remember account filter for the session
            $filterAccountId = 0;
            if (array_key_exists('account_id', $_GET)) {
              // account_id provided via querystring (may be empty for clearing)
              $filterAccountId = (int)($_GET['account_id'] ?? 0);
              $_SESSION['tx_filter_account_id'] = $filterAccountId;
            } elseif (isset($_SESSION['tx_filter_account_id'])) {
              // fall back to saved session filter if present
              $filterAccountId = (int)$_SESSION['tx_filter_account_id'];
            }

            $sql = 'SELECT t.id, t.`date`, t.amount, t.description, t.check_no, t.posted, t.status, t.updated_at_source,
                       t.account_id, t.initiated_date, t.mailed_date, t.settled_date, a.name AS account_name
                    FROM transactions t
                    LEFT JOIN accounts a ON a.id = t.account_id
                    WHERE t.owner = ?';
            $params = [$owner];
            if ($filterAccountId > 0) { $sql .= ' AND t.account_id = ?'; $params[] = $filterAccountId; }
            $sql .= ' ORDER BY COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) ASC, t.`date` DESC, t.updated_at DESC LIMIT ?';
            $params[] = $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $recentTx = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Compute total sum across all matching transactions (exclude scheduled), not limited
            $sumSql = 'SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM transactions t WHERE t.owner = ? AND COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) <> 0';
            $sumParams = [$owner];
            if ($filterAccountId > 0) { $sumSql .= ' AND t.account_id = ?'; $sumParams[] = $filterAccountId; }
            $sumStmt = $pdo->prepare($sumSql);
            $sumStmt->execute($sumParams);
            $sumRow = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
            $totalAmount = (float)($sumRow['total'] ?? 0);
            $totalCount = (int)($sumRow['cnt'] ?? 0);

            // Compute posted-only total across matching transactions (not limited)
            $postedSql = 'SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM transactions t WHERE t.owner = ? AND t.posted = 1';
            $postedParams = [$owner];
            if ($filterAccountId > 0) { $postedSql .= ' AND t.account_id = ?'; $postedParams[] = $filterAccountId; }
            $postedStmt = $pdo->prepare($postedSql);
            $postedStmt->execute($postedParams);
            $postedRow = $postedStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'cnt' => 0];
            $postedTotalAmount = (float)($postedRow['total'] ?? 0);
            $postedTotalCount = (int)($postedRow['cnt'] ?? 0);

            // Scheduled sum (for projected balance) from transactions.status = 0
            $schedSumSql = 'SELECT COALESCE(SUM(t.amount),0) AS total FROM transactions t WHERE t.owner = ? AND COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) = 0';
            $schedSumParams = [$owner];
            if ($filterAccountId > 0) { $schedSumSql .= ' AND t.account_id = ?'; $schedSumParams[] = $filterAccountId; }
            $schedSumStmt = $pdo->prepare($schedSumSql);
            $schedSumStmt->execute($schedSumParams);
            $schedTotalAmount = (float)($schedSumStmt->fetchColumn() ?: 0);

            // Accounts with activity in last 3 months (id => name)
            $accSql = "SELECT DISTINCT a.id, a.name
                       FROM accounts a
                       JOIN transactions t ON t.account_id = a.id
                       WHERE t.owner = ?
                         AND t.`date` >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                       ORDER BY a.name ASC";
            $accStmt = $pdo->prepare($accSql);
            $accStmt->execute([$owner]);
            $accPairs = $accStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            if ($filterAccountId > 0 && !isset($accPairs[$filterAccountId])) {
              $nm = $pdo->prepare('SELECT name FROM accounts WHERE id = ?');
              $nm->execute([$filterAccountId]);
              $name = $nm->fetchColumn();
              if ($name !== false) { $accPairs[$filterAccountId] = (string)$name; }
            }
            // Keep names array for modal account select
            $accounts = array_values($accPairs);

            // Descriptions (distinct) from last 3 months for autocomplete
            $descSql = "SELECT DISTINCT description FROM transactions
                        WHERE owner = ?
                          AND description IS NOT NULL AND description <> ''
                          AND `date` >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)
                        ORDER BY description ASC LIMIT 200";
            $descStmt = $pdo->prepare($descSql);
            $descStmt->execute([$owner]);
            $descriptions = $descStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
          } catch (Throwable $e) {
            $recentError = 'Unable to load recent transactions.';
          }
        ?>

        <div class="container my-4">
          <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 gap-2">
            <div class="d-flex align-items-center gap-2">
              <h2 class="h5 mb-0">Recent Transactions</h2>
              <button type="button" class="btn btn-sm btn-success" id="addTxBtn"
                data-account-name="<?= (isset($filterAccountId) && $filterAccountId > 0 && isset($accPairs[$filterAccountId])) ? htmlspecialchars((string)$accPairs[$filterAccountId]) : '' ?>">
                + Add Transaction
              </button>
              <a class="btn btn-sm btn-outline-secondary" href="reminders.php<?= ($filterAccountId>0? ('?account_id='.(int)$filterAccountId) : '') ?>">Reminders</a>
              <a class="btn btn-sm btn-outline-secondary" href="payments.php">Payments</a>
            </div>
            <form class="d-flex align-items-center gap-2" method="get" action="">
              <label for="filterAccount" class="form-label mb-0">Account</label>
              <select id="filterAccount" name="account_id" class="form-select form-select-sm" style="min-width: 240px;">
                <option value="">All accounts</option>
                <?php if (!empty($accPairs)) foreach ($accPairs as $aid => $aname): ?>
                  <option value="<?= (int)$aid ?>" <?= (isset($filterAccountId) && (int)$filterAccountId === (int)$aid) ? 'selected' : '' ?>><?= htmlspecialchars((string)$aname) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm btn-primary">Filter</button>
              <?php if ((int)$filterAccountId > 0): ?>
                <!-- Clear by explicitly submitting an empty account_id to reset the session value -->
                <a class="btn btn-sm btn-outline-secondary" href="index.php?account_id=">Clear</a>
              <?php endif; ?>
            </form>
          </div>
          <?php
            // Precompute classes and formatted values for section header totals
            $sumClass = $totalAmount < 0 ? 'text-danger' : 'text-success';
            $sumFmt = number_format($totalAmount, 2);
            $postedClass = $postedTotalAmount < 0 ? 'text-danger' : 'text-success';
            $postedFmt = number_format($postedTotalAmount, 2);
            $projectedWithSched = ($totalAmount ?? 0) + ($schedTotalAmount ?? 0);
            $projClass = $projectedWithSched < 0 ? 'text-danger' : 'text-success';
            $projFmt = number_format($projectedWithSched, 2);
          ?>
          <?php if ($recentError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($recentError) ?></div>
          <?php elseif (empty($recentTx)): ?>
            <div class="text-body-secondary">No transactions to display.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-striped table-hover table-sm align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">Date</th>
                    <th scope="col">Account</th>
                    <th scope="col">Description</th>
                    <th scope="col" class="text-end">Amount</th>
                    <th scope="col" class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    // Partition rows by status (fallback to posted for legacy rows)
                    $scheduledRows = [];
                    $pendingRows = [];
                    $postedRows = [];
                    foreach ($recentTx as $row) {
                      $status = $row['status'] ?? null;
                      $posted = $row['posted'] ?? '';
                      $statusNorm = ($status === null || $status === '') ? (($posted === 1 || (string)$posted === '1') ? 2 : 1) : (int)$status;
                      if ($statusNorm === 0) $scheduledRows[] = $row;
                      elseif ($statusNorm === 2) $postedRows[] = $row; else $pendingRows[] = $row;
                    }
                  ?>
                  <?php if (!empty($scheduledRows)): ?>
                    <tr class="table-active">
                      <td>Scheduled</td>
                      <td></td>
                      <td></td>
                      <td class="text-end"><strong class="<?= $projClass ?>">$<?= $projFmt ?></strong></td>
                      <td class="text-end">
                        <button class="btn btn-sm btn-outline-success tx-header-add" type="button" data-status="0" title="New scheduled transaction">
                          <i class="bi bi-plus-lg"></i>
                        </button>
                      </td>
                    </tr>
                    <?php $lastSchedDate = null; foreach ($scheduledRows as $row): ?>
                      <?php
                        $date = $row['date'] ?? '';
                        $acct = $row['account_name'] ?? '';
                        $desc = $row['description'] ?? '';
                        $amt = $row['amount'];
                        $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                        $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                        $txId = (int)($row['id'] ?? 0);
                      ?>
                      <?php
                        $dateCell = '';
                        if ($date !== $lastSchedDate) { $dateCell = htmlspecialchars((string)$date); $lastSchedDate = $date; }
                      ?>
                      <tr data-id="<?= $txId ?>"
                          data-date="<?= htmlspecialchars((string)$date) ?>"
                          data-amount="<?= htmlspecialchars((string)$row['amount']) ?>"
                          data-account="<?= htmlspecialchars((string)$acct) ?>"
                          data-description="<?= htmlspecialchars((string)$desc) ?>"
                          data-check="<?= htmlspecialchars((string)($row['check_no'] ?? '')) ?>"
                          data-initiated="<?= htmlspecialchars((string)($row['initiated_date'] ?? '')) ?>"
                          data-mailed="<?= htmlspecialchars((string)($row['mailed_date'] ?? '')) ?>"
                          data-settled="<?= htmlspecialchars((string)($row['settled_date'] ?? '')) ?>"
                          data-initiated="<?= htmlspecialchars((string)($row['initiated_date'] ?? '')) ?>"
                          data-mailed="<?= htmlspecialchars((string)($row['mailed_date'] ?? '')) ?>"
                          data-settled="<?= htmlspecialchars((string)($row['settled_date'] ?? '')) ?>"
                          data-status="0"
                          data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                        <td class="tx-click-edit" role="button"><?= $dateCell ?></td>
                        <td class="tx-click-edit" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                        <td class="text-truncate tx-click-edit" role="button" style="max-width: 480px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                        <td class="text-end <?= $amtClass ?> tx-click-edit" role="button"><?= $amtFmt ?></td>
                        <td class="text-end">
                          <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">
                              <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                              <li><a class="dropdown-item tx-menu-edit" href="#">Edit</a></li>
                              <li><a class="dropdown-item tx-menu-set-status" href="#" data-status="1">Mark Pending</a></li>
                              <li><a class="dropdown-item tx-menu-set-status" href="#" data-status="2">Mark Posted</a></li>
                              <li><hr class="dropdown-divider"></li>
                              <li><a class="dropdown-item tx-menu-delete text-danger" href="#">Delete</a></li>
                            </ul>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  <?php if (!empty($pendingRows) && !empty($scheduledRows)): ?>
                    <tr class="spacer-row"><td colspan="5"></td></tr>
                    <tr class="spacer-row"><td colspan="5"></td></tr>
                  <?php endif; ?>
                  <?php if (!empty($pendingRows)): ?>
                    <tr class="table-active">
                      <td>Pending</td>
                      <td></td>
                      <td></td>
                      <td class="text-end"><strong class="<?= $sumClass ?>">$<?= $sumFmt ?></strong></td>
                      <td class="text-end">
                        <button class="btn btn-sm btn-outline-success tx-header-add" type="button" data-status="1" title="New pending transaction">
                          <i class="bi bi-plus-lg"></i>
                        </button>
                      </td>
                    </tr>
                    <?php $lastPendDate = null; foreach ($pendingRows as $row): ?>
                      <?php
                        $date = $row['date'] ?? '';
                        $acct = $row['account_name'] ?? '';
                        $desc = $row['description'] ?? '';
                        $amt = $row['amount'];
                        $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                        $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                        $txId = (int)($row['id'] ?? 0);
                      ?>
                      <?php
                        $dateCell = '';
                        if ($date !== $lastPendDate) { $dateCell = htmlspecialchars((string)$date); $lastPendDate = $date; }
                      ?>
                      <tr data-id="<?= $txId ?>"
                          data-date="<?= htmlspecialchars((string)$date) ?>"
                          data-amount="<?= htmlspecialchars((string)$row['amount']) ?>"
                          data-account="<?= htmlspecialchars((string)$acct) ?>"
                          data-description="<?= htmlspecialchars((string)$desc) ?>"
                          data-check="<?= htmlspecialchars((string)($row['check_no'] ?? '')) ?>"
                          data-status="1"
                          data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                        <td class="tx-click-edit" role="button"><?= $dateCell ?></td>
                        <td class="tx-click-edit" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                        <td class="text-truncate tx-click-edit" role="button" style="max-width: 480px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                        <td class="text-end <?= $amtClass ?> tx-click-edit" role="button"><?= $amtFmt ?></td>
                        <td class="text-end">
                          <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">
                              <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                              <li><a class="dropdown-item tx-menu-edit" href="#">Edit</a></li>
                              <li><a class="dropdown-item tx-menu-set-status" href="#" data-status="2">Mark Posted</a></li>
                              <li><hr class="dropdown-divider"></li>
                              <li><a class="dropdown-item tx-menu-delete text-danger" href="#">Delete</a></li>
                            </ul>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  <?php if (!empty($postedRows) && (!empty($scheduledRows) || !empty($pendingRows))): ?>
                    <tr class="spacer-row"><td colspan="5"></td></tr>
                    <tr class="spacer-row"><td colspan="5"></td></tr>
                  <?php endif; ?>
                  <?php if (!empty($postedRows)):
                    $currentDate = null;
                    $postedSummaryShown = false;
                    foreach ($postedRows as $row):
                      $date = $row['date'] ?? '';
                      $acct = $row['account_name'] ?? '';
                      $desc = $row['description'] ?? '';
                      $amt = $row['amount'];
                      $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                      $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                      $txId = (int)($row['id'] ?? 0);
                      $isNewGroup = ($date !== $currentDate);
                      $dateCell = '';
                      if ($isNewGroup) {
                        $label = $date;
                        $ts = strtotime((string)$date);
                        if ($ts !== false) { $label = date('l, F j, Y', $ts); }
                        echo '<tr class="table-active">'
                           . '<td>' . htmlspecialchars($label) . '</td>'
                           . '<td></td>'
                           . '<td></td>'
                           . '<td class="text-end">' . (!$postedSummaryShown ? ('<strong class="' . $postedClass . '">$' . $postedFmt . '</strong>') : '') . '</td>'
                           . '<td class="text-end">'
                           .   '<button class="btn btn-sm btn-outline-success tx-header-add" type="button" data-status="2" data-date="' . htmlspecialchars((string)$date) . '" title="New posted transaction on this date">'
                           .     '<i class="bi bi-plus-lg"></i>'
                           .   '</button>'
                           . '</td>'
                           . '</tr>';
                        $postedSummaryShown = true;
                        $currentDate = $date;
                        $dateCell = htmlspecialchars((string)$date);
                      }
                  ?>
                    <tr data-id="<?= $txId ?>"
                        data-date="<?= htmlspecialchars((string)$date) ?>"
                        data-amount="<?= htmlspecialchars((string)$row['amount']) ?>"
                        data-account="<?= htmlspecialchars((string)$acct) ?>"
                        data-description="<?= htmlspecialchars((string)$desc) ?>"
                        data-check="<?= htmlspecialchars((string)($row['check_no'] ?? '')) ?>"
                        data-initiated="<?= htmlspecialchars((string)($row['initiated_date'] ?? '')) ?>"
                        data-mailed="<?= htmlspecialchars((string)($row['mailed_date'] ?? '')) ?>"
                        data-settled="<?= htmlspecialchars((string)($row['settled_date'] ?? '')) ?>"
                        data-status="2"
                        data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                      <td class="tx-click-edit" role="button"><?= $dateCell ?></td>
                      <td class="tx-click-edit" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                      <td class="text-truncate tx-click-edit" role="button" style="max-width: 480px;"><?= htmlspecialchars((string)$desc) ?></td>
                      <td class="text-end <?= $amtClass ?> tx-click-edit" role="button"><?= $amtFmt ?></td>
                      <td class="text-end">
                        <div class="dropdown">
                          <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">
                            <i class="bi bi-three-dots"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item tx-menu-edit" href="#">Edit</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item tx-menu-delete text-danger" href="#">Delete</a></li>
                          </ul>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="min-vh-100 d-flex align-items-center justify-content-center">
          <div class="w-100" style="max-width: 360px;">
            <?php if ($loginStep === 'email'): ?>
              <form method="post" class="w-100" novalidate>
                <input type="hidden" name="mode" value="request-code">
                <input type="email"
                       name="email"
                       class="form-control text-center<?= $emailErrorMessage !== '' ? ' is-invalid' : '' ?>"
                       aria-label="Email address"
                       autocomplete="email"
                       autocorrect="off"
                       autocapitalize="none"
                       spellcheck="false"
                       required
                       autofocus<?= $emailErrorMessage !== '' ? ' aria-invalid="true" title="' . htmlspecialchars($emailErrorMessage, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <button type="submit" class="visually-hidden" aria-hidden="true">Submit</button>
              </form>
            <?php else: ?>
              <form method="post" class="w-100" novalidate id="codeForm">
                <input type="hidden" name="mode" value="verify-code">
                <input type="text"
                       id="codeInput"
                       name="code"
                       class="form-control text-center<?= $codeErrorMessage !== '' ? ' is-invalid' : '' ?>"
                       aria-label="6-digit code"
                       inputmode="numeric"
                       pattern="[0-9]{6}"
                       autocomplete="one-time-code"
                       maxlength="6"
                       required
                       autofocus<?= $codeErrorMessage !== '' ? ' aria-invalid="true" title="' . htmlspecialchars($codeErrorMessage, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <button type="submit" class="visually-hidden" aria-hidden="true">Submit</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </main>

    <?php if (!$loggedIn && $loginStep === 'code'): ?>
      <script>
      (() => {
        const form = document.getElementById('codeForm');
        const input = document.getElementById('codeInput');
        if (!form || !input) return;
        const submitHiddenPost = (mode) => {
          const body = new URLSearchParams({ mode });
          fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
          }).then(() => window.location.reload());
        };
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            submitHiddenPost('change-email');
          }
          if ((event.key === 'Enter' && (event.metaKey || event.ctrlKey))) {
            event.preventDefault();
            submitHiddenPost('resend-code');
          }
        });
      })();
      </script>
    <?php endif; ?>

    <?php if ($loggedIn): ?>
      <!-- Edit Transaction Modal -->
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
              <div class="row g-2 mb-2">
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
                <div class="col-4">
                  <label class="form-label">Status</label>
                  <select class="form-select" name="status" id="txStatus">
                    <option value="0">Scheduled</option>
                    <option value="1" selected>Pending</option>
                    <option value="2">Posted</option>
                  </select>
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
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php if ($loggedIn): ?>
      <script src="api_error.js"></script>
      <script>
      (() => {
        const modalEl = document.getElementById('editTxModal');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
        const form = document.getElementById('editTxForm');
        const g = (id) => document.getElementById(id);
        const setv = (id, v) => { const el = g(id); if (el) el.value = v || ''; };
        function openEditFromRow(row){
          if (!row) return;
          setv('txId', row.dataset.id || '');
          setv('txDate', row.dataset.date || '');
          setv('txAmount', row.dataset.amount || '');
          // Account select / new
          const sel = g('txAccountSelect');
          const newInput = g('txAccountNew');
          if (sel) {
            const acct = row.dataset.account || '';
            const acctId = row.dataset.accountId || '';
            const keep = g('txAccountKeep'); if (keep) keep.value = acctId || '';
            let found = false;
            if (acct !== '') {
              for (const opt of sel.options) { if (opt.value === acct) { sel.value = acct; found = true; break; } }
            }
            if (!found) {
              if (acct !== '') {
                const opt = document.createElement('option');
                opt.value='__current__'; opt.textContent=`Current: ${acct}`; opt.disabled=true; opt.selected=true;
                sel.insertBefore(opt, sel.firstChild);
                if (newInput) { newInput.classList.add('d-none'); newInput.value=''; }
              } else {
                sel.value='__new__';
                if (newInput) { newInput.classList.remove('d-none'); newInput.value=''; }
              }
            } else if (newInput) {
              newInput.classList.add('d-none'); newInput.value='';
            }
          }
          setv('txDescription', row.dataset.description || '');
          setv('txCheck', row.dataset.check || '');
          const initDate = row.dataset.initiated || row.dataset.date || '';
          setv('txInitiated', initDate);
          setv('txMailed', row.dataset.mailed || '');
          setv('txSettled', row.dataset.settled || '');
          const statusVal = row.dataset.status ?? '1';
          const statusEl = g('txStatus'); if (statusEl) statusEl.value = statusVal;
          if ((!row.dataset.settled || row.dataset.settled === '') && statusVal === '2') {
            setv('txSettled', row.dataset.date || initDate);
          }
          modal && modal.show();
        }
        // Clickable cells open edit
        document.addEventListener('click', (e) => {
          const cell = e.target.closest('.tx-click-edit');
          if (!cell) return;
          const row = cell.closest('tr');
          if (!row) return;
          e.preventDefault();
          openEditFromRow(row);
        });
        // Menu: Edit
        document.addEventListener('click', (e) => {
          const a = e.target.closest('.tx-menu-edit');
          if (!a) return;
          const row = a.closest('tr');
          if (!row) return;
          e.preventDefault();
          openEditFromRow(row);
        });
        // Add new transaction
        const addBtn = document.getElementById('addTxBtn');
        addBtn && addBtn.addEventListener('click', (e) => {
          e.preventDefault();
          // reset form
          setv('txId','');
          const today = new Date();
          const yyyy = today.getFullYear();
          const mm = String(today.getMonth()+1).padStart(2,'0');
          const dd = String(today.getDate()).padStart(2,'0');
          setv('txDate', `${yyyy}-${mm}-${dd}`);
          setv('txAmount','');
          // Set account to current filter if present
          const acctName = addBtn.dataset.accountName || '';
          const sel2 = g('txAccountSelect');
          const newInput2 = g('txAccountNew');
          const keep2 = g('txAccountKeep'); if (keep2) keep2.value = '';
          if (sel2) {
            let found=false;
            if (acctName) { for (const opt of sel2.options){ if(opt.value===acctName){ sel2.value=acctName; found=true; break; } } }
            if (!found){ sel2.value='__new__'; newInput2 && (newInput2.classList.remove('d-none'), newInput2.value=acctName); }
            else { newInput2 && (newInput2.classList.add('d-none'), newInput2.value=''); }
          }
          setv('txDescription','');
          setv('txCheck','');
          const todaysDate = g('txDate') ? g('txDate').value : '';
          setv('txInitiated', todaysDate);
          setv('txMailed','');
          setv('txSettled','');
          const statusEl2 = g('txStatus'); if (statusEl2) statusEl2.value = '1';
          modal && modal.show();
        });
        // Toggle new account input visibility on select change
        const sel = g('txAccountSelect');
        sel && sel.addEventListener('change', () => {
          const newInput = g('txAccountNew');
          if (!newInput) return;
          if (sel.value === '__new__') newInput.classList.remove('d-none'); else { newInput.classList.add('d-none'); newInput.value=''; }
        });

        const statusControl = g('txStatus');
        statusControl && statusControl.addEventListener('change', () => {
          const settled = g('txSettled');
          const dateInput = g('txDate');
          if (!settled) return;
          if (statusControl.value === '2') {
            if (!settled.value && dateInput) settled.value = dateInput.value || '';
          } else {
            settled.value = '';
          }
        });

        const dateInput = g('txDate');
        dateInput && dateInput.addEventListener('change', () => {
          const initInput = g('txInitiated');
          const idInput = g('txId');
          if (initInput && dateInput.value && (!idInput || !idInput.value) && !initInput.value) {
            initInput.value = dateInput.value;
          }
          if (statusControl && statusControl.value === '2') {
            const settled = g('txSettled');
            if (settled) settled.value = dateInput.value || '';
          }
        });

        // Header add buttons (Scheduled/Pending/Posted-date)
        document.addEventListener('click', (e) => {
          const btn = e.target.closest('.tx-header-add');
          if (!btn) return;
          e.preventDefault();
          const status = btn.dataset.status || '1';
          const dateOverride = btn.dataset.date || '';
          // Reset form
          setv('txId','');
          // Choose date: header-provided date (for posted) or today
          if (dateOverride) {
            setv('txDate', dateOverride);
          } else {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm = String(today.getMonth()+1).padStart(2,'0');
            const dd = String(today.getDate()).padStart(2,'0');
            setv('txDate', `${yyyy}-${mm}-${dd}`);
          }
          setv('txAmount','');
          // Set account to current filter if present (from main Add button's dataset)
          const addBtn = document.getElementById('addTxBtn');
          const acctName = addBtn?.dataset.accountName || '';
          const sel2 = g('txAccountSelect');
          const newInput2 = g('txAccountNew');
          const keep2 = g('txAccountKeep'); if (keep2) keep2.value = '';
          if (sel2) {
            let found=false;
            if (acctName) { for (const opt of sel2.options){ if(opt.value===acctName){ sel2.value=acctName; found=true; break; } } }
            if (!found){ sel2.value='__new__'; newInput2 && (newInput2.classList.remove('d-none'), newInput2.value=acctName); }
            else { newInput2 && (newInput2.classList.add('d-none'), newInput2.value=''); }
          }
          setv('txDescription','');
          setv('txCheck','');
          const statusEl = g('txStatus'); if (statusEl) statusEl.value = status;
          const dateVal = g('txDate') ? g('txDate').value : '';
          setv('txInitiated', dateVal);
          setv('txMailed','');
          if (status === '2' && dateVal) { setv('txSettled', dateVal); } else { setv('txSettled',''); }
          modal && modal.show();
        });

        form && form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const fd = new FormData(form);
          const res = await fetch('transaction_save.php', { method:'POST', body: fd });
          if (!res.ok) return; // api_error.js will show modal
          try { await res.json(); } catch {}
          modal && modal.hide();
          window.location.reload();
        });

        // Menu: Change status
        document.addEventListener('click', async (e) => {
          const a = e.target.closest('.tx-menu-set-status');
          if (!a) return;
          e.preventDefault();
          const row = a.closest('tr');
          if (!row) return;
          const id = row.dataset.id;
          const status = a.dataset.status;
          if (!id || typeof status === 'undefined') return;
          const fd = new FormData();
          fd.append('id', id);
          fd.append('status', status);
          const res = await fetch('transaction_status.php', { method:'POST', body: fd });
          if (!res.ok) return;
          try { await res.json(); } catch {}
          window.location.reload();
        });
        // Menu: Delete
        document.addEventListener('click', async (e) => {
          const a = e.target.closest('.tx-menu-delete');
          if (!a) return;
          e.preventDefault();
          const row = a.closest('tr');
          if (!row) return;
          const id = row.dataset.id;
          const desc = row.dataset.description || '';
          const amt = row.dataset.amount || '';
          const date = row.dataset.date || '';
          const detail = [date, desc].filter(Boolean).join(' — ');
          const msg = `Delete this transaction${detail ? `: \"${detail}\"` : ''}${amt ? ` (amount: ${amt})` : ''}?`;
          const ok = window.confirm(msg);
          if (!ok) return;
          const fd = new FormData();
          fd.append('id', id);
          const res = await fetch('transaction_delete.php', { method:'POST', body: fd });
          if (!res.ok) return;
          try { await res.json(); } catch {}
          window.location.reload();
        });
      })();
      </script>
    <?php endif; ?>
  </body>
  </html>
