<?php
declare(strict_types=1);

// One-at-a-time OData sync for Transactions with minimal schema.
// - Creates tables if not exists
// - Pages with $top=1 using @nextLink
// - Resolves Account FK via /Transactions('<pk>')/Accounts

require_once __DIR__ . '/../../util.php';

function cfg(): array {
    $cfg = require __DIR__ . '/../../config.php';
    if (!isset($cfg['db'], $cfg['fms'])) {
        throw new RuntimeException('config.php must define db and fms');
    }
    return $cfg;
}

function pdo(): PDO { return get_mysql_connection(); }

function ensure_tables(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fm_pk VARCHAR(64) NOT NULL UNIQUE,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fm_pk VARCHAR(64) NOT NULL UNIQUE,
            account_pk VARCHAR(64) DEFAULT NULL,
            account_id INT UNSIGNED DEFAULT NULL,
            `date` DATE DEFAULT NULL,
            amount DECIMAL(14,2) DEFAULT NULL,
            description TEXT,
            check_no VARCHAR(64) DEFAULT NULL,
            posted VARCHAR(32) DEFAULT NULL,
            category VARCHAR(255) DEFAULT NULL,
            tags TEXT,
            created_at_source DATETIME DEFAULT NULL,
            updated_at_source DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_date (`date`),
            KEY idx_account_id (account_id),
            CONSTRAINT fk_tx_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sync_state (
            k VARCHAR(100) PRIMARY KEY,
            v TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );
}

function state_get(PDO $pdo, string $k): ?string {
    $s = $pdo->prepare('SELECT v FROM sync_state WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string)$v;
}

function state_set(PDO $pdo, string $k, ?string $v): void {
    if ($v === null) {
        $pdo->prepare('DELETE FROM sync_state WHERE k = ?')->execute([$k]);
        return;
    }
    $pdo->prepare('INSERT INTO sync_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$k, $v]);
}

function http_get(string $url, string $user, string $pass): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('HTTP ' . $status . ' fetching ' . $url . ' err=' . $err);
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON from ' . $url);
    }
    return $data;
}

function upsert_account(PDO $pdo, array $acct): int {
    $fmPk = (string)($acct['PrimaryKey'] ?? '');
    $name = (string)($acct['Account'] ?? '');
    if ($fmPk === '') return 0;
    $pdo->prepare('INSERT INTO accounts (fm_pk, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = CURRENT_TIMESTAMP')
        ->execute([$fmPk, $name]);
    $stmt = $pdo->prepare('SELECT id FROM accounts WHERE fm_pk = ?');
    $stmt->execute([$fmPk]);
    return (int)$stmt->fetchColumn();
}

function upsert_transaction(PDO $pdo, array $tx, ?string $accountPk, ?int $accountId): void {
    $fmPk = (string)($tx['PrimaryKey'] ?? '');
    if ($fmPk === '') return;
    $date = !empty($tx['Date']) ? (string)$tx['Date'] : null;
    $amount = isset($tx['Amount']) && $tx['Amount'] !== '' ? (string)$tx['Amount'] : null;
    $desc = isset($tx['Description']) ? (string)$tx['Description'] : null;
    $check = isset($tx['Check']) ? (string)$tx['Check'] : null;
    $posted = isset($tx['Posted']) ? (string)$tx['Posted'] : null;
    $category = isset($tx['Category']) ? (string)$tx['Category'] : null;
    $tags = isset($tx['Tags']) ? (string)$tx['Tags'] : null;
    $createdSrc = isset($tx['CreationTimestamp']) ? rtrim(str_replace('T', ' ', (string)$tx['CreationTimestamp']), 'Z') : null;
    $updatedSrc = isset($tx['ModificationTimestamp']) ? rtrim(str_replace('T', ' ', (string)$tx['ModificationTimestamp']), 'Z') : null;

    $sql = "INSERT INTO transactions (fm_pk, account_pk, account_id, `date`, amount, description, check_no, posted, category, tags, created_at_source, updated_at_source)
            VALUES (:fm_pk, :account_pk, :account_id, :date, :amount, :description, :check_no, :posted, :category, :tags, :created_src, :updated_src)
            ON DUPLICATE KEY UPDATE account_pk=VALUES(account_pk), account_id=VALUES(account_id), `date`=VALUES(`date`), amount=VALUES(amount),
              description=VALUES(description), check_no=VALUES(check_no), posted=VALUES(posted), category=VALUES(category), tags=VALUES(tags),
              created_at_source=VALUES(created_at_source), updated_at_source=VALUES(updated_at_source), updated_at=CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fm_pk' => $fmPk,
        ':account_pk' => $accountPk,
        ':account_id' => $accountId,
        ':date' => $date,
        ':amount' => $amount,
        ':description' => $desc,
        ':check_no' => $check,
        ':posted' => $posted,
        ':category' => $category,
        ':tags' => $tags,
        ':created_src' => $createdSrc,
        ':updated_src' => $updatedSrc,
    ]);
}

