<?php
declare(strict_types=1);
/** Action `payment_run_approve` (a draft run with payments, every one fully applied; first_cheque_number — the cheque payments without a number are numbered from it in sequence; confirm; money_out for an agent; log `payment_run.approve` with amount, payments, cheques[]): ap.approve. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.approve');
$pdo = db();
$id = req_id('payment_run', 'payment_run_id') ?? refuse(422, 'Say which payment run.');
require_visible($pdo, 'mcp_payment_runs', 'payment_run_id', $id, 'Payment run');
$first = req_has('first_cheque_number') && (string) req_val('first_cheque_number') !== '' ? trim((string) req_val('first_cheque_number')) : null;
if ($first !== null && !ctype_digit($first)) {
    gl_refuse_fields(['first_cheque_number' => 'The first cheque number is digits, like 2001.']);
}
try {
    $pdo->beginTransaction();
    $r = approve_run($pdo, $id, $first, (int) current_member_id());
    log_activity($pdo, 'payment_run.approve', 'payment_run', $id, ap_keys(null, null, $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['total'], 'currency' => $r['currency'], 'payments' => $r['payments'], 'cheques' => $r['cheques']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Approved ' . $r['number'] . ' (' . $r['payments'] . ' payment' . ($r['payments'] === 1 ? '' : 's') . ', ' . number_format((float) $r['total'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/payment-runs/' . $id), 'approved'), 'paymentRunChanged', ['payment_run_id' => $id, 'cheques' => $r['cheques']]);
