<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Reminders';
$error = '';
$rows = [];

try {
    $pdo = get_mysql_connection();
    $sql = 'SELECT r.id, r.fm_pk, r.due, r.amount, r.description, r.frequency, r.updated_at_source, a.name AS account_name
            FROM reminders r LEFT JOIN accounts a ON a.id = r.account_id
            ORDER BY r.due ASC, r.updated_at_source DESC';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Unable to load reminders.';
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
        <a class="btn btn-outline-secondary btn-sm" href="index.php">← Transactions</a>
        <span class="navbar-brand mx-auto">Reminders</span>
        <span class="position-absolute end-0 top-50 translate-middle-y text-body-secondary small d-none d-sm-inline"><?= htmlspecialchars((string)($_SESSION['username'] ?? '')) ?></span>
      </div>
    </nav>

    <main class="container my-4">
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if (empty($rows)): ?>
        <div class="text-body-secondary">No reminders.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-striped table-hover table-sm align-middle mb-0">
            <thead>
              <tr>
                <th scope="col">Due</th>
                <th scope="col">Account</th>
                <th scope="col">Description</th>
                <th scope="col" class="text-end">Amount</th>
                <th scope="col">Frequency</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <?php
                  $due = $r['due'] ?? '';
                  $acct = $r['account_name'] ?? '';
                  $desc = $r['description'] ?? '';
                  $amt = $r['amount'];
                  $freq = $r['frequency'] ?? '';
                  $cls = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                  $fmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                ?>
                <tr>
                  <td><?= htmlspecialchars((string)$due) ?></td>
                  <td><?= htmlspecialchars((string)$acct) ?></td>
                  <td class="text-truncate" style="max-width: 520px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                  <td class="text-end <?= $cls ?>">$<?= $fmt ?></td>
                  <td><?= htmlspecialchars((string)$freq) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  </body>
  </html>

