<?php
declare(strict_types=1);
/** Action `payment_run_add` (payment_run a draft, payment a draft of the same bank account and currency; log `payment_run.add`): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$rid = req_id('payment_run', 'payment_run_id') ?? refuse(422, 'Say which payment run.');
$pid = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
require_visible($pdo, 'mcp_payment_runs', 'payment_run_id', $rid, 'Payment run');
require_visible($pdo, 'mcp_payments', 'payment_id', $pid, 'Payment');
try {
    $pdo->beginTransaction();
    $run = payment_run_state($pdo, $rid);
    $a = run_add($pdo, $rid, $pid);
    log_activity($pdo, 'payment_run.add', 'payment_run', $rid, ap_keys(null, null, $run['bank_account_id']) + ['after' => ['number' => $a['run_number'], 'payment_id' => $pid, 'payment_number' => $a['payment_number'], 'amount' => $a['amount'], 'currency' => $run['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Added ' . $a['payment_number'] . ' to ' . $a['run_number'], $rid, gl_land(return_path('/payment-runs/' . $rid), 'saved', 'run-payments'), 'paymentRunChanged', ['payment_run_id' => $rid, 'payment_id' => $pid]);
