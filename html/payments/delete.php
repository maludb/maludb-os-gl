<?php
declare(strict_types=1);
/** Action `payment_delete` (a draft; confirm; deletion for an agent; log `payment.delete` BEFORE the delete): ap.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
require_visible($pdo, 'mcp_payments', 'payment_id', $id, 'Payment');
try {
    $pdo->beginTransaction();
    $p = payment_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'payment.delete', 'payment', $id, ap_keys($p['vendor_id'], null, $p['bank_account_id']) + ['before' => payment_log_state($p) + ['number' => $p['number']]]);
    delete_payment($pdo, $id);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Deleted the draft payment ' . $p['number'], $id, gl_land(return_path('/payments/'), 'deleted'), 'paymentChanged');
