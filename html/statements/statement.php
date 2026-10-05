<?php
declare(strict_types=1);
/**
 * /statements/{statement} — pl, balance-sheet, trial-balance, cash-flow (screen `statement`; reports.view; no handler). Params: period, from, to, as_of, compare, department, site,
 * format (table | csv | print). A CSV or a print page is logged `export.download` (after.what, after.period, after.format); the table logs `screen.view`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/periods.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/present.php';
require_right('reports.view');
const STATEMENTS = ['pl' => 'Profit and loss', 'balance-sheet' => 'Balance sheet', 'trial-balance' => 'Trial balance', 'cash-flow' => 'Cash flow'];
$slug = request_string('statement');
if (!isset(STATEMENTS[$slug])) {
    refuse(404, 'Statement not found.');
}
$pdo = db();
$range = period_range($pdo, ['period' => request_string('period'), 'from' => valid_date_param('from') ?? '', 'to' => valid_date_param('to') ?? '', 'as_of' => valid_date_param('as_of') ?? '']);
$mode = request_string('compare');
if (!isset(COMPARE_MODES[$mode])) {
    refuse(422, 'Compare to none, previous or last_year.');
}
$cmp = compare_range($range, $mode);
$dept = $slug === 'pl' ? request_integer('department') : null;
$site = $slug === 'pl' ? request_integer('site') : null;
$data = match ($slug) {
    'pl' => profit_and_loss($pdo, $range['from'], $range['to'], $dept, $site, $cmp),
    'balance-sheet' => balance_sheet($pdo, $range['as_of'], $cmp === null ? null : $cmp['as_of']),
    'trial-balance' => trial_balance($pdo, $range['as_of']),
    'cash-flow' => cash_flow($pdo, $range['from'], $range['to']),
};
$table = match ($slug) { 'pl' => pl_table($data), 'balance-sheet' => bs_table($data), 'trial-balance' => tb_table($data), 'cash-flow' => cf_table($data) };
$hasBooks = $data['has_books'] ?? true;
if ($slug === 'trial-balance') {
    $hasBooks = $data['rows'] !== [];
}
$pt = posted_through($pdo);
$currency = base_currency($pdo);
$format = request_string('format');
if ($format === 'csv' || $format === 'print') {
    log_activity($pdo, 'export.download', 'statement', null, ['after' => ['what' => $slug, 'period' => $range['label'], 'format' => $format, 'from' => $range['from'], 'to' => $range['to'], 'as_of' => $range['as_of']]]);
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $range['to'] . '.csv"');
        echo statement_csv([$table]);
        exit;
    }
    $biz = $pdo->query('SELECT business_name, business_address FROM mcp_settings')->fetch() ?: [];
    echo view('statements/print.php', ['title' => STATEMENTS[$slug], 'table' => $table, 'range' => $range, 'pt' => $pt, 'biz' => $biz, 'hasBooks' => $hasBooks]);
    exit;
}
log_screen_view($pdo, 'statement', ['after' => ['statement' => $slug]]);
if (wants_json()) {
    respond_screen(['statement' => $slug, 'title' => STATEMENTS[$slug], 'period' => $range, 'compare' => $cmp, 'department' => $dept, 'site' => $site, 'currency' => $currency, 'posted_through' => $pt, 'has_books' => $hasBooks, 'table' => $table, 'data' => $data]);
}
render_screen(STATEMENTS[$slug], view('statements/statement.php', ['slug' => $slug, 'title' => STATEMENTS[$slug], 'table' => $table, 'range' => $range, 'mode' => $mode, 'cmp' => $cmp, 'dept' => $dept, 'site' => $site, 'pt' => $pt, 'hasBooks' => $hasBooks,
    'periods' => period_choices($pdo), 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'currency' => $currency, 'mixed' => array_values(array_diff(books_currencies($pdo), [$currency]))]),
    ['activeNav' => 'statements', 'screen' => 'statement', 'entity' => 'statement', 'recordId' => $slug]);
