<?php
declare(strict_types=1);
/** Action `payment_run_create` (bank_account, pay_date, memo, bills[] — one payment per vendor for these bills, due_by — or every approved bill due by that date in the bank account's currency; log `payment_run.create`): ap.write. A draft run of draft payments, one per vendor with the vendor's method; cheque numbers wait for the approval. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('ap.write');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$defaultBank = ($d = $pdo->query('SELECT default_bank_account_id FROM gl_settings WHERE id = 1')->fetchColumn()) ? (int) $d : null;
$bank = gl_ref($pdo, req_has('bank_account') ? 'bank_account' : 'bank_account_id', $defaultBank, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors, false);
$pay = request_date('pay_date');
$dueBy = request_date('due_by');
if ($pay === false) {
    $errors['pay_date'] = 'The pay date is YYYY-MM-DD.';
}
if ($dueBy === false) {
    $errors['due_by'] = 'The due-by date is YYYY-MM-DD.';
}
$billIds = array_values(array_unique(array_map('intval', array_filter(request_list('bills') ?? [], static fn (string $v): bool => ctype_digit($v)))));
if ($billIds === [] && $dueBy === null && !isset($errors['due_by'])) {
    $errors['bills'] = 'Name the bills to pay, or a due-by date.';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['bank_account_id' => $bank, 'pay_date' => $pay ?? date('Y-m-d'), 'due_by' => $dueBy, 'memo' => req_has('memo') && (string) req_val('memo') !== '' ? mb_substr((string) req_val('memo'), 0, 500) : null];
try {
    $pdo->beginTransaction();
    foreach ($billIds as $b) {
        require_visible($pdo, 'mcp_bills', 'bill_id', $b, 'Bill');
    }
    $r = create_payment_run($pdo, $f, $billIds, $me);
    $run = payment_run_state($pdo, $r['payment_run_id']);
    log_activity($pdo, 'payment_run.create', 'payment_run', $r['payment_run_id'], ap_keys(null, null, $bank) + ['after' => ['number' => $run['number'], 'pay_date' => $f['pay_date'], 'currency' => $run['currency'], 'amount' => $run['total'], 'payments' => count($r['payments']), 'bills' => $r['bills']]]);
    notify_payment_awaiting('payment_run', $r['payment_run_id'], $run['number'], (string) $run['total'], $run['currency']);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Made ' . $run['number'] . ' (' . count($r['payments']) . ' payment' . (count($r['payments']) === 1 ? '' : 's') . ', ' . number_format((float) $run['total'], 2) . ' ' . $run['currency'] . ')', $r['payment_run_id'], gl_land(return_path('/payment-runs/' . $r['payment_run_id']), 'saved'), 'paymentRunChanged',
    ['payment_run_id' => $r['payment_run_id'], 'number' => $run['number'], 'payments' => $r['payments'], 'amount' => $run['total']]);
