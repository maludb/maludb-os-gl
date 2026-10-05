<?php
declare(strict_types=1);
/** Action `reconciliation_start` (bank_account, statement_date, statement_balance; log `reconciliation.start`): bank.write. One in progress per account; a statement dated after the last one. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$errors = [];
$bankId = gl_ref($pdo, req_has('bank_account') ? 'bank_account' : 'bank_account_id', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors, false);
$date = request_date('statement_date');
if ($date === false || $date === null) {
    $errors['statement_date'] = 'The statement date is YYYY-MM-DD.';
}
$bal = req_has('statement_balance') ? parse_money((string) req_val('statement_balance')) : null;
if ($bal === null) {
    $errors['statement_balance'] = 'The statement balance is an amount, like 11,336.44.';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
try {
    $pdo->beginTransaction();
    $r = start_reconciliation($pdo, $bankId, $date, $bal, (int) current_member_id());
    log_activity($pdo, 'reconciliation.start', 'reconciliation', $r['bank_reconciliation_id'], bank_keys($bankId) + ['after' => ['statement_date' => $date, 'statement_balance' => $bal, 'opening_balance' => $r['opening_balance'], 'cleared_balance' => $r['cleared_balance'], 'difference' => $r['difference']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Started reconciling ' . $r['bank_account_name'] . ' to ' . $date . ' (the difference is ' . $r['difference'] . ')', $r['bank_reconciliation_id'], gl_land('/cash/reconcile?bank_account=' . $bankId, 'started'), 'bankChanged', ['reconciliation_id' => $r['bank_reconciliation_id'], 'difference' => $r['difference'], 'cleared_balance' => $r['cleared_balance'], 'opening_balance' => $r['opening_balance']]);
