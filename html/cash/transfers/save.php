<?php
declare(strict_types=1);
/** Action `transfer_record` (from_bank_account, to_bank_account, amount, transfer_date, memo; confirm; money_out for an agent; log `transfer.record` with number, amount, currency, both account ids, journal_entry_id): bank.write. gl_transfer_record() posts DR the receiving / CR the sending; the currencies must agree. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$errors = [];
$from = gl_ref($pdo, req_has('from_bank_account') ? 'from_bank_account' : 'from', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'sending bank account', $errors, false);
$to = gl_ref($pdo, req_has('to_bank_account') ? 'to_bank_account' : 'to', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'receiving bank account', $errors, false);
$amount = req_money('amount', $errors, 'The amount');
if ($amount === null && !isset($errors['amount'])) {
    $errors['amount'] = 'The amount is required.';
}
$date = request_date('transfer_date');
if ($date === false) {
    $errors['transfer_date'] = 'The date is YYYY-MM-DD.';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$memo = req_has('memo') && trim((string) req_val('memo')) !== '' ? mb_substr(trim((string) req_val('memo')), 0, 500) : null;
try {
    $pdo->beginTransaction();
    $t = record_transfer($pdo, (int) $from, (int) $to, $date ?? date('Y-m-d'), $amount, $memo, (int) current_member_id());
    log_activity($pdo, 'transfer.record', 'transfer', $t['transfer_id'], bank_keys((int) $from, $t['journal_entry_id']) + ['after' => ['number' => $t['number'], 'amount' => $t['amount'], 'currency' => $t['currency'], 'from_bank_account_id' => (int) $from, 'to_bank_account_id' => (int) $to]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Recorded ' . $t['number'] . ' (' . $t['amount'] . ' ' . $t['currency'] . ')', $t['transfer_id'], gl_land(return_path('/cash/transfers/' . $t['transfer_id']), 'recorded'), 'bankChanged', ['transfer_id' => $t['transfer_id'], 'number' => $t['number']]);