function main(array $argv): void {
    $cfg = cfg();
    $pdo = pdo();
    ensure_tables($pdo);

    $base = rtrim((string)$cfg['fms']['base'], '/');
    $user = (string)$cfg['fms']['username'];
    $pass = (string)$cfg['fms']['password'];
    $limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 50; // process up to N records per run
    $sleepMs = isset($argv[2]) ? max(0, (int)$argv[2]) : 200; // throttle between requests (per record)
    $pageSize = isset($argv[3]) ? max(1, (int)$argv[3]) : 1; // OData $top per page

    $next = state_get($pdo, 'tx.next');
    $skipState = state_get($pdo, 'tx.skip');
    $skip = $skipState !== null ? max(0, (int)$skipState) : 0;
    $url = $next ?: ($base . '/Transactions?$top=' . $pageSize . ($skip > 0 ? ('&$skip=' . $skip) : ''));
    $processed = 0;

    while ($processed < $limit) {
        $page = http_get($url, $user, $pass);
        $values = $page['value'] ?? [];
        if (!is_array($values) || count($values) === 0) {
            state_set($pdo, 'tx.next', null);
            echo "No more records.\n";
            break;
        }
        foreach ($values as $tx) {
            if ($processed >= $limit) break;
            $txPk = (string)($tx['PrimaryKey'] ?? '');
            $acctPk = null; $acctId = null;
            if ($txPk !== '') {
                $acctUrl = $base . '/Transactions(' . rawurlencode("'{$txPk}'") . ')/' . rawurlencode('Accounts');
                try {
                    $acctResp = http_get($acctUrl, $user, $pass);
                    $acctRows = $acctResp['value'] ?? [];
                    if (is_array($acctRows) && !empty($acctRows)) {
                        $acctPk = (string)($acctRows[0]['PrimaryKey'] ?? '');
                        $acctId = upsert_account($pdo, $acctRows[0]);
                    }
                } catch (Throwable $e) {
                    // proceed without FK if fails
                }
            }
            upsert_transaction($pdo, $tx, $acctPk, $acctId);
            $processed++;
            if ($sleepMs > 0) usleep($sleepMs * 1000);
        }

        $nextLink = $page['@nextLink'] ?? $page['@odata.nextLink'] ?? null;
        if ($nextLink) {
            state_set($pdo, 'tx.skip', null);
            state_set($pdo, 'tx.next', (string)$nextLink);
            $url = (string)$nextLink;
        } else {
            // Fallback pagination via $skip
            $skip += count($values);
            state_set($pdo, 'tx.next', null);
            state_set($pdo, 'tx.skip', (string)$skip);
            $url = $base . '/Transactions?$top=' . $pageSize . '&$skip=' . $skip;
            // If fewer than requested returned, likely end-of-feed
            if (count($values) < $pageSize) {
                state_set($pdo, 'tx.next', null);
                state_set($pdo, 'tx.skip', null);
                echo "Processed {$processed} record(s). End of feed.\n";
                break;
            }
        }
    }
    echo "Processed {$processed} record(s).\n";
}

main($argv);
