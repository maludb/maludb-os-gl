<?php
declare(strict_types=1);
/** Action `secure_link_rotate` (confirm; log `invoice.secure_link_rotate`): ar.write. A new token is held for the customer and mailed (an outbox row); the old link is dead. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
try {
    $inv = find_invoice($pdo, $id);
    $pdo->beginTransaction();
    $r = rotate_invoice_link($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'invoice.secure_link_rotate', 'invoice', $id, audit_keys($inv['customer_id']) + ['after' => ['number' => $inv['number']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Rotated the link of ' . $inv['number'] . ' and mailed the new one', $id, gl_land(return_path('/invoices/' . $id), 'link_rotated'), 'invoiceChanged', ['invoice_id' => $id, 'outbox_id' => $r['outbox_id']]);
