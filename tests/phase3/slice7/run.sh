#!/usr/bin/env bash
# Phase 3, slice 7 — the AI statement: tests/phase3/slice7/run.sh
# A fresh SCRATCH database (gl_dev10 — never the installed one), the application on :8391 (php -S, or GL_APP=apache for a real Apache with
# the rendered deploy vhost), a fake kernel (:8392), a fake MaluDB (:8393), a fake MaluMail (:8396). Needs `sudo -n -u postgres`, php, the
# kernel's web/node_modules (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/gl-shots-p3s7).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export GL_DEV_DB=${GL_DEV_DB:-gl_dev10} GL_DEV_ENV=${GL_DEV_ENV:-/tmp/gl-dev10.env} GL_DEV_STATE=${GL_DEV_STATE:-/tmp/gl-dev10-state} SHOTS=${SHOTS:-/tmp/gl-shots-p3s7}
mkdir -p "$SHOTS" "$GL_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$GL_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$GL_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$GL_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-pull import group poll json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice7/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice7/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 7 PROOFS PASSED" || echo "SOME SLICE 7 PROOFS FAILED"
exit $status
