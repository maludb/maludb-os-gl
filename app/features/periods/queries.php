<?php
declare(strict_types=1);

/**
 * Fiscal time (slice 1, docs/build-specs/chart-fiscal.md): the years and their periods through mcp_fiscal_years / mcp_fiscal_periods, the
 * writer functions of db/007 and db/008 (gl_fiscal_year_create, gl_period_close, gl_period_reopen, gl_year_close, gl_year_reopen — the
 * database is the referee; its RAISE is the 422). The close checklist is computed here from the schema; later slices fill the data.
 */

function fiscal_year_cast(array $y): array
{
    foreach (['fiscal_year_id', 'closing_entry_id', 'closed_by', 'reopened_by', 'open_periods'] as $k) {
        $y[$k] = $y[$k] === null ? null : (int) $y[$k];
    }
    return $y;
}

function period_cast(array $p): array
{
    foreach (['fiscal_period_id', 'fiscal_year_id', 'closed_by', 'reopened_by', 'reopen_count', 'posted_entries', 'draft_entries'] as $k) {
        $p[$k] = $p[$k] === null ? null : (int) $p[$k];
    }
    return $p;
}

/** Every fiscal year, newest first, each with its periods in date order. */
function find_fiscal_years(PDO $pdo): array
{
    $years = array_map('fiscal_year_cast', $pdo->query('SELECT y.*, ce.number AS closing_entry_number, cb.display_name AS closed_by_name, rb.display_name AS reopened_by_name
        FROM mcp_fiscal_years y LEFT JOIN mcp_journal_entries ce ON ce.journal_entry_id = y.closing_entry_id LEFT JOIN members cb ON cb.id = y.closed_by LEFT JOIN members rb ON rb.id = y.reopened_by
        ORDER BY y.start_date DESC')->fetchAll());
    $periods = $pdo->prepare('SELECT * FROM mcp_fiscal_periods WHERE fiscal_year_id = :y ORDER BY start_date');
    foreach ($years as &$y) {
        $periods->execute(['y' => $y['fiscal_year_id']]);
        $y['periods'] = array_map('period_cast', $periods->fetchAll());
    }
    unset($y);
    return $years;
}

function find_fiscal_year(PDO $pdo, int $id): ?array
{
    foreach (find_fiscal_years($pdo) as $y) {
        if ($y['fiscal_year_id'] === $id) {
            return $y;
        }
    }
    return null;
}

function find_period(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.*, y.status AS fiscal_year_status, y.end_date AS fiscal_year_end, y.closing_entry_id FROM mcp_fiscal_periods p JOIN mcp_fiscal_years y ON y.fiscal_year_id = p.fiscal_year_id WHERE p.fiscal_period_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = period_cast($r);
    $r['closing_entry_id'] = $r['closing_entry_id'] === null ? null : (int) $r['closing_entry_id'];
    $r['is_last_of_year'] = $r['end_date'] === $r['fiscal_year_end'];
    return $r;
}

/** The fiscal year covering a date (the current one by default), or null. */
function fiscal_year_for(PDO $pdo, ?string $date = null): ?array
{
    $st = $pdo->prepare('SELECT * FROM mcp_fiscal_years WHERE :d BETWEEN start_date AND end_date');
    $st->execute(['d' => $date ?? date('Y-m-d')]);
    $r = $st->fetch();
    return $r === false ? null : fiscal_year_cast($r);
}

/** What posted in a period by source (count, total debits), and what is still a draft. */
function period_summary(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT source, count(*) AS entries, sum(total_debit) AS total FROM mcp_journal_entries WHERE period_id = :p AND status IN ('posted', 'reversed') GROUP BY source ORDER BY source");
    $st->execute(['p' => $id]);
    $posted = array_map(static fn (array $r): array => ['source' => $r['source'], 'entries' => (int) $r['entries'], 'total' => $r['total']], $st->fetchAll());
    $st = $pdo->prepare("SELECT count(*) AS n, sum(total_debit) AS total FROM mcp_journal_entries WHERE period_id = :p AND status = 'draft'");
    $st->execute(['p' => $id]);
    $d = $st->fetch();
    return ['posted' => $posted, 'posted_entries' => array_sum(array_column($posted, 'entries')), 'drafts' => (int) $d['n'], 'drafts_total' => $d['total']];
}

/**
 * The close checklist (slice 8's month-end items, read-only here): each [key, label, ok (true/false/null when nothing applies), detail, url].
 * Computed from the schema; the slices fill the data (the AI statement, the bank, recurring).
 */
function period_checklist(PDO $pdo, int $id): array
{
    $p = find_period($pdo, $id) ?? throw new DomainException('Not found.');
    $items = [];
    $drafts = $p['draft_entries'];
    $items[] = ['key' => 'drafts', 'label' => 'No draft entry dated in the period', 'ok' => $drafts === 0, 'detail' => $drafts === 0 ? 'None' : $drafts . ' draft' . ($drafts === 1 ? '' : 's'), 'url' => '/journal/?period=' . $id . '&status=draft'];
    $st = $pdo->prepare('SELECT status FROM ai_statements WHERE period = :m');
    $st->execute(['m' => $p['name']]);
    $ai = $st->fetchColumn();
    $items[] = ['key' => 'ai_statement', 'label' => "The OS's AI statement for the month is imported", 'ok' => $ai === false ? null : in_array($ai, ['imported', 'agrees'], true),
                'detail' => $ai === false ? 'Not pulled yet (the kernel closes the month first)' : ucfirst((string) $ai), 'url' => '/ap/ai/'];
    $st = $pdo->prepare("SELECT count(*) FROM receipts WHERE status = 'recorded' AND deposit_id IS NULL AND deposit_to_bank_account_id IS NULL AND received_on <= :e");
    $st->execute(['e' => $p['end_date']]);
    $und = (int) $st->fetchColumn();
    $items[] = ['key' => 'undeposited', 'label' => 'No undeposited receipt older than the month', 'ok' => $und === 0, 'detail' => $und === 0 ? 'None' : $und . ' receipt' . ($und === 1 ? '' : 's') . ' waiting for a deposit', 'url' => '/deposits/'];
    $st = $pdo->prepare("SELECT count(*) FILTER (WHERE last_reconciled_through IS NULL OR last_reconciled_through < :e) AS behind, count(*) AS total FROM bank_accounts WHERE archived_at IS NULL");
    $st->execute(['e' => $p['end_date']]);
    $b = $st->fetch();
    $items[] = ['key' => 'bank', 'label' => "Every bank account is reconciled through the month's end", 'ok' => (int) $b['total'] === 0 ? null : (int) $b['behind'] === 0,
                'detail' => (int) $b['total'] === 0 ? 'No bank account yet' : ((int) $b['behind'] === 0 ? 'All ' . $b['total'] . ' reconciled' : $b['behind'] . ' of ' . $b['total'] . ' not yet'), 'url' => '/cash/reconcile'];
    $st = $pdo->prepare('SELECT control, difference FROM gl_control_check(:e)');
    $st->execute(['e' => $p['end_date']]);
    $diffs = array_filter($st->fetchAll(), static fn (array $r): bool => (float) $r['difference'] != 0);
    $items[] = ['key' => 'control', 'label' => 'The receivable and payable control accounts tie to their subledgers', 'ok' => $diffs === [],
                'detail' => $diffs === [] ? 'Tied out' : implode('; ', array_map(static fn (array $r): string => $r['control'] . ' off by ' . $r['difference'], $diffs)), 'url' => '/audit'];
    $st = $pdo->prepare('SELECT count(*) FROM recurring_templates WHERE active AND next_date <= :e');
    $st->execute(['e' => $p['end_date']]);
    $due = (int) $st->fetchColumn();
    $items[] = ['key' => 'recurring', 'label' => 'No recurring template is due in or before the month', 'ok' => $due === 0, 'detail' => $due === 0 ? 'None due' : $due . ' due', 'url' => '/settings/recurring/'];
    return $items;
}

function create_fiscal_year(PDO $pdo, string $start, ?string $name, int $by): int
{
    $st = $pdo->prepare('SELECT gl_fiscal_year_create(:s, :n)');
    $st->execute(['s' => $start, 'n' => $name === '' ? null : $name]);
    return (int) $st->fetchColumn();
}

function close_period(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('SELECT gl_period_close(:p, :by)')->execute(['p' => $id, 'by' => $by]);
}

function reopen_period(PDO $pdo, int $id, int $by, string $reason): void
{
    $pdo->prepare('SELECT gl_period_reopen(:p, :by, :r)')->execute(['p' => $id, 'by' => $by, 'r' => $reason]);
}

/** Close a year; answers the closing entry [journal_entry_id, number, total] or null when nothing was there to close. */
function close_year(PDO $pdo, int $yearId, int $by): ?array
{
    $st = $pdo->prepare('SELECT (e).id AS journal_entry_id, (e).number, (e).total_debit AS total, (e).currency FROM gl_year_close(:y, :by) e');
    $st->execute(['y' => $yearId, 'by' => $by]);
    $r = $st->fetch();
    if ($r === false || $r['journal_entry_id'] === null) {
        return null;
    }
    return ['journal_entry_id' => (int) $r['journal_entry_id'], 'number' => $r['number'], 'total' => $r['total'], 'currency' => trim((string) $r['currency'])];
}

function reopen_year(PDO $pdo, int $yearId, int $by, string $reason): void
{
    $pdo->prepare('SELECT gl_year_reopen(:y, :by, :r)')->execute(['y' => $yearId, 'by' => $by, 'r' => $reason]);
}

/** A period's history from the activity log (closes, reopens with reasons), oldest first. */
function period_history(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT l.occurred_at, l.action, l.after, m.display_name AS actor_name FROM activity_log l LEFT JOIN members m ON m.id = l.actor_member_id
                          WHERE l.period_id = :p AND l.action IN ('period.close', 'period.reopen', 'fiscal_year.close', 'fiscal_year.reopen') ORDER BY l.id");
    $st->execute(['p' => $id]);
    return array_map(static fn (array $r): array => ['at' => $r['occurred_at'], 'action' => $r['action'], 'by' => $r['actor_name'], 'after' => json_decode((string) $r['after'], true) ?: []], $st->fetchAll());
}
