# General Ledger — CLAUDE.md

This repository is the **General Ledger** application of the MaluDB Business OS (`github.com/maludb/maludb-os-gl`,
private; local clone `/srv/apps/gl`). The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the
kernel owns identity, the directory, the agents and approvals; this application owns the books and never moves money.

## Read first
1. `docs/gl-design.md` — the plan (Phase 0): actors, roles, the memory model, the question inventory, the agents' door,
   the build order, what the kernel owes (K13–K16, H1), the owner's decisions (§13, answers recorded in §15), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references) — how it fits.
3. `/srv/apps/helpdesk` — the nearest exemplar (its design, kit, specs, proof suites); the kernel's retired
   `docs/build-specs/bookkeeping-ledger.md`, `sales-invoicing.md`, `expenses.md` — the prior art.

## Rules that bind here
- `htmx-php-builder` skills govern the PHP (`php-patterns` before any PHP, `design-system` for every screen, `mcp-servers`,
  `chat-actions`); `os-integration` governs the fit. The checkpoint gate: no feature PHP until the owner approves the
  schema + tool surface + action manifest + slice specs together (Phase 1).
- Catalog key `gl`; ports pinned before `apply`: 8184 / 8829 / 8830 (design §13.14). Never install with
  `--grant-standing-departments`.
- The database enforces the accounting: balance, period open, immutable once posted, reversal never un-post, one entry per
  source document, gapless invoice numbers on issue. PHP presents those refusals; it never re-implements them.
- Agents draft; a person posts, pays, sends, closes (approval categories in design §5).
- Commit after every finished step on `main`; never push unless the owner asks.
