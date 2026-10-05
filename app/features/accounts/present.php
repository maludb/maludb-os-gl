<?php
declare(strict_types=1);

/** The JSON shapes of an account and its activity — whitelists, never a raw row. */

function present_account(array $a): array
{
    return ['account_id' => $a['account_id'], 'code' => $a['code'], 'name' => $a['name'], 'label' => $a['code'] . ' — ' . $a['name'], 'account_type' => $a['account_type'], 'type_label' => $a['type_label'],
            'normal_balance' => $a['normal_balance'], 'subtype' => $a['subtype'], 'parent' => $a['parent_id'] === null ? null : ['account_id' => $a['parent_id'], 'code' => $a['parent_code'], 'name' => $a['parent_name']],
            'is_bank' => $a['is_bank'], 'is_control' => $a['is_control'], 'is_system' => $a['is_system'], 'description' => $a['description'], 'archived' => $a['archived_at'] !== null,
            'child_count' => $a['child_count'], 'balance' => $a['balance'] ?? null, 'balance_as_of' => $a['balance_as_of'] ?? null];
}

function present_account_activity(array $r): array
{
    return ['journal_entry_id' => (int) $r['entry_id'], 'number' => $r['entry_number'], 'date' => $r['entry_date'], 'source' => $r['source'], 'memo' => $r['memo'], 'description' => $r['description'],
            'debit' => $r['debit'], 'credit' => $r['credit'], 'running_balance' => $r['running_balance'],
            'department_id' => $r['department_id'] === null ? null : (int) $r['department_id'], 'site_id' => $r['site_id'] === null ? null : (int) $r['site_id'],
            'customer_id' => $r['customer_id'] === null ? null : (int) $r['customer_id'], 'vendor_id' => $r['vendor_id'] === null ? null : (int) $r['vendor_id']];
}
