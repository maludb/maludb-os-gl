<?php
declare(strict_types=1);

/** The JSON shapes of cash — whitelists. */
function present_bank_account(array $b): array
{
    return ['bank_account_id' => $b['bank_account_id'], 'name' => $b['name'], 'kind' => $b['kind'], 'ledger_account' => ['account_id' => $b['account_id'], 'code' => $b['account_code']], 'institution' => $b['institution'], 'account_last4' => $b['account_last4'], 'currency' => $b['currency'],
            'opening_balance' => $b['opening_balance'], 'opening_date' => $b['opening_date'], 'ledger_balance' => $b['ledger_balance'], 'cleared_balance' => $b['cleared_balance'], 'difference' => $b['difference'], 'unmatched_lines' => $b['unmatched_lines'],
            'last_reconciled_through' => $b['last_reconciled_through'], 'last_reconciled_balance' => $b['last_reconciled_balance'], 'reconciliation_in_progress_id' => $b['reconciliation_in_progress_id'], 'archived' => $b['archived_at'] !== null];
}

function present_bank_line(array $l, ?array $matched = null): array
{
    return ['bank_transaction_id' => $l['bank_transaction_id'], 'bank_account_id' => $l['bank_account_id'], 'txn_date' => $l['txn_date'], 'amount' => $l['amount'], 'description' => $l['description'], 'counterparty' => $l['counterparty'], 'reference' => $l['external_ref'],
            'balance_after' => $l['balance_after'], 'status' => $l['status'], 'matched' => $matched === null ? null : ['item_type' => $matched['type'], 'item_id' => $matched['id'], 'label' => $matched['label']], 'ignore_reason' => $l['ignore_reason'], 'reconciled' => $l['reconciliation_id'] !== null, 'import_id' => $l['import_id']];
}

function present_candidate(array $c): array
{
    return ['item_type' => $c['item_type'], 'item_id' => $c['item_id'], 'number' => $c['number'], 'date' => $c['item_date'], 'amount' => $c['amount'], 'description' => $c['description'], 'days_apart' => $c['days_apart']];
}

function present_reconciliation(array $r): array
{
    return ['reconciliation_id' => $r['bank_reconciliation_id'], 'bank_account' => ['bank_account_id' => $r['bank_account_id'], 'name' => $r['bank_account_name']], 'statement_date' => $r['statement_date'], 'statement_balance' => $r['statement_balance'], 'opening_balance' => $r['opening_balance'],
            'cleared_balance' => $r['cleared_balance'], 'difference' => $r['difference'], 'ledger_balance' => $r['ledger_balance'], 'status' => $r['status'], 'completed_at' => json_ts($r['completed_at']), 'completed_by' => $r['completed_by_name'] ?? null, 'reopened_at' => json_ts($r['reopened_at']),
            'reopen_reason' => $r['reopen_reason'], 'note' => $r['note'], 'lines' => $r['line_count']];
}

function present_transfer(array $t): array
{
    return ['transfer_id' => $t['transfer_id'], 'number' => $t['number'], 'from' => ['bank_account_id' => $t['from_bank_account_id'], 'name' => $t['from_bank_name']], 'to' => ['bank_account_id' => $t['to_bank_account_id'], 'name' => $t['to_bank_name']], 'transfer_date' => $t['transfer_date'],
            'amount' => $t['amount'], 'currency' => $t['currency'], 'memo' => $t['memo'], 'status' => $t['status'], 'journal_entry' => $t['journal_entry_id'] === null ? null : ['journal_entry_id' => $t['journal_entry_id'], 'number' => $t['journal_entry_number'] ?? null], 'void_reason' => $t['void_reason'] ?? null,
            'matched_lines' => array_map(static fn (array $m): array => ['bank_transaction_id' => $m['bank_transaction_id'], 'bank_account_id' => $m['bank_account_id'], 'txn_date' => $m['txn_date'], 'amount' => $m['amount']], $t['matched_lines'] ?? [])];
}
