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
    // Ensure structured frequency columns exist
    try { $pdo->exec('ALTER TABLE reminders ADD COLUMN frequency_every INT NULL'); } catch (Throwable $e) { /* ignore if exists */ }
    try { $pdo->exec("ALTER TABLE reminders ADD COLUMN frequency_unit VARCHAR(32) NULL"); } catch (Throwable $e) { /* ignore if exists */ }
    $sql = 'SELECT r.id, r.fm_pk, r.due, r.amount, r.description, r.frequency, r.frequency_every, r.frequency_unit, r.updated_at_source, r.account_id,
                    COALESCE(a.name, r.account_name) AS account_name
            FROM reminders r LEFT JOIN accounts a ON a.id = r.account_id
            ORDER BY r.due ASC, r.updated_at DESC';
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Backfill structured frequency columns from free-form frequency
    // IMPORTANT: Only set these once when both are currently NULL.
    // Do NOT overwrite existing structured values.
    if (!empty($rows)) {
        $upd = $pdo->prepare('UPDATE reminders SET frequency_every = :every, frequency_unit = :unit WHERE id = :id');
        foreach ($rows as &$r) {
            $parsed = (function(string $f): array {
                $f = trim(strtolower($f));
                if ($f === '') return [null, null];
                // Normalize common variants
                $f = str_replace(['–','—'], '-', $f);
                $every = null; $unit = null;
                // Semi-monthly
                if (strpos($f, 'semi') !== false && strpos($f, 'month') !== false) {
                    $every = 1; $unit = 'semi-monthly';
                    return [$every, $unit];
                }
                // Biweekly / every 2 weeks
                if (strpos($f, 'biweek') !== false || strpos($f, 'every 2 week') !== false) {
                    return [2, 'weeks'];
                }
                // Quarterly
                if (strpos($f, 'quarter') !== false) {
                    return [3, 'months'];
                }
                // Annually / yearly
                if (strpos($f, 'annual') !== false || strpos($f, 'year') !== false) {
                    // "every year" or variations
                    // If a number is present like "2 years", capture it below
                    $m = [];
                    if (preg_match('/(\d+)\s*year/', $f, $m)) return [max(1,(int)$m[1]), 'years'];
                    return [1, 'years'];
                }
                // Daily
                if (strpos($f, 'daily') !== false || strpos($f, 'day') !== false) {
                    $m = [];
                    if (preg_match('/(\d+)\s*day/', $f, $m)) return [max(1,(int)$m[1]), 'days'];
                    return [1, 'days'];
                }
                // Generic "every N unit"
                $m = [];
                if (preg_match('/every\s*(\d+)\s*(day|week|month|year)/', $f, $m)) {
                    $n = max(1, (int)$m[1]);
                    $u = $m[2];
                    return [$n, $u . 's'];
                }
                // Plain "N unit(s)"
                if (preg_match('/(\d+)\s*(day|week|month|year)s?\b/', $f, $m)) {
                    $n = max(1, (int)$m[1]);
                    $u = $m[2];
                    return [$n, $u . 's'];
                }
                // Weekly / Monthly default
                if (strpos($f, 'week') !== false) return [1, 'weeks'];
                if (strpos($f, 'month') !== false) return [1, 'months'];
                // Fallback: leave nulls
                return [null, null];
            })((string)($r['frequency'] ?? ''));
            [$every, $unit] = $parsed;
            $curEvery = isset($r['frequency_every']) ? (is_null($r['frequency_every']) ? null : (int)$r['frequency_every']) : null;
            $curUnit = isset($r['frequency_unit']) ? (is_null($r['frequency_unit']) ? null : (string)$r['frequency_unit']) : null;
            // Only backfill when BOTH are currently NULL and we have a parsed value
            $bothNull = ($curEvery === null && ($curUnit === null || $curUnit === ''));
            if ($bothNull && ($every !== null || ($unit !== null && $unit !== ''))) {
                $upd->execute([
                    ':every' => $every,
                    ':unit' => $unit,
                    ':id' => (int)$r['id'],
                ]);
                $r['frequency_every'] = $every;
                $r['frequency_unit'] = $unit;
            }
        }
        unset($r);
    }
} catch (Throwable $e) {
    $error = 'Unable to load reminders.';
}
  // Accounts for selects: use distinct account names drawn from reminders only
  try {
    $accSql = "SELECT DISTINCT TRIM(COALESCE(r.account_name, a.name)) AS name
               FROM reminders r
               LEFT JOIN accounts a ON a.id = r.account_id
               WHERE TRIM(COALESCE(r.account_name, a.name)) IS NOT NULL
                 AND TRIM(COALESCE(r.account_name, a.name)) <> ''
               ORDER BY name ASC";
    $accStmt = $pdo->query($accSql);
    $reminderAccounts = $accStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) { $reminderAccounts = []; }
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
                  $fev = $r['frequency_every'] ?? null;
                  $funit = $r['frequency_unit'] ?? null;
                  $exactStr = '—';
                  if ($funit === 'semi-monthly') {
                    $exactStr = 'semi-monthly';
                  } elseif ($fev !== null && $funit) {
                    $n = (int)$fev;
                    $singular = rtrim((string)$funit, 's');
                    $exactStr = 'every ' . $n . ' ' . ($n === 1 ? $singular : $funit);
                  }
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
                  data-frequency-every="<?= htmlspecialchars((string)($fev === null ? '' : (string)(int)$fev)) ?>"
                  data-frequency-unit="<?= htmlspecialchars((string)($funit ?? '')) ?>"
                  data-account-id="<?= (int)($r['account_id'] ?? 0) ?>"
                >
                  <td><?= $newGroup ? htmlspecialchars((string)$due) : '' ?></td>
                  <td class="rem-click-edit" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                  <td class="text-truncate rem-click-edit" role="button" style="max-width: 520px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                  <td class="text-end <?= $cls ?> rem-click-edit" role="button">$<?= $fmt ?></td>
                  <td class="rem-click-edit" role="button"><?= htmlspecialchars((string)$exactStr) ?></td>
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
            <div id="remProcessPreviewBox" class="alert alert-secondary py-2 px-3 d-none">
              <div class="small fw-bold mb-1">Processing preview</div>
              <div class="small">Current due: <span id="remProcessCurrentDue">—</span></div>
              <div class="small">New due: <span id="remProcessNewDue">—</span></div>
            </div>
            <div class="mb-2">
              <label class="form-label">Account</label>
              <select class="form-select" name="account_select" id="remAccountSelect">
                <?php if (!empty($reminderAccounts)) foreach ($reminderAccounts as $a): ?>
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
            <div class="border rounded p-2 mb-2">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Frequency</label>
              </div>
              <div class="row g-2 align-items-end">
                <div class="col-auto">
                  <label class="form-label">Repeat every</label>
                  <input type="number" min="1" step="1" class="form-control" id="remFreqEvery" name="frequency_every" value="1">
                </div>
                <div class="col-auto">
                  <label class="form-label">Unit</label>
                  <select class="form-select" id="remFreqUnit" name="frequency_unit">
                    <option value="days">day(s)</option>
                    <option value="weeks">week(s)</option>
                    <option value="months" selected>month(s)</option>
                    <option value="years">year(s)</option>
                    <option value="semi-monthly">semi-monthly</option>
                  </select>
                </div>
                <div class="col-auto">
                  <div class="form-text" id="remFreqExample">Example: every 1 month</div>
                </div>
              </div>
              <div class="mt-2 small" id="remFreqPreview">Next due preview: —</div>
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
                <?php if (!empty($reminderAccounts)) foreach ($reminderAccounts as $a): ?>
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

      function clearProcessingPreview(){
        const box = g('remProcessPreviewBox');
        const btn = g('remProcessBtn');
        if (box) box.classList.add('d-none');
        if (btn) btn.classList.remove('d-none');
      }

      function showProcessingPreview(currentDue, newDue){
        const box = g('remProcessPreviewBox');
        const cur = g('remProcessCurrentDue');
        const nxt = g('remProcessNewDue');
        const btn = g('remProcessBtn');
        if (cur) cur.textContent = currentDue || '—';
        if (nxt) nxt.textContent = newDue || '—';
        if (box) box.classList.remove('d-none');
        if (btn) btn.classList.add('d-none');
      }

      function openRemEditFromRow(row){
        if (!row) return;
        clearProcessingPreview();
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
        // Initialize exact frequency UI from saved values
        initExactFrequencyFromData(row.dataset.frequencyEvery || '', row.dataset.frequencyUnit || '', '');
        updateExactExampleAndPreview();
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
        clearProcessingPreview();
        setv('remId','');
        setv('remDue', todayStr());
        setv('remAmount','');
        setv('remDescription','');
        // Reset exact frequency to default (every 1 month)
        const ev = document.getElementById('remFreqEvery'); if (ev) ev.value = '1';
        const un = document.getElementById('remFreqUnit'); if (un) un.value = 'months';
        updateExactExampleAndPreview();
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

      // Date rolling helper based on frequency (freeform)
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

      // Exact frequency helpers
      function initExactFrequencyFromData(everyRaw, unitRaw, freqStr){
        const f = (freqStr || '').toLowerCase();
        const ev = document.getElementById('remFreqEvery');
        const un = document.getElementById('remFreqUnit');
        if (!ev || !un) return;
        // Prefer explicit saved values
        let every = everyRaw ? Math.max(1, parseInt(everyRaw, 10) || 1) : null;
        let unit = unitRaw || '';
        if (!every || !unit) {
          // Fallback to inference from freeform
          every = 1; unit = 'months';
          if (f.includes('semi') && f.includes('month')) { every = 1; unit = 'semi-monthly'; }
          else if (f.includes('biweek') || f.includes('every 2 week')) { every = 2; unit = 'weeks'; }
          else if (f.includes('quarter')) { every = 3; unit = 'months'; }
          else if (f.includes('year') || f.includes('annual')) { every = 1; unit = 'years'; }
          else {
            const m = f.match(/(\d+)\s*(day|week|month|year)/);
            if (m) {
              every = Math.max(1, parseInt(m[1], 10) || 1);
              const u = m[2];
              unit = (u === 'day') ? 'days' : (u + 's');
            } else if (f.includes('week')) { every = 1; unit = 'weeks'; }
            else if (f.includes('month')) { every = 1; unit = 'months'; }
            else if (f.includes('day')) { every = 1; unit = 'days'; }
          }
        }
        ev.value = String(every);
        un.value = unit;
        // Disable/enable count for semi-monthly
        ev.disabled = (unit === 'semi-monthly');
      }

      function rollForwardDateFromExact(dateStr, everyStr, unit){
        if (!dateStr) return dateStr;
        const d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d.getTime())) return dateStr;
        let every = parseInt(everyStr, 10);
        if (!isFinite(every) || every < 1) every = 1;
        const addDays = (n)=>{ d.setDate(d.getDate()+n); };
        const addMonths = (n)=>{ const day=d.getDate(); d.setMonth(d.getMonth()+n); if (d.getDate()!==day) { d.setDate(0); } };
        const pad2=(n)=> String(n).padStart(2,'0');
        switch (unit) {
          case 'days': addDays(1 * every); break;
          case 'weeks': addDays(7 * every); break;
          case 'months': addMonths(1 * every); break;
          case 'years': d.setFullYear(d.getFullYear() + 1 * every); break;
          case 'semi-monthly':
            const day = d.getDate();
            if (day < 15) { d.setDate(15); }
            else { d.setMonth(d.getMonth()+1); d.setDate(1); }
            break;
          default: addMonths(1); break;
        }
        return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
      }

      function updateExactExampleAndPreview(){
        const ev = document.getElementById('remFreqEvery');
        const un = document.getElementById('remFreqUnit');
        const ex = document.getElementById('remFreqExample');
        const pv = document.getElementById('remFreqPreview');
        const due = g('remDue')?.value || '';
        if (!ev || !un) return;
        const unit = un.value;
        const every = ev.value;
        ev.disabled = (unit === 'semi-monthly');
        if (ex) {
          const prettyUnit = (unit === 'semi-monthly') ? 'semi-monthly' : `${every} ${unit.replace(/s$/, '')}${(parseInt(every,10)===1 && unit!=='semi-monthly')?'':'s'}`;
          ex.textContent = `Example: every ${prettyUnit}`;
        }
        if (pv) {
          if (due) {
            const next = rollForwardDateFromExact(due, every, unit);
            try {
              const dt = new Date(next + 'T00:00:00');
              const fmt = dt.toLocaleDateString(undefined, { weekday:'long', year:'numeric', month:'long', day:'numeric' });
              pv.textContent = `Next due preview: ${fmt}`;
            } catch { pv.textContent = `Next due preview: ${next}`; }
          } else {
            pv.textContent = 'Next due preview: —';
          }
        }
      }

      // Wire changes
      ['remFreqEvery','remFreqUnit','remDue'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updateExactExampleAndPreview);
        if (el) el.addEventListener('change', updateExactExampleAndPreview);
      });

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
        pModalEl.dataset.reminderEvery = row?.dataset.frequencyEvery || '';
        pModalEl.dataset.reminderUnit = row?.dataset.frequencyUnit || '';
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
        pModalEl.dataset.reminderEvery = g('remFreqEvery')?.value || '';
        pModalEl.dataset.reminderUnit = g('remFreqUnit')?.value || '';
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
        const every = pModalEl.dataset.reminderEvery || '';
        const unit = pModalEl.dataset.reminderUnit || '';
        const account = pModalEl.dataset.reminderAccount || '';
        const amount = pModalEl.dataset.reminderAmount || '';
        const description = pModalEl.dataset.reminderDescription || '';
        const newDue = rollForwardDateFromExact(due, every, unit);
        // If we were already in edit, show preview and update the due field
        if (pModalEl.dataset.fromEdit === '1') {
          const before = g('remDue')?.value || due || '';
          setv('remDue', newDue);
          showProcessingPreview(before, newDue);
          return;
        }
        // Otherwise, open edit for this reminder id
        let row = null;
        if (rid) row = document.querySelector(`tr[data-id='${rid}'], tr[data-reminder-id='${rid}']`);
        if (row) {
          openRemEditFromRow(row);
          setTimeout(() => {
            const before = due || row?.dataset?.due || '';
            setv('remDue', newDue);
            showProcessingPreview(before, newDue);
          }, 150);
        } else {
          setv('remId', rid);
          const before = due || '';
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
          modal && modal.show();
          setTimeout(() => { showProcessingPreview(before, newDue); }, 150);
        }
      });
    })();
    </script>
  </body>
  </html>
