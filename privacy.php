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
            environment VARCHAR(32) NOT NULL DEFAULT "dev",
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
            environment VARCHAR(32) NOT NULL DEFAULT "dev",
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

function privacy_process_transaction_import(PDO $pdo, ?array $decodedJson, string $importOwner, int $importAccountId): array
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
                $importSummary['ok'] = true;
                $importSummary['action'] = 'updated';
                $importSummary['transaction_id'] = $existingId;
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
        $importSummary['ok'] = true;
        $importSummary['action'] = 'inserted';
        $importSummary['transaction_id'] = (int)$pdo->lastInsertId();
        return $importSummary;
    } catch (Throwable $e) {
        $importSummary['ok'] = false;
        $importSummary['action'] = 'error';
        $importSummary['reason'] = $e->getMessage();
        return $importSummary;
    }
}

function privacy_fetch_webhook(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM privacy_webhooks WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
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
    ?string $lastError = null
): void {
    $meta = privacy_extract_meta($decodedJson);
    $token = trim((string)($meta['transaction_token'] ?? ''));
    if ($token === '') {
        return;
    }

    privacy_ensure_sync_table($pdo);

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
            "privacy", "dev", :owner, :account_id, :transaction_token, :transaction_id, :last_webhook_id,
            :latest_transaction_status, :latest_result, :latest_event_type, :latest_created_at, :latest_event_at,
            :latest_amount_minor, :latest_merchant_descriptor, :latest_payload_json,
            :sync_status, :next_check_at, :completed_at, :last_error
        )
        ON DUPLICATE KEY UPDATE
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
    ?int $webhookId = null
): void {
    $meta = privacy_extract_meta($decodedJson);
    $token = trim((string)($meta['transaction_token'] ?? ''));
    if ($token === '') {
        return;
    }

    privacy_ensure_sync_table($pdo);

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
         SET owner = :owner,
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
        privacy_upsert_sync_record($pdo, $decodedJson, $owner, $accountId, $transactionId, $webhookId, $syncStatus, $lastError);
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

function privacy_mark_sync_not_found(PDO $pdo, string $transactionToken, string $message): void
{
    privacy_ensure_sync_table($pdo);

    $stmt = $pdo->prepare(
        'UPDATE privacy_transaction_sync
         SET last_checked_at = NOW(),
             sync_attempts = sync_attempts + 1,
             not_found_attempts = not_found_attempts + 1,
             last_error = :last_error,
             sync_status = CASE WHEN not_found_attempts + 1 >= 5 THEN "error" ELSE "active" END,
             next_check_at = CASE WHEN not_found_attempts + 1 >= 5 THEN NULL ELSE :next_check_at END
         WHERE transaction_token = :transaction_token'
    );
    $stmt->execute([
        ':last_error' => $message,
        ':next_check_at' => privacy_next_check_at(null, 60),
        ':transaction_token' => $transactionToken,
    ]);
}

function privacy_mark_sync_complete(PDO $pdo, string $transactionToken, string $reason = 'Budget transaction already posted'): void
{
    privacy_ensure_sync_table($pdo);

    $stmt = $pdo->prepare(
        'UPDATE privacy_transaction_sync
         SET sync_status = "complete",
             next_check_at = NULL,
             completed_at = COALESCE(completed_at, NOW()),
             last_checked_at = NOW(),
             last_error = :last_error
         WHERE transaction_token = :transaction_token'
    );
    $stmt->execute([
        ':last_error' => $reason,
        ':transaction_token' => $transactionToken,
    ]);
}

function privacy_fetch_due_sync_rows(PDO $pdo, int $limit = 25): array
{
    privacy_ensure_sync_table($pdo);
    $limit = max(1, min($limit, 250));

    $stmt = $pdo->prepare(
        'SELECT *
         FROM privacy_transaction_sync
         WHERE sync_status = "active"
           AND (next_check_at IS NULL OR next_check_at <= NOW())
         ORDER BY COALESCE(next_check_at, created_at) ASC, id ASC
         LIMIT :limit'
    );
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

function privacy_bootstrap_sync_rows_from_api(PDO $pdo, array $apiTransactionsByToken, string $owner, int $accountId): int
{
    if ($apiTransactionsByToken === []) {
        return 0;
    }

    privacy_ensure_sync_table($pdo);

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
            'active'
        );
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

function privacy_api_request_json(string $apiKey, string $path, array $query = []): array
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
        CURLOPT_USERAGENT => 'budget.lillard.dev/privacy-sync',
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

function privacy_api_list_transactions(string $apiKey, string $beginDate, int $pageSize = 1000, int $maxPages = 25): array
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
        ]);

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
