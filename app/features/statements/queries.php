<?php
declare(strict_types=1);

/**
 * The statements and reports (slice 3): one function per statement over the db/015 functions (a screen, a tool and a file never disagree), the
 * reports over the document views. Money is a numeric string. The db/015 functions sum the books as they stand and carry no currency, so a
 * statement is one table in the base currency; books_currencies() says when entries in another currency are in the books (see the spec's open question).
 * Requires accounts/queries.php (ACCOUNT_SUBTYPES) and periods.php.
 */

function base_currency(PDO $pdo): string
{
    return trim((string) $pdo->query('SELECT gl_base_currency()')->fetchColumn());
}

/** Every currency a posted entry carries. */
function books_currencies(PDO $pdo): array
{
    return array_map(static fn (string $c): string => trim($c), $pdo->query("SELECT DISTINCT currency FROM mcp_journal_entries WHERE status IN ('posted', 'reversed') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN));
}

/** Posted-through date and what waits: ['date' => ?string, 'waiting' => [label => count], 'sentence' => string]. */
function posted_through(PDO $pdo): array
{
    $date = $pdo->query('SELECT gl_posted_through()')->fetchColumn();
    $w = $pdo->query('SELECT * FROM gl_unposted_summary()')->fetch() ?: [];
    $labels = ['draft_entries' => ['draft', 'drafts'], 'draft_invoices' => ['draft invoice', 'draft invoices'], 'draft_bills' => ['draft bill', 'draft bills'],
               'bills_awaiting_approval' => ['bill awaiting approval', 'bills awaiting approval'], 'payments_awaiting_approval' => ['payment awaiting approval', 'payments awaiting approval'],
               'unmatched_bank_lines' => ['unmatched bank line', 'unmatched bank lines'], 'open_claims' => ['open claim', 'open claims'], 'undeposited_receipts' => ['undeposited receipt', 'undeposited receipts']];
    $waiting = [];
    $parts = [];
    foreach ($labels as $k => [$one, $many]) {
        $n = (int) ($w[$k] ?? 0);
        $waiting[$k] = $n;
        if ($n > 0) {
            $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
        }
    }
    $sentence = ($date === false || $date === null ? 'Nothing posted yet' : 'Posted through ' . date('j M Y', strtotime((string) $date))) . ($parts !== [] ? ' · ' . implode(', ', $parts) . ' waiting' : '');
    return ['date' => $date === false ? null : $date, 'waiting' => $waiting, 'sentence' => $sentence];
}

function money_sum(array $values): string
{
    $t = 0;
    foreach ($values as $v) {
        $t += m_cents($v);
    }
    return m_str($t);
}

/** The trial balance as of a date: rows with debit/credit, the totals and whether they are equal. */
function trial_balance(PDO $pdo, string $asOf): array
{
    $st = $pdo->prepare('SELECT account_id, code, name, account_type, subtype, debit, credit FROM gl_trial_balance(:d) ORDER BY code');
    $st->execute(['d' => $asOf]);
    $rows = array_map(static function (array $r): array { $r['account_id'] = (int) $r['account_id']; return $r; }, $st->fetchAll());
    $debit = money_sum(array_column($rows, 'debit'));
    $credit = money_sum(array_column($rows, 'credit'));
    return ['currency' => base_currency($pdo), 'as_of' => $asOf, 'rows' => $rows, 'debit' => $debit, 'credit' => $credit, 'balanced' => m_cmp($debit, $credit) === 0, 'difference' => m_sub($debit, $credit)];
}

/** One range's P&L rows from the function: [account_id => row]. */
function pl_rows(PDO $pdo, string $from, string $to, ?int $dept, ?int $site): array
{
    $st = $pdo->prepare('SELECT account_type, subtype, account_id, code, name, amount FROM gl_profit_and_loss(:f, :t, :d, :s) ORDER BY code');
    $st->execute(['f' => $from, 't' => $to, 'd' => $dept, 's' => $site]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $r['account_id'] = (int) $r['account_id'];
        $out[$r['account_id']] = $r;
    }
    return $out;
}

/** Variance of an amount against its prior: [variance, pct|null]. */
function variance(string $amount, ?string $prior): array
{
    if ($prior === null) {
        return [null, null];
    }
    return [m_sub($amount, $prior), m_pct($amount, $prior)];
}

/**
 * The profit and loss: income accounts, expenses grouped by subtype, net income; with $compare ['from','to'] a prior and a variance on every row.
 * 'has_books' is false when the range holds no posted line at all (the screen then says "no books for that period").
 */
function profit_and_loss(PDO $pdo, string $from, string $to, ?int $dept, ?int $site, ?array $compare): array
{
    $cur = pl_rows($pdo, $from, $to, $dept, $site);
    $prior = $compare === null ? null : pl_rows($pdo, $compare['from'], $compare['to'], $dept, $site);
    $ids = array_keys($cur + ($prior ?? []));
    $meta = $cur + ($prior ?? []);
    $rows = [];
    foreach ($ids as $id) {
        $m = $meta[$id];
        $amount = $cur[$id]['amount'] ?? '0.00';
        $p = $prior === null ? null : ($prior[$id]['amount'] ?? '0.00');
        [$v, $pct] = variance($amount, $p);
        $rows[] = ['account_id' => $id, 'code' => $m['code'], 'name' => $m['name'], 'account_type' => $m['account_type'], 'subtype' => $m['subtype'], 'amount' => $amount, 'prior' => $p, 'variance' => $v, 'pct' => $pct];
    }
    usort($rows, static fn (array $a, array $b): int => strcmp($a['code'], $b['code']));
    $income = array_values(array_filter($rows, static fn (array $r): bool => $r['account_type'] === 'income'));
    $groups = [];
    foreach (array_filter($rows, static fn (array $r): bool => $r['account_type'] === 'expense') as $r) {
        $key = in_array($r['subtype'], ['cogs'], true) ? 'cogs' : (in_array($r['subtype'], ['operating', 'bad_debt'], true) ? 'operating' : 'other');
        $groups[$key][] = $r;
    }
    $labels = ['cogs' => 'Cost of goods sold', 'operating' => 'Operating expenses', 'other' => 'Other expenses'];
    $expenseGroups = [];
    foreach (['cogs', 'operating', 'other'] as $k) {
        if (!empty($groups[$k])) {
            $expenseGroups[] = ['key' => $k, 'label' => $labels[$k], 'rows' => $groups[$k], 'total' => money_sum(array_column($groups[$k], 'amount')), 'prior' => $prior === null ? null : money_sum(array_column($groups[$k], 'prior'))];
        }
    }
    $incomeTotal = money_sum(array_column($income, 'amount'));
    $expenseTotal = money_sum(array_column(array_merge(...array_column($expenseGroups, 'rows') ?: [[]]), 'amount'));
    $net = m_sub($incomeTotal, $expenseTotal);
    $priorIncome = $prior === null ? null : money_sum(array_column($income, 'prior'));
    $priorExpense = $prior === null ? null : money_sum(array_column(array_merge(...array_column($expenseGroups, 'rows') ?: [[]]), 'prior'));
    $priorNet = $prior === null ? null : m_sub($priorIncome, $priorExpense);
    $q = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM mcp_journal_entries WHERE status IN ('posted', 'reversed') AND entry_date BETWEEN :f AND :t)");
    $q->execute(['f' => $from, 't' => $to]);
    $hasBooks = (bool) $q->fetchColumn();
    $ni = $pdo->prepare('SELECT gl_net_income(:f, :t, :d, :s)');
    $ni->execute(['f' => $from, 't' => $to, 'd' => $dept, 's' => $site]);
    return ['currency' => base_currency($pdo), 'from' => $from, 'to' => $to, 'compare' => $compare, 'department' => $dept, 'site' => $site, 'income' => $income, 'income_total' => $incomeTotal, 'income_prior' => $priorIncome,
            'expense_groups' => $expenseGroups, 'expense_total' => $expenseTotal, 'expense_prior' => $priorExpense, 'net_income' => $net, 'net_prior' => $priorNet, 'net_check' => (string) $ni->fetchColumn(),
            'net_variance' => variance($net, $priorNet), 'has_books' => $hasBooks];
}

/** The balance sheet as of a date (and a prior as-of): sections of rows, totals, the equation. */
function balance_sheet(PDO $pdo, string $asOf, ?string $compareAsOf): array
{
    $load = static function (string $d) use ($pdo): array {
        $st = $pdo->prepare('SELECT section, subtype, account_id, code, name, balance FROM gl_balance_sheet(:d)');
        $st->execute(['d' => $d]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[($r['account_id'] ?? 'x') . '|' . $r['subtype'] . '|' . $r['name']] = $r;
        }
        return $out;
    };
    $cur = $load($asOf);
    $prior = $compareAsOf === null ? null : $load($compareAsOf);
    $keys = array_keys($cur + ($prior ?? []));
    $meta = $cur + ($prior ?? []);
    $sections = ['asset' => [], 'liability' => [], 'equity' => []];
    foreach ($keys as $k) {
        $m = $meta[$k];
        $bal = $cur[$k]['balance'] ?? '0.00';
        $pb = $prior === null ? null : ($prior[$k]['balance'] ?? '0.00');
        $sections[$m['section']][] = ['account_id' => $m['account_id'] === null ? null : (int) $m['account_id'], 'code' => $m['code'], 'name' => $m['name'], 'subtype' => $m['subtype'], 'balance' => $bal, 'prior' => $pb];
    }
    foreach ($sections as &$rows) {
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['code'] ?? 'zzz'), (string) ($b['code'] ?? 'zzz')));
    }
    unset($rows);
    $totals = [];
    foreach ($sections as $s => $rows) {
        $totals[$s] = ['balance' => money_sum(array_column($rows, 'balance')), 'prior' => $prior === null ? null : money_sum(array_column($rows, 'prior'))];
    }
    $chk = $pdo->prepare('SELECT assets, liabilities, equity, difference FROM gl_balance_sheet_check(:d)');
    $chk->execute(['d' => $asOf]);
    $check = $chk->fetch();
    $ce = $pdo->prepare("SELECT COALESCE(sum(balance), 0) FROM gl_balance_sheet(:d) WHERE subtype = 'current_earnings'");
    $ce->execute(['d' => $asOf]);
    return ['currency' => base_currency($pdo), 'as_of' => $asOf, 'compare_as_of' => $compareAsOf, 'sections' => $sections, 'totals' => $totals, 'check' => $check, 'holds' => m_cmp((string) $check['difference'], '0') === 0,
            'current_earnings' => (string) $ce->fetchColumn(), 'has_books' => $cur !== []];
}

