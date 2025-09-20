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
  // Accounts for select lists (load all accounts so new reminders can target any existing account)
  try {
    $as = $pdo->query('SELECT id, name FROM accounts ORDER BY name ASC');
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" crossorigin="anonymous">
  </head>
  <body>
    <nav class="navbar bg-body-tertiary sticky-top">
      <div class="container-fluid position-relative">
        <a class="btn btn-outline-secondary btn-sm" href="index.php">← Transactions</a>
        <span class="navbar-brand mx-auto">Reminders</span>
        <div class="position-absolute end-0 top-50 translate-middle-y d-flex align-items-center gap-2">
          <button type="button" class="btn btn-sm btn-success" id="addRemBtn">+ Add Reminder</button>
          <span class="text-body-secondary small d-none d-sm-inline"><?= htmlspecialchars((string)($_SESSION['username'] ?? '')) ?></span>
        </div>
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
                <tr
                  data-reminder-id="<?= $rid ?>"
                  data-id="<?= $rid ?>"
                  data-due="<?= htmlspecialchars((string)$due) ?>"
                  data-amount="<?= htmlspecialchars((string)$r['amount']) ?>"
                  data-account="<?= htmlspecialchars((string)$acct) ?>"
                  data-description="<?= htmlspecialchars((string)$desc) ?>"
                  data-frequency="<?= htmlspecialchars((string)$freq) ?>"
                  data-account-id="<?= (int)($r['account_id'] ?? 0) ?>"
                >
                  <td><?= $newGroup ? htmlspecialchars((string)$due) : '' ?></td>
                  <td class="rem-click-edit" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                  <td class="text-truncate rem-click-edit" role="button" style="max-width: 520px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                  <td class="text-end <?= $cls ?> rem-click-edit" role="button">$<?= $fmt ?></td>
                  <td class="rem-click-edit" role="button"><?= htmlspecialchars((string)$freq) ?></td>
                  <td class="text-end">
                    <div class="dropdown">
                      <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions">
                        <i class="bi bi-three-dots"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                        <li><a href="#" class="dropdown-item rem-menu-edit">Edit</a></li>
                        <li><a href="#" class="dropdown-item rem-menu-process">Process…</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a href="#" class="dropdown-item text-danger rem-menu-delete">Delete</a></li>
                      </ul>
                    </div>
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
            <button type="button" class="btn btn-outline-primary me-auto" id="remProcessBtn">Process…</button>
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Save</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Process Reminder -> New Transaction Modal -->
    <div class="modal fade" id="processTxModal" tabindex="-1" aria-hidden="true" aria-labelledby="processTxLabel">
      <div class="modal-dialog">
        <form class="modal-content" id="processTxForm">
          <div class="modal-header">
            <h5 class="modal-title" id="processTxLabel">New Transaction (from Reminder)</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="id" id="pTxId">
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" id="pTxDate">
              </div>
              <div class="col-6">
                <label class="form-label">Amount</label>
                <input type="text" class="form-control" name="amount" id="pTxAmount" placeholder="0.00">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">Account</label>
              <select class="form-select" name="account_select" id="pTxAccountSelect">
                <?php if (!empty($accounts)) foreach ($accounts as $a): ?>
                  <option value="<?= htmlspecialchars((string)$a) ?>"><?= htmlspecialchars((string)$a) ?></option>
                <?php endforeach; ?>
                <option value="__new__">Add new account…</option>
              </select>
              <input type="text" class="form-control mt-2 d-none" name="account_name_new" id="pTxAccountNew" placeholder="New account name">
              <input type="hidden" name="account_keep" id="pTxAccountKeep">
            </div>
            <div class="mb-2">
              <label class="form-label">Description</label>
              <input type="text" class="form-control" name="description" id="pTxDescription">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label">Check #</label>
                <input type="text" class="form-control" name="check_no" id="pTxCheck">
              </div>
              <div class="col-6">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" id="pTxStatus">
                  <option value="0" selected>Scheduled</option>
                  <option value="1">Pending</option>
                  <option value="2">Posted</option>
                </select>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Create Transaction</button>
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
      const todayStr = () => { const d=new Date(); const y=d.getFullYear(); const m=String(d.getMonth()+1).padStart(2,'0'); const dd=String(d.getDate()).padStart(2,'0'); return `${y}-${m}-${dd}`; };

      function openRemEditFromRow(row){
        if (!row) return;
        setv('remId', row.dataset.id || row.dataset.reminderId || '');
        setv('remDue', row.dataset.due || '');
        setv('remAmount', row.dataset.amount || '');
        const sel = g('remAccountSelect');
        const newInput = g('remAccountNew');
        const keep = g('remAccountKeep'); if (keep) keep.value = row.dataset.accountId || '';
        if (sel) {
          const acct = row.dataset.account || '';
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
        setv('remDescription', row.dataset.description || '');
        setv('remFrequency', row.dataset.frequency || '');
        modal && modal.show();
      }

      // Clickable cells open edit
      document.addEventListener('click', (e) => {
        const cell = e.target.closest('.rem-click-edit');
        if (!cell) return;
        e.preventDefault();
        const row = cell.closest('tr');
        openRemEditFromRow(row);
      });

      // Dropdown menu: Edit
      document.addEventListener('click', (e) => {
        const a = e.target.closest('.rem-menu-edit');
        if (!a) return;
        e.preventDefault();
        const row = a.closest('tr');
        openRemEditFromRow(row);
      });

      const sel = g('remAccountSelect');
      sel && sel.addEventListener('change', () => {
        const newInput = g('remAccountNew');
        if (!newInput) return;
        if (sel.value==='__new__') newInput.classList.remove('d-none'); else { newInput.classList.add('d-none'); newInput.value=''; }
      });
      const addBtn = document.getElementById('addRemBtn');
      addBtn && addBtn.addEventListener('click', (e) => {
        e.preventDefault();
        // Reset form for a new reminder
        setv('remId','');
        setv('remDue', todayStr());
        setv('remAmount','');
        setv('remDescription','');
        setv('remFrequency','');
        const keep = g('remAccountKeep'); if (keep) keep.value = '';
        const sel = g('remAccountSelect');
        const newInput = g('remAccountNew');
        if (sel) {
          // Prefer first real account option if available; else default to __new__
          let chosen = '';
          for (const opt of sel.options) {
            if (opt.disabled) continue;
            if (opt.value !== '__new__' && opt.value !== '__current__') { chosen = opt.value; break; }
          }
          if (chosen) {
            sel.value = chosen;
            newInput && (newInput.classList.add('d-none'), newInput.value='');
          } else {
            sel.value = '__new__';
            newInput && (newInput.classList.remove('d-none'), newInput.value='');
          }
        } else if (newInput) {
          newInput.classList.remove('d-none'); newInput.value='';
        }
        modal && modal.show();
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

      // Dropdown menu: Delete
      document.addEventListener('click', async (e) => {
        const del = e.target.closest('.rem-menu-delete');
        if (!del) return;
        e.preventDefault();
        const row = del.closest('tr');
        const id = row?.dataset.id || row?.dataset.reminderId;
        const desc = row?.dataset.description || '';
        if (!id) return;
        const ok = window.confirm(`Delete this reminder${desc ? `: \"${desc}\"` : ''}?`);
        if (!ok) return;
        const fd = new FormData(); fd.append('id', id);
        const res = await fetch('reminder_delete.php', { method: 'POST', body: fd });
        if (!res.ok) return;
        try { await res.json(); } catch {}
        window.location.reload();
      });

      // Date rolling helper based on frequency
      function rollForwardDate(dateStr, freqStr){
        if (!dateStr) return dateStr;
        const d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d.getTime())) return dateStr;
        const f = (freqStr || '').toLowerCase();
        const addDays = (n)=>{ d.setDate(d.getDate()+n); };
        const addMonths = (n)=>{ const day=d.getDate(); d.setMonth(d.getMonth()+n); if (d.getDate()!==day) { d.setDate(0); } };
        const pad2=(n)=> String(n).padStart(2,'0');
        if (f.includes('every 2 week') || f.includes('biweek') || (f.includes('2') && f.includes('week'))) {
          addDays(14);
        } else if (f.includes('week')) {
          addDays(7);
        } else if (f.includes('semi') && f.includes('month')) {
          const day = d.getDate();
          if (day < 15) { d.setDate(15); }
          else { d.setMonth(d.getMonth()+1); d.setDate(1); }
        } else if (f.includes('quarter')) {
          addMonths(3);
        } else if (f.includes('month')) {
          addMonths(1);
        } else if (f.includes('year') || f.includes('annual')) {
          d.setFullYear(d.getFullYear()+1);
        } else if (f.includes('day') || f.includes('daily')) {
          addDays(1);
        } else {
          // Unknown: leave unchanged
          return dateStr;
        }
        return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
      }

      // Process flow modal
      const pModalEl = document.getElementById('processTxModal');
      const pModal = pModalEl ? new bootstrap.Modal(pModalEl) : null;
      const pForm = document.getElementById('processTxForm');

      function prefillProcessFromSource(source){
        // source: a <tr> with dataset or indicates using edit form fields
        const getVal = (key) => {
          if (!source) return '';
          if (source instanceof HTMLElement) return source.dataset[key] || '';
          return '';
        };
        // Date: today
        setv('pTxDate', todayStr());
        // Amount: negative of reminder amount
        let a = 0;
        if (source instanceof HTMLElement) a = parseFloat(getVal('amount') || '0');
        else a = parseFloat(g('remAmount')?.value || '0');
        if (!isFinite(a)) a = 0; if (a > 0) a = -a; setv('pTxAmount', a.toFixed(2));
        // Account
        const acctName = (source instanceof HTMLElement) ? (getVal('account') || '') : ((g('remAccountSelect')?.value && g('remAccountSelect')?.value !== '__new__' && g('remAccountSelect')?.value !== '__current__') ? g('remAccountSelect').value : (g('remAccountNew')?.value || ''));
        const acctId = (source instanceof HTMLElement) ? (getVal('accountId') || '') : (g('remAccountKeep')?.value || '');
        const sel = g('pTxAccountSelect');
        const newInput = g('pTxAccountNew');
        const keep = g('pTxAccountKeep'); if (keep) keep.value = acctId || '';
        if (sel) {
          let found=false;
          if (acctName) { for (const opt of sel.options){ if(opt.value===acctName){ sel.value=acctName; found=true; break; } } }
          if (!found) {
            if (acctName) {
              const opt = document.createElement('option');
              opt.value='__current__'; opt.textContent=`Current: ${acctName}`; opt.disabled=true; opt.selected=true;
              sel.insertBefore(opt, sel.firstChild);
              newInput && (newInput.classList.add('d-none'), newInput.value='');
            } else {
              sel.value='__new__'; newInput && (newInput.classList.remove('d-none'), newInput.value='');
            }
          } else { newInput && (newInput.classList.add('d-none'), newInput.value=''); }
        }
        // Description from reminder account field per request
        setv('pTxDescription', acctName || '');
        // Clear check number and default status to Scheduled
        setv('pTxCheck','');
        const st = g('pTxStatus'); if (st) st.value = '0';
      }

      // Row menu -> Process
      document.addEventListener('click', (e) => {
        const a = e.target.closest('.rem-menu-process');
        if (!a) return;
        e.preventDefault();
        const row = a.closest('tr');
        prefillProcessFromSource(row);
        pModal && pModal.show();
        // Store context on modal for after-create
        pModalEl.dataset.reminderId = row?.dataset.id || row?.dataset.reminderId || '';
        pModalEl.dataset.reminderDue = row?.dataset.due || '';
        pModalEl.dataset.reminderFreq = row?.dataset.frequency || '';
        pModalEl.dataset.reminderAccount = row?.dataset.account || '';
        pModalEl.dataset.reminderAmount = row?.dataset.amount || '';
        pModalEl.dataset.reminderDescription = row?.dataset.description || '';
        pModalEl.dataset.fromEdit = '0';
      });

      // Edit modal Process button
      const remProcessBtn = document.getElementById('remProcessBtn');
      remProcessBtn && remProcessBtn.addEventListener('click', (e) => {
        e.preventDefault();
        prefillProcessFromSource(null);
        pModal && pModal.show();
        // Context from current edit fields
        pModalEl.dataset.reminderId = g('remId')?.value || '';
        pModalEl.dataset.reminderDue = g('remDue')?.value || '';
        pModalEl.dataset.reminderFreq = g('remFrequency')?.value || '';
        // Account to show back in case we need it
        const selR = g('remAccountSelect');
        const newR = g('remAccountNew');
        const acctName = (selR && selR.value && selR.value !== '__new__' && selR.value !== '__current__') ? selR.value : (newR?.value || '');
        pModalEl.dataset.reminderAccount = acctName || '';
        pModalEl.dataset.reminderAmount = g('remAmount')?.value || '';
        pModalEl.dataset.reminderDescription = g('remDescription')?.value || '';
        pModalEl.dataset.fromEdit = '1';
      });

      // Create transaction submit
      pForm && pForm.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(pForm);
        const res = await fetch('transaction_save.php', { method:'POST', body: fd });
        if (!res.ok) return;
        try { await res.json(); } catch {}
        pModal && pModal.hide();
        // After creation, open reminder edit and roll forward date
        const rid = pModalEl.dataset.reminderId || '';
        const due = pModalEl.dataset.reminderDue || '';
        const freq = pModalEl.dataset.reminderFreq || '';
        const account = pModalEl.dataset.reminderAccount || '';
        const amount = pModalEl.dataset.reminderAmount || '';
        const description = pModalEl.dataset.reminderDescription || '';
        const newDue = rollForwardDate(due, freq);
        // If we were already in edit, just update the due field
        if (pModalEl.dataset.fromEdit === '1') {
          setv('remDue', newDue);
          return;
        }
        // Otherwise, open edit for this reminder id
        let row = null;
        if (rid) row = document.querySelector(`tr[data-id='${rid}'], tr[data-reminder-id='${rid}']`);
        if (row) {
          openRemEditFromRow(row);
          setTimeout(() => { setv('remDue', newDue); }, 150);
        } else {
          setv('remId', rid);
          setv('remDue', newDue);
          setv('remAmount', amount);
          // Account select
          const sel = g('remAccountSelect');
          const newInput = g('remAccountNew');
          if (sel) {
            let found=false;
            if (account) { for (const opt of sel.options){ if(opt.value===account){ sel.value=account; found=true; break; } } }
            if (!found) {
              if (account) {
                const opt = document.createElement('option');
                opt.value='__current__'; opt.textContent=`Current: ${account}`; opt.disabled=true; opt.selected=true;
                sel.insertBefore(opt, sel.firstChild);
                newInput && (newInput.classList.add('d-none'), newInput.value='');
              } else {
                sel.value='__new__'; newInput && (newInput.classList.remove('d-none'), newInput.value='');
              }
            } else { newInput && (newInput.classList.add('d-none'), newInput.value=''); }
          }
          setv('remDescription', description);
          setv('remFrequency', freq);
          modal && modal.show();
        }
      });
    })();
    </script>
  </body>
  </html>
