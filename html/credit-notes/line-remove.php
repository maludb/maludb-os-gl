<?php
declare(strict_types=1);
/** Action `credit_note_line_remove` (`credit_note` a draft, `line`; log `credit_note.line_remove`): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('credit_note', 'credit_note_id') ?? refuse(422, 'Say which credit note.');
require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
$cn = find_credit_note($pdo, $id) ?? refuse(404, 'Credit note not found.');
$lineId = req_id('line', 'line_id') ?? refuse(422, 'Say which line.');
$gone = null;
foreach ($cn['lines'] as $l) {
    if ($l['credit_note_line_id'] === $lineId) {
        $gone = $l;
    }
}
$gone ?? refuse(404, 'Line not found.');
try {
    $pdo->beginTransaction();
    remove_credit_note_line($pdo, $id, $lineId);
    log_activity($pdo, 'credit_note.line_remove', 'credit_note', $id, audit_keys($cn['customer_id']) + ['before' => ['description' => $gone['description'], 'line_total' => $gone['line_total']], 'after' => ['removed' => true, 'currency' => $cn['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'credit-note-lines') {
    emit_action_status(true, ['did' => 'Removed the line', 'record_id' => $lineId]);
    answer_doc_region($pdo, 'credit_note', $cn);
}
gl_done('Removed the line', $lineId, gl_land(return_path('/credit-notes/' . $id), 'line_removed', 'credit-note-lines'), 'creditNoteChanged', ['credit_note_id' => $id]);
