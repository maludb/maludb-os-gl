# maludb-os-gl — General Ledger

MaluDB Business OS application: **General Ledger** — a chart of accounts, a double-entry journal with fiscal periods,
the standard financial statements (P&L, balance sheet, trial balance, cash flow), accounts receivable (customers,
invoices, receipts, deposits), accounts payable (vendors, bills, payment runs), and cash management that ties each
bank account in the ledger out to the bank's statement — with no bank feed and no way to move money. The kernel's
monthly AI statement becomes one bill per provider.

An optional application: installed on request beside [maludb-os-core](https://github.com/maludb/maludb-os-core),
never by default, never granted to every department. Built with `htmx-php-builder`, fitted to
`maludb-os-integration` 0.5.1, served as `gl.<domain>`.

- **The plan:** `docs/gl-design.md` (Phase 0, 2026-10-04 — **approved**; the owner's fourteen decisions are §15).
- Status: **Phase 0 complete** (2026-10-04) — the schema db/001–016 proven by 151 checks (`db/proof/phase0_proof.sql`), the kit proven without a kernel (`tests/phase0/run.sh`, 15 checks), `maludb-os.json`, two agents, seven skills, deploy templates. **Phase 1 written 2026-10-05** (`docs/gl-mcp-tool-surface.md`, `docs/gl-action-manifest.md` — 92 screens, 124 actions —, `mcp/action_registry.json`, ten specs in `docs/build-specs/`) — **approved 2026-10-05**; **Phase 2 built and proven 2026-10-05** (`tests/phase2/run.sh`, 251 checks under php -S and Apache); **slice 1 (the chart and fiscal time) built and proven 2026-10-05** (`tests/phase3/slice1/run.sh`, 219 checks); **slice 2 (journal core, the exemplar) built and proven 2026-10-05** (`tests/phase3/slice2/run.sh`, 161 checks). **The handoff point is reached: slices 3–10 are the worker's.**
- Proofs: `tests/setup_dev.sh` builds the scratch database; `tests/phase0/run.sh` runs both proofs.