/** The cash flow: sections of rows from gl_cash_flow(), the summary lines and the check. */
function cash_flow(PDO $pdo, string $from, string $to): array
{
    $st = $pdo->prepare('SELECT section, line_no, label, account_id, code, amount FROM gl_cash_flow(:f, :t) ORDER BY line_no');
    $st->execute(['f' => $from, 't' => $to]);
    $sections = ['operating' => [], 'investing' => [], 'financing' => []];
    $summary = [];
    foreach ($st->fetchAll() as $r) {
        if ($r['section'] === 'summary') {
            $summary[$r['label']] = $r['amount'];
        } else {
            $sections[$r['section']][] = ['account_id' => $r['account_id'] === null ? null : (int) $r['account_id'], 'code' => $r['code'], 'label' => $r['label'], 'amount' => $r['amount']];
        }
    }
    return ['currency' => base_currency($pdo), 'from' => $from, 'to' => $to, 'sections' => $sections, 'subtotals' => ['operating' => $summary['Cash from operations'] ?? '0.00', 'investing' => $summary['Cash from investing'] ?? '0.00', 'financing' => $summary['Cash from financing'] ?? '0.00'],
            'net_change' => $summary['Net change in cash'] ?? '0.00', 'cash_start' => $summary['Cash at start'] ?? '0.00', 'cash_end' => $summary['Cash at end'] ?? '0.00',
            'check' => $summary['Check: end − start − net change (must be zero)'] ?? '0.00', 'has_books' => $sections['operating'] !== []];
}

