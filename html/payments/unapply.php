<?php
declare(strict_types=1);
/** Action `payment_unapply` (payment a draft or approved, bill; log `payment.unapply`): ap.write. A recorded payment's applications are fixed. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$pid = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
$bid = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_payments', 'payment_id', $pid, 'Payment');
try {
    $pdo->beginTransaction();
    $p = payment_state($pdo, $pid);
    $a = unapply_payment($pdo, $pid, $bid);
    log_activity($pdo, 'payment.unapply', 'payment', $pid, ap_keys($p['vendor_id'], null, $p['bank_account_id']) + ['after' => ['number' => $p['number'], 'bill_id' => $bid, 'bill_number' => $a['bill_number'], 'amount' => $a['amount'], 'currency' => $p['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Took ' . $p['number'] . ' off ' . $a['bill_number'], $pid, gl_land(return_path('/payments/' . $pid), 'unapplied'), 'paymentChanged', ['payment_id' => $pid, 'bill_id' => $bid]);
