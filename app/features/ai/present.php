<?php
declare(strict_types=1);

/** The JSON shapes of the AI statement — whitelists. */
function present_ai_statement_row(array $s): array
{
    if (!($s['pulled'] ?? true)) {
        return ['pulled' => false, 'period' => $s['period'], 'kernel_status' => $s['kernel_status'], 'statement_lines' => $s['statement_lines'] ?? null];
    }
    return ['pulled' => true, 'ai_statement_id' => $s['ai_statement_id'], 'period' => $s['period'], 'kernel_status' => $s['kernel_status'], 'status' => $s['status'], 'pulled_at' => json_ts($s['pulled_at']), 'imported_at' => json_ts($s['imported_at']), 'checked_at' => json_ts($s['checked_at']),
            'late_calls' => $s['late_calls'], 'totals' => $s['totals'], 'bills' => array_map(static fn (array $b): array => ['bill_id' => $b['bill_id'], 'number' => $b['number'], 'provider' => $b['provider'], 'status' => $b['status'], 'total' => $b['total'], 'currency' => $b['currency']], $s['bills'] ?? [])];
}
