<?php
declare(strict_types=1);
/** Action `deposit_draft` (bank_account, deposit_date, memo, receipts[] — undeposited receipts to put in; log `deposit.draft`): ar.write. The deposit is numbered at creation; a bank receipt is refused ("only undeposited receipts join"). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$errors = [];
$bank = gl_ref($pdo, req_has('bank_account') ? 'bank_account' : 'bank_account_id', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors, false);
$date = request_date('deposit_date');
if ($date === false) {
    $errors['deposit_date'] = 'The date is YYYY-MM-DD.';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$ids = array_values(array_unique(array_map('intval', array_filter(request_list('receipts') ?? [], static fn (string $v): bool => ctype_digit($v)))));
try {
    $pdo->beginTransaction();
    foreach ($ids as $rid) {
        require_visible($pdo, 'mcp_receipts', 'receipt_id', $rid, 'Receipt');
    }
    $id = draft_deposit($pdo, ['bank_account_id' => $bank, 'deposit_date' => $date ?? date('Y-m-d'), 'memo' => req_has('memo') && (string) req_val('memo') !== '' ? mb_substr((string) req_val('memo'), 0, 500) : null], $ids, (int) current_member_id());
    $d = deposit_state($pdo, $id);
    log_activity($pdo, 'deposit.draft', 'deposit', $id, audit_keys(null, null, $bank) + ['after' => ['number' => $d['number'], 'receipts' => count($ids), 'amount' => $d['total'], 'currency' => $d['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Drafted ' . $d['number'] . ' (' . count($ids) . ' receipt' . (count($ids) === 1 ? '' : 's') . ')', $id, gl_land(return_path('/deposits/' . $id), 'saved'), 'depositChanged', ['deposit_id' => $id, 'number' => $d['number']]);
