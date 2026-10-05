<?php
declare(strict_types=1);

/** The JSON shapes of an invoice, its lines and a list row — whitelists; never the secure link's token or hash. */

function present_invoice_row(array $i): array
{
    return ['invoice_id' => $i['invoice_id'], 'number' => $i['number'], 'customer' => ['customer_id' => $i['customer_id'] ?? null, 'name' => $i['customer_name'] ?? null], 'issue_date' => $i['issue_date'], 'due_date' => $i['due_date'], 'currency' => $i['currency'],
            'status' => $i['status'], 'total' => $i['total'], 'balance_due' => $i['balance_due'], 'overdue' => (bool) ($i['overdue'] ?? false), 'days_overdue' => (int) ($i['days_overdue'] ?? 0)];
}

function present_invoice_line(array $l): array
{
    return ['invoice_line_id' => $l['invoice_line_id'], 'sort_order' => $l['sort_order'], 'item' => $l['item_id'] === null ? null : ['item_id' => $l['item_id'], 'name' => $l['item_name'] ?? null], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
            'tax_rate' => $l['tax_rate_id'] === null ? null : ['tax_rate_id' => $l['tax_rate_id'], 'rate' => $l['tax_rate']], 'line_subtotal' => $l['line_subtotal'], 'line_tax' => $l['line_tax'], 'line_total' => $l['line_total'],
            'income_account' => ['account_id' => $l['income_account_id'], 'code' => $l['income_account_code']], 'department_id' => $l['department_id'], 'site_id' => $l['site_id']];
}

function present_invoice(array $i): array
{
    return present_invoice_row($i) + ['subtotal' => $i['subtotal'], 'tax_total' => $i['tax_total'], 'amount_paid' => $i['amount_paid'], 'amount_credited' => $i['amount_credited'], 'reference' => $i['reference'], 'memo' => $i['memo'], 'terms_text' => $i['terms_text'],
        'department' => $i['department_id'] === null ? null : ['department_id' => $i['department_id'], 'name' => $i['department_name']], 'site' => $i['site_id'] === null ? null : ['site_id' => $i['site_id'], 'name' => $i['site_name']],
        'journal_entry' => $i['journal_entry_id'] === null ? null : ['journal_entry_id' => $i['journal_entry_id'], 'number' => $i['journal_entry_number'] ?? null],
        'issued_at' => json_ts($i['issued_at']), 'sent_at' => json_ts($i['sent_at']), 'viewed_at' => json_ts($i['viewed_at']), 'paid_at' => json_ts($i['paid_at']), 'voided_at' => json_ts($i['voided_at']), 'void_reason' => $i['void_reason'], 'written_off_at' => json_ts($i['written_off_at']),
        'reminder_count' => $i['reminder_count'], 'last_reminder_at' => json_ts($i['last_reminder_at']), 'open_claims' => $i['open_claims'], 'link' => ['live' => $i['has_live_link'], 'views' => (int) ($i['link']['view_count'] ?? 0)],
        'lines' => array_map('present_invoice_line', $i['lines'] ?? []),
        'receipts' => array_map(static fn (array $r): array => ['receipt_id' => $r['receipt_id'], 'number' => $r['receipt_number'], 'amount' => $r['amount'], 'received_on' => $r['received_on'], 'method' => $r['method']], $i['receipts'] ?? []),
        'credits' => array_map(static fn (array $c): array => ['credit_note_id' => $c['credit_note_id'], 'number' => $c['credit_note_number'], 'amount' => $c['amount']], $i['credits'] ?? []),
        'claims' => array_map(static fn (array $k): array => ['claim_id' => $k['receipt_claim_id'], 'claimed_on' => $k['claimed_on'], 'method' => $k['method'], 'amount' => $k['amount'], 'status' => $k['status']], $i['claims'] ?? []),
        'created_by' => $i['created_by'] === null ? null : ['member_id' => $i['created_by'], 'name' => $i['created_by_name'], 'kind' => $i['created_by_kind']], 'attachment_count' => $i['attachment_count']];
}
