<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'], 'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null,
            'url' => notification_record_url($n['record_type'], $n['record_id'] !== null ? (int) $n['record_id'] : null),
            'title' => $n['title'], 'body' => $n['body'], 'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

// ---- slice 1: the ledger's configuration ---------------------------------------------------------------------------------
function present_settings(array $s): array
{
    $out = array_intersect_key($s, array_flip(SETTINGS_KEYS));
    $out['updated_at'] = json_ts($s['updated_at'] ?? null);
    return $out;
}

function present_numbering(array $n): array
{
    return ['kind' => $n['kind'], 'label' => $n['label'], 'prefix' => $n['prefix'], 'next_value' => $n['next_value'], 'padding' => $n['padding'], 'preview' => $n['preview']];
}

function present_tax_rate(array $t): array
{
    return ['tax_rate_id' => $t['tax_rate_id'], 'name' => $t['name'], 'rate' => $t['rate'], 'is_default' => $t['is_default'], 'archived' => $t['archived_at'] !== null];
}

function present_item(array $i): array
{
    return ['item_id' => $i['item_id'], 'code' => $i['code'], 'name' => $i['name'], 'kind' => $i['kind'], 'description' => $i['description'], 'unit' => $i['unit'], 'unit_price' => $i['unit_price'],
            'income_account' => $i['income_account_id'] === null ? null : ['account_id' => $i['income_account_id'], 'code' => $i['income_account_code'], 'name' => $i['income_account_name']],
            'expense_account' => $i['expense_account_id'] === null ? null : ['account_id' => $i['expense_account_id'], 'code' => $i['expense_account_code'], 'name' => $i['expense_account_name']],
            'tax_rate' => $i['tax_rate_id'] === null ? null : ['tax_rate_id' => $i['tax_rate_id'], 'name' => $i['tax_rate_name'], 'rate' => $i['tax_rate']], 'archived' => $i['archived_at'] !== null];
}

function present_posting_rule(array $r): array
{
    $acct = static fn (?int $id, ?string $code, ?string $name): ?array => $id === null ? null : ['account_id' => $id, 'code' => $code, 'name' => $name];
    return ['posting_rule_id' => $r['posting_rule_id'], 'source' => $r['source'], 'source_label' => $r['source_label'], 'match_key' => $r['match_key'],
            'debit_account' => $acct($r['debit_account_id'], $r['debit_account_code'], $r['debit_account_name']), 'credit_account' => $acct($r['credit_account_id'], $r['credit_account_code'], $r['credit_account_name']),
            'tax_account' => $acct($r['tax_account_id'], $r['tax_account_code'], $r['tax_account_name'] ?? null), 'note' => $r['note'], 'active' => $r['active']];
}

function present_site(array $s): array
{
    return ['site_id' => $s['site_id'], 'name' => $s['name'], 'kernel_location_id' => $s['kernel_location_id'], 'address' => $s['address'], 'archived' => $s['archived_at'] !== null];
}

function present_agent_here(array $a): array
{
    $d = $a['last_dispatch'];
    return ['member_id' => $a['member_id'], 'name' => $a['display_name'], 'job_title' => $a['job_title'], 'roles' => $a['roles'], 'last_morning_note' => json_ts($a['last_morning_note'] ?? null),
            'last_dispatch' => $d === null ? null : ['kind' => $d['kind'], 'via' => $d['via'], 'status' => $d['status'], 'record_type' => $d['record_type'], 'record_id' => $d['record_id'] === null ? null : (int) $d['record_id'], 'created_at' => json_ts($d['created_at']), 'answered_at' => json_ts($d['answered_at'])]];
}
