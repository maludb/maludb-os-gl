<?php
declare(strict_types=1);
/** The home's regions (screen `dashboard`, docs/build-specs/sso-shell.md): the summary every slice fills a little more of. */

/** Everything the dashboard shows, from the db/015 functions and the member's own notifications. */
function home_summary(PDO $pdo): array
{
    $waiting = $pdo->query('SELECT * FROM gl_unposted_summary()')->fetch() ?: [];
    require_once __DIR__ . '/../cash/queries.php';
    $cash = cash_position($pdo);
    $postedThrough = $pdo->query('SELECT gl_posted_through()')->fetchColumn();
    $year = $pdo->query("SELECT fiscal_year_id, name, start_date, end_date, status FROM mcp_fiscal_years WHERE status = 'open' ORDER BY start_date DESC LIMIT 1")->fetch() ?: null;
    $anyYear = (int) $pdo->query('SELECT count(*) FROM mcp_fiscal_years')->fetchColumn() > 0;
    $settings = $pdo->query('SELECT base_currency, business_name FROM mcp_settings')->fetch() ?: ['base_currency' => 'USD', 'business_name' => null];
    $thisMonth = null;
    if ($year !== null) {
        $from = date('Y-m-01');
        $to = date('Y-m-t');
        $st = $pdo->prepare('SELECT gl_net_income(:f, :t) AS net');
        $st->execute(['f' => $from, 't' => $to]);
        require_once __DIR__ . '/../statements/periods.php';
        require_once __DIR__ . '/../statements/queries.php';
        $pl = profit_and_loss($pdo, $from, $to, null, null, null);
        $thisMonth = ['from' => $from, 'to' => $to, 'net_income' => $st->fetchColumn(), 'revenue' => $pl['income_total'], 'spend' => $pl['expense_total']];
    }
    $ar = $pdo->query('SELECT currency, sum(total) AS total, sum(days_over_90) AS over_90, sum(open_invoices) AS open_count FROM gl_ar_aging() GROUP BY currency ORDER BY currency')->fetchAll();
    $ap = $pdo->query('SELECT currency, sum(total) AS total, sum(days_over_90) AS over_90, sum(open_bills) AS open_count FROM gl_ap_aging() GROUP BY currency ORDER BY currency')->fetchAll();
    $ai = $pdo->query('SELECT ai_statement_id, period, kernel_status, status, totals, bill_count FROM mcp_ai_statements ORDER BY period DESC LIMIT 1')->fetch() ?: null;
    $note = $pdo->query("SELECT title, body, created_at FROM mcp_notifications WHERE kind = 'morning_note' ORDER BY created_at DESC LIMIT 1")->fetch() ?: null;
    return ['waiting' => array_map(static fn ($v) => $v === null ? null : (is_numeric($v) ? (int) $v : $v), $waiting), 'cash' => $cash, 'cash_totals' => cash_totals($cash), 'cash_trend' => $cash === [] ? null : cash_trend($pdo, 12), 'posted_through' => $postedThrough ?: null,
            'fiscal_year' => $year, 'has_fiscal_year' => $anyYear, 'base_currency' => $settings['base_currency'], 'business_name' => $settings['business_name'],
            'this_month' => $thisMonth, 'ar' => $ar, 'ap' => $ap, 'ai' => $ai, 'morning_note' => $note];
}

/** The JSON shape of the dashboard — whitelists, no raw row. */
function present_home(array $h): array
{
    return [
        'business_name' => $h['business_name'], 'base_currency' => $h['base_currency'], 'has_fiscal_year' => $h['has_fiscal_year'],
        'fiscal_year' => $h['fiscal_year'] === null ? null : ['fiscal_year_id' => (int) $h['fiscal_year']['fiscal_year_id'], 'name' => $h['fiscal_year']['name'], 'start' => $h['fiscal_year']['start_date'], 'end' => $h['fiscal_year']['end_date']],
        'posted_through' => $h['posted_through'],
        'waiting' => array_intersect_key($h['waiting'], array_flip(['draft_entries', 'unbalanced_entries', 'oldest_draft', 'draft_invoices', 'draft_bills', 'bills_awaiting_approval', 'payments_awaiting_approval', 'unmatched_bank_lines', 'open_claims', 'undeposited_receipts'])),
        'cash_totals' => $h['cash_totals'] ?? [],
        'cash_trend' => $h['cash_trend'] === null ? null : ['months' => $h['cash_trend']['months'], 'accounts' => $h['cash_trend']['accounts'], 'rows' => $h['cash_trend']['rows']],
        'cash' => array_map(static fn (array $c): array => ['bank_account_id' => (int) $c['bank_account_id'], 'name' => $c['name'], 'kind' => $c['kind'], 'currency' => $c['currency'], 'ledger_balance' => $c['ledger_balance'], 'cleared_balance' => $c['cleared_balance'],
            'last_reconciled_through' => $c['last_reconciled_through'], 'unmatched_lines' => (int) $c['unmatched_lines']], $h['cash']),
        'receivables' => array_map(static fn (array $r): array => ['currency' => $r['currency'], 'total' => $r['total'], 'over_90' => $r['over_90'], 'open_invoices' => (int) $r['open_count']], $h['ar']),
        'payables' => array_map(static fn (array $r): array => ['currency' => $r['currency'], 'total' => $r['total'], 'over_90' => $r['over_90'], 'open_bills' => (int) $r['open_count']], $h['ap']),
        'this_month' => $h['this_month'] === null ? null : ['from' => $h['this_month']['from'], 'to' => $h['this_month']['to'], 'net_income' => $h['this_month']['net_income'], 'revenue' => $h['this_month']['revenue'], 'spend' => $h['this_month']['spend']],
        'ai_statement' => $h['ai'] === null ? null : ['ai_statement_id' => (int) $h['ai']['ai_statement_id'], 'period' => $h['ai']['period'], 'kernel_status' => $h['ai']['kernel_status'], 'status' => $h['ai']['status'], 'bill_count' => (int) $h['ai']['bill_count']],
        'morning_note' => $h['morning_note'] === null ? null : ['title' => $h['morning_note']['title'], 'body' => $h['morning_note']['body'], 'created_at' => json_ts($h['morning_note']['created_at'])],
    ];
}
