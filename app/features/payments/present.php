<?php
declare(strict_types=1);

function present_payment_row(array $p): array
{
    return ['payment_id' => $p['payment_id'], 'number' => $p['number'], 'vendor' => ['vendor_id' => $p['vendor_id'], 'name' => $p['vendor_name']], 'payment_date' => $p['payment_date'], 'amount' => $p['amount'], 'currency' => $p['currency'], 'method' => $p['method'],
            'cheque_number' => $p['cheque_number'], 'bank_account' => ['bank_account_id' => $p['bank_account_id'], 'name' => $p['bank_account_name']], 'status' => $p['status'], 'payment_run' => $p['payment_run_id'] === null ? null : ['payment_run_id' => $p['payment_run_id'], 'number' => $p['payment_run_number']],
            'amount_applied' => $p['amount_applied']];
}

function present_payment(array $p): array
{
    return present_payment_row($p) + ['reference' => $p['reference'], 'memo' => $p['memo'], 'journal_entry' => $p['journal_entry_id'] === null ? null : ['journal_entry_id' => $p['journal_entry_id'], 'number' => $p['journal_entry_number'] ?? null],
        'applications' => array_map(static fn (array $a): array => ['bill_id' => $a['bill_id'], 'number' => $a['bill_number'], 'vendor_ref' => $a['vendor_ref'], 'amount' => $a['amount']], $p['applications'] ?? []),
        'unapplied' => $p['unapplied'] ?? null, 'approved_at' => json_ts($p['approved_at']), 'recorded_at' => json_ts($p['recorded_at']), 'voided_at' => json_ts($p['voided_at']), 'void_reason' => $p['void_reason'],
        'recorded_by' => $p['recorded_by'] === null ? null : ['member_id' => $p['recorded_by'], 'name' => $p['recorded_by_name'], 'kind' => $p['recorded_by_kind']], 'created_at' => json_ts($p['created_at'])];
}
