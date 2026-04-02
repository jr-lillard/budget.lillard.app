#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/privacy.php';

$options = getopt('', ['dry-run', 'limit::', 'begin::', 'environment::', 'owner::', 'account-id::']);
$dryRun = array_key_exists('dry-run', $options);
$limit = isset($options['limit']) ? max(1, min((int)$options['limit'], 250)) : 50;
$beginDate = isset($options['begin']) ? trim((string)$options['begin']) : gmdate('Y-m-d', strtotime('-365 days'));
$environmentOption = isset($options['environment']) ? trim((string)$options['environment']) : trim((string)getenv('BUDGET_PRIVACY_ENVIRONMENT'));
$environment = privacy_normalize_environment($environmentOption !== '' ? $environmentOption : 'dev');
$ownerOption = isset($options['owner']) ? trim((string)$options['owner']) : trim((string)getenv('BUDGET_PRIVACY_OWNER'));
$accountIdOption = isset($options['account-id']) ? trim((string)$options['account-id']) : trim((string)getenv('BUDGET_PRIVACY_ACCOUNT_ID'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beginDate)) {
    fwrite(STDERR, "Invalid --begin value. Expected YYYY-MM-DD.\n");
    exit(1);
}

$owner = budget_canonical_user($ownerOption !== '' ? $ownerOption : 'jr@lillard.org');
if ($owner === '') {
    fwrite(STDERR, "Invalid owner value.\n");
    exit(1);
}

$accountId = $accountIdOption !== '' ? (int)$accountIdOption : 1;
if ($accountId <= 0) {
    fwrite(STDERR, "Invalid account id value.\n");
    exit(1);
}

try {
    $pdo = get_mysql_connection();
    privacy_ensure_sync_table($pdo);
    $apiKey = privacy_api_get_key();
    $apiTransactionsByToken = privacy_api_list_transactions($apiKey, $beginDate, 1000, 25, $environment);
} catch (Throwable $e) {
    fwrite(STDERR, 'Privacy sync bootstrap failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$bootstrapped = privacy_bootstrap_sync_rows_from_api($pdo, $apiTransactionsByToken, $owner, $accountId, $environment);
$dueRows = privacy_fetch_due_sync_rows($pdo, $limit, $environment);

$summary = [
    'dry_run' => $dryRun,
    'environment' => $environment,
    'owner' => $owner,
    'account_id' => $accountId,
    'begin' => $beginDate,
    'api_transactions' => count($apiTransactionsByToken),
    'bootstrapped' => $bootstrapped,
    'due' => count($dueRows),
    'processed' => 0,
    'completed' => 0,
    'active' => 0,
    'missing' => 0,
    'errors' => 0,
];

echo privacy_json_encode([
    'ok' => true,
    'phase' => 'start',
    'summary' => $summary,
]) . "\n";

foreach ($dueRows as $row) {
    $token = trim((string)($row['transaction_token'] ?? ''));
    if ($token === '') {
        continue;
    }

    $transactionId = isset($row['transaction_id']) ? (int)$row['transaction_id'] : 0;
    if ($transactionId > 0) {
        $txState = privacy_fetch_open_transaction_state($pdo, $transactionId);
        if (is_array($txState) && (int)($txState['status_norm'] ?? 0) === 2) {
            if (!$dryRun) {
                privacy_mark_sync_complete($pdo, $token, 'Budget transaction already posted', $environment);
            }
            $summary['completed']++;
            echo privacy_json_encode([
                'token' => $token,
                'action' => $dryRun ? 'would_complete_posted' : 'completed_posted',
                'transaction_id' => $transactionId,
            ]) . "\n";
            continue;
        }
    }

    $payload = $apiTransactionsByToken[$token] ?? null;
    if (!is_array($payload)) {
        if (!$dryRun) {
            privacy_mark_sync_not_found($pdo, $token, 'Transaction not found in Privacy API snapshot', $environment);
        }
        $summary['missing']++;
        echo privacy_json_encode([
            'token' => $token,
            'action' => $dryRun ? 'would_mark_missing' : 'marked_missing',
        ]) . "\n";
        continue;
    }

    $status = strtoupper(trim((string)($payload['status'] ?? '')));
    $importSummary = $dryRun
        ? [
            'ok' => true,
            'action' => privacy_is_terminal_status($status) ? 'would_complete' : 'would_update',
            'reason' => null,
            'transaction_id' => $transactionId > 0 ? $transactionId : null,
        ]
        : privacy_process_transaction_import($pdo, $payload, $owner, $accountId);

    if (!$dryRun) {
        privacy_record_sync_result($pdo, $payload, $owner, $accountId, $importSummary, null, $environment);
    }

    $summary['processed']++;
    if (($importSummary['ok'] ?? null) === false) {
        $summary['errors']++;
    }
    if (privacy_is_terminal_status($status) || in_array((string)($importSummary['action'] ?? ''), ['deleted', 'preserved_posted'], true)) {
        $summary['completed']++;
    } else {
        $summary['active']++;
    }

    echo privacy_json_encode([
        'token' => $token,
        'privacy_status' => $status !== '' ? $status : null,
        'action' => $importSummary['action'] ?? null,
        'ok' => $importSummary['ok'] ?? null,
        'reason' => $importSummary['reason'] ?? null,
        'transaction_id' => $importSummary['transaction_id'] ?? null,
    ]) . "\n";
}

echo privacy_json_encode([
    'ok' => true,
    'phase' => 'finish',
    'summary' => $summary,
]) . "\n";
