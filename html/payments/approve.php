<?php
declare(strict_types=1);
/** Action `payment_approve` (a draft fully applied to bills; confirm; money_out for an agent; log `payment.approve` with amount, currency, applied[]): ap.approve. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.approve');
$pdo = db();
$id = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
require_visible($pdo, 'mcp_payments', 'payment_id', $id, 'Payment');
try {
    $pdo->beginTransaction();
    $r = approve_payment($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'payment.approve', 'payment', $id, ap_keys($r['vendor_id'], null, $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'applied' => $r['applied']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Approved ' . $r['number'] . ' (' . number_format((float) $r['amount'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/payments/' . $id), 'approved'), 'paymentChanged', ['payment_id' => $id, 'number' => $r['number'], 'amount' => $r['amount']]);
