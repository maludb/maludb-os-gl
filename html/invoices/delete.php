<?php
declare(strict_types=1);
/** Action `invoice_delete` (a draft; confirm; deletion for an agent; log `invoice.delete` BEFORE the delete): ar.write. An issued invoice is never deleted (void it). A deleted draft burns no number. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
try {
    $pdo->beginTransaction();
    $s = invoice_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'invoice.delete', 'invoice', $id, audit_keys($s['customer_id']) + ['before' => invoice_log_state($s) + ['total' => $s['total']]]);
    delete_invoice($pdo, $id, (int) current_member_id());
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Deleted the draft invoice', $id, gl_land(return_path('/invoices/'), 'deleted'), 'invoiceChanged');
