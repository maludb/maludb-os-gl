<?php
declare(strict_types=1);
/** Action `invoice_send` (an issued invoice; to_email, message; confirm; external_send for an agent; log `invoice.send` with the count, never the address): ar.write. Queues the mail with the secure link for the customer. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
$errors = [];
$to = req_email('to_email', $errors);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$message = req_has('message') ? mb_substr((string) req_val('message'), 0, 2000) : null;
try {
    $inv = find_invoice($pdo, $id);
    $pdo->beginTransaction();
    $r = send_invoice($pdo, $id, $to, $message, (int) current_member_id());
    log_activity($pdo, 'invoice.send', 'invoice', $id, audit_keys($inv['customer_id']) + ['after' => ['to' => $r['to_count'], 'number' => $inv['number'], 'amount' => $inv['balance_due'], 'currency' => $inv['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Queued ' . $inv['number'] . ' for the customer', $id, gl_land(return_path('/invoices/' . $id), 'sent'), 'invoiceChanged', ['invoice_id' => $id, 'outbox_id' => $r['outbox_id']]);
