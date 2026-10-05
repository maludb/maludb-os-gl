<?php
declare(strict_types=1);

/** The JSON shapes of an entry and a line — whitelists, never a raw row. */

function present_entry(array $e): array
{
    return ['journal_entry_id' => $e['journal_entry_id'], 'number' => $e['number'], 'entry_date' => $e['entry_date'], 'memo' => $e['memo'], 'status' => $e['status'],
            'period' => $e['period_id'] === null ? null : ['fiscal_period_id' => $e['period_id'], 'name' => $e['period_name'], 'status' => $e['period_status'], 'reopened' => $e['period_reopen_count'] > 0],
            'source' => $e['source'], 'source_label' => $e['source_label'], 'document' => $e['document'] ?? ($e['source_type'] === null ? null : ['record_type' => $e['source_type'], 'record_id' => $e['source_id']]),
            'reversal_of' => $e['reversal_of'] === null ? null : ['journal_entry_id' => $e['reversal_of'], 'number' => $e['reversal_of_number']],
            'reversed_by' => $e['reversed_by'] === null ? null : ['journal_entry_id' => $e['reversed_by'], 'number' => $e['reversed_by_number']],
            'currency' => $e['currency'], 'total_debit' => $e['total_debit'], 'total_credit' => $e['total_credit'], 'total' => $e['total'],
            'balance' => $e['status'] === 'draft' ? ['debits' => $e['draft_debit'], 'credits' => $e['draft_credit'], 'difference' => $e['difference'], 'balances' => $e['balances']] : null,
            'department' => $e['department_id'] === null ? null : ['department_id' => $e['department_id'], 'name' => $e['department_name']],
            'site' => $e['site_id'] === null ? null : ['site_id' => $e['site_id'], 'name' => $e['site_name']],
            'created_by' => $e['created_by'] === null ? null : ['member_id' => $e['created_by'], 'name' => $e['created_by_name'], 'kind' => $e['created_by_kind']],
            'posted_by' => $e['posted_by'] === null ? null : ['member_id' => $e['posted_by'], 'name' => $e['posted_by_name']], 'posted_at' => json_ts($e['posted_at']),
            'voided_at' => json_ts($e['voided_at']), 'void_reason' => $e['void_reason'], 'reversal_reason' => $e['reversal_reason'],
            'line_count' => $e['line_count'], 'attachment_count' => $e['attachment_count'], 'lines' => isset($e['lines']) ? array_map('present_line', $e['lines']) : null, 'created_at' => json_ts($e['created_at'])];
}

function present_line(array $l): array
{
    return ['journal_line_id' => $l['journal_line_id'], 'line_no' => $l['line_no'], 'account' => ['account_id' => $l['account_id'], 'code' => $l['account_code'], 'name' => $l['account_name'], 'type' => $l['account_type'], 'is_control' => $l['is_control'] ?? false],
            'description' => $l['description'], 'debit' => $l['debit'], 'credit' => $l['credit'],
            'department' => $l['department_id'] === null ? null : ['department_id' => $l['department_id'], 'name' => $l['department_name']], 'site' => $l['site_id'] === null ? null : ['site_id' => $l['site_id'], 'name' => $l['site_name']],
            'customer' => $l['customer_id'] === null ? null : ['customer_id' => $l['customer_id'], 'name' => $l['customer_name']], 'vendor' => $l['vendor_id'] === null ? null : ['vendor_id' => $l['vendor_id'], 'name' => $l['vendor_name']],
            'tax_rate' => $l['tax_rate_id'] === null ? null : ['tax_rate_id' => $l['tax_rate_id'], 'name' => $l['tax_rate_name'] ?? null]];
}
