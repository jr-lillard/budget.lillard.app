<?php
declare(strict_types=1);
session_start();

$loggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
$pageTitle = 'Budget';
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
          <button class="navbar-toggler position-absolute start-0 top-50 translate-middle-y" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenu" aria-controls="mainMenu" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
          </button>
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
    <?php if ($loggedIn): ?>
      <div class="offcanvas offcanvas-start" tabindex="-1" id="mainMenu" aria-labelledby="mainMenuLabel">
        <div class="offcanvas-header">
          <h5 class="offcanvas-title" id="mainMenuLabel">Menu</h5>
          <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
          <ul class="list-unstyled mb-0">
            <li><a class="link-body-emphasis d-block py-1" href="index.php">Home</a></li>
            <li><a class="link-body-emphasis d-block py-1" href="accounts.php">Accounts</a></li>
            <li><a class="link-body-emphasis d-block py-1" href="manage_projects.php">Manage Projects</a></li>
            <li><a class="link-body-emphasis d-block py-1" href="setup.php">Setup</a></li>
          </ul>
        </div>
      </div>
    <?php endif; ?>

    <main>
      <?php if ($loggedIn): ?>
        <div class="alert alert-info" role="alert">Welcome, <?= htmlspecialchars((string)$_SESSION['username']) ?>.</div>
        <p class="text-body-secondary">This is the Time Entries view. Build out your UI here.</p>
      <?php else: ?>
        <div class="container mt-5 mx-auto" style="max-width: 420px;">
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
