<?php
declare(strict_types=1);

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
    // accounts table already managed in transactions sync; ensure it exists
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fm_pk VARCHAR(64) NULL,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_accounts_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );
    // reminders table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS reminders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            fm_pk VARCHAR(64) NOT NULL UNIQUE,
            account_id INT UNSIGNED DEFAULT NULL,
            account_name VARCHAR(255) DEFAULT NULL,
            description TEXT,
            amount DECIMAL(14,2) DEFAULT NULL,
            due DATE DEFAULT NULL,
            frequency VARCHAR(64) DEFAULT NULL,
            created_at_source DATETIME DEFAULT NULL,
            updated_at_source DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_due (due),
            KEY idx_account_id (account_id),
            CONSTRAINT fk_rem_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
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
    $attempts = 0; $lastErr = '';
    while ($attempts < 3) {
        $attempts++;
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
        if ($body !== false && $status >= 200 && $status < 300) {
            $data = json_decode($body, true);
            if (is_array($data)) return $data;
            $lastErr = 'Invalid JSON';
        } else {
            $lastErr = 'HTTP ' . $status . ' ' . $err;
        }
        usleep(250 * 1000);
    }
    throw new RuntimeException('HTTP fetch failed ' . $lastErr . ' for ' . $url);
}

function upsert_account_by_name(PDO $pdo, string $name): ?int {
    if ($name === '') return null;
    $pdo->prepare('INSERT INTO accounts (name) VALUES (?) ON DUPLICATE KEY UPDATE updated_at = updated_at')
        ->execute([$name]);
    $sel = $pdo->prepare('SELECT id FROM accounts WHERE name = ?');
    $sel->execute([$name]);
    $id = $sel->fetchColumn();
    return $id === false ? null : (int)$id;
}

function upsert_reminder(PDO $pdo, array $r, ?int $accountId): void {
    $fmPk = (string)($r['PrimaryKey'] ?? '');
    if ($fmPk === '') return;
    $accountName = isset($r['Account']) ? (string)$r['Account'] : null;
    $desc = isset($r['Description']) ? (string)$r['Description'] : null;
    $amount = isset($r['Amount']) && $r['Amount'] !== '' ? (string)$r['Amount'] : null;
    $due = !empty($r['Due']) ? (string)$r['Due'] : null;
    $freq = isset($r['Frequency']) ? (string)$r['Frequency'] : null;
    $createdSrc = isset($r['CreationTimestamp']) ? rtrim(str_replace('T',' ',(string)$r['CreationTimestamp']),'Z') : null;
    $updatedSrc = isset($r['ModificationTimestamp']) ? rtrim(str_replace('T',' ',(string)$r['ModificationTimestamp']),'Z') : null;

    $sql = "INSERT INTO reminders (fm_pk, account_id, account_name, description, amount, due, frequency, created_at_source, updated_at_source)
            VALUES (:fm_pk, :account_id, :account_name, :description, :amount, :due, :frequency, :created_src, :updated_src)
            ON DUPLICATE KEY UPDATE account_id=VALUES(account_id), account_name=VALUES(account_name), description=VALUES(description),
              amount=VALUES(amount), due=VALUES(due), frequency=VALUES(frequency), created_at_source=VALUES(created_at_source),
              updated_at_source=VALUES(updated_at_source), updated_at=CURRENT_TIMESTAMP";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':fm_pk' => $fmPk,
        ':account_id' => $accountId,
        ':account_name' => $accountName,
        ':description' => $desc,
        ':amount' => $amount,
        ':due' => $due,
        ':frequency' => $freq,
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
    $limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 500; // records per run
    $sleepMs = isset($argv[2]) ? max(0, (int)$argv[2]) : 50; // throttle per record
    $pageSize = isset($argv[3]) ? max(1, (int)$argv[3]) : 20; // OData $top

    $next = state_get($pdo, 'rem.next');
    $skipState = state_get($pdo, 'rem.skip');
    $skip = $skipState !== null ? max(0, (int)$skipState) : 0;
    $url = $next ?: ($base . '/Reminders?$top=' . $pageSize . ($skip > 0 ? ('&$skip=' . $skip) : ''));
    $processed = 0;

    while ($processed < $limit) {
        $page = http_get($url, $user, $pass);
        $values = $page['value'] ?? [];
        if (!is_array($values) || count($values) === 0) {
            state_set($pdo, 'rem.next', null);
            echo "No more records.\n";
            break;
        }
        foreach ($values as $rec) {
            if ($processed >= $limit) break;
            $acctName = isset($rec['Account']) ? (string)$rec['Account'] : '';
            $acctId = $acctName !== '' ? upsert_account_by_name($pdo, $acctName) : null;
            upsert_reminder($pdo, $rec, $acctId);
            $processed++;
            if ($sleepMs > 0) usleep($sleepMs * 1000);
        }
        $nextLink = $page['@nextLink'] ?? $page['@odata.nextLink'] ?? null;
        if ($nextLink) {
            state_set($pdo, 'rem.skip', null);
            state_set($pdo, 'rem.next', (string)$nextLink);
            $url = (string)$nextLink;
        } else {
            $skip += count($values);
            state_set($pdo, 'rem.next', null);
            state_set($pdo, 'rem.skip', (string)$skip);
            $url = $base . '/Reminders?$top=' . $pageSize . '&$skip=' . $skip;
            if (count($values) < $pageSize) {
                state_set($pdo, 'rem.next', null);
                state_set($pdo, 'rem.skip', null);
                echo "Processed {$processed} record(s). End of feed.\n";
                break;
            }
        }
    }
    echo "Processed {$processed} record(s).\n";
}

main($argv);

