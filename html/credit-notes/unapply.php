<?php
declare(strict_types=1);
/** Action `credit_note_unapply` (credit_note, invoice; confirm; money_out for an agent; log `credit_note.unapply`): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$cid = req_id('credit_note', 'credit_note_id') ?? refuse(422, 'Say which credit note.');
$iid = req_id('invoice', 'invoice_id') ?? refuse(422, 'Say which invoice.');
require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $cid, 'Credit note');
try {
    $pdo->beginTransaction();
    $cn = credit_note_state($pdo, $cid);
    $a = unapply_credit($pdo, $cid, $iid);
    log_activity($pdo, 'credit_note.unapply', 'credit_note', $cid, audit_keys($cn['customer_id']) + ['after' => ['number' => $cn['number'], 'invoice_id' => $iid, 'invoice_number' => $a['invoice_number'], 'amount' => $a['amount'], 'currency' => $cn['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Took ' . $cn['number'] . ' off ' . $a['invoice_number'], $cid, gl_land(return_path('/credit-notes/' . $cid), 'unapplied'), 'creditNoteChanged', ['credit_note_id' => $cid, 'invoice_id' => $iid]);
