<?php
declare(strict_types=1);
/** Action `invoice_void` (issued, nothing applied; reason; confirm; money_out for an agent; log `invoice.void` with reason, amount, currency): journal.override. gl_invoice_void() reverses the entry. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $r = void_invoice($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'invoice.void', 'invoice', $id, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'amount' => $r['total'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Voided ' . $r['number'], $id, gl_land(return_path('/invoices/' . $id), 'voided'), 'invoiceChanged', ['invoice_id' => $id]);