/** The AR ageing as of a date, optionally one customer: rows from gl_ar_aging() and a totals row per currency. */
function ar_aging(PDO $pdo, string $asOf, ?int $customerId): array
{
    $st = $pdo->prepare('SELECT customer_id, customer_name, currency, current_due, days_1_30, days_31_60, days_61_90, days_over_90, total, open_invoices FROM gl_ar_aging(:d) ORDER BY customer_name, currency');
    $st->execute(['d' => $asOf]);
    $rows = array_values(array_filter($st->fetchAll(), static fn (array $r): bool => $customerId === null || (int) $r['customer_id'] === $customerId));
    return aging_result($rows, 'customer_id', 'customer_name', 'open_invoices', $asOf);
}

function ap_aging(PDO $pdo, string $asOf, ?int $vendorId): array
{
    $st = $pdo->prepare('SELECT vendor_id, vendor_name, currency, current_due, days_1_30, days_31_60, days_61_90, days_over_90, total, open_bills FROM gl_ap_aging(:d) ORDER BY vendor_name, currency');
    $st->execute(['d' => $asOf]);
    $rows = array_values(array_filter($st->fetchAll(), static fn (array $r): bool => $vendorId === null || (int) $r['vendor_id'] === $vendorId));
    return aging_result($rows, 'vendor_id', 'vendor_name', 'open_bills', $asOf);
}

