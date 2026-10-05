#!/usr/bin/env bash
# Phase 5 — the end-to-end proof of design §10 on a scratch installation: tests/phase5/run.sh
# A fresh SCRATCH database (gl_dev14 — never the installed one), the application on :8391 (php -S), a fake kernel (:8392 — run-facts, K6) and a fake MaluDB/MaluMail (:8393/:8396),
# and the servers under test on :8398 (records), :8399 (activity) and :8400 (records with a DEAD kernel url, for the fail-closed proofs). NOTHING REAL IS TOUCHED: no live service,
# Apache, unit, database or role password. PROOFS="gate tools_people" runs some. Needs `sudo -n -u postgres`, php, mcp/venv, the kernel's mcp/venv (for the hook proof).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export GL_DEV_DB=${GL_DEV_DB:-gl_dev14} GL_DEV_ENV=${GL_DEV_ENV:-/tmp/gl-dev14.env} GL_DEV_STATE=${GL_DEV_STATE:-/tmp/gl-dev14-state} SHOTS=${SHOTS:-/tmp/gl-shots-p5}
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
proofs=${PROOFS:-e2e}
for p in $proofs; do
  echo "== $p"
  php "tests/phase5/$p.php" || status=1
done
[ $status -eq 0 ] && echo "ALL PHASE 5 PROOFS PASSED" || echo "SOME PHASE 5 PROOFS FAILED"
exit $status
