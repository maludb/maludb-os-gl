<?php
declare(strict_types=1);

/** The JSON shapes of a bill, its lines and a list row — whitelists. */

function present_bill_row(array $b): array
{
    return ['bill_id' => $b['bill_id'], 'number' => $b['number'], 'vendor' => ['vendor_id' => $b['vendor_id'], 'name' => $b['vendor_name']], 'vendor_ref' => $b['vendor_ref'], 'bill_date' => $b['bill_date'], 'due_date' => $b['due_date'], 'currency' => $b['currency'],
            'status' => $b['status'], 'total' => $b['total'], 'amount_paid' => $b['amount_paid'], 'balance_due' => $b['balance_due'], 'overdue' => $b['overdue'], 'days_overdue' => (int) ($b['days_overdue'] ?? 0), 'has_document' => $b['has_attachment'], 'source' => $b['source']];
}

function present_bill_line(array $l): array
{
    return ['bill_line_id' => $l['bill_line_id'], 'sort_order' => $l['sort_order'], 'item' => $l['item_id'] === null ? null : ['item_id' => $l['item_id'], 'name' => $l['item_name'] ?? null], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
            'tax_rate' => $l['tax_rate_id'] === null ? null : ['tax_rate_id' => $l['tax_rate_id'], 'rate' => $l['tax_rate']], 'line_subtotal' => $l['line_subtotal'], 'line_tax' => $l['line_tax'], 'line_total' => $l['line_total'],
            'expense_account' => ['account_id' => $l['expense_account_id'], 'code' => $l['expense_account_code']], 'department_id' => $l['department_id'], 'site_id' => $l['site_id']];
}

function present_bill(array $b): array
{
    return present_bill_row($b) + ['subtotal' => $b['subtotal'], 'tax_total' => $b['tax_total'], 'amount_credited' => $b['amount_credited'], 'memo' => $b['memo'],
        'department' => $b['department_id'] === null ? null : ['department_id' => $b['department_id'], 'name' => $b['department_name']], 'site' => $b['site_id'] === null ? null : ['site_id' => $b['site_id'], 'name' => $b['site_name']],
        'journal_entry' => $b['journal_entry_id'] === null ? null : ['journal_entry_id' => $b['journal_entry_id'], 'number' => $b['journal_entry_number'] ?? null],
        'approved_at' => json_ts($b['approved_at']), 'approved_by' => $b['approved_by'] === null ? null : ['member_id' => $b['approved_by'], 'name' => $b['approved_by_name']], 'paid_at' => json_ts($b['paid_at']), 'voided_at' => json_ts($b['voided_at']), 'void_reason' => $b['void_reason'],
        'lines' => array_map('present_bill_line', $b['lines'] ?? []),
        'payments' => array_map(static fn (array $p): array => ['payment_id' => $p['payment_id'], 'number' => $p['payment_number'], 'amount' => $p['amount'], 'payment_date' => $p['payment_date'], 'method' => $p['method'], 'status' => $p['payment_status']], $b['payments'] ?? []),
        'credits' => array_map(static fn (array $c): array => ['vendor_credit_id' => $c['vendor_credit_id'], 'number' => $c['vendor_credit_number'], 'amount' => $c['amount']], $b['credits'] ?? []),
        'created_by' => $b['created_by'] === null ? null : ['member_id' => $b['created_by'], 'name' => $b['created_by_name'], 'kind' => $b['created_by_kind']], 'attachment_count' => $b['attachment_count']];
}