function aging_result(array $rows, string $idCol, string $nameCol, string $countCol, string $asOf): array
{
    $buckets = ['current_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90', 'total'];
    $by = [];
    foreach ($rows as $r) {
        $c = trim((string) $r['currency']);
        $by[$c]['rows'][] = ['party_id' => (int) $r[$idCol], 'party_name' => $r[$nameCol]] + array_intersect_key($r, array_flip($buckets)) + ['documents' => (int) $r[$countCol], 'currency' => $c];
    }
    ksort($by);
    $tables = [];
    foreach ($by as $c => $t) {
        $totals = [];
        foreach ($buckets as $b) {
            $totals[$b] = money_sum(array_column($t['rows'], $b));
        }
        $tables[] = ['currency' => $c, 'rows' => $t['rows'], 'totals' => $totals, 'documents' => array_sum(array_column($t['rows'], 'documents'))];
    }
    return ['as_of' => $asOf, 'tables' => $tables];
}

/** The sales tax report: by tax rate, taxable sales and tax charged from issued invoice lines less issued credit note lines. */
function sales_tax(PDO $pdo, string $from, string $to): array
{
    $sql = "SELECT t.tax_rate_id, t.name, t.rate,
                   COALESCE(sum(l.line_subtotal), 0) AS taxable, COALESCE(sum(l.line_tax), 0) AS tax
              FROM (SELECT tax_rate_id, line_subtotal, line_tax FROM mcp_invoice_lines il JOIN mcp_invoices i ON i.invoice_id = il.invoice_id
                     WHERE i.status NOT IN ('draft', 'void') AND i.issue_date BETWEEN :f AND :t
                    UNION ALL
                    SELECT tax_rate_id, -line_subtotal, -line_tax FROM mcp_credit_note_lines cl JOIN mcp_credit_notes c ON c.credit_note_id = cl.credit_note_id
                     WHERE c.status = 'issued' AND c.issue_date BETWEEN :f AND :t) l
              LEFT JOIN mcp_tax_rates t ON t.tax_rate_id = l.tax_rate_id
             GROUP BY t.tax_rate_id, t.name, t.rate ORDER BY t.rate NULLS FIRST";
    $st = $pdo->prepare($sql);
    $st->execute(['f' => $from, 't' => $to]);
    $rows = array_map(static fn (array $r): array => ['tax_rate_id' => $r['tax_rate_id'] === null ? null : (int) $r['tax_rate_id'], 'name' => $r['name'] ?? 'No tax', 'rate' => $r['rate'], 'taxable' => $r['taxable'], 'tax' => $r['tax']], $st->fetchAll());
    return ['from' => $from, 'to' => $to, 'rows' => $rows, 'taxable_total' => money_sum(array_column($rows, 'taxable')), 'tax_total' => money_sum(array_column($rows, 'tax'))];
}

