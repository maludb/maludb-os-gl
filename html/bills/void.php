<?php
declare(strict_types=1);
/** Action `bill_void` (approved, nothing applied; reason; confirm; money_out for an agent; log `bill.void` with reason, amount, currency): journal.override. gl_bill_void() reverses the entry; the guard refuses a bill with a payment or credit applied. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/bills/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('bill', 'bill_id') ?? refuse(422, 'Say which bill.');
require_visible($pdo, 'mcp_bills', 'bill_id', $id, 'Bill');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $r = void_bill($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'bill.void', 'bill', $id, ap_keys($r['vendor_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'amount' => $r['total'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    bill_refused($pdo, $ex);
}
gl_done('Voided ' . $r['number'], $id, gl_land(return_path('/bills/' . $id), 'voided'), 'billChanged', ['bill_id' => $id]);
