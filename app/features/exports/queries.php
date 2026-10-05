<?php
declare(strict_types=1);

/**
 * The exports (slice 9): every ledger as a file for the accountant's software. Twelve files, each from the same view or function the screens read (a file, a screen and a tool never disagree):
 * the general ledger detail, the journal, the chart with balances, the trial balance, the profit and loss, the balance sheet, the receivables and payables aging, the invoices, the bills, the receipts and the payments.
 * A flat file is one table (columns and rows); a statement is its page's table written out with its title. Requires statements/{periods,queries,present}.php.
 */

const EXPORT_FILES = [
    'ledger-detail' => 'The general ledger detail — every posted line with its entry, date, account, debit, credit, party and dimensions',
    'journal'       => 'The journal — every posted entry with its totals, status and who posted it',
    'chart'         => 'The chart of accounts with each balance as of the end date',
    'trial-balance' => 'The trial balance as of the end date',
    'pl'            => 'The profit and loss for the period',
    'balance-sheet' => 'The balance sheet as of the end date',
    'ar-aging'      => 'Receivables aging as of the end date',
    'ap-aging'      => 'Payables aging as of the end date',
    'invoices'      => 'The invoices issued in the period',
    'bills'         => 'The bills dated in the period',
    'receipts'      => 'The receipts received in the period',
    'payments'      => 'The payments dated in the period',
];

/** The flat files and the statements, apart: a flat file has a row count the page can show. */
const EXPORT_FLAT = ['ledger-detail', 'journal', 'chart', 'invoices', 'bills', 'receipts', 'payments'];

/** A flat file's table: ['columns' => [...], 'rows' => [[...]]]. $range is period_range()'s answer. */
function export_table(PDO $pdo, string $slug, array $range): array
{
    $f = $range['from'];
    $t = $range['to'];
    $q = static function (string $sql, array $args) use ($pdo): array {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_NUM);
    };
    switch ($slug) {
        case 'ledger-detail':
            return ['columns' => ['Entry', 'Date', 'Line', 'Account code', 'Account', 'Description', 'Debit', 'Credit', 'Customer', 'Vendor', 'Department', 'Site', 'Source', 'Entry status'],
                    'rows' => $q("SELECT entry_number, entry_date, line_no, account_code, account_name, description, debit, credit, customer_name, vendor_name, department_name, site_name, source, entry_status
                                    FROM mcp_journal_lines WHERE entry_status IN ('posted', 'reversed') AND entry_date BETWEEN :f AND :t ORDER BY entry_date, entry_number, line_no", ['f' => $f, 't' => $t])];
        case 'journal':
            return ['columns' => ['Entry', 'Date', 'Memo', 'Source', 'Status', 'Currency', 'Total debit', 'Total credit', 'Reverses', 'Reversed by', 'Posted by', 'Posted at'],
                    'rows' => $q("SELECT number, entry_date, memo, source, status, currency, total_debit, total_credit, reversal_of_number, reversed_by_number, posted_by_name, posted_at
                                    FROM mcp_journal_entries WHERE status IN ('posted', 'reversed') AND entry_date BETWEEN :f AND :t ORDER BY entry_date, number", ['f' => $f, 't' => $t])];
        case 'chart':
            return ['columns' => ['Code', 'Account', 'Type', 'Subtype', 'Normal balance', 'Archived', 'Balance as of ' . $range['as_of']],
                    'rows' => $q("SELECT code, name, account_type, subtype, normal_balance, CASE WHEN archived_at IS NULL THEN 'no' ELSE 'yes' END, gl_account_balance(account_id, CAST(:d AS date)) FROM mcp_accounts ORDER BY code", ['d' => $range['as_of']])];
        case 'invoices':
            return ['columns' => ['Number', 'Customer', 'Issued', 'Due', 'Currency', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Credited', 'Balance due', 'Reference'],
                    'rows' => $q("SELECT number, customer_name, issue_date, due_date, currency, status, subtotal, tax_total, total, amount_paid, amount_credited, balance_due, reference FROM mcp_invoices WHERE issue_date BETWEEN :f AND :t ORDER BY issue_date, number", ['f' => $f, 't' => $t])];
        case 'bills':
            return ['columns' => ['Number', 'Vendor', 'Vendor reference', 'Dated', 'Due', 'Currency', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Credited', 'Balance due'],
                    'rows' => $q("SELECT number, vendor_name, vendor_ref, bill_date, due_date, currency, status, subtotal, tax_total, total, amount_paid, amount_credited, balance_due FROM mcp_bills WHERE bill_date BETWEEN :f AND :t ORDER BY bill_date, number", ['f' => $f, 't' => $t])];
        case 'receipts':
            return ['columns' => ['Number', 'Customer', 'Received', 'Amount', 'Currency', 'Method', 'Reference', 'Deposited to', 'Status', 'Unapplied'],
                    'rows' => $q("SELECT number, customer_name, received_on, amount, currency, method, reference, deposit_to_bank_name, status, unapplied FROM mcp_receipts WHERE received_on BETWEEN :f AND :t ORDER BY received_on, number", ['f' => $f, 't' => $t])];
        case 'payments':
            return ['columns' => ['Number', 'Vendor', 'Dated', 'Amount', 'Currency', 'Method', 'Cheque', 'Bank account', 'Reference', 'Status', 'Applied'],
                    'rows' => $q("SELECT number, vendor_name, payment_date, amount, currency, method, cheque_number, bank_account_name, reference, status, amount_applied FROM mcp_payments WHERE payment_date BETWEEN :f AND :t ORDER BY payment_date, number", ['f' => $f, 't' => $t])];
    }
    throw new DomainException('No such file.');
}

/** What each flat file holds (for the page): [slug => rows]. */
function export_counts(PDO $pdo, array $range): array
{
    $out = [];
    foreach (EXPORT_FLAT as $slug) {
        $out[$slug] = count(export_table($pdo, $slug, $range)['rows']);
    }
    return $out;
}
