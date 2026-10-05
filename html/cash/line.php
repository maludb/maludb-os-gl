<?php
declare(strict_types=1);
/** Action `bank_line_add` (bank_account, txn_date, amount signed, description, reference; log `bank.line_add`): bank.write. A line typed in by hand, for a bank with no export. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
gl_handler_begin();
require_right('bank.write');
$pdo = db();
$errors = [];
$bankId = gl_ref($pdo, req_has('bank_account') ? 'bank_account' : 'bank_account_id', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors, false);
$date = request_date('txn_date');
if ($date === false || $date === null) {
    $errors['txn_date'] = 'The date is YYYY-MM-DD.';
}
$amount = req_has('amount') ? parse_money((string) req_val('amount')) : null;
if ($amount === null || m_cmp($amount, '0') === 0) {
    $errors['amount'] = 'The amount is signed, like -15.00 for a fee, and not zero.';
}
$desc = trim((string) req_val('description'));
if ($desc === '' || mb_strlen($desc) > 300) {
    $errors['description'] = 'Describe the line in up to 300 characters.';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$ref = req_has('reference') && trim((string) req_val('reference')) !== '' ? mb_substr(trim((string) req_val('reference')), 0, 120) : null;
try {
    $pdo->beginTransaction();
    $id = add_bank_line($pdo, $bankId, ['txn_date' => $date, 'amount' => $amount, 'description' => $desc, 'reference' => $ref], (int) current_member_id());
    log_activity($pdo, 'bank.line_add', 'bank_line', $id, bank_keys($bankId) + ['after' => ['bank_transaction_id' => $id, 'txn_date' => $date, 'amount' => $amount, 'description' => $desc]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Added a bank line of ' . $amount . ' on ' . $date, $id, gl_land(return_path('/cash/match?bank_account=' . $bankId), 'saved'), 'bankChanged', ['bank_transaction_id' => $id]);
