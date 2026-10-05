<?php
declare(strict_types=1);

/**
 * Statement periods (slice 3): a fiscal period or year by name, or custom from/to (as_of for the balance sheet and trial balance),
 * and the two comparison ranges. Dates are ISO strings; a bad one is a 422 in words.
 */

const COMPARE_MODES = ['' => 'None', 'previous' => 'The previous period', 'last_year' => 'The same period last year'];

function valid_date_param(string $name): ?string
{
    $v = request_date($name);
    if ($v === false) {
        refuse(422, 'A date is YYYY-MM-DD (' . $name . ').');
    }
    return $v;
}

/**
 * The range a request names: `period` (a fiscal period name like 2026-09 or a fiscal year name like FY2026), else `from`/`to`, else `as_of`;
 * default the fiscal period covering today, or this calendar month when no fiscal year covers it.
 * Answers ['from', 'to', 'as_of', 'label', 'period' (the select's value or 'custom'), 'custom' => bool].
 */
function period_range(PDO $pdo, array $params): array
{
    $period = trim((string) ($params['period'] ?? ''));
    $from = ($params['from'] ?? '') !== '' ? (string) $params['from'] : null;
    $to = ($params['to'] ?? '') !== '' ? (string) $params['to'] : null;
    $asOf = ($params['as_of'] ?? '') !== '' ? (string) $params['as_of'] : null;
    if ($period !== '' && $period !== 'custom') {
        $st = $pdo->prepare('SELECT name, start_date, end_date FROM mcp_fiscal_periods WHERE name = :n');
        $st->execute(['n' => $period]);
        $p = $st->fetch();
        if ($p === false) {
            $st = $pdo->prepare('SELECT name, start_date, end_date FROM mcp_fiscal_years WHERE name = :n');
            $st->execute(['n' => $period]);
            $p = $st->fetch();
        }
        if ($p === false) {
            refuse(422, 'No fiscal period or year is called ' . $period . '.');
        }
        return ['from' => $p['start_date'], 'to' => $p['end_date'], 'as_of' => $asOf ?? $p['end_date'], 'label' => $p['name'], 'period' => $p['name'], 'custom' => false];
    }
    if ($from !== null || $to !== null || $asOf !== null || $period === 'custom') {
        $today = date('Y-m-d');
        $to = $to ?? $asOf ?? $today;
        $from = $from ?? date('Y-m-01', strtotime($to));
        if ($from > $to) {
            refuse(422, 'The range ends before it starts.');
        }
        return ['from' => $from, 'to' => $to, 'as_of' => $asOf ?? $to, 'label' => format_date($from) . ' – ' . format_date($to), 'period' => 'custom', 'custom' => true];
    }
    $st = $pdo->prepare('SELECT name, start_date, end_date FROM mcp_fiscal_periods WHERE CURRENT_DATE BETWEEN start_date AND end_date');
    $st->execute();
    $p = $st->fetch();
    if ($p !== false) {
        return ['from' => $p['start_date'], 'to' => $p['end_date'], 'as_of' => $p['end_date'], 'label' => $p['name'], 'period' => $p['name'], 'custom' => false];
    }
    $from = date('Y-m-01');
    $to = date('Y-m-t');
    return ['from' => $from, 'to' => $to, 'as_of' => $to, 'label' => date('F Y'), 'period' => 'custom', 'custom' => true];
}

/** True when the range is exactly one calendar month. */
function is_calendar_month(array $range): bool
{
    return $range['from'] === date('Y-m-01', strtotime($range['from'])) && $range['to'] === date('Y-m-t', strtotime($range['from']));
}

/** The range just before: the previous calendar month for a month, the previous year for a year, else the same number of days ending the day before. */
function previous_range(array $range): array
{
    if (is_calendar_month($range)) {
        $from = date('Y-m-01', strtotime($range['from'] . ' -1 month'));
        return ['from' => $from, 'to' => date('Y-m-t', strtotime($from)), 'as_of' => date('Y-m-t', strtotime($from)), 'label' => date('Y-m', strtotime($from))];
    }
    $days = (int) round((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1;
    $to = date('Y-m-d', strtotime($range['from'] . ' -1 day'));
    $from = date('Y-m-d', strtotime($to . ' -' . ($days - 1) . ' days'));
    return ['from' => $from, 'to' => $to, 'as_of' => $to, 'label' => format_date($from) . ' – ' . format_date($to)];
}

/** The same dates one year earlier. */
function last_year_range(array $range): array
{
    $from = date('Y-m-d', strtotime($range['from'] . ' -1 year'));
    $to = date('Y-m-d', strtotime($range['to'] . ' -1 year'));
    return ['from' => $from, 'to' => $to, 'as_of' => $to, 'label' => format_date($from) . ' – ' . format_date($to)];
}

/** The comparison range for a mode ('previous' or 'last_year'), or null. */
function compare_range(array $range, string $mode): ?array
{
    if ($mode === 'previous') {
        return previous_range($range);
    }
    if ($mode === 'last_year') {
        return last_year_range($range);
    }
    return null;
}

/** The periods and years a picker offers: [{value, label}] newest first. */
function period_choices(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT name FROM mcp_fiscal_years ORDER BY start_date DESC')->fetchAll() as $y) {
        $out[] = ['value' => $y['name'], 'label' => $y['name'] . ' (the year)'];
    }
    foreach ($pdo->query('SELECT name, status FROM mcp_fiscal_periods ORDER BY start_date DESC')->fetchAll() as $p) {
        $out[] = ['value' => $p['name'], 'label' => $p['name'] . ($p['status'] === 'closed' ? ' (closed)' : '')];
    }
    return $out;
}
