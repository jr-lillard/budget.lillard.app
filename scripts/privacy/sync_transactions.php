#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/privacy.php';

$options = getopt('', ['dry-run', 'limit::', 'begin::']);
$dryRun = array_key_exists('dry-run', $options);
$limit = isset($options['limit']) ? max(1, min((int)$options['limit'], 250)) : 50;
$beginDate = isset($options['begin']) ? trim((string)$options['begin']) : gmdate('Y-m-d', strtotime('-365 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beginDate)) {
    fwrite(STDERR, "Invalid --begin value. Expected YYYY-MM-DD.\n");
    exit(1);
}

$owner = 'jr@lillard.org';
$accountId = 1;

try {
    $pdo = get_mysql_connection();
    privacy_ensure_sync_table($pdo);
    $apiKey = privacy_api_get_key();
    $apiTransactionsByToken = privacy_api_list_transactions($apiKey, $beginDate);
} catch (Throwable $e) {
    fwrite(STDERR, 'Privacy sync bootstrap failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$bootstrapped = privacy_bootstrap_sync_rows_from_api($pdo, $apiTransactionsByToken, $owner, $accountId);
$dueRows = privacy_fetch_due_sync_rows($pdo, $limit);

$summary = [
    'dry_run' => $dryRun,
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
                privacy_mark_sync_complete($pdo, $token);
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
            privacy_mark_sync_not_found($pdo, $token, 'Transaction not found in Privacy API snapshot');
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
        privacy_record_sync_result($pdo, $payload, $owner, $accountId, $importSummary, null);
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
