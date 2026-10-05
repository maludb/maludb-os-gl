<?php
declare(strict_types=1);
/** Action `payment_run_record` (an approved run; pay_date — the day the money moved; confirm; money_out for an agent; log `payment_run.record` with amount, payments, pay_date): ap.write. gl_payment_run_record() records and posts every approved payment. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('payment_run', 'payment_run_id') ?? refuse(422, 'Say which payment run.');
require_visible($pdo, 'mcp_payment_runs', 'payment_run_id', $id, 'Payment run');
$date = request_date('pay_date');
if ($date === false) {
    gl_refuse_fields(['pay_date' => 'The pay date is YYYY-MM-DD.']);
}
try {
    $pdo->beginTransaction();
    $run = payment_run_state($pdo, $id) ?? throw new DomainException('Not found.');
    $r = record_run($pdo, $id, $date ?? $run['pay_date'], (int) current_member_id());
    log_activity($pdo, 'payment_run.record', 'payment_run', $id, ap_keys(null, null, $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'payments' => $r['payments'], 'pay_date' => $r['pay_date']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Recorded ' . $r['number'] . ' as paid on ' . $r['pay_date'] . ' (' . $r['payments'] . ' payment' . ($r['payments'] === 1 ? '' : 's') . ')', $id, gl_land(return_path('/payment-runs/' . $id), 'recorded'), 'paymentRunChanged', ['payment_run_id' => $id, 'payments' => $r['payments']]);
