<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';

$pageTitle = 'Budget';
$loggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
$loginError = '';
$recentTx = [];
$recentError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loggedIn) {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username !== '' && $password !== '') {
        try {
            $pdo = get_mysql_connection();
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $hash = $stmt->fetchColumn();
            if ($hash && password_verify($password, (string)$hash)) {
                $_SESSION['username'] = $username;
                header('Location: ' . (string)($_SERVER['PHP_SELF'] ?? 'index.php'));
                exit;
            } else {
                $loginError = 'Invalid username or password.';
            }
        } catch (Throwable $e) {
            $loginError = 'Login error. Please try again later.';
        }
    } else {
        $loginError = 'Please enter username and password.';
    }
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
  </head>
  <body>
    <nav class="navbar bg-body-tertiary sticky-top">
      <div class="container-fluid position-relative">
        <?php if ($loggedIn): ?>
          <a class="navbar-brand mx-auto" href="#"><?= htmlspecialchars($pageTitle) ?></a>
          <div class="position-absolute end-0 top-50 translate-middle-y d-flex align-items-center gap-2">
            <span class="text-body-secondary small d-none d-sm-inline"><?= htmlspecialchars((string)$_SESSION['username']) ?></span>
            <a class="btn btn-outline-secondary btn-sm" href="#" role="button" aria-disabled="true">Logout</a>
          </div>
        <?php else: ?>
          <span class="navbar-brand mx-auto"><?= htmlspecialchars($pageTitle) ?></span>
        <?php endif; ?>
      </div>
    </nav>
    <?php /* Offcanvas menu removed per request */ ?>

    <main>
      <?php if ($loggedIn): ?>
        <?php
          try {
            $pdo = get_mysql_connection();
            $limit = 50; // recent rows to show
            $stmt = $pdo->prepare(
              'SELECT t.id, t.fm_pk, t.`date`, t.amount, t.description, t.check_no, t.posted, t.category, t.tags, t.updated_at_source, 
                      a.name AS account_name
               FROM transactions t
               LEFT JOIN accounts a ON a.id = t.account_id
               ORDER BY t.`date` DESC, t.updated_at_source DESC
               LIMIT ?'
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $recentTx = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) {
            $recentError = 'Unable to load recent transactions.';
          }
        ?>

        <div class="container my-4">
          <h2 class="h5 mb-3">Recent Transactions</h2>
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
                    <th scope="col">Posted</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentTx as $row): ?>
                    <?php
                      $date = $row['date'] ?? '';
                      $acct = $row['account_name'] ?? '';
                      $desc = $row['description'] ?? '';
                      $amt = $row['amount'];
                      $posted = $row['posted'] ?? '';
                      $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                      $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                    ?>
                    <tr>
                      <td><?= htmlspecialchars((string)$date) ?></td>
                      <td><?= htmlspecialchars((string)$acct) ?></td>
                      <td class="text-truncate" style="max-width: 480px;"><?= htmlspecialchars((string)$desc) ?></td>
                      <td class="text-end <?= $amtClass ?>"><?= $amtFmt ?></td>
                      <td><?= htmlspecialchars((string)$posted) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="container mt-5 mx-auto" style="max-width: 420px;">
          <?php if ($loginError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($loginError) ?></div>
          <?php endif; ?>
          <form method="post" action="" id="loginForm">
            <div class="mb-3">
              <label for="username" class="form-label">Username</label>
              <input type="text" id="username" name="username" class="form-control" autocomplete="username" required>
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">Password</label>
              <input type="password" id="password" name="password" class="form-control" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Log In</button>
          </form>
        </div>
      <?php endif; ?>
    </main>

    <?php if ($loggedIn): ?>
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
    <?php endif; ?>
  </body>
  </html>
