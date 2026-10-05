<?php
declare(strict_types=1);
/** Action `payment_run_void` (reason; its unrecorded payments are voided, a recorded one is left and the run reads "partly recorded"; confirm; money_out for an agent; log `payment_run.void`): journal.override. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('payment_run', 'payment_run_id') ?? refuse(422, 'Say which payment run.');
require_visible($pdo, 'mcp_payment_runs', 'payment_run_id', $id, 'Payment run');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $r = void_run($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'payment_run.void', 'payment_run', $id, ap_keys(null, null, $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'voided_payments' => $r['voided'], 'kept_recorded' => $r['kept'], 'amount' => $r['total'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done($r['partly_recorded'] ? 'Voided the unrecorded payments of ' . $r['number'] . '; ' . $r['kept'] . ' recorded payment' . ($r['kept'] === 1 ? ' stays' : 's stay') . ' (partly recorded)' : 'Voided ' . $r['number'], $id, gl_land(return_path('/payment-runs/' . $id), 'voided'), 'paymentRunChanged', ['payment_run_id' => $id, 'partly_recorded' => $r['partly_recorded']]);
