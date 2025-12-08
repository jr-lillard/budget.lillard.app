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

$pageTitle = 'Profile';
$error = '';
$success = '';
$phone = '';

$localUser = $currentUser;
if (str_contains($currentUser, '@')) {
    [$localUser] = explode('@', $currentUser, 2);
    $localUser = budget_canonical_user($localUser);
}

try {
    $pdo = get_mysql_connection();
    // Ensure phone column exists
    try { $pdo->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(32) NULL'); } catch (Throwable $e) { /* ignore if exists */ }

    // Load current phone, preferring exact username then local part fallback
    $stmt = $pdo->prepare('SELECT phone FROM users WHERE username IN (?, ?) ORDER BY (username = ?) DESC LIMIT 1');
    $stmt->execute([$currentUser, $localUser, $currentUser]);
    $phone = (string)($stmt->fetchColumn() ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = trim((string)($_POST['phone'] ?? ''));
        // allow digits, spaces, plus, parentheses, dashes, dots
        if ($raw !== '' && !preg_match('/^[0-9\s\-()+\.]{5,32}$/', $raw)) {
            throw new RuntimeException('Enter a valid phone number (5-32 chars, digits and +()-.).');
        }
        $phone = $raw;
        $params = [
            ':phone' => $phone !== '' ? $phone : null,
            ':username' => $currentUser,
        ];
        $upd = $pdo->prepare('UPDATE users SET phone = :phone, updated_at = CURRENT_TIMESTAMP WHERE username = :username');
        $upd->execute($params);

        if ($upd->rowCount() < 1 && $localUser !== $currentUser) {
            $params[':username'] = $localUser;
            $upd = $pdo->prepare('UPDATE users SET phone = :phone, updated_at = CURRENT_TIMESTAMP WHERE username = :username');
            $upd->execute($params);
        }

        if ($upd->rowCount() < 1) {
            throw new RuntimeException('User record not found to update.');
        }

        $success = 'Profile updated.';
    }
} catch (Throwable $e) {
    $error = 'Error: ' . $e->getMessage();
}

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
          <span class="navbar-brand mb-0 h1">Profile</span>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span class="text-body-secondary small d-none d-sm-inline"><?= h($currentUser) ?></span>
          <a class="btn btn-outline-secondary btn-sm" href="index.php?logout=1">Logout</a>
        </div>
      </div>
    </nav>

    <main class="container my-4" style="max-width: 540px;">
      <?php if ($success !== ''): ?>
        <div class="alert alert-success" role="alert"><?= h($success) ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
      <?php endif; ?>

      <div class="card shadow-sm">
        <div class="card-body">
          <h2 class="h5 mb-3">Contact</h2>
          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">Email</label>
              <input type="email" class="form-control" value="<?= h($currentUser) ?>" disabled>
            </div>
            <div>
              <label class="form-label" for="phone">Phone number</label>
              <input type="text"
                     class="form-control"
                     id="phone"
                     name="phone"
                     inputmode="tel"
                     pattern="[0-9\s\-()+\.]{5,32}"
                     maxlength="32"
                     placeholder="e.g. +1 (555) 123-4567"
                     value="<?= h($phone) ?>">
              <div class="form-text">Digits and + ( ) - . allowed; leave blank to clear.</div>
            </div>
            <div class="d-flex justify-content-end gap-2">
              <a class="btn btn-outline-secondary" href="index.php">Cancel</a>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>
    </main>
  </body>
</html>
