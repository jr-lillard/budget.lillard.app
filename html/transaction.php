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

$pageTitle = 'Edit Transaction';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';
$saved = false;
$row = [
    'id' => $id,
    'date' => '',
    'amount' => '',
    'description' => '',
    'check_no' => '',
    'posted' => '',
    'initiated_date' => '',
    'mailed_date' => '',
    'settled_date' => '',
    'category' => '',
    'tags' => '',
    'account_name' => ''
];

try {
    $pdo = get_mysql_connection();
    $defaultOwner = budget_default_owner();
    budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
    budget_ensure_transaction_date_columns($pdo);
    $owner = budget_canonical_user((string)$_SESSION['username']);
    // Save
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $date = trim((string)($_POST['date'] ?? ''));
        $amount = trim((string)($_POST['amount'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $checkNo = trim((string)($_POST['check_no'] ?? ''));
        $posted = trim((string)($_POST['posted'] ?? ''));
        $initiatedDate = trim((string)($_POST['initiated_date'] ?? ''));
        $mailedDate = trim((string)($_POST['mailed_date'] ?? ''));
        $settledDate = trim((string)($_POST['settled_date'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $tags = trim((string)($_POST['tags'] ?? ''));
        $accountName = trim((string)($_POST['account_name'] ?? ''));

        // Basic validation
        if ($id <= 0) { throw new RuntimeException('Invalid transaction id'); }
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('Date must be YYYY-MM-DD');
        }
        if ($amount !== '' && !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new RuntimeException('Amount format invalid');
        }
        foreach ([['Initiated date', $initiatedDate], ['Mailed date', $mailedDate], ['Settled date', $settledDate]] as [$label, $val]) {
            if ($val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                throw new RuntimeException($label . ' must be YYYY-MM-DD');
            }
        }

        // Resolve account by name (insert if missing)
        $accountId = null;
        if ($accountName !== '') {
            $stmt = $pdo->prepare('INSERT INTO accounts (name) VALUES (?) ON DUPLICATE KEY UPDATE updated_at = updated_at');
            $stmt->execute([$accountName]);
            $get = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
            $get->execute([$accountName]);
            $accountId = (int)$get->fetchColumn();
        }

        if ($initiatedDate === '' && $date !== '') {
            $initiatedDate = $date;
        }
        $mailedParam = $mailedDate !== '' ? $mailedDate : null;
        $isPostedFlag = $posted !== '';
        if ($isPostedFlag) {
            if ($settledDate === '' && $date !== '') { $settledDate = $date; }
        } else {
            $settledDate = '';
        }
        $initiatedParam = $initiatedDate !== '' ? $initiatedDate : null;
        $settledParam = $settledDate !== '' ? $settledDate : null;

        $sql = 'UPDATE transactions SET account_id = :account_id, `date` = :date, amount = :amount, description = :description, check_no = :check_no, initiated_date = :initiated, mailed_date = :mailed, settled_date = :settled, posted = :posted, category = :category, tags = :tags, updated_at_source = NOW()
                WHERE id = :id AND owner = :owner';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':account_id' => $accountId ?: null,
            ':date' => $date !== '' ? $date : null,
            ':amount' => $amount !== '' ? $amount : null,
            ':description' => $description !== '' ? $description : null,
            ':check_no' => $checkNo !== '' ? $checkNo : null,
            ':initiated' => $initiatedParam,
            ':mailed' => $mailedParam,
            ':settled' => $settledParam,
            ':posted' => $posted !== '' ? $posted : null,
            ':category' => $category !== '' ? $category : null,
            ':tags' => $tags !== '' ? $tags : null,
            ':id' => $id,
            ':owner' => $owner,
        ]);
        $saved = true;
    }

    // Fetch row (after possible save)
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT t.*, a.name AS account_name FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id WHERE t.id = ? AND t.owner = ?');
        $stmt->execute([$id, $owner]);
        $tmp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tmp) { $row = array_merge($row, $tmp); }
    }

    // Accounts list for datalist
    $accounts = [];
    $accStmt = $pdo->query('SELECT name FROM accounts ORDER BY name ASC');
    $accounts = $accStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $error = 'Error: ' . $e->getMessage();
}
$hasCheck = trim((string)($row['check_no'] ?? '')) !== '';
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
        <a class="btn btn-outline-secondary btn-sm" href="index.php">← Back</a>
        <span class="navbar-brand mx-auto">Edit Transaction</span>
        <div class="position-absolute end-0 top-50 translate-middle-y">
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

    <main class="container my-4" style="max-width: 720px;">
      <?php if ($saved): ?>
        <div class="alert alert-success">Saved.</div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="transaction.php?id=<?= (int)$row['id'] ?>">
        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

        <div class="row g-3 mb-3<?= $hasCheck ? '' : ' d-none' ?>" id="checkDateFields">
          <div class="col-md-6">
            <label class="form-label">Date</label>
            <input type="date" class="form-control" name="date" value="<?= htmlspecialchars((string)($row['date'] ?? '')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Amount</label>
            <input type="text" class="form-control" name="amount" value="<?= htmlspecialchars((string)($row['amount'] ?? '')) ?>" placeholder="0.00">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Account</label>
          <input list="accountsList" class="form-control" name="account_name" value="<?= htmlspecialchars((string)($row['account_name'] ?? '')) ?>" placeholder="Start typing…">
          <datalist id="accountsList">
            <?php foreach ($accounts as $a): ?>
              <option value="<?= htmlspecialchars((string)$a) ?>"></option>
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="mb-3">
          <label class="form-label">Description</label>
          <input type="text" class="form-control" name="description" value="<?= htmlspecialchars((string)($row['description'] ?? '')) ?>">
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Check #</label>
            <input type="text" class="form-control" name="check_no" value="<?= htmlspecialchars((string)($row['check_no'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Posted</label>
            <input type="text" class="form-control" name="posted" value="<?= htmlspecialchars((string)($row['posted'] ?? '')) ?>" placeholder="e.g., x">
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <input type="text" class="form-control" name="category" value="<?= htmlspecialchars((string)($row['category'] ?? '')) ?>">
          </div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label">Initiated</label>
            <input type="date" class="form-control" name="initiated_date" value="<?= htmlspecialchars((string)($row['initiated_date'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Mailed</label>
            <input type="date" class="form-control" name="mailed_date" value="<?= htmlspecialchars((string)($row['mailed_date'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Settled</label>
            <input type="date" class="form-control" name="settled_date" value="<?= htmlspecialchars((string)($row['settled_date'] ?? '')) ?>">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Tags</label>
          <input type="text" class="form-control" name="tags" value="<?= htmlspecialchars((string)($row['tags'] ?? '')) ?>">
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
    (() => {
      const form = document.querySelector('form');
      if (!form) return;
      const checkInput = form.querySelector('[name="check_no"]');
      const checkDatesRow = document.getElementById('checkDateFields');
      const dateInput = form.querySelector('[name="date"]');
      const initiatedInput = form.querySelector('[name="initiated_date"]');
      const mailedInput = form.querySelector('[name="mailed_date"]');
      const settledInput = form.querySelector('[name="settled_date"]');
      const toggleCheckDates = () => {
        if (!checkDatesRow) return;
        const hasCheck = !!(checkInput && checkInput.value.trim());
        if (hasCheck) {
          checkDatesRow.classList.remove('d-none');
          if (initiatedInput && !initiatedInput.value && dateInput && dateInput.value) {
            initiatedInput.value = dateInput.value;
          }
        } else {
          checkDatesRow.classList.add('d-none');
          if (initiatedInput) initiatedInput.value = '';
          if (mailedInput) mailedInput.value = '';
          if (settledInput) settledInput.value = '';
        }
      };
      checkInput && checkInput.addEventListener('input', toggleCheckDates);
      dateInput && dateInput.addEventListener('change', () => {
        if (checkInput && checkInput.value.trim() && initiatedInput && !initiatedInput.value) {
          initiatedInput.value = dateInput.value || '';
        }
      });
      toggleCheckDates();
    })();
    </script>
  </body>
  </html>
