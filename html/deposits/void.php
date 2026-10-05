<?php
declare(strict_types=1);
/** Action `deposit_void` (recorded and unmatched; reason; confirm; money_out for an agent; log `deposit.void`): journal.override. gl_deposit_void() reverses the entry; the receipts return to undeposited; a deposit matched to a bank line is refused in the function's words. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('deposit', 'deposit_id') ?? refuse(422, 'Say which deposit.');
require_visible($pdo, 'mcp_deposits', 'deposit_id', $id, 'Deposit');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $r = void_deposit($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'deposit.void', 'deposit', $id, audit_keys(null, $r['journal_entry_id'], $r['bank_account_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'amount' => $r['amount'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Voided ' . $r['number'], $id, gl_land(return_path('/deposits/' . $id), 'voided'), 'depositChanged', ['deposit_id' => $id]);
