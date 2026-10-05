#!/usr/bin/env bash
# General Ledger — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0
# (2026-10-04); later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database
# (tests/setup_dev.sh), php -S and fixtures, and never touch an installed application, Apache or systemd.
#   bash deploy/ROOT_STEPS.sh          prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them (0)
set -euo pipefail
APP=/srv/apps/gl
KERNEL=/var/www
MODE=${1:-plan}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (the owner's D14; Help Desk's lesson of 2026-10-02). A config/.env
#    holding ONLY the three ports: the installer reads them, fills every other required key itself, and sets fresh role
#    passwords because DB_PASSWORD is absent. Never put anything else in this file by hand.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "printf 'APP_INTERNAL_PORT=8184\nMCP_RECORDS_PORT=8829\nMCP_ACTIVITY_PORT=8830\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{…}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K13 seeded, db/167 —, the application with /sso and /sso/logout,
#    scope_kind none), the application token into config/.env, skills imported, approval policies, the installing
#    super-admin's admin grant. NEVER --grant-standing-departments (D3: the books are granted person by person; K14 makes the
#    installer warn). --hire-agents hires the expert and the Bookkeeper (D6). Run it twice: the roles are read from
#    app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. The owner's keys and decisions (any time after 2):
echo "   (the whole sequence with the live end-to-end proof: docs/phase5-runbook.md; db is now 001-020, units: two MCP servers, worker, ingest, directory sync)"
echo "== 3. The owner's"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (slice 4 sends invoices, statements and reminders)"
echo "   - the K6 text sender, if texts are wanted: php $KERNEL/bin/notify_endpoint_set.php (already set when another application uses it)"
echo "   - DNS and TLS for gl.subello.com (until then /etc/hosts maps it to this host)"
echo "   - the first fiscal year and the opening balances (Phase 5), the bank accounts, the people granted (Viewer, Bookkeeper, Controller, Accountant)"
echo "   - the Bookkeeper's duty (30 6 * * *) in Agent HR until the installer reads agents[].duty"
echo "   - K14 (the installer's guard) and K15 (the Accounting agents' grants) in the kernel; H1 (payroll_by_department) in HR"
