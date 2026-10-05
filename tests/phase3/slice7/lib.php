<?php
/**
 * Helpers for the slice 7 proofs (docs/build-specs/ai-statement.md, "Proof"): the fake kernel (tests/fake_kernel.php) answers /api/v1/ledger/periods.php from its state file; ledger_state() writes what it says.
 * The documents are the Phase 0 proof's figures: September closed — Anthropic 12.3456 + 7.6549 + 0.0049 = 20.0054 (→ 20.01), OpenAI 3.0000, total 23.0054; October open.
 */
require dirname(__DIR__) . '/slice6/lib.php';

function ai_line(string $provider, string $model, ?string $dept, string $agent, int $calls, int $late, string $amount, ?string $note = null): array
{
    return ['provider' => $provider, 'model' => $model, 'department' => $dept, 'agent' => $agent, 'application' => null, 'calls' => $calls, 'late_calls' => $late, 'input_tokens' => $calls * 10, 'output_tokens' => $calls * 5, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'amount' => $amount, 'currency' => 'USD', 'note' => $note];
}
function ai_doc(string $period, string $status, array $lines): array
{
    $tot = '0';
    $sum = 0;
    foreach ($lines as $l) { $sum += (int) round((float) $l['amount'] * 10000); }
    [$y, $m] = explode('-', $period);
    return ['schema' => 'os.ledger-period/1', 'business' => 'SMOKE Co', 'period' => $period, 'period_start' => "$period-01", 'period_end' => date('Y-m-t', strtotime("$period-01")), 'status' => $status, 'closed_at' => $status === 'closed' ? "$period-28T10:00:00Z" : null, 'rolled_up_at' => null,
            'currencies' => ['USD'], 'exchange' => 'none — each line in the currency the provider billed; nothing is converted', 'amount_scale' => 4, 'generated_at' => '2026-10-02T08:00:00Z', 'lines' => $lines,
            'totals' => [['currency' => 'USD', 'calls' => array_sum(array_column($lines, 'calls')), 'late_calls' => array_sum(array_column($lines, 'late_calls')), 'amount' => sprintf('%d.%04d', intdiv($sum, 10000), $sum % 10000)]]];
}
function sept_doc(): array
{
    return ai_doc('2026-09', 'closed', [ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 120, 0, '12.3456'), ai_line('anthropic', 'claude-sonnet-5-5', 'IT', 'Sysadmin', 80, 2, '7.6549', '2 late calls'), ai_line('anthropic', 'claude-opus-5-5', 'Accounting', 'Jack', 10, 0, '0.0049'), ai_line('openai', 'gpt-5', 'Front Office', 'Seamus', 30, 0, '3.0000')]);
}
function oct_doc(): array { return ai_doc('2026-10', 'open', [ai_line('anthropic', 'claude-sonnet-5-5', 'Accounting', 'Sasha', 15, 0, '1.5000')]); }
function periods_doc(array $docs): array
{
    return ['schema' => 'os.ledger-periods/1', 'periods' => array_map(fn (array $d): array => ['period' => $d['period'], 'period_start' => $d['period_start'], 'period_end' => $d['period_end'], 'status' => $d['status'], 'closed_at' => $d['closed_at'], 'statement_lines' => count($d['lines']), 'ledger_calls' => array_sum(array_column($d['lines'], 'calls'))], $docs)];
}
/** Write what the fake kernel says: the documents (period => document), the mode (ok | unauthorized | down). */
function ledger_state(array $docs, string $mode = 'ok'): void
{
    kernel_state(function (array $st) use ($docs, $mode) { $st['ledger_documents'] = $docs; $st['ledger_periods'] = periods_doc(array_values($docs)); $st['ledger_mode'] = $mode; return $st; });
}
function stmt_id(string $period): int { return (int) one('SELECT id FROM ai_statements WHERE period = :p', ['p' => $period]); }
