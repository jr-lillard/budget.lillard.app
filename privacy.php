<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

function privacy_json_encode(mixed $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to encode JSON payload.');
    }
    return $json;
}

function privacy_db_datetime_to_iso(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    if (!$dt instanceof DateTimeImmutable) {
        return null;
    }
    return $dt->format('Y-m-d\TH:i:s\Z');
}

function privacy_iso_to_db_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function privacy_amount_string(?int $amountMinor): ?string
{
    if ($amountMinor === null) {
        return null;
    }
    return number_format($amountMinor / 100, 2, '.', '');
}

function privacy_format_currency(float $amount): string
{
    return ($amount < 0 ? '-$' : '$') . number_format(abs($amount), 2, '.', ',');
}

function privacy_low_balance_alert_threshold(): float
{
    return 100.0;
}

function privacy_fetch_account_projected_balance(PDO $pdo, string $owner, int $accountId): float
{
    $owner = budget_canonical_user($owner);
    if ($owner === '' || $accountId <= 0) {
        return 0.0;
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM transactions
         WHERE owner = :owner
           AND account_id = :account_id'
    );
    $stmt->execute([
        ':owner' => $owner,
        ':account_id' => $accountId,
    ]);

    return round((float)($stmt->fetchColumn() ?: 0), 2);
}

function privacy_lookup_account_name(PDO $pdo, int $accountId): string
{
    if ($accountId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT name FROM accounts WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $accountId]);
    return trim((string)($stmt->fetchColumn() ?? ''));
}

