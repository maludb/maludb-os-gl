<?php
declare(strict_types=1);
/** /payment-runs/ — the payment runs (screen `payment-runs-list`; documents.view). Param: status. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
require_right('documents.view');
$pdo = db();
$filters = ['status' => request_string('status')];
$rows = find_payment_runs($pdo, $filters);
$may = ['write' => has_right('ap.write')];
log_screen_view($pdo, 'payment-runs-list');
if (wants_json()) {
    respond_screen(['payment_runs' => array_map(static fn (array $r): array => ['payment_run_id' => $r['payment_run_id'], 'number' => $r['number'], 'pay_date' => $r['pay_date'], 'bank_account' => ['bank_account_id' => $r['bank_account_id'], 'name' => $r['bank_account_name']], 'currency' => $r['currency'], 'status' => $r['status'], 'total' => $r['total'], 'payments' => $r['payment_count']], $rows), 'filters' => $filters, 'may' => $may]);
}
render_screen('Payment runs', view('payment_runs/list.php', ['rows' => $rows, 'filters' => $filters, 'may' => $may, 'here' => here_url()]), ['activeNav' => 'payment-runs', 'screen' => 'payment-runs-list', 'entity' => 'paymentRun']);
