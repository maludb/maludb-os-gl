<?php
declare(strict_types=1);
/**
 * /reports/{report} — ar-aging, ap-aging, sales-tax, spend, revenue, customer-statement, control-check (screen `reports`; reports.view; no handler). Params: as_of, from, to, period, group_by, customer,
 * vendor, format (table | csv | print). A CSV or a print page is logged `export.download`; the table logs `screen.view` with after.report.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/periods.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/statements/present.php';
require_right('reports.view');
const REPORTS = ['ar-aging' => 'Who owes us', 'ap-aging' => 'Whom we owe', 'sales-tax' => 'Sales tax', 'spend' => 'Spend', 'revenue' => 'Revenue', 'customer-statement' => 'Customer statement', 'control-check' => 'Control check'];
$slug = request_string('report');
if (!isset(REPORTS[$slug])) {
    refuse(404, 'Report not found.');
}
$pdo = db();
$range = period_range($pdo, ['period' => request_string('period'), 'from' => valid_date_param('from') ?? '', 'to' => valid_date_param('to') ?? '', 'as_of' => valid_date_param('as_of') ?? '']);
$currency = base_currency($pdo);
$customer = request_integer('customer');
$vendor = request_integer('vendor');
$groupBy = request_string('group_by', 'account');
if ($groupBy !== '' && !isset(REPORT_GROUPS[$groupBy])) {
    refuse(422, 'Group by account, vendor, customer, department or site.');
}
$groupBy = $groupBy === '' ? 'account' : $groupBy;
if ($slug === 'spend' && $groupBy === 'customer') {
    $groupBy = 'account';
}
if ($slug === 'revenue' && $groupBy === 'vendor') {
    $groupBy = 'account';
}
$asOf = ($_GET['as_of'] ?? '') !== '' ? $range['as_of'] : (($_GET['period'] ?? '') !== '' || ($_GET['to'] ?? '') !== '' ? $range['to'] : date('Y-m-d'));
$tables = [];
$extra = [];
$data = null;
switch ($slug) {
    case 'ar-aging':
        $data = ar_aging($pdo, $asOf, $customer);
        $tables = aging_tables($data, 'customer', 'Who owes us');
        break;
    case 'ap-aging':
        $data = ap_aging($pdo, $asOf, $vendor);
        $tables = aging_tables($data, 'vendor', 'Whom we owe');
        break;
    case 'sales-tax':
        $data = sales_tax($pdo, $range['from'], $range['to']);
        $tables = [sales_tax_table($data, $currency)];
        break;
    case 'spend':
        $data = spend($pdo, $range['from'], $range['to'], $groupBy);
        $tables = [grouped_table($data, 'Spend by ' . strtolower(REPORT_GROUPS[$groupBy]), $currency, '/bills/', 'Bills')];
        break;
    case 'revenue':
        $data = revenue($pdo, $range['from'], $range['to'], $groupBy);
        $tables = [grouped_table($data, 'Revenue by ' . strtolower(REPORT_GROUPS[$groupBy]), $currency, '/invoices/', 'Documents')];
        break;
    case 'customer-statement':
        if ($customer !== null) {
            try {
                $data = customer_statement($pdo, $customer, $range['from'], $range['to']);
            } catch (DomainException) {
                refuse(404, 'Customer not found.');
            }
            $tables = [customer_statement_table($data)];
        }
        break;
    case 'control-check':
        $data = control_check($pdo, $asOf);
        $tables = [control_table($data, $currency)];
        $ar = $pdo->prepare("SELECT COALESCE(gl_account_balance((SELECT ar_account_id FROM mcp_settings), :d), 0)");
        $ar->execute(['d' => $asOf]);
        $extra['ledger_receivable'] = (string) $ar->fetchColumn();
        break;
}
$pt = posted_through($pdo);
$format = request_string('format');
if (($format === 'csv' || $format === 'print') && $tables !== []) {
    log_activity($pdo, 'export.download', 'report', null, ['after' => ['what' => $slug, 'period' => $range['label'], 'format' => $format, 'from' => $range['from'], 'to' => $range['to'], 'as_of' => $asOf]]);
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . $asOf . '.csv"');
        echo statement_csv($tables);
        exit;
    }
    $biz = $pdo->query('SELECT business_name, business_address FROM mcp_settings')->fetch() ?: [];
    echo view('statements/print.php', ['title' => REPORTS[$slug], 'table' => null, 'tables' => $tables, 'range' => $range, 'pt' => $pt, 'biz' => $biz, 'hasBooks' => true]);
    exit;
}
log_screen_view($pdo, 'reports', ['after' => ['report' => $slug]]);
if (wants_json()) {
    respond_screen(['report' => $slug, 'title' => REPORTS[$slug], 'period' => $range, 'as_of' => $asOf, 'currency' => $currency, 'posted_through' => $pt, 'tables' => $tables, 'data' => $data] + $extra);
}
render_screen(REPORTS[$slug], view('reports/report.php', ['slug' => $slug, 'title' => REPORTS[$slug], 'tables' => $tables, 'data' => $data, 'range' => $range, 'asOf' => $asOf, 'groupBy' => $groupBy, 'customer' => $customer, 'vendor' => $vendor, 'pt' => $pt, 'extra' => $extra,
    'periods' => period_choices($pdo), 'customers' => $pdo->query('SELECT customer_id, name FROM mcp_customers WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll(), 'vendors' => $pdo->query('SELECT vendor_id, name FROM mcp_vendors WHERE archived_at IS NULL ORDER BY lower(name)')->fetchAll(),
    'currency' => $currency, 'mixed' => array_values(array_diff(books_currencies($pdo), [$currency]))]), ['activeNav' => in_array($slug, ['ar-aging', 'ap-aging'], true) ? $slug : 'statements', 'screen' => 'reports', 'entity' => 'report', 'recordId' => $slug]);
