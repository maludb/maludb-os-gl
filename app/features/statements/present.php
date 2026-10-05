<?php
declare(strict_types=1);

/**
 * Statements and reports as generic tables (slice 3): one shape for the screen, the print page and the CSV so they never disagree.
 * A table: ['title', 'currency', 'columns' => [labels], 'text_cols' => n (leading text columns; the rest are money), 'rows' => [row], 'status' => ?['ok' => bool, 'text' => string]].
 * A row: ['kind' => head|row|subtotal|total|check, 'cells' => [...], 'url' => ?string (links the last text cell), 'ok' => ?bool (a check row), 'links' => [cell index => url]].
 */

function t_row(string $kind, array $cells, ?string $url = null, ?bool $ok = null, array $links = []): array
{
    return ['kind' => $kind, 'cells' => $cells, 'url' => $url, 'ok' => $ok, 'links' => $links];       // links: cell index => url (an ageing bucket opens its documents)
}

function pct_cell(?string $p): string
{
    return $p === null ? '' : $p . '%';
}

function account_link(int $id, string $from, string $to, ?int $dept = null, ?int $site = null): string
{
    return '/accounts/' . $id . '?' . http_build_query(array_filter(['from' => $from, 'to' => $to, 'department' => $dept, 'site' => $site], static fn ($v) => $v !== null));
}

function tb_table(array $d): array
{
    $rows = [];
    foreach ($d['rows'] as $r) {
        $rows[] = t_row('row', [$r['code'], $r['name'], $r['debit'], $r['credit']], account_link($r['account_id'], date('Y-01-01', strtotime($d['as_of'])), $d['as_of']));
    }
    $rows[] = t_row('total', ['', 'Total', $d['debit'], $d['credit']]);
    return ['title' => 'Trial balance as of ' . format_date($d['as_of']), 'currency' => $d['currency'], 'columns' => ['Code', 'Account', 'Debit', 'Credit'], 'text_cols' => 2, 'rows' => $rows,
            'status' => ['ok' => $d['balanced'], 'text' => $d['balanced'] ? 'Debits equal credits' : 'Debits and credits differ by ' . $d['difference']]];
}

function pl_table(array $d): array
{
    $cmp = $d['compare'] !== null;
    $cols = $cmp ? ['Code', 'Account', 'Amount', 'Prior', 'Variance', '%'] : ['Code', 'Account', 'Amount'];
    $line = static function (string $kind, string $code, string $name, string $amount, ?string $prior, ?string $url = null) use ($cmp): array {
        if (!$cmp) {
            return t_row($kind, [$code, $name, $amount], $url);
        }
        [$v, $p] = variance($amount, $prior);
        return t_row($kind, [$code, $name, $amount, $prior ?? '', $v ?? '', pct_cell($p)], $url);
    };
    $rows = [t_row('head', ['', 'Income'] + array_fill(2, count($cols) - 2, ''))];
    foreach ($d['income'] as $r) {
        $rows[] = $line('row', $r['code'], $r['name'], $r['amount'], $r['prior'], account_link($r['account_id'], $d['from'], $d['to'], $d['department'], $d['site']));
    }
    $rows[] = $line('subtotal', '', 'Total income', $d['income_total'], $d['income_prior']);
    foreach ($d['expense_groups'] as $g) {
        $rows[] = t_row('head', ['', $g['label']] + array_fill(2, count($cols) - 2, ''));
        foreach ($g['rows'] as $r) {
            $rows[] = $line('row', $r['code'], $r['name'], $r['amount'], $r['prior'], account_link($r['account_id'], $d['from'], $d['to'], $d['department'], $d['site']));
        }
        $rows[] = $line('subtotal', '', 'Total ' . strtolower($g['label']), $g['total'], $g['prior']);
    }
    $rows[] = $line('total', '', 'Net income', $d['net_income'], $d['net_prior']);
    return ['title' => 'Profit and loss, ' . format_date($d['from']) . ' – ' . format_date($d['to']), 'currency' => $d['currency'], 'columns' => $cols, 'text_cols' => 2, 'rows' => $rows, 'status' => null];
}

