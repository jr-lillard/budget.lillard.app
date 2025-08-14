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
$sql = 'SELECT r.id, r.fm_pk, r.due, r.amount, r.description, r.frequency, r.updated_at_source, r.account_id, a.name AS account_name
            FROM reminders r LEFT JOIN accounts a ON a.id = r.account_id
            ORDER BY r.due ASC, r.updated_at DESC';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $error = 'Unable to load reminders.';
}
  // Accounts used by reminders for select lists
  try {
    $as = $pdo->query('SELECT DISTINCT a.id, a.name FROM accounts a JOIN reminders r ON r.account_id = a.id ORDER BY a.name ASC');
    $accPairs = $as->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $accounts = array_values($accPairs);
  } catch (Throwable $e) { $accPairs = []; $accounts = []; }
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
                <th scope="col" class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php $currentDue = null; foreach ($rows as $r): ?>
                <?php
                  $due = $r['due'] ?? '';
                  $acct = $r['account_name'] ?? '';
                  $desc = $r['description'] ?? '';
                  $amt = $r['amount'];
                  $freq = $r['frequency'] ?? '';
                  $cls = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                  $fmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                  $rid = (int)($r['id'] ?? 0);
                  $newGroup = ($due !== $currentDue);
                  if ($newGroup) {
                    $label = $due;
                    $ts = strtotime((string)$due);
                    if ($ts !== false) { $label = date('l, F j, Y', $ts); }
                    echo '<tr class="table-active"><td colspan="6">' . htmlspecialchars($label) . '</td></tr>';
                    $currentDue = $due;
                  }
                ?>
                <tr data-reminder-id="<?= $rid ?>">
                  <td><?= $newGroup ? htmlspecialchars((string)$due) : '' ?></td>
                  <td><?= htmlspecialchars((string)$acct) ?></td>
                  <td class="text-truncate" style="max-width: 520px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                  <td class="text-end <?= $cls ?>">$<?= $fmt ?></td>
                  <td><?= htmlspecialchars((string)$freq) ?></td>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-edit-rem"
                      data-id="<?= $rid ?>"
                      data-due="<?= htmlspecialchars((string)$due) ?>"
                      data-amount="<?= htmlspecialchars((string)$r['amount']) ?>"
                      data-account="<?= htmlspecialchars((string)$acct) ?>"
                      data-description="<?= htmlspecialchars((string)$desc) ?>"
                      data-frequency="<?= htmlspecialchars((string)$freq) ?>"
                      data-account-id="<?= (int)($r['account_id'] ?? 0) ?>">
                      Edit
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </main>

    <!-- Edit Reminder Modal -->
    <div class="modal fade" id="editRemModal" tabindex="-1" aria-hidden="true" aria-labelledby="editRemLabel">
      <div class="modal-dialog">
        <form class="modal-content" id="editRemForm">
          <div class="modal-header">
            <h5 class="modal-title" id="editRemLabel">Edit Reminder</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="id" id="remId">
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label">Due</label>
                <input type="date" class="form-control" name="due" id="remDue">
              </div>
              <div class="col-6">
                <label class="form-label">Amount</label>
                <input type="text" class="form-control" name="amount" id="remAmount" placeholder="0.00">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Account</label>
              <select class="form-select" name="account_select" id="remAccountSelect">
                <?php if (!empty($accounts)) foreach ($accounts as $a): ?>
                  <option value="<?= htmlspecialchars((string)$a) ?>"><?= htmlspecialchars((string)$a) ?></option>
                <?php endforeach; ?>
                <option value="__new__">Add new account…</option>
              </select>
              <input type="text" class="form-control mt-2 d-none" name="account_name_new" id="remAccountNew" placeholder="New account name">
              <input type="hidden" name="account_keep" id="remAccountKeep">
            </div>
            <div class="mb-2">
              <label class="form-label">Description</label>
              <input type="text" class="form-control" name="description" id="remDescription">
            </div>
            <div class="mb-2">
              <label class="form-label">Frequency</label>
              <input type="text" class="form-control" name="frequency" id="remFrequency" placeholder="e.g., Monthly">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <script>
    (() => {
      const modalEl = document.getElementById('editRemModal');
      const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
      const form = document.getElementById('editRemForm');
      const g = (id) => document.getElementById(id);
      const setv = (id,v)=>{ const el=g(id); if(el) el.value = v || ''; };
      document.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-edit-rem');
        if (!btn) return;
        e.preventDefault();
        setv('remId', btn.dataset.id);
        setv('remDue', btn.dataset.due);
        setv('remAmount', btn.dataset.amount);
        const sel = g('remAccountSelect');
        const newInput = g('remAccountNew');
        const keep = g('remAccountKeep'); if (keep) keep.value = btn.dataset.accountId || '';
        if (sel) {
          const acct = btn.dataset.account || '';
          let found=false;
          if (acct) { for (const opt of sel.options){ if(opt.value===acct){ sel.value=acct; found=true; break; } } }
          if (!found) {
            if (acct) {
              const opt = document.createElement('option');
              opt.value='__current__'; opt.textContent=`Current: ${acct}`; opt.disabled=true; opt.selected=true;
              sel.insertBefore(opt, sel.firstChild);
              newInput && (newInput.classList.add('d-none'), newInput.value='');
            } else {
              sel.value='__new__'; newInput && (newInput.classList.remove('d-none'), newInput.value='');
            }
          } else { newInput && (newInput.classList.add('d-none'), newInput.value=''); }
        }
        setv('remDescription', btn.dataset.description);
        setv('remFrequency', btn.dataset.frequency);
        modal && modal.show();
      });
      const sel = g('remAccountSelect');
      sel && sel.addEventListener('change', () => {
        const newInput = g('remAccountNew');
        if (!newInput) return;
        if (sel.value==='__new__') newInput.classList.remove('d-none'); else { newInput.classList.add('d-none'); newInput.value=''; }
      });
      form && form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(form);
        const res = await fetch('reminder_save.php', { method:'POST', body: fd });
        if (!res.ok) return;
        try { await res.json(); } catch {}
        modal && modal.hide();
        window.location.reload();
      });
    })();
    </script>
  </body>
  </html>
