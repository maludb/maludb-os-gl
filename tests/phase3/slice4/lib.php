<?php
/**
 * Helpers for the slice 4 proofs (docs/build-specs/receivables.md, "Proof"): builds on the slice 1–3 libraries. These proofs make their own customers and invoices through the handlers
 * (the Phase 0 `world()` is not used: slice 4 IS the receivables), a fiscal year, a bank account and a tax rate by SQL. Every row is named "SMOKE …".
 */
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__, 3) . '/app/money_math.php';

/** The common fixture: a fiscal year, a checking bank account, an 8.25% tax rate (once). Answers ids. */
function ar_fixture(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    ensure_year(as_member(1));
    pdo()->exec("UPDATE gl_settings SET business_name = 'SMOKE Co', business_address = '1 Main St'");
    $bank = (int) one("SELECT id FROM bank_accounts WHERE name = 'SMOKE Checking'");
    if (!$bank) { $bank = (int) one("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Checking', 'checking', :a, 'USD', 0, '2026-01-01', 1) RETURNING id", ['a' => account_id('1010')]); }
    $tax = (int) one("SELECT id FROM tax_rates WHERE name = 'SMOKE Sales tax 8.25%'");
    if (!$tax) { $tax = (int) one("INSERT INTO tax_rates (name, rate) VALUES ('SMOKE Sales tax 8.25%', 8.25) RETURNING id"); }
    return $ids = ['bank' => $bank, 'tax' => $tax];
}
/** A customer through the handler as the Bookkeeper; answers the id. */
function mk_customer(string $name, array $extra = []): int
{
    [$c, $b] = act(as_member(27), '/customers/save.php', ['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@example.invalid'] + $extra);
    return (int) ($b['record_id'] ?? 0);
}
/** A draft invoice with lines [[desc, qty, price, ?tax]] through the handler; answers the id. */
function mk_invoice(int $customer, array $lines, array $extra = []): int
{
    $rows = array_map(fn ($l) => ['description' => $l[0], 'quantity' => $l[1], 'unit_price' => $l[2], 'income_account' => $l[4] ?? '4100'] + (isset($l[3]) ? ['tax_rate' => $l[3]] : ['tax_rate' => '']), $lines);
    [$c, $b] = act(as_member(27), '/invoices/save.php', ['customer' => $customer, 'lines' => json_encode($rows)] + $extra);
    return (int) ($b['record_id'] ?? 0);
}
/** Issue it as the Bookkeeper; answers the decoded body. */
function issue(int $invoice): array { [$c, $b] = act(as_member(27), '/invoices/issue.php', ['invoice' => $invoice]); return $b + ['_code' => $c]; }
function inv_row(int $id): array { return q('SELECT * FROM invoices WHERE id = :i', ['i' => $id])[0] ?? []; }
/** The raw token held for an invoice (only in the held outbox row). */
function held_token(int $invoice): ?string { $body = one("SELECT body FROM notification_outbox WHERE kind = 'invoice_link' AND record_type = 'invoice' AND record_id = :i AND status = 'skipped' ORDER BY id DESC LIMIT 1", ['i' => $invoice]); return $body && preg_match('#/i/([a-f0-9]{48})$#', (string) $body, $m) ? $m[1] : null; }
