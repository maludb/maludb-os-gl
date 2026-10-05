<?php
declare(strict_types=1);
/** Action `credit_note_delete` (a draft; confirm; deletion for an agent; log `credit_note.delete` before the delete): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('credit_note', 'credit_note_id') ?? refuse(422, 'Say which credit note.');
require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
try {
    $pdo->beginTransaction();
    $c = credit_note_state($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'credit_note.delete', 'credit_note', $id, audit_keys($c['customer_id']) + ['before' => credit_note_log_state($c) + ['total' => $c['total']]]);
    delete_credit_note($pdo, $id);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Deleted the draft credit note', $id, gl_land(return_path('/credit-notes/'), 'deleted'), 'creditNoteChanged');
