<?php
declare(strict_types=1);
/** Action `credit_note_void` (issued and unapplied; reason; confirm; money_out for an agent; log `credit_note.void`): journal.override. The guard refuses an applied one ("unapply it first"); its entry is reversed. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('journal.override');
$pdo = db();
$id = req_id('credit_note', 'credit_note_id') ?? refuse(422, 'Say which credit note.');
require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
$reason = mb_substr((string) req_val('reason'), 0, 500);
try {
    $pdo->beginTransaction();
    $c = void_credit_note($pdo, $id, (int) current_member_id(), $reason);
    log_activity($pdo, 'credit_note.void', 'credit_note', $id, audit_keys($c['customer_id'], $c['journal_entry_id']) + ['after' => ['number' => $c['number'], 'reason' => $reason, 'amount' => $c['total'], 'currency' => $c['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Voided ' . $c['number'], $id, gl_land(return_path('/credit-notes/' . $id), 'voided'), 'creditNoteChanged', ['credit_note_id' => $id]);
