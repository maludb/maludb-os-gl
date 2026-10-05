# General Ledger — CLAUDE.md

This repository is the **General Ledger** application of the MaluDB Business OS (`github.com/maludb/maludb-os-gl`,
private; local clone `/srv/apps/gl`). The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the
kernel owns identity, the directory, the agents and approvals; this application owns the books and never moves money.

## Read first
1. `docs/gl-design.md` — the plan (Phase 0): actors, roles, the memory model, the question inventory, the agents' door,
   the build order, what the kernel owes (K13 built as db/167; K14–K16, H1 owed), the owner's decisions (§13, **all fourteen answered 2026-10-04, recorded as D1–D14 in §15** — rules, not questions), the state (§16 — **Phase 0 complete 2026-10-04**, the schema proven by 151 checks, the kit by 15; **Phase 1 written 2026-10-05**: the tool surface, the manifest (92 screens, 124 actions, 69 approvals), the registry, ten specs — **APPROVED 2026-10-05**, the nine answers as recommended; **Phase 2 BUILT 2026-10-05**, 251 checks; **slice 1 BUILT 2026-10-05**, 219 checks; **slice 2 BUILT 2026-10-05**, 161 checks — **THE HANDOFF POINT IS REACHED: slices 3–9 are the worker's**; **slice 3 (statements) BUILT 2026-10-05, 127 checks — its Open questions hold the per-currency-statements question for the owner; slice 4 (receivables) BUILT 2026-10-05, 292 checks, db/017; slice 5 (payables) BUILT 2026-10-05, 370 checks, no schema change; slice 6 (cash) BUILT 2026-10-05, 270 checks, db/018; slice 7 (the AI statement) BUILT 2026-10-05, 148 checks, no schema change; slice 8 (recurring, notifications, the worker) BUILT 2026-10-05, 174 checks, db/019; next slice 9**, from `docs/build-specs/statements.md` on, replicating slices 1 and 2).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references) — how it fits.
3. `/srv/apps/helpdesk` — the nearest exemplar (its design, kit, specs, proof suites); the kernel's retired
   `docs/build-specs/bookkeeping-ledger.md`, `sales-invoicing.md`, `expenses.md` — the prior art.

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` for every screen, `mcp-servers`,
  `chat-actions`); `os-integration` governs the fit. The checkpoint gate: no feature PHP until the owner approves the
  schema + tool surface + action manifest + slice specs together (Phase 1).
- Catalog key `gl`; ports pinned before `apply`: 8184 / 8829 / 8830 (design §13.14). Never install with
  `--grant-standing-departments`.
- `docs/gl-action-manifest.md` is the one source of actions, screens and approval categories: `php bin/build_action_registry.php --check`
  and `php bin/sync_approvals.php --check` must both pass before a commit (the second rewrites `maludb-os.json` `approvals[]` without `--check`).
- The database enforces the accounting: balance, period open, immutable once posted, reversal never un-post, one entry per
  source document, gapless invoice numbers on issue; a document POSTS when it becomes true (issue, approve, record) through the
  `gl_*` writer functions (db/008–013) — PHP calls them and presents their refusals; it never re-implements them.
- Proofs: `tests/setup_dev.sh` (the scratch database `gl_dev`, never the installed one), `tests/phase0/run.sh` (schema + kit), `tests/phase2/run.sh`
  (the shell; `GL_APP=apache` for the real vhost); every slice adds `tests/phase3/sliceN/run.sh` and keeps the earlier ones green. The scratch ports are 8391–8397.
- Agents draft; a person posts, pays, sends, closes (approval categories in design §5).
- Commit after every finished step on `main`; never push unless the owner asks.

## Build order and the handoff (decided 2026-10-04)

The same division of labour every earlier application used: a **planning-class model** writes what the database
enforces, the specs and the exemplar; a **worker model (Sonnet 5.5)** replicates every other slice from a spec,
stopping and escalating on any ambiguity rather than improvising. The handoff is a clean checkpoint, with everything
a worker needs in this repository — never mid-slice.

**Before the handoff (planning-class model):**
1. **K13** in the kernel (`/var/www`): a migration seeding the `gl` catalog row (Finance / accounting / `feather-book-open`).
2. **Phase 0, second half**: `db/001`–`0NN` with the accounting rules as triggers (balance, period open, immutable once
   posted, one entry per source, gapless issue numbers, the control-account guard), the seeded chart (D11), the
   statement functions (`gl_trial_balance`, `gl_profit_and_loss`, `gl_balance_sheet`, `gl_cash_flow`, `gl_account_activity`,
   `gl_ar_aging`, `gl_ap_aging`), `gl_has_right()`, the `mcp_*` views; `db/proof/phase0_proof.sql` on a scratch database;
   the kit copied from Help Desk and proven without a kernel (`tests/phase0/run.sh`); `maludb-os.json`,
   `os/{expert,bookkeeper}.md`, the seven skills, `deploy/` with `ROOT_STEPS.sh` pinning 8184/8829/8830.
3. **Phase 1**: `docs/gl-mcp-tool-surface.md`, `docs/gl-action-manifest.md`, `mcp/action_registry.json` (built clean by
   `bin/build_action_registry.php --check`), and the ten specs in `docs/build-specs/` — `sso-shell`, then slices 1–9 of the
   design §10, each in the shape of Help Desk's (`/srv/apps/helpdesk/docs/build-specs/tickets-core.md`): screens, files,
   query-function signatures, actions with categories, log events, tools, acceptance, "Open questions" EMPTY.
   **The owner approves Phase 1 as a whole before any PHP.**
4. **Phase 2** (`sso-shell`) and **slice 1** (the chart and fiscal time — the CRUD exemplar for workers).
5. **Slice 2, journal core — THE EXEMPLAR**: the lines editor and balance strip, post/reverse/void, the period gates, the
   timeline, attachments; its proof suite `tests/phase3/slice2/run.sh` green. This is what every later slice copies.

**The handoff point** = commit after slice 2 with design §16 saying "exemplar built; slices 3–9 open to workers".

**After the handoff (worker model — Sonnet 5.5):** slices 3 → 9 in order, one at a time, each on the exemplar's
pattern from its spec: screens + handlers + `log_activity()` + manifest entries + the tools' query functions, proven at
375 and 1280 on a scratch database, the spec's "Built and proven" and the design §16 updated, one commit per slice. Then
**Phase 4** (the two MCP servers over the same query functions, `app_roles`, the `shares[]` tools, the registry wrapper,
the agents' grants — Help Desk's `tests/phase4/run.sh` is the pattern), and **Phase 5** with the owner (`apply`, DNS,
MaluMail, the hires, the opening balances, the end-to-end proof of §10).

**A worker's rules:** read this file, design §3 (rights), §5 (what pauses), §6 (the tables — never modify a migration,
add one), §15 (D1–D14) and the slice's spec before touching a file; reads through `mcp_*` views, writes to base
tables after the gate; the database's refusal is shown as a field error, never re-implemented in PHP; money events
carry `amount` and `currency` in `after`; a question the spec does not answer stops the slice and is written into the
spec's "Open questions" for the owner — never guessed.
