<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

function plaid_normalize_environment(?string $environment = null): string
{
    $env = strtolower(trim((string)($environment ?? '')));
    if ($env === '') {
        $env = strtolower(trim((string)getenv('BUDGET_PLAID_ENVIRONMENT')));
    }
    if ($env === '') {
        $env = 'production';
    }
    if ($env === 'prod') {
        $env = 'production';
    }
    if (!in_array($env, ['production', 'sandbox'], true)) {
        throw new RuntimeException('Unsupported Plaid environment.');
    }
    return $env;
}

function plaid_env_file(string $environment): string
{
    $environment = plaid_normalize_environment($environment);
    return '/root/.config/budget-lillard/plaid-' . $environment . '.env';
}

function plaid_env_paths(string $environment): array
{
    $environment = plaid_normalize_environment($environment);
    $filename = 'plaid-' . $environment . '.env';
    $paths = [];
    $configDir = trim((string)getenv('BUDGET_PLAID_CONFIG_DIR'));
    if ($configDir !== '') {
        $paths[] = rtrim($configDir, '/') . '/' . $filename;
    }
    $paths[] = '/etc/budget-lillard/' . $filename;
    $paths[] = plaid_env_file($environment);
    return array_values(array_unique($paths));
}

function plaid_parse_env_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $values[$key] = $value;
        }
    }
    return $values;
}

function plaid_get_config(?string $environment = null): array
{
    $environment = plaid_normalize_environment($environment);
    $fileValues = [];
    foreach (plaid_env_paths($environment) as $path) {
        foreach (plaid_parse_env_file($path) as $key => $value) {
            if (!array_key_exists($key, $fileValues)) {
                $fileValues[$key] = $value;
            }
        }
    }
    $clientId = trim((string)($fileValues['PLAID_CLIENT_ID'] ?? getenv('PLAID_CLIENT_ID')));
    $secret = trim((string)($fileValues['PLAID_SECRET'] ?? getenv('PLAID_SECRET')));
    if ($clientId === '' || $secret === '') {
        throw new RuntimeException('Plaid credentials are not configured for ' . $environment . '.');
    }
    return [
        'environment' => $environment,
        'client_id' => $clientId,
        'secret' => $secret,
        'host' => 'https://' . $environment . '.plaid.com',
    ];
}

function plaid_has_config(?string $environment = null): bool
{
    try {
        plaid_get_config($environment);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function plaid_api_request(string $path, array $body, ?string $environment = null, int $timeout = 45): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for Plaid.');
    }
    $config = plaid_get_config($environment);
    $url = $config['host'] . $path;
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        throw new RuntimeException('Unable to encode Plaid request.');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize Plaid request.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'PLAID-CLIENT-ID: ' . $config['client_id'],
            'PLAID-SECRET: ' . $config['secret'],
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'budget.lillard.app/plaid',
    ]);

    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Plaid request failed: ' . $error);
    }
    $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Plaid returned invalid JSON.');
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        $code = trim((string)($decoded['error_code'] ?? ''));
        $message = trim((string)($decoded['error_message'] ?? $decoded['display_message'] ?? ''));
        if ($message === '') {
            $message = 'HTTP ' . $statusCode;
        }
        throw new RuntimeException('Plaid ' . ($code !== '' ? $code . ': ' : '') . $message);
    }
    return $decoded;
}

function plaid_current_webhook_url(): ?string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')) {
        return null;
    }
    $scheme = auth_is_https() ? 'https' : 'http';
    return $scheme . '://' . $host . '/plaid_webhook.php';
}

function plaid_best_effort_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Schema drift is handled opportunistically because production already had an early Plaid table draft.
    }
}

