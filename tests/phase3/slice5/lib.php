<?php
/**
 * Helpers for the slice 5 proofs (docs/build-specs/payables.md, "Proof"): builds on the slice 1–4 libraries. These proofs make their own vendors, bills and payments through the handlers
 * (slice 5 IS the payables), a fiscal year, two bank accounts and a tax rate by SQL. Every row is named "SMOKE …".
 * Roles: Bea 27 Bookkeeper (ap.write), Carl 28 Controller (ap.approve, journal.override), Vera 26 Viewer, owner 1.
 */
require dirname(__DIR__) . '/slice4/lib.php';

/** The common fixture: slice 4's year, bank and tax rate, plus a second bank (cash on hand) and a Euro bank. Answers ids. */
function ap_fixture(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    $fx = ar_fixture();
    $cash = (int) one("SELECT id FROM bank_accounts WHERE name = 'SMOKE Petty cash'");
    if (!$cash) { $cash = (int) one("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Petty cash', 'cash', :a, 'USD', 0, '2026-01-01', 1) RETURNING id", ['a' => account_id('1000')]); }
    $eur = (int) one("SELECT id FROM bank_accounts WHERE name = 'SMOKE Euro account'");
    if (!$eur) {
        if (!one("SELECT 1 FROM accounts WHERE code = '1020'")) { q("INSERT INTO accounts (code, name, account_type, subtype, is_bank, is_control, is_system) VALUES ('1020', 'SMOKE Euro account', 'asset', 'bank', true, false, false)"); }
        $eur = (int) one("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Euro account', 'checking', :a, 'EUR', 0, '2026-01-01', 1) RETURNING id", ['a' => account_id('1020')]);
    }
    return $ids = $fx + ['cash' => $cash, 'eur' => $eur];
}
/** A vendor through the handler as the Bookkeeper; answers the id. */
function mk_vendor(string $name, array $extra = []): int
{
    [$c, $b] = act(as_member(27), '/vendors/save.php', ['name' => $name, 'email' => strtolower(str_replace(' ', '', $name)) . '@example.invalid'] + $extra);
    return (int) ($b['record_id'] ?? 0);
}
/** A draft bill with lines [[desc, qty, price, ?tax, ?account]] through the handler; answers the id. */
function mk_bill(int $vendor, array $lines, array $extra = []): int
{
    $rows = array_map(fn ($l) => ['description' => $l[0], 'quantity' => $l[1], 'unit_price' => $l[2], 'expense_account' => $l[4] ?? '6200'] + (isset($l[3]) ? ['tax_rate' => $l[3]] : ['tax_rate' => '']), $lines);
    [$c, $b] = act(as_member(27), '/bills/save.php', ['vendor' => $vendor, 'lines' => json_encode($rows)] + $extra);
    return (int) ($b['record_id'] ?? 0);
}
/** Approve it as the Controller; answers the decoded body with _code. */
function approve(int $bill): array { [$c, $b] = act(as_member(28), '/bills/approve.php', ['bill' => $bill]); return $b + ['_code' => $c]; }
/** A draft bill, approved. */
function ready_bill(int $vendor, array $lines, array $extra = []): int { $id = mk_bill($vendor, $lines, $extra); approve($id); return $id; }
function bill_row(int $id): array { return q('SELECT * FROM bills WHERE id = :i', ['i' => $id])[0] ?? []; }
function pay_row(int $id): array { return q('SELECT * FROM payments WHERE id = :i', ['i' => $id])[0] ?? []; }
function run_row(int $id): array { return q('SELECT * FROM payment_runs WHERE id = :i', ['i' => $id])[0] ?? []; }
/** A draft payment for a vendor through the handler (the bills applied oldest first); answers the id. */
function mk_payment(int $vendor, int $bank, array $billIds, array $extra = []): int
{
    [$c, $b] = act(as_member(27), '/payments/save.php', $extra + ['vendor' => $vendor, 'bank_account' => $bank, 'method' => 'ach', 'bills' => $billIds]);
    return (int) ($b['record_id'] ?? 0);
}
/** Void every other open bill (a proof that selects bills by date must not meet another proof's leftovers); a bill that cannot be voided (a payment or credit applied) is left. */
function clear_open_bills(array $exceptVendors): void
{
    $skip = $exceptVendors === [] ? '0' : implode(',', array_map('intval', $exceptVendors));
    foreach (q("SELECT id FROM bills WHERE status IN ('approved', 'partially_paid') AND vendor_id NOT IN ($skip) ORDER BY id") as $r) {
        try { q("SELECT gl_bill_void(:b, 28, 'SMOKE clearing for the runs proof')", ['b' => $r['id']]); } catch (PDOException) { }
    }
}
