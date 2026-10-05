<?php
declare(strict_types=1);

/** The JSON shapes of a fiscal year and a period — whitelists, never a raw row. */

function present_period(array $p): array
{
    return ['fiscal_period_id' => $p['fiscal_period_id'], 'fiscal_year_id' => $p['fiscal_year_id'], 'name' => $p['name'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date'], 'status' => $p['status'],
            'closed_at' => json_ts($p['closed_at']), 'closed_by' => $p['closed_by'] === null ? null : ['member_id' => $p['closed_by'], 'name' => $p['closed_by_name']],
            'reopened_at' => json_ts($p['reopened_at']), 'reopened_by' => $p['reopened_by'] === null ? null : ['member_id' => $p['reopened_by'], 'name' => $p['reopened_by_name']],
            'reopen_reason' => $p['reopen_reason'], 'reopen_count' => $p['reopen_count'], 'posted_entries' => $p['posted_entries'], 'draft_entries' => $p['draft_entries']];
}

function present_fiscal_year(array $y): array
{
    return ['fiscal_year_id' => $y['fiscal_year_id'], 'name' => $y['name'], 'start_date' => $y['start_date'], 'end_date' => $y['end_date'], 'status' => $y['status'],
            'closing_entry' => $y['closing_entry_id'] === null ? null : ['journal_entry_id' => $y['closing_entry_id'], 'number' => $y['closing_entry_number'] ?? null],
            'closed_at' => json_ts($y['closed_at']), 'closed_by' => $y['closed_by'] === null ? null : ['member_id' => $y['closed_by'], 'name' => $y['closed_by_name'] ?? null],
            'reopened_at' => json_ts($y['reopened_at']), 'open_periods' => $y['open_periods'], 'periods' => array_map('present_period', $y['periods'] ?? [])];
}
