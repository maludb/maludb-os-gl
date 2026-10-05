<?php
declare(strict_types=1);
/** Action `payment_void` (not matched to a bank line; reason; confirm; money_out for an agent; log `payment.void`): journal.override. gl_payment_void() reverses the entry and the bills are owed again. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
require_visible($pdo, 'mcp_payments', 'payment_id', $id, 'Payment');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $r = void_payment($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'payment.void', 'payment', $id, ap_keys($r['vendor_id'], $r['journal_entry_id'], $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'amount' => $r['amount'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Voided ' . $r['number'], $id, gl_land(return_path('/payments/' . $id), 'voided'), 'paymentChanged', ['payment_id' => $id]);