function plaid_ensure_tables(PDO $pdo): void
{
    budget_ensure_owner_column($pdo, 'transactions', 'owner', budget_default_owner());
    plaid_best_effort_exec($pdo, 'ALTER TABLE transactions ADD COLUMN status TINYINT NULL');
    plaid_best_effort_exec($pdo, 'ALTER TABLE transactions MODIFY fm_pk VARCHAR(64) NULL');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS plaid_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            owner VARCHAR(190) NOT NULL,
            environment VARCHAR(32) NOT NULL DEFAULT "production",
            item_id VARCHAR(255) NOT NULL,
            access_token TEXT NOT NULL,
            institution_id VARCHAR(64) DEFAULT NULL,
            institution_name VARCHAR(255) DEFAULT NULL,
            link_session_id VARCHAR(255) DEFAULT NULL,
            transactions_cursor TEXT DEFAULT NULL,
            sync_status VARCHAR(32) NOT NULL DEFAULT "active",
            last_synced_at DATETIME DEFAULT NULL,
            last_error TEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_owner_env_item (owner, environment, item_id),
            KEY idx_owner_env (owner, environment),
            KEY idx_item_id (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS plaid_accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plaid_item_id BIGINT UNSIGNED NOT NULL,
            plaid_account_id VARCHAR(255) NOT NULL,
            local_account_id INT UNSIGNED DEFAULT NULL,
            name VARCHAR(255) DEFAULT NULL,
            official_name VARCHAR(255) DEFAULT NULL,
            mask VARCHAR(32) DEFAULT NULL,
            type VARCHAR(64) DEFAULT NULL,
            subtype VARCHAR(64) DEFAULT NULL,
            current_balance DECIMAL(14,2) DEFAULT NULL,
            available_balance DECIMAL(14,2) DEFAULT NULL,
            iso_currency_code VARCHAR(16) DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_item_account (plaid_item_id, plaid_account_id),
            KEY idx_local_account (local_account_id),
            CONSTRAINT fk_plaid_accounts_item FOREIGN KEY (plaid_item_id) REFERENCES plaid_items (id) ON DELETE CASCADE,
            CONSTRAINT fk_plaid_accounts_local FOREIGN KEY (local_account_id) REFERENCES accounts (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS plaid_transactions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plaid_item_id BIGINT UNSIGNED NOT NULL,
            plaid_account_id VARCHAR(255) NOT NULL,
            plaid_transaction_id VARCHAR(255) NOT NULL,
            budget_transaction_id INT UNSIGNED DEFAULT NULL,
            pending_transaction_id VARCHAR(255) DEFAULT NULL,
            date DATE DEFAULT NULL,
            authorized_date DATE DEFAULT NULL,
            amount DECIMAL(14,2) DEFAULT NULL,
            name TEXT DEFAULT NULL,
            merchant_name VARCHAR(255) DEFAULT NULL,
            pending TINYINT(1) NOT NULL DEFAULT 0,
            removed TINYINT(1) NOT NULL DEFAULT 0,
            match_method VARCHAR(64) DEFAULT NULL,
            matched_at DATETIME DEFAULT NULL,
            raw_json LONGTEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_item_transaction (plaid_item_id, plaid_transaction_id),
            KEY idx_budget_transaction (budget_transaction_id),
            KEY idx_item_account (plaid_item_id, plaid_account_id),
            KEY idx_match_method (match_method),
            CONSTRAINT fk_plaid_transactions_item FOREIGN KEY (plaid_item_id) REFERENCES plaid_items (id) ON DELETE CASCADE,
            CONSTRAINT fk_plaid_transactions_budget FOREIGN KEY (budget_transaction_id) REFERENCES transactions (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS plaid_webhooks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            environment VARCHAR(32) NOT NULL DEFAULT "production",
            received_at DATETIME NOT NULL,
            item_id VARCHAR(255) DEFAULT NULL,
            webhook_type VARCHAR(64) DEFAULT NULL,
            webhook_code VARCHAR(128) DEFAULT NULL,
            payload_json LONGTEXT NOT NULL,
            processing_status VARCHAR(32) NOT NULL DEFAULT "received",
            processing_message TEXT DEFAULT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_item_received (item_id, received_at),
            KEY idx_type_code (webhook_type, webhook_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    plaid_best_effort_exec($pdo, 'ALTER TABLE plaid_transactions ADD COLUMN match_method VARCHAR(64) DEFAULT NULL');
    plaid_best_effort_exec($pdo, 'ALTER TABLE plaid_transactions ADD COLUMN matched_at DATETIME DEFAULT NULL');
    plaid_best_effort_exec($pdo, 'ALTER TABLE plaid_transactions ADD KEY idx_match_method (match_method)');
}

function plaid_fetch_items(PDO $pdo, string $owner): array
{
    plaid_ensure_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT pi.id, pi.environment, pi.item_id, pi.institution_name, pi.sync_status,
                pi.last_synced_at, pi.last_error, pi.created_at,
                COUNT(DISTINCT pa.id) AS account_count,
                COUNT(DISTINCT CASE WHEN pa.local_account_id IS NOT NULL THEN pa.id END) AS mapped_account_count,
                COUNT(DISTINCT pt.id) AS transaction_count,
                COUNT(DISTINCT CASE WHEN pt.budget_transaction_id IS NOT NULL THEN pt.id END) AS matched_transaction_count,
                COUNT(DISTINCT CASE WHEN pt.budget_transaction_id IS NULL THEN pt.id END) AS unmatched_transaction_count
         FROM plaid_items pi
         LEFT JOIN plaid_accounts pa ON pa.plaid_item_id = pi.id
         LEFT JOIN plaid_transactions pt ON pt.plaid_item_id = pi.id AND pt.removed = 0
         WHERE pi.owner = ?
         GROUP BY pi.id
         ORDER BY pi.environment, pi.institution_name, pi.id'
    );
    $stmt->execute([budget_canonical_user($owner)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function plaid_fetch_account_mappings(PDO $pdo, string $owner): array
{
    plaid_ensure_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT pa.id, pa.plaid_item_id, pa.plaid_account_id, pa.local_account_id,
                pa.name, pa.official_name, pa.mask, pa.type, pa.subtype,
                pa.current_balance, pa.available_balance, pa.iso_currency_code,
                pi.environment, pi.institution_name, pi.last_synced_at, pi.sync_status,
                a.name AS local_account_name,
                COUNT(pt.id) AS transaction_count,
                SUM(CASE WHEN pt.id IS NOT NULL AND pt.budget_transaction_id IS NOT NULL THEN 1 ELSE 0 END) AS matched_transaction_count,
                SUM(CASE WHEN pt.id IS NOT NULL AND pt.budget_transaction_id IS NULL THEN 1 ELSE 0 END) AS unmatched_transaction_count
         FROM plaid_accounts pa
         JOIN plaid_items pi ON pi.id = pa.plaid_item_id
         LEFT JOIN accounts a ON a.id = pa.local_account_id
         LEFT JOIN plaid_transactions pt
           ON pt.plaid_item_id = pa.plaid_item_id
          AND pt.plaid_account_id = pa.plaid_account_id
          AND pt.removed = 0
         WHERE pi.owner = ?
         GROUP BY pa.id, pa.plaid_item_id, pa.plaid_account_id, pa.local_account_id,
                  pa.name, pa.official_name, pa.mask, pa.type, pa.subtype,
                  pa.current_balance, pa.available_balance, pa.iso_currency_code,
                  pi.environment, pi.institution_name, pi.last_synced_at, pi.sync_status,
                  a.name
         ORDER BY pi.environment, pi.institution_name, pa.name, pa.mask, pa.id'
    );
    $stmt->execute([budget_canonical_user($owner)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function plaid_fetch_account_mapping_row(PDO $pdo, string $owner, int $plaidAccountRowId): ?array
{
    plaid_ensure_tables($pdo);
    $stmt = $pdo->prepare(
        'SELECT pa.*, pi.owner, pi.environment, pi.institution_name, a.name AS local_account_name
         FROM plaid_accounts pa
         JOIN plaid_items pi ON pi.id = pa.plaid_item_id
         LEFT JOIN accounts a ON a.id = pa.local_account_id
         WHERE pa.id = ? AND pi.owner = ?
         LIMIT 1'
    );
    $stmt->execute([$plaidAccountRowId, budget_canonical_user($owner)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function plaid_fetch_unmatched_review(PDO $pdo, string $owner, int $limit = 25): array
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    $limit = max(1, min(100, $limit));
    $stmt = $pdo->prepare(
        'SELECT pt.id, pt.plaid_item_id, pt.plaid_account_id, pt.plaid_transaction_id,
                pt.budget_transaction_id,
                pt.date, pt.authorized_date, pt.amount, pt.name, pt.merchant_name,
                pt.pending, pt.match_method,
                pi.institution_name,
                pa.name AS plaid_account_name,
                pa.mask AS plaid_account_mask,
                pa.local_account_id,
                a.name AS local_account_name
         FROM plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         JOIN plaid_accounts pa
           ON pa.plaid_item_id = pt.plaid_item_id
          AND pa.plaid_account_id = pt.plaid_account_id
         LEFT JOIN accounts a ON a.id = pa.local_account_id
         WHERE pi.owner = :owner
           AND pt.removed = 0
           AND pt.budget_transaction_id IS NULL
           AND pa.local_account_id IS NOT NULL
         ORDER BY COALESCE(pt.date, pt.authorized_date) DESC, pt.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([':owner' => $owner]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['budget_amount'] = plaid_budget_amount_from_plaid_amount($row['amount'] ?? null);
        $row['candidates'] = plaid_fetch_merge_candidates(
            $pdo,
            $owner,
            (int)($row['local_account_id'] ?? 0),
            $row['budget_amount'],
            plaid_valid_date((string)($row['date'] ?? '')) ?? plaid_valid_date((string)($row['authorized_date'] ?? '')),
            (int)($row['id'] ?? 0),
            (int)($row['budget_transaction_id'] ?? 0)
        );
    }
    unset($row);
    return $rows;
}

function plaid_fetch_merge_candidates(
    PDO $pdo,
    string $owner,
    int $localAccountId,
    ?string $budgetAmount,
    ?string $date,
    int $plaidTransactionRowId,
    int $excludeBudgetTransactionId = 0,
    int $limit = 12
): array {
    if ($localAccountId <= 0) {
        return [];
    }
    $owner = budget_canonical_user($owner);
    $limit = max(1, min(50, $limit));
    if ($date === null) {
        $date = gmdate('Y-m-d');
    }
    [$startDate, $endDate] = plaid_date_range($date, 14);
    $amountForOrder = $budgetAmount !== null && is_numeric($budgetAmount) ? $budgetAmount : '0.00';

    $stmt = $pdo->prepare(
        'SELECT t.id, t.`date`, t.amount, t.description, t.status, t.posted
         FROM transactions t
         LEFT JOIN plaid_transactions linked
           ON linked.budget_transaction_id = t.id
          AND linked.removed = 0
          AND linked.id <> :plaid_transaction_row_id
         WHERE t.owner = :owner
           AND t.account_id = :account_id
           AND t.`date` BETWEEN :start_date AND :end_date
           AND (:exclude_budget_transaction_id_zero = 0 OR t.id <> :exclude_budget_transaction_id)
           AND linked.id IS NULL
           ORDER BY (t.amount = :amount_for_order) DESC,
                  ABS(DATEDIFF(t.`date`, :target_date)) ASC,
                  t.id DESC
         LIMIT ' . $limit
    );
    $stmt->execute([
        ':plaid_transaction_row_id' => $plaidTransactionRowId,
        ':owner' => $owner,
        ':account_id' => $localAccountId,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':exclude_budget_transaction_id_zero' => max(0, $excludeBudgetTransactionId),
        ':exclude_budget_transaction_id' => max(0, $excludeBudgetTransactionId),
        ':amount_for_order' => $amountForOrder,
        ':target_date' => $date,
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function plaid_merge_transaction(PDO $pdo, string $owner, int $plaidTransactionRowId, int $budgetTransactionId): array
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    if ($plaidTransactionRowId <= 0 || $budgetTransactionId <= 0) {
        throw new RuntimeException('Missing Plaid or budget transaction.');
    }

    $plaidStmt = $pdo->prepare(
        'SELECT pt.id, pt.budget_transaction_id, pt.match_method,
                pa.local_account_id,
                pi.owner
         FROM plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         JOIN plaid_accounts pa
           ON pa.plaid_item_id = pt.plaid_item_id
          AND pa.plaid_account_id = pt.plaid_account_id
         WHERE pt.id = ? AND pi.owner = ? AND pt.removed = 0
         LIMIT 1'
    );
    $plaidStmt->execute([$plaidTransactionRowId, $owner]);
    $plaid = $plaidStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($plaid)) {
        throw new RuntimeException('Plaid transaction not found.');
    }

    $localAccountId = (int)($plaid['local_account_id'] ?? 0);
    if ($localAccountId <= 0) {
        throw new RuntimeException('Map the Plaid account before merging transactions.');
    }

    $budgetStmt = $pdo->prepare(
        'SELECT id, account_id, fm_pk
         FROM transactions
         WHERE id = ? AND owner = ?
         LIMIT 1'
    );
    $budgetStmt->execute([$budgetTransactionId, $owner]);
    $budget = $budgetStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($budget)) {
        throw new RuntimeException('Budget transaction not found.');
    }
    if ((int)($budget['account_id'] ?? 0) !== $localAccountId) {
        throw new RuntimeException('Budget transaction must belong to the mapped account.');
    }
    $linkedStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM plaid_transactions
         WHERE budget_transaction_id = ?
           AND removed = 0
           AND id <> ?'
    );
    $linkedStmt->execute([$budgetTransactionId, $plaidTransactionRowId]);
    if ((int)$linkedStmt->fetchColumn() > 0) {
        throw new RuntimeException('Budget transaction is already linked to another Plaid transaction.');
    }

    $previousBudgetTransactionId = (int)($plaid['budget_transaction_id'] ?? 0);
    $deletedDuplicateId = null;
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE plaid_transactions
             SET budget_transaction_id = :budget_transaction_id,
                 match_method = "manual_merge",
                 matched_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $update->execute([
            ':budget_transaction_id' => $budgetTransactionId,
            ':id' => $plaidTransactionRowId,
        ]);

        if ($previousBudgetTransactionId > 0 && $previousBudgetTransactionId !== $budgetTransactionId) {
            $oldStmt = $pdo->prepare(
                'SELECT id, fm_pk
                 FROM transactions
                 WHERE id = ? AND owner = ?
                 LIMIT 1'
            );
            $oldStmt->execute([$previousBudgetTransactionId, $owner]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            $oldFmPk = trim((string)($old['fm_pk'] ?? ''));
            if (is_array($old) && str_starts_with($oldFmPk, 'plaid:')) {
                $refStmt = $pdo->prepare('SELECT COUNT(*) FROM plaid_transactions WHERE budget_transaction_id = ?');
                $refStmt->execute([$previousBudgetTransactionId]);
                if ((int)$refStmt->fetchColumn() === 0) {
                    $delete = $pdo->prepare('DELETE FROM transactions WHERE id = ? AND owner = ? LIMIT 1');
                    $delete->execute([$previousBudgetTransactionId, $owner]);
                    $deletedDuplicateId = $previousBudgetTransactionId;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'plaid_transaction_id' => $plaidTransactionRowId,
        'budget_transaction_id' => $budgetTransactionId,
        'deleted_duplicate_id' => $deletedDuplicateId,
    ];
}

function plaid_delete_unmatched_transaction(PDO $pdo, string $owner, int $plaidTransactionRowId): array
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    if ($plaidTransactionRowId <= 0) {
        throw new RuntimeException('Missing Plaid transaction.');
    }

    $deletedBudgetTransactionId = null;
    $pdo->beginTransaction();
    try {
        $plaidStmt = $pdo->prepare(
            'SELECT pt.id, pt.budget_transaction_id, pt.match_method,
                    pt.plaid_transaction_id,
                    pi.owner
             FROM plaid_transactions pt
             JOIN plaid_items pi ON pi.id = pt.plaid_item_id
             WHERE pt.id = ?
               AND pi.owner = ?
               AND pt.removed = 0
             LIMIT 1
             FOR UPDATE'
        );
        $plaidStmt->execute([$plaidTransactionRowId, $owner]);
        $plaid = $plaidStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($plaid)) {
            throw new RuntimeException('Plaid transaction not found.');
        }

        $budgetTransactionId = (int)($plaid['budget_transaction_id'] ?? 0);
        $matchMethod = (string)($plaid['match_method'] ?? '');
        if ($budgetTransactionId > 0) {
            if ($matchMethod !== 'created') {
                throw new RuntimeException('Only unmatched Plaid transactions can be deleted here.');
            }

            $txStmt = $pdo->prepare(
                'SELECT id, fm_pk, status
                 FROM transactions
                 WHERE id = ?
                   AND owner = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $txStmt->execute([$budgetTransactionId, $owner]);
            $budgetRow = $txStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($budgetRow)) {
                $fmPk = trim((string)($budgetRow['fm_pk'] ?? ''));
                $status = $budgetRow['status'] ?? null;
                $statusNorm = ($status === null || $status === '') ? 0 : (int)$status;
                if (!str_starts_with($fmPk, 'plaid:') || $statusNorm !== 0) {
                    throw new RuntimeException('Only scheduled Plaid-created transactions can be deleted here.');
                }

                $deleteBudget = $pdo->prepare('DELETE FROM transactions WHERE id = ? AND owner = ? LIMIT 1');
                $deleteBudget->execute([$budgetTransactionId, $owner]);
                if ($deleteBudget->rowCount() !== 1) {
                    throw new RuntimeException('Unable to delete the Plaid-created scheduled transaction.');
                }
                $deletedBudgetTransactionId = $budgetTransactionId;
            }
        }

        $update = $pdo->prepare(
            'UPDATE plaid_transactions
             SET removed = 1,
                 budget_transaction_id = NULL,
                 match_method = "manual_delete",
                 matched_at = NULL
             WHERE id = ?'
        );
        $update->execute([$plaidTransactionRowId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'plaid_transaction_id' => $plaidTransactionRowId,
        'deleted_budget_transaction_id' => $deletedBudgetTransactionId,
    ];
}

function plaid_match_method_was_linked(?string $matchMethod): bool
{
    $matchMethod = strtolower(trim((string)$matchMethod));
    return $matchMethod === 'created'
        || $matchMethod === 'manual_merge'
        || str_starts_with($matchMethod, 'amount_');
}

function plaid_mark_budget_transaction_deleted(PDO $pdo, string $owner, int $budgetTransactionId): int
{
    if ($budgetTransactionId <= 0) {
        return 0;
    }
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    $stmt = $pdo->prepare(
        'UPDATE plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         SET pt.removed = 1,
             pt.budget_transaction_id = NULL,
             pt.match_method = "manual_delete",
             pt.matched_at = NULL
         WHERE pt.budget_transaction_id = ?
           AND pi.owner = ?
           AND pt.removed = 0'
    );
    $stmt->execute([$budgetTransactionId, $owner]);
    return $stmt->rowCount();
}

function plaid_store_item(PDO $pdo, string $owner, string $environment, array $exchange, array $metadata = []): int
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    $environment = plaid_normalize_environment($environment);
    $itemId = trim((string)($exchange['item_id'] ?? ''));
    $accessToken = trim((string)($exchange['access_token'] ?? ''));
    if ($itemId === '' || $accessToken === '') {
        throw new RuntimeException('Plaid token exchange did not return an Item.');
    }
    $institution = is_array($metadata['institution'] ?? null) ? $metadata['institution'] : [];
    $institutionId = trim((string)($institution['institution_id'] ?? ''));
    $institutionName = trim((string)($institution['name'] ?? ''));
    $linkSessionId = trim((string)($metadata['link_session_id'] ?? ''));

    $stmt = $pdo->prepare(
        'INSERT INTO plaid_items
            (owner, environment, item_id, access_token, institution_id, institution_name, link_session_id, sync_status, last_error)
         VALUES
            (:owner, :environment, :item_id, :access_token, :institution_id, :institution_name, :link_session_id, "active", NULL)
         ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            access_token = VALUES(access_token),
            institution_id = COALESCE(NULLIF(VALUES(institution_id), ""), institution_id),
            institution_name = COALESCE(NULLIF(VALUES(institution_name), ""), institution_name),
            link_session_id = COALESCE(NULLIF(VALUES(link_session_id), ""), link_session_id),
            sync_status = "active",
            last_error = NULL'
    );
    $stmt->execute([
        ':owner' => $owner,
        ':environment' => $environment,
        ':item_id' => $itemId,
        ':access_token' => $accessToken,
        ':institution_id' => $institutionId !== '' ? $institutionId : null,
        ':institution_name' => $institutionName !== '' ? $institutionName : null,
        ':link_session_id' => $linkSessionId !== '' ? $linkSessionId : null,
    ]);
    return (int)$pdo->lastInsertId();
}

function plaid_upsert_accounts(PDO $pdo, int $plaidItemId, string $institutionName, array $accounts): int
{
    unset($institutionName);
    $count = 0;
    $stmt = $pdo->prepare(
        'INSERT INTO plaid_accounts
            (plaid_item_id, plaid_account_id, local_account_id, name, official_name, mask, type, subtype,
             current_balance, available_balance, iso_currency_code)
         VALUES
            (:plaid_item_id, :plaid_account_id, :local_account_id, :name, :official_name, :mask, :type, :subtype,
             :current_balance, :available_balance, :iso_currency_code)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            official_name = VALUES(official_name),
            mask = VALUES(mask),
            type = VALUES(type),
            subtype = VALUES(subtype),
            current_balance = VALUES(current_balance),
            available_balance = VALUES(available_balance),
            iso_currency_code = VALUES(iso_currency_code)'
    );
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountId = trim((string)($account['account_id'] ?? ''));
        if ($accountId === '') {
            continue;
        }
        $balances = is_array($account['balances'] ?? null) ? $account['balances'] : [];
        $stmt->execute([
            ':plaid_item_id' => $plaidItemId,
            ':plaid_account_id' => $accountId,
            ':local_account_id' => null,
            ':name' => trim((string)($account['name'] ?? '')) ?: null,
            ':official_name' => trim((string)($account['official_name'] ?? '')) ?: null,
            ':mask' => trim((string)($account['mask'] ?? '')) ?: null,
            ':type' => trim((string)($account['type'] ?? '')) ?: null,
            ':subtype' => trim((string)($account['subtype'] ?? '')) ?: null,
            ':current_balance' => is_numeric($balances['current'] ?? null) ? (string)$balances['current'] : null,
            ':available_balance' => is_numeric($balances['available'] ?? null) ? (string)$balances['available'] : null,
            ':iso_currency_code' => trim((string)($balances['iso_currency_code'] ?? '')) ?: null,
        ]);
        $count++;
    }
    return $count;
}

function plaid_update_account_mapping(PDO $pdo, string $owner, int $plaidAccountRowId, ?int $localAccountId): array
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    $existing = plaid_fetch_account_mapping_row($pdo, $owner, $plaidAccountRowId);
    if (!$existing) {
        throw new RuntimeException('Plaid account not found.');
    }

    $localAccountId = $localAccountId !== null && $localAccountId > 0 ? $localAccountId : null;
    if ($localAccountId !== null) {
        $check = $pdo->prepare('SELECT id FROM accounts WHERE id = ? LIMIT 1');
        $check->execute([$localAccountId]);
        if ((int)($check->fetchColumn() ?: 0) <= 0) {
            throw new RuntimeException('Budget account not found.');
        }
    }

    $clearedAccountIds = [];
    $pdo->beginTransaction();
    try {
        if ($localAccountId !== null) {
            $cleared = $pdo->prepare(
                'SELECT pa.id
                 FROM plaid_accounts pa
                 JOIN plaid_items pi ON pi.id = pa.plaid_item_id
                 WHERE pi.owner = ? AND pa.local_account_id = ? AND pa.id <> ?'
            );
            $cleared->execute([$owner, $localAccountId, $plaidAccountRowId]);
            $clearedAccountIds = array_map('intval', $cleared->fetchAll(PDO::FETCH_COLUMN) ?: []);

            $clear = $pdo->prepare(
                'UPDATE plaid_accounts pa
                 JOIN plaid_items pi ON pi.id = pa.plaid_item_id
                 SET pa.local_account_id = NULL
                 WHERE pi.owner = ? AND pa.local_account_id = ? AND pa.id <> ?'
            );
            $clear->execute([$owner, $localAccountId, $plaidAccountRowId]);
        }

        $update = $pdo->prepare(
            'UPDATE plaid_accounts pa
             JOIN plaid_items pi ON pi.id = pa.plaid_item_id
             SET pa.local_account_id = :local_account_id
             WHERE pa.id = :plaid_account_id AND pi.owner = :owner'
        );
        $update->execute([
            ':local_account_id' => $localAccountId,
            ':plaid_account_id' => $plaidAccountRowId,
            ':owner' => $owner,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'account' => plaid_fetch_account_mapping_row($pdo, $owner, $plaidAccountRowId),
        'cleared_account_ids' => $clearedAccountIds,
    ];
}

function plaid_fetch_item_row(PDO $pdo, int $id, ?string $owner = null): ?array
{
    $sql = 'SELECT * FROM plaid_items WHERE id = ?';
    $params = [$id];
    if ($owner !== null) {
        $sql .= ' AND owner = ?';
        $params[] = budget_canonical_user($owner);
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function plaid_local_account_for_transaction(PDO $pdo, int $plaidItemId, string $plaidAccountId): ?int
{
    $stmt = $pdo->prepare('SELECT local_account_id FROM plaid_accounts WHERE plaid_item_id = ? AND plaid_account_id = ? LIMIT 1');
    $stmt->execute([$plaidItemId, $plaidAccountId]);
    $id = $stmt->fetchColumn();
    return $id !== false && (int)$id > 0 ? (int)$id : null;
}

function plaid_transaction_budget_amount(array $tx): string
{
    $plaidAmount = is_numeric($tx['amount'] ?? null) ? (float)$tx['amount'] : 0.0;
    return number_format(-1 * $plaidAmount, 2, '.', '');
}

function plaid_budget_amount_from_plaid_amount($amount): ?string
{
    if (!is_numeric($amount)) {
        return null;
    }
    return number_format(-1 * (float)$amount, 2, '.', '');
}

function plaid_transaction_description(array $tx): string
{
    $merchant = trim((string)($tx['merchant_name'] ?? ''));
    if ($merchant !== '') {
        return $merchant;
    }
    $name = trim((string)($tx['name'] ?? ''));
    return $name !== '' ? $name : 'Plaid transaction';
}

function plaid_transaction_is_transfer_like(?string $rawJson, string $name = '', string $merchantName = ''): bool
{
    $rawJson = trim((string)$rawJson);
    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) {
            $pfc = $decoded['personal_finance_category'] ?? null;
            if (is_array($pfc)) {
                $primary = strtoupper(trim((string)($pfc['primary'] ?? '')));
                $detailed = strtoupper(trim((string)($pfc['detailed'] ?? '')));
                if (str_starts_with($primary, 'TRANSFER') || str_starts_with($detailed, 'TRANSFER')) {
                    return true;
                }
            }
        }
    }

    $text = plaid_text_key($name . ' ' . $merchantName);
    return str_contains($text, 'person pay')
        || (str_contains($text, 'payment center') && (str_contains($text, 'withdrawal') || str_contains($text, 'deposit')));
}

function plaid_reconcile_transfer_pair_for_transaction(PDO $pdo, string $owner, int $plaidTransactionRowId): bool
{
    if ($plaidTransactionRowId <= 0) {
        return false;
    }
    $owner = budget_canonical_user($owner);

    $stmt = $pdo->prepare(
        'SELECT pt.id, pt.amount, pt.date, pt.authorized_date, pt.name, pt.merchant_name, pt.raw_json,
                pt.budget_transaction_id, pt.match_method,
                pa.local_account_id,
                a.name AS local_account_name,
                t.fm_pk AS budget_fm_pk
         FROM plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         JOIN plaid_accounts pa
           ON pa.plaid_item_id = pt.plaid_item_id
          AND pa.plaid_account_id = pt.plaid_account_id
         LEFT JOIN accounts a ON a.id = pa.local_account_id
         LEFT JOIN transactions t ON t.id = pt.budget_transaction_id AND t.owner = pi.owner
         WHERE pt.id = ?
           AND pi.owner = ?
           AND pt.removed = 0
         LIMIT 1'
    );
    $stmt->execute([$plaidTransactionRowId, $owner]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current)) {
        return false;
    }

    $budgetTransactionId = (int)($current['budget_transaction_id'] ?? 0);
    $localAccountId = (int)($current['local_account_id'] ?? 0);
    $localAccountName = trim((string)($current['local_account_name'] ?? ''));
    $matchMethod = trim((string)($current['match_method'] ?? ''));
    $budgetFmPk = trim((string)($current['budget_fm_pk'] ?? ''));
    if ($budgetTransactionId <= 0
        || $localAccountId <= 0
        || $localAccountName === ''
        || $matchMethod !== 'created'
        || !str_starts_with($budgetFmPk, 'plaid:')
        || !is_numeric($current['amount'] ?? null)
        || !plaid_transaction_is_transfer_like(
            (string)($current['raw_json'] ?? ''),
            (string)($current['name'] ?? ''),
            (string)($current['merchant_name'] ?? '')
        )) {
        return false;
    }

    $date = plaid_valid_date((string)($current['date'] ?? ''))
        ?? plaid_valid_date((string)($current['authorized_date'] ?? ''));
    if ($date === null) {
        return false;
    }
    [$startDate, $endDate] = plaid_date_range($date, 3);
    $amount = (string)$current['amount'];

    $candidateStmt = $pdo->prepare(
        'SELECT pt.id, pt.amount, pt.date, pt.authorized_date, pt.name, pt.merchant_name, pt.raw_json,
                pt.budget_transaction_id,
                pa.local_account_id,
                a.name AS local_account_name,
                t.fm_pk AS budget_fm_pk
         FROM plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         JOIN plaid_accounts pa
           ON pa.plaid_item_id = pt.plaid_item_id
          AND pa.plaid_account_id = pt.plaid_account_id
         JOIN accounts a ON a.id = pa.local_account_id
         JOIN transactions t ON t.id = pt.budget_transaction_id AND t.owner = pi.owner
         WHERE pi.owner = :owner
           AND pt.removed = 0
           AND pt.id <> :id
           AND pt.match_method = "created"
           AND pt.budget_transaction_id IS NOT NULL
           AND pa.local_account_id IS NOT NULL
           AND pa.local_account_id <> :local_account_id
           AND t.fm_pk LIKE "plaid:%"
           AND ABS(pt.amount + :amount) < 0.01
           AND COALESCE(pt.date, pt.authorized_date) BETWEEN :start_date AND :end_date
         ORDER BY ABS(DATEDIFF(COALESCE(pt.date, pt.authorized_date), :target_date)) ASC, pt.id DESC
         LIMIT 10'
    );
    $candidateStmt->execute([
        ':owner' => $owner,
        ':id' => $plaidTransactionRowId,
        ':local_account_id' => $localAccountId,
        ':amount' => $amount,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':target_date' => $date,
    ]);
    $candidates = array_values(array_filter(
        $candidateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        static function (array $candidate): bool {
            return trim((string)($candidate['local_account_name'] ?? '')) !== ''
                && plaid_transaction_is_transfer_like(
                    (string)($candidate['raw_json'] ?? ''),
                    (string)($candidate['name'] ?? ''),
                    (string)($candidate['merchant_name'] ?? '')
                );
        }
    ));
    if (count($candidates) !== 1) {
        return false;
    }

    $counterpart = $candidates[0];
    $counterpartBudgetTransactionId = (int)($counterpart['budget_transaction_id'] ?? 0);
    $counterpartAccountName = trim((string)($counterpart['local_account_name'] ?? ''));
    if ($counterpartBudgetTransactionId <= 0 || $counterpartAccountName === '') {
        return false;
    }

    $update = $pdo->prepare(
        'UPDATE transactions
         SET description = :description,
             updated_at_source = NOW()
         WHERE id = :id
           AND owner = :owner
           AND (description IS NULL OR description <> :description_match)'
    );
    $update->execute([
        ':description' => $counterpartAccountName,
        ':description_match' => $counterpartAccountName,
        ':id' => $budgetTransactionId,
        ':owner' => $owner,
    ]);
    $changed = $update->rowCount() > 0;
    $update->execute([
        ':description' => $localAccountName,
        ':description_match' => $localAccountName,
        ':id' => $counterpartBudgetTransactionId,
        ':owner' => $owner,
    ]);

    return $changed || $update->rowCount() > 0;
}

function plaid_reconcile_recent_transfer_pairs(PDO $pdo, string $owner, int $days = 30): int
{
    $owner = budget_canonical_user($owner);
    $days = max(1, min(90, $days));
    $relativeStart = gmdate('Y-m-d', strtotime('-' . $days . ' days') ?: time());
    $cutoff = max($relativeStart, plaid_auto_create_cutoff_date());

    $stmt = $pdo->prepare(
        'SELECT pt.id
         FROM plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         JOIN plaid_accounts pa
           ON pa.plaid_item_id = pt.plaid_item_id
          AND pa.plaid_account_id = pt.plaid_account_id
         WHERE pi.owner = :owner
           AND pt.removed = 0
           AND pt.match_method = "created"
           AND pt.budget_transaction_id IS NOT NULL
           AND pa.local_account_id IS NOT NULL
           AND COALESCE(pt.date, pt.authorized_date) >= :cutoff
         ORDER BY COALESCE(pt.date, pt.authorized_date) DESC, pt.id DESC
         LIMIT 500'
    );
    $stmt->execute([
        ':owner' => $owner,
        ':cutoff' => $cutoff,
    ]);

    $changed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
        if (plaid_reconcile_transfer_pair_for_transaction($pdo, $owner, (int)$id)) {
            $changed++;
        }
    }
    return $changed;
}

function plaid_reprocess_unlinked_transactions(PDO $pdo, string $owner, ?int $plaidItemId = null, int $days = 30): array
{
    $owner = budget_canonical_user($owner);
    $days = max(1, min(90, $days));
    $relativeStart = gmdate('Y-m-d', strtotime('-' . $days . ' days') ?: time());
    $cutoff = max($relativeStart, plaid_auto_create_cutoff_date());
    $summary = [
        'matched' => 0,
        'created' => 0,
        'unmatched' => 0,
        'unmapped' => 0,
        'ignored' => 0,
    ];

    $sql = 'SELECT pt.id, pt.plaid_item_id, pt.plaid_account_id, pt.plaid_transaction_id,
                   pt.date, pt.authorized_date, pt.amount, pt.name, pt.merchant_name,
                   pt.match_method, pa.local_account_id
            FROM plaid_transactions pt
            JOIN plaid_items pi ON pi.id = pt.plaid_item_id
            JOIN plaid_accounts pa
              ON pa.plaid_item_id = pt.plaid_item_id
             AND pa.plaid_account_id = pt.plaid_account_id
            WHERE pi.owner = :owner
              AND pt.removed = 0
              AND pt.budget_transaction_id IS NULL
              AND COALESCE(pt.date, pt.authorized_date) >= :cutoff';
    $params = [
        ':owner' => $owner,
        ':cutoff' => $cutoff,
    ];
    if ($plaidItemId !== null && $plaidItemId > 0) {
        $sql .= ' AND pt.plaid_item_id = :plaid_item_id';
        $params[':plaid_item_id'] = $plaidItemId;
    }
    $sql .= ' ORDER BY COALESCE(pt.date, pt.authorized_date) DESC, pt.id DESC LIMIT 500';

    $rows = $pdo->prepare($sql);
    $rows->execute($params);
    $update = $pdo->prepare(
        'UPDATE plaid_transactions
         SET budget_transaction_id = :budget_transaction_id,
             match_method = :match_method,
             matched_at = :matched_at
         WHERE id = :id'
    );

    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $previousMatchMethod = strtolower(trim((string)($row['match_method'] ?? '')));
        if ($previousMatchMethod === 'manual_delete' || plaid_match_method_was_linked($previousMatchMethod)) {
            $update->execute([
                ':budget_transaction_id' => null,
                ':match_method' => 'manual_delete',
                ':matched_at' => null,
                ':id' => (int)$row['id'],
            ]);
            $remove = $pdo->prepare('UPDATE plaid_transactions SET removed = 1 WHERE id = ?');
            $remove->execute([(int)$row['id']]);
            $summary['ignored']++;
            continue;
        }

        $localAccountId = (int)($row['local_account_id'] ?? 0);
        $budgetAmount = plaid_budget_amount_from_plaid_amount($row['amount'] ?? null);
        $description = trim((string)($row['merchant_name'] ?? ''));
        if ($description === '') {
            $description = trim((string)($row['name'] ?? ''));
        }
        $date = plaid_valid_date((string)($row['date'] ?? ''));
        $authorizedDate = plaid_valid_date((string)($row['authorized_date'] ?? ''));
        $match = plaid_find_budget_match(
            $pdo,
            $owner,
            (int)$row['plaid_item_id'],
            (string)$row['plaid_transaction_id'],
            $localAccountId > 0 ? $localAccountId : null,
            $date,
            $authorizedDate,
            $budgetAmount,
            $description
        );
        $budgetTransactionId = !empty($match['budget_transaction_id']) ? (int)$match['budget_transaction_id'] : null;
        $matchMethod = (string)($match['match_method'] ?? 'no_match');
        $created = false;
        if ($budgetTransactionId === null
            && $budgetAmount !== null
            && $localAccountId > 0
            && plaid_should_auto_create_transaction(true, $date, $authorizedDate)
            && in_array($matchMethod, ['no_match', 'ambiguous'], true)) {
            $createdBudgetTransactionId = plaid_create_or_update_scheduled_transaction(
                $pdo,
                $owner,
                $localAccountId,
                (string)$row['plaid_transaction_id'],
                $date,
                $authorizedDate,
                $budgetAmount,
                $description
            );
            if ($createdBudgetTransactionId !== null) {
                $budgetTransactionId = $createdBudgetTransactionId;
                $matchMethod = 'created';
                $created = true;
            }
        }

        $update->execute([
            ':budget_transaction_id' => $budgetTransactionId,
            ':match_method' => $matchMethod,
            ':matched_at' => $budgetTransactionId !== null ? gmdate('Y-m-d H:i:s') : null,
            ':id' => (int)$row['id'],
        ]);

        if ($budgetTransactionId !== null) {
            plaid_reconcile_transfer_pair_for_transaction($pdo, $owner, (int)$row['id']);
        }
        if ($created) {
            $summary['created']++;
        } elseif ($budgetTransactionId !== null) {
            $summary['matched']++;
        } elseif ($matchMethod === 'unmapped') {
            $summary['unmapped']++;
        } else {
            $summary['unmatched']++;
        }
    }

    return $summary;
}

function plaid_valid_date(?string $date): ?string
{
    $date = trim((string)$date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return null;
    }
    return $date;
}

function plaid_auto_create_cutoff_date(): string
{
    return plaid_valid_date(getenv('BUDGET_PLAID_AUTO_CREATE_AFTER') ?: '') ?? '2026-05-01';
}

function plaid_should_auto_create_transaction(bool $autoCreate, ?string $date, ?string $authorizedDate): bool
{
    if (!$autoCreate) {
        return false;
    }
    $transactionDate = plaid_valid_date($date) ?? plaid_valid_date($authorizedDate);
    return $transactionDate !== null && $transactionDate >= plaid_auto_create_cutoff_date();
}

function plaid_text_key(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return preg_replace('/\s+/', ' ', trim($value)) ?? '';
}

function plaid_description_matches(string $plaidDescription, string $budgetDescription): bool
{
    $plaidKey = plaid_text_key($plaidDescription);
    $budgetKey = plaid_text_key($budgetDescription);
    if ($plaidKey === '' || $budgetKey === '') {
        return false;
    }
    if ($plaidKey === $budgetKey) {
        return true;
    }
    return strlen($plaidKey) >= 5 && strlen($budgetKey) >= 5
        && (str_contains($plaidKey, $budgetKey) || str_contains($budgetKey, $plaidKey));
}

function plaid_date_range(string $date, int $days): array
{
    $dt = new DateTimeImmutable($date);
    return [
        $dt->modify('-' . $days . ' days')->format('Y-m-d'),
        $dt->modify('+' . $days . ' days')->format('Y-m-d'),
    ];
}

function plaid_find_unique_budget_match(
    PDO $pdo,
    string $owner,
    int $plaidItemId,
    string $plaidTransactionId,
    int $localAccountId,
    string $budgetAmount,
    string $startDate,
    string $endDate,
    string $description
): array {
    $stmt = $pdo->prepare(
        'SELECT t.id, t.`date`, t.description
         FROM transactions t
         LEFT JOIN plaid_transactions linked
           ON linked.budget_transaction_id = t.id
          AND linked.removed = 0
          AND NOT (linked.plaid_item_id = :plaid_item_id AND linked.plaid_transaction_id = :plaid_transaction_id)
         WHERE t.owner = :owner
           AND t.account_id = :account_id
           AND t.amount = :amount
           AND t.`date` BETWEEN :start_date AND :end_date
           AND linked.id IS NULL
         ORDER BY ABS(DATEDIFF(t.`date`, :order_date)), t.id DESC
         LIMIT 10'
    );
    $stmt->execute([
        ':plaid_item_id' => $plaidItemId,
        ':plaid_transaction_id' => $plaidTransactionId,
        ':owner' => $owner,
        ':account_id' => $localAccountId,
        ':amount' => $budgetAmount,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':order_date' => $startDate,
    ]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($candidates) === 1) {
        return ['budget_transaction_id' => (int)$candidates[0]['id'], 'ambiguous' => false];
    }
    if (count($candidates) > 1) {
        $textMatches = array_values(array_filter(
            $candidates,
            static fn(array $row): bool => plaid_description_matches($description, (string)($row['description'] ?? ''))
        ));
        if (count($textMatches) === 1) {
            return ['budget_transaction_id' => (int)$textMatches[0]['id'], 'ambiguous' => false];
        }
        return ['budget_transaction_id' => null, 'ambiguous' => true];
    }
    return ['budget_transaction_id' => null, 'ambiguous' => false];
}

function plaid_find_budget_match(
    PDO $pdo,
    string $owner,
    int $plaidItemId,
    string $plaidTransactionId,
    ?int $localAccountId,
    ?string $date,
    ?string $authorizedDate,
    ?string $budgetAmount,
    string $description
): array {
    if ($localAccountId === null || $localAccountId <= 0) {
        return ['budget_transaction_id' => null, 'match_method' => 'unmapped'];
    }
    if ($budgetAmount === null || !is_numeric($budgetAmount)) {
        return ['budget_transaction_id' => null, 'match_method' => 'invalid_amount'];
    }

    $ambiguous = false;
    $date = plaid_valid_date($date);
    $authorizedDate = plaid_valid_date($authorizedDate);
    $exactDates = [];
    if ($date !== null) {
        $exactDates['amount_date'] = $date;
    }
    if ($authorizedDate !== null && $authorizedDate !== $date) {
        $exactDates['amount_authorized_date'] = $authorizedDate;
    }

    foreach ($exactDates as $method => $candidateDate) {
        $match = plaid_find_unique_budget_match(
            $pdo,
            $owner,
            $plaidItemId,
            $plaidTransactionId,
            $localAccountId,
            $budgetAmount,
            $candidateDate,
            $candidateDate,
            $description
        );
        if (!empty($match['budget_transaction_id'])) {
            return ['budget_transaction_id' => (int)$match['budget_transaction_id'], 'match_method' => $method];
        }
        $ambiguous = $ambiguous || !empty($match['ambiguous']);
    }

    if ($date !== null) {
        [$startDate, $endDate] = plaid_date_range($date, 3);
        $match = plaid_find_unique_budget_match(
            $pdo,
            $owner,
            $plaidItemId,
            $plaidTransactionId,
            $localAccountId,
            $budgetAmount,
            $startDate,
            $endDate,
            $description
        );
        if (!empty($match['budget_transaction_id'])) {
            return ['budget_transaction_id' => (int)$match['budget_transaction_id'], 'match_method' => 'amount_date_window'];
        }
        $ambiguous = $ambiguous || !empty($match['ambiguous']);
    }

    return ['budget_transaction_id' => null, 'match_method' => $ambiguous ? 'ambiguous' : 'no_match'];
}

function plaid_budget_fm_pk(string $plaidTransactionId): string
{
    return 'plaid:' . substr(sha1($plaidTransactionId), 0, 32);
}

function plaid_create_or_update_scheduled_transaction(
    PDO $pdo,
    string $owner,
    int $localAccountId,
    string $plaidTransactionId,
    ?string $date,
    ?string $authorizedDate,
    string $budgetAmount,
    string $description,
    ?int $existingBudgetTransactionId = null
): ?int {
    if ($localAccountId <= 0 || $plaidTransactionId === '' || !is_numeric($budgetAmount)) {
        return null;
    }
    $owner = budget_canonical_user($owner);
    $fmPk = plaid_budget_fm_pk($plaidTransactionId);
    $txDate = $date ?? $authorizedDate ?? gmdate('Y-m-d');
    $txDate = plaid_valid_date($txDate) ?? gmdate('Y-m-d');
    $description = trim($description) !== '' ? trim($description) : 'Plaid transaction';

    $candidate = null;
    if ($existingBudgetTransactionId !== null && $existingBudgetTransactionId > 0) {
        $stmt = $pdo->prepare('SELECT id, fm_pk, status FROM transactions WHERE id = ? AND owner = ? LIMIT 1');
        $stmt->execute([$existingBudgetTransactionId, $owner]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && str_starts_with(trim((string)($row['fm_pk'] ?? '')), 'plaid:')) {
            $candidate = $row;
        }
    }
    if ($candidate === null) {
        $stmt = $pdo->prepare('SELECT id, fm_pk, status FROM transactions WHERE fm_pk = ? AND owner = ? LIMIT 1');
        $stmt->execute([$fmPk, $owner]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $candidate = $row;
        }
    }

    if ($candidate !== null) {
        $id = (int)($candidate['id'] ?? 0);
        $status = $candidate['status'];
        $statusNorm = ($status === null || $status === '') ? 0 : (int)$status;
        if ($id > 0 && $statusNorm === 0) {
            $update = $pdo->prepare(
                'UPDATE transactions
                 SET fm_pk = :fm_pk,
                     account_id = :account_id,
                     `date` = :date,
                     amount = :amount,
                     description = :description,
                     check_no = NULL,
                     posted = 0,
                     status = 0,
                     updated_at_source = NOW()
                 WHERE id = :id AND owner = :owner'
            );
            $update->execute([
                ':fm_pk' => $fmPk,
                ':account_id' => $localAccountId,
                ':date' => $txDate,
                ':amount' => $budgetAmount,
                ':description' => $description,
                ':id' => $id,
                ':owner' => $owner,
            ]);
        }
        return $id > 0 ? $id : null;
    }

    $insert = $pdo->prepare(
        'INSERT INTO transactions
            (fm_pk, account_id, `date`, amount, description, check_no, posted, status, owner, created_at_source, updated_at_source)
         VALUES
            (:fm_pk, :account_id, :date, :amount, :description, NULL, 0, 0, :owner, NOW(), NOW())'
    );
    $insert->execute([
        ':fm_pk' => $fmPk,
        ':account_id' => $localAccountId,
        ':date' => $txDate,
        ':amount' => $budgetAmount,
        ':description' => $description,
        ':owner' => $owner,
    ]);
    $id = (int)$pdo->lastInsertId();
    return $id > 0 ? $id : null;
}

function plaid_upsert_transaction(PDO $pdo, array $item, array $tx, bool $autoCreate = false): array
{
    $plaidItemId = (int)$item['id'];
    $owner = budget_canonical_user((string)$item['owner']);
    $transactionId = trim((string)($tx['transaction_id'] ?? ''));
    $plaidAccountId = trim((string)($tx['account_id'] ?? ''));
    if ($transactionId === '' || $plaidAccountId === '') {
        return ['stored' => false, 'matched' => false, 'created' => false, 'unmatched' => false, 'unmapped' => false, 'match_method' => 'skipped'];
    }
    $localAccountId = plaid_local_account_for_transaction($pdo, $plaidItemId, $plaidAccountId);
    $date = plaid_valid_date(trim((string)($tx['date'] ?? '')));
    $authorizedDate = plaid_valid_date(trim((string)($tx['authorized_date'] ?? '')));
    $pending = !empty($tx['pending']) ? 1 : 0;
    $budgetAmount = plaid_transaction_budget_amount($tx);
    $description = plaid_transaction_description($tx);
    $rawJson = json_encode($tx, JSON_UNESCAPED_SLASHES);

    $existingStmt = $pdo->prepare(
        'SELECT id, budget_transaction_id, match_method, removed
         FROM plaid_transactions
         WHERE plaid_item_id = ? AND plaid_transaction_id = ?
         LIMIT 1'
    );
    $existingStmt->execute([$plaidItemId, $transactionId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    $existingBudgetTransactionId = is_array($existing) && (int)($existing['budget_transaction_id'] ?? 0) > 0
        ? (int)$existing['budget_transaction_id']
        : null;
    $existingMatchMethod = is_array($existing) ? (string)($existing['match_method'] ?? '') : '';
    $existingRemoved = is_array($existing) ? (int)($existing['removed'] ?? 0) : 0;

    if ($existingRemoved === 1 && $existingMatchMethod === 'manual_delete') {
        $preserveDeleted = $pdo->prepare(
            'UPDATE plaid_transactions
             SET plaid_account_id = :plaid_account_id,
                 pending_transaction_id = :pending_transaction_id,
                 date = :date,
                 authorized_date = :authorized_date,
                 amount = :amount,
                 name = :name,
                 merchant_name = :merchant_name,
                 pending = :pending,
                 raw_json = :raw_json
             WHERE id = :id'
        );
        $preserveDeleted->execute([
            ':plaid_account_id' => $plaidAccountId,
            ':pending_transaction_id' => trim((string)($tx['pending_transaction_id'] ?? '')) ?: null,
            ':date' => $date,
            ':authorized_date' => $authorizedDate,
            ':amount' => is_numeric($tx['amount'] ?? null) ? (string)$tx['amount'] : null,
            ':name' => trim((string)($tx['name'] ?? '')) ?: null,
            ':merchant_name' => trim((string)($tx['merchant_name'] ?? '')) ?: null,
            ':pending' => $pending,
            ':raw_json' => $rawJson !== false ? $rawJson : null,
            ':id' => (int)$existing['id'],
        ]);

        return ['stored' => false, 'matched' => false, 'created' => false, 'unmatched' => false, 'unmapped' => false, 'ignored' => true, 'match_method' => 'manual_delete'];
    }

    $created = false;
    if ($existingBudgetTransactionId !== null && in_array($existingMatchMethod, ['created', 'manual_merge'], true)) {
        $budgetTransactionId = $existingBudgetTransactionId;
        $matchMethod = $existingMatchMethod;
        if ($existingMatchMethod === 'created') {
            plaid_create_or_update_scheduled_transaction(
                $pdo,
                $owner,
                (int)($localAccountId ?? 0),
                $transactionId,
                $date,
                $authorizedDate,
                $budgetAmount,
                $description,
                $existingBudgetTransactionId
            );
            $created = true;
        }
    } else {
        $match = plaid_find_budget_match(
            $pdo,
            $owner,
            $plaidItemId,
            $transactionId,
            $localAccountId,
            $date,
            $authorizedDate,
            $budgetAmount,
            $description
        );
        $budgetTransactionId = !empty($match['budget_transaction_id']) ? (int)$match['budget_transaction_id'] : null;
        $matchMethod = (string)($match['match_method'] ?? 'no_match');

        if ($budgetTransactionId === null
            && plaid_should_auto_create_transaction($autoCreate, $date, $authorizedDate)
            && $localAccountId !== null
            && in_array($matchMethod, ['no_match', 'ambiguous'], true)) {
            $createdBudgetTransactionId = plaid_create_or_update_scheduled_transaction(
                $pdo,
                $owner,
                $localAccountId,
                $transactionId,
                $date,
                $authorizedDate,
                $budgetAmount,
                $description
            );
            if ($createdBudgetTransactionId !== null) {
                $budgetTransactionId = $createdBudgetTransactionId;
                $matchMethod = 'created';
                $created = true;
            }
        }
    }

    $map = $pdo->prepare(
        'INSERT INTO plaid_transactions
            (plaid_item_id, plaid_account_id, plaid_transaction_id, budget_transaction_id, pending_transaction_id,
             date, authorized_date, amount, name, merchant_name, pending, removed, match_method, matched_at, raw_json)
         VALUES
            (:plaid_item_id, :plaid_account_id, :plaid_transaction_id, :budget_transaction_id, :pending_transaction_id,
             :date, :authorized_date, :amount, :name, :merchant_name, :pending, 0, :match_method, :matched_at, :raw_json)
         ON DUPLICATE KEY UPDATE
            plaid_account_id = VALUES(plaid_account_id),
            budget_transaction_id = VALUES(budget_transaction_id),
            pending_transaction_id = VALUES(pending_transaction_id),
            date = VALUES(date),
            authorized_date = VALUES(authorized_date),
            amount = VALUES(amount),
            name = VALUES(name),
            merchant_name = VALUES(merchant_name),
            pending = VALUES(pending),
            removed = 0,
            match_method = VALUES(match_method),
            matched_at = VALUES(matched_at),
            raw_json = VALUES(raw_json)'
    );
    $map->execute([
        ':plaid_item_id' => $plaidItemId,
        ':plaid_account_id' => $plaidAccountId,
        ':plaid_transaction_id' => $transactionId,
        ':budget_transaction_id' => $budgetTransactionId,
        ':pending_transaction_id' => trim((string)($tx['pending_transaction_id'] ?? '')) ?: null,
        ':date' => $date,
        ':authorized_date' => $authorizedDate,
        ':amount' => is_numeric($tx['amount'] ?? null) ? (string)$tx['amount'] : null,
        ':name' => trim((string)($tx['name'] ?? '')) ?: null,
        ':merchant_name' => trim((string)($tx['merchant_name'] ?? '')) ?: null,
        ':pending' => $pending,
        ':match_method' => $matchMethod,
        ':matched_at' => $budgetTransactionId !== null ? gmdate('Y-m-d H:i:s') : null,
        ':raw_json' => $rawJson !== false ? $rawJson : null,
    ]);

    $plaidTransactionRowId = is_array($existing) ? (int)($existing['id'] ?? 0) : 0;
    if ($plaidTransactionRowId <= 0) {
        $rowStmt = $pdo->prepare(
            'SELECT id
             FROM plaid_transactions
             WHERE plaid_item_id = ?
               AND plaid_transaction_id = ?
             LIMIT 1'
        );
        $rowStmt->execute([$plaidItemId, $transactionId]);
        $plaidTransactionRowId = (int)($rowStmt->fetchColumn() ?: 0);
    }
    if ($budgetTransactionId !== null && $plaidTransactionRowId > 0) {
        plaid_reconcile_transfer_pair_for_transaction($pdo, $owner, $plaidTransactionRowId);
    }

    return [
        'stored' => true,
        'matched' => $budgetTransactionId !== null && !$created,
        'created' => $created,
        'unmatched' => $budgetTransactionId === null && !in_array($matchMethod, ['unmapped', 'skipped'], true),
        'unmapped' => $matchMethod === 'unmapped',
        'match_method' => $matchMethod,
    ];
}

function plaid_rematch_account_transactions(PDO $pdo, string $owner, int $plaidAccountRowId): array
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    $account = plaid_fetch_account_mapping_row($pdo, $owner, $plaidAccountRowId);
    if (!$account) {
        throw new RuntimeException('Plaid account not found.');
    }

    $summary = [
        'plaid_account_id' => $plaidAccountRowId,
        'matched' => 0,
        'created' => 0,
        'unmatched' => 0,
        'unmapped' => 0,
        'transfers' => 0,
    ];
    $localAccountId = (int)($account['local_account_id'] ?? 0);
    if ($localAccountId <= 0) {
        $stmt = $pdo->prepare(
            'UPDATE plaid_transactions
             SET budget_transaction_id = NULL, match_method = "unmapped", matched_at = NULL
             WHERE plaid_item_id = ? AND plaid_account_id = ? AND removed = 0'
        );
        $stmt->execute([(int)$account['plaid_item_id'], (string)$account['plaid_account_id']]);
        $summary['unmapped'] = $stmt->rowCount();
        return $summary;
    }

    $rows = $pdo->prepare(
        'SELECT id, plaid_item_id, plaid_account_id, plaid_transaction_id, date, authorized_date,
                amount, name, merchant_name
         FROM plaid_transactions
         WHERE plaid_item_id = ? AND plaid_account_id = ? AND removed = 0'
    );
    $rows->execute([(int)$account['plaid_item_id'], (string)$account['plaid_account_id']]);
    $update = $pdo->prepare(
        'UPDATE plaid_transactions
         SET budget_transaction_id = :budget_transaction_id,
             match_method = :match_method,
             matched_at = :matched_at
         WHERE id = :id'
    );
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $budgetAmount = plaid_budget_amount_from_plaid_amount($row['amount'] ?? null);
        $description = trim((string)($row['merchant_name'] ?? ''));
        if ($description === '') {
            $description = trim((string)($row['name'] ?? ''));
        }
        $date = plaid_valid_date((string)($row['date'] ?? ''));
        $authorizedDate = plaid_valid_date((string)($row['authorized_date'] ?? ''));
        $match = plaid_find_budget_match(
            $pdo,
            $owner,
            (int)$row['plaid_item_id'],
            (string)$row['plaid_transaction_id'],
            $localAccountId,
            $date,
            $authorizedDate,
            $budgetAmount,
            $description
        );
        $budgetTransactionId = !empty($match['budget_transaction_id']) ? (int)$match['budget_transaction_id'] : null;
        $matchMethod = (string)($match['match_method'] ?? 'no_match');
        $created = false;
        if ($budgetTransactionId === null
            && $budgetAmount !== null
            && plaid_should_auto_create_transaction(true, $date, $authorizedDate)
            && in_array($matchMethod, ['no_match', 'ambiguous'], true)) {
            $createdBudgetTransactionId = plaid_create_or_update_scheduled_transaction(
                $pdo,
                $owner,
                $localAccountId,
                (string)$row['plaid_transaction_id'],
                $date,
                $authorizedDate,
                $budgetAmount,
                $description
            );
            if ($createdBudgetTransactionId !== null) {
                $budgetTransactionId = $createdBudgetTransactionId;
                $matchMethod = 'created';
                $created = true;
            }
        }
        $update->execute([
            ':budget_transaction_id' => $budgetTransactionId,
            ':match_method' => $matchMethod,
            ':matched_at' => $budgetTransactionId !== null ? gmdate('Y-m-d H:i:s') : null,
            ':id' => (int)$row['id'],
        ]);
        if ($created) {
            $summary['created']++;
        } elseif ($budgetTransactionId !== null) {
            $summary['matched']++;
        } elseif ($matchMethod === 'unmapped') {
            $summary['unmapped']++;
        } else {
            $summary['unmatched']++;
        }
    }
    $summary['transfers'] = plaid_reconcile_recent_transfer_pairs($pdo, $owner);
    return $summary;
}

function plaid_remove_transaction(PDO $pdo, array $item, array $removed): bool
{
    $plaidItemId = (int)$item['id'];
    $transactionId = trim((string)($removed['transaction_id'] ?? ''));
    if ($transactionId === '') {
        return false;
    }
    $existing = $pdo->prepare(
        'SELECT pt.budget_transaction_id, pt.match_method, pi.owner
         FROM plaid_transactions pt
         JOIN plaid_items pi ON pi.id = pt.plaid_item_id
         WHERE pt.plaid_item_id = ? AND pt.plaid_transaction_id = ?
         LIMIT 1'
    );
    $existing->execute([$plaidItemId, $transactionId]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    $update = $pdo->prepare(
        'UPDATE plaid_transactions
         SET removed = 1, budget_transaction_id = NULL, match_method = "removed", matched_at = NULL
         WHERE plaid_item_id = ? AND plaid_transaction_id = ?'
    );
    $update->execute([$plaidItemId, $transactionId]);

    $budgetTransactionId = is_array($row) ? (int)($row['budget_transaction_id'] ?? 0) : 0;
    $matchMethod = is_array($row) ? (string)($row['match_method'] ?? '') : '';
    $owner = is_array($row) ? budget_canonical_user((string)($row['owner'] ?? '')) : '';
    if ($budgetTransactionId > 0 && $matchMethod === 'created' && $owner !== '') {
        $tx = $pdo->prepare('SELECT fm_pk, status FROM transactions WHERE id = ? AND owner = ? LIMIT 1');
        $tx->execute([$budgetTransactionId, $owner]);
        $budgetRow = $tx->fetch(PDO::FETCH_ASSOC);
        $fmPk = trim((string)($budgetRow['fm_pk'] ?? ''));
        $status = $budgetRow['status'] ?? null;
        $statusNorm = ($status === null || $status === '') ? 0 : (int)$status;
        if (is_array($budgetRow) && str_starts_with($fmPk, 'plaid:') && $statusNorm === 0) {
            $delete = $pdo->prepare('DELETE FROM transactions WHERE id = ? AND owner = ? LIMIT 1');
            $delete->execute([$budgetTransactionId, $owner]);
        }
    }
    return true;
}

function plaid_refresh_accounts(PDO $pdo, array $item): int
{
    $response = plaid_api_request('/accounts/get', [
        'access_token' => (string)$item['access_token'],
    ], (string)$item['environment']);
    $accounts = is_array($response['accounts'] ?? null) ? $response['accounts'] : [];
    return plaid_upsert_accounts($pdo, (int)$item['id'], (string)($item['institution_name'] ?? ''), $accounts);
}

function plaid_tally_match_result(array &$summary, array $result): void
{
    if (empty($result['stored'])) {
        return;
    }
    $summary['stored']++;
    if (!empty($result['created'])) {
        $summary['created']++;
    } elseif (!empty($result['matched'])) {
        $summary['matched']++;
    } elseif (!empty($result['unmapped'])) {
        $summary['unmapped']++;
    } else {
        $summary['unmatched']++;
    }
}

function plaid_sync_item(PDO $pdo, array $item): array
{
    plaid_ensure_tables($pdo);
    $itemId = (int)$item['id'];
    $summary = [
        'item_id' => $itemId,
        'institution_name' => $item['institution_name'] ?? '',
        'accounts' => 0,
        'stored' => 0,
        'matched' => 0,
        'created' => 0,
        'unmatched' => 0,
        'unmapped' => 0,
        'removed' => 0,
        'reprocessed' => 0,
        'ignored' => 0,
        'transfers' => 0,
        'pages' => 0,
    ];

    try {
        $summary['accounts'] = plaid_refresh_accounts($pdo, $item);
        $cursor = trim((string)($item['transactions_cursor'] ?? ''));
        $autoCreateFromPlaid = true;
        $nextCursor = $cursor;
        $hasMore = true;
        while ($hasMore) {
            $body = [
                'access_token' => (string)$item['access_token'],
                'count' => 500,
            ];
            if ($nextCursor !== '') {
                $body['cursor'] = $nextCursor;
            }
            $response = plaid_api_request('/transactions/sync', $body, (string)$item['environment'], 60);
            $added = is_array($response['added'] ?? null) ? $response['added'] : [];
            $modified = is_array($response['modified'] ?? null) ? $response['modified'] : [];
            $removed = is_array($response['removed'] ?? null) ? $response['removed'] : [];

            $pdo->beginTransaction();
            foreach ($added as $tx) {
                if (is_array($tx)) {
                    plaid_tally_match_result($summary, plaid_upsert_transaction($pdo, $item, $tx, $autoCreateFromPlaid));
                }
            }
            foreach ($modified as $tx) {
                if (is_array($tx)) {
                    plaid_tally_match_result($summary, plaid_upsert_transaction($pdo, $item, $tx, $autoCreateFromPlaid));
                }
            }
            foreach ($removed as $tx) {
                if (is_array($tx) && plaid_remove_transaction($pdo, $item, $tx)) {
                    $summary['removed']++;
                }
            }
            $pdo->commit();

            $nextCursor = trim((string)($response['next_cursor'] ?? $nextCursor));
            $hasMore = !empty($response['has_more']);
            $summary['pages']++;
            if ($summary['pages'] > 50) {
                throw new RuntimeException('Plaid sync returned too many pages.');
            }
        }

        $reprocessed = plaid_reprocess_unlinked_transactions($pdo, (string)$item['owner'], $itemId);
        $summary['matched'] += (int)$reprocessed['matched'];
        $summary['created'] += (int)$reprocessed['created'];
        $summary['unmatched'] += (int)$reprocessed['unmatched'];
        $summary['unmapped'] += (int)$reprocessed['unmapped'];
        $summary['ignored'] += (int)$reprocessed['ignored'];
        $summary['reprocessed'] = array_sum($reprocessed);
        $summary['transfers'] = plaid_reconcile_recent_transfer_pairs($pdo, (string)$item['owner']);

        $stmt = $pdo->prepare(
            'UPDATE plaid_items
             SET transactions_cursor = :cursor,
                 sync_status = "active",
                 last_synced_at = UTC_TIMESTAMP(),
                 last_error = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            ':cursor' => $nextCursor !== '' ? $nextCursor : null,
            ':id' => $itemId,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $stmt = $pdo->prepare('UPDATE plaid_items SET sync_status = "error", last_error = :error WHERE id = :id');
        $stmt->execute([
            ':error' => $e->getMessage(),
            ':id' => $itemId,
        ]);
        throw $e;
    }

    return $summary;
}

function plaid_sync_owner_items(PDO $pdo, string $owner, ?int $onlyItemId = null): array
{
    plaid_ensure_tables($pdo);
    $owner = budget_canonical_user($owner);
    $sql = 'SELECT * FROM plaid_items WHERE owner = ? AND sync_status <> "disabled"';
    $params = [$owner];
    if ($onlyItemId !== null && $onlyItemId > 0) {
        $sql .= ' AND id = ?';
        $params[] = $onlyItemId;
    }
    $sql .= ' ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $summaries = [];
    foreach ($items as $item) {
        $summaries[] = plaid_sync_item($pdo, $item);
    }
    return $summaries;
}
