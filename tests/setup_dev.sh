#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $GL_DEV_DB, default gl_dev)
# 1. drops and creates the scratch database; 2. applies db/*.sql in order as postgres (the same files the installer applies);
# 3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch
#    password (the kernel's installer sets fresh ones at `apply`); 4. writes the proofs' environment to $GL_DEV_ENV (default
#    /tmp/gl-dev.env, mode 600). Nothing here is committed or installed.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${GL_DEV_DB:-gl_dev}
ENVF=${GL_DEV_ENV:-/tmp/gl-dev.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
for f in "$ROOT"/db/0*.sql; do $PSQL -d "$DB" -f "$f" >/dev/null; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in gl_rw gl_records_ro gl_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="General Ledger"
APP_KEY=gl
APP_URL=http://127.0.0.1:8391
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=gl_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=gl_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=gl_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8391
MCP_RECORDS_PORT=8394
MCP_ACTIVITY_PORT=8395
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8392
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8393
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:8396
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=ledger@example.invalid
MAIL_FROM_NAME="General Ledger"
ATTACHMENT_MAX_BYTES=5000000
GL_LINK_LIFETIME_DAYS=180
ENV
echo "scratch database $DB ready; environment in $ENVF"
