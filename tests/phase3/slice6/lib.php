<?php
/**
 * Helpers for the slice 6 proofs (docs/build-specs/cash.md, "Proof"): builds on the slice 1–5 libraries. cash_world() records, by SQL through the writer functions, the receipts, deposit and payments the bank lines will be matched to
 * (the screens of slices 4–5 are not used: slice 6 reads their results). Idempotent. Every row is named "SMOKE …".
 *   Checking (1010, opening 10,000.00 on 2026-01-01) · Petty cash (1000) · Card (2300, a credit card)
 *   R1 Acme 1,608.24 banked straight to Checking 2026-05-05 · R2 500.00 + R3 300.00 undeposited → deposit D1 800.00 on 2026-05-10 · R4 800.00 undeposited (no deposit: never a candidate)
 *   P1 Hosting 259.80 by ACH 2026-05-12 · P2 Landlord 2,000.00 by cheque 2001 on 2026-05-28 (outstanding at the end of May)
 *   Ledger at 31 May: 10,000 + 1,608.24 + 800 − 259.80 − 2,000 = 10,148.44 (before the fee and interest entries the proofs post).
 */
require dirname(__DIR__) . '/slice5/lib.php';

function cash_world(): array
{
    static $ids = null;
    if ($ids !== null) { return $ids; }
    ensure_year(as_member(1));
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    $row = q("SELECT id FROM bank_accounts WHERE name = 'SMOKE Checking'");
    if ($row) {
        return $ids = cash_ids();
    }
    $chk = (int) one("INSERT INTO bank_accounts (name, kind, account_id, institution, account_last4, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Checking', 'checking', :a, 'SMOKE Bank', '4321', 'USD', 10000, '2026-01-01', 1) RETURNING id", ['a' => account_id('1010')]);
    $cash = (int) one("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Petty cash', 'cash', :a, 'USD', 0, '2026-01-01', 1) RETURNING id", ['a' => account_id('1000')]);
    $card = (int) one("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Card', 'credit_card', :a, 'USD', 0, '2026-01-01', 1) RETURNING id", ['a' => account_id('2300')]);
    $je = (int) one("INSERT INTO journal_entries (entry_date, memo, source, created_by) VALUES ('2026-01-01', 'SMOKE opening balance — Checking', 'opening', 1) RETURNING id");
    foreach ([[1, '1010', '10000', '0'], [2, '3000', '0', '10000']] as [$n, $code, $dr, $cr]) { q('INSERT INTO journal_lines (entry_id, line_no, account_id, debit, credit) VALUES (:e, :n, :a, :d, :c)', ['e' => $je, 'n' => $n, 'a' => account_id($code), 'd' => $dr, 'c' => $cr]); }
    one('SELECT gl_entry_post(:e, 1)', ['e' => $je]);
    $acme = (int) one("INSERT INTO customers (name, email, terms_days, created_by) VALUES ('SMOKE Cash Acme', 'ap@cashacme.invalid', 30, 27) RETURNING id");
    $rcv = function (float|string $amt, string $date, ?int $bank) use ($acme): int {
        $r = (int) one("INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, deposit_to_bank_account_id, recorded_by) VALUES (:c, :d, :a, 'USD', 'transfer', 'SMOKE', :b, 27) RETURNING id", ['c' => $acme, 'd' => $date, 'a' => $amt, 'b' => $bank]);
        one('SELECT gl_receipt_post(:r, 27)', ['r' => $r]);
        return $r;
    };
    $r1 = $rcv('1608.24', '2026-05-05', $chk); $r2 = $rcv('500.00', '2026-05-06', null); $r3 = $rcv('300.00', '2026-05-07', null); $r4 = $rcv('800.00', '2026-05-09', null);
    $d1 = (int) one("INSERT INTO deposits (bank_account_id, deposit_date, currency, created_by) VALUES (:b, '2026-05-10', 'USD', 27) RETURNING id", ['b' => $chk]);
    q('UPDATE receipts SET deposit_id = :d WHERE id IN (:a, :b)', ['d' => $d1, 'a' => $r2, 'b' => $r3]);
    one('SELECT gl_deposit_record(:d, 27)', ['d' => $d1]);
    $host = (int) one("INSERT INTO vendors (name, terms_days, created_by) VALUES ('SMOKE Cash Hosting', 30, 27) RETURNING id");
    $land = (int) one("INSERT INTO vendors (name, terms_days, created_by) VALUES ('SMOKE Cash Landlord', 0, 27) RETURNING id");
    $pay = function (int $vendor, string $amt, string $date, string $method, ?string $chq, int $acct) use ($chk): int {
        $b = (int) one("INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, created_by) VALUES (:v, :r, :d, :d, 'USD', 27) RETURNING id", ['v' => $vendor, 'r' => 'B-' . $date . '-' . $amt, 'd' => $date]);
        q('INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, expense_account_id) VALUES (:b, 1, \'SMOKE\', 1, :p, :a)', ['b' => $b, 'p' => $amt, 'a' => $acct]);
        one('SELECT gl_bill_approve(:b, 28)', ['b' => $b]);
        $p = (int) one("INSERT INTO payments (vendor_id, payment_date, amount, currency, method, cheque_number, bank_account_id, created_by) VALUES (:v, :d, :a, 'USD', :m, :c, :bk, 27) RETURNING id", ['v' => $vendor, 'd' => $date, 'a' => $amt, 'm' => $method, 'c' => $chq, 'bk' => $chk]);
        q('INSERT INTO payment_applications (payment_id, bill_id, amount, applied_by) VALUES (:p, :b, :a, 27)', ['p' => $p, 'b' => $b, 'a' => $amt]);
        one('SELECT gl_payment_approve(:p, 28)', ['p' => $p]);
        one('SELECT gl_payment_record(:p, 27)', ['p' => $p]);
        return $p;
    };
    $p1 = $pay($host, '259.80', '2026-05-12', 'ach', null, account_id('6200'));
    $p2 = $pay($land, '2000.00', '2026-05-28', 'cheque', '2001', account_id('6100'));
    return $ids = cash_ids();
}
function cash_ids(): array
{
    return ['chk' => (int) one("SELECT id FROM bank_accounts WHERE name = 'SMOKE Checking'"), 'cash' => (int) one("SELECT id FROM bank_accounts WHERE name = 'SMOKE Petty cash'"), 'card' => (int) one("SELECT id FROM bank_accounts WHERE name = 'SMOKE Card'"),
            'r1' => (int) one("SELECT id FROM receipts WHERE amount = 1608.24 AND reference = 'SMOKE' ORDER BY id LIMIT 1"), 'd1' => (int) one("SELECT id FROM deposits WHERE deposit_date = '2026-05-10' ORDER BY id LIMIT 1"),
            'r4' => (int) one("SELECT id FROM receipts WHERE amount = 800.00 AND deposit_id IS NULL AND reference = 'SMOKE' ORDER BY id LIMIT 1"),
            'p1' => (int) one("SELECT id FROM payments WHERE amount = 259.80 AND payment_date = '2026-05-12' ORDER BY id LIMIT 1"), 'p2' => (int) one("SELECT id FROM payments WHERE cheque_number = '2001' ORDER BY id LIMIT 1")];
}
/** A temp CSV file; answers its path. */
function tmp_csv(string $name, string $content): string { $p = sys_get_temp_dir() . '/' . $name; file_put_contents($p, $content); return $p; }
/** A multipart POST that answers HTML (the preview page), not JSON: [status, body]. */
function upload_html(string $jar, string $path, array $form, array $files): array
{
    $tok = csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $fields = $form + ['csrf_token' => $tok];
    foreach ($files as $field => [$file, $name]) { $fields[$field] = new CURLFile($file, 'text/csv', $name); }
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
    $body = (string) curl_exec($ch);
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
}
/** A bank line straight by SQL (the proofs that need a line, not an import). Answers the id. */
function bank_line_row(int $bank, string $date, string $amount, string $desc, ?string $ref = null): int
{
    return (int) one('INSERT INTO bank_transactions (bank_account_id, txn_date, amount, description, external_ref) VALUES (:b, :d, :a, :de, :r) RETURNING id', ['b' => $bank, 'd' => $date, 'a' => $amount, 'de' => $desc, 'r' => $ref]);
}
function line_status(int $id): string { return (string) one('SELECT status FROM bank_transactions WHERE id = :i', ['i' => $id]); }
/** The five May lines of the Checking statement (the import proof makes them from the CSV; here they are made by SQL when absent). Answers [ref => id]. */
function may_lines(array $w): array
{
    $out = [];
    foreach ([['REF-1', '2026-05-06', '1608.24', 'ACME TRANSFER'], ['REF-2', '2026-05-11', '800.00', 'DEPOSIT'], ['REF-3', '2026-05-13', '-259.80', 'HOSTING CO'], ['REF-4', '2026-05-31', '-15.00', 'MONTHLY FEE'], ['REF-5', '2026-05-31', '4.10', 'INTEREST']] as [$ref, $d, $a, $desc]) {
        $id = one('SELECT id FROM bank_transactions WHERE bank_account_id = :b AND external_ref = :r', ['b' => $w['chk'], 'r' => $ref]);
        $out[$ref] = $id ? (int) $id : bank_line_row($w['chk'], $d, $a, $desc, $ref);
    }
    return $out;
}
