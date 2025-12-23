#!/usr/bin/env bash
set -euo pipefail

PROD_HOST=${PROD_HOST:-root@lillard.app}
PROD_APP_DIR=${PROD_APP_DIR:-/var/www/budget.lillard.app}
DEV_APP_DIR=${DEV_APP_DIR:-/root/projects/internal/budget.lillard.app}
export DEV_APP_DIR

LOCK_FILE=${LOCK_FILE:-/tmp/budget-prod-to-dev.lock}

if command -v flock >/dev/null 2>&1; then
  exec 9>"$LOCK_FILE"
  if ! flock -n 9; then
    echo "Another sync is running; exiting." >&2
    exit 1
  fi
fi

tmp_dir=$(mktemp -d /tmp/budget-prod-to-dev.XXXXXX)
trap 'rm -rf "$tmp_dir"' EXIT

dump_file="$tmp_dir/prod_data.sql"

dev_cnf="$tmp_dir/dev.cnf"
DEV_DB=$(php -r '$cfg=require getenv("DEV_APP_DIR")."/config.php"; $db=$cfg["db"]; $cnf=$argv[1]; $contents="[client]\nuser=".$db["username"]."\npassword=".$db["password"]."\nhost=".$db["host"]."\nport=".$db["port"]."\n"; file_put_contents($cnf, $contents); chmod($cnf, 0600); echo $db["database"];' "$dev_cnf")

if [[ -z "$DEV_DB" ]]; then
  echo "Failed to determine dev database name." >&2
  exit 1
fi

# Determine optional flags supported on production host
read -r -d '' remote_script <<'REMOTE' || true
set -euo pipefail

if ! command -v mysqldump >/dev/null 2>&1; then
  echo "mysqldump not found on production host" >&2
  exit 1
fi

tmp=$(mktemp /tmp/budget_prod_cnf.XXXXXX)
DB_NAME=$(php -r '$cfg=require getenv("PROD_APP_DIR")."/config.php"; $db=$cfg["db"]; $cnf=$argv[1]; $contents="[client]\nuser=".$db["username"]."\npassword=".$db["password"]."\nhost=".$db["host"]."\nport=".$db["port"]."\n"; file_put_contents($cnf, $contents); chmod($cnf, 0600); echo $db["database"];' "$tmp")

if [[ -z "$DB_NAME" ]]; then
  echo "Failed to determine production database name." >&2
  rm -f "$tmp"
  exit 1
fi

flags=(
  --no-create-info
  --complete-insert
  --skip-triggers
  --skip-lock-tables
  --single-transaction
  --quick
  --order-by-primary
  --replace
)
if mysqldump --help 2>/dev/null | grep -q -- '--no-tablespaces'; then
  flags+=(--no-tablespaces)
fi
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  flags+=(--set-gtid-purged=OFF)
fi
if mysqldump --help 2>/dev/null | grep -q -- '--column-statistics'; then
  flags+=(--column-statistics=0)
fi

mysqldump --defaults-extra-file="$tmp" "${flags[@]}" "$DB_NAME"
rm -f "$tmp"
REMOTE

# Export production data to a local temp file
ssh "$PROD_HOST" "PROD_APP_DIR='$PROD_APP_DIR' bash -s" > "$dump_file" <<<"$remote_script"

# Import into dev without deleting non-conflicting rows
{
  echo "SET FOREIGN_KEY_CHECKS=0;"
  cat "$dump_file"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} | mysql --defaults-extra-file="$dev_cnf" "$DEV_DB"

echo "Sync complete: production data merged into dev (no deletes)."
