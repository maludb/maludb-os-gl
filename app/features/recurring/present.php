<?php
declare(strict_types=1);

/** The JSON shapes of recurring templates (slice 8): whitelists, never a raw row. */

function present_template_row(array $t): array
{
    return ['recurring_template_id' => $t['recurring_template_id'], 'name' => $t['name'], 'kind' => $t['kind'], 'cadence' => $t['cadence'], 'every_n' => $t['every_n'], 'cadence_words' => cadence_words($t['cadence'], $t['every_n']),
            'next_date' => $t['next_date'], 'end_date' => $t['end_date'], 'active' => $t['active'], 'run_count' => $t['run_count'], 'last_run_at' => json_ts($t['last_run_at'] ?? null),
            'last_status' => $t['last_status'] ?? null, 'last_detail' => $t['last_detail'] ?? null, 'url' => '/settings/recurring/' . $t['recurring_template_id']];
}

function present_template(array $t): array
{
    return present_template_row($t) + ['template' => $t['template'], 'last_failed' => $t['last_failed'] ?? null,
        'runs' => array_map(static fn (array $r): array => ['recurring_run_id' => $r['recurring_run_id'], 'run_date' => $r['run_date'], 'status' => $r['status'], 'detail' => $r['detail'], 'record_type' => $r['record_type'], 'record_id' => $r['record_id'], 'record_url' => $r['record_url'], 'created_at' => json_ts($r['created_at'])], $t['runs'] ?? [])];
}
