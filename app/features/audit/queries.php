<?php
declare(strict_types=1);

/**
 * The audit page's questions (slice 9): what was reopened and what was posted into it, what was reversed or voided and why, what the AI statement checks said, and the control accounts' tie-out with the lines that have no document behind them.
 * Reads the mcp_* views and gl_control_check() as the acting member (the writer reads mcp_activity_log under the same rule as the activity role: a person with reports.view reads every row).
 * Requires statements/queries.php (base_currency) and ai/queries.php (ai_check_rows).
 */

/** The range the page shows: from/to when valid, else the open fiscal year containing today, else this calendar year. */
function audit_range(PDO $pdo, ?string $from, ?string $to): array
{
    if ($from === null || $to === null) {
        $y = $pdo->query('SELECT start_date, end_date, name FROM mcp_fiscal_years WHERE CURRENT_DATE BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1')->fetch();
        $dFrom = $y !== false ? $y['start_date'] : date('Y-01-01');
        $dTo = $y !== false ? $y['end_date'] : date('Y-12-31');
        $from ??= $dFrom;
        $to ??= $dTo;
    }
    if ($from > $to) {
        refuse(422, 'The range ends before it starts.');
    }
    return ['from' => $from, 'to' => $to];
}

/** Periods reopened that overlap the range, each with every reopening (who, why, when) and the entries posted into it after the first. */
function audit_reopened_periods(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT fiscal_period_id, name, start_date, end_date, status, reopen_count, closed_at, closed_by_name FROM mcp_fiscal_periods WHERE reopen_count > 0 AND start_date <= :t AND end_date >= :f ORDER BY start_date');
    $st->execute(['f' => $from, 't' => $to]);
    $out = [];
    foreach ($st->fetchAll() as $p) {
        $id = (int) $p['fiscal_period_id'];
        $r = $pdo->prepare("SELECT occurred_at, actor_name, after->>'reason' AS reason FROM mcp_activity_log WHERE action = 'period.reopen' AND period_id = :p ORDER BY occurred_at");
        $r->execute(['p' => $id]);
        $p['fiscal_period_id'] = $id;
        $p['reopenings'] = $r->fetchAll();
        $p['posts'] = audit_posts_after_reopen($pdo, $id);
        $out[] = $p;
    }
    return $out;
}

/** The entries posted into a period that had been reopened: activity rows `journal_entry.post` with after.reopened_period, newest last. */
function audit_posts_after_reopen(PDO $pdo, int $periodId): array
{
    $st = $pdo->prepare("SELECT occurred_at, actor_name, entity_id AS journal_entry_id, after->>'number' AS number, after->>'amount' AS amount, after->>'currency' AS currency, after->>'entry_date' AS entry_date
                           FROM mcp_activity_log WHERE action = 'journal_entry.post' AND period_id = :p AND (after->>'reopened_period')::boolean ORDER BY occurred_at");
    $st->execute(['p' => $periodId]);
    return $st->fetchAll();
}

/** Entries reversed in the range (by the original's date), with the reason, who and the reversal entry. */
function audit_reversals(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT o.journal_entry_id, o.number, o.entry_date, o.memo, o.total_debit AS amount, o.currency, COALESCE(o.reversal_reason, r.reversal_reason) AS reversal_reason, o.reversed_by AS reversal_id, o.reversed_by_number AS reversal_number, r.posted_by_name AS reversed_by_name, r.posted_at AS reversed_at
                           FROM mcp_journal_entries o LEFT JOIN mcp_journal_entries r ON r.journal_entry_id = o.reversed_by
                          WHERE o.status = 'reversed' AND o.entry_date BETWEEN :f AND :t ORDER BY o.entry_date, o.number");
    $st->execute(['f' => $from, 't' => $to]);
    return $st->fetchAll();
}

/** Drafts voided in the range (by the day voided). */
function audit_voids(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT e.journal_entry_id, e.number, e.entry_date, e.memo, (SELECT COALESCE(sum(l.debit), 0) FROM mcp_journal_lines l WHERE l.journal_entry_id = e.journal_entry_id) AS amount, e.currency, e.void_reason, e.voided_at, m.display_name AS voided_by_name
                           FROM mcp_journal_entries e LEFT JOIN mcp_members m ON m.member_id = e.voided_by
                          WHERE e.status = 'void' AND e.voided_at::date BETWEEN :f AND :t ORDER BY e.voided_at");
    $st->execute(['f' => $from, 't' => $to]);
    return $st->fetchAll();
}

/** Each AI statement month in the range that has been checked: the verdict, and (a differing one) the difference per provider in words. */
function audit_ai_verdicts(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare("SELECT ai_statement_id, period, kernel_status, status, checked_at, bill_count, totals FROM mcp_ai_statements WHERE period_start <= :t AND period_end >= :f ORDER BY period");
    $st->execute(['f' => $from, 't' => $to]);
    $out = [];
    foreach ($st->fetchAll() as $s) {
        $id = (int) $s['ai_statement_id'];
        $s['ai_statement_id'] = $id;
        $s['differences'] = [];
        if ($s['status'] === 'differs') {
            foreach (ai_check_rows($pdo, $id) as $r) {
                if (!$r['agrees']) {
                    $s['differences'][] = ai_difference_sentence($r, $s['period']);
                }
            }
        }
        $out[] = $s;
    }
    return $out;
}

/** gl_control_check() as of a date: the receivable and the payable control accounts against their documents. */
function audit_control_check(PDO $pdo, string $asOf): array
{
    $st = $pdo->prepare('SELECT control, subledger, ledger, difference FROM gl_control_check(:d)');
    $st->execute(['d' => $asOf]);
    return array_map(static function (array $r): array {
        foreach (['subledger', 'ledger', 'difference'] as $k) {
            $r[$k] = m_str(m_cents((string) $r[$k]));
        }
        return $r;
    }, $st->fetchAll());
}

/** Lines on the receivable and payable accounts that no invoice, bill, receipt or payment stands behind (a manual, opening, import or recurring entry), by entry, as of a date. */
function audit_control_lines(PDO $pdo, string $asOf): array
{
    $st = $pdo->prepare("SELECT l.journal_entry_id, l.entry_number, l.entry_date, l.source, l.account_code, l.account_name, l.description, l.debit, l.credit, e.memo
                           FROM mcp_journal_lines l JOIN mcp_journal_entries e ON e.journal_entry_id = l.journal_entry_id
                          WHERE l.entry_status IN ('posted', 'reversed') AND l.entry_date <= :d AND l.source IN ('manual', 'opening', 'import', 'recurring', 'closing')
                            AND l.account_id IN (SELECT ar_account_id FROM gl_settings WHERE id = 1 UNION SELECT ap_account_id FROM gl_settings WHERE id = 1)
                          ORDER BY l.entry_date, l.entry_number, l.line_no");
    $st->execute(['d' => $asOf]);
    return $st->fetchAll();
}

/** The invariant: entries posted after their period was closed (the database refuses it; the count says it is so). */
function audit_posted_after_close(PDO $pdo): int
{
    return (int) $pdo->query("SELECT count(*) FROM mcp_journal_entries e JOIN mcp_fiscal_periods p ON p.fiscal_period_id = e.period_id WHERE p.status = 'closed' AND e.posted_at > p.closed_at")->fetchColumn();
}