function bs_table(array $d): array
{
    $cmp = $d['compare_as_of'] !== null;
    $cols = $cmp ? ['Code', 'Account', format_date($d['as_of']), format_date($d['compare_as_of']), 'Variance'] : ['Code', 'Account', 'Balance'];
    $line = static function (string $kind, string $code, string $name, string $bal, ?string $prior, ?string $url = null) use ($cmp): array {
        return $cmp ? t_row($kind, [$code, $name, $bal, $prior ?? '', m_sub($bal, $prior ?? '0')], $url) : t_row($kind, [$code, $name, $bal], $url);
    };
    $titles = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'];
    $rows = [];
    foreach ($d['sections'] as $s => $items) {
        $rows[] = t_row('head', ['', $titles[$s]] + array_fill(2, count($cols) - 2, ''));
        foreach ($items as $r) {
            $rows[] = $line('row', $r['code'] ?? '', $r['name'], $r['balance'], $r['prior'], $r['account_id'] === null ? null : account_link($r['account_id'], date('Y-01-01', strtotime($d['as_of'])), $d['as_of']));
        }
        $rows[] = $line('subtotal', '', 'Total ' . strtolower($titles[$s]), $d['totals'][$s]['balance'], $d['totals'][$s]['prior']);
    }
    $c = $d['check'];
    $text = 'Assets ' . number_format((float) $c['assets'], 2) . ' = Liabilities ' . number_format((float) $c['liabilities'], 2) . ' + Equity ' . number_format((float) $c['equity'], 2) . ($d['holds'] ? ' ✓' : ' — does not hold, off by ' . $c['difference']);
    return ['title' => 'Balance sheet as of ' . format_date($d['as_of']), 'currency' => $d['currency'], 'columns' => $cols, 'text_cols' => 2, 'rows' => $rows, 'status' => ['ok' => $d['holds'], 'text' => $text]];
}

function cf_table(array $d): array
{
    $titles = ['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'];
    $rows = [];
    foreach ($d['sections'] as $s => $items) {
        if ($items === [] && $s !== 'operating') {
            continue;
        }
        $rows[] = t_row('head', ['', $titles[$s], '']);
        foreach ($items as $r) {
            $rows[] = t_row('row', [$r['code'] ?? '', $r['label'], $r['amount']], $r['account_id'] === null ? null : account_link($r['account_id'], $d['from'], $d['to']));
        }
        $rows[] = t_row('subtotal', ['', 'Cash from ' . $s, $d['subtotals'][$s]]);
    }
    $rows[] = t_row('total', ['', 'Net change in cash', $d['net_change']]);
    $rows[] = t_row('row', ['', 'Cash at start', $d['cash_start']]);
    $rows[] = t_row('total', ['', 'Cash at end', $d['cash_end']]);
    $zero = m_cmp((string) $d['check'], '0') === 0;
    $rows[] = t_row('check', ['', 'Check: end − start − net change', $d['check']], null, $zero);
    return ['title' => 'Cash flow, ' . format_date($d['from']) . ' – ' . format_date($d['to']), 'currency' => $d['currency'], 'columns' => ['Code', 'Line', 'Amount'], 'text_cols' => 2, 'rows' => $rows,
            'status' => ['ok' => $zero, 'text' => $zero ? 'Cash at the end ties to the change' : 'The cash flow is off by ' . $d['check']]];
}

/** The aging tables, one per currency: party rows with the five buckets and a total; $kind 'customer' or 'vendor' picks the links. */
function aging_tables(array $d, string $kind, string $title): array
{
    $tables = [];
    foreach ($d['tables'] as $t) {
        $rows = [];
        foreach ($t['rows'] as $r) {
            $list = $kind === 'customer' ? '/invoices/' : '/bills/';
            $who = [$kind => $r['party_id'], 'as_of' => $d['as_of']];
            $links = [];
            foreach ([1 => 'current', 2 => '1_30', 3 => '31_60', 4 => '61_90', 5 => 'over_90'] as $i => $bucket) {
                $links[$i] = $list . '?' . http_build_query($who + ['overdue' => $bucket]);
            }
            $links[6] = $list . '?' . http_build_query($who + ['overdue' => 'any']);
            $rows[] = t_row('row', [$r['party_name'], $r['current_due'], $r['days_1_30'], $r['days_31_60'], $r['days_61_90'], $r['days_over_90'], $r['total'], (string) $r['documents']],
                $kind === 'customer' ? '/customers/' . $r['party_id'] : '/vendors/' . $r['party_id'], null, $links);
        }
        $x = $t['totals'];
        $rows[] = t_row('total', ['Total', $x['current_due'], $x['days_1_30'], $x['days_31_60'], $x['days_61_90'], $x['days_over_90'], $x['total'], (string) $t['documents']]);
        $tables[] = ['title' => $title . ' as of ' . format_date($d['as_of']), 'currency' => $t['currency'], 'columns' => [ucfirst($kind), 'Current', '1–30', '31–60', '61–90', 'Over 90', 'Total', 'Open'], 'text_cols' => 1, 'money_to' => 7, 'rows' => $rows, 'status' => null];
    }
    return $tables;
}

