<?php
declare(strict_types=1);
/** Action `receipt_apply` (receipt, invoice, amount: empty = what fits; confirm; money_out for an agent; log `receipt.apply` with amount, currency): ar.write. The guards refuse overpaying, another customer, another currency. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$rid = req_id('receipt', 'receipt_id') ?? refuse(422, 'Say which receipt.');
$iid = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_receipts', 'receipt_id', $rid, 'Receipt');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $iid, 'Invoice');
$errors = [];
$amount = req_money('amount', $errors, 'The amount');
if ($errors !== []) {
    gl_refuse_fields($errors);
}
try {
    $pdo->beginTransaction();
    $r = receipt_state($pdo, $rid);
    $a = apply_receipt($pdo, $rid, $iid, $amount, (int) current_member_id());
    log_activity($pdo, 'receipt.apply', 'receipt', $rid, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'invoice_id' => $iid, 'invoice_number' => $a['invoice_number'], 'amount' => $a['amount'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Applied ' . number_format((float) $a['amount'], 2) . ' of ' . $r['number'] . ' to ' . $a['invoice_number'], $rid, gl_land(return_path('/receipts/' . $rid), 'applied'), 'receiptChanged', ['receipt_id' => $rid, 'invoice_id' => $iid, 'amount' => $a['amount']]);
