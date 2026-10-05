<?php
declare(strict_types=1);

/**
 * The record map (slice 2; every later slice extends it): a record type → its mcp_* view and id column (the database decides what the caller
 * sees), the label a timeline or a source link shows, and the page it lives on. Attachments, notes and the activity trail are generic over it.
 */

/** record_type → [view, id column, label SQL (over the view, aliased r), url pattern] */
const RECORD_VIEWS = [
    'journal_entry'  => ['mcp_journal_entries', 'journal_entry_id', "COALESCE(r.number, 'Draft #' || r.journal_entry_id)", '/journal/{id}'],
    'invoice'        => ['mcp_invoices', 'invoice_id', "COALESCE(r.number, 'Draft invoice #' || r.invoice_id)", '/invoices/{id}'],
    'credit_note'    => ['mcp_credit_notes', 'credit_note_id', "COALESCE(r.number, 'Draft credit note #' || r.credit_note_id)", '/credit-notes/{id}'],
    'receipt'        => ['mcp_receipts', 'receipt_id', 'r.number', '/receipts/{id}'],
    'deposit'        => ['mcp_deposits', 'deposit_id', 'r.number', '/deposits/{id}'],
    'bill'           => ['mcp_bills', 'bill_id', 'r.number', '/bills/{id}'],
    'vendor_credit'  => ['mcp_vendor_credits', 'vendor_credit_id', 'r.number', '/vendor-credits/{id}'],
    'payment'        => ['mcp_payments', 'payment_id', 'r.number', '/payments/{id}'],
    'payment_run'    => ['mcp_payment_runs', 'payment_run_id', 'r.number', '/payment-runs/{id}'],
    'transfer'       => ['mcp_transfers', 'transfer_id', 'r.number', '/cash/transfers/{id}'],
    'reconciliation' => ['mcp_bank_reconciliations', 'bank_reconciliation_id', "'Reconciliation #' || r.bank_reconciliation_id", '/cash/reconcile?reconciliation={id}'],
    'customer'       => ['mcp_customers', 'customer_id', 'r.name', '/customers/{id}'],
    'vendor'         => ['mcp_vendors', 'vendor_id', 'r.name', '/vendors/{id}'],
    'ai_statement'   => ['mcp_ai_statements', 'ai_statement_id', "'AI statement ' || r.period", '/ap/ai/{id}'],
    'fiscal_period'  => ['mcp_fiscal_periods', 'fiscal_period_id', "'Period ' || r.name", '/periods/{id}'],
    'bank_account'   => ['mcp_bank_accounts', 'bank_account_id', 'r.name', '/cash/banks/{id}'],
    'recurring_run'  => ['mcp_recurring_runs', 'recurring_run_id', "'Recurring run #' || r.recurring_run_id", '/settings/recurring/'],
];

/** The record types that take an attachment (db/009's CHECK). */
const ATTACHABLE_TYPES = ['journal_entry', 'invoice', 'credit_note', 'receipt', 'deposit', 'bill', 'vendor_credit', 'payment', 'payment_run', 'transfer', 'reconciliation', 'customer', 'vendor', 'ai_statement'];

function record_known(string $type): bool
{
    return isset(RECORD_VIEWS[$type]);
}

/** True when the caller sees the record through its view (an unknown type or id: false). */
function record_visible(PDO $pdo, string $type, int $id): bool
{
    if (!isset(RECORD_VIEWS[$type])) {
        return false;
    }
    [$view, $col] = RECORD_VIEWS[$type];
    try {
        $st = $pdo->prepare("SELECT 1 FROM {$view} WHERE {$col} = :id");
        $st->execute(['id' => $id]);
        return $st->fetchColumn() !== false;
    } catch (PDOException) {
        return false;                                  // a view a later slice has not created yet
    }
}

/** The record's label (its number or name) when visible, else null. */
function record_label(PDO $pdo, string $type, int $id): ?string
{
    if (!isset(RECORD_VIEWS[$type])) {
        return null;
    }
    [$view, $col, $label] = RECORD_VIEWS[$type];
    try {
        $st = $pdo->prepare("SELECT {$label} FROM {$view} r WHERE r.{$col} = :id");
        $st->execute(['id' => $id]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    } catch (PDOException) {
        return null;
    }
}

function record_url(string $type, int $id): string
{
    return str_replace('{id}', (string) $id, RECORD_VIEWS[$type][3] ?? '/');
}

/** The words for a record type ("invoice", "credit note"). */
function record_words(string $type): string
{
    return str_replace('_', ' ', $type);
}
