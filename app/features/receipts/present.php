<?php
declare(strict_types=1);

function present_receipt_row(array $r): array
{
    return ['receipt_id' => $r['receipt_id'], 'number' => $r['number'], 'customer' => ['customer_id' => $r['customer_id'], 'name' => $r['customer_name']], 'received_on' => $r['received_on'], 'amount' => $r['amount'], 'currency' => $r['currency'],
            'method' => $r['method'], 'reference' => $r['reference'], 'status' => $r['status'], 'unapplied' => $r['unapplied'], 'deposit' => $r['deposit_id'] === null ? null : ['deposit_id' => $r['deposit_id'], 'number' => $r['deposit_number']],
            'deposit_to' => $r['deposit_to_bank_account_id'] === null ? null : ['bank_account_id' => $r['deposit_to_bank_account_id'], 'name' => $r['deposit_to_bank_name']], 'undeposited' => $r['status'] === 'recorded' && $r['deposit_id'] === null && $r['deposit_to_bank_account_id'] === null];
}

function present_receipt(array $r): array
{
    return present_receipt_row($r) + ['memo' => $r['memo'], 'journal_entry' => $r['journal_entry_id'] === null ? null : ['journal_entry_id' => $r['journal_entry_id'], 'number' => $r['journal_entry_number'] ?? null],
        'applications' => array_map(static fn (array $a): array => ['invoice_id' => $a['invoice_id'], 'number' => $a['invoice_number'], 'amount' => $a['amount']], $r['applications'] ?? []),
        'claim' => $r['claim'] === null ? null : ['claim_id' => (int) $r['claim']['receipt_claim_id'], 'status' => $r['claim']['status']], 'voided_at' => json_ts($r['voided_at']), 'void_reason' => $r['void_reason'],
        'recorded_by' => $r['recorded_by'] === null ? null : ['member_id' => $r['recorded_by'], 'name' => $r['recorded_by_name'], 'kind' => $r['recorded_by_kind']], 'created_at' => json_ts($r['created_at'])];
}
