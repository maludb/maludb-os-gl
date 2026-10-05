<?php
declare(strict_types=1);
/** /receipts/ — money received (screen `receipts-list`; documents.view). Params: customer, from, to, unapplied, undeposited, page. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
require_right('documents.view');
$pdo = db();
$from = request_date('from'); $to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['customer' => request_integer('customer'), 'from' => $from ?? '', 'to' => $to ?? '', 'unapplied' => request_bool('unapplied'), 'undeposited' => request_bool('undeposited')];
$page = max(1, request_integer('page') ?? 1);
$total = count_receipts($pdo, $filters);
$rows = find_receipts($pdo, $filters, RECEIPT_PAGE, ($page - 1) * RECEIPT_PAGE);
$may = ['write' => has_right('ar.write')];
log_screen_view($pdo, 'receipts-list');
if (wants_json()) {
    respond_screen(['receipts' => array_map('present_receipt_row', $rows), 'total' => $total, 'page' => $page, 'per_page' => RECEIPT_PAGE, 'filters' => $filters, 'may' => $may]);
}
$data = ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'may' => $may, 'pdo' => $pdo, 'here' => here_url(), 'claims' => count(find_claims($pdo, 'submitted')),
         'notice' => gl_notice($_GET['notice'] ?? null, ['voided' => ['warning', 'The receipt was voided.']])];
if (is_htmx_request() && !is_htmx_boosted() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'receipt-list') {
    echo view('receipts/partials/list-region.php', $data);
    exit;
}
render_screen('Receipts', view('receipts/list.php', $data), ['activeNav' => 'receipts', 'screen' => 'receipts-list', 'entity' => 'receipt']);
