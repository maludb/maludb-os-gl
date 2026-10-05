#!/usr/bin/env bash
# Phase 4 — the two MCP servers, app_roles, the shares, the gate, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (gl_dev13 — never the installed one), the application on :8391 (php -S), a fake kernel (:8392 — run-facts, K6) and a fake MaluDB/MaluMail (:8393/:8396),
# and the servers under test on :8398 (records), :8399 (activity) and :8400 (records with a DEAD kernel url, for the fail-closed proofs). NOTHING REAL IS TOUCHED: no live service,
# Apache, unit, database or role password. PROOFS="gate tools_people" runs some. Needs `sudo -n -u postgres`, php, mcp/venv, the kernel's mcp/venv (for the hook proof).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export GL_DEV_DB=${GL_DEV_DB:-gl_dev13} GL_DEV_ENV=${GL_DEV_ENV:-/tmp/gl-dev13.env} GL_DEV_STATE=${GL_DEV_STATE:-/tmp/gl-dev13-state} SHOTS=${SHOTS:-/tmp/gl-shots-p4}
mkdir -p "$GL_DEV_STATE"; rm -f "$GL_DEV_STATE/world.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
trap '"$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$GL_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$GL_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$GL_DEV_STATE/maludb.log" FAKE_MALUMAIL_STATE="$GL_DEV_STATE/malumail.json" FAKE_MALUMAIL_LOG="$GL_DEV_STATE/malumail.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-gate tools_books tools_documents tools_cash tools_shares tools_activity registry hook}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
