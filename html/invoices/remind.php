<?php
declare(strict_types=1);
/** Action `invoice_remind` (past due; message; confirm; external_send for an agent; log `invoice.remind` with the step and the days): ar.write. The wording escalates with the days past due. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
$message = req_has('message') ? mb_substr((string) req_val('message'), 0, 2000) : null;
try {
    $inv = find_invoice($pdo, $id);
    $pdo->beginTransaction();
    $r = remind_invoice($pdo, $id, $message, (int) current_member_id());
    log_activity($pdo, 'invoice.remind', 'invoice', $id, audit_keys($inv['customer_id']) + ['after' => ['step' => $r['step'], 'days_overdue' => $r['days_overdue'], 'reminder_count' => $r['reminder_count'], 'number' => $inv['number']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Queued reminder ' . $r['reminder_count'] . ' (step ' . $r['step'] . ') for ' . $inv['number'], $id, gl_land(return_path('/invoices/' . $id), 'reminded'), 'invoiceChanged', ['invoice_id' => $id, 'step' => $r['step'], 'reminder_count' => $r['reminder_count']]);
