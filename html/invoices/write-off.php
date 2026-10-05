<?php
declare(strict_types=1);
/** Action `invoice_write_off` (owed; reason; write_off_date; confirm; money_out for an agent; log `invoice.write_off` with reason, amount, currency): journal.override. gl_invoice_write_off() posts DR bad debt / CR receivable. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_invoices', 'invoice_id', $id, 'Invoice');
$reason = mb_substr((string) req_val('reason'), 0, 500);
$date = request_date('write_off_date');
if ($date === false) {
    gl_refuse_fields(['write_off_date' => 'The date is YYYY-MM-DD.']);
}
try {
    $pdo->beginTransaction();
    $r = write_off_invoice($pdo, $id, (int) current_member_id(), $reason, $date);
    log_activity($pdo, 'invoice.write_off', 'invoice', $id, audit_keys($r['customer_id'], $r['journal_entry_id']) + ['after' => ['number' => $r['number'], 'reason' => $reason, 'amount' => $r['amount'], 'currency' => $r['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Wrote off ' . $r['number'] . ' (' . number_format((float) $r['amount'], 2) . ' ' . $r['currency'] . ')', $id, gl_land(return_path('/invoices/' . $id), 'written_off'), 'invoiceChanged', ['invoice_id' => $id]);
