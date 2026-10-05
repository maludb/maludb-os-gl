<?php
declare(strict_types=1);
/** Action `payment_apply` (payment a draft or approved, bill, amount: empty = what fits; log `payment.apply` with amount, currency): ap.write. The guards refuse overpaying, another vendor, another currency. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$pid = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
$bid = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_payments', 'payment_id', $pid, 'Payment');
require_visible($pdo, 'mcp_bills', 'bill_id', $bid, 'Bill');
$errors = [];
$amount = req_money('amount', $errors, 'The amount');
if ($errors !== []) {
    gl_refuse_fields($errors);
}
try {
    $pdo->beginTransaction();
    $p = payment_state($pdo, $pid);
    $a = apply_payment($pdo, $pid, $bid, $amount, (int) current_member_id());
    log_activity($pdo, 'payment.apply', 'payment', $pid, ap_keys($p['vendor_id'], null, $p['bank_account_id']) + ['after' => ['number' => $p['number'], 'bill_id' => $bid, 'bill_number' => $a['bill_number'], 'amount' => $a['amount'], 'currency' => $p['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Applied ' . number_format((float) $a['amount'], 2) . ' of ' . $p['number'] . ' to ' . $a['bill_number'], $pid, gl_land(return_path('/payments/' . $pid), 'applied'), 'paymentChanged', ['payment_id' => $pid, 'bill_id' => $bid, 'amount' => $a['amount']]);
