-- Plaid Link and Transactions sync tables.
-- Target DB: budget_lillard_app
-- Plaid accounts are mapped explicitly to existing budget accounts.
-- Plaid transactions are stored for matching; this migration does not auto-post budget transactions.

CREATE TABLE IF NOT EXISTS plaid_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner VARCHAR(190) NOT NULL,
    environment VARCHAR(32) NOT NULL DEFAULT 'production',
    item_id VARCHAR(255) NOT NULL,
    access_token TEXT NOT NULL,
    institution_id VARCHAR(64) DEFAULT NULL,
    institution_name VARCHAR(255) DEFAULT NULL,
    link_session_id VARCHAR(255) DEFAULT NULL,
    transactions_cursor TEXT DEFAULT NULL,
    sync_status VARCHAR(32) NOT NULL DEFAULT 'active',
    last_synced_at DATETIME DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_owner_env_item (owner, environment, item_id),
    KEY idx_owner_env (owner, environment),
    KEY idx_item_id (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plaid_accounts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plaid_transactions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plaid_webhooks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    environment VARCHAR(32) NOT NULL DEFAULT 'production',
    received_at DATETIME NOT NULL,
    item_id VARCHAR(255) DEFAULT NULL,
    webhook_type VARCHAR(64) DEFAULT NULL,
    webhook_code VARCHAR(128) DEFAULT NULL,
    payload_json LONGTEXT NOT NULL,
    processing_status VARCHAR(32) NOT NULL DEFAULT 'received',
    processing_message TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_item_received (item_id, received_at),
    KEY idx_type_code (webhook_type, webhook_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