const REPORT_GROUPS = ['account' => 'Account', 'vendor' => 'Vendor', 'customer' => 'Customer', 'department' => 'Department', 'site' => 'Site'];

/** Spend: approved bills' lines (less nothing else) by account, vendor, department or site. [rows: key, label, url, amount; total]. */
function spend(PDO $pdo, string $from, string $to, string $groupBy): array
{
    $groupBy = in_array($groupBy, ['account', 'vendor', 'department', 'site'], true) ? $groupBy : 'account';
    $col = ['account' => ['a.account_id', "a.code || ' ' || a.name", 'JOIN mcp_accounts a ON a.account_id = bl.expense_account_id'],
            'vendor' => ['v.vendor_id', 'v.name', 'JOIN mcp_vendors v ON v.vendor_id = b.vendor_id'],
            'department' => ['d.department_id', 'COALESCE(d.name, \'No department\')', 'LEFT JOIN mcp_departments d ON d.department_id = COALESCE(bl.department_id, b.department_id)'],
            'site' => ['s.site_id', 'COALESCE(s.name, \'No site\')', 'LEFT JOIN mcp_sites s ON s.site_id = COALESCE(bl.site_id, b.site_id)']][$groupBy];
    $sql = "SELECT {$col[0]} AS key, {$col[1]} AS label, sum(bl.line_subtotal) AS amount, count(DISTINCT b.bill_id) AS documents
              FROM mcp_bill_lines bl JOIN mcp_bills b ON b.bill_id = bl.bill_id {$col[2]}
             WHERE b.status IN ('approved', 'partially_paid', 'paid') AND b.bill_date BETWEEN :f AND :t GROUP BY 1, 2 ORDER BY 3 DESC";
    $st = $pdo->prepare($sql);
    $st->execute(['f' => $from, 't' => $to]);
    $rows = array_map(static fn (array $r): array => ['key' => $r['key'] === null ? null : (int) $r['key'], 'label' => $r['label'], 'amount' => $r['amount'], 'documents' => (int) $r['documents']], $st->fetchAll());
    return ['group_by' => $groupBy, 'from' => $from, 'to' => $to, 'rows' => $rows, 'total' => money_sum(array_column($rows, 'amount'))];
}

