<?php
declare(strict_types=1);
/** /payments/ — money out: draft, approved, recorded (screen `payments-list`; documents.view). Params: status, vendor, bank_account, from, to, page. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$from = request_date('from'); $to = request_date('to');
if ($from === false || $to === false) {
    refuse(422, 'A date is YYYY-MM-DD.');
}
$filters = ['status' => request_string('status'), 'vendor' => request_integer('vendor'), 'bank_account' => request_integer('bank_account'), 'from' => $from ?? '', 'to' => $to ?? ''];
$page = max(1, request_integer('page') ?? 1);
$total = count_payments($pdo, $filters);
$rows = find_payments($pdo, $filters, PAYMENT_PAGE, ($page - 1) * PAYMENT_PAGE);
$may = ['write' => has_right('ap.write'), 'approve' => has_right('ap.approve')];
log_screen_view($pdo, 'payments-list');
if (wants_json()) {
    respond_screen(['payments' => array_map('present_payment_row', $rows), 'total' => $total, 'page' => $page, 'per_page' => PAYMENT_PAGE, 'filters' => $filters, 'may' => $may]);
}
render_screen('Payments', view('payments/list.php', ['rows' => $rows, 'total' => $total, 'page' => $page, 'filters' => $filters, 'may' => $may, 'vendors' => vendors_for_select($pdo), 'banks' => banks_for_select($pdo), 'here' => here_url(),
    'notice' => gl_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The draft payment was deleted.']])]), ['activeNav' => 'payments', 'screen' => 'payments-list', 'entity' => 'payment']);
