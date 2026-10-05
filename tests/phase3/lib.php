<?php
/**
 * Helpers shared by the slice 3+ proofs: world() rebuilds, by SQL through the writer functions, the Phase 0 proof's books on the scratch database (the slices 4–7 have not built
 * their screens yet, so a statement's data is made the way the proof made it). Idempotent. Every row is named "SMOKE …".
 *   opening 2026-01-01: DR 1010 10,000 · DR 1200 1,000 (Acme) · CR 3000 11,000          (the 1,000 of receivables with no invoice behind it)
 *   INV-00001 Acme 2026-04-01 (IT? no — Front Office 6, the site) 1,500 + 99.99 + 8.25 tax = 1,608.24; receipt of 1,000 banked 2026-04-21, applied
 *   INV-00002 Beta 2026-06-01 500; CN-00001 100 applied; hosting bill 2026-04-05 259.80 (IT, dept 5); rent bills 2026-05-01 and 2026-06-01 2,000 each
 * Hand figures: income 1,999.99 · expenses 4,259.80 (gross) · net income −2,259.81 · AR documents 1,008.24 against 2,008.24 in the ledger.
 */
require __DIR__ . '/slice2/lib.php';

function world(): array
{
    if (one("SELECT 1 FROM customers WHERE name = 'SMOKE Acme'")) {
        return world_ids();
    }
    ensure_year(as_member(1));
    pdo()->exec("UPDATE gl_settings SET business_name = 'SMOKE Co', business_address = '1 Main St'");
    q("INSERT INTO sites (name) VALUES ('SMOKE Main Street')");
    $chk = (int) one("INSERT INTO bank_accounts (name, kind, account_id, currency, opening_balance, opening_date, created_by) VALUES ('SMOKE Checking', 'checking', :a, 'USD', 10000, '2026-01-01', 1) RETURNING id", ['a' => account_id('1010')]);
    $tax = (int) one("INSERT INTO tax_rates (name, rate) VALUES ('SMOKE Sales tax 8.25%', 8.25) RETURNING id");
    $acme = (int) one("INSERT INTO customers (name, email, terms_days, created_by) VALUES ('SMOKE Acme', 'ap@acme.invalid', 30, 27) RETURNING id");
    $beta = (int) one("INSERT INTO customers (name, email, terms_days, created_by) VALUES ('SMOKE Beta', 'ap@beta.invalid', 15, 27) RETURNING id");
    $hosting = (int) one("INSERT INTO vendors (name, email, terms_days, created_by) VALUES ('SMOKE Hosting Co', 'billing@hosting.invalid', 30, 27) RETURNING id");
    $landlord = (int) one("INSERT INTO vendors (name, terms_days, created_by) VALUES ('SMOKE Landlord', 0, 27) RETURNING id");
    $site = (int) one("SELECT id FROM sites WHERE name = 'SMOKE Main Street'");
    // the opening entry (a manual entry; the control line names its customer)
    $je = (int) one("INSERT INTO journal_entries (entry_date, memo, source, created_by) VALUES ('2026-01-01', 'SMOKE opening balances', 'opening', 1) RETURNING id");
    foreach ([[1, '1010', '10000', '0', null], [2, '1200', '1000', '0', $acme], [3, '3000', '0', '11000', null]] as [$n, $code, $dr, $cr, $cust]) {
        q('INSERT INTO journal_lines (entry_id, line_no, account_id, debit, credit, customer_id) VALUES (:e, :n, :a, :d, :c, :cu)', ['e' => $je, 'n' => $n, 'a' => account_id($code), 'd' => $dr, 'c' => $cr, 'cu' => $cust]);
    }
    one('SELECT gl_entry_post(:e, 1)', ['e' => $je]);
    // INV-00001 (department 6, the site)
    $inv1 = (int) one("INSERT INTO invoices (customer_id, issue_date, due_date, currency, department_id, site_id, created_by) VALUES (:c, '2026-04-01', '2026-05-01', 'USD', 6, :s, 27) RETURNING id", ['c' => $acme, 's' => $site]);
    q("INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, tax_rate_id, income_account_id) VALUES (:i, 1, 'Consulting, 10 h', 10, 150.00, NULL, :a)", ['i' => $inv1, 'a' => account_id('4100')]);
    q("INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, tax_rate_id, income_account_id) VALUES (:i, 2, 'Hardware', 3, 33.33, :t, :a)", ['i' => $inv1, 't' => $tax, 'a' => account_id('4000')]);
    one('SELECT gl_invoice_issue(:i, 27)', ['i' => $inv1]);
    $rct = (int) one("INSERT INTO receipts (customer_id, received_on, amount, currency, method, reference, deposit_to_bank_account_id, recorded_by) VALUES (:c, '2026-04-21', 1000, 'USD', 'transfer', 'ACME-7781', :b, 27) RETURNING id", ['c' => $acme, 'b' => $chk]);
    one('SELECT gl_receipt_post(:r, 27)', ['r' => $rct]);
    q('INSERT INTO receipt_applications (receipt_id, invoice_id, amount, applied_by) VALUES (:r, :i, 1000, 27)', ['r' => $rct, 'i' => $inv1]);
    // INV-00002 and its credit note
    $inv2 = (int) one("INSERT INTO invoices (customer_id, issue_date, due_date, currency, created_by) VALUES (:c, '2026-06-01', '2026-06-16', 'USD', 27) RETURNING id", ['c' => $beta]);
    q("INSERT INTO invoice_lines (invoice_id, sort_order, description, quantity, unit_price, income_account_id) VALUES (:i, 1, 'Retainer', 1, 500, :a)", ['i' => $inv2, 'a' => account_id('4100')]);
    one('SELECT gl_invoice_issue(:i, 27)', ['i' => $inv2]);
    $cn = (int) one("INSERT INTO credit_notes (customer_id, invoice_id, issue_date, currency, reason, created_by) VALUES (:c, :i, '2026-06-10', 'USD', 'SMOKE goodwill', 27) RETURNING id", ['c' => $beta, 'i' => $inv2]);
    q("INSERT INTO credit_note_lines (credit_note_id, sort_order, description, quantity, unit_price, income_account_id) VALUES (:n, 1, 'Goodwill', 1, 100, :a)", ['n' => $cn, 'a' => account_id('4100')]);
    one('SELECT gl_credit_note_issue(:n, 27)', ['n' => $cn]);
    q('INSERT INTO credit_applications (credit_note_id, invoice_id, amount, applied_by) VALUES (:n, :i, 100, 27)', ['n' => $cn, 'i' => $inv2]);
    // the bills
    $b1 = (int) one("INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, department_id, created_by) VALUES (:v, 'H-2026-04', '2026-04-05', '2026-05-05', 'USD', 5, 27) RETURNING id", ['v' => $hosting]);
    q("INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, tax_rate_id, expense_account_id) VALUES (:b, 1, 'April hosting', 1, 240.00, :t, :a)", ['b' => $b1, 't' => $tax, 'a' => account_id('6200')]);
    one('SELECT gl_bill_approve(:b, 28)', ['b' => $b1]);
    foreach ([['RENT-05', '2026-05-01'], ['RENT-06', '2026-06-01']] as [$ref, $d]) {
        $b = (int) one("INSERT INTO bills (vendor_id, vendor_ref, bill_date, due_date, currency, created_by) VALUES (:v, :r, :d, :d, 'USD', 27) RETURNING id", ['v' => $landlord, 'r' => $ref, 'd' => $d]);
        q("INSERT INTO bill_lines (bill_id, sort_order, description, quantity, unit_price, expense_account_id) VALUES (:b, 1, 'Rent', 1, 2000, :a)", ['b' => $b, 'a' => account_id('6100')]);
        one('SELECT gl_bill_approve(:b, 28)', ['b' => $b]);
    }
    return world_ids();
}

