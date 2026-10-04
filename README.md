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
- Status: approved plan. Next: the schema and its proof, the kit, `maludb-os.json`, agents, skills, deploy (Phase 0's second half); no PHP before the Phase 1 checkpoint.
