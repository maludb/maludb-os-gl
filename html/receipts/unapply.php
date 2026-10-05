<?php
declare(strict_types=1);
/** Action `receipt_unapply` (receipt, invoice; confirm; money_out for an agent; log `receipt.unapply`): ar.write. A deposited receipt's application is fixed ("Take it out of the deposit first."). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$rid = req_id('receipt', 'receipt_id') ?? refuse(422, 'Say which receipt.');
$iid = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_receipts', 'receipt_id', $rid, 'Receipt');
try {
    $pdo->beginTransaction();
    $r = receipt_state($pdo, $rid);
    $a = unapply_receipt($pdo, $rid, $iid);
    log_activity($pdo, 'receipt.unapply', 'receipt', $rid, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'invoice_id' => $iid, 'invoice_number' => $a['invoice_number'], 'amount' => $a['amount'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Took ' . $r['number'] . ' off ' . $a['invoice_number'], $rid, gl_land(return_path('/receipts/' . $rid), 'unapplied'), 'receiptChanged', ['receipt_id' => $rid, 'invoice_id' => $iid]);
