<?php
declare(strict_types=1);
/** Action `receipt_void` (unapplied and undeposited; reason; confirm; money_out for an agent; log `receipt.void`): journal.override. gl_receipt_void() reverses the entry; the guard refuses an applied or deposited one in its words. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('receipt', 'receipt_id') ?? refuse(422, 'Say which receipt.');
require_visible($pdo, 'mcp_receipts', 'receipt_id', $id, 'Receipt');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $r = void_receipt($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'receipt.void', 'receipt', $id, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'amount' => $r['amount'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Voided ' . $r['number'], $id, gl_land(return_path('/receipts/' . $id), 'voided'), 'receiptChanged', ['receipt_id' => $id]);