function world_ids(): array
{
    return ['acme' => (int) one("SELECT id FROM customers WHERE name = 'SMOKE Acme'"), 'beta' => (int) one("SELECT id FROM customers WHERE name = 'SMOKE Beta'"),
            'hosting' => (int) one("SELECT id FROM vendors WHERE name = 'SMOKE Hosting Co'"), 'landlord' => (int) one("SELECT id FROM vendors WHERE name = 'SMOKE Landlord'"),
            'site' => (int) one("SELECT id FROM sites WHERE name = 'SMOKE Main Street'"), 'inv1' => (int) one("SELECT id FROM invoices WHERE number = 'INV-00001'"), 'inv2' => (int) one("SELECT id FROM invoices WHERE number = 'INV-00002'")];
}

/** Posted lines summed straight from the tables (the proof's independent figure): debits − credits of the accounts of a type over a range. */
function direct_net(string $type, string $from, string $to): string
{
    return (string) one("SELECT COALESCE(sum(l.debit - l.credit), 0) FROM journal_lines l JOIN journal_entries e ON e.id = l.entry_id AND e.status IN ('posted', 'reversed') JOIN accounts a ON a.id = l.account_id
                         WHERE a.account_type = :t AND e.source <> 'closing' AND e.entry_date BETWEEN :f AND :t2", ['t' => $type, 'f' => $from, 't2' => $to]);
}
