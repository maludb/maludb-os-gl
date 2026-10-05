<?php
declare(strict_types=1);
/** Action `credit_note_line_save` (`credit_note` a draft; `line` to change one; log `credit_note.line_save`): ar.write. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
require_right('ar.write');
$pdo = db();
$id = req_id('credit_note', 'credit_note_id') ?? refuse(422, 'Say which credit note.');
require_visible($pdo, 'mcp_credit_notes', 'credit_note_id', $id, 'Credit note');
$cn = find_credit_note($pdo, $id) ?? refuse(404, 'Credit note not found.');
$lineId = req_id('line', 'line_id');
$before = null;
if ($lineId !== null) {
    foreach ($cn['lines'] as $l) {
        if ($l['credit_note_line_id'] === $lineId) {
            $before = $l;
        }
    }
    $before ?? refuse(404, 'Line not found.');
}
try {
    $defaults = invoice_defaults($pdo, $cn['customer_id'], $cn['issue_date']);
    $f = normalize_doc_line($pdo, doc_line_request($before), $before === null ? count($cn['lines']) + 1 : (int) $before['sort_order'], $defaults);
    $pdo->beginTransaction();
    $newLine = save_credit_note_line($pdo, $id, $lineId, $f);
    $after = null;
    foreach (credit_note_lines($pdo, $id) as $l) {
        if ($l['credit_note_line_id'] === $newLine) {
            $after = $l;
        }
    }
    log_activity($pdo, 'credit_note.line_save', 'credit_note', $id, audit_keys($cn['customer_id']) + ['before' => $before === null ? null : ['description' => $before['description'], 'line_total' => $before['line_total']], 'after' => ['description' => $after['description'], 'line_total' => $after['line_total'], 'currency' => $cn['currency']]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
if (is_htmx_request() && !wants_json() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'credit-note-lines') {
    emit_action_status(true, ['did' => 'Saved the line', 'record_id' => $newLine]);
    answer_doc_region($pdo, 'credit_note', $cn);
}
gl_done(($lineId === null ? 'Added a line' : 'Saved the line'), $newLine, gl_land(return_path('/credit-notes/' . $id), 'line_saved', 'credit-note-lines'), 'creditNoteChanged', ['credit_note_id' => $id, 'credit_note_line_id' => $newLine]);
