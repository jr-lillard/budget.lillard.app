<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../privacy.php';
require_once __DIR__ . '/../plaid.php';

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
$recentLimit = 50;
$recentPage = 1;
$recentTotalRows = 0;
$recentTotalPages = 1;
$recentOffset = 0;
$accPairs = [];
$allAccountPairs = [];
$accounts = [];
$descriptions = [];
$accountActivity = [];
$plaidAvailable = false;
$plaidItems = [];
$plaidAccounts = [];
$plaidReviewRows = [];
$plaidSummaryError = '';
$activityMonths = '0';
$hideClients = '0';
$activityMonths = '0';

/**
 * Send a one-time login code to the given phone number.
 * Returns [bool ok, string message].
 */
function budget_send_login_code_to_phone(string $phone, string $code): array
{
    $normalizedPhone = budget_normalize_phone_for_sms($phone);
    if ($normalizedPhone === '') {
        return [false, 'Invalid phone number'];
    }

    [$smsOk, $smsErr] = send_sms_via_smtp2go($normalizedPhone, "Budget login code: {$code}. Use within 10 minutes.");
    if ($smsOk) {
        return [true, 'Sent via SMS'];
    }

    error_log('SMS code send failed for ' . $normalizedPhone . ': ' . (string)$smsErr);
    return [false, (string)$smsErr];
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

function budget_privacy_status_cell_html(array $row): string
{
    $privacyToken = trim((string)($row['privacy_token'] ?? ''));
    $privacyStatus = strtoupper(trim((string)($row['privacy_status'] ?? '')));
    $privacyEventType = strtoupper(trim((string)($row['privacy_event_type'] ?? '')));
    $privacySyncStatus = strtolower(trim((string)($row['privacy_sync_status'] ?? '')));
    $privacySyncError = trim((string)($row['privacy_sync_error'] ?? ''));

    if ($privacyToken === '' && $privacyStatus === '' && $privacySyncStatus === '') {
        return '';
    }

    $statusClass = 'privacy-status-muted';
    $label = 'linked';
    $titleParts = [];

    if ($privacyStatus !== '') {
        $label = $privacyStatus;
        $titleParts[] = 'Privacy API status: ' . $privacyStatus;
        if ($privacyEventType !== '') {
            $titleParts[] = 'Latest event: ' . $privacyEventType;
        }

        switch ($privacyStatus) {
            case 'SETTLED':
                $statusClass = 'privacy-status-settled';
                break;
            case 'PENDING':
            case 'SETTLING':
                $statusClass = 'privacy-status-open';
                break;
            case 'VOIDED':
            case 'DECLINED':
            case 'BOUNCED':
            case 'EXPIRED':
                $statusClass = 'privacy-status-terminal';
                break;
            default:
                $statusClass = 'privacy-status-muted';
                break;
        }
    } elseif ($privacySyncStatus === 'active') {
        $label = 'queued';
        $statusClass = 'privacy-status-queued';
        $titleParts[] = 'Waiting for Privacy API status';
    } elseif ($privacySyncStatus === 'error') {
        $label = 'error';
        $statusClass = 'privacy-status-error';
        $titleParts[] = 'Privacy sync error';
    }

    if ($privacySyncError !== '') {
        $titleParts[] = $privacySyncError;
    }
    if ($privacyToken !== '') {
        $titleParts[] = 'Token: ' . $privacyToken;
    }

    $title = implode(' | ', $titleParts);

    return '<span class="privacy-status-text ' . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . '"'
        . ($title !== '' ? ' title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"' : '')
        . '>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}

function budget_plaid_status_cell_html(array $row): string
{
    $plaidRowId = (int)($row['plaid_transaction_row_id'] ?? 0);
    if ($plaidRowId <= 0) {
        return '';
    }

    $matchMethod = trim((string)($row['plaid_match_method'] ?? ''));
    $pending = (int)($row['plaid_pending'] ?? 0) === 1;
    $linkCount = max(1, (int)($row['plaid_link_count'] ?? 1));
    $statusClass = 'plaid-status-muted';
    $label = 'linked';

    if ($linkCount > 1) {
        $label = 'multi';
        $statusClass = 'plaid-status-warning';
    } elseif ($pending) {
        $label = 'pending';
        $statusClass = 'plaid-status-pending';
    } elseif ($matchMethod === 'created') {
        $label = 'created';
        $statusClass = 'plaid-status-created';
    } elseif ($matchMethod === 'manual_merge') {
        $label = 'merged';
        $statusClass = 'plaid-status-manual';
    } elseif (str_starts_with($matchMethod, 'amount_')) {
        $label = 'matched';
        $statusClass = 'plaid-status-matched';
    } elseif ($matchMethod !== '') {
        $label = str_replace('_', ' ', $matchMethod);
    }

    $titleParts = ['Plaid status: ' . $label];
    if ($matchMethod !== '') {
        $titleParts[] = 'Match method: ' . $matchMethod;
    }
    if ($linkCount > 1) {
        $titleParts[] = $linkCount . ' active Plaid links';
    }
    $institution = trim((string)($row['plaid_institution_name'] ?? ''));
    $account = trim((string)($row['plaid_account_name'] ?? ''));
    $mask = trim((string)($row['plaid_account_mask'] ?? ''));
    $accountLabel = trim($institution . ($account !== '' ? ' | ' . $account : ''));
    if ($mask !== '') {
        $accountLabel .= ' ...' . $mask;
    }
    if ($accountLabel !== '') {
        $titleParts[] = $accountLabel;
    }
    $plaidDate = trim((string)($row['plaid_date'] ?? ''));
    if ($plaidDate !== '') {
        $titleParts[] = 'Plaid date: ' . $plaidDate;
    }

    return '<span class="plaid-status-text ' . htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') . '"'
        . ' title="' . htmlspecialchars(implode(' | ', $titleParts), ENT_QUOTES, 'UTF-8') . '"'
        . '>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}

$loginFlash = $_SESSION['login_flash'] ?? [];
unset($_SESSION['login_flash']);

$loginFlow = $_SESSION['login_flow'] ?? null;
$loginStep = 'phone';
if (is_array($loginFlow) && isset($loginFlow['username'], $loginFlow['phone'], $loginFlow['code_hash'], $loginFlow['expires']) && (int)$loginFlow['expires'] >= time()) {
    $loginStep = 'code';
} else {
    $loginFlow = null;
    unset($_SESSION['login_flow']);
}
$afterResponseTasks = [];
$flashMessage = trim((string)($loginFlash['message'] ?? ''));
$flashType = $loginFlash['type'] ?? '';
$phoneErrorMessage = ($loginStep === 'phone' && $flashType === 'danger') ? $flashMessage : '';
$codeErrorMessage = ($loginStep === 'code' && $flashType === 'danger') ? $flashMessage : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$loggedIn) {
    $mode = (string)($_POST['mode'] ?? '');
    $self = (string)($_SERVER['PHP_SELF'] ?? 'index.php');

    if ($mode === 'request-code') {
        $phoneInput = trim((string)($_POST['phone'] ?? ''));
        $phone = budget_normalize_phone_for_sms($phoneInput);
        if ($phone === '') {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Enter a valid phone number.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }

        try {
            $pdo = $pdo ?? get_mysql_connection();
            $username = budget_lookup_user_by_phone($pdo, $phone);
        } catch (Throwable $e) {
            error_log('Phone login lookup failed: ' . $e->getMessage());
            $username = null;
        }

        if ($username === null || $username === '') {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'No account found for that phone number.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['login_flow'] = [
            'username' => budget_canonical_user($username),
            'phone' => $phone,
            'code_hash' => hash('sha256', $code),
            'expires' => time() + 600,
            'attempts' => 0,
            'last_sent' => time(),
        ];
        unset($_SESSION['login_flash']);
        $afterResponseTasks[] = static function () use ($phone, $code): void {
            [$ok, $err] = budget_send_login_code_to_phone($phone, $code);
            if (!$ok) {
                error_log(sprintf('Login code SMS failed for %s: %s', $phone, (string)$err));
            }
        };
        budget_redirect($self, $afterResponseTasks);
    } elseif ($mode === 'verify-code') {
        $flow = $_SESSION['login_flow'] ?? null;
        $codeDigits = preg_replace('/[^0-9]/', '', (string)($_POST['code'] ?? ''));
        if (!is_array($flow) || !isset($flow['username'], $flow['phone'], $flow['code_hash'], $flow['expires'])) {
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
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Enter the 6-digit code from the text message.'];
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
        $username = budget_canonical_user((string)$flow['username']);
        $_SESSION['username'] = $username;
        try {
            $pdo = $pdo ?? get_mysql_connection();
            auth_issue_remember_cookie($pdo, $username);
        } catch (Throwable $e) {
            // ignore remember-me errors
        }
        unset($_SESSION['login_flash']);
        budget_redirect($self);
    } elseif ($mode === 'resend-code') {
        $flow = $_SESSION['login_flow'] ?? null;
        if (!is_array($flow) || empty($flow['username']) || empty($flow['phone'])) {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Request a new login code to continue.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }
        $username = budget_canonical_user((string)$flow['username']);
        $phone = budget_normalize_phone_for_sms((string)$flow['phone']);
        if ($phone === '') {
            $_SESSION['login_flash'] = ['type' => 'danger', 'message' => 'Request a new login code to continue.'];
            unset($_SESSION['login_flow']);
            budget_redirect($self);
        }
        $lastSent = (int)($flow['last_sent'] ?? 0);
        if ($lastSent > time() - 30) {
            budget_redirect($self);
        }
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['login_flow'] = [
            'username' => $username,
            'phone' => $phone,
            'code_hash' => hash('sha256', $code),
            'expires' => time() + 600,
            'attempts' => 0,
            'last_sent' => time(),
        ];
        unset($_SESSION['login_flash']);
        $afterResponseTasks[] = static function () use ($phone, $code): void {
            [$ok, $err] = budget_send_login_code_to_phone($phone, $code);
            if (!$ok) {
                error_log(sprintf('Login SMS resend failed for %s: %s', $phone, (string)$err));
            }
        };
        budget_redirect($self, $afterResponseTasks);
    } elseif ($mode === 'change-phone' || $mode === 'change-email') {
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
      .combo-suggestions {
        position: absolute;
        top: calc(100% + 0.25rem);
        left: 0;
        right: 0;
        z-index: 1065;
        max-height: 14rem;
        overflow-y: auto;
        border: 1px solid rgba(0, 0, 0, 0.125);
        border-radius: 0.5rem;
        background: var(--bs-body-bg);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.12);
      }
      .combo-suggestions button {
        border: 0;
        border-radius: 0;
        background: transparent;
        text-align: left;
      }
      .combo-suggestions button:hover,
      .combo-suggestions button:focus-visible {
        background: var(--bs-tertiary-bg);
      }
      .combo-suggestions button.active,
      .combo-suggestions button.active:hover,
      .combo-suggestions button.active:focus-visible {
        background: var(--bs-primary);
        color: #fff;
      }
      .stale-scheduled-row .tx-click-edit {
        opacity: 0.55;
      }
      .privacy-status-col,
      .plaid-status-col {
        width: 6.75rem;
        white-space: nowrap;
      }
      .privacy-status-cell,
      .plaid-status-cell {
        text-align: center;
      }
      .privacy-status-text,
      .plaid-status-text {
        display: inline-block;
        min-width: 5.75rem;
        font-size: 0.68rem;
        font-weight: 600;
        letter-spacing: 0;
        text-transform: uppercase;
        color: var(--bs-secondary-color);
      }
      .privacy-status-settled {
        color: var(--bs-success-text-emphasis);
      }
      .privacy-status-open {
        color: var(--bs-warning-text-emphasis);
      }
      .privacy-status-terminal,
      .privacy-status-error {
        color: var(--bs-danger-text-emphasis);
      }
      .privacy-status-queued {
        color: var(--bs-info-text-emphasis);
      }
      .plaid-status-matched,
      .plaid-status-manual {
        color: var(--bs-success-text-emphasis);
      }
      .plaid-status-created,
      .plaid-status-pending,
      .plaid-status-warning {
        color: var(--bs-warning-text-emphasis);
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
          <a class="btn btn-outline-primary w-100 mb-2" href="profile.php">Profile</a>
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
            try { privacy_ensure_sync_table($pdo); } catch (Throwable $e) { /* ignore if unavailable */ }
            try { plaid_ensure_tables($pdo); } catch (Throwable $e) { $plaidSummaryError = 'Plaid setup needs attention.'; }
            $defaultOwner = budget_default_owner();
            budget_ensure_owner_column($pdo, 'transactions', 'owner', $defaultOwner);
            budget_ensure_owner_column($pdo, 'reminders', 'owner', $defaultOwner);
            $owner = $currentUser;
            $plaidAvailable = plaid_has_config('production');
            if ($plaidSummaryError === '') {
                try {
                    $plaidItems = plaid_fetch_items($pdo, $owner);
                    $plaidAccounts = plaid_fetch_account_mappings($pdo, $owner);
                    $plaidReviewRows = plaid_fetch_unmatched_review($pdo, $owner, 25);
                } catch (Throwable $e) {
                    $plaidSummaryError = 'Unable to load Plaid connections.';
                }
            }
            $recentPage = max(1, (int)($_GET['recent_page'] ?? 1));
            // Activity filter persistence
            $validActivityOptions = ['0','3','6','9','12'];
            $activityMonths = isset($_GET['activity_months']) ? (string)$_GET['activity_months'] : (string)($_COOKIE['activity_months'] ?? '0');
            if (!in_array($activityMonths, $validActivityOptions, true)) {
                $activityMonths = '0';
            }
            $hideClients = isset($_GET['hide_clients']) ? '1' : ((string)($_COOKIE['hide_clients'] ?? '0') === '1' ? '1' : '0');
            // remember selection
            setcookie('activity_months', $activityMonths, [
                'expires' => time() + 365*24*60*60,
                'path' => '/',
                'secure' => auth_is_https(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            setcookie('hide_clients', $hideClients, [
                'expires' => time() + 365*24*60*60,
                'path' => '/',
                'secure' => auth_is_https(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
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

            $countSql = 'SELECT COUNT(*) FROM transactions t WHERE t.owner = ?';
            $countParams = [$owner];
            if ($filterAccountId > 0) {
                $countSql .= ' AND t.account_id = ?';
                $countParams[] = $filterAccountId;
            }
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($countParams);
            $recentTotalRows = (int)($countStmt->fetchColumn() ?: 0);
            $recentTotalPages = max(1, (int)ceil($recentTotalRows / $recentLimit));
            if ($recentPage > $recentTotalPages) {
                $recentPage = $recentTotalPages;
            }
            $recentOffset = ($recentPage - 1) * $recentLimit;

            $sql = 'SELECT t.id, t.`date`, t.amount, t.description, t.check_no, t.posted, t.status, t.updated_at_source,
                       t.account_id, t.fm_pk, a.name AS account_name,
                       pts.transaction_token AS privacy_token,
                       pts.latest_transaction_status AS privacy_status,
                       pts.latest_event_type AS privacy_event_type,
                       pts.latest_result AS privacy_result,
                       pts.latest_merchant_descriptor AS privacy_merchant_descriptor,
                       pts.latest_created_at AS privacy_created_at,
                       pts.latest_event_at AS privacy_event_at,
                       pts.last_checked_at AS privacy_last_checked_at,
                       pts.sync_status AS privacy_sync_status,
                       pts.last_error AS privacy_sync_error,
                       pt.id AS plaid_transaction_row_id,
                       pt.plaid_transaction_id AS plaid_transaction_token,
                       pt.match_method AS plaid_match_method,
                       pt.pending AS plaid_pending,
                       pt.date AS plaid_date,
                       pt.authorized_date AS plaid_authorized_date,
                       pt.amount AS plaid_amount,
                       pt.name AS plaid_name,
                       pt.merchant_name AS plaid_merchant_name,
                       pt_link.plaid_link_count,
                       pi.institution_name AS plaid_institution_name,
                       pa.name AS plaid_account_name,
                       pa.mask AS plaid_account_mask
                    FROM transactions t
                    LEFT JOIN accounts a ON a.id = t.account_id
                    LEFT JOIN privacy_transaction_sync pts ON pts.transaction_token = t.fm_pk
                    LEFT JOIN (
                        SELECT budget_transaction_id, MIN(id) AS plaid_transaction_row_id, COUNT(*) AS plaid_link_count
                        FROM plaid_transactions
                        WHERE removed = 0 AND budget_transaction_id IS NOT NULL
                        GROUP BY budget_transaction_id
                    ) pt_link ON pt_link.budget_transaction_id = t.id
                    LEFT JOIN plaid_transactions pt ON pt.id = pt_link.plaid_transaction_row_id
                    LEFT JOIN plaid_items pi ON pi.id = pt.plaid_item_id
                    LEFT JOIN plaid_accounts pa
                      ON pa.plaid_item_id = pt.plaid_item_id
                     AND pa.plaid_account_id = pt.plaid_account_id
                    WHERE t.owner = ?';
            if ($filterAccountId > 0) { $sql .= ' AND t.account_id = ?'; }
            $sql .= ' ORDER BY COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) ASC, t.`date` DESC, t.updated_at DESC LIMIT ? OFFSET ?';
            $stmt = $pdo->prepare($sql);
            $paramIndex = 1;
            $stmt->bindValue($paramIndex++, $owner, PDO::PARAM_STR);
            if ($filterAccountId > 0) {
                $stmt->bindValue($paramIndex++, $filterAccountId, PDO::PARAM_INT);
            }
            $stmt->bindValue($paramIndex++, $recentLimit, PDO::PARAM_INT);
            $stmt->bindValue($paramIndex++, $recentOffset, PDO::PARAM_INT);
            $stmt->execute();
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

            // Account activity grouped by account (all time), with optional inactivity filter
            $activitySql = "SELECT COALESCE(a.id, 0) AS account_id,
                                   COALESCE(a.name, '(No account)') AS account_name,
                                   SUM(CASE WHEN COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) = 0 THEN t.amount ELSE 0 END) AS scheduled_total,
                                   SUM(CASE WHEN COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) = 1 THEN t.amount ELSE 0 END) AS pending_total,
                                   SUM(CASE WHEN COALESCE(t.status, CASE WHEN t.posted = 1 THEN 2 ELSE 1 END) = 2 THEN t.amount ELSE 0 END) AS posted_total,
                                   MAX(t.`date`) AS last_tx_date,
                                   MAX(IFNULL(a.is_client, 0)) AS is_client
                            FROM transactions t
                            LEFT JOIN accounts a ON a.id = t.account_id
                            WHERE t.owner = ?
                            GROUP BY account_id, account_name
                            HAVING (scheduled_total IS NOT NULL OR pending_total IS NOT NULL OR posted_total IS NOT NULL)";
            $paramsActivity = [$owner];
            if ($activityMonths !== '0') {
                $activitySql .= " AND last_tx_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)";
                $paramsActivity[] = (int)$activityMonths;
            }
            if ($hideClients === '1') {
                $activitySql .= " AND IFNULL(is_client,0) = 0";
            }
            $activitySql .= " ORDER BY account_name ASC";
            $actStmt = $pdo->prepare($activitySql);
            $actStmt->execute($paramsActivity);
            $accountActivity = $actStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
            $allAccStmt = $pdo->query('SELECT id, name FROM accounts WHERE name IS NOT NULL AND name <> "" ORDER BY name ASC');
            $allAccountPairs = $allAccStmt ? ($allAccStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: []) : [];
            // Keep names array for modal account select
            $accounts = array_values(!empty($allAccountPairs) ? $allAccountPairs : $accPairs);

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
          <?php if (!empty($accountActivity)): ?>
            <div class="card mb-3 shadow-sm">
              <div class="card-body">
                <form class="d-flex flex-wrap gap-2 align-items-center mb-2" method="get" action="">
                  <label class="form-label mb-0" for="activity_months">Hide inactive &gt;</label>
                  <select id="activity_months" name="activity_months" class="form-select form-select-sm" style="width: 160px;">
                    <option value="0" <?= $activityMonths === '0' ? 'selected' : '' ?>>Show all accounts</option>
                    <option value="3" <?= $activityMonths === '3' ? 'selected' : '' ?>>3 months</option>
                    <option value="6" <?= $activityMonths === '6' ? 'selected' : '' ?>>6 months</option>
                    <option value="9" <?= $activityMonths === '9' ? 'selected' : '' ?>>9 months</option>
                    <option value="12" <?= $activityMonths === '12' ? 'selected' : '' ?>>12 months</option>
                  </select>
                  <div class="form-check form-check-inline ms-2">
                    <input class="form-check-input" type="checkbox" id="hide_clients" name="hide_clients" value="1" <?= $hideClients === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="hide_clients">Hide clients</label>
                  </div>
                  <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
                </form>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">Account</th>
                        <th scope="col" class="text-end">Scheduled</th>
                        <th scope="col" class="text-end">Pending</th>
                        <th scope="col" class="text-end">Posted</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php
                        $totSched = 0.0; $totPend = 0.0; $totPost = 0.0;
                        $activityFilterQueryBase = [];
                        if ($activityMonths !== '0') {
                          $activityFilterQueryBase['activity_months'] = $activityMonths;
                        }
                        if ($hideClients === '1') {
                          $activityFilterQueryBase['hide_clients'] = '1';
                        }
                      ?>
                      <?php foreach ($accountActivity as $acct):
                        $activityAccountId = (int)($acct['account_id'] ?? 0);
                        $activityAccountName = (string)($acct['account_name'] ?? '');
                        $sched = (float)($acct['scheduled_total'] ?? 0);
                        $pend = (float)($acct['pending_total'] ?? 0);
                        $post = (float)($acct['posted_total'] ?? 0);
                        $isClient = (int)($acct['is_client'] ?? 0) === 1;
                        $fmt = fn($v) => '$' . number_format($v, 2);
                        $cls = fn($v) => $v < 0 ? 'text-danger' : 'text-success';
                        $totSched += $sched; $totPend += $pend; $totPost += $post;
                        $postDisplay = $post;
                        $pendDisplay = $post + $pend;
                        $schedDisplay = $post + $pend + $sched;
                        $activityAccountQuery = $activityFilterQueryBase;
                        if ($activityAccountId > 0) {
                          $activityAccountQuery['account_id'] = $activityAccountId;
                        }
                        $activityAccountHref = $activityAccountId > 0 ? ('?' . http_build_query($activityAccountQuery)) : '';
                        $activityAccountLinkClass = 'link-body-emphasis text-decoration-none';
                        if ($activityAccountId > 0 && $activityAccountId === (int)$filterAccountId) {
                          $activityAccountLinkClass .= ' fw-semibold';
                        }
                      ?>
                        <tr>
                          <td>
                            <div class="d-flex align-items-center gap-2">
                              <?php if ($activityAccountId > 0): ?>
                                <a class="<?= htmlspecialchars($activityAccountLinkClass) ?>" href="<?= htmlspecialchars($activityAccountHref) ?>" title="Show recent transactions for this account">
                                  <?= htmlspecialchars($activityAccountName) ?>
                                </a>
                              <?php else: ?>
                                <span><?= htmlspecialchars($activityAccountName) ?></span>
                              <?php endif; ?>
                              <?php if ($isClient): ?><span class="badge text-bg-warning">Client</span><?php endif; ?>
                              <div class="dropdown ms-auto">
                                <button class="btn btn-sm border-0 text-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account actions">
                                  <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu">
                                  <li>
                                    <form method="post" action="account_client_toggle.php" class="px-3 py-1">
                                      <input type="hidden" name="account_name" value="<?= htmlspecialchars((string)($acct['account_name'] ?? '')) ?>">
                                      <input type="hidden" name="is_client" value="<?= $isClient ? '0' : '1' ?>">
                                      <button type="submit" class="btn btn-link p-0">
                                        <?= $isClient ? 'Unmark client' : 'Mark client' ?>
                                      </button>
                                    </form>
                                  </li>
                                </ul>
                              </div>
                            </div>
                          </td>
                          <td class="text-end <?= $cls($schedDisplay) ?>"><?= $fmt($schedDisplay) ?></td>
                          <td class="text-end <?= $cls($pendDisplay) ?>"><?= $fmt($pendDisplay) ?></td>
                          <td class="text-end <?= $cls($postDisplay) ?>"><?= $fmt($postDisplay) ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <tr class="table-light fw-semibold">
                        <td>Totals</td>
                        <?php
                          $totPostDisplay = $totPost;
                          $totPendDisplay = $totPost + $totPend;
                          $totSchedDisplay = $totPost + $totPend + $totSched;
                        ?>
                        <td class="text-end <?= ($totSchedDisplay < 0 ? 'text-danger' : 'text-success') ?>"><?= '$' . number_format($totSchedDisplay, 2) ?></td>
                        <td class="text-end <?= ($totPendDisplay < 0 ? 'text-danger' : 'text-success') ?>"><?= '$' . number_format($totPendDisplay, 2) ?></td>
                        <td class="text-end <?= ($totPostDisplay < 0 ? 'text-danger' : 'text-success') ?>"><?= '$' . number_format($totPostDisplay, 2) ?></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <div class="d-flex flex-wrap align-items-center justify-content-between mb-2 gap-2">
            <div class="d-flex align-items-center gap-2">
              <h2 class="h5 mb-0">Recent Transactions</h2>
              <button type="button" class="btn btn-sm btn-success" id="addTxBtn"
                data-account-name="<?= (isset($filterAccountId) && $filterAccountId > 0 && isset($accPairs[$filterAccountId])) ? htmlspecialchars((string)$accPairs[$filterAccountId]) : '' ?>">
                + Add Transaction
              </button>
              <button type="button" class="btn btn-sm btn-outline-primary" id="transferBtn"
                data-account-id="<?= (int)($filterAccountId ?? 0) ?>">
                Transfer
              </button>
              <a class="btn btn-sm btn-outline-secondary" href="reminders.php<?= ($filterAccountId>0? ('?account_id='.(int)$filterAccountId) : '') ?>">Reminders</a>
              <a class="btn btn-sm btn-outline-secondary" href="payments.php">Payments</a>
              <a class="btn btn-sm btn-outline-secondary" href="transactions.php">All transactions</a>
              <?php if ($plaidAvailable): ?>
                <button type="button" class="btn btn-sm btn-outline-success" id="connectPlaidBtn">
                  <i class="bi bi-bank"></i> Connect Plaid
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="syncPlaidBtn" <?= empty($plaidItems) ? 'disabled' : '' ?>>
                  <i class="bi bi-arrow-repeat"></i> Sync Plaid
                </button>
              <?php endif; ?>
            </div>
            <form class="d-flex align-items-center gap-2" method="get" action="" id="dashboardFilterForm">
              <label for="filterAccount" class="form-label mb-0">Account</label>
              <select id="filterAccount" name="account_id" class="form-select form-select-sm" style="min-width: 240px;">
                <option value="">All accounts</option>
                <?php if (!empty($accPairs)) foreach ($accPairs as $aid => $aname): ?>
                  <option value="<?= (int)$aid ?>" <?= (isset($filterAccountId) && (int)$filterAccountId === (int)$aid) ? 'selected' : '' ?>><?= htmlspecialchars((string)$aname) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
          <?php if ($plaidSummaryError !== ''): ?>
            <div class="alert alert-warning py-2 small" role="alert"><?= htmlspecialchars($plaidSummaryError) ?></div>
          <?php elseif (!empty($plaidItems)): ?>
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2 small text-body-secondary">
              <span class="fw-semibold text-body">Plaid</span>
              <?php foreach ($plaidItems as $plaidItem): ?>
                <?php
                  $plaidName = trim((string)($plaidItem['institution_name'] ?? '')) ?: 'Linked item';
                  $plaidLastSynced = trim((string)($plaidItem['last_synced_at'] ?? ''));
                  $plaidStatus = trim((string)($plaidItem['sync_status'] ?? ''));
                  $plaidError = trim((string)($plaidItem['last_error'] ?? ''));
                  $plaidTitle = $plaidLastSynced !== '' ? ('Last synced ' . $plaidLastSynced . ' UTC') : 'Not synced yet';
                  if ($plaidError !== '') { $plaidTitle .= ' | ' . $plaidError; }
                ?>
	                  <span class="badge rounded-pill <?= $plaidError !== '' ? 'text-bg-warning' : 'text-bg-light' ?>" title="<?= htmlspecialchars($plaidTitle, ENT_QUOTES, 'UTF-8') ?>">
	                    <?= htmlspecialchars($plaidName) ?>
	                    · <?= (int)($plaidItem['mapped_account_count'] ?? 0) ?>/<?= (int)($plaidItem['account_count'] ?? 0) ?> mapped
	                    · <?= (int)($plaidItem['matched_transaction_count'] ?? 0) ?>/<?= (int)($plaidItem['transaction_count'] ?? 0) ?> matched
	                    <?= $plaidStatus !== '' && $plaidStatus !== 'active' ? ' · ' . htmlspecialchars($plaidStatus) : '' ?>
	                  </span>
	                <?php endforeach; ?>
	            </div>
	            <?php if (!empty($plaidAccounts)): ?>
	              <div class="card mb-3 shadow-sm">
	                <div class="card-body">
	                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
	                    <h3 class="h6 mb-0">Plaid Account Mapping</h3>
	                  </div>
	                  <div class="table-responsive">
	                    <table class="table table-sm align-middle mb-0">
	                      <thead class="table-light">
	                        <tr>
	                          <th scope="col">Plaid Account</th>
	                          <th scope="col">Budget Account</th>
	                          <th scope="col" class="text-end">Matches</th>
	                        </tr>
	                      </thead>
	                      <tbody>
	                        <?php foreach ($plaidAccounts as $plaidAccount): ?>
	                          <?php
	                            $plaidAccountLabel = trim((string)($plaidAccount['official_name'] ?? ''));
	                            if ($plaidAccountLabel === '') { $plaidAccountLabel = trim((string)($plaidAccount['name'] ?? '')); }
	                            if ($plaidAccountLabel === '') { $plaidAccountLabel = 'Plaid account'; }
	                            $plaidMask = trim((string)($plaidAccount['mask'] ?? ''));
	                            $plaidInstitution = trim((string)($plaidAccount['institution_name'] ?? ''));
	                            $plaidType = trim(implode(' ', array_filter([
	                              (string)($plaidAccount['type'] ?? ''),
	                              (string)($plaidAccount['subtype'] ?? ''),
	                            ])));
	                            $plaidMappedId = (int)($plaidAccount['local_account_id'] ?? 0);
	                            $plaidTxCount = (int)($plaidAccount['transaction_count'] ?? 0);
	                            $plaidMatchedCount = (int)($plaidAccount['matched_transaction_count'] ?? 0);
	                            $plaidUnmatchedCount = (int)($plaidAccount['unmatched_transaction_count'] ?? 0);
	                          ?>
	                          <tr>
	                            <td>
	                              <div class="fw-semibold"><?= htmlspecialchars($plaidAccountLabel) ?><?= $plaidMask !== '' ? ' ' . htmlspecialchars('...' . $plaidMask) : '' ?></div>
	                              <div class="small text-body-secondary">
	                                <?= htmlspecialchars(trim($plaidInstitution . ($plaidType !== '' ? ' · ' . $plaidType : ''))) ?>
	                              </div>
	                            </td>
	                            <td style="min-width: 260px;">
	                              <select class="form-select form-select-sm plaid-account-map" data-plaid-account-id="<?= (int)$plaidAccount['id'] ?>">
	                                <option value="">Not mapped</option>
	                                <?php foreach ($allAccountPairs as $budgetAccountId => $budgetAccountName): ?>
	                                  <option value="<?= (int)$budgetAccountId ?>" <?= $plaidMappedId === (int)$budgetAccountId ? 'selected' : '' ?>>
	                                    <?= htmlspecialchars((string)$budgetAccountName) ?>
	                                  </option>
	                                <?php endforeach; ?>
	                              </select>
	                            </td>
	                            <td class="text-end">
	                              <span class="badge text-bg-light"><?= $plaidMatchedCount ?>/<?= $plaidTxCount ?></span>
	                              <?php if ($plaidUnmatchedCount > 0): ?>
	                                <span class="badge text-bg-warning"><?= $plaidUnmatchedCount ?> unmatched</span>
	                              <?php endif; ?>
	                            </td>
	                          </tr>
	                        <?php endforeach; ?>
	                      </tbody>
	                    </table>
	                  </div>
	                </div>
	              </div>
	            <?php endif; ?>
	            <?php if (!empty($plaidReviewRows)): ?>
	              <div class="card mb-3 shadow-sm">
	                <div class="card-body">
	                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
	                    <h3 class="h6 mb-0">Unmatched Plaid Transactions</h3>
	                  </div>
	                  <div class="table-responsive">
	                    <table class="table table-sm align-middle mb-0">
	                      <thead class="table-light">
	                        <tr>
	                          <th scope="col">Plaid</th>
	                          <th scope="col" class="text-end">Amount</th>
	                          <th scope="col">Merge With</th>
	                          <th scope="col" class="text-end">Action</th>
	                        </tr>
	                      </thead>
	                      <tbody>
	                        <?php foreach ($plaidReviewRows as $plaidRow): ?>
	                          <?php
	                            $plaidDesc = trim((string)($plaidRow['merchant_name'] ?? ''));
	                            if ($plaidDesc === '') { $plaidDesc = trim((string)($plaidRow['name'] ?? '')); }
	                            if ($plaidDesc === '') { $plaidDesc = 'Plaid transaction'; }
	                            $plaidDate = trim((string)($plaidRow['date'] ?? ''));
	                            $plaidBudgetAmount = $plaidRow['budget_amount'];
	                            $plaidAmountClass = is_numeric($plaidBudgetAmount) && (float)$plaidBudgetAmount < 0 ? 'text-danger' : 'text-success';
	                            $plaidAmountFmt = is_numeric($plaidBudgetAmount) ? number_format((float)$plaidBudgetAmount, 2) : '';
	                            $plaidCandidates = is_array($plaidRow['candidates'] ?? null) ? $plaidRow['candidates'] : [];
	                          ?>
	                          <tr data-plaid-transaction-id="<?= (int)$plaidRow['id'] ?>">
	                            <td>
	                              <div class="fw-semibold"><?= htmlspecialchars($plaidDesc) ?></div>
	                              <div class="small text-body-secondary">
	                                <?= htmlspecialchars(trim((string)($plaidRow['local_account_name'] ?? ''))) ?>
	                                <?= $plaidDate !== '' ? ' · ' . htmlspecialchars($plaidDate) : '' ?>
	                                <?= trim((string)($plaidRow['match_method'] ?? '')) !== '' ? ' · ' . htmlspecialchars((string)$plaidRow['match_method']) : '' ?>
	                              </div>
	                            </td>
	                            <td class="text-end <?= $plaidAmountClass ?>"><?= $plaidAmountFmt ?></td>
	                            <td style="min-width: 360px;">
	                              <select class="form-select form-select-sm plaid-merge-target" data-plaid-transaction-id="<?= (int)$plaidRow['id'] ?>">
	                                <option value="">Select transaction...</option>
	                                <?php foreach ($plaidCandidates as $candidate): ?>
	                                  <?php
	                                    $candidateAmount = $candidate['amount'] ?? '';
	                                    $candidateLabel = trim(implode(' · ', array_filter([
	                                      (string)($candidate['date'] ?? ''),
	                                      (string)($candidate['description'] ?? ''),
	                                      is_numeric($candidateAmount) ? number_format((float)$candidateAmount, 2) : (string)$candidateAmount,
	                                    ])));
	                                  ?>
	                                  <option value="<?= (int)$candidate['id'] ?>"><?= htmlspecialchars($candidateLabel) ?></option>
	                                <?php endforeach; ?>
	                              </select>
	                            </td>
	                            <td class="text-end">
	                              <div class="btn-group btn-group-sm" role="group" aria-label="Plaid transaction actions">
	                                <button type="button" class="btn btn-outline-primary plaid-merge-btn" <?= empty($plaidCandidates) ? 'disabled' : '' ?>>
	                                  Merge
	                                </button>
	                                <button type="button"
	                                        class="btn btn-outline-danger plaid-delete-btn"
	                                        data-plaid-transaction-id="<?= (int)$plaidRow['id'] ?>"
	                                        aria-label="Delete Plaid transaction"
	                                        title="Delete Plaid transaction">
	                                  <i class="bi bi-trash" aria-hidden="true"></i>
	                                </button>
	                              </div>
	                            </td>
	                          </tr>
	                        <?php endforeach; ?>
	                      </tbody>
	                    </table>
	                  </div>
	                </div>
	              </div>
	            <?php endif; ?>
	          <?php endif; ?>
          <?php
            // Precompute classes and formatted values for section header totals
            $sumClass = $totalAmount < 0 ? 'text-danger' : 'text-success';
            $sumFmt = number_format($totalAmount, 2);
            $postedClass = $postedTotalAmount < 0 ? 'text-danger' : 'text-success';
            $postedFmt = number_format($postedTotalAmount, 2);
            $projectedWithSched = ($totalAmount ?? 0) + ($schedTotalAmount ?? 0);
            $projClass = $projectedWithSched < 0 ? 'text-danger' : 'text-success';
            $projFmt = number_format($projectedWithSched, 2);
            $showRecentAccountColumn = ((int)$filterAccountId <= 0);
            $recentAccountColumnClass = $showRecentAccountColumn ? '' : 'd-none';
            $recentAccountCellClass = trim($recentAccountColumnClass . ' tx-click-edit');
            $recentPrivacyColumnClass = 'privacy-status-col privacy-status-cell';
            $recentPrivacyCellClass = trim($recentPrivacyColumnClass . ' tx-click-edit');
            $recentPlaidColumnClass = 'plaid-status-col plaid-status-cell';
            $recentPlaidCellClass = trim($recentPlaidColumnClass . ' tx-click-edit');
            $recentSpacerColspan = $showRecentAccountColumn ? 7 : 6;
            $recentHasPrev = $recentPage > 1;
            $recentHasNext = $recentPage < $recentTotalPages;
            $recentPageFrom = $recentTotalRows > 0 ? ($recentOffset + 1) : 0;
            $recentPageTo = min($recentOffset + $recentLimit, $recentTotalRows);
            $recentQueryBase = [];
            if ((int)$filterAccountId > 0) {
              $recentQueryBase['account_id'] = (int)$filterAccountId;
            }
            if ($activityMonths !== '0') {
              $recentQueryBase['activity_months'] = $activityMonths;
            }
            if ($hideClients === '1') {
              $recentQueryBase['hide_clients'] = '1';
            }
            $recentPrevQuery = $recentQueryBase;
            $recentPrevQuery['recent_page'] = max(1, $recentPage - 1);
            $recentNextQuery = $recentQueryBase;
            $recentNextQuery['recent_page'] = min($recentTotalPages, $recentPage + 1);
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
                    <th scope="col" class="<?= $recentAccountColumnClass ?>">Account</th>
                    <th scope="col">Description</th>
                    <th scope="col" class="<?= $recentPrivacyColumnClass ?>">Privacy</th>
                    <th scope="col" class="<?= $recentPlaidColumnClass ?>">Plaid</th>
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
                    $staleScheduledCutoff = date('Y-m-d', strtotime('-7 days'));
                  ?>
                  <?php if (!empty($scheduledRows)): ?>
                    <tr class="table-active">
                      <td>Scheduled</td>
                      <td class="<?= $recentAccountColumnClass ?>"></td>
                      <td></td>
                      <td class="<?= $recentPrivacyColumnClass ?>"></td>
                      <td class="<?= $recentPlaidColumnClass ?>"></td>
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
                        $staleScheduledClass = ($date !== '' && $date < $staleScheduledCutoff) ? 'stale-scheduled-row' : '';
                        $privacyStatusCell = budget_privacy_status_cell_html($row);
                        $plaidStatusCell = budget_plaid_status_cell_html($row);
                      ?>
                      <?php
                        $dateCell = '';
                        if ($date !== $lastSchedDate) { $dateCell = htmlspecialchars((string)$date); $lastSchedDate = $date; }
                      ?>
                      <tr class="<?= $staleScheduledClass ?>" data-id="<?= $txId ?>"
                          data-date="<?= htmlspecialchars((string)$date) ?>"
                          data-amount="<?= htmlspecialchars((string)$row['amount']) ?>"
                          data-account="<?= htmlspecialchars((string)$acct) ?>"
                          data-description="<?= htmlspecialchars((string)$desc) ?>"
                          data-check="<?= htmlspecialchars((string)($row['check_no'] ?? '')) ?>"
                          data-privacy-token="<?= htmlspecialchars((string)($row['privacy_token'] ?? '')) ?>"
                          data-privacy-status="<?= htmlspecialchars((string)($row['privacy_status'] ?? '')) ?>"
                          data-privacy-event-type="<?= htmlspecialchars((string)($row['privacy_event_type'] ?? '')) ?>"
                          data-privacy-result="<?= htmlspecialchars((string)($row['privacy_result'] ?? '')) ?>"
                          data-privacy-merchant="<?= htmlspecialchars((string)($row['privacy_merchant_descriptor'] ?? '')) ?>"
                          data-privacy-created-at="<?= htmlspecialchars((string)($row['privacy_created_at'] ?? '')) ?>"
                          data-privacy-event-at="<?= htmlspecialchars((string)($row['privacy_event_at'] ?? '')) ?>"
                          data-privacy-last-checked-at="<?= htmlspecialchars((string)($row['privacy_last_checked_at'] ?? '')) ?>"
                          data-privacy-sync-status="<?= htmlspecialchars((string)($row['privacy_sync_status'] ?? '')) ?>"
                          data-privacy-sync-error="<?= htmlspecialchars((string)($row['privacy_sync_error'] ?? '')) ?>"
                          data-plaid-transaction-row-id="<?= (int)($row['plaid_transaction_row_id'] ?? 0) ?>"
                          data-plaid-transaction-token="<?= htmlspecialchars((string)($row['plaid_transaction_token'] ?? '')) ?>"
                          data-plaid-match-method="<?= htmlspecialchars((string)($row['plaid_match_method'] ?? '')) ?>"
                          data-plaid-pending="<?= (int)($row['plaid_pending'] ?? 0) ?>"
                          data-plaid-date="<?= htmlspecialchars((string)($row['plaid_date'] ?? '')) ?>"
                          data-plaid-authorized-date="<?= htmlspecialchars((string)($row['plaid_authorized_date'] ?? '')) ?>"
                          data-plaid-amount="<?= htmlspecialchars((string)($row['plaid_amount'] ?? '')) ?>"
                          data-plaid-name="<?= htmlspecialchars((string)($row['plaid_name'] ?? '')) ?>"
                          data-plaid-merchant-name="<?= htmlspecialchars((string)($row['plaid_merchant_name'] ?? '')) ?>"
                          data-plaid-link-count="<?= (int)($row['plaid_link_count'] ?? 0) ?>"
                          data-plaid-institution="<?= htmlspecialchars((string)($row['plaid_institution_name'] ?? '')) ?>"
                          data-plaid-account="<?= htmlspecialchars((string)($row['plaid_account_name'] ?? '')) ?>"
                          data-plaid-mask="<?= htmlspecialchars((string)($row['plaid_account_mask'] ?? '')) ?>"
                          data-status="0"
                          data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                        <td class="tx-click-edit" role="button"><?= $dateCell ?></td>
                        <td class="<?= $recentAccountCellClass ?>" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                        <td class="text-truncate tx-click-edit" role="button" style="max-width: 480px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                        <td class="<?= $recentPrivacyCellClass ?>" role="button"><?= $privacyStatusCell ?></td>
                        <td class="<?= $recentPlaidCellClass ?>" role="button"><?= $plaidStatusCell ?></td>
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
                    <tr class="spacer-row"><td colspan="<?= $recentSpacerColspan ?>"></td></tr>
                    <tr class="spacer-row"><td colspan="<?= $recentSpacerColspan ?>"></td></tr>
                  <?php endif; ?>
                  <?php if (!empty($pendingRows)): ?>
                    <tr class="table-active">
                      <td>Pending</td>
                      <td class="<?= $recentAccountColumnClass ?>"></td>
                      <td></td>
                      <td class="<?= $recentPrivacyColumnClass ?>"></td>
                      <td class="<?= $recentPlaidColumnClass ?>"></td>
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
                        $privacyStatusCell = budget_privacy_status_cell_html($row);
                        $plaidStatusCell = budget_plaid_status_cell_html($row);
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
                          data-privacy-token="<?= htmlspecialchars((string)($row['privacy_token'] ?? '')) ?>"
                          data-privacy-status="<?= htmlspecialchars((string)($row['privacy_status'] ?? '')) ?>"
                          data-privacy-event-type="<?= htmlspecialchars((string)($row['privacy_event_type'] ?? '')) ?>"
                          data-privacy-result="<?= htmlspecialchars((string)($row['privacy_result'] ?? '')) ?>"
                          data-privacy-merchant="<?= htmlspecialchars((string)($row['privacy_merchant_descriptor'] ?? '')) ?>"
                          data-privacy-created-at="<?= htmlspecialchars((string)($row['privacy_created_at'] ?? '')) ?>"
                          data-privacy-event-at="<?= htmlspecialchars((string)($row['privacy_event_at'] ?? '')) ?>"
                          data-privacy-last-checked-at="<?= htmlspecialchars((string)($row['privacy_last_checked_at'] ?? '')) ?>"
                          data-privacy-sync-status="<?= htmlspecialchars((string)($row['privacy_sync_status'] ?? '')) ?>"
                          data-privacy-sync-error="<?= htmlspecialchars((string)($row['privacy_sync_error'] ?? '')) ?>"
                          data-plaid-transaction-row-id="<?= (int)($row['plaid_transaction_row_id'] ?? 0) ?>"
                          data-plaid-transaction-token="<?= htmlspecialchars((string)($row['plaid_transaction_token'] ?? '')) ?>"
                          data-plaid-match-method="<?= htmlspecialchars((string)($row['plaid_match_method'] ?? '')) ?>"
                          data-plaid-pending="<?= (int)($row['plaid_pending'] ?? 0) ?>"
                          data-plaid-date="<?= htmlspecialchars((string)($row['plaid_date'] ?? '')) ?>"
                          data-plaid-authorized-date="<?= htmlspecialchars((string)($row['plaid_authorized_date'] ?? '')) ?>"
                          data-plaid-amount="<?= htmlspecialchars((string)($row['plaid_amount'] ?? '')) ?>"
                          data-plaid-name="<?= htmlspecialchars((string)($row['plaid_name'] ?? '')) ?>"
                          data-plaid-merchant-name="<?= htmlspecialchars((string)($row['plaid_merchant_name'] ?? '')) ?>"
                          data-plaid-link-count="<?= (int)($row['plaid_link_count'] ?? 0) ?>"
                          data-plaid-institution="<?= htmlspecialchars((string)($row['plaid_institution_name'] ?? '')) ?>"
                          data-plaid-account="<?= htmlspecialchars((string)($row['plaid_account_name'] ?? '')) ?>"
                          data-plaid-mask="<?= htmlspecialchars((string)($row['plaid_account_mask'] ?? '')) ?>"
                          data-status="1"
                          data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                        <td class="tx-click-edit" role="button"><?= $dateCell ?></td>
                        <td class="<?= $recentAccountCellClass ?>" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                        <td class="text-truncate tx-click-edit" role="button" style="max-width: 480px;">&nbsp;<?= htmlspecialchars((string)$desc) ?></td>
                        <td class="<?= $recentPrivacyCellClass ?>" role="button"><?= $privacyStatusCell ?></td>
                        <td class="<?= $recentPlaidCellClass ?>" role="button"><?= $plaidStatusCell ?></td>
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
                    <tr class="spacer-row"><td colspan="<?= $recentSpacerColspan ?>"></td></tr>
                    <tr class="spacer-row"><td colspan="<?= $recentSpacerColspan ?>"></td></tr>
                  <?php endif; ?>
                  <?php if (!empty($postedRows)):
                    $currentDate = null;
                    $postedSummaryShown = false;
                    $postedHeaderAccountCell = '<td' . ($recentAccountColumnClass !== '' ? ' class="' . $recentAccountColumnClass . '"' : '') . '></td>';
                    $postedHeaderPrivacyCell = '<td class="' . $recentPrivacyColumnClass . '"></td>';
                    $postedHeaderPlaidCell = '<td class="' . $recentPlaidColumnClass . '"></td>';
                    foreach ($postedRows as $row):
                      $date = $row['date'] ?? '';
                      $acct = $row['account_name'] ?? '';
                      $desc = $row['description'] ?? '';
                      $amt = $row['amount'];
                      $amtClass = (is_numeric($amt) && (float)$amt < 0) ? 'text-danger' : 'text-success';
                      $amtFmt = is_numeric($amt) ? number_format((float)$amt, 2) : htmlspecialchars((string)$amt);
                      $txId = (int)($row['id'] ?? 0);
                      $plaidStatusCell = budget_plaid_status_cell_html($row);
                      $isNewGroup = ($date !== $currentDate);
                      $dateCell = '';
                      if ($isNewGroup) {
                        $label = $date;
                        $ts = strtotime((string)$date);
                        if ($ts !== false) { $label = date('l, F j, Y', $ts); }
                        echo '<tr class="table-active">'
                           . '<td>' . htmlspecialchars($label) . '</td>'
                           . $postedHeaderAccountCell
                           . '<td></td>'
                           . $postedHeaderPrivacyCell
                           . $postedHeaderPlaidCell
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
                        data-privacy-token="<?= htmlspecialchars((string)($row['privacy_token'] ?? '')) ?>"
                        data-privacy-status="<?= htmlspecialchars((string)($row['privacy_status'] ?? '')) ?>"
                        data-privacy-event-type="<?= htmlspecialchars((string)($row['privacy_event_type'] ?? '')) ?>"
                        data-privacy-result="<?= htmlspecialchars((string)($row['privacy_result'] ?? '')) ?>"
                        data-privacy-merchant="<?= htmlspecialchars((string)($row['privacy_merchant_descriptor'] ?? '')) ?>"
                        data-privacy-created-at="<?= htmlspecialchars((string)($row['privacy_created_at'] ?? '')) ?>"
                        data-privacy-event-at="<?= htmlspecialchars((string)($row['privacy_event_at'] ?? '')) ?>"
                        data-privacy-last-checked-at="<?= htmlspecialchars((string)($row['privacy_last_checked_at'] ?? '')) ?>"
                        data-privacy-sync-status="<?= htmlspecialchars((string)($row['privacy_sync_status'] ?? '')) ?>"
                        data-privacy-sync-error="<?= htmlspecialchars((string)($row['privacy_sync_error'] ?? '')) ?>"
                        data-plaid-transaction-row-id="<?= (int)($row['plaid_transaction_row_id'] ?? 0) ?>"
                        data-plaid-transaction-token="<?= htmlspecialchars((string)($row['plaid_transaction_token'] ?? '')) ?>"
                        data-plaid-match-method="<?= htmlspecialchars((string)($row['plaid_match_method'] ?? '')) ?>"
                        data-plaid-pending="<?= (int)($row['plaid_pending'] ?? 0) ?>"
                        data-plaid-date="<?= htmlspecialchars((string)($row['plaid_date'] ?? '')) ?>"
                        data-plaid-authorized-date="<?= htmlspecialchars((string)($row['plaid_authorized_date'] ?? '')) ?>"
                        data-plaid-amount="<?= htmlspecialchars((string)($row['plaid_amount'] ?? '')) ?>"
                        data-plaid-name="<?= htmlspecialchars((string)($row['plaid_name'] ?? '')) ?>"
                        data-plaid-merchant-name="<?= htmlspecialchars((string)($row['plaid_merchant_name'] ?? '')) ?>"
                        data-plaid-link-count="<?= (int)($row['plaid_link_count'] ?? 0) ?>"
                        data-plaid-institution="<?= htmlspecialchars((string)($row['plaid_institution_name'] ?? '')) ?>"
                        data-plaid-account="<?= htmlspecialchars((string)($row['plaid_account_name'] ?? '')) ?>"
                        data-plaid-mask="<?= htmlspecialchars((string)($row['plaid_account_mask'] ?? '')) ?>"
                        data-status="2"
                        data-account-id="<?= (int)($row['account_id'] ?? 0) ?>">
                      <td class="tx-click-edit" role="button"><?= $dateCell ?></td>
                      <td class="<?= $recentAccountCellClass ?>" role="button"><?= htmlspecialchars((string)$acct) ?></td>
                      <td class="text-truncate tx-click-edit" role="button" style="max-width: 480px;"><?= htmlspecialchars((string)$desc) ?></td>
                      <td class="<?= $recentPrivacyCellClass ?>" role="button"></td>
                      <td class="<?= $recentPlaidCellClass ?>" role="button"><?= $plaidStatusCell ?></td>
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
            <?php if ($recentTotalRows > 0): ?>
              <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 gap-2">
                <div class="text-body-secondary small">
                  Page <?= $recentPage ?> of <?= $recentTotalPages ?> • Showing <?= $recentPageFrom ?>-<?= $recentPageTo ?> of <?= $recentTotalRows ?> transaction<?= $recentTotalRows === 1 ? '' : 's' ?>
                </div>
                <div class="btn-group" role="group" aria-label="Recent transaction pagination">
                  <a class="btn btn-outline-secondary btn-sm<?= $recentHasPrev ? '' : ' disabled' ?>" href="<?= $recentHasPrev ? htmlspecialchars('?' . http_build_query($recentPrevQuery)) : '#' ?>">« Prev</a>
                  <a class="btn btn-outline-secondary btn-sm<?= $recentHasNext ? '' : ' disabled' ?>" href="<?= $recentHasNext ? htmlspecialchars('?' . http_build_query($recentNextQuery)) : '#' ?>">Next »</a>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="min-vh-100 d-flex align-items-center justify-content-center">
          <div class="w-100" style="max-width: 360px;">
            <?php if ($loginStep === 'phone'): ?>
              <form method="post" class="w-100" novalidate>
                <input type="hidden" name="mode" value="request-code">
                <input type="tel"
                       name="phone"
                       class="form-control text-center<?= $phoneErrorMessage !== '' ? ' is-invalid' : '' ?>"
                       aria-label="Phone number"
                       inputmode="tel"
                       autocomplete="tel"
                       autocorrect="off"
                       autocapitalize="none"
                       spellcheck="false"
                       required
                       autofocus<?= $phoneErrorMessage !== '' ? ' aria-invalid="true" title="' . htmlspecialchars($phoneErrorMessage, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
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
        // Auto-submit when 6 digits are present (e.g., after SMS autofill)
        const maybeAutoSubmit = () => {
          const digits = (input.value || '').replace(/\D+/g, '');
          if (digits.length === 6) {
            form.submit();
          }
        };
        input.addEventListener('input', maybeAutoSubmit);
        input.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            event.preventDefault();
            submitHiddenPost('change-phone');
          }
          if ((event.key === 'Enter' && (event.metaKey || event.ctrlKey))) {
            event.preventDefault();
            submitHiddenPost('resend-code');
          }
        });
        // Try once on load in case the browser prefilled it
        maybeAutoSubmit();
      })();
      </script>
    <?php endif; ?>

    <?php if ($loggedIn): ?>
      <!-- Edit Transaction Modal -->
      <div class="modal fade" id="editTxModal" tabindex="-1" aria-hidden="true" aria-labelledby="editTxLabel">
        <div class="modal-dialog modal-dialog-scrollable">
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
              <div class="mb-2 position-relative">
                <label class="form-label">Account</label>
                <input type="hidden" name="account_select" id="txAccountSelect">
                <input type="hidden" name="account_name_new" id="txAccountNew">
                <input type="text" class="form-control" id="txAccountInput" placeholder="Start typing an account" autocomplete="off" autocorrect="off" autocapitalize="words" spellcheck="false">
                <div class="combo-suggestions d-none" id="txAccountSuggestions"></div>
              </div>
              <div class="mb-2 position-relative">
                <label class="form-label">Description</label>
                <input type="text" class="form-control" name="description" id="txDescription" autocomplete="off" autocorrect="off" autocapitalize="words" spellcheck="false">
                <div class="combo-suggestions d-none" id="txDescriptionSuggestions"></div>
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
              <div class="border rounded bg-body-tertiary d-none" id="txApiTabsWrap">
                <ul class="nav nav-tabs px-3 pt-3" id="txApiTabs" role="tablist">
                  <li class="nav-item d-none" role="presentation" id="txPrivacyTabItem">
                    <button class="nav-link" id="txPrivacyTab" data-bs-toggle="tab" data-bs-target="#txPrivacyPanel" type="button" role="tab" aria-controls="txPrivacyPanel" aria-selected="false">Privacy</button>
                  </li>
                  <li class="nav-item d-none" role="presentation" id="txPlaidTabItem">
                    <button class="nav-link" id="txPlaidTab" data-bs-toggle="tab" data-bs-target="#txPlaidPanel" type="button" role="tab" aria-controls="txPlaidPanel" aria-selected="false">Plaid</button>
                  </li>
                </ul>
                <div class="tab-content p-3">
                  <div class="tab-pane fade d-none" id="txPrivacyPanel" role="tabpanel" aria-labelledby="txPrivacyTab" tabindex="0">
                    <div class="row g-2 small">
                      <div class="col-6">
                        <div class="text-body-secondary">Status</div>
                        <div id="txPrivacyStatus">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Latest Event</div>
                        <div id="txPrivacyEventType">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Result</div>
                        <div id="txPrivacyResult">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Sync</div>
                        <div id="txPrivacySyncStatus">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Merchant</div>
                        <div id="txPrivacyMerchant">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Token</div>
                        <div id="txPrivacyToken" class="font-monospace text-break">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Created</div>
                        <div id="txPrivacyCreatedAt">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Last Event At</div>
                        <div id="txPrivacyEventAt">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Last Checked</div>
                        <div id="txPrivacyLastCheckedAt">-</div>
                      </div>
                      <div class="col-12 d-none" id="txPrivacyErrorWrap">
                        <div class="text-body-secondary">Sync Error</div>
                        <div id="txPrivacyError" class="text-danger text-break">-</div>
                      </div>
                    </div>
                  </div>
                  <div class="tab-pane fade d-none" id="txPlaidPanel" role="tabpanel" aria-labelledby="txPlaidTab" tabindex="0">
                    <div class="row g-2 small">
                      <div class="col-6">
                        <div class="text-body-secondary">Status</div>
                        <div id="txPlaidStatus">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Match</div>
                        <div id="txPlaidMatchMethod">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Date</div>
                        <div id="txPlaidDate">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Authorized</div>
                        <div id="txPlaidAuthorizedDate">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Plaid Amount</div>
                        <div id="txPlaidAmount">-</div>
                      </div>
                      <div class="col-6">
                        <div class="text-body-secondary">Links</div>
                        <div id="txPlaidLinkCount">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Account</div>
                        <div id="txPlaidAccount">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Merchant</div>
                        <div id="txPlaidMerchant">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Name</div>
                        <div id="txPlaidName">-</div>
                      </div>
                      <div class="col-12">
                        <div class="text-body-secondary">Transaction ID</div>
                        <div id="txPlaidTransactionToken" class="font-monospace text-break">-</div>
                      </div>
                    </div>
                  </div>
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
      <!-- Transfer Modal -->
      <div class="modal fade" id="transferModal" tabindex="-1" aria-hidden="true" aria-labelledby="transferLabel">
        <div class="modal-dialog">
          <form class="modal-content" id="transferForm">
            <div class="modal-header">
              <h5 class="modal-title" id="transferLabel">Transfer Funds</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="mode" value="transfer">
              <div class="row g-2 mb-2">
                <div class="col-6">
                  <label class="form-label">Date</label>
                  <input type="date" class="form-control" name="date" id="transferDate" required>
                </div>
                <div class="col-6">
                  <label class="form-label">Amount</label>
                  <input type="text" class="form-control" name="amount" id="transferAmount" placeholder="0.00" required>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-label">From Account</label>
                <select class="form-select" name="from_account_id" id="transferFromAccount" required>
                  <option value="">Select source account…</option>
                  <?php if (!empty($accPairs)) foreach ($accPairs as $aid => $aname): ?>
                    <option value="<?= (int)$aid ?>"><?= htmlspecialchars((string)$aname) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">To Account</label>
                <select class="form-select" name="to_account_id" id="transferToAccount" required>
                  <option value="">Select target account…</option>
                  <?php if (!empty($accPairs)) foreach ($accPairs as $aid => $aname): ?>
                    <option value="<?= (int)$aid ?>"><?= htmlspecialchars((string)$aname) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status" id="transferStatus">
                  <option value="0">Scheduled</option>
                  <option value="1" selected>Pending</option>
                  <option value="2">Posted</option>
                </select>
              </div>
              <div class="form-text">Creates two transactions: negative on source with target name, and positive on target with source name.</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Transfer</button>
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
      <?php if ($plaidAvailable): ?>
        <script src="https://cdn.plaid.com/link/v2/stable/link-initialize.js"></script>
      <?php endif; ?>
      <script>
      (() => {
        const modalEl = document.getElementById('editTxModal');
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
        const form = document.getElementById('editTxForm');
        const transferModalEl = document.getElementById('transferModal');
        const transferModal = transferModalEl ? new bootstrap.Modal(transferModalEl) : null;
        const transferForm = document.getElementById('transferForm');
        const g = (id) => document.getElementById(id);
        const setv = (id, v) => { const el = g(id); if (el) el.value = v || ''; };
        const dashboardFilterForm = document.getElementById('dashboardFilterForm');
        const dashboardFilterSelect = document.getElementById('filterAccount');
        const connectPlaidBtn = document.getElementById('connectPlaidBtn');
        const syncPlaidBtn = document.getElementById('syncPlaidBtn');
        const accountOptions = <?= json_encode(array_values(array_unique(array_map('strval', $accounts ?? []))), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const descriptionOptions = <?= json_encode(array_values(array_unique(array_map('strval', $descriptions ?? []))), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const todayIso = () => {
          const now = new Date();
          const yyyy = now.getFullYear();
          const mm = String(now.getMonth() + 1).padStart(2, '0');
          const dd = String(now.getDate()).padStart(2, '0');
          return `${yyyy}-${mm}-${dd}`;
        };
        const normalizeText = (value) => (value || '').trim().toLowerCase();
        const txAccountInput = g('txAccountInput');
        const txAccountSelect = g('txAccountSelect');
        const txAccountNew = g('txAccountNew');
        const txAccountSuggestions = g('txAccountSuggestions');
        const txDescriptionInput = g('txDescription');
        const txDescriptionSuggestions = g('txDescriptionSuggestions');
        const txApiTabsWrap = g('txApiTabsWrap');
        const txPrivacyTabItem = g('txPrivacyTabItem');
        const txPrivacyTab = g('txPrivacyTab');
        const txPrivacyPanel = g('txPrivacyPanel');
        const txPrivacyErrorWrap = g('txPrivacyErrorWrap');
        const txPlaidTabItem = g('txPlaidTabItem');
        const txPlaidTab = g('txPlaidTab');
        const txPlaidPanel = g('txPlaidPanel');
        const hideSuggestions = (panel, state = null) => {
          if (!panel) return;
          panel.classList.add('d-none');
          panel.textContent = '';
          if (state) {
            state.matches = [];
            state.activeIndex = -1;
          }
        };
        const setText = (id, value, fallback = '-') => {
          const el = g(id);
          if (!el) return;
          const text = (value || '').toString().trim();
          el.textContent = text !== '' ? text : fallback;
        };
        const formatPrivacyTimestamp = (value) => {
          const text = (value || '').toString().trim();
          if (text === '') return '';
          const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)
            ? text.replace(' ', 'T') + 'Z'
            : text;
          const parsed = new Date(normalized);
          if (Number.isNaN(parsed.getTime())) return text;
          return parsed.toLocaleString([], {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
          });
        };
        const apiTabConfig = {
          privacy: { item: txPrivacyTabItem, button: txPrivacyTab, panel: txPrivacyPanel },
          plaid: { item: txPlaidTabItem, button: txPlaidTab, panel: txPlaidPanel },
        };
        const setApiTabAvailable = (name, available) => {
          const config = apiTabConfig[name];
          if (!config) return;
          config.item && config.item.classList.toggle('d-none', !available);
          config.panel && config.panel.classList.toggle('d-none', !available);
          if (!available) {
            config.button && config.button.classList.remove('active');
            config.button && config.button.setAttribute('aria-selected', 'false');
            config.panel && config.panel.classList.remove('active', 'show');
          }
        };
        const activateApiTab = (name) => {
          Object.entries(apiTabConfig).forEach(([key, config]) => {
            const isActive = key === name;
            config.button && config.button.classList.toggle('active', isActive);
            config.button && config.button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            config.panel && config.panel.classList.toggle('active', isActive);
            config.panel && config.panel.classList.toggle('show', isActive);
          });
        };
        const syncApiTabs = () => {
          const available = Object.entries(apiTabConfig)
            .filter(([, config]) => config.item && !config.item.classList.contains('d-none'))
            .map(([name]) => name);
          if (txApiTabsWrap) txApiTabsWrap.classList.toggle('d-none', available.length === 0);
          if (available.length > 0) {
            activateApiTab(available[0]);
          }
        };
        const resetPrivacyPanel = () => {
          setApiTabAvailable('privacy', false);
          if (txPrivacyErrorWrap) txPrivacyErrorWrap.classList.add('d-none');
          setText('txPrivacyStatus', '');
          setText('txPrivacyEventType', '');
          setText('txPrivacyResult', '');
          setText('txPrivacySyncStatus', '');
          setText('txPrivacyMerchant', '');
          setText('txPrivacyToken', '');
          setText('txPrivacyCreatedAt', '');
          setText('txPrivacyEventAt', '');
          setText('txPrivacyLastCheckedAt', '');
          setText('txPrivacyError', '');
        };
        const showPrivacyPanelFromRow = (row) => {
          const token = row?.dataset?.privacyToken || '';
          const status = row?.dataset?.privacyStatus || '';
          const eventType = row?.dataset?.privacyEventType || '';
          const result = row?.dataset?.privacyResult || '';
          const merchant = row?.dataset?.privacyMerchant || '';
          const createdAt = row?.dataset?.privacyCreatedAt || '';
          const eventAt = row?.dataset?.privacyEventAt || '';
          const lastCheckedAt = row?.dataset?.privacyLastCheckedAt || '';
          const syncStatus = row?.dataset?.privacySyncStatus || '';
          const syncError = row?.dataset?.privacySyncError || '';
          const hasPrivacyData = [token, status, eventType, result, merchant, createdAt, eventAt, lastCheckedAt, syncStatus, syncError]
            .some((value) => (value || '').toString().trim() !== '');

          resetPrivacyPanel();
          if (!hasPrivacyData) return;

          setText('txPrivacyStatus', status);
          setText('txPrivacyEventType', eventType);
          setText('txPrivacyResult', result);
          setText('txPrivacySyncStatus', syncStatus);
          setText('txPrivacyMerchant', merchant);
          setText('txPrivacyToken', token);
          setText('txPrivacyCreatedAt', formatPrivacyTimestamp(createdAt));
          setText('txPrivacyEventAt', formatPrivacyTimestamp(eventAt));
          setText('txPrivacyLastCheckedAt', formatPrivacyTimestamp(lastCheckedAt));
          setText('txPrivacyError', syncError);
          if (txPrivacyErrorWrap) {
            txPrivacyErrorWrap.classList.toggle('d-none', (syncError || '').trim() === '');
          }
          setApiTabAvailable('privacy', true);
        };
        const plaidStatusLabel = (matchMethod, pending, linkCount) => {
          if (linkCount > 1) return 'multi';
          if (pending === '1') return 'pending';
          if (matchMethod === 'created') return 'created';
          if (matchMethod === 'manual_merge') return 'merged';
          if ((matchMethod || '').startsWith('amount_')) return 'matched';
          return (matchMethod || '').replace(/_/g, ' ') || 'linked';
        };
        const resetPlaidPanel = () => {
          setApiTabAvailable('plaid', false);
          setText('txPlaidStatus', '');
          setText('txPlaidMatchMethod', '');
          setText('txPlaidDate', '');
          setText('txPlaidAuthorizedDate', '');
          setText('txPlaidAmount', '');
          setText('txPlaidLinkCount', '');
          setText('txPlaidAccount', '');
          setText('txPlaidMerchant', '');
          setText('txPlaidName', '');
          setText('txPlaidTransactionToken', '');
        };
        const showPlaidPanelFromRow = (row) => {
          const rowId = row?.dataset?.plaidTransactionRowId || '';
          resetPlaidPanel();
          if (!rowId || rowId === '0') return;

          const matchMethod = row?.dataset?.plaidMatchMethod || '';
          const pending = row?.dataset?.plaidPending || '0';
          const linkCountRaw = row?.dataset?.plaidLinkCount || '1';
          const linkCount = Math.max(1, Number.parseInt(linkCountRaw, 10) || 1);
          const institution = row?.dataset?.plaidInstitution || '';
          const account = row?.dataset?.plaidAccount || '';
          const mask = row?.dataset?.plaidMask || '';
          const accountParts = [];
          if (institution) accountParts.push(institution);
          if (account) accountParts.push(account);
          let accountLabel = accountParts.join(' | ');
          if (mask) accountLabel = `${accountLabel}${accountLabel ? ' ' : ''}...${mask}`;

          setText('txPlaidStatus', plaidStatusLabel(matchMethod, pending, linkCount));
          setText('txPlaidMatchMethod', matchMethod);
          setText('txPlaidDate', row?.dataset?.plaidDate || '');
          setText('txPlaidAuthorizedDate', row?.dataset?.plaidAuthorizedDate || '');
          setText('txPlaidAmount', row?.dataset?.plaidAmount || '');
          setText('txPlaidLinkCount', String(linkCount));
          setText('txPlaidAccount', accountLabel);
          setText('txPlaidMerchant', row?.dataset?.plaidMerchantName || '');
          setText('txPlaidName', row?.dataset?.plaidName || '');
          setText('txPlaidTransactionToken', row?.dataset?.plaidTransactionToken || '');
          setApiTabAvailable('plaid', true);
        };
        const syncSuggestionHighlight = (panel, activeIndex) => {
          if (!panel) return;
          const buttons = panel.querySelectorAll('button[data-suggestion-index]');
          buttons.forEach((button, index) => {
            const isActive = index === activeIndex;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            if (isActive) {
              button.scrollIntoView({ block: 'nearest' });
            }
          });
        };
        const findSuggestionMatches = (options, query) => {
          const trimmed = (query || '').trim();
          if (trimmed === '') return options.slice(0, 8);
          const normalized = normalizeText(trimmed);
          const exact = [];
          const starts = [];
          const contains = [];
          options.forEach((option) => {
            const label = String(option || '');
            if (label === '') return;
            const candidate = normalizeText(label);
            if (candidate === normalized) exact.push(label);
            else if (candidate.startsWith(normalized)) starts.push(label);
            else if (candidate.includes(normalized)) contains.push(label);
          });
          return [...exact, ...starts, ...contains].slice(0, 8);
        };
        const renderSuggestions = (panel, state, onPick) => {
          if (!panel) return;
          panel.textContent = '';
          if (!state.matches.length) {
            panel.classList.add('d-none');
            return;
          }
          state.matches.forEach((match, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action py-2 px-3';
            button.textContent = match;
            button.dataset.suggestionIndex = String(index);
            button.setAttribute('aria-selected', 'false');
            button.addEventListener('mouseenter', () => {
              state.activeIndex = index;
              syncSuggestionHighlight(panel, state.activeIndex);
            });
            button.addEventListener('pointerdown', (event) => {
              event.preventDefault();
              state.activeIndex = index;
              onPick(match);
              hideSuggestions(panel, state);
            });
            panel.append(button);
          });
          panel.classList.remove('d-none');
          syncSuggestionHighlight(panel, state.activeIndex);
        };
        const attachSuggestionInput = ({ input, panel, options, onPick }) => {
          if (!input || !panel) return;
          const state = { matches: [], activeIndex: -1 };
          const chooseActive = () => {
            const targetIndex = state.activeIndex >= 0 ? state.activeIndex : -1;
            if (targetIndex < 0 || targetIndex >= state.matches.length) return false;
            onPick(state.matches[targetIndex]);
            hideSuggestions(panel, state);
            return true;
          };
          const update = () => {
            state.matches = findSuggestionMatches(options, input.value || '');
            state.activeIndex = -1;
            renderSuggestions(panel, state, onPick);
          };
          input.addEventListener('focus', update);
          input.addEventListener('input', update);
          input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && state.matches.length) {
              event.preventDefault();
              state.activeIndex = Math.min(state.activeIndex + 1, state.matches.length - 1);
              syncSuggestionHighlight(panel, state.activeIndex);
              return;
            }
            if (event.key === 'ArrowUp' && state.matches.length) {
              event.preventDefault();
              state.activeIndex = state.activeIndex <= 0 ? state.matches.length - 1 : state.activeIndex - 1;
              syncSuggestionHighlight(panel, state.activeIndex);
              return;
            }
            if (event.key === 'Enter' && state.matches.length) {
              if (state.activeIndex < 0) state.activeIndex = 0;
              event.preventDefault();
              chooseActive();
              return;
            }
            if (event.key === 'Tab' && state.activeIndex >= 0 && state.matches.length) {
              chooseActive();
              return;
            }
            if (event.key === 'Escape') hideSuggestions(panel, state);
          });
          input.addEventListener('blur', () => {
            window.setTimeout(() => hideSuggestions(panel, state), 120);
          });
        };
        const syncAccountFields = () => {
          if (!txAccountInput || !txAccountSelect || !txAccountNew) return;
          const typed = (txAccountInput.value || '').trim();
          if (typed === '') {
            txAccountSelect.value = '';
            txAccountNew.value = '';
            return;
          }
          const exactMatch = accountOptions.find((option) => normalizeText(option) === normalizeText(typed));
          if (exactMatch) {
            txAccountInput.value = exactMatch;
            txAccountSelect.value = exactMatch;
            txAccountNew.value = '';
          } else {
            txAccountSelect.value = '__new__';
            txAccountNew.value = typed;
          }
        };
        // Use custom suggestion lists instead of native select/datalist to avoid iPad text-entry issues.
        attachSuggestionInput({
          input: txAccountInput,
          panel: txAccountSuggestions,
          options: accountOptions,
          onPick: (value) => {
            if (txAccountInput) txAccountInput.value = value;
            syncAccountFields();
          },
        });
        attachSuggestionInput({
          input: txDescriptionInput,
          panel: txDescriptionSuggestions,
          options: descriptionOptions,
          onPick: (value) => {
            if (txDescriptionInput) txDescriptionInput.value = value;
          },
        });
        txAccountInput && txAccountInput.addEventListener('input', syncAccountFields);
        function openEditFromRow(row){
          if (!row) return;
          setv('txId', row.dataset.id || '');
          setv('txDate', row.dataset.date || '');
          setv('txAmount', row.dataset.amount || '');
          const acct = row.dataset.account || '';
          const acctId = row.dataset.accountId || '';
          const keep = g('txAccountKeep'); if (keep) keep.value = acctId || '';
          setv('txAccountInput', acct);
          syncAccountFields();
          setv('txDescription', row.dataset.description || '');
          setv('txCheck', row.dataset.check || '');
          const statusVal = row.dataset.status ?? '1';
          const statusEl = g('txStatus'); if (statusEl) statusEl.value = statusVal;
          showPrivacyPanelFromRow(row);
          showPlaidPanelFromRow(row);
          syncApiTabs();
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
          setv('txDate', todayIso());
          setv('txAmount','');
          // Set account to current filter if present
          const acctName = addBtn.dataset.accountName || '';
          const keep2 = g('txAccountKeep'); if (keep2) keep2.value = '';
          setv('txAccountInput', acctName);
          syncAccountFields();
          setv('txDescription','');
          setv('txCheck','');
          const statusEl2 = g('txStatus'); if (statusEl2) statusEl2.value = '1';
          resetPrivacyPanel();
          resetPlaidPanel();
          syncApiTabs();
          modal && modal.show();
        });
        const transferBtn = document.getElementById('transferBtn');
        transferBtn && transferBtn.addEventListener('click', (e) => {
          e.preventDefault();
          setv('transferDate', todayIso());
          setv('transferAmount', '');
          const fromSel = g('transferFromAccount');
          const toSel = g('transferToAccount');
          const statusSel = g('transferStatus');
          if (statusSel) statusSel.value = '1';
          const preferred = transferBtn.dataset.accountId || '';
          if (fromSel) {
            if (preferred && Array.from(fromSel.options).some((opt) => opt.value === preferred)) {
              fromSel.value = preferred;
            } else {
              fromSel.value = '';
            }
          }
          if (toSel) {
            toSel.value = '';
            if (fromSel && fromSel.value !== '') {
              const firstDifferent = Array.from(toSel.options).find((opt) => opt.value !== '' && opt.value !== fromSel.value);
              if (firstDifferent) toSel.value = firstDifferent.value;
            }
          }
          transferModal && transferModal.show();
        });
        dashboardFilterSelect && dashboardFilterForm && dashboardFilterSelect.addEventListener('change', () => {
          if (typeof dashboardFilterForm.requestSubmit === 'function') {
            dashboardFilterForm.requestSubmit();
          } else {
            dashboardFilterForm.submit();
          }
        });
        const postForm = async (url, values = {}) => {
          const fd = new FormData();
          Object.entries(values).forEach(([key, value]) => fd.append(key, value));
          const res = await fetch(url, { method: 'POST', body: fd });
          if (!res.ok) return null;
          return res.json().catch(() => ({}));
        };
        connectPlaidBtn && connectPlaidBtn.addEventListener('click', async (event) => {
          event.preventDefault();
          if (!window.Plaid) {
            window.alert('Plaid Link did not load.');
            return;
          }
          connectPlaidBtn.disabled = true;
          const tokenResponse = await postForm('plaid_link_token.php', { environment: 'production' });
          connectPlaidBtn.disabled = false;
          if (!tokenResponse || !tokenResponse.link_token) return;
          const handler = window.Plaid.create({
            token: tokenResponse.link_token,
            onSuccess: async (publicToken, metadata) => {
              connectPlaidBtn.disabled = true;
              const res = await fetch('plaid_exchange_token.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  environment: tokenResponse.environment || 'production',
                  public_token: publicToken,
                  metadata: metadata || {},
                }),
              });
              connectPlaidBtn.disabled = false;
              if (!res.ok) return;
              await res.json().catch(() => ({}));
              window.location.reload();
            },
            onExit: () => {
              connectPlaidBtn.disabled = false;
            },
          });
          handler.open();
        });
        syncPlaidBtn && syncPlaidBtn.addEventListener('click', async (event) => {
          event.preventDefault();
          syncPlaidBtn.disabled = true;
          const result = await postForm('plaid_sync.php');
          if (!result) {
            syncPlaidBtn.disabled = false;
            return;
          }
          window.location.reload();
        });
        document.addEventListener('change', async (event) => {
          const select = event.target.closest('.plaid-account-map');
          if (!select) return;
          const plaidAccountId = select.dataset.plaidAccountId || '';
          if (!plaidAccountId) return;
          select.disabled = true;
          const result = await postForm('plaid_account_map.php', {
            plaid_account_id: plaidAccountId,
            budget_account_id: select.value || '',
          });
          if (!result) {
            select.disabled = false;
            return;
          }
          window.location.reload();
        });
        document.addEventListener('click', async (event) => {
          const button = event.target.closest('.plaid-merge-btn');
          if (!button) return;
          const row = button.closest('tr');
          const select = row ? row.querySelector('.plaid-merge-target') : null;
          const plaidTransactionId = select?.dataset?.plaidTransactionId || '';
          const budgetTransactionId = select?.value || '';
          if (!plaidTransactionId || !budgetTransactionId) return;
          button.disabled = true;
          const result = await postForm('plaid_transaction_merge.php', {
            plaid_transaction_id: plaidTransactionId,
            budget_transaction_id: budgetTransactionId,
          });
          if (!result) {
            button.disabled = false;
            return;
          }
          window.location.reload();
        });
        document.addEventListener('click', async (event) => {
          const button = event.target.closest('.plaid-delete-btn');
          if (!button) return;
          event.preventDefault();
          const row = button.closest('tr');
          const plaidTransactionId = button.dataset.plaidTransactionId || row?.dataset?.plaidTransactionId || '';
          if (!plaidTransactionId) return;
          if (!window.confirm('Delete this Plaid transaction from review?')) return;
          button.disabled = true;
          const result = await postForm('plaid_transaction_delete.php', {
            plaid_transaction_id: plaidTransactionId,
          });
          if (!result) {
            button.disabled = false;
            window.alert('Unable to delete Plaid transaction.');
            return;
          }
          window.location.reload();
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
            setv('txDate', todayIso());
          }
          setv('txAmount','');
          // Set account to current filter if present (from main Add button's dataset)
          const addBtn = document.getElementById('addTxBtn');
          const acctName = addBtn?.dataset.accountName || '';
          const keep2 = g('txAccountKeep'); if (keep2) keep2.value = '';
          setv('txAccountInput', acctName);
          syncAccountFields();
          setv('txDescription','');
          setv('txCheck','');
          const statusEl = g('txStatus'); if (statusEl) statusEl.value = status;
          resetPrivacyPanel();
          resetPlaidPanel();
          syncApiTabs();
          modal && modal.show();
        });

        form && form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          syncAccountFields();
          const fd = new FormData(form);
          const res = await fetch('transaction_save.php', { method:'POST', body: fd });
          if (!res.ok) return; // api_error.js will show modal
          try { await res.json(); } catch {}
          modal && modal.hide();
          window.location.reload();
        });
        transferForm && transferForm.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const fromId = g('transferFromAccount')?.value || '';
          const toId = g('transferToAccount')?.value || '';
          if (!fromId || !toId) {
            window.alert('Select both source and target accounts.');
            return;
          }
          if (fromId === toId) {
            window.alert('Source and target accounts must be different.');
            return;
          }
          const fd = new FormData(transferForm);
          const res = await fetch('transaction_save.php', { method: 'POST', body: fd });
          if (!res.ok) return; // api_error.js will show modal
          try { await res.json(); } catch {}
          transferModal && transferModal.hide();
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