function privacy_lookup_transaction_description(PDO $pdo, ?int $transactionId): string
{
    $transactionId = (int)$transactionId;
    if ($transactionId <= 0) {
        return '';
    }

    $stmt = $pdo->prepare('SELECT description FROM transactions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $transactionId]);
    return trim((string)($stmt->fetchColumn() ?? ''));
}

function privacy_dashboard_url_for_environment(string $environment, int $accountId): ?string
{
    $environment = privacy_normalize_environment($environment);

    if ($environment === 'dev') {
        $base = 'https://budget.lillard.dev/';
    } elseif (str_starts_with($environment, 'prod')) {
        $base = 'https://budget.lillard.app/';
    } else {
        return null;
    }

    if ($accountId <= 0) {
        return $base;
    }

    return $base . '?' . http_build_query(['account_id' => $accountId]);
}

function privacy_send_low_balance_alert(
    PDO $pdo,
    ?array $decodedJson,
    string $owner,
    int $accountId,
    float $balanceBefore,
    float $balanceAfter,
    ?int $transactionId = null,
    string $environment = 'dev'
): array {
    $threshold = privacy_low_balance_alert_threshold();
    $owner = budget_canonical_user($owner);
    $result = [
        'attempted' => false,
        'triggered' => false,
        'ok' => null,
        'error' => null,
        'email_to' => $owner !== '' ? $owner : null,
        'threshold' => $threshold,
        'balance_before' => round($balanceBefore, 2),
        'balance_after' => round($balanceAfter, 2),
    ];

    if ($owner === '' || $accountId <= 0) {
        return $result;
    }

    if ($balanceBefore < $threshold || $balanceAfter >= $threshold) {
        return $result;
    }

    $meta = privacy_extract_meta($decodedJson);
    $accountName = privacy_lookup_account_name($pdo, $accountId);
    $txDescription = privacy_lookup_transaction_description($pdo, $transactionId);
    $merchantDescriptor = trim((string)($meta['merchant_descriptor'] ?? ''));
    $eventType = trim((string)($meta['event_type'] ?? ''));
    $status = trim((string)($meta['status'] ?? ''));
    $transactionToken = trim((string)($meta['transaction_token'] ?? ''));
    $dashboardUrl = privacy_dashboard_url_for_environment($environment, $accountId);
    $transactionLabel = $txDescription !== ''
        ? $txDescription
        : ($merchantDescriptor !== '' ? $merchantDescriptor : 'Privacy transaction');
    $amountMinor = privacy_extract_amount_minor($decodedJson);
    $amountText = $amountMinor !== 0 ? privacy_format_currency(-1 * (abs($amountMinor) / 100)) : 'n/a';
    $accountLabel = $accountName !== '' ? $accountName : ('Account #' . $accountId);
    $subject = 'Budget alert: ' . $accountLabel . ' scheduled balance is now ' . privacy_format_currency($balanceAfter);

    $lines = [
        'A Privacy transaction dropped the scheduled balance below ' . privacy_format_currency($threshold) . '.',
        '',
        'Account: ' . $accountLabel,
        'Transaction: ' . $transactionLabel,
        'Amount: ' . $amountText,
        'Balance before: ' . privacy_format_currency($balanceBefore),
        'Balance after: ' . privacy_format_currency($balanceAfter),
    ];
    if ($status !== '') {
        $lines[] = 'Privacy status: ' . $status;
    }
    if ($eventType !== '') {
        $lines[] = 'Privacy event: ' . $eventType;
    }
    if ($transactionToken !== '') {
        $lines[] = 'Privacy token: ' . $transactionToken;
    }
    if ($dashboardUrl !== null) {
        $lines[] = 'Dashboard: ' . $dashboardUrl;
    }
    $text = implode("\n", $lines);

    $htmlParts = [
        '<p>A Privacy transaction dropped the scheduled balance below <strong>' . htmlspecialchars(privacy_format_currency($threshold), ENT_QUOTES, 'UTF-8') . '</strong>.</p>',
        '<table cellpadding="6" cellspacing="0" border="0">',
        '<tr><td><strong>Account</strong></td><td>' . htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>',
        '<tr><td><strong>Transaction</strong></td><td>' . htmlspecialchars($transactionLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>',
        '<tr><td><strong>Amount</strong></td><td>' . htmlspecialchars($amountText, ENT_QUOTES, 'UTF-8') . '</td></tr>',
        '<tr><td><strong>Balance before</strong></td><td>' . htmlspecialchars(privacy_format_currency($balanceBefore), ENT_QUOTES, 'UTF-8') . '</td></tr>',
        '<tr><td><strong>Balance after</strong></td><td>' . htmlspecialchars(privacy_format_currency($balanceAfter), ENT_QUOTES, 'UTF-8') . '</td></tr>',
    ];
    if ($status !== '') {
        $htmlParts[] = '<tr><td><strong>Privacy status</strong></td><td>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if ($eventType !== '') {
        $htmlParts[] = '<tr><td><strong>Privacy event</strong></td><td>' . htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    if ($transactionToken !== '') {
        $htmlParts[] = '<tr><td><strong>Privacy token</strong></td><td><code>' . htmlspecialchars($transactionToken, ENT_QUOTES, 'UTF-8') . '</code></td></tr>';
    }
    $htmlParts[] = '</table>';
    if ($dashboardUrl !== null) {
        $safeUrl = htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8');
        $htmlParts[] = '<p><a href="' . $safeUrl . '">Open account in budget dashboard</a></p>';
    }
    $html = implode("\n", $htmlParts);

    $result['attempted'] = true;
    $result['triggered'] = true;
    [$ok, $error] = send_mail_via_smtp2go($owner, $subject, $html, $text, null);
    $result['ok'] = $ok;
    $result['error'] = $error;

    return $result;
}

function privacy_normalize_environment(?string $environment, string $fallback = 'dev'): string
{
    $environment = strtolower(trim((string)$environment));
    if ($environment === '') {
        return strtolower(trim($fallback)) !== '' ? strtolower(trim($fallback)) : 'dev';
    }

    $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $environment);
    $normalized = trim((string)$normalized, '-');
    if ($normalized === '') {
        return strtolower(trim($fallback)) !== '' ? strtolower(trim($fallback)) : 'dev';
    }

    return $normalized;
}

function privacy_api_user_agent(?string $environment = null): string
{
    return 'budget.lillard.app/privacy-sync/' . privacy_normalize_environment($environment, 'app');
}

function privacy_extract_latest_event(?array $decodedJson): ?array
{
    if (!is_array($decodedJson) || !isset($decodedJson['events']) || !is_array($decodedJson['events'])) {
        return null;
    }
    $eventValues = array_values(array_filter($decodedJson['events'], 'is_array'));
    if ($eventValues === []) {
        return null;
    }
    return $eventValues[count($eventValues) - 1];
}

function privacy_extract_meta(?array $decodedJson): array
{
    $latestEvent = privacy_extract_latest_event($decodedJson);
    $eventType = is_array($latestEvent) ? strtoupper(trim((string)($latestEvent['type'] ?? ''))) : '';
    $eventToken = is_array($decodedJson) ? trim((string)($decodedJson['token'] ?? '')) : '';
    $merchantDescriptor = is_array($decodedJson)
        ? trim((string)($decodedJson['merchant']['descriptor'] ?? ''))
        : '';
    $amountMinor = (is_array($decodedJson) && isset($decodedJson['amount']) && is_numeric($decodedJson['amount']))
        ? (int)round((float)$decodedJson['amount'])
        : null;
    $result = is_array($decodedJson) ? strtoupper(trim((string)($decodedJson['result'] ?? ''))) : '';
    $status = is_array($decodedJson) ? strtoupper(trim((string)($decodedJson['status'] ?? ''))) : '';
    $createdAt = is_array($decodedJson) ? trim((string)($decodedJson['created'] ?? '')) : '';
    $eventCreatedAt = is_array($latestEvent) ? trim((string)($latestEvent['created'] ?? '')) : '';

    return [
        'latest_event' => $latestEvent,
        'event_type' => $eventType !== '' ? $eventType : null,
        'transaction_token' => $eventToken !== '' ? $eventToken : null,
        'merchant_descriptor' => $merchantDescriptor !== '' ? $merchantDescriptor : null,
        'amount_minor' => $amountMinor,
        'result' => $result !== '' ? $result : null,
        'status' => $status !== '' ? $status : null,
        'created_at' => $createdAt !== '' ? $createdAt : null,
        'latest_event_at' => $eventCreatedAt !== '' ? $eventCreatedAt : null,
    ];
}

function privacy_ensure_webhooks_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS privacy_webhooks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL DEFAULT "privacy",
            environment VARCHAR(32) NOT NULL DEFAULT "unknown",
            received_at DATETIME NOT NULL,
            process_started_at DATETIME NULL,
            processed_at DATETIME NULL,
            host VARCHAR(255) NOT NULL,
            method VARCHAR(16) NOT NULL,
            request_uri VARCHAR(255) NOT NULL,
            remote_addr VARCHAR(64) DEFAULT NULL,
            content_type VARCHAR(255) DEFAULT NULL,
            body_bytes INT UNSIGNED NOT NULL DEFAULT 0,
            transaction_token VARCHAR(64) DEFAULT NULL,
            event_type VARCHAR(64) DEFAULT NULL,
            result VARCHAR(64) DEFAULT NULL,
            merchant_descriptor VARCHAR(255) DEFAULT NULL,
            amount_minor INT DEFAULT NULL,
            processing_status VARCHAR(32) NOT NULL DEFAULT "received",
            processing_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            processing_error TEXT DEFAULT NULL,
            import_action VARCHAR(64) DEFAULT NULL,
            transaction_id INT UNSIGNED DEFAULT NULL,
            import_summary_json LONGTEXT DEFAULT NULL,
            email_ok TINYINT(1) DEFAULT NULL,
            email_error TEXT DEFAULT NULL,
            email_sent_at DATETIME DEFAULT NULL,
            headers_json LONGTEXT DEFAULT NULL,
            body_json LONGTEXT DEFAULT NULL,
            body_text LONGTEXT DEFAULT NULL,
            body_base64 LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_received_at (received_at),
            KEY idx_processing_status (processing_status),
            KEY idx_transaction_token (transaction_token),
            KEY idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function privacy_ensure_sync_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS privacy_transaction_sync (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(32) NOT NULL DEFAULT "privacy",
            environment VARCHAR(32) NOT NULL DEFAULT "unknown",
            owner VARCHAR(190) NOT NULL,
            account_id INT UNSIGNED DEFAULT NULL,
            transaction_token VARCHAR(64) NOT NULL,
            transaction_id INT UNSIGNED DEFAULT NULL,
            last_webhook_id BIGINT UNSIGNED DEFAULT NULL,
            latest_transaction_status VARCHAR(64) DEFAULT NULL,
            latest_result VARCHAR(64) DEFAULT NULL,
            latest_event_type VARCHAR(64) DEFAULT NULL,
            latest_created_at DATETIME DEFAULT NULL,
            latest_event_at DATETIME DEFAULT NULL,
            latest_amount_minor INT DEFAULT NULL,
            latest_merchant_descriptor VARCHAR(255) DEFAULT NULL,
            latest_payload_json LONGTEXT DEFAULT NULL,
            sync_status VARCHAR(32) NOT NULL DEFAULT "active",
            next_check_at DATETIME DEFAULT NULL,
            last_checked_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            sync_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            not_found_attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_transaction_token (transaction_token),
            KEY idx_sync_status_next_check (sync_status, next_check_at),
            KEY idx_transaction_id (transaction_id),
            KEY idx_last_webhook_id (last_webhook_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function privacy_is_terminal_status(?string $status): bool
{
    $status = strtoupper(trim((string)$status));
    if ($status === '') {
        return false;
    }

    return in_array($status, ['SETTLED', 'VOIDED', 'BOUNCED', 'DECLINED', 'EXPIRED'], true);
}

function privacy_next_check_at(?string $status = null, int $minutes = 15): string
{
    $minutes = max(1, $minutes);
    return gmdate('Y-m-d H:i:s', time() + ($minutes * 60));
}

function privacy_extract_amount_minor(?array $decodedJson): int
{
    if (!is_array($decodedJson)) {
        return 0;
    }

    $candidates = [
        $decodedJson['amount'] ?? null,
        $decodedJson['merchant_amount'] ?? null,
    ];

    $latestEvent = privacy_extract_latest_event($decodedJson);
    if (is_array($latestEvent)) {
        $candidates[] = $latestEvent['amount'] ?? null;
    }

    foreach ($candidates as $candidate) {
        if (is_numeric($candidate)) {
            $value = (int)round((float)$candidate);
            if ($value !== 0) {
                return $value;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_numeric($candidate)) {
            return (int)round((float)$candidate);
        }
    }

    return 0;
}

function privacy_should_delete_unposted(?array $decodedJson): bool
{
    $meta = privacy_extract_meta($decodedJson);
    $status = strtoupper(trim((string)($meta['status'] ?? '')));
    $eventType = strtoupper(trim((string)($meta['event_type'] ?? '')));
    $result = strtoupper(trim((string)($meta['result'] ?? '')));

    if (in_array($status, ['VOIDED', 'DECLINED', 'BOUNCED', 'EXPIRED'], true)) {
        return true;
    }

    if (in_array($eventType, ['VOID', 'AUTHORIZATION_REVERSAL'], true)) {
        return true;
    }

    return $result !== '' && $result !== 'APPROVED' && privacy_is_terminal_status($status);
}

function privacy_process_transaction_import(
    PDO $pdo,
    ?array $decodedJson,
    string $importOwner,
    int $importAccountId,
    string $environment = 'dev'
): array
{
    $meta = privacy_extract_meta($decodedJson);
    $eventType = (string)($meta['event_type'] ?? '');
    $eventToken = (string)($meta['transaction_token'] ?? '');
    $status = (string)($meta['status'] ?? '');

    $importSummary = [
        'attempted' => false,
        'ok' => null,
        'action' => 'ignored',
        'reason' => null,
        'event_type' => $eventType !== '' ? $eventType : null,
        'transaction_token' => $eventToken !== '' ? $eventToken : null,
        'transaction_id' => null,
        'privacy_status' => $status !== '' ? $status : null,
    ];

    if (!is_array($decodedJson) || $eventToken === '') {
        $importSummary['ok'] = true;
        $importSummary['reason'] = 'Missing transaction payload or token';
        return $importSummary;
    }

    $importSummary['attempted'] = true;

    try {
        try { $pdo->exec('ALTER TABLE transactions ADD COLUMN status TINYINT NULL'); } catch (Throwable $e) { /* ignore */ }
        budget_ensure_owner_column($pdo, 'transactions', 'owner', budget_default_owner());
        try { $pdo->exec("ALTER TABLE transactions MODIFY fm_pk VARCHAR(64) NULL"); } catch (Throwable $e) { /* ignore */ }

        $owner = budget_canonical_user($importOwner);
        $balanceBefore = privacy_fetch_account_projected_balance($pdo, $owner, $importAccountId);
        $existingStmt = $pdo->prepare('SELECT id, status, posted FROM transactions WHERE fm_pk = :fm_pk LIMIT 1');
        $existingStmt->execute([':fm_pk' => $eventToken]);
        $existingTx = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $existingId = (int)($existingTx['id'] ?? 0);
        $existingStatus = array_key_exists('status', (array)$existingTx) && $existingTx['status'] !== null
            ? (int)$existingTx['status']
            : (((int)($existingTx['posted'] ?? 0) === 1) ? 2 : 1);

        if (privacy_should_delete_unposted($decodedJson)) {
            if ($existingId > 0 && $existingStatus !== 2) {
                $deleteStmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id LIMIT 1');
                $deleteStmt->execute([':id' => $existingId]);
                $importSummary['ok'] = true;
                $importSummary['action'] = 'deleted';
                $importSummary['transaction_id'] = $existingId;
            } elseif ($existingId > 0) {
                $importSummary['ok'] = true;
                $importSummary['action'] = 'preserved_posted';
                $importSummary['reason'] = 'Existing row already posted; leaving it unchanged';
                $importSummary['transaction_id'] = $existingId;
            } else {
                $importSummary['ok'] = true;
                $importSummary['reason'] = 'Terminal non-posting transaction; nothing to import';
            }
            return $importSummary;
        }

        if (strtoupper(trim((string)($decodedJson['result'] ?? ''))) !== 'APPROVED') {
            $importSummary['ok'] = true;
            $importSummary['reason'] = 'Only APPROVED transactions are imported';
            return $importSummary;
        }

        $amountMinor = privacy_extract_amount_minor($decodedJson);
        if ($amountMinor === 0) {
            $importSummary['ok'] = true;
            $importSummary['reason'] = 'Zero-amount transaction ignored';
            return $importSummary;
        }

        $createdAt = (string)($decodedJson['created'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt) === 1 ? substr($createdAt, 0, 10) : gmdate('Y-m-d');
        $descriptor = trim((string)($decodedJson['merchant']['descriptor'] ?? ''));
        $description = $descriptor !== ''
            ? budget_apply_privacy_description_rule($pdo, $owner, $descriptor)
            : 'Privacy Card Transaction';
        $amount = number_format((abs($amountMinor) / 100) * ($amountMinor < 0 ? 1 : -1), 2, '.', '');

        if ($existingId > 0) {
            if ($existingStatus === 2) {
                $importSummary['ok'] = true;
                $importSummary['action'] = 'preserved_posted';
                $importSummary['reason'] = 'Existing row already posted; leaving it unchanged';
                $importSummary['transaction_id'] = $existingId;
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE transactions
                     SET account_id = :account_id,
                         `date` = :date,
                         amount = :amount,
                         description = :description,
                         updated_at_source = NOW()
                     WHERE id = :id'
                );
                $updateStmt->execute([
                    ':account_id' => $importAccountId,
                    ':date' => $date,
                    ':amount' => $amount,
                    ':description' => $description,
                    ':id' => $existingId,
                ]);
                $balanceAfter = privacy_fetch_account_projected_balance($pdo, $owner, $importAccountId);
                $importSummary['ok'] = true;
                $importSummary['action'] = 'updated';
                $importSummary['transaction_id'] = $existingId;
                $importSummary['balance_before'] = $balanceBefore;
                $importSummary['balance_after'] = $balanceAfter;
                $importSummary['low_balance_alert'] = privacy_send_low_balance_alert(
                    $pdo,
                    $decodedJson,
                    $owner,
                    $importAccountId,
                    $balanceBefore,
                    $balanceAfter,
                    $existingId,
                    $environment
                );
            }
            return $importSummary;
        }

        $insertStmt = $pdo->prepare(
            'INSERT INTO transactions (fm_pk, account_id, `date`, amount, description, check_no, posted, status, owner, created_at_source, updated_at_source)
             VALUES (:fm_pk, :account_id, :date, :amount, :description, NULL, 0, 0, :owner, NOW(), NOW())'
        );
        $insertStmt->execute([
            ':fm_pk' => $eventToken,
            ':account_id' => $importAccountId,
            ':date' => $date,
            ':amount' => $amount,
            ':description' => $description,
            ':owner' => $owner,
        ]);
        $transactionId = (int)$pdo->lastInsertId();
        $balanceAfter = privacy_fetch_account_projected_balance($pdo, $owner, $importAccountId);
        $importSummary['ok'] = true;
        $importSummary['action'] = 'inserted';
        $importSummary['transaction_id'] = $transactionId;
        $importSummary['balance_before'] = $balanceBefore;
        $importSummary['balance_after'] = $balanceAfter;
        $importSummary['low_balance_alert'] = privacy_send_low_balance_alert(
            $pdo,
            $decodedJson,
            $owner,
            $importAccountId,
            $balanceBefore,
            $balanceAfter,
            $transactionId,
            $environment
        );
        return $importSummary;
    } catch (Throwable $e) {
        $importSummary['ok'] = false;
        $importSummary['action'] = 'error';
        $importSummary['reason'] = $e->getMessage();
        return $importSummary;
    }
}

function privacy_fetch_webhook(PDO $pdo, int $id, ?string $environment = null): ?array
{
    $environment = trim((string)$environment);
    if ($environment === '') {
        $stmt = $pdo->prepare('SELECT * FROM privacy_webhooks WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM privacy_webhooks WHERE id = :id AND environment = :environment LIMIT 1');
        $stmt->execute([
            ':id' => $id,
            ':environment' => privacy_normalize_environment($environment),
        ]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function privacy_render_webhook_row(array $row, string $allowedHost, string $scriptPath): array
{
    $headers = json_decode((string)($row['headers_json'] ?? ''), true);
    $bodyJson = json_decode((string)($row['body_json'] ?? ''), true);
    $importSummary = json_decode((string)($row['import_summary_json'] ?? ''), true);
    if (is_array($importSummary) && array_key_exists('email_notification', $importSummary)) {
        unset($importSummary['email_notification']);
    }
    $id = (int)($row['id'] ?? 0);

    return [
        'id' => $id,
        'provider' => $row['provider'] ?? 'privacy',
        'environment' => $row['environment'] ?? 'dev',
        'received_at' => privacy_db_datetime_to_iso($row['received_at'] ?? null),
        'process_started_at' => privacy_db_datetime_to_iso($row['process_started_at'] ?? null),
        'processed_at' => privacy_db_datetime_to_iso($row['processed_at'] ?? null),
        'processing_status' => $row['processing_status'] ?? null,
        'processing_attempts' => isset($row['processing_attempts']) ? (int)$row['processing_attempts'] : null,
        'processing_error' => $row['processing_error'] ?? null,
        'host' => $row['host'] ?? null,
        'method' => $row['method'] ?? null,
        'request_uri' => $row['request_uri'] ?? null,
        'remote_addr' => $row['remote_addr'] ?? null,
        'content_type' => $row['content_type'] ?? null,
        'body_bytes' => isset($row['body_bytes']) ? (int)$row['body_bytes'] : null,
        'event_type' => $row['event_type'] ?? null,
        'event_token' => $row['transaction_token'] ?? null,
        'merchant' => $row['merchant_descriptor'] ?? null,
        'amount' => isset($row['amount_minor']) ? privacy_amount_string((int)$row['amount_minor']) : null,
        'result' => $row['result'] ?? null,
        'import_action' => $row['import_action'] ?? null,
        'transaction_id' => isset($row['transaction_id']) ? (int)$row['transaction_id'] : null,
        'headers' => is_array($headers) ? $headers : null,
        'body_json' => is_array($bodyJson) ? $bodyJson : null,
        'body_text' => $row['body_text'] ?? null,
        'body_base64' => $row['body_base64'] ?? null,
        'import_summary' => is_array($importSummary) ? $importSummary : null,
        'record_url' => 'https://' . $allowedHost . $scriptPath . '?id=' . $id,
    ];
}

function privacy_upsert_sync_record(
    PDO $pdo,
    ?array $decodedJson,
    string $owner,
    int $accountId,
    ?int $transactionId = null,
    ?int $webhookId = null,
    ?string $syncStatusOverride = null,
    ?string $lastError = null,
    string $environment = 'dev'
): void {
    $meta = privacy_extract_meta($decodedJson);
    $token = trim((string)($meta['transaction_token'] ?? ''));
    if ($token === '') {
        return;
    }

    privacy_ensure_sync_table($pdo);
    $environment = privacy_normalize_environment($environment);

    $status = strtoupper(trim((string)($meta['status'] ?? '')));
    $syncStatus = $syncStatusOverride;
    if ($syncStatus === null || trim($syncStatus) === '') {
        $syncStatus = privacy_is_terminal_status($status) ? 'complete' : 'active';
    }

    $nextCheckAt = $syncStatus === 'active' ? privacy_next_check_at($status) : null;
    $completedAt = $syncStatus === 'complete' ? gmdate('Y-m-d H:i:s') : null;

    $stmt = $pdo->prepare(
        'INSERT INTO privacy_transaction_sync (
            provider, environment, owner, account_id, transaction_token, transaction_id, last_webhook_id,
            latest_transaction_status, latest_result, latest_event_type, latest_created_at, latest_event_at,
            latest_amount_minor, latest_merchant_descriptor, latest_payload_json,
            sync_status, next_check_at, completed_at, last_error
        ) VALUES (
            "privacy", :environment, :owner, :account_id, :transaction_token, :transaction_id, :last_webhook_id,
            :latest_transaction_status, :latest_result, :latest_event_type, :latest_created_at, :latest_event_at,
            :latest_amount_minor, :latest_merchant_descriptor, :latest_payload_json,
            :sync_status, :next_check_at, :completed_at, :last_error
        )
        ON DUPLICATE KEY UPDATE
            environment = VALUES(environment),
            owner = VALUES(owner),
            account_id = VALUES(account_id),
            transaction_id = COALESCE(VALUES(transaction_id), transaction_id),
            last_webhook_id = COALESCE(VALUES(last_webhook_id), last_webhook_id),
            latest_transaction_status = VALUES(latest_transaction_status),
            latest_result = VALUES(latest_result),
            latest_event_type = VALUES(latest_event_type),
            latest_created_at = VALUES(latest_created_at),
            latest_event_at = VALUES(latest_event_at),
            latest_amount_minor = VALUES(latest_amount_minor),
            latest_merchant_descriptor = VALUES(latest_merchant_descriptor),
            latest_payload_json = VALUES(latest_payload_json),
            sync_status = VALUES(sync_status),
            next_check_at = VALUES(next_check_at),
            completed_at = CASE
                WHEN VALUES(sync_status) = "complete" THEN COALESCE(completed_at, VALUES(completed_at), UTC_TIMESTAMP())
                ELSE NULL
            END,
            last_error = VALUES(last_error)'
    );
    $stmt->execute([
        ':environment' => $environment,
        ':owner' => budget_canonical_user($owner),
        ':account_id' => $accountId > 0 ? $accountId : null,
        ':transaction_token' => $token,
        ':transaction_id' => ($transactionId !== null && $transactionId > 0) ? $transactionId : null,
        ':last_webhook_id' => ($webhookId !== null && $webhookId > 0) ? $webhookId : null,
        ':latest_transaction_status' => $status !== '' ? $status : null,
        ':latest_result' => $meta['result'],
        ':latest_event_type' => $meta['event_type'],
        ':latest_created_at' => privacy_iso_to_db_datetime((string)($meta['created_at'] ?? '')),
        ':latest_event_at' => privacy_iso_to_db_datetime((string)($meta['latest_event_at'] ?? '')),
        ':latest_amount_minor' => $meta['amount_minor'],
        ':latest_merchant_descriptor' => $meta['merchant_descriptor'],
        ':latest_payload_json' => is_array($decodedJson) ? privacy_json_encode($decodedJson) : null,
        ':sync_status' => $syncStatus,
        ':next_check_at' => $nextCheckAt,
        ':completed_at' => $completedAt,
        ':last_error' => $lastError,
    ]);
}

function privacy_record_sync_result(
    PDO $pdo,
    ?array $decodedJson,
    string $owner,
    int $accountId,
    array $importSummary,
    ?int $webhookId = null,
    string $environment = 'dev'
): void {
    $meta = privacy_extract_meta($decodedJson);
    $token = trim((string)($meta['transaction_token'] ?? ''));
    if ($token === '') {
        return;
    }

    privacy_ensure_sync_table($pdo);
    $environment = privacy_normalize_environment($environment);

    $status = strtoupper(trim((string)($meta['status'] ?? '')));
    $isComplete = privacy_is_terminal_status($status)
        || in_array((string)($importSummary['action'] ?? ''), ['deleted', 'preserved_posted'], true);
    $syncStatus = $isComplete ? 'complete' : 'active';
    $nextCheckAt = $isComplete ? null : privacy_next_check_at($status);
    $completedAt = $isComplete ? gmdate('Y-m-d H:i:s') : null;
    $transactionId = isset($importSummary['transaction_id']) ? (int)$importSummary['transaction_id'] : null;
    $lastError = ($importSummary['ok'] ?? null) === false ? (string)($importSummary['reason'] ?? 'Unknown sync error') : null;

    $stmt = $pdo->prepare(
        'UPDATE privacy_transaction_sync
         SET environment = :environment,
             owner = :owner,
             account_id = :account_id,
             transaction_id = COALESCE(:transaction_id, transaction_id),
             last_webhook_id = COALESCE(:last_webhook_id, last_webhook_id),
             latest_transaction_status = :latest_transaction_status,
             latest_result = :latest_result,
             latest_event_type = :latest_event_type,
             latest_created_at = :latest_created_at,
             latest_event_at = :latest_event_at,
             latest_amount_minor = :latest_amount_minor,
             latest_merchant_descriptor = :latest_merchant_descriptor,
             latest_payload_json = :latest_payload_json,
             sync_status = :sync_status,
             next_check_at = :next_check_at,
             last_checked_at = NOW(),
             completed_at = :completed_at,
             sync_attempts = sync_attempts + 1,
             not_found_attempts = 0,
             last_error = :last_error
         WHERE transaction_token = :transaction_token'
    );
    $stmt->execute([
        ':environment' => $environment,
        ':owner' => budget_canonical_user($owner),
        ':account_id' => $accountId > 0 ? $accountId : null,
        ':transaction_id' => ($transactionId !== null && $transactionId > 0) ? $transactionId : null,
        ':last_webhook_id' => ($webhookId !== null && $webhookId > 0) ? $webhookId : null,
        ':latest_transaction_status' => $status !== '' ? $status : null,
        ':latest_result' => $meta['result'],
        ':latest_event_type' => $meta['event_type'],
        ':latest_created_at' => privacy_iso_to_db_datetime((string)($meta['created_at'] ?? '')),
        ':latest_event_at' => privacy_iso_to_db_datetime((string)($meta['latest_event_at'] ?? '')),
        ':latest_amount_minor' => $meta['amount_minor'],
        ':latest_merchant_descriptor' => $meta['merchant_descriptor'],
        ':latest_payload_json' => is_array($decodedJson) ? privacy_json_encode($decodedJson) : null,
        ':sync_status' => $syncStatus,
        ':next_check_at' => $nextCheckAt,
        ':completed_at' => $completedAt,
        ':last_error' => $lastError,
        ':transaction_token' => $token,
    ]);

    if ($stmt->rowCount() === 0) {
        privacy_upsert_sync_record($pdo, $decodedJson, $owner, $accountId, $transactionId, $webhookId, $syncStatus, $lastError, $environment);
        if ($syncStatus === 'complete' || $lastError !== null) {
            $touchStmt = $pdo->prepare(
                'UPDATE privacy_transaction_sync
                 SET last_checked_at = NOW(),
                     sync_attempts = sync_attempts + 1,
                     not_found_attempts = 0
                 WHERE transaction_token = :transaction_token'
            );
            $touchStmt->execute([':transaction_token' => $token]);
        }
    }
}

function privacy_mark_sync_not_found(PDO $pdo, string $transactionToken, string $message, string $environment = 'dev'): void
{
    privacy_ensure_sync_table($pdo);
    $environment = privacy_normalize_environment($environment);

    $stmt = $pdo->prepare(
        'UPDATE privacy_transaction_sync
         SET last_checked_at = NOW(),
             sync_attempts = sync_attempts + 1,
             not_found_attempts = not_found_attempts + 1,
             last_error = :last_error,
             sync_status = CASE WHEN not_found_attempts + 1 >= 5 THEN "error" ELSE "active" END,
             next_check_at = CASE WHEN not_found_attempts + 1 >= 5 THEN NULL ELSE :next_check_at END
         WHERE transaction_token = :transaction_token
           AND environment = :environment'
    );
    $stmt->execute([
        ':last_error' => $message,
        ':next_check_at' => privacy_next_check_at(null, 60),
        ':transaction_token' => $transactionToken,
        ':environment' => $environment,
    ]);
}

function privacy_mark_sync_complete(
    PDO $pdo,
    string $transactionToken,
    string $reason = 'Budget transaction already posted',
    string $environment = 'dev'
): void
{
    privacy_ensure_sync_table($pdo);
    $environment = privacy_normalize_environment($environment);

    $stmt = $pdo->prepare(
        'UPDATE privacy_transaction_sync
         SET sync_status = "complete",
             next_check_at = NULL,
             completed_at = COALESCE(completed_at, NOW()),
             last_checked_at = NOW(),
             last_error = :last_error
         WHERE transaction_token = :transaction_token
           AND environment = :environment'
    );
    $stmt->execute([
        ':last_error' => $reason,
        ':transaction_token' => $transactionToken,
        ':environment' => $environment,
    ]);
}

function privacy_fetch_due_sync_rows(PDO $pdo, int $limit = 25, string $environment = 'dev'): array
{
    privacy_ensure_sync_table($pdo);
    $limit = max(1, min($limit, 250));
    $environment = privacy_normalize_environment($environment);

    $stmt = $pdo->prepare(
        'SELECT *
         FROM privacy_transaction_sync
         WHERE environment = :environment
           AND sync_status = "active"
           AND (next_check_at IS NULL OR next_check_at <= NOW())
         ORDER BY COALESCE(next_check_at, created_at) ASC, id ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':environment', $environment, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function privacy_fetch_open_transaction_state(PDO $pdo, int $transactionId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id,
                COALESCE(status, CASE WHEN posted = 1 THEN 2 ELSE 1 END) AS status_norm,
                posted
         FROM transactions
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $transactionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function privacy_bootstrap_sync_rows_from_api(
    PDO $pdo,
    array $apiTransactionsByToken,
    string $owner,
    int $accountId,
    string $environment = 'dev'
): int
{
    if ($apiTransactionsByToken === []) {
        return 0;
    }

    privacy_ensure_sync_table($pdo);
    $environment = privacy_normalize_environment($environment);

    $stmt = $pdo->prepare(
        'SELECT id, fm_pk
         FROM transactions
         WHERE owner = :owner
           AND account_id = :account_id
           AND fm_pk IS NOT NULL
           AND fm_pk <> ""
           AND COALESCE(status, CASE WHEN posted = 1 THEN 2 ELSE 1 END) <> 2'
    );
    $stmt->execute([
        ':owner' => budget_canonical_user($owner),
        ':account_id' => $accountId,
    ]);

    $count = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $token = trim((string)($row['fm_pk'] ?? ''));
        if ($token === '' || !isset($apiTransactionsByToken[$token]) || !is_array($apiTransactionsByToken[$token])) {
            continue;
        }

        privacy_upsert_sync_record(
            $pdo,
            $apiTransactionsByToken[$token],
            $owner,
            $accountId,
            (int)$row['id'],
            null,
            'active',
            null,
            $environment
        );
        $touchStmt = $pdo->prepare(
            'UPDATE privacy_transaction_sync
             SET next_check_at = NOW(),
                 last_error = NULL
             WHERE transaction_token = :transaction_token
               AND environment = :environment'
        );
        $touchStmt->execute([
            ':transaction_token' => $token,
            ':environment' => $environment,
        ]);
        $count++;
    }

    return $count;
}

function privacy_api_get_key(): string
{
    $envKey = trim((string)getenv('PRIVACY_API_KEY'));
    if ($envKey !== '') {
        return $envKey;
    }

    $config = budget_get_config();
    $privacy = $config['privacy'] ?? null;
    if (is_array($privacy)) {
        $configKey = trim((string)($privacy['api_key'] ?? ''));
        if ($configKey !== '') {
            return $configKey;
        }
    }

    throw new RuntimeException('Privacy API key is not configured. Set PRIVACY_API_KEY or add config[privacy][api_key].');
}

function privacy_api_request_json(string $apiKey, string $path, array $query = [], ?string $environment = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Privacy API sync.');
    }

    $url = 'https://api.privacy.com' . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL for Privacy API request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: api-key ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => privacy_api_user_agent($environment),
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Privacy API request failed: ' . $error);
    }

    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Privacy API returned invalid JSON.');
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = trim((string)($decoded['message'] ?? $decoded['error'] ?? ''));
        if ($message === '') {
            $message = 'HTTP ' . $statusCode;
        }
        throw new RuntimeException('Privacy API returned ' . $message);
    }

    return $decoded;
}

function privacy_api_list_transactions(
    string $apiKey,
    string $beginDate,
    int $pageSize = 1000,
    int $maxPages = 25,
    ?string $environment = null
): array
{
    $pageSize = max(1, min($pageSize, 1000));
    $maxPages = max(1, min($maxPages, 100));

    $page = 1;
    $transactions = [];

    do {
        $response = privacy_api_request_json($apiKey, '/v1/transactions', [
            'begin' => $beginDate,
            'page' => $page,
            'page_size' => $pageSize,
        ], $environment);

        $rows = $response['data'] ?? [];
        if (!is_array($rows)) {
            break;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $token = trim((string)($row['token'] ?? ''));
            if ($token === '') {
                continue;
            }
            $transactions[$token] = $row;
        }

        $totalPages = (int)($response['total_pages'] ?? $page);
        if ($page >= $totalPages) {
            break;
        }

        $page++;
    } while ($page <= $maxPages);

    return $transactions;
}
