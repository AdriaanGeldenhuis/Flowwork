#!/usr/bin/env bash
# Provision the local test database for the finance test suite.
#
# Creates database `flowwork_test` (user flowwork_test / flowwork_test_pw) on a
# local MariaDB, loads the base schema+data dump from git history (the dump was
# purged from the working tree for security reasons but is needed as the base
# DDL), applies every migration in /Migrations in filename order, and seeds the
# SARS chart of accounts.
#
# Usage:  tests/db/setup.sh            (from the repo root)
# Requires: a running local MariaDB reachable as root via unix socket
#           (`service mariadb start`), or docker.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BASE_DUMP_REF="2f1a0b9^:Migrations/flowwwqmnt_db1.sql"
DB_NAME="${TEST_DB_NAME:-flowwork_test}"
DB_USER="${TEST_DB_USER:-flowwork_test}"
DB_PASS="${TEST_DB_PASS:-flowwork_test_pw}"

MARIADB="mariadb"
if ! command -v mariadb >/dev/null 2>&1; then
  MARIADB="mysql"
fi

if ! $MARIADB -e "SELECT 1" >/dev/null 2>&1; then
  if command -v service >/dev/null 2>&1; then
    service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1 || true
    sleep 2
  fi
fi
if ! $MARIADB -e "SELECT 1" >/dev/null 2>&1; then
  echo "ERROR: cannot reach a local MariaDB as root. Install/start one first:" >&2
  echo "  apt-get install -y mariadb-server && service mariadb start" >&2
  echo "  (or docker run -d -p 127.0.0.1:3306:3306 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 mariadb:10.11)" >&2
  exit 1
fi

echo "==> Recreating ${DB_NAME}"
$MARIADB -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;"

echo "==> Loading base schema dump from git history (${BASE_DUMP_REF})"
git -C "$REPO_ROOT" show "$BASE_DUMP_REF" \
  | $MARIADB --init-command="SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'; SET SESSION foreign_key_checks=0;" "$DB_NAME"

echo "==> Applying migrations"
for f in $(ls "$REPO_ROOT/Migrations"/*.sql | sort); do
  name="$(basename "$f")"
  if out=$($MARIADB --init-command="SET SESSION sql_mode=''; SET SESSION foreign_key_checks=0;" "$DB_NAME" < "$f" 2>&1); then
    echo "    OK   $name"
  else
    # Non-finance migrations can collide with data already present in the base
    # dump (duplicate seed rows / indexes). Report and continue.
    echo "    SKIP $name :: $(echo "$out" | head -1)"
  fi
done

echo "==> Seeding SARS chart of accounts for all companies"
for cid in $($MARIADB -N "$DB_NAME" -e "SELECT id FROM companies"); do
  $MARIADB "$DB_NAME" -e "CALL seed_sars_chart_of_accounts(${cid});"
done

echo "==> Running finance_setup tool"
DB_HOST=127.0.0.1 DB_NAME="$DB_NAME" DB_USER="$DB_USER" DB_PASS="$DB_PASS" php "$REPO_ROOT/finances/tools/finance_setup.php"

echo "==> Done. Test env:"
echo "    DB_HOST=127.0.0.1 DB_NAME=${DB_NAME} DB_USER=${DB_USER} DB_PASS=${DB_PASS}"
echo "    Run the suite with: php tests/run.php"
