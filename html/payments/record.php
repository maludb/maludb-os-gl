<?php
declare(strict_types=1);
/**
 * Action `payment_record` (an approved payment; the money was moved by a person; payment_date — the day it moved, cheque_number, reference; confirm; money_out for an agent; log `payment.record` with number, amount, method, cheque_number, bank_account_id, applied[],
 * vendor_id, journal_entry_id): ap.write. gl_payment_record() posts DR AP (vendor) / CR the bank's ledger account and recalculates the bills.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$id = req_id('payment', 'payment_id') ?? refuse(422, 'Say which payment.');
require_visible($pdo, 'mcp_payments', 'payment_id', $id, 'Payment');
$errors = [];
$f = [];
if (req_has('payment_date')) {
    $d = request_date('payment_date');
    if ($d === false) {
        $errors['payment_date'] = 'The date is YYYY-MM-DD.';
    } elseif ($d !== null) {
        $f['payment_date'] = $d;
    }
}
if (req_has('cheque_number')) {
    $f['cheque_number'] = (string) req_val('cheque_number') === '' ? null : mb_substr((string) req_val('cheque_number'), 0, 40);
}
if (req_has('reference')) {
    $f['reference'] = (string) req_val('reference') === '' ? null : mb_substr((string) req_val('reference'), 0, 120);
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
try {
    $pdo->beginTransaction();
    $r = record_payment($pdo, $id, $f, (int) current_member_id());
    log_activity($pdo, 'payment.record', 'payment', $id, ap_keys($r['vendor_id'], $r['journal_entry_id'], $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'amount' => $r['amount'], 'currency' => $r['currency'], 'method' => $r['method'], 'cheque_number' => $r['cheque_number'], 'bank_account_id' => $r['bank_account_id'], 'applied' => $r['applied']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Recorded ' . $r['number'] . ' as paid on ' . $r['payment_date'] . ' (' . number_format((float) $r['amount'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/payments/' . $id), 'recorded'), 'paymentChanged', ['payment_id' => $id, 'number' => $r['number'], 'applied' => $r['applied']]);