function grouped_table(array $d, string $title, string $currency, string $listUrl, string $docsLabel): array
{
    $rows = [];
    foreach ($d['rows'] as $r) {
        $url = $listUrl . '?' . http_build_query(array_filter([$d['group_by'] => $r['key'], 'from' => $d['from'], 'to' => $d['to']], static fn ($v) => $v !== null));
        $rows[] = t_row('row', [$r['label'], $r['amount'], (string) $r['documents']], $r['key'] === null ? null : $url);
    }
    $rows[] = t_row('total', ['Total', $d['total'], '']);
    return ['title' => $title . ', ' . format_date($d['from']) . ' – ' . format_date($d['to']), 'currency' => $currency, 'columns' => [REPORT_GROUPS[$d['group_by']] ?? 'Group', 'Amount', $docsLabel], 'text_cols' => 1, 'money_to' => 2, 'rows' => $rows, 'status' => null];
}

function sales_tax_table(array $d, string $currency): array
{
    $rows = [];
    foreach ($d['rows'] as $r) {
        $rows[] = t_row('row', [$r['name'], rtrim(rtrim(number_format((float) ($r['rate'] ?? 0), 4, '.', ''), '0'), '.') . '%', $r['taxable'], $r['tax']]);
    }
    $rows[] = t_row('total', ['Total', '', $d['taxable_total'], $d['tax_total']]);
    return ['title' => 'Sales tax, ' . format_date($d['from']) . ' – ' . format_date($d['to']), 'currency' => $currency, 'columns' => ['Tax rate', 'Rate', 'Taxable sales', 'Tax charged'], 'text_cols' => 2, 'rows' => $rows, 'status' => null];
}

function control_table(array $d, string $currency): array
{
    $rows = [];
    foreach ($d['rows'] as $r) {
        $rows[] = t_row('row', [ucfirst($r['control']), $r['subledger'], $r['ledger'], $r['difference']], null, $r['ok']);
    }
    return ['title' => 'Control check as of ' . format_date($d['as_of']), 'currency' => $currency, 'columns' => ['Control', 'Subledger', 'Ledger', 'Difference'], 'text_cols' => 1, 'rows' => $rows,
            'status' => ['ok' => $d['ok'], 'text' => $d['ok'] ? 'The subledgers tie to the ledger' : 'A control account differs from its subledger']];
}

function customer_statement_table(array $d): array
{
    $words = ['invoice' => 'Invoice', 'receipt' => 'Receipt', 'credit_note' => 'Credit note'];
    $urls = ['invoice' => '/invoices/', 'receipt' => '/receipts/', 'credit_note' => '/credit-notes/'];
    $rows = [t_row('row', [format_date($d['from']), 'Opening balance', '', '', $d['opening']])];
    foreach ($d['rows'] as $r) {
        $rows[] = t_row('row', [format_date($r['date']), ($words[$r['kind']] ?? $r['kind']) . ' ' . $r['number'], $r['debit'] ?? '', $r['credit'] ?? '', $r['balance']], ($urls[$r['kind']] ?? '/') . $r['id']);
    }
    $rows[] = t_row('total', [format_date($d['to']), 'Closing balance', '', '', $d['closing']]);
    return ['title' => 'Statement for ' . $d['customer_name'] . ', ' . format_date($d['from']) . ' – ' . format_date($d['to']), 'currency' => $d['currency'], 'columns' => ['Date', 'Document', 'Charged', 'Paid or credited', 'Balance'], 'text_cols' => 2, 'rows' => $rows, 'status' => null];
}

/** One header pair per table (the title, the currency), the column names, the rows, and a TOTAL row where the table has a total. */
function statement_csv(array $tables): string
{
    $out = fopen('php://temp', 'w+');
    foreach ($tables as $i => $t) {
        if ($i > 0) {
            fputcsv($out, []);
        }
        fputcsv($out, [$t['title']]);
        fputcsv($out, ['Currency', $t['currency']]);
        fputcsv($out, $t['columns']);
        foreach ($t['rows'] as $r) {
            if ($r['kind'] === 'head') {
                fputcsv($out, array_merge([strtoupper((string) ($r['cells'][1] ?? ''))], array_fill(0, max(0, count($t['columns']) - 1), '')));
                continue;
            }
            $cells = $r['cells'];
            if ($r['kind'] === 'total') {
                $cells[$t['text_cols'] - 1] = 'TOTAL' . (($cells[$t['text_cols'] - 1] ?? '') !== '' ? ' ' . $cells[$t['text_cols'] - 1] : '');
            }
            fputcsv($out, $cells);
        }
    }
    rewind($out);
    return (string) stream_get_contents($out);
}
