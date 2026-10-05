<?php
declare(strict_types=1);
/** /bills/ — the bills (screen `bills-list`; documents.view). Tabs: awaiting approval, approved, due this week, all. Params: status (a status or `unpaid`), vendor, due_within, no_document, source, overdue (a bucket or 1), as_of, from, to, q, page; format=csv (exports.download, logged). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$from = request_date('from'); $to = request_date('to'); $asOf = request_date('as_of');
if ($from === false || $to === false || $asOf === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['status' => request_string('status'), 'vendor' => request_integer('vendor'), 'due_within' => request_integer('due_within'), 'no_document' => request_bool('no_document'), 'source' => request_string('source'), 'overdue' => request_string('overdue'),
            'as_of' => $asOf ?? '', 'from' => $from ?? '', 'to' => $to ?? '', 'q' => request_string('q')];
$page = max(1, request_integer('page') ?? 1);
$total = count_bills($pdo, $filters);
$rows = find_bills($pdo, $filters, BILL_PAGE, ($page - 1) * BILL_PAGE);
$totals = bill_totals($pdo, $filters);
$may = ['write' => has_right('ap.write'), 'approve' => has_right('ap.approve'), 'export' => has_right('exports.download')];
if (request_string('format') === 'csv') {
    require_right('exports.download');
    $all = find_bills($pdo, $filters, 10000, 0);
    log_activity($pdo, 'export.download', 'bill', null, ['after' => ['what' => 'bills', 'format' => 'csv', 'rows' => count($all)]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bills.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['number', 'vendor', 'their_reference', 'dated', 'due', 'status', 'currency', 'total', 'paid', 'balance', 'has_document']);
    foreach ($all as $b) {
        fputcsv($out, [$b['number'], $b['vendor_name'], $b['vendor_ref'], $b['bill_date'], $b['due_date'], $b['status'], $b['currency'], $b['total'], $b['amount_paid'], $b['balance_due'], $b['has_attachment'] ? 'yes' : 'no']);
    }
    fclose($out);
    exit;
}
$counts = bill_tab_counts($pdo);
log_screen_view($pdo, 'bills-list');
if (wants_json()) {
    respond_screen(['bills' => array_map('present_bill_row', $rows), 'total' => $total, 'page' => $page, 'per_page' => BILL_PAGE, 'totals' => $totals, 'counts' => $counts, 'filters' => $filters, 'may' => $may]);
}
$data = ['rows' => $rows, 'total' => $total, 'page' => $page, 'totals' => $totals, 'counts' => $counts, 'filters' => $filters, 'may' => $may, 'vendors' => vendors_for_select($pdo), 'here' => here_url(),
         'notice' => gl_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The draft was deleted.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'bill-list') {
    echo view('bills/partials/list-region.php', $data);
    exit;
}
render_screen('Bills', view('bills/list.php', $data), ['activeNav' => 'bills', 'screen' => 'bills-list', 'entity' => 'bill']);
