<?php
declare(strict_types=1);
/** /invoices/ — the invoices (screen `invoices-list`; documents.view). Params: status, overdue (a bucket or 1), as_of, customer, from, to, q, page; format=csv (exports.download, logged). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$from = request_date('from'); $to = request_date('to'); $asOf = request_date('as_of');
if ($from === false || $to === false || $asOf === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['status' => request_string('status'), 'overdue' => request_string('overdue'), 'as_of' => $asOf ?? '', 'customer' => request_integer('customer'), 'from' => $from ?? '', 'to' => $to ?? '', 'q' => request_string('q')];
$page = max(1, request_integer('page') ?? 1);
$total = count_invoices($pdo, $filters);
$rows = find_invoices($pdo, $filters, INVOICE_PAGE, ($page - 1) * INVOICE_PAGE);
$totals = invoice_totals($pdo, $filters);
$may = ['write' => has_right('ar.write'), 'export' => has_right('exports.download')];
if (request_string('format') === 'csv') {
    require_right('exports.download');
    $all = find_invoices($pdo, $filters, 10000, 0);
    log_activity($pdo, 'export.download', 'invoice', null, ['after' => ['what' => 'invoices', 'format' => 'csv', 'rows' => count($all)]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['number', 'customer', 'issued', 'due', 'status', 'currency', 'total', 'paid', 'balance']);
    foreach ($all as $i) {
        fputcsv($out, [$i['number'], $i['customer_name'], $i['issue_date'], $i['due_date'], $i['status'], $i['currency'], $i['total'], $i['amount_paid'], $i['balance_due']]);
    }
    fclose($out);
    exit;
}
log_screen_view($pdo, 'invoices-list');
if (wants_json()) {
    respond_screen(['invoices' => array_map('present_invoice_row', $rows), 'total' => $total, 'page' => $page, 'per_page' => INVOICE_PAGE, 'totals' => $totals, 'filters' => $filters, 'may' => $may]);
}
$data = ['rows' => $rows, 'total' => $total, 'page' => $page, 'totals' => $totals, 'filters' => $filters, 'may' => $may, 'customers' => customers_for_select($pdo), 'here' => here_url(),
         'notice' => gl_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The draft was deleted.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'invoice-list') {
    echo view('invoices/partials/list-region.php', $data);
    exit;
}
render_screen('Invoices', view('invoices/list.php', $data), ['activeNav' => 'invoices', 'screen' => 'invoices-list', 'entity' => 'invoice']);
