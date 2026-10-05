<?php
declare(strict_types=1);

/** The audit page's JSON (slice 9): whitelists only. */

function present_audit(array $a): array
{
    $ts = static fn (?string $t): ?string => json_ts($t);
    return [
        'range' => $a['range'],
        'reopened_periods' => array_map(static fn (array $p): array => ['fiscal_period_id' => $p['fiscal_period_id'], 'name' => $p['name'], 'status' => $p['status'], 'reopen_count' => (int) $p['reopen_count'],
            'reopenings' => array_map(static fn (array $r): array => ['at' => json_ts($r['occurred_at']), 'by' => $r['actor_name'], 'reason' => $r['reason']], $p['reopenings']),
            'posted_after_reopen' => array_map(static fn (array $x): array => ['journal_entry_id' => (int) $x['journal_entry_id'], 'number' => $x['number'], 'amount' => $x['amount'], 'currency' => $x['currency'], 'entry_date' => $x['entry_date'], 'by' => $x['actor_name'], 'at' => json_ts($x['occurred_at'])], $p['posts'])], $a['reopened']),
        'reversals' => array_map(static fn (array $r): array => ['journal_entry_id' => (int) $r['journal_entry_id'], 'number' => $r['number'], 'entry_date' => $r['entry_date'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'reason' => $r['reversal_reason'],
            'reversal_number' => $r['reversal_number'], 'by' => $r['reversed_by_name'], 'at' => json_ts($r['reversed_at'])], $a['reversals']),
        'voids' => array_map(static fn (array $v): array => ['journal_entry_id' => (int) $v['journal_entry_id'], 'number' => $v['number'], 'amount' => $v['amount'], 'currency' => $v['currency'], 'reason' => $v['void_reason'], 'by' => $v['voided_by_name'], 'at' => json_ts($v['voided_at'])], $a['voids']),
        'ai_statements' => array_map(static fn (array $s): array => ['ai_statement_id' => $s['ai_statement_id'], 'period' => $s['period'], 'status' => $s['status'], 'checked_at' => json_ts($s['checked_at']), 'differences' => $s['differences']], $a['ai']),
        'control_check' => array_map(static fn (array $c): array => ['control' => $c['control'], 'subledger' => $c['subledger'], 'ledger' => $c['ledger'], 'difference' => $c['difference']], $a['control']),
        'control_lines' => array_map(static fn (array $l): array => ['journal_entry_id' => (int) $l['journal_entry_id'], 'number' => $l['entry_number'], 'entry_date' => $l['entry_date'], 'source' => $l['source'], 'account' => $l['account_code'], 'debit' => $l['debit'], 'credit' => $l['credit'], 'description' => $l['description']], $a['control_lines']),
        'posted_after_close' => $a['posted_after_close'],
    ];
}