/** Revenue: issued invoice lines less issued credit note lines by account, customer, department or site. */
function revenue(PDO $pdo, string $from, string $to, string $groupBy): array
{
    $groupBy = in_array($groupBy, ['account', 'customer', 'department', 'site'], true) ? $groupBy : 'account';
    $col = ['account' => ['a.account_id', "a.code || ' ' || a.name", 'JOIN mcp_accounts a ON a.account_id = x.income_account_id'],
            'customer' => ['c.customer_id', 'c.name', 'JOIN mcp_customers c ON c.customer_id = x.customer_id'],
            'department' => ['d.department_id', 'COALESCE(d.name, \'No department\')', 'LEFT JOIN mcp_departments d ON d.department_id = x.department_id'],
            'site' => ['s.site_id', 'COALESCE(s.name, \'No site\')', 'LEFT JOIN mcp_sites s ON s.site_id = x.site_id']][$groupBy];
    $sql = "SELECT {$col[0]} AS key, {$col[1]} AS label, sum(x.amount) AS amount, count(DISTINCT x.doc) AS documents FROM (
                SELECT il.income_account_id, i.customer_id, i.department_id, i.site_id, il.line_subtotal AS amount, i.invoice_id AS doc
                  FROM mcp_invoice_lines il JOIN mcp_invoices i ON i.invoice_id = il.invoice_id WHERE i.status NOT IN ('draft', 'void') AND i.issue_date BETWEEN :f AND :t
                UNION ALL
                SELECT cl.income_account_id, c.customer_id, NULL, NULL, -cl.line_subtotal, c.credit_note_id
                  FROM mcp_credit_note_lines cl JOIN mcp_credit_notes c ON c.credit_note_id = cl.credit_note_id WHERE c.status = 'issued' AND c.issue_date BETWEEN :f AND :t) x
            {$col[2]} GROUP BY 1, 2 ORDER BY 3 DESC";
    $st = $pdo->prepare($sql);
    $st->execute(['f' => $from, 't' => $to]);
    $rows = array_map(static fn (array $r): array => ['key' => $r['key'] === null ? null : (int) $r['key'], 'label' => $r['label'], 'amount' => $r['amount'], 'documents' => (int) $r['documents']], $st->fetchAll());
    return ['group_by' => $groupBy, 'from' => $from, 'to' => $to, 'rows' => $rows, 'total' => money_sum(array_column($rows, 'amount'))];
}

/** The receivable and payable control checks. */
function control_check(PDO $pdo, string $asOf): array
{
    $st = $pdo->prepare('SELECT control, subledger, ledger, difference FROM gl_control_check(:d) ORDER BY control');
    $st->execute(['d' => $asOf]);
    $rows = $st->fetchAll();
    $ok = true;
    foreach ($rows as &$r) {
        $r['ok'] = m_cmp((string) $r['difference'], '0') === 0;
        $ok = $ok && $r['ok'];
    }
    unset($r);
    return ['as_of' => $asOf, 'rows' => $rows, 'ok' => $ok];
}

/** One customer's statement: what was owed at $from, then each invoice, receipt and credit in date order with a running balance, the closing balance. */
function customer_statement(PDO $pdo, int $customerId, string $from, string $to): array
{
    $c = $pdo->prepare('SELECT customer_id, name FROM mcp_customers WHERE customer_id = :c');
    $c->execute(['c' => $customerId]);
    $cust = $c->fetch() ?: throw new DomainException('Not found.');
    $sql = "SELECT d, kind, number, id, amount, memo FROM (
                SELECT issue_date AS d, 'invoice' AS kind, number, invoice_id AS id, total AS amount, NULL AS memo FROM mcp_invoices WHERE customer_id = :c AND status NOT IN ('draft', 'void')
                UNION ALL SELECT received_on, 'receipt', number, receipt_id, -amount, reference FROM mcp_receipts WHERE customer_id = :c AND status = 'recorded'
                UNION ALL SELECT issue_date, 'credit_note', number, credit_note_id, -total, NULL FROM mcp_credit_notes WHERE customer_id = :c AND status = 'issued') t
            ORDER BY d, id";
    $st = $pdo->prepare($sql);
    $st->execute(['c' => $customerId]);
    $opening = '0.00';
    $rows = [];
    $running = '0.00';
    foreach ($st->fetchAll() as $r) {
        $running = m_add($running, (string) $r['amount']);
        if ($r['d'] < $from) {
            $opening = $running;
        } elseif ($r['d'] <= $to) {
            $rows[] = ['date' => $r['d'], 'kind' => $r['kind'], 'number' => $r['number'], 'id' => (int) $r['id'], 'memo' => $r['memo'], 'debit' => (float) $r['amount'] > 0 ? $r['amount'] : null,
                       'credit' => (float) $r['amount'] < 0 ? m_neg((string) $r['amount']) : null, 'balance' => $running];
        }
    }
    return ['customer_id' => (int) $cust['customer_id'], 'customer_name' => $cust['name'], 'from' => $from, 'to' => $to, 'opening' => $opening, 'rows' => $rows, 'closing' => $running, 'currency' => base_currency($pdo)];
}
